<?php
/**
 * AvailabilityStatus: the cross-run health verdict for one model.
 *
 * `App\Connectors\ModelBreaker` answers a narrower question — "is this model
 * still worth calling inside *this* run?" — and is deliberately in-memory and
 * per-run. That is correct for a breaker: state that outlives an incident is
 * stale state. But it leaves a real gap the operator feels:
 *
 *   a provider that has been down for twenty minutes is rediscovered from
 *   scratch on every single run, by paying the timeout again, and the
 *   consensus pool the operator configured silently runs short every time.
 *
 * This enum is the verdict of the *persistent* monitor that fills that gap.
 * It is computed from a rolling window of recent calls plus scheduled probes,
 * stored in `revoap_model_availability`, and consulted at pool-assembly time
 * so a known-dead model is replaced before the first token is spent rather
 * than after the timeout.
 *
 * The five states are ordered from best to worst; `worseThan()` exists so a
 * caller can ask "should this replace that?" without re-deriving the order.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

enum AvailabilityStatus: string
{
    /** Answered recently, no failures in the window. Safe to use. */
    case Healthy = 'healthy';

    /** Never probed and never called. Treated as usable — an unverified model
     *  is not a broken model, and refusing it would empty a fresh install's
     *  pool. It does, however, sort below Healthy when both are candidates. */
    case Unknown = 'unknown';

    /** Answering, but slowly or with intermittent failures. Prefer a Healthy
     *  alternative; still usable when there is none. */
    case Degraded = 'degraded';

    /** Failing consistently. Excluded from substitution candidates and
     *  replaced in the pool unless the policy says otherwise. */
    case Unavailable = 'unavailable';

    /** An administrator switched it off, or the provider no longer serves it.
     *  Never auto-selected, and surfaced in the catalogue as removed. */
    case Retired = 'retired';

    /** Ordering weight, higher is better. */
    public function rank(): int
    {
        return match ($this) {
            self::Healthy    => 4,
            self::Unknown    => 3,
            self::Degraded   => 2,
            self::Unavailable => 1,
            self::Retired    => 0,
        };
    }

    public function isUsable(): bool
    {
        return $this->rank() >= self::Unknown->rank();
    }

    /** May this status be offered as a substitute for another model? */
    public function isSubstitutable(): bool
    {
        return $this === self::Healthy || $this === self::Unknown;
    }

    public function worseThan(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    /**
     * Parse a stored/transported value, never throwing.
     *
     * A NULL, an empty string, or a value written by an older schema all map
     * to Unknown rather than to an error: the monitor's job is to improve the
     * verdict over time, and a row it has not seen yet is exactly Unknown.
     */
    /**
     * Tolerant parse. Named `parse`, not `from`: a backed enum's own `from()`
     * is final and THROWS on an unknown value, which would turn a row written by
     * an older schema into a fatal error on read.
     *
     * NULL, an empty string and anything unrecognised all map to Unknown rather
     * than to an error: the monitor's job is to improve the verdict over time,
     * and a row it has not seen yet is exactly Unknown.
     */
    public static function parse(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return self::Unknown;
        }
        return self::tryFrom(strtolower(trim($value))) ?? self::Unknown;
    }

    /** Catalogue-facing label key, resolved by App\I18n\Translator. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Healthy     => 'models.breaker_closed',
            self::Unknown     => 'models.unknown_status',
            self::Degraded    => 'models.degraded',
            self::Unavailable => 'models.unavailable',
            self::Retired     => 'models.inactive',
        };
    }

    /** Semantic colour for the badge in the catalogue table. */
    public function tone(): string
    {
        return match ($this) {
            self::Healthy     => 'success',
            self::Unknown     => 'muted',
            self::Degraded    => 'warning',
            self::Unavailable => 'destructive',
            self::Retired     => 'secondary',
        };
    }
}
