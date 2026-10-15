<?php
/**
 * UnavailableReason: WHY a model could not answer.
 *
 * The distinction is not cosmetic — it drives three separate behaviours:
 *
 *   1. the message shown to the operator ("the provider no longer serves
 *      this model" suggests refreshing the catalogue; "quota exhausted"
 *      suggests topping up, and no amount of refreshing helps);
 *   2. whether the model is offered as a substitute for something else
 *      (a RateLimited model recovers in seconds, a Removed one never does);
 *   3. whether the monitor should keep probing it (probing a Removed model
 *      every five minutes is noise and costs a request each time).
 *
 * Each case therefore carries `transient()`, `probeWorthy()` and
 * `substitutable()`.
 *
 * @project  RevoAp   @version 2.0.0   @since 2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

enum UnavailableReason: string
{
    /** No API key is configured for this provider. */
    case KeyMissing = 'key_missing';
    /** The provider returned 404 / unknown model. */
    case Removed = 'not_found';
    /** Quota or rate limit exhausted (429, insufficient credits). */
    case Quota = 'quota';
    /** The per-run circuit breaker opened. */
    case Breaker = 'breaker';
    /** Repeated timeouts. */
    case Timeout = 'timeout';
    /** An administrator switched it off in the catalogue. */
    case Disabled = 'disabled';
    /** Context window too small for this particular query. */
    case ContextTooSmall = 'context';
    /** Never probed; verdict unknown. */
    case Unverified = 'unverified';
    /** Provider returned 5xx. */
    case ProviderError = 'provider_error';
    /** Content filtered by the provider. */
    case ContentFiltered = 'content_filtered';

    /**
     * Tolerant parse. Named `parse`, not `from`: a backed enum's own `from()` is
     * final and throws. Reason strings arrive from stored JSON and from provider
     * error text, neither of which is under our control.
     */
    public static function parse(mixed $value): self
    {
        if ($value instanceof self) { return $value; }
        if (!is_string($value) || $value === '') { return self::Unverified; }
        return self::tryFrom(strtolower(trim($value))) ?? self::Unverified;
    }

    /** True when the condition clears on its own within minutes. */
    public function transient(): bool
    {
        return match ($this) {
            self::Quota, self::Breaker, self::Timeout, self::ProviderError, self::ContentFiltered => true,
            self::KeyMissing, self::Removed, self::Disabled, self::ContextTooSmall, self::Unverified => false,
        };
    }

    /** True when the availability monitor should keep probing this model. */
    public function probeWorthy(): bool
    {
        return $this !== self::Removed && $this !== self::Disabled;
    }

    /**
     * True when the model can still serve as a replacement for another one.
     *
     * KeyMissing and Disabled are configuration facts, not health facts, and
     * they apply equally to whatever slot the model would fill — so offering
     * them as a substitute would just move the failure.
     */
    public function substitutable(): bool
    {
        return match ($this) {
            self::KeyMissing, self::Removed, self::Disabled, self::ContextTooSmall => false,
            self::Quota, self::Breaker, self::Timeout, self::ProviderError,
            self::ContentFiltered, self::Unverified => true,
        };
    }

    /** The i18n key for the operator-facing explanation. */
    public function labelKey(): string
    {
        return match ($this) {
            self::KeyMissing      => 'fallback.reason_key_missing',
            self::Removed         => 'fallback.reason_not_found',
            self::Quota           => 'fallback.reason_quota',
            self::Breaker         => 'fallback.reason_breaker',
            self::Timeout         => 'fallback.reason_timeout',
            self::Disabled        => 'fallback.reason_disabled',
            self::ContextTooSmall => 'fallback.reason_context',
            self::Unverified      => 'fallback.reason_unverified',
            self::ProviderError   => 'errors.provider_5xx',
            self::ContentFiltered => 'errors.content_filtered',
        };
    }
}
