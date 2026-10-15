<?php
/**
 * EconomyPolicyTest: the decision core of R10 (economy mode).
 *
 * R10 makes the cheap path (batch queue, prompt caching) something the
 * application offers where it pays off - without switching anything on behind
 * the operator's back. This suite pins the three parts of that decision:
 *
 *   - `EconomyMode`: `off` / `suggest` / `prefer`, where an unknown or missing
 *     value is `off` (never a guess) and `off` produces no advice at all, so
 *     the previous behaviour is reproduced exactly;
 *   - `BatchSuitability`: a pure classifier whose objections are ordered - an
 *     empty pool, an unusable contour, a pool the provider will not batch, an
 *     operator who is waiting, a run that is already fast - and which answers
 *     "no history" with `sync` plus a reason rather than with a guess;
 *   - `CacheValuePolicy`: the one measured rule the application can state about
 *     caching - writes with no reads means the write premium is being paid for
 *     nothing - and silence where the provider's caching is undocumented;
 *   - `RunCostEstimator`: ranges, never point values, and "unknown" when there
 *     is nothing to estimate from.
 *
 * Runs without PHPUnit, a database or a network:
 *   php tests/EconomyPolicyTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-23
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Cost\BatchSuitability;
use App\Cost\CacheValuePolicy;
use App\Cost\CostHistory;
use App\Cost\EconomyAdvisor;
use App\Cost\EconomyMode;
use App\Cost\RunCostEstimator;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failures++;
    }
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-economy-' . bin2hex(random_bytes(4));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'config', 0o775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'logs', 0o775, true);

function boot(array $tree): void
{
    global $tmp;
    Config::set(array_merge(['app' => ['name' => 'ReVoAp', 'version' => '1.9.0', 'debug' => false]], $tree), $tmp);
}

// =====================================================================
echo "\n== (a) the three levels, and what an unusable value means ==\n";
// =====================================================================
boot(['economy' => ['mode' => 'off']]);
check('an explicit off', EconomyMode::fromConfig()->level() === EconomyMode::OFF);
check('off gives no advice at all', EconomyMode::fromConfig()->advises() === false);
check('off does not prefer the cheap path', EconomyMode::fromConfig()->prefers() === false);

boot(['economy' => ['mode' => 'suggest']]);
check('suggest advises', EconomyMode::fromConfig()->advises() === true);
check('suggest does not switch anything on by itself', EconomyMode::fromConfig()->prefers() === false);

boot(['economy' => ['mode' => 'PREFER']]);
check('prefer is recognised whatever the case', EconomyMode::fromConfig()->level() === EconomyMode::PREFER);
check('prefer switches the cheap path on where safe', EconomyMode::fromConfig()->prefers() === true);

boot(['economy' => ['mode' => 'banana']]);
check('an unknown level falls back to off, never to a guess',
    EconomyMode::fromConfig()->level() === EconomyMode::OFF);

boot(['economy' => ['mode' => 7]]);
check('a non-string level also falls back to off', EconomyMode::fromConfig()->level() === EconomyMode::OFF);

boot([]);
check('a missing setting is off', EconomyMode::fromConfig()->level() === EconomyMode::OFF);

boot(['economy' => ['mode' => 'prefer', 'cache_min_calls' => 0, 'cache_min_hit_rate' => 5.0, 'batch_min_seconds' => 99999]]);
check('cache_min_calls is clamped to a usable floor', EconomyMode::minCacheCalls() === 1,
    (string)EconomyMode::minCacheCalls());
check('cache_min_hit_rate is clamped to 0..1', EconomyMode::minCacheHitRate() === 1.0,
    (string)EconomyMode::minCacheHitRate());
check('batch_min_seconds is clamped to a sane ceiling', EconomyMode::minBatchSeconds() === 7200,
    (string)EconomyMode::minBatchSeconds());

boot(['economy' => ['mode' => 'prefer', 'cache_min_calls' => 'garbage', 'batch_min_seconds' => -5]]);
check('a garbage threshold falls back to its documented default rather than disabling the advice',
    EconomyMode::minCacheCalls() === 20 && EconomyMode::minBatchSeconds() === 5,
    EconomyMode::minCacheCalls() . '/' . EconomyMode::minBatchSeconds());

check('every level says what it does', str_contains(EconomyMode::named('off')->label(), 'nothing')
    && str_contains(EconomyMode::named('prefer')->label(), 'cheap path'));

// =====================================================================
echo "\n== (b) the batch classifier: objections in order, never a silent delay ==\n";
// =====================================================================
$ready = ['run_mode' => 'sync', 'pool_size' => 6, 'batch_capable' => true, 'batch_ready' => true, 'interactive' => false];

// Overrides have to merge: + on arrays keeps the LEFT operand's keys, so the
// first version of this suite tested nothing it meant to test.

$r = BatchSuitability::decide(['run_mode' => 'sync', 'pool_size' => 0, 'batch_capable' => true, 'batch_ready' => true]);
check('an empty pool cannot be moved to the queue',
    $r['recommend'] === BatchSuitability::SYNC && $r['decided_by'] === 'pool', json_encode($r));

$r = BatchSuitability::decide([...$ready, 'run_mode' => 'batch']);
check('a run already on the Batch API is not "recommended" it again',
    $r['recommend'] === BatchSuitability::BATCH && $r['decided_by'] === 'already', json_encode($r));

$r = BatchSuitability::decide([...$ready, 'batch_ready' => false]);
check('an unusable contour keeps the run synchronous',
    $r['recommend'] === BatchSuitability::SYNC && $r['decided_by'] === 'contour', json_encode($r));

$r = BatchSuitability::decide([...$ready, 'batch_capable' => false]);
check('a pool the provider will not batch stays synchronous',
    $r['recommend'] === BatchSuitability::SYNC && $r['decided_by'] === 'capability', json_encode($r));

$r = BatchSuitability::decide([...$ready, 'interactive' => true, 'expected_seconds' => 900]);
check('an operator who is waiting is never told to wait hours',
    $r['recommend'] === BatchSuitability::SYNC && $r['decided_by'] === 'interactive', json_encode($r));

boot(['economy' => ['mode' => 'suggest', 'batch_min_seconds' => 120]]);
$r = BatchSuitability::decide([...$ready, 'expected_seconds' => 30]);
check('a run that is already quick stays synchronous',
    $r['recommend'] === BatchSuitability::SYNC && $r['decided_by'] === 'fast', json_encode($r));

$r = BatchSuitability::decide([...$ready, 'expected_seconds' => 900]);
check('a slow, non-interactive, batchable run is recommended for the queue',
    $r['recommend'] === BatchSuitability::BATCH && $r['decided_by'] === 'slow', json_encode($r));

$r = BatchSuitability::decide($ready);
check('no history means no recommendation - and it says so',
    $r['recommend'] === BatchSuitability::SYNC && $r['decided_by'] === 'unknown'
    && str_contains($r['reason'], 'no history'), json_encode($r));

check('every verdict carries a reason the operator can argue with',
    $r['reason'] !== '' && BatchSuitability::decide([...$ready, 'expected_seconds' => 900])['reason'] !== '');

// =====================================================================
echo "\n== (c) cache advice: only what was measured, silence when undocumented ==\n";
// =====================================================================
$undocumented = CacheValuePolicy::advise(['calls' => 50, 'reads' => 0, 'writes' => 50], null);
check('an undocumented family gets no advice in either direction',
    $undocumented['advice'] === CacheValuePolicy::UNKNOWN, json_encode($undocumented));

$automatic = CacheValuePolicy::advise(
    ['calls' => 50, 'reads' => 0, 'writes' => 0],
    ['family' => 'openai', 'explicit_breakpoints' => false],
);
check('an automatic provider has nothing to switch off',
    $automatic['advice'] === CacheValuePolicy::KEEP
    && str_contains($automatic['reason'], 'automatically'), json_encode($automatic));

$few = CacheValuePolicy::advise(
    ['calls' => 3, 'reads' => 0, 'writes' => 3],
    ['family' => 'anthropic', 'explicit_breakpoints' => true],
);
check('too few calls means no verdict yet',
    $few['advice'] === CacheValuePolicy::KEEP && str_contains($few['reason'], 'at least'), json_encode($few));

$wasted = CacheValuePolicy::advise(
    ['calls' => 40, 'reads' => 0, 'writes' => 40],
    ['family' => 'anthropic', 'explicit_breakpoints' => true],
);
check('writes with no reads is the case the operator is paying a premium for',
    $wasted['advice'] === CacheValuePolicy::DROP
    && str_contains($wasted['reason'], '1.25'), json_encode($wasted));

$working = CacheValuePolicy::advise(
    ['calls' => 40, 'reads' => 30, 'writes' => 40],
    ['family' => 'anthropic', 'explicit_breakpoints' => true],
);
check('a cache that is read is kept, with the rate stated',
    $working['advice'] === CacheValuePolicy::KEEP && $working['hit_rate'] === 0.75, json_encode($working));

check('the hit rate is what was measured, not a rounded guess',
    abs($working['hit_rate'] - (30 / 40)) < 0.0001);

// =====================================================================
echo "\n== (d) the estimator: ranges, and an honest unknown ==\n";
// =====================================================================
$none = RunCostEstimator::fromObservations([], 1);
check('no observations gives no numbers', $none['cost_low'] === null && $none['samples'] === 0, json_encode($none));
check('no observations is stated as unknown, not as zero cost',
    str_contains($none['notes'], 'unknown'), $none['notes']);

$est = RunCostEstimator::fromObservations([
    ['model' => 'bothub/gpt-6-astra-pro', 'cost' => 0.30, 'latency_ms' => 20_000, 'calls' => 2],
    ['model' => 'bothub/glm-5.3', 'cost' => 0.10, 'latency_ms' => 8_000, 'calls' => 1],
], 2);
check('a range, not a point value', $est['cost_low'] < $est['cost_high'], json_encode($est));
check('the range scales with the cycle limit',
    RunCostEstimator::fromObservations([
        ['model' => 'm', 'cost' => 0.10, 'latency_ms' => 1000, 'calls' => 1],
    ], 3)['cost_low'] > RunCostEstimator::fromObservations([
        ['model' => 'm', 'cost' => 0.10, 'latency_ms' => 1000, 'calls' => 1],
    ], 1)['cost_low']);
check('an ETA is produced in seconds', is_int($est['eta_low_s']) && $est['eta_low_s'] > 0
    && $est['eta_high_s'] > $est['eta_low_s'], json_encode([$est['eta_low_s'], $est['eta_high_s']]));
check('per-model figures are kept for the operator', count($est['per_model']) === 2);
check('a small sample is labelled as such', str_contains($est['notes'], 'rough order of magnitude'), $est['notes']);

$batch = RunCostEstimator::batchAlternative($est);
check('the batch comparison is an estimate and says so',
    $batch['low'] < $est['cost_low'] && str_contains($batch['note'], 'not measured'), json_encode($batch));
check('the batch comparison is safe with no estimate',
    RunCostEstimator::batchAlternative(['cost_low' => null, 'cost_high' => null])['low'] === null);

// =====================================================================
echo "\n== (e) cost history: totals, not pre-averaged, and honest about missing prices ==\n";
// =====================================================================
$rows = CostHistory::fromRows([
    ['model' => 'bothub/gpt-6-astra-pro', 'provider' => 'bothub', 'calls' => 4,
     'latency_total' => 40_000, 'tokens_total' => 8_000, 'cost_total' => 0.40, 'cost_calls' => 3],
    ['provider' => 'bothub', 'calls' => 2, 'latency_total' => 1_000],
]);
check('a row without a model is skipped', count($rows) === 1, json_encode($rows));
check('totals stay totals (the estimator does the dividing)',
    $rows[0]['latency_ms'] === 40_000.0 && $rows[0]['cost'] === 0.40 && $rows[0]['calls'] === 4, json_encode($rows[0]));
check('the priced-call count is kept, so "no price" never reads as free',
    $rows[0]['cost_reported'] === 3, (string)$rows[0]['cost_reported']);

$priced = CostHistory::toEstimatorInput([
    ['model' => 'a', 'calls' => 4, 'latency_ms' => 40_000, 'cost' => 0.40, 'cost_reported' => 4],
]);
check('fully priced history needs no caveat', $priced['note'] === '' && $priced['priced_calls'] === 4, json_encode($priced));

$unpriced = CostHistory::toEstimatorInput([
    ['model' => 'a', 'calls' => 4, 'latency_ms' => 40_000, 'cost' => 0.0, 'cost_reported' => 0],
]);
check('history with no prices still yields a time estimate, and says money is impossible',
    $unpriced['rows'] !== [] && str_contains($unpriced['note'], 'impossible'), $unpriced['note']);

$partial = CostHistory::toEstimatorInput([
    ['model' => 'a', 'calls' => 4, 'latency_ms' => 40_000, 'cost' => 0.40, 'cost_reported' => 2],
]);
check('a partly priced history is called a floor, not a range',
    str_contains($partial['note'], 'floor'), $partial['note']);

$nothing = CostHistory::toEstimatorInput([]);
check('no history is stated plainly', $nothing['rows'] === [] && str_contains($nothing['note'], 'No comparable calls'), $nothing['note']);

check('a history with no database answers "no history" instead of failing',
    (new CostHistory(null))->observations() === []);
// =====================================================================
echo "\n== (f) the per-run ceiling is a warning, not a gate ==\n";
// =====================================================================
boot(['economy' => ['mode' => 'suggest', 'budget_per_run' => null]]);
check('no ceiling configured means no verdict at all', EconomyMode::budgetPerRun() === null);

boot(['economy' => ['mode' => 'suggest', 'budget_per_run' => 0]]);
check('a zero ceiling means "no ceiling", not "spend nothing"', EconomyMode::budgetPerRun() === null);

$budgetPool = ['pool' => [['provider' => 'p', 'model' => 'm']], 'run_mode' => 'sync'];
$budgetObs = [['model' => 'm', 'provider' => 'p', 'calls' => 2, 'latency_ms' => 1000, 'cost' => 1.0, 'tokens' => 100, 'cost_reported' => 2]];

boot(['economy' => ['mode' => 'suggest', 'budget_per_run' => 0.5]]);
$overAdvice = EconomyAdvisor::advise($budgetPool, $budgetObs);
check('an estimate whose high end is above the ceiling is reported as exceeded',
    ($overAdvice['budget']['exceeded'] ?? false) === true, json_encode($overAdvice['budget'] ?? null));
check('and the way out is named instead of silence',
    str_contains((string)($overAdvice['budget']['note'] ?? ''), 'Not blocked'),
    (string)($overAdvice['budget']['note'] ?? ''));

boot(['economy' => ['mode' => 'suggest', 'budget_per_run' => 100.0]]);
$underAdvice = EconomyAdvisor::advise($budgetPool, $budgetObs);
check('an estimate under the ceiling is not flagged',
    ($underAdvice['budget']['exceeded'] ?? true) === false, json_encode($underAdvice['budget'] ?? null));

boot(['economy' => ['mode' => 'off', 'budget_per_run' => 0.5]]);
$offAdvice = EconomyAdvisor::advise($budgetPool, $budgetObs);
check('with economy mode off nothing is advised - including the ceiling',
    ($offAdvice['budget'] ?? null) === null && ($offAdvice['advice'] ?? true) === false,
    json_encode($offAdvice));

rmTree($tmp);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);