<?php
/**
 * ParallelChatDispatcher: fan-out chat completions across many provider/model
 * pairs concurrently using curl_multi.
 *
 * Provider connectors retain ownership of headers, retry/backoff and
 * usage parsing — this class drives the network IO and hands raw
 * responses back to the connector for assembly.
 *
 * Falls back to sequential execution when a connector cannot expose a
 * pre-built request (e.g. a stub connector with no HTTP base URL): the
 * caller may invoke ConnectorInterface::chat() one-at-a-time as a safe
 * default. The pipeline engine handles that branch directly.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Exceptions\ConnectorException;
use App\Core\ErrorDiagnostics;
use App\Helpers\JsonHelper;
use App\Helpers\TimeHelper;

/**
 * Lightweight value type describing a single chat dispatch.
 *
 * @internal
 */
final class ParallelChatRequest
{
    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     * @param mixed $tag Caller-defined correlation key.
     */
    public function __construct(
        public readonly ConnectorInterface $connector,
        public readonly string             $model,
        public readonly array              $messages,
        public readonly array              $options = [],
        public readonly mixed              $tag = null,
    ) {}
}

final class ParallelChatDispatcher
{
    /**
     * Test seam for the ext-curl probe below.
     *
     * Extensions cannot be unloaded inside one PHP process, so the
     * "host without ext-curl" branch is otherwise unreachable on a
     * machine that has curl compiled in. Tests flip this via reflection
     * to exercise both branches deterministically; production leaves it
     * null so the real probe decides.
     */
    private static ?bool $curlAvailableOverride = null;

    /**
     * Whether ext-curl is usable in this process.
     *
     * Both probes are deliberate. `extension_loaded('curl')` is the
     * authoritative yes/no, while `function_exists('curl_multi_init')`
     * additionally catches builds where the function is suppressed via
     * `disable_functions` (there curl is loaded but the symbol is gone,
     * and calling it still throws). Either probe failing means the
     * multi-handle path cannot run.
     */
    private static function curlAvailable(): bool
    {
        if (self::$curlAvailableOverride !== null) {
            return self::$curlAvailableOverride;
        }
        return extension_loaded('curl') && function_exists('curl_multi_init');
    }

    /**
     * Dispatch every request concurrently. Returns a list keyed by the same
     * positional index as $requests. Each entry is one of:
     *
     *   ['ok' => true,  'response' => ChatResponse, 'tag' => mixed]
     *   ['ok' => false, 'error'    => string,        'tag' => mixed]
     *
     * When `$onComplete` is supplied it is invoked with `(int $index,
     * array $row)` as soon as each request finishes — the row shape
     * matches the array returned by the same call. This enables
     * real-time SSE emission per L1 model without waiting for the slowest
     * model to return. Sequential / stub requests also fire the callback
     * synchronously so the caller can treat both code paths uniformly.
     *
     * @param ParallelChatRequest[] $requests
     * @param  null|callable(int,array<string,mixed>):void $onComplete
     * @return array<int,array<string,mixed>>
     */
    public function dispatch(array $requests, ?callable $onComplete = null): array
    {
        if ($requests === []) {
            return [];
        }
        // Partition: HTTP-based connectors go through curl_multi; the rest
        // (stubs, in-memory connectors used for tests) fall back to a
        // sequential loop so they remain testable without a network stack.
        $httpIndex = [];
        $sequential = [];
        $skipped = [];
        foreach ($requests as $i => $r) {
            // R11: a model the breaker has dropped is not called again in this run -
            // not in a retry, not in a later consensus cycle. The skip is recorded, so
            // it surfaces as a reason instead of as a silent gap in the council.
            if ($this->breaker()->isOpen((string)$r->model)) {
                $reason = $this->breaker()->openReason((string)$r->model);
                $skipped[$i] = [
                    'ok'    => false,
                    'error' => 'skipped by the call breaker: ' . $reason,
                    'tag'   => $r->tag,
                    'kind'  => 'breaker',
                ];
                $this->noteOutcome((string)$r->model, false, 'breaker', $reason, 0);
                continue;
            }
            if ($r->connector instanceof AbstractConnector) {
                $httpIndex[$i] = $r;
            } else {
                $sequential[$i] = $r;
            }
        }

        $results = array_fill(0, count($requests), null);
        foreach ($skipped as $i => $row) {
            $results[$i] = $row;
            if ($onComplete !== null) {
                $onComplete($i, $row);
            }
        }

        if ($httpIndex !== []) {
            $multiResults = $this->runMulti($httpIndex, $onComplete);
            foreach ($multiResults as $i => $row) {
                $results[$i] = $row;
            }
        }
        foreach ($sequential as $i => $r) {
            $row = $this->runSequential($r);
            $results[$i] = $row;
            if ($onComplete !== null) {
                $onComplete($i, $row);
            }
        }

        $this->retryTransient($requests, $results, $onComplete);

        return array_values(array_map(static fn($r) => $r ?? ['ok' => false, 'error' => 'no-result', 'tag' => null], $results));
    }

