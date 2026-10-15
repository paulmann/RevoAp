<?php
/**
 * SessionStats: Aggregate statistics for a complete pipeline run.
 *
 * Adds session-wide cost summation and a `cost_available` flag so
 * downstream renderers can render "—" rather than a misleading 0.0
 * when no provider in the run reported a cost.
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

final readonly class SessionStats
{
    /**
     * @param array<string,TokenUsage> $perModel  Keyed by 'model:provider'.
     * @param array<string,TokenUsage> $perLayer  Keyed by 'L1' | 'L2' | ...
     */
    public function __construct(
        public int    $totalTokens,
        public float  $totalLatencyMs,
        public array  $perModel,
        public array  $perLayer,
        public float  $avgTokensPerRole,
        public string $mostEfficientModel,
        public int    $roleCount,
        public ?float $totalCost = null,
        public bool   $costAvailable = false,
        // --- prompt caching (Bothub) -------------------------------------
        // Run-level roll-up, so the Chat summary can answer "how many calls
        // hit the cache, how many tokens were read, how many written, how
        // much was saved" without walking per_model. `cacheReportedCalls`
        // is the denominator that makes the rest readable: with it, "0
        // calls hit the cache" is distinguishable from "no provider in this
        // run returned cache statistics".
        public int    $totalCacheReadTokens = 0,
        public int    $totalCacheWriteTokens = 0,
        public int    $cacheCalls = 0,
        public int    $cacheReportedCalls = 0,
        public ?float $totalCacheDiscount = null,
        public bool   $cacheDiscountAvailable = false,
        public int    $totalCalls = 0,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $perModel = [];
        foreach ($this->perModel as $k => $u) { $perModel[$k] = $u->toArray(); }
        $perLayer = [];
        foreach ($this->perLayer as $k => $u) { $perLayer[$k] = $u->toArray(); }
        return [
            'total_tokens'         => $this->totalTokens,
            'total_latency_ms'     => $this->totalLatencyMs,
            'per_model'            => $perModel,
            'per_layer'            => $perLayer,
            'avg_tokens_per_role'  => $this->avgTokensPerRole,
            'most_efficient_model' => $this->mostEfficientModel,
            'role_count'           => $this->roleCount,
            'total_cost'           => $this->costAvailable ? $this->totalCost : null,
            'cost_available'       => $this->costAvailable,
            'total_calls'             => $this->totalCalls,
            'cache_read_tokens'       => $this->totalCacheReadTokens,
            'cache_write_tokens'      => $this->totalCacheWriteTokens,
            'cache_calls'             => $this->cacheCalls,
            'cache_reported_calls'    => $this->cacheReportedCalls,
            'cache_discount'          => $this->cacheDiscountAvailable ? $this->totalCacheDiscount : null,
            'cache_discount_available' => $this->cacheDiscountAvailable,
        ];
    }
}
