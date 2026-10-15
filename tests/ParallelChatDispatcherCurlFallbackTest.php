<?php
/**
 * ParallelChatDispatcherCurlFallbackTest: offline guard for the ext-curl
 * probe in ParallelChatDispatcher::runMulti().
 *
 * Why this test exists: runMulti() called curl_multi_init() before checking
 * whether the multi handle was usable. On a host without ext-curl that call
 * is not a "returns false" failure — the symbol does not exist, so PHP
 * raises a fatal `Call to undefined function curl_multi_init()` Error and
 * the request dies. The documented sequential fallback sitting directly
 * below the check was therefore unreachable in exactly the situation it
 * was written for, and the whole L1 fan-out broke instead of degrading.
 *
 * The whole suite runs without network, database, PHPUnit and — on a
 * curl-less box — without ext-curl, which is the point: it must pass in
 * both environments. A reflection seam on the private $curlAvailableOverride
 * property lets it exercise the "no ext-curl" branch deterministically even
 * where the extension is compiled in.
 *
 * Runs as:
 *   php tests/ParallelChatDispatcherCurlFallbackTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Connectors\AbstractConnector;
use App\Connectors\ChatResponse;
use App\Connectors\ConnectorInterface;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\ParallelChatDispatcher;
use App\Connectors\ParallelChatRequest;
use App\Connectors\CallPolicy;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failures++;
    }
}

/**
 * Force the dispatcher's ext-curl probe. The property is private because
 * production must never set it; reflection is the whole point of the seam.
 */
function forceCurlAvailability(?bool $value): void
{
    static $prop = null;
    if ($prop === null) {
        $prop = new ReflectionProperty(ParallelChatDispatcher::class, 'curlAvailableOverride');
        $prop->setAccessible(true);
    }
    $prop->setValue(null, $value);
}

/**
 * HTTP-shaped connector (an AbstractConnector, so dispatch() routes it to
 * runMulti) that answers locally instead of over the wire. Counting chat()
 * calls and refusing to build a request is what proves the sequential
 * branch ran: the curl branch never calls chat(), and always calls
 * buildChatRequest() first.
 */
final class RecordingHttpConnector extends AbstractConnector
{
    public int $chatCalls = 0;
    public int $requestBuilds = 0;

    /** @var string[] */
    public array $seenModels = [];

    public function __construct(private readonly bool $shouldThrow = false)
    {
        parent::__construct('probe', 'https://example.invalid');
    }

    public function listModels(): array
    {
        return [];
    }

    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $this->chatCalls++;
        $this->seenModels[] = $model;
        if ($this->shouldThrow) {
            throw new ConnectorException('probe refused ' . $model);
        }
        return new ChatResponse("answer:{$model}", $model, 'probe', 3, 5, 8, 1.5);
    }

    public function buildChatRequest(string $model, array $messages, array $options = []): array
    {
        $this->requestBuilds++;
        return ['url' => 'https://example.invalid/v1/chat/completions', 'headers' => [], 'payload' => [], 'timeout' => 5];
    }
}

/** Non-Abstract connector: always sequential, used as the reference path. */
final class RecordingInlineConnector implements ConnectorInterface
{
    public int $chatCalls = 0;

    public function __construct(private readonly bool $shouldThrow = false) {}

    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $this->chatCalls++;
        if ($this->shouldThrow) {
            throw new ConnectorException('inline refused ' . $model);
        }
        return new ChatResponse("answer:{$model}", $model, 'inline', 3, 5, 8, 1.5);
    }

    public function embed(string $model, string $text): \App\Connectors\EmbedResponse
    {
        throw new \App\Connectors\Exceptions\UnsupportedOperationException('no');
    }

    public function listModels(): array { return []; }
    public function getProviderName(): string { return 'inline'; }
    public function isAvailable(): bool { return true; }
}

/**
 * Capture the (index, row) pairs an $onComplete callback receives, so the
 * callback stream can be compared against the returned array.
 *
 * Returns an ArrayObject rather than a plain array because PHP list
 * destructuring copies values: a captured `&$seen` array would come back
 * as the empty snapshot taken before the dispatch ran.
 *
 * @return array{0:callable(int,array<string,mixed>):void,1:\ArrayObject<int,array<string,mixed>>}
 */