    /**
     * @param array<int,ParallelChatRequest> $requests
     * @param  null|callable(int,array<string,mixed>):void $onComplete
     * @return array<int,array<string,mixed>>
     */

    private ?ModelBreaker $breaker = null;

    /** @var array<string,array{model:string,attempts:int,ok:bool,kind:string,reason:string}> */
    private array $outcomes = [];

    /** The in-run breaker: one instance per dispatcher, so it spans the cycles. */
    /**
     * The models this dispatcher currently considers broken, as `provider/model`
     * ids. Public so the engine can replace them when it assembles the next
     * pool: a council that lost a member to the breaker should not stay a
     * member short when the active catalogue offers a stand-in.
     *
     * @return array<int,string>
     */
    public function openModels(): array
    {
        // summary() lists the models that are OPEN, and only those - it has no
        // per-row flag (the shape is {"model":..,"reason":..}). Requiring an
        // "open" key here made this accessor silently return nothing, which is
        // the worst kind of bug: the substitution simply never fired.
        $open = [];
        foreach ($this->breaker()->summary() as $row) {
            $model = is_array($row) ? trim((string)($row['model'] ?? '')) : '';
            if ($model !== '') {
                $open[] = $model;
            }
        }
        return $open;
    }
    private function breaker(): ModelBreaker
    {
        return $this->breaker ??= new ModelBreaker();
    }

    /**
     * What happened to every model in the last dispatch (R11).
     *
     * Reported for the run's own accounting: how many opinions arrived, which
     * models produced none, and why. Never used to change a verdict - the
     * terminal status keeps its own, stricter rules.
     *
     * @return array<int,array{model:string,attempts:int,ok:bool,kind:string,reason:string}>
     */
    public function outcomes(): array
    {
        return array_values($this->outcomes);
    }

    /**
     * Models that produced no usable answer, each with its classified reason.
     *
     * @return array<int,array{model:string,attempts:int,kind:string,reason:string}>
     */
    public function missing(): array
    {
        $out = [];
        foreach ($this->outcomes as $model => $row) {
            if ($row['ok'] === true) {
                continue;
            }
            $out[] = [
                'model'    => $model,
                'attempts' => $row['attempts'],
                'kind'     => $row['kind'],
                'reason'   => $row['reason'],
            ];
        }

        return $out;
    }

    /** Models dropped for the rest of this run, with the reason. */
    public function breakerSummary(): array
    {
        return $this->breaker()->summary();
    }

    /** Remember what a model did, keeping the latest verdict per model. */
    private function noteOutcome(string $model, bool $ok, string $kind, string $reason, int $attempts): void
    {
        if ($model === '') {
            return;
        }
        $previous = $this->outcomes[$model]['attempts'] ?? 0;
        $this->outcomes[$model] = [
            'model'    => $model,
            'attempts' => $attempts > 0 ? $previous + $attempts : $previous,
            'ok'       => $ok,
            'kind'     => $kind,
            'reason'   => $reason,
        ];
    }

