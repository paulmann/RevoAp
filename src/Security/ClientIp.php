<?php
/**
 * ClientIp: resolve the public-facing client IP for the current request.
 *
 * Defaults to $_SERVER['REMOTE_ADDR']. When trust_proxy_headers=true AND
 * REMOTE_ADDR is in the configured trusted_proxies list, the leftmost
 * entry of X-Forwarded-For is honoured instead. This is the only path
 * by which XFF is trusted; otherwise an attacker could spoof their IP
 * and bypass the firewall.
 *
 * The static cache is cleared in tests by passing an explicit override.
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

final class ClientIp
{
    /**
     * @param  array<string,mixed>|null  $server  Override $_SERVER (tests only).
     * @param  array{
     *     trust_proxy_headers: bool,
     *     trusted_proxies: list<string>
     * }|null $security
     */
    public static function resolve(?array $server = null, ?array $security = null): string
    {
        $server   = $server   ?? $_SERVER;
        $security = $security ?? SecurityStore::load();

        $remote = (string)($server['REMOTE_ADDR'] ?? '');
        $remote = trim($remote);
        if ($remote === '') {
            return '';
        }
        if (($security['trust_proxy_headers'] ?? false) !== true) {
            return $remote;
        }
        $trusted = $security['trusted_proxies'] ?? [];
        if ($trusted === [] || !IpMatcher::matchesAny($remote, $trusted)) {
            return $remote;
        }
        // REMOTE_ADDR is trusted — accept the leftmost public IP in XFF.
        $xff = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            foreach (explode(',', $xff) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') { continue; }
                // Strip optional [::]:port wrapping
                if ($candidate[0] === '[') {
                    $end = strpos($candidate, ']');
                    if ($end !== false) {
                        $candidate = substr($candidate, 1, $end - 1);
                    }
                }
                if (@inet_pton($candidate) !== false) {
                    return $candidate;
                }
            }
        }
        $real = (string)($server['HTTP_X_REAL_IP'] ?? '');
        if ($real !== '' && @inet_pton(trim($real)) !== false) {
            return trim($real);
        }
        return $remote;
    }
}
