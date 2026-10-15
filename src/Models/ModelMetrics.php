<?php
declare(strict_types=1);

namespace App\Models;

/**
 * R9 - what the journal says about a model as a worker: how fast it answers,
 * what it costs, and how it behaves with the prompt cache.
 *
 * Pure: it folds rows in and never queries, so the arithmetic is testable
 * without a database and the reading side stays thin.
 *
 * Three deliberate choices:
 *
 *   - **Latency is reported as a median and a tail, not a mean.** A model that
 *     usually answers in 4 s but stalls for two minutes once in twenty is not a
 *     5-second model, and an average would say it is.
 *   - **A missing price is `null`, never 0.** The provider either reported a
 *     cost or it did not; a zero would make the least-documented provider look
 *     like the cheapest one.
 *   - **Cache behaviour is only reported when the columns said something.**
 *     A deployment without the 018 columns reports no cache data, which is not
 *     the same as "the cache never fired".
 *
 * The p95 is a nearest-rank value over the window: with few calls it is simply
 * the slowest one, which is the honest reading of a small sample.
 */
final class ModelMetrics
{
    /**
     * @param array<int,array<string,mixed>> $rows
     *        `{model, provider, latency_ms, total_tokens, cost, cache_read_tokens, cache_write_tokens}`
     * @return array<int,array<string,mixed>>
     */
    public static function fromRows(array $rows): array
    {
        $byModel = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $model = trim((string)($row['model'] ?? ''));
            if ($model === '') { continue; }
            $provider = (string)($row['provider'] ?? '');

            $byModel[$model] ??= [
                'model'         => $model,
                'provider'      => $provider,
                'calls'         => 0,
                'measured_calls'=> 0,
                'instant_calls' => 0,
                'tokens'        => 0,
                'cost'          => 0.0,
                'priced_calls'  => 0,
                'cache_reads'   => 0,
                'cache_writes'  => 0,
                'cache_reported'=> false,
                'cache_hit_calls'=> 0,
                'latencies'     => [],
            ];
            $entry = &$byModel[$model];
            $entry['calls']++;
            $entry['tokens'] += max(0, (int)($row['total_tokens'] ?? 0));
            $cost = $row['cost'] ?? null;
            if (is_numeric($cost)) {
                $entry['cost'] += (float)$cost;
                $entry['priced_calls']++;
            }
            $reads  = $row['cache_read_tokens'] ?? null;
            $writes = $row['cache_write_tokens'] ?? null;
            if (is_numeric($reads) || is_numeric($writes)) {
                $entry['cache_reported'] = true;
            }
            // Two different facts, kept apart: how many *calls* actually read
            // from the cache (that is a hit rate), and how many tokens came out
            // of it. The first version divided tokens by calls, which produced
            // "hit rates" above 100% - the kind of number that looks precise and
            // means nothing.
            if ((int)($reads ?? 0) > 0) {
                $entry['cache_hit_calls']++;
            }
            $entry['cache_reads']  += max(0, (int)($reads ?? 0));
            $entry['cache_writes'] += max(0, (int)($writes ?? 0));
            if (is_numeric($reads) || is_numeric($writes)) {
                $entry['cache_reported'] = true;
            }
            $latency = max(0, (int)($row['latency_ms'] ?? 0));
            // A call with no measurable latency did not go to the provider: the
            // local response cache answered it. Folding those zeros into the
            // median made half the column read "0 ms", which describes the cache
            // and not the model - so they are counted, and reported, separately.
            if ($latency > 0) {
                $entry['measured_calls']++;
                $entry['latencies'][] = $latency;
            } else {
                $entry['instant_calls']++;
            }
            unset($entry);
        }

        $out = [];
        foreach ($byModel as $entry) {
            $latencies = $entry['latencies'];
            sort($latencies);
            $calls = max(1, (int)$entry['calls']);
            $priced = (int)$entry['priced_calls'];

            $out[] = [
                'model'          => $entry['model'],
                'provider'       => $entry['provider'],
                'calls'          => (int)$entry['calls'],
                'measured_calls' => (int)$entry['measured_calls'],
                'instant_calls'  => (int)$entry['instant_calls'],
                'median_ms'      => self::percentile($latencies, 0.5),
                'p95_ms'         => self::percentile($latencies, 0.95),
                'max_ms'         => $latencies === [] ? 0 : (int)$latencies[count($latencies) - 1],
                'avg_tokens'     => (int)round($entry['tokens'] / $calls),
                // Per thousand tokens, because that is the unit providers price
                // in; null when nothing was reported rather than a fake zero.
                'cost_per_1k'    => ($priced > 0 && $entry['tokens'] > 0)
                    ? round($entry['cost'] / ($entry['tokens'] / 1000), 6)
                    : null,
                'priced_calls'   => $priced,
                'cache_reported'      => (bool)$entry['cache_reported'],
                'cache_reads'         => (int)$entry['cache_reads'],
                'cache_writes'        => (int)$entry['cache_writes'],
                // Calls that read from the cache, over all calls: a share, so it
                // cannot exceed 1. Tokens read per call are reported separately.
                'cache_hit_calls'     => (int)$entry['cache_hit_calls'],
                'cache_hit_rate'      => $entry['cache_reported']
                    ? round((int)$entry['cache_hit_calls'] / $calls, 4)
                    : null,
                'cache_reads_per_call' => $entry['cache_reported']
                    ? (int)round((int)$entry['cache_reads'] / $calls)
                    : null,
            ];
        }

        usort($out, static fn(array $a, array $b): int => $b['calls'] <=> $a['calls']);

        return $out;
    }

    /** Nearest-rank percentile over an ascending list. */
    private static function percentile(array $sorted, float $quantile): int
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }
        $rank = (int)ceil($quantile * $n);

        return (int)$sorted[max(0, min($n - 1, $rank - 1))];
    }
}