    /**
     * Give transiently failing models their bounded second chance (R11).
     *
     * Only kinds a retry can plausibly fix are retried, only within the
     * configured budget, and never for a model the breaker has already dropped.
     * Each attempt is recorded, so the run can report "2 attempts, timed out
     * twice" rather than an anonymous gap in the council.
     *
     * @param array<int,ParallelChatRequest> $requests
     * @param array<int,array<string,mixed>|null> $results
     */
    private function retryTransient(array $requests, array &$results, ?callable $onComplete): void
    {
        $budget = CallPolicy::retryBudget();
        if ($budget < 1) {
            return;
        }

        foreach ($requests as $i => $req) {
            $row = $results[$i] ?? null;
            if (!is_array($row) || ($row['ok'] ?? false) === true) {
                continue;
            }
            $model = (string)$req->model;
            $error = (string)($row['error'] ?? '');
            $status = is_int($row['status'] ?? null) ? $row['status'] : null;
            $verdict = CallPolicy::classify($error, $status);

            $this->noteOutcome($model, false, $verdict['kind'], $verdict['reason'], 1);
            $this->breaker()->record($model, false, $verdict['reason']);

            if (!$verdict['transient'] || $this->breaker()->isOpen($model)) {
                continue;
            }

            for ($attempt = 0; $attempt < $budget; $attempt++) {
                $retry = $this->runSequential($req);
                if (($retry['ok'] ?? false) === true) {
                    $results[$i] = $retry;
                    $this->noteOutcome($model, true, 'ok', '', 1);
                    $this->breaker()->record($model, true);
                    if ($onComplete !== null) {
                        $onComplete($i, $retry);
                    }
                    break;
                }
                $error = (string)($retry['error'] ?? '');
                $retryVerdict = CallPolicy::classify($error, is_int($retry['status'] ?? null) ? $retry['status'] : null);
                $results[$i] = $retry;
                $this->noteOutcome($model, false, $retryVerdict['kind'], $retryVerdict['reason'], 1);
                $this->breaker()->record($model, false, $retryVerdict['reason']);
                if (!$retryVerdict['transient'] || $this->breaker()->isOpen($model)) {
                    break;
                }
            }
        }
    }

