<?php
/**
 * StatisticsAggregator: pure function that turns a flat list of pre-fetched
 * rows into the JSON payload returned by /api/statistics/summary.
 *
 * Inputs:
 *   - $sessions: rows from council_sessions filtered by period.
 *   - $modelRows: per-call rows joined from council_steps.meta JSON
 *                 (or council_model_responses + step.meta) — one row per
 *                 LLM invocation, carrying provider, model, tokens, cost,
 *                 latency, cache_hit, cached_tokens, reasoning_tokens,
 *                 tokens_estimated, status.
 *
 * Cost handling:
 *   Cost is summed only when the provider returned a numeric value. If no
 *   row in a group carries a cost, the corresponding total/avg is left as
 *   null so the UI can show "—" instead of a misleading 0.0.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\Statistics;

final class StatisticsAggregator
{
    /**
     * @param array<int,array<string,mixed>> $sessions  rows from council_sessions
     * @param array<int,array<string,mixed>> $modelRows rows from per-call meta (one per LLM invocation)
     * @return array<string,mixed>
     */
    public function aggregate(array $sessions, array $modelRows): array
    {
        $sessionTotal     = 0;
        $sessionCompleted = 0;
        $sessionFailed    = 0;
        $sessionRunning   = 0;
        $totalDurationMs  = 0;
        $durationSeen     = 0;

        foreach ($sessions as $s) {
            $sessionTotal++;
            $status = (string)($s['status'] ?? '');
            if ($status === 'completed') { $sessionCompleted++; }
            elseif ($status === 'failed') { $sessionFailed++; }
            elseif ($status === 'running') { $sessionRunning++; }

            $dur = (int)($s['duration_ms'] ?? 0);
            if ($dur > 0) {
                $totalDurationMs += $dur;
                $durationSeen++;
            }
        }

        $totalCalls          = 0;
        $callsCompleted      = 0;
        $callsFailed         = 0;
        $totalPrompt         = 0;
        $totalCompletion     = 0;
        $totalTokens         = 0;
        $totalCachedTokens   = 0;
        $totalReasoningTok   = 0;
        $totalLatencyMs      = 0;
        $latencySeen         = 0;
        $totalCost           = 0.0;
        $costSeen            = false;
        $costRowCount        = 0;
        $cacheHitCount       = 0;
        $estimatedTokenRows  = 0;

        /** @var array<string,array<string,mixed>> $perModel */
        $perModel = [];

        foreach ($modelRows as $row) {
            $totalCalls++;
            $status = (string)($row['status'] ?? 'completed');
            if ($status === 'failed') { $callsFailed++; } else { $callsCompleted++; }

            $provider = (string)($row['provider'] ?? '');
            $model    = (string)($row['model']    ?? '');
            if ($provider === '' && $model === '') { continue; }
            $key      = $provider . '|' . $model;

            $prompt    = (int)($row['prompt_tokens']     ?? 0);
            $completion= (int)($row['completion_tokens'] ?? 0);
            $tokens    = (int)($row['total_tokens']      ?? 0);
            if ($tokens === 0 && ($prompt + $completion) > 0) {
                $tokens = $prompt + $completion;
            }
            $latency   = (int)($row['latency_ms']        ?? 0);
            $cachedTok = (int)($row['cached_tokens']     ?? 0);
            $reasonTok = (int)($row['reasoning_tokens']  ?? 0);
            $cacheHit  = (bool)($row['cache_hit']        ?? false);
            $estimated = (bool)($row['tokens_estimated'] ?? false);

            $costRaw = $row['cost'] ?? null;
            $cost    = is_numeric($costRaw) ? (float)$costRaw : null;

            $totalPrompt       += $prompt;
            $totalCompletion   += $completion;
            $totalTokens       += $tokens;
            $totalCachedTokens += $cachedTok;
            $totalReasoningTok += $reasonTok;
            if ($latency > 0) {
                $totalLatencyMs += $latency;
                $latencySeen++;
            }
            if ($cacheHit) { $cacheHitCount++; }
            if ($estimated) { $estimatedTokenRows++; }
            if ($cost !== null) {
                $totalCost += $cost;
                $costSeen = true;
                $costRowCount++;
            }

            if (!isset($perModel[$key])) {
                $perModel[$key] = [
                    'provider'           => $provider,
                    'model'              => $model,
                    'calls'              => 0,
                    'success'            => 0,
                    'failed'             => 0,
                    'prompt_tokens'      => 0,
                    'completion_tokens'  => 0,
                    'total_tokens'       => 0,
                    'cached_tokens'      => 0,
                    'reasoning_tokens'   => 0,
                    'cache_hits'         => 0,
                    'estimated_rows'     => 0,
                    'total_latency_ms'   => 0,
                    'latency_seen'       => 0,
                    'cost'               => 0.0,
                    'cost_seen'          => false,
                    'cost_rows'          => 0,
                ];
            }
            $m = &$perModel[$key];
            $m['calls']++;
            if ($status === 'failed') { $m['failed']++; } else { $m['success']++; }
            $m['prompt_tokens']     += $prompt;
            $m['completion_tokens'] += $completion;
            $m['total_tokens']      += $tokens;
            $m['cached_tokens']     += $cachedTok;
            $m['reasoning_tokens']  += $reasonTok;
            if ($cacheHit)  { $m['cache_hits']++; }
            if ($estimated) { $m['estimated_rows']++; }
            if ($latency > 0) {
                $m['total_latency_ms'] += $latency;
                $m['latency_seen']++;
            }
            if ($cost !== null) {
                $m['cost']      += $cost;
                $m['cost_seen']  = true;
                $m['cost_rows']++;
            }
            unset($m);
        }

        $perModelOut = [];
        foreach ($perModel as $row) {
            $avgLatency = $row['latency_seen'] > 0
                ? (int)round($row['total_latency_ms'] / $row['latency_seen'])
                : 0;
            $perModelOut[] = [
                'provider'          => $row['provider'],
                'model'             => $row['model'],
                'calls'             => $row['calls'],
                'success'           => $row['success'],
                'failed'            => $row['failed'],
                'prompt_tokens'     => $row['prompt_tokens'],
                'completion_tokens' => $row['completion_tokens'],
                'total_tokens'      => $row['total_tokens'],
                'cached_tokens'     => $row['cached_tokens'],
                'reasoning_tokens'  => $row['reasoning_tokens'],
                'cache_hits'        => $row['cache_hits'],
                'estimated_rows'    => $row['estimated_rows'],
                'total_latency_ms'  => $row['total_latency_ms'],
                'avg_latency_ms'    => $avgLatency,
                'cost'              => $row['cost_seen'] ? round($row['cost'], 6) : null,
                'avg_cost'          => $row['cost_seen'] && $row['cost_rows'] > 0
                                          ? round($row['cost'] / $row['cost_rows'], 6) : null,
            ];
        }

        usort($perModelOut, static function (array $a, array $b): int {
            $cmp = $b['calls'] <=> $a['calls'];
            if ($cmp !== 0) { return $cmp; }
            return strcmp((string)$a['provider'] . '|' . (string)$a['model'],
                          (string)$b['provider'] . '|' . (string)$b['model']);
        });

        $avgDurationMs = $durationSeen > 0
            ? (int)round($totalDurationMs / $durationSeen)
            : 0;
        $avgLatencyMs  = $latencySeen > 0
            ? (int)round($totalLatencyMs / $latencySeen)
            : 0;
        $avgCost       = $costSeen && $costRowCount > 0
            ? round($totalCost / $costRowCount, 6)
            : null;

        return [
            'sessions' => [
                'total'     => $sessionTotal,
                'completed' => $sessionCompleted,
                'failed'    => $sessionFailed,
                'running'   => $sessionRunning,
                'duration_total_ms' => $totalDurationMs,
                'duration_avg_ms'   => $avgDurationMs,
            ],
            'tokens' => [
                'prompt'     => $totalPrompt,
                'completion' => $totalCompletion,
                'total'      => $totalTokens,
                'cached'     => $totalCachedTokens,
                'reasoning'  => $totalReasoningTok,
                'estimated_rows' => $estimatedTokenRows,
            ],
            'calls' => [
                'total'      => $totalCalls,
                'success'    => $callsCompleted,
                'failed'     => $callsFailed,
                'cache_hits' => $cacheHitCount,
            ],
            'latency' => [
                'total_ms' => $totalLatencyMs,
                'avg_ms'   => $avgLatencyMs,
            ],
            'cost' => [
                'total'     => $costSeen ? round($totalCost, 6) : null,
                'avg'       => $avgCost,
                'available' => $costSeen,
            ],
            'per_model' => $perModelOut,
        ];
    }
}