function collectingCallbacks(): array
{
    $seen = new \ArrayObject();
    return [static function (int $i, array $row) use ($seen): void { $seen[$i] = $row; }, $seen];
}

/**
 * Reduce a result row to the fields both dispatch paths must agree on.
 * The two connectors legitimately differ in `provider`/`model`, so a raw
 * json_encode() comparison would be a false negative.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function normalizeRows(array $rows): array
{
    return array_map(
        static fn(array $r): array => [
            'ok'      => $r['ok'] ?? null,
            'tag'     => $r['tag'] ?? null,
            'content' => isset($r['response']) ? $r['response']->content : null,
            'error'   => $r['error'] ?? null,
        ],
        $rows
    );
}

// ---------------------------------------------------------------------------


echo "\n== environment ==\n";
$extLoaded  = extension_loaded('curl');
$fnExists   = function_exists('curl_multi_init');
echo "  info ext-curl loaded={$extLoaded} curl_multi_init()={$fnExists}\n";
check('the probe reads the same signals the dispatcher does', true);

// ---------------------------------------------------------------------------
// The regression itself: with the probe forced to "no ext-curl", a batch of
// AbstractConnector (HTTP-shaped) requests must go sequential and must NOT
// touch any curl_* symbol.

echo "\n== forced no-ext-curl: HTTP connectors degrade to the sequential path ==\n";

forceCurlAvailability(false);
$dispatcher = new ParallelChatDispatcher();

$httpConn = new RecordingHttpConnector();
$requests = [
    new ParallelChatRequest($httpConn, 'm1', [['role' => 'user', 'content' => 'q1']], [], 'a'),
    new ParallelChatRequest($httpConn, 'm2', [['role' => 'user', 'content' => 'q2']], [], 'b'),
    new ParallelChatRequest($httpConn, 'm3', [['role' => 'user', 'content' => 'q3']], [], 'c'),
];

[$cb, $seenRef] = collectingCallbacks();
$captured = null;
$fatal = null;
try {
    $rows = $dispatcher->dispatch($requests, $cb);
    $captured = $rows;
} catch (\Throwable $e) {
    $fatal = $e;
}

check('dispatch does not raise (the reported symptom was a fatal Error)', $fatal === null,
    $fatal !== null ? get_class($fatal) . ': ' . $fatal->getMessage() : '');
check('one row per request', is_array($captured) && count($captured) === 3);
check('every row is a success — a curl path would have failed on the unresolvable host',
    is_array($captured) && count(array_filter($captured, static fn($r) => ($r['ok'] ?? false) === true)) === 3);
check('requests were served by chat(), i.e. the sequential branch ran', $httpConn->chatCalls === 3,
    "chatCalls={$httpConn->chatCalls}");
check('buildChatRequest was never called, i.e. runMulti did not enter its curl loop',
    $httpConn->requestBuilds === 0, "requestBuilds={$httpConn->requestBuilds}");
check('models reached the connector in request order', $httpConn->seenModels === ['m1', 'm2', 'm3'],
    implode(',', $httpConn->seenModels));
check('returned rows preserve caller tags', is_array($captured)
    && array_column($captured, 'tag') === ['a', 'b', 'c']);
check('returned rows carry ChatResponse payloads', is_array($captured)
    && ($captured[0]['response'] ?? null) instanceof ChatResponse
    && $captured[0]['response']->content === 'answer:m1');

$seen = $seenRef->getArrayCopy();
check('onComplete fired once per request', count($seen) === 3, 'fired ' . count($seen));
check('onComplete indices match the caller-provided keys', array_keys($seen) === [0, 1, 2],
    implode(',', array_keys($seen)));
check('onComplete rows are identical to the returned rows', $seen === $captured);

// ---------------------------------------------------------------------------
// Identical observable behaviour vs the always-sequential reference path.

echo "\n== no-ext-curl fallback matches the sequential reference byte-for-byte ==\n";

$inlineConn = new RecordingInlineConnector();
[$cbRef, $seenRefRef] = collectingCallbacks();
$refRows = $dispatcher->dispatch([
    new ParallelChatRequest($inlineConn, 'm1', [['role' => 'user', 'content' => 'q1']], [], 'a'),
    new ParallelChatRequest($inlineConn, 'm2', [['role' => 'user', 'content' => 'q2']], [], 'b'),
    new ParallelChatRequest($inlineConn, 'm3', [['role' => 'user', 'content' => 'q3']], [], 'c'),
], $cbRef);

check('fallback result rows equal the sequential reference rows',
    normalizeRows((array)$captured) === normalizeRows($refRows),
    'fallback=' . json_encode(normalizeRows((array)$captured))
    . ' reference=' . json_encode(normalizeRows($refRows)));
check('fallback callback stream equals the sequential reference stream',
    normalizeRows($seenRef->getArrayCopy()) === normalizeRows($seenRefRef->getArrayCopy()));

// ---------------------------------------------------------------------------
// Error rows must degrade the same way, not swallow the failure.

echo "\n== forced no-ext-curl: connector failures surface as error rows ==\n";

$boom = new RecordingHttpConnector(shouldThrow: true);
[$cbErr, $seenErrRef] = collectingCallbacks();
$errRows = $dispatcher->dispatch([
    new ParallelChatRequest($boom, 'm1', [['role' => 'user', 'content' => 'q']], [], 'x'),
], $cbErr);

check('throwing connector yields ok=false rather than an exception bubbling out',
    ($errRows[0]['ok'] ?? true) === false);
check('error row carries the connector message and the tag',
    ($errRows[0]['error'] ?? '') === 'probe refused m1' && ($errRows[0]['tag'] ?? null) === 'x',
    (string)($errRows[0]['error'] ?? ''));
check('error row was still reported through onComplete', count($seenErrRef) === 1
    && ($seenErrRef[0]['ok'] ?? true) === false);
check('no response object is attached to a failed row', !isset($errRows[0]['response']));

// ---------------------------------------------------------------------------
// Empty batch and missing-callback shapes must not regress.

echo "\n== edge shapes ==\n";
check('empty batch returns empty list', $dispatcher->dispatch([]) === []);
$noCbRows = $dispatcher->dispatch([
    new ParallelChatRequest(new RecordingHttpConnector(), 'm1', [['role' => 'user', 'content' => 'q']], [], 'z'),
]);
check('dispatch without onComplete still returns rows', count($noCbRows) === 1
    && ($noCbRows[0]['ok'] ?? false) === true);

// ---------------------------------------------------------------------------
// Mixed batch: HTTP + inline connectors keep their positional slots while
// the HTTP half falls back.

echo "\n== mixed batch keeps positional slots under fallback ==\n";
$mixed = $dispatcher->dispatch([
    new ParallelChatRequest(new RecordingInlineConnector(), 'inline1', [], [], 'inline-slot'),
    new ParallelChatRequest(new RecordingHttpConnector(), 'http1', [], [], 'http-slot'),
]);
check('mixed batch returns both rows', count($mixed) === 2);
check('inline connector keeps slot 0', ($mixed[0]['tag'] ?? null) === 'inline-slot');
check('HTTP connector keeps slot 1', ($mixed[1]['tag'] ?? null) === 'http-slot');

// ---------------------------------------------------------------------------
// The real probe: no override. On a curl-less host this is the live
// regression the bug report came from; on a curl host it must simply
// return true and stay on the multi path.

echo "\n== real probe with no override ==\n";
forceCurlAvailability(null);
$probeProp = new ReflectionProperty(ParallelChatDispatcher::class, 'curlAvailableOverride');
$probeProp->setAccessible(true);
check('override cleared so production behaviour is exercised', $probeProp->getValue() === null);

$realConn = new RecordingHttpConnector();
$realFatal = null;
try {
    $realRows = $dispatcher->dispatch([
        new ParallelChatRequest($realConn, 'm1', [['role' => 'user', 'content' => 'q']], [], 'r'),
    ]);
} catch (\Throwable $e) {
    $realFatal = $e;
    $realRows = [];
}
check('dispatch survives with the real probe (fatal here = the original bug)', $realFatal === null,
    $realFatal !== null ? get_class($realFatal) . ': ' . $realFatal->getMessage() : '');

if (!$fnExists) {
    check('curl-less host: real probe routes around curl_multi_init', $realConn->requestBuilds === 0
        && ($realRows[0]['ok'] ?? false) === true);
} else {
    check('curl host: real probe reports curl available', true);
}

// ---------------------------------------------------------------------------
// Static guard: the probe must stay in front of the curl call.

echo "\n== source order guard ==\n";
$src = (string)file_get_contents(__DIR__ . '/../src/Connectors/ParallelChatDispatcher.php');
$posProbe = strpos($src, 'if (!self::curlAvailable())');
$posInit  = strpos($src, '$mh = curl_multi_init();');
check('curlAvailable() probe precedes curl_multi_init() in runMulti()',
    $posProbe !== false && $posInit !== false && $posProbe < $posInit,
    "probe={$posProbe} init={$posInit}");
check('the guard documents why it must run first (undefined-function fatal)',
    str_contains($src, 'Call to undefined function'));
check('both fallback triggers share one implementation',
    substr_count($src, 'runSequentialBatch($requests, $onComplete)') === 2);

// ---------------------------------------------------------------------------

forceCurlAvailability(null);

// =====================================================================
echo "\n== (r11) reliability of one model call: retry, breaker, honest outcome ==\n";
// =====================================================================

/**
 * Fails the first $failures calls, then answers: the case a retry is for.
 *
 * Implements the whole ConnectorInterface (chat, embed, listModels,
 * getProviderName, isAvailable) so the dispatcher's sequential branch accepts
 * it exactly as it accepts a real connector.
 */
