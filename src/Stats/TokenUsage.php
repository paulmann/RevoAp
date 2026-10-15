<?php
/**
 * TokenUsage: Immutable per-call token + latency record.
 *
 * Carries optional cost / cached / reasoning fields propagated from
 * ChatResponse so the UI can render a per-LLM cost-and-token breakdown
 * at the bottom of every session. `costAvailable` is `true` only when at
 * least one merged call carried a provider-reported cost; this lets the
 * UI distinguish "summed cost is 0.0" from "no cost data was returned".
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

final readonly class TokenUsage
{
    public function __construct(
        public string $model,
        public string $provider,
        public int    $promptTokens,
        public int    $completionTokens,
        public int    $totalTokens,
        public float  $latencyMs,
        public int    $calls = 1,
        public ?float $cost = null,
        public bool   $costAvailable = false,
        public int    $cachedTokens = 0,
        public int    $reasoningTokens = 0,
        public bool   $tokensEstimated = false,
        // --- prompt caching (Bothub) -------------------------------------
        // `cachedTokens` above is the READ side — one number whichever
        // dialect reported it. The fields below carry the rest of what a
        // provider can say about the cache:
        //
        //   cacheWriteTokens     tokens written to the cache
        //   cacheCalls           how many calls actually read from the cache
        //   cacheReportedCalls   how many calls carried ANY cache statistic
        //   cacheDiscount        Bothub's saving figure; SIGNED, so a
        //                        write-heavy call can be negative
        //
        // `cacheReportedCalls` is what keeps the aggregate honest: without
        // it, "0 tokens read" cannot be told apart from "the provider said
        // nothing", and the UI would report a cache miss where there is
        // simply no data.
        public int    $cacheWriteTokens = 0,
        public int    $cacheCalls = 0,
        public int    $cacheReportedCalls = 0,
        public ?float $cacheDiscount = null,
        public bool   $cacheDiscountAvailable = false,
    ) {
        if ($this->totalTokens !== $this->promptTokens + $this->completionTokens) {
            throw new \InvalidArgumentException('totalTokens must equal promptTokens + completionTokens');
        }
    }

    public function add(self $other): self
    {
        $bothCost = $this->costAvailable || $other->costAvailable;
        $sumCost = null;
        if ($bothCost) {
            $sumCost = ($this->cost ?? 0.0) + ($other->cost ?? 0.0);
        }
        $bothDiscount = $this->cacheDiscountAvailable || $other->cacheDiscountAvailable;
        $sumDiscount = null;
        if ($bothDiscount) {
            $sumDiscount = ($this->cacheDiscount ?? 0.0) + ($other->cacheDiscount ?? 0.0);
        }
        return new self(
            model:            $this->model,
            provider:         $this->provider,
            promptTokens:     $this->promptTokens     + $other->promptTokens,
            completionTokens: $this->completionTokens + $other->completionTokens,
            totalTokens:      $this->totalTokens      + $other->totalTokens,
            latencyMs:        $this->latencyMs        + $other->latencyMs,
            calls:            $this->calls            + $other->calls,
            cost:             $sumCost,
            costAvailable:    $bothCost,
            cachedTokens:     $this->cachedTokens     + $other->cachedTokens,
            reasoningTokens:  $this->reasoningTokens  + $other->reasoningTokens,
            tokensEstimated:  $this->tokensEstimated || $other->tokensEstimated,
            cacheWriteTokens: $this->cacheWriteTokens + $other->cacheWriteTokens,
            cacheCalls:       $this->cacheCalls       + $other->cacheCalls,
            cacheReportedCalls: $this->cacheReportedCalls + $other->cacheReportedCalls,
            cacheDiscount:    $sumDiscount,
            cacheDiscountAvailable: $bothDiscount,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $avgLatency = $this->calls > 0 ? $this->latencyMs / $this->calls : 0.0;
        return [
            'model'             => $this->model,
            'provider'          => $this->provider,
            'prompt_tokens'     => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens'      => $this->totalTokens,
            'latency_ms'        => $this->latencyMs,
            'avg_latency_ms'    => $avgLatency,
            'calls'             => $this->calls,
            'cost'              => $this->costAvailable ? $this->cost : null,
            'cost_available'    => $this->costAvailable,
            'cached_tokens'     => $this->cachedTokens,
            'reasoning_tokens'  => $this->reasoningTokens,
            'tokens_estimated'  => $this->tokensEstimated,
            // Cache block. Null discount / zero reported calls is how a
            // renderer distinguishes "no data" from "a reported zero".
            'cache_write_tokens'      => $this->cacheWriteTokens,
            'cache_calls'             => $this->cacheCalls,
            'cache_reported_calls'    => $this->cacheReportedCalls,
            'cache_discount'          => $this->cacheDiscountAvailable ? $this->cacheDiscount : null,
            'cache_discount_available' => $this->cacheDiscountAvailable,
        ];
    }
}
