<?php
/**
 * ModelMetricsTest - how the journal describes a model as a worker (R9).
 *
 * The three rules this suite pins are the ones that keep the numbers usable:
 *
 *   - latency is a median and a tail, so one stall does not re-describe a model
 *     that is otherwise quick;
 *   - an unreported price is null, never zero, so the least-documented provider
 *     does not appear to be the cheapest;
 *   - cache behaviour is reported only when the columns carried something -
 *     no columns is "not measured", not "the cache never fired".
 *
 * Run: php tests/ModelMetricsTest.php
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

use App\Models\ModelMetrics;

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

$row = static fn(string $model, int $ms, int $tokens = 100, float|null $cost = null, int|null $reads = null, int|null $writes = null): array => [
    'model' => $model, 'provider' => 'bothub', 'latency_ms' => $ms,
    'total_tokens' => $tokens, 'cost' => $cost,
    'cache_read_tokens' => $reads, 'cache_write_tokens' => $writes,
];

// =====================================================================
echo "\n== (a) latency is a median and a tail, not an average ==\n";
// =====================================================================
$fast = ModelMetrics::fromRows([
    $row('m-fast', 1000), $row('m-fast', 1100), $row('m-fast', 1200), $row('m-fast', 1300),
    $row('m-fast', 9000),   // one stall
]);
$fastRow = $fast[0] ?? [];
check('the median ignores the single stall', ($fastRow['median_ms'] ?? 0) === 1200, json_encode($fastRow));
check('the tail reports it', ($fastRow['p95_ms'] ?? 0) === 9000 && ($fastRow['max_ms'] ?? 0) === 9000);
check('an average would have hidden the difference - and is not what is reported',
    ($fastRow['median_ms'] ?? 0) !== (int)round((1000 + 1100 + 1200 + 1300 + 9000) / 5));

$mixed = ModelMetrics::fromRows([
    $row('m-mixed', 5000), $row('m-mixed', 6000),
    $row('m-mixed', 0), $row('m-mixed', 0),   // served from the local response cache
]);
$mixedRow = $mixed[0] ?? [];
check('a call answered by the local cache does not drag the median to zero',
    ($mixedRow['median_ms'] ?? 0) === 5000, json_encode($mixedRow));
check('and the cache-answered calls are counted separately, not hidden',
    ($mixedRow['measured_calls'] ?? 0) === 2 && ($mixedRow['instant_calls'] ?? 0) === 2
    && ($mixedRow['calls'] ?? 0) === 4, json_encode($mixedRow));

// =====================================================================
echo "\n== (b) a missing price is null, not zero ==\n";
// =====================================================================
$priced = ModelMetrics::fromRows([$row('m-priced', 1000, 2000, 0.400), $row('m-priced', 1000, 2000, 0.400)]);
check('a reported price becomes a per-thousand rate',
    abs(($priced[0]['cost_per_1k'] ?? 0) - 0.2) < 0.0001, json_encode($priced[0]));
check('and the number of priced calls is kept', ($priced[0]['priced_calls'] ?? 0) === 2);

$unpriced = ModelMetrics::fromRows([$row('m-unpriced', 1000, 2000)]);
// ?? cannot tell a null value from a missing key, so the check asks for the
// key explicitly.
check('an unreported price is null, never a zero',
    array_key_exists('cost_per_1k', $unpriced[0]) && $unpriced[0]['cost_per_1k'] === null, json_encode($unpriced[0]));
check('the calls are still counted', ($unpriced[0]['calls'] ?? 0) === 1 && ($unpriced[0]['priced_calls'] ?? -1) === 0);

// =====================================================================
echo "\n== (c) cache behaviour only when it was measured ==\n";
// =====================================================================
$cached = ModelMetrics::fromRows([$row('m-cache', 1000, 100, null, 400, 100), $row('m-cache', 1000, 100, null, 0, 0)]);
check('a cache hit rate is the share of calls that read the cache',
    ($cached[0]['cache_reported'] ?? false) === true
    && ($cached[0]['cache_hit_calls'] ?? 0) === 1
    && abs(($cached[0]['cache_hit_rate'] ?? 0) - 0.5) < 0.0001
    && ($cached[0]['cache_hit_rate'] ?? 2) <= 1.0,
    json_encode($cached[0]));
check('reads and writes are summed, not averaged', ($cached[0]['cache_reads'] ?? 0) === 400 && ($cached[0]['cache_writes'] ?? 0) === 100);

$noColumns = ModelMetrics::fromRows([$row('m-plain', 1000)]);
check('a deployment without cache columns reports no cache data, not a zero rate',
    ($noColumns[0]['cache_reported'] ?? true) === false
    && array_key_exists('cache_hit_rate', $noColumns[0]) && $noColumns[0]['cache_hit_rate'] === null,
    json_encode($noColumns[0]));

// =====================================================================
echo "\n== (d) sorting, averages and empty input ==\n";
// =====================================================================
$many = ModelMetrics::fromRows([
    $row('m-a', 1000), $row('m-a', 1000),
    $row('m-b', 1000),
]);
check('the busiest model comes first', ($many[0]['model'] ?? '') === 'm-a' && ($many[0]['calls'] ?? 0) === 2);
check('average tokens are per call, not a total',
    (ModelMetrics::fromRows([$row('m', 1, 100), $row('m', 1, 300)])[0]['avg_tokens'] ?? 0) === 200);
check('empty input folds to nothing', ModelMetrics::fromRows([]) === []);
check('a row without a model is skipped', ModelMetrics::fromRows([['latency_ms' => 10]]) === []);
check('one call still yields numbers rather than an error',
    ($noColumns[0]['median_ms'] ?? null) === 1000 && ($noColumns[0]['p95_ms'] ?? null) === 1000);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