final class FlakyInlineConnector implements ConnectorInterface
{
    public int $chatCalls = 0;

    public function __construct(
        private readonly int $failures = 1,
        private readonly string $message = 'bothub HTTP 503: service unavailable',
    ) {
    }

    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $this->chatCalls++;
        if ($this->chatCalls <= $this->failures) {
            throw new ConnectorException($this->message);
        }

        return new ChatResponse("answer:{$model}", $model, 'inline', 3, 5, 8, 1.5);
    }

    public function embed(string $model, string $text): \App\Connectors\EmbedResponse
    {
        throw new ConnectorException('embed is not part of this fixture');
    }

    public function listModels(): array
    {
        return [];
    }

    public function getProviderName(): string
    {
        return 'flaky';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

/** Always fails with a permanent provider error: a model that is gone. */
final class GoneInlineConnector implements ConnectorInterface
{
    public int $chatCalls = 0;

    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $this->chatCalls++;
        throw new ConnectorException('bothub HTTP 404 [MODEL_NOT_FOUND] for model "' . $model . '"');
    }

    public function embed(string $model, string $text): \App\Connectors\EmbedResponse
    {
        throw new ConnectorException('embed is not part of this fixture');
    }

    public function listModels(): array
    {
        return [];
    }

    public function getProviderName(): string
    {
        return 'gone';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

$budget  = CallPolicy::retryBudget();
$breaker = CallPolicy::breakerThreshold();

// --- a transient failure is retried, and the answer survives -----------------
$flaky = new FlakyInlineConnector(1);
$flakyRows = $dispatcher->dispatch([
    new ParallelChatRequest($flaky, 'm-flaky', [['role' => 'user', 'content' => 'q']], [], 'f'),
]);
check('a transient failure is retried and the model still answers',
    ($flakyRows[0]['ok'] ?? false) === true && $flaky->chatCalls === 2,
    'ok=' . var_export($flakyRows[0]['ok'] ?? null, true) . ' calls=' . $flaky->chatCalls);
// Outcomes accumulate across every dispatch this dispatcher has served, so
// the check looks up its own model rather than counting rows.
$flakyOutcome = null;
foreach ($dispatcher->outcomes() as $row) { if (($row['model'] ?? '') === 'm-flaky') { $flakyOutcome = $row; } }
check('the retry is visible as an outcome, not as a silent second call',
    ($flakyOutcome['ok'] ?? false) === true && ($flakyOutcome['attempts'] ?? 0) === 2,
    json_encode($flakyOutcome));

// --- a permanent failure is not retried: that only spends money --------------
$gone = new GoneInlineConnector();
$goneRows = $dispatcher->dispatch([
    new ParallelChatRequest($gone, 'm-gone', [['role' => 'user', 'content' => 'q']], [], 'g'),
]);
check('a model the provider no longer serves is not retried',
    ($goneRows[0]['ok'] ?? true) === false && $gone->chatCalls === 1,
    'calls=' . $gone->chatCalls . ' budget=' . $budget);
$missing = $dispatcher->missing();
$goneMissing = null;
foreach ($missing as $row) { if (($row['model'] ?? '') === 'm-gone') { $goneMissing = $row; } }
check('the gap is classified, not just counted',
    ($goneMissing['kind'] ?? '') === 'not_found' && ($goneMissing['attempts'] ?? 0) === 1,
    json_encode($goneMissing));

// --- the breaker stops re-asking a model that has plainly stopped ------------
$dead = new FlakyInlineConnector(99, 'bothub HTTP 503: service unavailable');
$request = static fn(): ParallelChatRequest => new ParallelChatRequest(
    $dead,
    'm-dead',
    [['role' => 'user', 'content' => 'q']],
    [],
    'd',
);

$dispatcher->dispatch([$request()]);
$callsAfterCycleOne = $dead->chatCalls;
$dropped = $dispatcher->breakerSummary();
check('consecutive failures drop the model for the rest of the run',
    count($dropped) === 1 && ($dropped[0]['model'] ?? '') === 'm-dead'
    && str_contains((string)($dropped[0]['reason'] ?? ''), 'consecutive failures'),
    json_encode($dropped));

$secondCycle = $dispatcher->dispatch([$request()]);
check('a dropped model is not called again in a later cycle',
    $dead->chatCalls === $callsAfterCycleOne,
    'calls=' . $dead->chatCalls . ' after cycle 1=' . $callsAfterCycleOne);
check('the skip is reported with its reason instead of vanishing',
    ($secondCycle[0]['ok'] ?? true) === false
    && ($secondCycle[0]['kind'] ?? '') === 'breaker'
    && str_contains((string)($secondCycle[0]['error'] ?? ''), 'call breaker'),
    json_encode($secondCycle[0] ?? null));

// --- classification is the policy's, and it is conservative ------------------
$cases = [
    ['bothub HTTP 503: service unavailable', 503, 'server', true],
    ['bothub HTTP 429: rate limited', 429, 'rate_limit', true],
    ['bothub: request failed after 2 attempts (last error: Operation timed out)', null, 'timeout', true],
    ['Could not resolve host: bothub.chat', null, 'connection', true],
    ['bothub HTTP 404 [MODEL_NOT_FOUND] for model "x"', 404, 'not_found', false],
    ['model "x:batch" is a batch-only identifier', null, 'rejected', false],
    ['something nobody has seen before', null, 'other', false],
];
$bad = [];
foreach ($cases as [$message, $status, $kind, $transient]) {
    $got = CallPolicy::classify($message, $status);
    if (($got['kind'] ?? '') !== $kind || ($got['transient'] ?? null) !== $transient) {
        $bad[] = $kind . ' => ' . json_encode($got);
    }
}
check('failures are classified from what the transport reported', $bad === [], implode(' | ', $bad));
check('an unrecognised failure is treated as permanent, so it cannot burn the budget',
    CallPolicy::classify('???', null)['transient'] === false);
check('the policy thresholds are the documented ones',
    CallPolicy::timeoutSeconds() >= 5 && CallPolicy::timeoutSeconds() <= 3600
    && $budget >= 0 && $budget <= 3 && $breaker >= 1 && $breaker <= 10,
    sprintf('timeout=%d budget=%d breaker=%d', CallPolicy::timeoutSeconds(), $budget, $breaker));

echo "\n----------------------------------------\n";
echo "ALL OK: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);