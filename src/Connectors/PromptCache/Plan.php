<?php
/**
 * Plan: the outcome of one prompt-caching decision for one request.
 *
 * `reason` is the part that keeps the feature honest. "No breakpoint was
 * added" is not the same statement as "caching did not happen", and the
 * caller (and the operator reading the logs) has to be able to tell the
 * cases apart:
 *
 *   disabled          — the operator switched the feature off
 *   automatic_family  — the provider caches on its own; payload untouched
 *                       by design, so the request format cannot break
 *   unknown_family    — nothing documented: no breakpoint, no promise
 *   no_stable_prefix  — no leading `system` message to anchor a breakpoint
 *   below_threshold   — the stable prefix is smaller than the documented
 *                       minimum for this family, so caching would not fire
 *   applied           — breakpoints were placed
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Connectors\PromptCache;

final readonly class Plan
{
    public const REASON_DISABLED         = 'disabled';
    public const REASON_AUTOMATIC_FAMILY = 'automatic_family';
    public const REASON_UNKNOWN_FAMILY   = 'unknown_family';
    public const REASON_NO_STABLE_PREFIX = 'no_stable_prefix';
    public const REASON_BELOW_THRESHOLD  = 'below_threshold';
    public const REASON_APPLIED          = 'applied';

    /**
     * @param array<int,array<string,mixed>> $messages     Messages to send (unchanged when `applied` is false).
     * @param array<string,string>|null      $cacheControl The object attached to the breakpoint(s), or null.
     */
    public function __construct(
        public bool    $applied,
        public string  $reason,
        public array   $messages,
        public int     $breakpoints,
        public int     $prefixTokens,
        public int     $threshold,
        public ?array  $cacheControl,
        public bool    $includeUsage,
        public string  $family,
        public string  $mode,
    ) {}

    /** True when the request asks the provider to return cache tokens. */
    public function requestsUsageDetails(): bool
    {
        return $this->includeUsage;
    }

    /** Compact description for the pipeline log (never contains prompt text). */
    public function logContext(): array
    {
        return [
            'family'        => $this->family,
            'mode'          => $this->mode,
            'reason'        => $this->reason,
            'breakpoints'   => $this->breakpoints,
            'prefix_tokens' => $this->prefixTokens,
            'threshold'     => $this->threshold,
            'include_usage' => $this->includeUsage,
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->logContext() + [
            'applied'       => $this->applied,
            'cache_control' => $this->cacheControl,
        ];
    }
}
