<?php
/**
 * SseEventContractTest: offline guard for the client/server SSE event-type
 * contract.
 *
 * Why this test exists: the replay endpoint writes an explicit
 * `event: <TYPE>` line for every persisted row. Per the EventSource
 * specification `onmessage` fires only for frames WITHOUT an `event`
 * field, so a type the frontend has no named listener for is dropped in
 * complete silence — no console error, no `anyEventSeen`, nothing for the
 * chat to render. Eleven event types the engine emits (the arbiter /
 * main-model-synthesis / repair / round-progress surface, plus
 * PIPELINE_STARTED and REQUEST_ANALYZER) were in exactly that gap until
 * v1.8.46, so the fix and the guard below cannot rely on anyone noticing a
 * missing name by reading the list.
 *
 * The check is deliberately static: it parses the engine's emitEvent()
 * call sites and the frontend's `knownTypes` array from source, so a new
 * engine event cannot ship without a matching listener.
 *
 * Runs without PHPUnit, database or network:
 *   php tests/SseEventContractTest.php
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

$root   = dirname(__DIR__);
$engine = $root . '/src/Pipeline/PipelineEngine.php';
$appJs  = $root . '/assets/js/app.js';
$jobsCt = $root . '/src/Http/Controllers/JobsController.php';

/**
 * Event-type literals passed to emitEvent(...) — the single funnel every
 * pipeline event goes through.
 *
 * @return string[]
 */
function engineEventTypes(string $file): array
{
    $src = (string)file_get_contents($file);
    $types = [];
    foreach (preg_split('/emitEvent\s*\(/', $src) ?: [] as $chunk) {
        // Only the argument list of the call, not the rest of the statement.
        $args = substr($chunk, 0, (int)strcspn($chunk, ';'));
        if (preg_match_all("/'([A-Z][A-Z0-9_]{3,})'/", $args, $m) > 0) {
            foreach ($m[1] as $t) {
                $types[$t] = true;
            }
        }
    }
    $out = array_keys($types);
    sort($out);
    return $out;
}

/** The frontend's bound event-type list. @return string[] */
function frontendKnownTypes(string $file): array
{
    $src = (string)file_get_contents($file);
    if (preg_match('/const knownTypes = \[(.*?)\];/s', $src, $m) !== 1) {
        return [];
    }
    preg_match_all("/'([A-Z0-9_]+)'/", $m[1], $hits);
    return $hits[1] ?? [];
}

echo "== the engine emits events through a single funnel ==\n";
$engineTypes = engineEventTypes($engine);
check('engine event types were discovered', count($engineTypes) >= 20, 'found=' . count($engineTypes));

$known = frontendKnownTypes($appJs);
check('the frontend binds a non-trivial set of types', count($known) >= 20, 'found=' . count($known));
check('the frontend list has no duplicate entries',
    count($known) === count(array_unique($known)),
    implode(',', array_diff_assoc($known, array_unique($known))));

echo "\n== every emitted type has a client listener ==\n";
$missing = array_values(array_diff($engineTypes, $known));
check('no engine event type is silently dropped by the client',
    $missing === [],
    'unbound: ' . implode(', ', $missing));
check('the previously-dropped types are bound', (static function (array $known): bool {
    foreach ([
        'PIPELINE_STARTED', 'REQUEST_ANALYZER',
        'CONSENSUS_ROUND_COMPLETED', 'CONSENSUS_ROUND_REPEAT',
        'L1_REPAIR_DISPATCH',
        'L4_ARBITER_STARTED', 'L4_ARBITER_FAILED',
        'L4_MAIN_MODEL_SYNTHESIS_STARTED', 'L4_MAIN_MODEL_SYNTHESIS_FAILED', 'L4_MAIN_MODEL_ATTEMPT_FAILED',
        'L4_PROVIDER_FALLBACK',
    ] as $t) {
        if (!in_array($t, $known, true)) {
            return false;
        }
    }
    return true;
})($known));

echo "\n== the wire format makes the listener list mandatory ==\n";
$jobsSrc = (string)file_get_contents($jobsCt);
check('the replay endpoint writes an explicit `event:` line per frame',
    str_contains($jobsSrc, "event: ' . \$type"),
    'the message fallback cannot compensate for a missing listener');
check('the replay endpoint declares the SSE content type',
    str_contains($jobsSrc, 'text/event-stream'));
check('the replay endpoint disables proxy buffering',
    str_contains($jobsSrc, 'X-Accel-Buffering: no'));
check('the terminal frame is always emitted',
    str_contains($jobsSrc, 'PIPELINE_REPLAY_DONE'));

echo "\n== the frontend keeps a generic fallback for stripped event fields ==\n";
check('a `message` listener is still bound',
    str_contains((string)file_get_contents($appJs), "es.addEventListener('message'"));

echo "----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