    private function runMulti(array $requests, ?callable $onComplete = null): array
    {
        $out = [];
        // ext-curl is optional at runtime even though composer declares it
        // required: hardened hosts and minimal PHP builds ship without it.
        // When the extension is missing, curl_multi_init() is not a
        // "returns false" failure — the symbol does not exist at all, so
        // the call raises a fatal `Call to undefined function` Error and
        // kills the request. The documented sequential fallback further
        // down would therefore never be reached. Probe first, before any
        // curl_* symbol is touched.
        if (!self::curlAvailable()) {
            return $this->runSequentialBatch($requests, $onComplete);
        }
        $mh = curl_multi_init();
        if ($mh === false) {
            // Fall back to sequential when curl_multi is unavailable.
            return $this->runSequentialBatch($requests, $onComplete);
        }
        $handles = [];
        $started = [];
        $payloads = [];
        // Reverse-map of curl handle id → original index so curl_multi_info_read
        // results can be routed back to the caller-provided index.
        $handleIndexByResource = [];
        foreach ($requests as $i => $req) {
            /** @var AbstractConnector $conn */
            $conn = $req->connector;
            // A payload the connector refuses outright — today that means a
            // batch-only model id, which must never reach a synchronous
            // endpoint (see AbstractConnector::assertSynchronousModel()).
            // The refusal is reported as THIS row's failure and every other
            // model keeps running: one unusable id in the pool must not take
            // the whole L1 fan-out down with it.
            try {
                $build = $conn->buildChatRequest($req->model, $req->messages, $req->options);
            } catch (\Throwable $e) {
                $out[$i] = ['ok' => false, 'error' => $e->getMessage(), 'tag' => $req->tag];
                if ($onComplete !== null) { $onComplete($i, $out[$i]); }
                continue;
            }
            $ch = curl_init();
            if ($ch === false) {
                $out[$i] = ['ok' => false, 'error' => 'curl init failed', 'tag' => $req->tag];
                if ($onComplete !== null) { $onComplete($i, $out[$i]); }
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_URL            => $build['url'],
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $build['headers'],
                CURLOPT_POSTFIELDS     => JsonHelper::encode($build['payload']),
                CURLOPT_TIMEOUT        => $build['timeout'],
                CURLOPT_CONNECTTIMEOUT => min(15, $build['timeout']),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
            $started[$i] = TimeHelper::now();
            $payloads[$i] = $req;
            $handleIndexByResource[(int)$ch] = $i;
        }

        // Drive the multi-loop and harvest each finished handle as soon
        // as it becomes done. Emitting per-completion is the difference
        // between the SSE client filling cards live and the previous
        // behaviour that buffered every L1 result until the slowest
        // model returned.
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            // Drain every newly-finished handle each tick. We use the
            // resource-id map because curl_multi_info_read returns the
            // raw handle and we need the original positional index to
            // build the result row in the right slot.
            while (($info = curl_multi_info_read($mh)) !== false) {
                $doneHandle = $info['handle'] ?? null;
                if ($doneHandle === null) { continue; }
                $key = (int)$doneHandle;
                if (!isset($handleIndexByResource[$key])) { continue; }
                $i = $handleIndexByResource[$key];
                if (isset($out[$i])) { continue; }
                $row = $this->harvestHandle($mh, $doneHandle, $payloads[$i], $started[$i]);
                $out[$i] = $row;
                if ($onComplete !== null) {
                    $onComplete($i, $row);
                }
            }
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        // Backstop: any handle still missing from $out (e.g. an early
        // CURLM_OK error before info_read fired) gets harvested here.
        foreach ($handles as $i => $ch) {
            if (isset($out[$i])) { continue; }
            $row = $this->harvestHandle($mh, $ch, $payloads[$i], $started[$i]);
            $out[$i] = $row;
            if ($onComplete !== null) {
                $onComplete($i, $row);
            }
        }
        curl_multi_close($mh);
        return $out;
    }

    /**
     * Read content + status off a finished curl handle and translate it
     * into the standard {ok,response|error,tag} row. Always closes the
     * handle so callers don't have to.
     *
     * @return array<string,mixed>
     */
    private function harvestHandle(\CurlMultiHandle $mh, \CurlHandle $ch, ParallelChatRequest $req, float $startedAt): array
    {
        $raw  = curl_multi_getcontent($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $latency = TimeHelper::elapsedMs($startedAt);
        if ($err !== '' || $raw === false) {
            return ['ok' => false, 'error' => 'cURL error: ' . $err, 'tag' => $req->tag];
        }
        if ($code >= 400) {
            // Same sentence the serial path builds (ErrorDiagnostics::
            // connectorHttpMessage): the parallel path used to be the one
            // place a rejected model still produced a model-free "HTTP 404",
            // which is exactly what made an L1 failure ambiguous. Both
            // paths now name the provider code and the model.
            return [
                'ok'    => false,
                'error' => ErrorDiagnostics::connectorHttpMessage(
                    $req->connector->getProviderName(),
                    $code,
                    (string)$raw,
                    $req->model,
                    500,
                ),
                'tag'   => $req->tag,
            ];
        }
        $decoded = JsonHelper::tryDecode((string)$raw);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'invalid JSON response', 'tag' => $req->tag];
        }
        try {
            /** @var AbstractConnector $conn */
            $conn = $req->connector;
            $resp = $conn->buildChatResponseFromRaw($req->model, $req->messages, $decoded, $latency);
            return ['ok' => true, 'response' => $resp, 'tag' => $req->tag];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'tag' => $req->tag];
        }
    }

    /**
     * Run a batch one-at-a-time, firing $onComplete after each row.
     *
     * Single implementation shared by both fallback triggers (no ext-curl
     * at all, and curl_multi_init() returning false) so the two cannot
     * drift apart. Keys of $requests are preserved in the result.
     *
     * @param array<int,ParallelChatRequest> $requests
     * @param  null|callable(int,array<string,mixed>):void $onComplete
     * @return array<int,array<string,mixed>>
     */
    private function runSequentialBatch(array $requests, ?callable $onComplete = null): array
    {
        $out = [];
        foreach ($requests as $i => $r) {
            $out[$i] = $this->runSequential($r);
            if ($onComplete !== null) { $onComplete($i, $out[$i]); }
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function runSequential(ParallelChatRequest $r): array
    {
        try {
            $resp = $r->connector->chat($r->model, $r->messages, $r->options);
            return ['ok' => true, 'response' => $resp, 'tag' => $r->tag];
        } catch (ConnectorException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'tag' => $r->tag];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'tag' => $r->tag];
        }
    }
}
