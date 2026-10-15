<?php
/**
 * Policy: the operator's prompt-caching settings, validated and resolved.
 *
 * The Settings card writes four knobs (on/off, TTL, breakpoint count,
 * minimum prompt size); everything else is fixed by the Bothub
 * documentation and lives here as a constant rather than as a setting, so
 * an operator cannot configure the request into a shape the provider
 * rejects:
 *
 *   - TTL is only ever `5m` (default) or `1h` — those are the two values
 *     the documentation defines, and anything else would be silently
 *     dropped by the provider;
 *   - the breakpoint count is clamped to 1..4, because the documentation
 *     states a hard ceiling of four breakpoints (Anthropic);
 *   - the minimum prompt size has a floor of 0 but is *raised* per family
 *     by the family's documented minimum before it is used
 *     ({@see Planner}).
 *
 * Every clamp is applied on read, not on write, so a hand-edited
 * `var/config/runtime.json` cannot produce an invalid request either.
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

use App\Core\Config;

final readonly class Policy
{
    /** Bothub `cache_control` TTL value for the documented 5-minute default. */
    public const TTL_FIVE_MINUTES = '5m';

    /** Bothub `cache_control` TTL value for the documented one-hour session. */
    public const TTL_ONE_HOUR = '1h';

    /** Hard ceiling stated by the documentation (Anthropic). */
    public const MAX_BREAKPOINTS = 4;

    /**
     * Default minimum prompt size when the operator has not chosen one.
     * 1024 tokens is the smallest documented family minimum the feature
     * knows about (OpenAI), so it is the least surprising starting point.
     */
    public const DEFAULT_MIN_PROMPT_TOKENS = 1024;

    public function __construct(
        public bool   $enabled = true,
        public string $ttl = self::TTL_FIVE_MINUTES,
        public int    $maxBreakpoints = 1,
        public int    $minPromptTokens = self::DEFAULT_MIN_PROMPT_TOKENS,
        public bool   $includeUsage = true,
        public string $provider = 'bothub',
    ) {}

    /**
     * The policy as configured for the running installation.
     *
     * Read through `Config::get()` so `var/config/runtime.json` overrides
     * win over the shipped `config.php` defaults, exactly like every other
     * runtime knob.
     */
    public static function fromConfig(): self
    {
        return new self(
            enabled:         (bool)Config::get('prompt_cache.enabled', true),
            ttl:             (string)Config::get('prompt_cache.ttl', self::TTL_FIVE_MINUTES),
            maxBreakpoints:  (int)Config::get('prompt_cache.max_breakpoints', 1),
            minPromptTokens: (int)Config::get('prompt_cache.min_prompt_tokens', self::DEFAULT_MIN_PROMPT_TOKENS),
            includeUsage:    (bool)Config::get('prompt_cache.include_usage', true),
            provider:        (string)Config::get('prompt_cache.provider', 'bothub'),
        );
    }

    /** Normalized TTL: anything that is not the documented `1h` means 5 minutes. */
    public function normalizedTtl(): string
    {
        return strtolower(trim($this->ttl)) === self::TTL_ONE_HOUR
            ? self::TTL_ONE_HOUR
            : self::TTL_FIVE_MINUTES;
    }

    /** True when the operator asked for the one-hour TTL. */
    public function usesLongTtl(): bool
    {
        return $this->normalizedTtl() === self::TTL_ONE_HOUR;
    }

    /** Breakpoint count clamped to 1..4. */
    public function effectiveMaxBreakpoints(): int
    {
        return max(1, min(self::MAX_BREAKPOINTS, $this->maxBreakpoints));
    }

    /** Configured threshold clamped to >= 0 (0 = "no size guard"). */
    public function effectiveMinPromptTokens(): int
    {
        return max(0, $this->minPromptTokens);
    }

    /**
     * The `cache_control` object to attach to a breakpoint.
     *
     * Exactly the two shapes the documentation defines:
     *   `{"type":"ephemeral"}`                        — 5-minute default
     *   `{"type":"ephemeral","ttl":"1h"}`             — one-hour session
     *
     * @return array<string,string>
     */
    public function cacheControl(): array
    {
        $control = ['type' => 'ephemeral'];
        if ($this->usesLongTtl()) {
            $control['ttl'] = self::TTL_ONE_HOUR;
        }
        return $control;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'enabled'                 => $this->enabled,
            'ttl'                     => $this->normalizedTtl(),
            'max_breakpoints'         => $this->effectiveMaxBreakpoints(),
            'min_prompt_tokens'       => $this->effectiveMinPromptTokens(),
            'include_usage'           => $this->includeUsage,
            'provider'                => $this->provider,
            'ttl_values'              => [self::TTL_FIVE_MINUTES, self::TTL_ONE_HOUR],
            'max_breakpoints_ceiling' => self::MAX_BREAKPOINTS,
        ];
    }
}
