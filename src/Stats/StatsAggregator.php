<?php
/**
 * StatsAggregator: Computes SessionStats from a flat list of ChatResponse rows.
 *
 * Aggregates per-call token + latency + cost figures into per-model and
 * per-layer summaries. Cost is only summed when at least one call in the
 * group carried a provider-reported cost — otherwise the per-model cost
 * is left null so the UI shows "—" instead of a misleading 0.0.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Stats;

use App\Connectors\ChatResponse;

final class StatsAggregator
{
    /**
     * @param array<int,array{step:string,layer:string,resp:ChatResponse}> $rows
     */
    public function aggregate(array $rows, float $totalLatencyMs): SessionStats
    {
        /** @var array<string,TokenUsage> $perModel */
        $perModel = [];
        /** @var array<string,TokenUsage> $perLayer */
        $perLayer = [];
        $totalTokens = 0;
        $totalCost   = 0.0;
        $costSeen    = false;
        $roleCount   = 0;
        $cacheReadTokens   = 0;
        $cacheWriteTokens  = 0;
        $cacheCalls        = 0;
        $cacheReported     = 0;
        $totalCacheDiscount = 0.0;
        $discountSeen      = false;
        $totalCalls        = 0;

        foreach ($rows as $row) {
            $r       = $row['resp'];
            $effectiveModel = $r->effectiveModel();
            $modelId = $effectiveModel . ':' . $r->provider;
            $layer   = $row['layer'];

            $usage = new TokenUsage(
                model:            $effectiveModel,
                provider:         $r->provider,
                promptTokens:     $r->promptTokens,
                completionTokens: $r->completionTokens,
                totalTokens:      $r->totalTokens,
                latencyMs:        $r->latencyMs,
                calls:            1,
                cost:             $r->cost,
                costAvailable:    $r->cost !== null,
                cachedTokens:     $r->cachedTokens ?? 0,
                reasoningTokens:  $r->reasoningTokens ?? 0,
                tokensEstimated:  $r->tokensEstimated,
                cacheWriteTokens: $r->cacheWriteTokens ?? 0,
                // "Hit the cache" means the provider reported a positive
                // read, not merely that it answered. A call that reported a
                // zero read is counted as reported-but-missed, which is the
                // distinction the run summary has to show.
                cacheCalls:       $r->cacheWasRead() ? 1 : 0,
                cacheReportedCalls: $r->hasCacheStats() ? 1 : 0,
                cacheDiscount:    $r->cacheDiscount,
                cacheDiscountAvailable: $r->cacheDiscount !== null,
            );

            $perModel[$modelId] = isset($perModel[$modelId]) ? $perModel[$modelId]->add($usage) : $usage;
            $perLayer[$layer]   = isset($perLayer[$layer])   ? $perLayer[$layer]->add($usage)   : $usage;

            $totalTokens += $r->totalTokens;
            if ($r->cost !== null) {
                $costSeen   = true;
                $totalCost += (float)$r->cost;
            }
            $cacheReadTokens  += $r->cachedTokens ?? 0;
            $cacheWriteTokens += $r->cacheWriteTokens ?? 0;
            $totalCalls++;
            if ($r->cacheWasRead())  { $cacheCalls++; }
            if ($r->hasCacheStats()) { $cacheReported++; }
            if ($r->cacheDiscount !== null) {
                $discountSeen        = true;
                $totalCacheDiscount += $r->cacheDiscount;
            }
            if (str_starts_with($row['step'], 'L1.')) {
                $roleCount++;
            }
        }
        $roleCount = max($roleCount, 1);
        $avg       = $totalTokens / $roleCount;

        $mostEfficient = '';
        $bestRatio     = PHP_FLOAT_MAX;
        foreach ($perModel as $key => $u) {
            $ratio = $u->totalTokens / max(1, $u->calls);
            if ($ratio < $bestRatio) {
                $bestRatio     = $ratio;
                $mostEfficient = $key;
            }
        }

        return new SessionStats(
            totalTokens:        $totalTokens,
            totalLatencyMs:     $totalLatencyMs,
            perModel:           $perModel,
            perLayer:           $perLayer,
            avgTokensPerRole:   $avg,
            mostEfficientModel: $mostEfficient,
            roleCount:          $roleCount,
            totalCost:          $costSeen ? $totalCost : null,
            costAvailable:      $costSeen,
            totalCacheReadTokens:  $cacheReadTokens,
            totalCacheWriteTokens: $cacheWriteTokens,
            cacheCalls:            $cacheCalls,
            cacheReportedCalls:    $cacheReported,
            totalCacheDiscount:    $discountSeen ? $totalCacheDiscount : null,
            cacheDiscountAvailable: $discountSeen,
            totalCalls:            $totalCalls,
        );
    }
}
