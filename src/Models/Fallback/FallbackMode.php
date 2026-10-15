<?php
/**
 * FallbackMode: what the pipeline does when a selected model cannot answer.
 *
 * Four values, ordered from least to most interventionist. The default is
 * Auto — it is the only mode that both keeps the council at its configured
 * size (so the consensus score still means what it says) and reports every
 * substitution, so nothing changes behind the operator's back.
 *
 * @project  RevoAp   @version 2.0.0   @since 2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

enum FallbackMode: string
{
    /** Run short and say so. The pre-v2 behaviour. */
    case Never = 'never';

    /** Substitute from the chain, then report every swap with the run. */
    case Auto = 'auto';

    /** Pause and offer the alternatives; nothing is chosen for the operator. */
    case Ask = 'ask';

    /** Abort. A missing member is worse than no answer at all. */
    case Strict = 'strict';

    /**
     * Tolerant parse: never throws, never returns null.
     *
     * Named `parse` rather than `from` because a backed enum already has a
     * FINAL static `from()` that throws on an unknown value — redeclaring it is
     * a fatal error, and calling it with operator input would turn a typo in
     * `runtime.json` into a 500. An unrecognised mode falls back to Auto, the
     * documented default.
     */
    public static function parse(mixed $value): self
    {
        if ($value instanceof self) { return $value; }
        if (!is_string($value) || $value === '') { return self::Auto; }
        return self::tryFrom(strtolower(trim($value))) ?? self::Auto;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Never  => 'fallback.mode_none',
            self::Auto   => 'fallback.mode_auto',
            self::Ask    => 'fallback.mode_ask',
            self::Strict => 'fallback.mode_strict',
        };
    }

    public function helpKey(): string
    {
        return match ($this) {
            self::Never  => 'fallback.mode_none_help',
            self::Auto   => 'fallback.mode_auto_help',
            self::Ask    => 'fallback.mode_ask_help',
            self::Strict => 'fallback.mode_strict_help',
        };
    }

    /** True when the resolver should be asked for candidates at all. */
    public function substitutes(): bool
    {
        return $this === self::Auto || $this === self::Ask;
    }

    /** True when an unfilled slot aborts the run instead of weakening it. */
    public function abortsOnGap(): bool
    {
        return $this === self::Strict;
    }
}
