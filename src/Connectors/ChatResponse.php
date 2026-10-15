<?php
/**
 * ChatResponse: Immutable value object describing an LLM chat completion.
 *
 * Carries normalized provider stats so the UI can render a non-empty
 * Statistics block for every successful step. Optional fields capture
 * provider-specific extras (cost, cached / reasoning tokens, generation
 * id) that OpenRouter — and any OpenAI-compatible endpoint that mirrors
 * its `usage.*_details` shape — may include in the response body.
 *
 * Invariants:
 *  - totalTokens == promptTokens + completionTokens (enforced; reconciled
 *    by the connector when providers report a non-matching total — the
 *    raw provider value is preserved verbatim under
 *    rawResponse['usage']['total_tokens']).
 *  - latencyMs is wall-clock and never negative.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Connectors;

final readonly class ChatResponse
{
    /**
     * @param array<string,mixed> $rawResponse
     * @param array<string,mixed> $usageDetails  prompt_tokens_details / completion_tokens_details / cost_details / cache
     */
    public function __construct(
        public string  $content,
        public string  $model,
        public string  $provider,
        public int     $promptTokens,
        public int     $completionTokens,
        public int     $totalTokens,
        public float   $latencyMs,
        public array   $rawResponse = [],
        public bool    $tokensEstimated = false,
        public ?string $actualModel = null,
        public ?float  $cost = null,
        public ?int    $cachedTokens = null,
        public ?int    $reasoningTokens = null,
        public ?string $generationId = null,
        public array   $usageDetails = [],
        // --- prompt caching (Bothub) -------------------------------------
        // `cachedTokens` above is the READ side: it is filled from either
        // dialect — OpenAI-like `usage.prompt_tokens_details.cached_tokens`
        // or Anthropic-like `usage.cache_read_input_tokens` — so the two
        // never double-count in an aggregate.
        //
        // `cacheWriteTokens` is the WRITE side and only the Anthropic-like
        // dialect reports it. `cacheDiscount` is Bothub's own saving figure
        // (`cache_discount` in the response body); it is negative on a
        // cache write for vendors that charge more for writing than for
        // reading the input, so it must never be presented as "≥ 0".
        //
        // All three stay null when the provider reported nothing: null is
        // "no statistics", 0 is "a reported zero". The UI depends on that
        // distinction to avoid reading "the cache did not fire" into a
        // response that simply carried no cache block.
        public ?int    $cacheWriteTokens = null,
        public ?float  $cacheDiscount = null,
    ) {
        if ($this->promptTokens < 0 || $this->completionTokens < 0 || $this->totalTokens < 0) {
            throw new \InvalidArgumentException('Token counts must be non-negative');
        }
        if ($this->totalTokens !== $this->promptTokens + $this->completionTokens) {
            throw new \InvalidArgumentException(
                "totalTokens ({$this->totalTokens}) must equal promptTokens + completionTokens"
            );
        }
        if ($this->latencyMs < 0.0) {
            throw new \InvalidArgumentException('latencyMs must be non-negative');
        }
    }

    /** Provider-reported model id, falling back to the model the caller requested. */
    public function effectiveModel(): string
    {
        return $this->actualModel !== null && $this->actualModel !== ''
            ? $this->actualModel
            : $this->model;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'content'           => $this->content,
            'model'             => $this->model,
            'actual_model'      => $this->actualModel,
            'provider'          => $this->provider,
            'prompt_tokens'     => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens'      => $this->totalTokens,
            'latency_ms'        => $this->latencyMs,
            'tokens_estimated'  => $this->tokensEstimated,
            'cost'              => $this->cost,
            'cached_tokens'     => $this->cachedTokens,
            'reasoning_tokens'  => $this->reasoningTokens,
            'generation_id'     => $this->generationId,
            'usage_details'     => $this->usageDetails,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'cache_discount'    => $this->cacheDiscount,
        ];
    }

    /**
     * Whether the provider told us anything about its prompt cache.
     *
     * The UI must not turn silence into a failure: a response with no cache
     * block at all is "the provider returned no cache statistics", which is
     * a different sentence from "the cache was not used" — and only this
     * method can tell them apart.
     */
    public function hasCacheStats(): bool
    {
        return $this->cachedTokens !== null
            || $this->cacheWriteTokens !== null
            || $this->cacheDiscount !== null;
    }

    /** True when at least one prompt token was served from the cache. */
    public function cacheWasRead(): bool
    {
        return ($this->cachedTokens ?? 0) > 0;
    }

    /**
     * Compact stats payload for UI / event rendering. Drops null/empty
     * extras so the JSON envelope stays small.
     *
     * @return array<string,mixed>
     */
    public function statsPayload(): array
    {
        $out = [
            'model'             => $this->effectiveModel(),
            'requested_model'   => $this->model,
            'provider'          => $this->provider,
            'prompt_tokens'     => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens'      => $this->totalTokens,
            'latency_ms'        => (int)round($this->latencyMs),
            'tokens_estimated'  => $this->tokensEstimated,
        ];
        if ($this->cost !== null)            { $out['cost']             = $this->cost; }
        if ($this->cachedTokens !== null)    { $out['cached_tokens']    = $this->cachedTokens; }
        if ($this->reasoningTokens !== null) { $out['reasoning_tokens'] = $this->reasoningTokens; }
        if ($this->generationId !== null)    { $out['generation_id']    = $this->generationId; }
        // Prompt caching. `cached_tokens` above is the read side and keeps
        // its historical name; the two keys below are new and only appear
        // when the provider actually reported them, so an old client sees
        // the payload it always saw.
        if ($this->cacheWriteTokens !== null) { $out['cache_write_tokens'] = $this->cacheWriteTokens; }
        if ($this->cacheDiscount !== null)    { $out['cache_discount']     = $this->cacheDiscount; }
        if ($this->hasCacheStats())           { $out['cache_stats']        = true; }
        return $out;
    }
}
