<?php
/**
 * IpMatcher: validate and match IPv4/IPv6 addresses and CIDR ranges.
 *
 * Used by the Security tab + the enforcement filter to decide whether a
 * client IP belongs to the configured whitelist or blacklist. Loopback
 * addresses (127.0.0.0/8 and ::1) are special-cased throughout the
 * application — they MUST NOT be denied by either list, so an operator
 * cannot lock themselves out from the loopback interface even if they
 * paste a wildcard rule by accident.
 *
 * Match semantics:
 *   - Exact IPv4   : "203.0.113.42"
 *   - IPv4 CIDR    : "10.0.0.0/8", "192.168.1.0/24"
 *   - Exact IPv6   : "2001:db8::1"
 *   - IPv6 CIDR    : "2001:db8::/32"
 *
 * The matcher is INTENTIONALLY strict: it rejects malformed entries at
 * normalization time so a typo never silently bypasses enforcement.
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

final class IpMatcher
{
    /** Always-allowed loopback rules. Cannot be removed from the whitelist. */
    public const LOOPBACK_RULES = ['127.0.0.0/8', '::1/128'];

    /**
     * @return bool True iff $candidate looks like a syntactically valid
     *              IP address or CIDR range. Does not check reachability.
     */
    public static function isValidEntry(string $candidate): bool
    {
        return self::normalizeEntry($candidate) !== null;
    }

    /**
     * Trim/lowercase an IP or CIDR. Returns null when the entry is not a
     * recognized form so the caller can reject it cleanly. IPv4 octets
     * are kept as-is; IPv6 is canonicalized via inet_pton/inet_ntop.
     */
    public static function normalizeEntry(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }
        if (strpos($candidate, '/') !== false) {
            [$ip, $prefix] = explode('/', $candidate, 2);
            $ip     = trim($ip);
            $prefix = trim($prefix);
            if ($ip === '' || $prefix === '' || !ctype_digit($prefix)) {
                return null;
            }
            $prefixInt = (int)$prefix;
            $packed    = @inet_pton($ip);
            if ($packed === false) {
                return null;
            }
            $maxPrefix = strlen($packed) === 4 ? 32 : 128;
            if ($prefixInt < 0 || $prefixInt > $maxPrefix) {
                return null;
            }
            return self::canonical($ip) . '/' . (string)$prefixInt;
        }
        $packed = @inet_pton($candidate);
        if ($packed === false) {
            return null;
        }
        return self::canonical($candidate);
    }

    /** Canonical string form of an IPv4/IPv6 address. */
    public static function canonical(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        $back = @inet_ntop($packed);
        return is_string($back) ? strtolower($back) : strtolower($ip);
    }

    /** True when $ip is the IPv4 or IPv6 loopback address. */
    public static function isLoopback(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        // IPv4: 127.0.0.0/8
        if (strlen($packed) === 4) {
            return ord($packed[0]) === 127;
        }
        // IPv6: ::1
        return $packed === inet_pton('::1');
    }

    /**
     * Test whether $ip matches any of the supplied $rules (exact or CIDR).
     * Invalid rules are silently skipped; invalid $ip returns false.
     *
     * @param string        $ip
     * @param list<string>  $rules
     */
    public static function matchesAny(string $ip, array $rules): bool
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }
        foreach ($rules as $rule) {
            if (!is_string($rule) || $rule === '') {
                continue;
            }
            if (self::matches($packedIp, trim($rule))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $packedIp Result of inet_pton on the candidate IP.
     */
    private static function matches(string $packedIp, string $rule): bool
    {
        if (strpos($rule, '/') === false) {
            $packedRule = @inet_pton($rule);
            return $packedRule !== false && $packedRule === $packedIp;
        }
        [$ruleIp, $prefix] = explode('/', $rule, 2);
        if (!ctype_digit($prefix)) {
            return false;
        }
        $packedRule = @inet_pton($ruleIp);
        if ($packedRule === false || strlen($packedRule) !== strlen($packedIp)) {
            return false;
        }
        $bits     = (int)$prefix;
        $totalBits = strlen($packedIp) * 8;
        if ($bits < 0 || $bits > $totalBits) {
            return false;
        }
        if ($bits === 0) {
            return true; // /0 covers everything in the same family
        }
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedRule, 0, $fullBytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask    = chr((0xff << (8 - $remainder)) & 0xff);
        $ipByte  = $packedIp[$fullBytes]  ?? "\0";
        $rByte   = $packedRule[$fullBytes] ?? "\0";
        return ($ipByte & $mask) === ($rByte & $mask);
    }
}
