<?php
/**
 * SecurityStore: persistent state for the Security tab.
 *
 * Stored under the `security.*` subtree of {root}/var/config/runtime.json
 * (the same store SettingsController uses for runtime overrides). All
 * mutations go through update() which:
 *   - normalizes every IP/CIDR entry through IpMatcher,
 *   - drops malformed entries,
 *   - guarantees the loopback rules (127.0.0.0/8, ::1/128) are always
 *     present in the whitelist so the operator cannot lock themselves
 *     out from localhost,
 *   - de-duplicates rules.
 *
 * Defaults are SAFE: enforcement is OFF until the operator explicitly
 * flips a toggle in the Security tab. Reading a fresh install always
 * returns a working configuration.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Core\RuntimeConfig;

final class SecurityStore
{
    public const KEY = 'security';

    /** Default admin session TTL when nothing is configured. */
    public const DEFAULT_TTL_MINUTES = 120;

    /**
     * @return array{
     *     ip_whitelist_enabled: bool,
     *     ip_whitelist: list<string>,
     *     ip_blacklist_enabled: bool,
     *     ip_blacklist: list<string>,
     *     trusted_proxies: list<string>,
     *     trust_proxy_headers: bool,
     *     admin_session_ttl_minutes: int
     * }
     */
    public static function load(): array
    {
        $raw = (array)Config::get(self::KEY, []);
        return self::normalize($raw);
    }

    /**
     * Persist the supplied state on top of the existing security subtree.
     * Always re-normalizes so corrupt entries can never survive a save.
     *
     * @param  array<string,mixed> $patch
     * @return array{
     *     ip_whitelist_enabled: bool, ip_whitelist: list<string>,
     *     ip_blacklist_enabled: bool, ip_blacklist: list<string>,
     *     trusted_proxies: list<string>, trust_proxy_headers: bool,
     *     admin_session_ttl_minutes: int
     * }
     */
    public static function save(array $patch): array
    {
        $merged = array_replace(self::load(), $patch);
        $clean  = self::normalize($merged);
        // v1.8.50 — a firewall rule that never reached the file is worse
        // than one that was never set: the UI would report the new policy
        // while the old one is still enforced. {@see ConfigWriteException}
        // carries the failing stage and the filesystem facts; the
        // controller turns it into a 500 rather than "ok".
        RuntimeConfig::updateOrFail([self::KEY => $clean]);
        // Keep Config in-memory state in sync so subsequent reads in the
        // same request see the fresh value.
        Config::reload(RuntimeConfig::rootDir() . DIRECTORY_SEPARATOR . 'config.php');
        return $clean;
    }

    /**
     * Append the given IP to the whitelist (validated, deduped). Used by
     * the Security tab "auto-add current IP" flow. No-op when the IP is
     * not a syntactically valid address.
     */
    public static function ensureIpInWhitelist(string $ip): array
    {
        $state = self::load();
        $norm  = IpMatcher::normalizeEntry($ip);
        if ($norm === null) {
            return $state;
        }
        if (!in_array($norm, $state['ip_whitelist'], true)
            && !IpMatcher::matchesAny($ip, $state['ip_whitelist'])
        ) {
            $state['ip_whitelist'][] = $norm;
            return self::save($state);
        }
        return $state;
    }

    /**
     * Normalize a raw security subtree:
     *   - cast booleans, ints,
     *   - validate every IP/CIDR via IpMatcher,
     *   - guarantee loopback rules are present in the whitelist,
     *   - dedupe.
     *
     * @param  array<string,mixed> $raw
     * @return array{
     *     ip_whitelist_enabled: bool, ip_whitelist: list<string>,
     *     ip_blacklist_enabled: bool, ip_blacklist: list<string>,
     *     trusted_proxies: list<string>, trust_proxy_headers: bool,
     *     admin_session_ttl_minutes: int
     * }
     */
    public static function normalize(array $raw): array
    {
        $whitelist = self::cleanRules($raw['ip_whitelist'] ?? []);
        foreach (IpMatcher::LOOPBACK_RULES as $loop) {
            if (!in_array($loop, $whitelist, true)) {
                array_unshift($whitelist, $loop);
            }
        }
        $whitelist = array_values(array_unique($whitelist));

        $blacklist = self::cleanRules($raw['ip_blacklist'] ?? []);
        // Loopback rules are silently stripped from the blacklist —
        // operators cannot deny localhost (defense against lockout).
        // Strips both verbatim loopback rules (127.0.0.0/8, ::1/128) AND
        // any individual loopback IP someone might paste accidentally.
        $blacklist = array_values(array_filter(
            $blacklist,
            static function (string $rule): bool {
                if (in_array($rule, IpMatcher::LOOPBACK_RULES, true)) {
                    return false;
                }
                $bare = strpos($rule, '/') === false ? $rule : explode('/', $rule, 2)[0];
                return !IpMatcher::isLoopback($bare);
            }
        ));

        $trustedProxies = self::cleanRules($raw['trusted_proxies'] ?? []);

        $ttl = (int)($raw['admin_session_ttl_minutes'] ?? self::DEFAULT_TTL_MINUTES);
        if ($ttl < 5)        { $ttl = 5; }
        if ($ttl > 60 * 24 * 30) { $ttl = 60 * 24 * 30; } // cap at 30 days

        return [
            'ip_whitelist_enabled'     => (bool)($raw['ip_whitelist_enabled'] ?? false),
            'ip_whitelist'             => $whitelist,
            'ip_blacklist_enabled'     => (bool)($raw['ip_blacklist_enabled'] ?? false),
            'ip_blacklist'             => $blacklist,
            'trusted_proxies'          => $trustedProxies,
            'trust_proxy_headers'      => (bool)($raw['trust_proxy_headers'] ?? false),
            'admin_session_ttl_minutes'=> $ttl,
        ];
    }

    /**
     * @param  mixed $rules
     * @return list<string>
     */
    private static function cleanRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            return [];
        }
        $out = [];
        foreach ($rules as $r) {
            if (!is_string($r)) { continue; }
            $norm = IpMatcher::normalizeEntry($r);
            if ($norm !== null && !in_array($norm, $out, true)) {
                $out[] = $norm;
            }
        }
        return $out;
    }
}
