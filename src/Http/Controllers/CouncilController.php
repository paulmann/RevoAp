<?php
/**
 * CouncilController: HTTP endpoints for running and querying council sessions.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.8.23
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Consensus\ConsensusResult;
use App\Core\Config;
use App\Core\Diagnostics;
use App\Admin\Budgets\UserQuotaInterface;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Pipeline\PipelineEngine;
use App\Pipeline\QueryAttachments;
use App\Pipeline\RunControl;
use App\Pipeline\SseEventStreamer;
use App\Pipeline\StreamRunLock;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;
use PDO;

final class CouncilController
{
    /**
     * @param UserQuotaInterface|null $quotas v2.1.0 (T5) — per-user budget
     *        enforcement on the web launch paths, mirroring /v1/runs.
     * @param callable|null           $defaultUserResolver resolves the
     *        end-user identity for web runs that carry no explicit user_id.
     *        The bootstrap passes the same closure JobService uses (the
     *        local profile user), so a console run is BILLABLE TO SOMEONE
     *        instead of landing in the unattributed bucket — and "my usage"
     *        on a single-operator install shows that operator's real runs.
     */
    public function __construct(
        private readonly PipelineEngine     $engine,
        private readonly SessionRepository  $sessions,
        private readonly StepRepository     $steps,
        private readonly ?ConnectorRegistry $connectors = null,
        private readonly ?PDO               $pdo        = null,
        private readonly ?RunControl        $runControl = null,
        private readonly ?StreamRunLock     $streamLock = null,
        private readonly ?UserQuotaInterface $quotas = null,
        private readonly mixed              $defaultUserResolver = null,
    ) {}

    /**
     * Resolve the end-user id for a web launch: explicit payload user_id
     * wins; otherwise the default resolver (the local profile user) when
     * one is wired. Never throws — a resolver failure degrades to null
     * (unattributed), which is the pre-v2.1 behaviour.
     *
     * @param array<string,mixed> $payload
     */
    private function resolveUserId(array $payload): ?int
    {
        if (isset($payload['user_id']) && is_numeric($payload['user_id'])) {
            $uid = (int)$payload['user_id'];
            return $uid > 0 ? $uid : null;
        }
        $resolver = $this->defaultUserResolver;
        if (!is_callable($resolver)) {
            return null;
        }
        try {
            $resolved = $resolver();
            return is_int($resolved) && $resolved > 0 ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Budget verdict for a web launch. Returns null when the run may
     * proceed, or the blocking verdict array. Mirrors
     * ApiV1Controller::quotaGate — one contract, two renderers (JSON here,
     * an SSE frame in stream()).
     *
     * @return array<string,mixed>|null
     */
    private function quotaBlock(?int $userId): ?array
    {
        if ($this->quotas === null || $userId === null) {
            return null;
        }
        try {
            $verdict = $this->quotas->checkForRun($userId);
        } catch (\Throwable $e) {
            Logger::warn('council.quota_check_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return null;
        }
        if (($verdict['action'] ?? 'ok') === 'block') {
            return $verdict;
        }
        return null;
    }

    /**
     * POST /api/council/control — pause / continue / reject a running
     * session. Body (or query string): {session_id:int, action:string}.
     * Idempotent: repeated identical actions are no-ops.
     *
     * Cooperative semantics: the running PHP worker polls the
     * RunControl file between layers (and inside the L1 dispatcher
     * gap), so a pause/reject signal takes effect at the next safe
     * boundary. In-flight curl_multi network calls cannot be aborted
     * from another request, so a reject during L1 will land when the
     * current batch returns; a pause during L1 likewise honors at the
     * boundary between dispatcher rounds.
     */
    public function control(): string
    {
        if ($this->runControl === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'run_control_unavailable',
                'message' => 'Run control is not configured on this host.',
            ], 503);
        }
        $payload   = $this->readJsonBody();
        $sessionId = (int)($payload['session_id'] ?? $_GET['session_id'] ?? 0);
        $action    = strtolower(trim((string)($payload['action'] ?? $_GET['action'] ?? '')));
        if ($sessionId <= 0) {
            return Response::json(['ok' => false, 'error' => 'session_id is required'], 400);
        }
        $allowed = [
            RunControl::ACTION_PAUSE,
            RunControl::ACTION_CONTINUE,
            RunControl::ACTION_REJECT,
            // v1.8.20: "Stop and Run consensus".
            RunControl::ACTION_FINALIZE,
        ];
        if (!in_array($action, $allowed, true)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid action',
                'allowed' => $allowed,
            ], 400);
        }
        try {
            $rec = $this->runControl->applyAction($sessionId, $action);
        } catch (\Throwable $e) {
            return Response::json([
                'ok'      => false,
                'error'   => 'control_apply_failed',
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ], 500);
        }
        return Response::json([
            'ok'         => true,
            'session_id' => $sessionId,
            'action'     => $action,
            'state'      => $rec['state'] ?? RunControl::STATE_RUNNING,
            'updated_at' => $rec['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            'note'       => 'Cooperative semantics — takes effect at the next safe pipeline boundary. '
                          . 'In-flight model HTTP calls cannot be aborted mid-flight.',
        ]);
    }

    public function run(): string
    {
        $payload = $this->readJsonBody();
        $query   = trim((string)($payload['query'] ?? ''));
        if ($query === '') {
            return Response::json(['error' => 'query is required'], 400);
        }
        $options = $this->extractRunOptions($payload);
        $attachments = QueryAttachments::fromRequest($payload['attachments'] ?? null);
        if ($attachments['errors'] !== []) {
            return Response::json([
                'error'   => 'invalid_attachments',
                'message' => implode(' ', $attachments['errors']),
            ], 400);
        }
        if ($attachments['items'] !== []) {
            $options['attachments'] = $attachments['items'];
        }
        // v2.1.0 (T5) — attribute the run to its end user, then gate on the
        // per-user budget BEFORE the engine is touched.
        $userId = $this->resolveUserId($payload);
        if (($blocked = $this->quotaBlock($userId)) !== null) {
            $blocking = is_array($blocked['blocking'] ?? null) ? $blocked['blocking'] : [];
            $retry = isset($blocked['retry_after']) && (int)$blocked['retry_after'] > 0
                ? (int)$blocked['retry_after'] : null;
            if ($retry !== null && !headers_sent()) {
                header('Retry-After: ' . $retry);
            }
            return Response::json([
                'error'   => 'budget_exceeded',
                'message' => 'Run refused: ' . (string)($blocking['name'] ?? 'user budget') . ' is exhausted.',
                'budget'  => $blocking,
            ], 429);
        }
        if ($this->runControl !== null) {
            $this->engine->setRunControl($this->runControl);
        }
        try {
            $result = $this->engine->run(
                $query,
                $userId,
                $options,
            );
            $sessionId = (int)$result['session_id'];
            // Single-model mode returns no consensus verdict at all (nothing
            // was compared), so `consensus` is null by contract. Reporting that
            // honestly is the point: the alternative - a synthesised
            // `{"score": 0.0}` - would tell every client the council had
            // measured something it never ran.
            $body = [
                'session_id'      => $sessionId,
                'final_answer'    => $result['final_answer'],
                'consensus'       => $result['consensus'] instanceof ConsensusResult
                    ? $result['consensus']->toArray()
                    : null,
                'stats'           => $result['stats']->toArray(),
                'synthesis_stats' => $result['synthesis_stats'] ?? null,
                'selected_models' => $result['selected_models'] ?? [],
                'steps'           => $this->steps->listStepsBySession($sessionId),
                'events'          => $this->steps->listEventsBySession($sessionId),
            ];
            // Strictly additive: the consensus shape above is unchanged for
            // every other run, and a single-model run says so instead of
            // leaving the client to infer it from a null verdict.
            if (isset($result['run_mode'])) {
                $body['run_mode']     = $result['run_mode'];
                $body['single_model'] = $result['single_model'] ?? null;
            }
            if ($this->isDebugRequest()) {
                $body['debug'] = $this->buildRunDebugEnvelope($result);
            }
            return Response::json($body);
        } catch (\Throwable $e) {
            Logger::error('Pipeline failed', ['message' => $e->getMessage()]);
            return Response::json([
                'error'   => 'Pipeline failed',
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ], 500);
        }
    }

    /**
     * SSE streaming endpoint. Runs the pipeline inside a single HTTP
     * request and emits each pipeline event as it is produced. The
     * stream terminates after a final PIPELINE_DONE (or PIPELINE_FATAL)
     * event so EventSource clients can clean up.
     *
     * Compatible with PHP-FPM as long as the front-end (Apache mod_proxy
     * or nginx) does not buffer responses. We disable PHP output
     * buffering and emit a leading no-op comment to encourage proxies
     * to flush immediately.
     */
    public function stream(): string
    {
        // SSE headers must be sent before any body.
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);
        // v1.8.23: keep the PHP worker running even if the browser drops
        // the SSE connection mid-pipeline. L4 synthesis is the most
        // common spot where the EventSource layer reconnects and the
        // backend would otherwise abort the run — leaving the session
        // row half-completed and forcing the user to retry. With
        // ignore_user_abort(true) the engine completes, persists the
        // final answer, and emits PIPELINE_DONE; the frontend can then
        // recover state via GET /api/council/session/{id}.
        @ignore_user_abort(true);
        // Best-effort: stretch the time limit so a long no-consensus
        // run (multi-cycle + main-selected synthesis) doesn't hit the
        // default 30s ceiling on shared hosts.
        @set_time_limit(0);

        $rawQuery = (string)($_GET['query'] ?? '');
        $query    = trim($rawQuery);
        $streamer = new SseEventStreamer();
        $streamer->comment('RevoAp SSE pipeline stream — keepalive');

        if ($query === '') {
            $streamer->emit('PIPELINE_FATAL', ['message' => 'query is required'], null);
            return '';
        }

        $payload = [];
        $rawBody = (string)file_get_contents('php://input');
        if ($rawBody !== '') {
            $decoded = JsonHelper::tryDecode($rawBody);
            if (is_array($decoded)) { $payload = $decoded; }
        }
        $payload = array_merge($_GET ?? [], $payload);
        // selected_models is JSON-encoded over the query string.
        if (isset($payload['selected_models']) && is_string($payload['selected_models'])) {
            $decoded = JsonHelper::tryDecode($payload['selected_models']);
            if (is_array($decoded)) {
                $payload['selected_models'] = $decoded;
            }
        }
        // consensus_skill may arrive as JSON or a plain slug.
        if (isset($payload['consensus_skill']) && is_string($payload['consensus_skill'])) {
            $decoded = JsonHelper::tryDecode($payload['consensus_skill']);
            if (is_array($decoded)) {
                $payload['consensus_skill'] = $decoded;
            }
        }
        $payload['query'] = $query;
        $options = $this->extractRunOptions($payload);
        $attachments = QueryAttachments::fromRequest($payload['attachments'] ?? null);
        if ($attachments['errors'] !== []) {
            $streamer->emit('PIPELINE_FATAL', ['message' => 'Attachments: ' . implode(' ', $attachments['errors'])], null);
            return '';
        }
        if ($attachments['items'] !== []) {
            $options['attachments'] = $attachments['items'];
        }

        // v1.8.21: protect against EventSource auto-reconnect re-executing
        // the pipeline. The client passes a stable client_run_id; if a
        // worker is already running the same id we emit a duplicate event
        // and close instead of forking a second pipeline.
        $clientRunId = (string)($payload['client_run_id'] ?? $_GET['client_run_id'] ?? '');
        if ($clientRunId === '' && isset($_SERVER['HTTP_LAST_EVENT_ID'])) {
            $clientRunId = (string)$_SERVER['HTTP_LAST_EVENT_ID'];
        }
        // Fall back to a fingerprint when the client failed to mint an id.
        if ($clientRunId === '') {
            $clientRunId = 'fp_' . substr(hash('sha256', $query . '|' . json_encode($options)), 0, 24);
        }
        if ($this->streamLock !== null) {
            $existing = $this->streamLock->read($clientRunId);
            $acquired = $this->streamLock->acquire($clientRunId);
            if (!$acquired) {
                $streamer->emit('PIPELINE_DUPLICATE_RUN_REJECTED', [
                    'client_run_id' => $clientRunId,
                    'reason'        => 'another worker is already running this client_run_id',
                    'existing'      => $existing,
                    'detail'        => 'EventSource auto-reconnect or duplicate submission detected. '
                                     . 'The original pipeline run is still in flight — this duplicate '
                                     . 'connection will not start a new run. Close the duplicate tab '
                                     . 'or wait for the original run to finish.',
                ], null);
                // PIPELINE_DONE so the EventSource closes cleanly without
                // attempting another reconnect.
                $streamer->emit('PIPELINE_DONE', [
                    'reason'        => 'duplicate_run_rejected',
                    'client_run_id' => $clientRunId,
                ], null);
                return '';
            }
        }

        $this->engine->setEventStreamer($streamer);
        if ($this->runControl !== null) {
            $this->engine->setRunControl($this->runControl);
        }
        // Snapshot the session id onto the lock the moment PipelineEngine
        // emits PIPELINE_STARTED so a parallel duplicate connection can
        // report the original session id.
        $self = $this;
        $streamer->setOnEmit(function (string $type, array $payload) use ($self, $clientRunId): void {
            if ($self->streamLock === null) { return; }
            if ($type === 'PIPELINE_STARTED' && isset($payload['session_id'])) {
                $sid = (int)$payload['session_id'];
                if ($sid > 0) {
                    $self->streamLock->recordSession($clientRunId, $sid);
                }
            }
        });
        // v2.1.0 (T5) — the same gate as run(), rendered as a terminal SSE
        // frame: the headers are already on the wire, so a 429 status is no
        // longer expressible. PIPELINE_FATAL is the frame the front end
        // already treats as "this run will not produce an answer".
        $streamUserId = $this->resolveUserId($payload);
        if (($blocked = $this->quotaBlock($streamUserId)) !== null) {
            $blocking = is_array($blocked['blocking'] ?? null) ? $blocked['blocking'] : [];
            $streamer->emit('PIPELINE_FATAL', [
                'message' => 'budget_exceeded: ' . (string)($blocking['name'] ?? 'user budget') . ' is exhausted.',
                'reason'  => 'budget_exceeded',
                'budget'  => $blocking,
            ], null);
            // Release exactly what the try/finally below would have: the
            // lock was acquired above, and an early return that skips the
            // release would strand this client_run_id until the TTL — the
            // user's retry after topping up the budget would then be
            // refused as a duplicate of the run that never started.
            $this->engine->setEventStreamer(null);
            $this->releaseStreamLock($clientRunId);
            return '';
        }
        try {
            $this->engine->run($query, $streamUserId, array_merge(
                $options,
                ['client_run_id' => $clientRunId],
            ));
        } catch (\Throwable $e) {
            // The engine's own catch already emitted PIPELINE_FATAL +
            // marked running steps as failed. We only run a defensive
            // fallback for the case where the throw bypassed it (e.g.
            // a fatal during L0 before the engine took over). Also
            // hand-emit a stream-level fatal so EventSource clients
            // observe a terminal frame even when the pipeline failed
            // before its own emit fired.
            $message = Logger::sanitizeScalar($e->getMessage());
            $sessionId = $this->engine->currentSessionId();
            if ($sessionId !== null) {
                try { $this->engine->finalizeRunningSteps($sessionId, $message); } catch (\Throwable) {}
            }
            $streamer->emit('PIPELINE_FATAL', [
                'message' => $message,
                'class'   => (new \ReflectionClass($e))->getShortName(),
            ], null);
        } finally {
            $this->engine->setEventStreamer(null);
            $this->releaseStreamLock($clientRunId);
        }
        return '';
    }

    /**
     * Drop the duplicate-run lock for this client_run_id. The empty-id guard
     * lives HERE (once): release('') would resolve to the shared 'anon' lock
     * file and release somebody else's claim.
     */
    private function releaseStreamLock(string $clientRunId): void
    {
        if ($this->streamLock !== null && $clientRunId !== '') {
            $this->streamLock->release($clientRunId);
        }
    }

    /** @param array<string,string> $params */
    public function getSession(array $params): string
    {
        $id      = (int)($params['id'] ?? 0);
        $session = $this->sessions->find($id);
        if ($session === null) {
            $body = ['error' => 'session not found', 'session_id' => $id];
            if ($this->isDebugRequest()) {
                $storageMode = $this->pdo === null ? 'memory' : 'persistent';
                $body['debug'] = [
                    'storage_mode' => $storageMode,
                    'reason' => $this->pdo === null
                        ? 'Database not connected — sessions are kept in memory only and do not survive '
                            . 'across requests. The session id you requested either never existed in this '
                            . 'process or belonged to a previous request whose memory has been released.'
                        : 'No row with id ' . $id . ' was found in council_sessions.',
                    'suggestion' => $this->pdo === null
                        ? 'Configure database.dsn in config.php and apply db/schema.sql, then re-run '
                            . 'POST /api/council/run. The returned session_id will then be retrievable.'
                        : 'Verify that the id was returned by a successful POST /api/council/run on this database.',
                    'last_db_error' => [
                        'class'   => Diagnostics::lastDbErrorClass(),
                        'message' => Diagnostics::lastDbErrorMessage(),
                    ],
                ];
            }
            return Response::json($body, 404);
        }
        $steps  = $this->steps->listStepsBySession($id);
        // v1.8.23: expose the persisted final answer + consensus envelope
        // so the frontend can recover after an SSE drop. The session row
        // already carries final_answer / consensus_score / consensus_status
        // once SessionRepository::complete() has run, regardless of
        // whether the EventSource layer stayed connected.
        $events = $this->steps->listEventsBySession($id);
        $finalAnswer    = isset($session['final_answer']) ? (string)$session['final_answer'] : '';
        $statusStr      = isset($session['status']) ? (string)$session['status'] : '';
        $consensusScore = isset($session['consensus_score']) && is_numeric($session['consensus_score'])
            ? (float)$session['consensus_score']
            : null;
        $consensusStatus= isset($session['consensus_status']) ? (string)$session['consensus_status'] : '';

        // Pull synthesis_stats off the most recent L4_SYNTHESIS event if
        // present so the recovered Final Answer card can render the same
        // stats the live stream would have shown.
        $synthesisStats = null;
        foreach (array_reverse($events) as $ev) {
            if (($ev['event_type'] ?? '') !== 'L4_SYNTHESIS') {
                continue;
            }
            $payload = $ev['payload'] ?? [];
            if (is_string($payload)) {
                $decoded = JsonHelper::tryDecode($payload);
                $payload = is_array($decoded) ? $decoded : [];
            }
            if (is_array($payload)) {
                $synthesisStats = $payload;
            }
            break;
        }
        $completed = $statusStr === 'completed' && $finalAnswer !== '';
        return Response::json([
            'session'         => $session,
            'steps'           => $steps,
            'events'          => $events,
            'final_answer'    => $finalAnswer,
            'consensus'       => [
                'score'  => $consensusScore,
                'status' => $consensusStatus,
            ],
            'synthesis_stats' => $synthesisStats,
            'completed'       => $completed,
        ]);
    }

    /** @param array<string,string> $params */
    public function getEvents(array $params): string
    {
        $id     = (int)($params['id'] ?? 0);
        $events = $this->steps->listEventsBySession($id);
        return Response::json(['events' => $events]);
    }

    /**
     * Normalize the run options carried in a JSON body or query string.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function extractRunOptions(array $payload): array
    {
        $opts = [];
        if (isset($payload['selected_models'])) {
            $sm = $payload['selected_models'];
            if (is_string($sm)) {
                $decoded = JsonHelper::tryDecode($sm);
                if (is_array($decoded)) { $sm = $decoded; }
            }
            if (is_array($sm)) {
                $opts['selected_models'] = $sm;
            }
        }
        if (isset($payload['reasoning'])) {
            $opts['reasoning'] = is_array($payload['reasoning'])
                ? $payload['reasoning']
                : (string)$payload['reasoning'];
        }
        // Consensus skill: lets the front-end pick which consensus
        // scoring strategy to use for this run. Accepts either a slug
        // string (e.g. "semantic_consensus_reasoning") or a structured
        // object (e.g. {"slug":"...","scoring":"semantic_components"}).
        if (isset($payload['consensus_skill'])) {
            $cs = $payload['consensus_skill'];
            if (is_string($cs)) {
                $decoded = JsonHelper::tryDecode($cs);
                $cs = is_array($decoded) ? $decoded : $cs;
            }
            if (is_string($cs)) {
                $opts['consensus_skill'] = preg_replace('/[^a-z0-9_\-]/i', '', $cs) ?? '';
            } elseif (is_array($cs)) {
                $opts['consensus_skill'] = $cs;
            }
        }
        // Cycle limit (v1.8.19): operator-supplied per-request override of
        // the consensus cycle ceiling. Accepts a numeric string or integer.
        // The engine clamps it server-side to the validated range; here we
        // only forward what we got, leaving validation to PipelineEngine
        // so a single source of truth governs the clamp + event emission.
        if (isset($payload['cycle_limit'])) {
            $raw = $payload['cycle_limit'];
            if (is_array($raw)) {
                $raw = $raw['cycle_limit'] ?? null;
            }
            if (is_string($raw) && is_numeric($raw)) {
                $opts['cycle_limit'] = (int)$raw;
            } elseif (is_int($raw)) {
                $opts['cycle_limit'] = $raw;
            } elseif (is_float($raw)) {
                $opts['cycle_limit'] = (int)$raw;
            }
        }
        // Single-model mode: ask ONE model, use its answer verbatim, compute
        // no consensus. Accepted strictly - only a literal truthy value counts,
        // exactly like the tab flags - because a malformed body must never
        // silently turn a consensus run into a single call (or the reverse).
        // The engine decides whether the database can carry the verdict and
        // degrades to the consensus path with a warning when it cannot.
        if (array_key_exists('single_model', $payload)) {
            $flag = $payload['single_model'];
            if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true' || $flag === 'on') {
                $opts['single_model'] = true;
            } elseif ($flag === false || $flag === 0 || $flag === '0' || $flag === '' || $flag === null) {
                $opts['single_model'] = false;
            }
        }
        return $opts;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return $_POST;
        }
        $parsed = JsonHelper::tryDecode($raw);
        return is_array($parsed) ? $parsed : [];
    }

    private function isDebugRequest(): bool
    {
        if (Config::isDebug()) {
            return true;
        }
        foreach ($_GET as $key => $value) {
            if (strcasecmp((string)$key, 'debug') !== 0) {
                continue;
            }
            $v = is_scalar($value) ? trim((string)$value) : '';
            if ($v === '' || $v === '1' || strcasecmp($v, 'true') === 0 || strcasecmp($v, 'on') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function buildRunDebugEnvelope(array $result): array
    {
        $envelope = [
            'storage_mode' => $this->pdo === null ? 'memory' : 'persistent',
            'trace'        => $this->engine->trace(),
        ];
        if ($this->connectors !== null) {
            $envelope['diagnostics'] = Diagnostics::buildReport($this->connectors, $this->pdo);
        }
        return Logger::sanitize($envelope);
    }
}
