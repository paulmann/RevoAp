<?php
declare(strict_types=1);
/**
 * FallbackScenariosTest: what happens when a model stops answering.
 *
 * The pipeline survives a dead model through two pieces: ModelBreaker counts
 * consecutive failures per model and stops dispatching to it, and CallPolicy
 * separates what may be retried from what may not. Both are the difference
 * between "one provider is down, the council still concludes" and "the run
 * dies with an empty result", so the scenarios are pinned here rather than
 * assumed:
 *
 *   1. a healthy model is never skipped;
 *   2. failures below the threshold do not open the breaker;
 *   3. reaching the threshold opens it, and the reason is kept;
 *   4. one success clears the streak (a provider that came back is used again);
 *   5. the threshold is configurable and reported;
 *   6. a transient failure is retryable, a permanent one is not - the two sets
 *      must not overlap, or the dispatcher would retry a rejected request
 *      for ever;
 *   7. the dispatcher really consults both, and the engine reports "no models
 *      at all" differently from "models answered nothing", because the two
 *      need completely different operator responses.
 *
 *   php tests/FallbackScenariosTest.php
 */
use App\Connectors\CallPolicy;
use App\Connectors\ModelBreaker;

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $c) {
    if (is_file($root . $c)) { require $root . $c; }
}
$passes = 0; $failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passes, $failed;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failed++; }
}

check('the breaker is loadable', class_exists(ModelBreaker::class));
check('the call policy is loadable', class_exists(CallPolicy::class));

$model = 'bothub/gpt-6-luna-pro';

// 1 - a healthy model
$breaker = new ModelBreaker(3);
check('a model nobody has called is not skipped', $breaker->isOpen($model) === false);
check('and it has no reason to report', $breaker->openReason($model) === '');

// 2 - failures below the threshold
$breaker->record($model, false, 'timeout');
check('one failure does not open the breaker', $breaker->isOpen($model) === false);
$breaker->record($model, false, 'timeout');
check('two failures with a threshold of three still do not open it', $breaker->isOpen($model) === false);

// 3 - the threshold
$breaker->record($model, false, 'rate_limit');
check('the third consecutive failure opens it', $breaker->isOpen($model) === true);
// The reason is reported as a sentence the operator can read, not as the
// raw code: '3 consecutive failures in this run (rate_limit)'. Both halves
// matter - the count says how badly it is failing, the code says why.
$reason = $breaker->openReason($model);
check('the reason explains why the model was dropped', str_contains($reason, 'rate_limit'), $reason);
check('and how many times it failed', str_contains($reason, '3'), $reason);
check('the reason is a readable sentence, not a bare code', $reason !== 'rate_limit' && strlen($reason) > 12, $reason);
$summary = $breaker->summary();
check('the summary is an array', is_array($summary));
check('and it mentions the broken model', str_contains(json_encode($summary, JSON_UNESCAPED_SLASHES) ?: '', $model));
check('the streak is reported', is_array($breaker->streaks()));

// 4 - recovery
$breaker->record($model, true);
check('one success clears the streak', $breaker->isOpen($model) === false);
check('and the reason is gone', $breaker->openReason($model) === '');

// 5 - configurability
$strict = new ModelBreaker(1);
$strict->record($model, false, 'server');
check('a threshold of one opens on the first failure', $strict->isOpen($model) === true);
check('the threshold is reported back', $strict->threshold() === 1, (string)$strict->threshold());
check('the default threshold is at least two', (new ModelBreaker())->threshold() >= 2, (string)(new ModelBreaker())->threshold());
check('a breaker is per model', (new ModelBreaker(1))->isOpen('other/model') === false);

// 6 - retryable versus permanent
$transient = CallPolicy::TRANSIENT;
$permanent = CallPolicy::PERMANENT;
check('a timeout is transient', in_array('timeout', $transient, true));
check('a rate limit is transient', in_array('rate_limit', $transient, true));
check('a 5xx is transient', in_array('server', $transient, true));
check('a dropped connection is transient', in_array('connection', $transient, true));
check('a missing model is permanent', in_array('not_found', $permanent, true));
check('a rejection is permanent', in_array('rejected', $permanent, true));
check('an open breaker is permanent', in_array('breaker', $permanent, true));
check('the two sets do not overlap', array_intersect($transient, $permanent) === [],
    implode(',', array_intersect($transient, $permanent)));
check('every reason has a home', count(array_unique(array_merge($transient, $permanent))) === count($transient) + count($permanent));

// 7 - the wiring the scenarios above depend on
$dispatcher = (string)file_get_contents($root . '/src/Connectors/ParallelChatDispatcher.php');
check('the dispatcher skips a broken model before dispatching', str_contains($dispatcher, '$this->breaker()->isOpen((string)$r->model)'));
check('and consults the policy before retrying', substr_count($dispatcher, "!\$verdict['transient']") >= 1);
check('and consults the policy after a retry too', substr_count($dispatcher, "!\$retryVerdict['transient']") >= 1);

$engine = (string)file_get_contents($root . '/src/Pipeline/PipelineEngine.php');
check('the engine reports "no models available" separately',
    str_contains($engine, "'L1_NO_AVAILABLE_MODELS'"));
check('and "models answered nothing" separately too',
    str_contains($engine, "'L1_NO_RESULTS'"));
check('a broken model has a reason the operator can read',
    str_contains($dispatcher . $engine, 'isOpen(') && method_exists(ModelBreaker::class, 'openReason'));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
