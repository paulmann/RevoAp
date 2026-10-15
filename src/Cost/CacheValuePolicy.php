<?php
declare(strict_types=1);

namespace App\Cost;

/**
 * R10 - is prompt caching still worth paying for on this model?
 *
 * The point of the rule: a cache that is never read still charges for the
 * write. At Anthropic's multiplier that is 1.25x on every cached token, so a
 * model whose breakpoints never hit is more expensive with caching on than
 * with it off. The advice below is the one thing the application can say about
 * it without pretending to know the provider's price list: `reads == 0` after
 * enough calls means the operator is paying a premium for nothing.
 *
 * All inputs are measured facts from the response side (`cache_read_tokens`,
 * `cache_write_tokens`, `cache_discount`); nothing is inferred from the model
 * name. Where the capability table says the family is undocumented, there is
 * no advice at all - neither to keep nor to drop.
 */
final class CacheValuePolicy
{
    public const KEEP    = 'keep';
    public const DROP    = 'drop';
    public const UNKNOWN = 'unknown';

    /**
     * @param array{calls:int,reads:int,writes:int,hits?:int,discount?:float|null} $usage
     *        Measured totals for one model over the recent window.
     * @param array{family?:string,explicit_breakpoints?:bool|null}|null $capability
     *        The documented capability of that family, or null when unknown.
     * @return array{advice:string,reason:string,hit_rate:float}
     */
    public static function advise(array $usage, ?array $capability): array
    {
        $calls  = max(0, (int)($usage['calls'] ?? 0));
        $reads  = max(0, (int)($usage['reads'] ?? 0));
        $writes = max(0, (int)($usage['writes'] ?? 0));
        $hitRate = $calls > 0 ? round($reads / $calls, 4) : 0.0;

        if ($capability === null || ($capability['explicit_breakpoints'] ?? null) === null) {
            return self::verdict(self::UNKNOWN, $hitRate,
                'Caching for this model is not documented: nothing is changed and nothing is promised.');
        }

        if (($capability['explicit_breakpoints'] ?? false) !== true) {
            return self::verdict(self::KEEP, $hitRate,
                'The provider caches this model automatically, so there is nothing to switch off.');
        }

        $minCalls = EconomyMode::minCacheCalls();
        if ($calls < $minCalls) {
            return self::verdict(self::KEEP, $hitRate, sprintf(
                'Only %d call(s) so far; at least %d are needed before recommending anything about caching.',
                $calls,
                $minCalls,
            ));
        }

        if ($writes > 0 && $reads === 0) {
            return self::verdict(self::DROP, $hitRate, sprintf(
                'Across %d call(s) the cache was written %d time(s) and never read once. This provider charges a premium '
                . 'for the write (Anthropic: 1.25x), so caching is costing more than it saves here - consider turning it '
                . 'off for this model, or lengthening the TTL if the prefix only repeats after a pause.',
                $calls,
                $writes,
            ));
        }

        if ($hitRate < EconomyMode::minCacheHitRate()) {
            return self::verdict(self::KEEP, $hitRate, sprintf(
                'The cache is read on %.1f%% of calls - below the %s%% threshold, but the prefix does repeat: '
                . 'keep caching and watch whether the rate improves with longer TTL.',
                $hitRate * 100,
                rtrim(rtrim(number_format(EconomyMode::minCacheHitRate() * 100, 1), '0'), '.'),
            ));
        }

        return self::verdict(self::KEEP, $hitRate, sprintf(
            'The cache is read on %.1f%% of calls: the repeated prefix is paying for itself.',
            $hitRate * 100,
        ));
    }

    /** @return array{advice:string,reason:string,hit_rate:float} */
    private static function verdict(string $advice, float $hitRate, string $reason): array
    {
        return ['advice' => $advice, 'reason' => $reason, 'hit_rate' => $hitRate];
    }
}
