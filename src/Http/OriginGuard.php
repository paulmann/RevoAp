<?php
/**
 * OriginGuard: refuse a state-changing request that came from another site.
 *
 * RevoAp publishes state-changing endpoints under /api/* - publishing a
 * document, exporting a run, installing an extension, changing settings. None
 * of them looked at where the request came from, so any page on the internet
 * could POST to them through a visitor's browser: the response is unreadable to
 * the attacker (no CORS headers), but the side effect is not - a file written,
 * a run exported, a setting changed. MD.Viewer learned this the hard way and
 * has refused cross-site state changes since v3.11.0; this is the same rule,
 * in the same shape, so the two products behave alike.
 *
 * The rule, deliberately conservative:
 *
 *   - only state-changing methods are checked (GET/HEAD/OPTIONS are left
 *     alone: they must stay usable from a plain link or a bookmark);
 *   - `Origin` decides when it is present. `Referer` is only consulted when
 *     `Origin` is absent, because a proxy may strip one and not the other;
 *   - a request with NEITHER header is allowed. That is the CLI and curl case
 *     (this is how the operator's own scripts and the deploy probes talk to the
 *     host) and refusing it would break them without stopping anything: a
 *     browser always sends `Origin` on a cross-site POST;
 *   - the comparison is by host, ignoring scheme and port, so http behind a
 *     proxy and https in front of it agree.
 *
 * Paths that legitimately receive requests from elsewhere can be listed in
 * ALLOWED_PREFIXES; nothing needs it today (the webhooks in this application
 * are outbound), and the list exists so that the next such endpoint is a
 * one-line change instead of a hole.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-28
 */
declare(strict_types=1);

namespace App\Http;

final class OriginGuard
{
    /** Methods that may change state and therefore need the check. */
    private const GUARDED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Path prefixes that accept cross-site requests on purpose.
     * Empty today: this application's webhooks are outbound.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [];

    /**
     * Why this request must be refused, or null when it may proceed.
     *
     * @param array<string,mixed> $server Typically $_SERVER.
     */
    public static function violation(string $method, string $path, array $server): ?string
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, self::GUARDED_METHODS, true)) {
            return null;
        }
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return null;
            }
        }

        $host = self::hostFromHostHeader((string)($server['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = self::hostFromHostHeader((string)($server['SERVER_NAME'] ?? ''));
        }

        $origin = self::hostFromUrl((string)($server['HTTP_ORIGIN'] ?? ''));
        if ($origin !== null) {
            return self::sameHost($origin, $host)
                ? null
                : 'Origin ' . $origin . ' is not this host';
        }

        $referer = self::hostFromUrl((string)($server['HTTP_REFERER'] ?? ''));
        if ($referer !== null) {
            return self::sameHost($referer, $host)
                ? null
                : 'Referer ' . $referer . ' is not this host';
        }

        // No browser context at all: a CLI client, curl, a deploy probe.
        return null;
    }

    /** Host of an absolute URL, lower-cased and without a port, or null. */
    private static function hostFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '/')) {
            return null; // relative: nothing to compare
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        return self::hostFromHostHeader((string)$parts['host']);
    }

    /** Lower-case the host and drop a port and a trailing dot. */
    private static function hostFromHostHeader(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        $host = rtrim($host, '.');
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return strtolower($host);
    }

    private static function sameHost(?string $a, string $b): bool
    {
        return $a !== null && $b !== '' && $a === $b;
    }
}