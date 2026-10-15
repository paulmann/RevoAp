<?php
declare(strict_types=1);

namespace App\Cost;

use App\Core\Config;

/**
 * R10 - the operator's economy setting.
 *
 * Three levels, because "spend less" is not a single decision:
 *
 *   - `off`     nothing is suggested and nothing changes. This is the level
 *               that has to reproduce the previous behaviour exactly, so it is
 *               the one the tests pin (see `tests/EconomyPolicyTest.php`).
 *   - `suggest` recommendations and estimates are produced and shown, and the
 *               run itself is untouched: the operator still chooses.
 *   - `prefer`  the cheap path is switched on where it is safe (the batch
 *               toggle, the cache policy), and the operator keeps the switch.
 *
 * The level never withholds information: `suggest` and `prefer` differ in what
 * they turn on, not in what they tell the operator.
 */
final class EconomyMode
{
    public const OFF     = 'off';
    public const SUGGEST = 'suggest';
    public const PREFER  = 'prefer';

    /** @var array<int,string> */
    private const LEVELS = [self::OFF, self::SUGGEST, self::PREFER];

    private function __construct(private readonly string $level)
    {
    }

    /** The configured level; an unknown or missing value is `off`, never a guess. */
    public static function fromConfig(): self
    {
        $raw = Config::get('economy.mode', self::OFF);
        $value = strtolower(trim(is_scalar($raw) ? (string)$raw : ''));

        return new self(in_array($value, self::LEVELS, true) ? $value : self::OFF);
    }

    public static function named(string $level): self
    {
        $value = strtolower(trim($level));

        return new self(in_array($value, self::LEVELS, true) ? $value : self::OFF);
    }

    public function level(): string
    {
        return $this->level;
    }

    /** May recommendations and estimates be produced at all? */
    public function advises(): bool
    {
        return $this->level !== self::OFF;
    }

    /** May the cheap path be switched on without asking? */
    public function prefers(): bool
    {
        return $this->level === self::PREFER;
    }

    public function label(): string
    {
        return match ($this->level) {
            self::OFF     => 'Economy mode is off: nothing is suggested and no run is changed.',
            self::SUGGEST => 'Economy mode suggests: estimates and recommendations are shown, and every run stays as you configure it.',
            default       => 'Economy mode prefers the cheap path: batch and prompt caching are switched on where they are safe, and you can still turn them off per run.',
        };
    }

    /** Config thresholds, clamped to sane values so a typo cannot disable the advice silently. */
    public static function minCacheCalls(): int
    {
        $raw = Config::get('economy.cache_min_calls', 20);
        $value = is_numeric($raw) ? (int)$raw : 20;

        return max(1, min(1000, $value));
    }

    public static function minCacheHitRate(): float
    {
        $raw = Config::get('economy.cache_min_hit_rate', 0.05);
        $value = is_numeric($raw) ? (float)$raw : 0.05;

        return max(0.0, min(1.0, $value));
    }


    /**
     * The operator's per-run cost ceiling, or null when none is set (R1).
     *
     * The value is in the same unit the providers report cost in - the field
     * comes straight from their responses - so it is deliberately not labelled
     * with a currency here. `0` and negative values mean "no ceiling" rather
     * than "spend nothing": a mistaken zero must not stop every run.
     */
    public static function budgetPerRun(): ?float
    {
        // Read exactly like the other thresholds in this class. alue() lives in
        // CallPolicy, which governs a model call; this class governs the mode.
        $raw = Config::get('economy.budget_per_run', null);
        $value = is_numeric($raw) ? (float)$raw : 0.0;

        return $value > 0 ? $value : null;
    }

    /** A slow run is a candidate for the batch queue; minutes, not seconds. */
    public static function minBatchSeconds(): int
    {
        $raw = Config::get('economy.batch_min_seconds', 120);
        $value = is_numeric($raw) ? (int)$raw : 120;

        return max(5, min(7200, $value));
    }
}
