<?php
/**
 * StatisticsPeriod: pure helper that maps a period token + optional
 * anchor into a [from, to] DATETIME pair the aggregator can paste into
 * a WHERE clause.
 *
 * Periods (server-time, inclusive `from`, exclusive `to`):
 *   - all     — no lower bound (returns from = '1970-01-01 00:00:00')
 *   - year    — from start of current year (Jan 1 00:00) to now
 *   - quarter — from start of current calendar quarter (Jan/Apr/Jul/Oct)
 *   - week    — last 7 days from `to` (rolling window, not ISO week)
 *   - day     — last 24 hours from `to` (rolling)
 *   - hour    — last 60 minutes from `to` (rolling)
 *
 * Anchor `to` defaults to the current server time. Tests pass an explicit
 * anchor so period boundaries are deterministic.
 *
 * Why rolling for week/day/hour: operators looking at "last hour" expect
 * a moving window, not "since the top of the hour". Year/quarter use the
 * calendar boundary because that is the natural reporting unit.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\Statistics;

final class StatisticsPeriod
{
    public const ALL     = 'all';
    public const YEAR    = 'year';
    public const QUARTER = 'quarter';
    public const WEEK    = 'week';
    public const DAY     = 'day';
    public const HOUR    = 'hour';

    public const SUPPORTED = [
        self::ALL, self::YEAR, self::QUARTER, self::WEEK, self::DAY, self::HOUR,
    ];

    public const DEFAULT = self::ALL;

    /**
     * Normalize incoming period token to one of SUPPORTED. Falls back
     * to DEFAULT for unknown / empty input so callers do not need to
     * special-case bad parameter values.
     */
    public static function normalize(?string $raw): string
    {
        $p = strtolower(trim((string)$raw));
        return in_array($p, self::SUPPORTED, true) ? $p : self::DEFAULT;
    }

    /**
     * Compute [from, to] DATETIME strings (UTC) for the given period.
     *
     * @param  string                 $period One of SUPPORTED.
     * @param  \DateTimeImmutable|null $anchor When null, `now` is used.
     * @return array{from:string,to:string,period:string,anchor:string}
     */
    public static function range(string $period, ?\DateTimeImmutable $anchor = null): array
    {
        $period = self::normalize($period);
        $tz     = new \DateTimeZone('UTC');
        $to     = $anchor !== null ? $anchor->setTimezone($tz) : new \DateTimeImmutable('now', $tz);

        $from = match ($period) {
            self::ALL     => new \DateTimeImmutable('1970-01-01 00:00:00', $tz),
            self::YEAR    => new \DateTimeImmutable($to->format('Y') . '-01-01 00:00:00', $tz),
            self::QUARTER => self::quarterStart($to, $tz),
            self::WEEK    => $to->modify('-7 days'),
            self::DAY     => $to->modify('-24 hours'),
            self::HOUR    => $to->modify('-60 minutes'),
            default       => new \DateTimeImmutable('1970-01-01 00:00:00', $tz),
        };

        return [
            'period' => $period,
            'from'   => $from->format('Y-m-d H:i:s'),
            'to'     => $to->format('Y-m-d H:i:s'),
            'anchor' => $to->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function quarterStart(\DateTimeImmutable $anchor, \DateTimeZone $tz): \DateTimeImmutable
    {
        $month   = (int)$anchor->format('n');
        $qStart  = (int)(floor(($month - 1) / 3) * 3) + 1;
        $padded  = str_pad((string)$qStart, 2, '0', STR_PAD_LEFT);
        return new \DateTimeImmutable($anchor->format('Y') . '-' . $padded . '-01 00:00:00', $tz);
    }
}
