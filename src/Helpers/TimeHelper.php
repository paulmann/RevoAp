<?php
/**
 * TimeHelper: Monotonic timing primitives and human-readable duration formatting.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Helpers;

final class TimeHelper
{
    /** @return float Microsecond precision UNIX timestamp. */
    public static function now(): float
    {
        return microtime(true);
    }

    /**
     * Compute milliseconds elapsed since $start.
     *
     * @param  float $start Result of self::now()
     * @return float Milliseconds (>= 0).
     */
    public static function elapsedMs(float $start): float
    {
        $delta = (self::now() - $start) * 1000.0;
        return $delta < 0.0 ? 0.0 : $delta;
    }

    /**
     * Format a millisecond value as a short human string.
     * 850ms, 1.4s, 12.3s, 2m04s.
     */
    public static function format(float $ms): string
    {
        if ($ms < 1000.0) {
            return sprintf('%dms', (int)round($ms));
        }
        $s = $ms / 1000.0;
        if ($s < 60.0) {
            return sprintf('%.1fs', $s);
        }
        $m = (int)floor($s / 60.0);
        $r = (int)round($s - $m * 60);
        return sprintf('%dm%02ds', $m, $r);
    }

    /** ISO-8601 timestamp in UTC with millisecond precision. */
    public static function isoNow(): string
    {
        $t  = microtime(true);
        $ms = (int)floor(($t - floor($t)) * 1000);
        return gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', $ms);
    }
}
