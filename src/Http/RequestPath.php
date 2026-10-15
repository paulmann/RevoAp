<?php
/**
 * RequestPath: pure URI-to-route-path normalization.
 *
 * Centralizes the logic that turns a raw `REQUEST_URI` value into the
 * canonical path consumed by the router, regardless of whether Apache's
 * mod_rewrite delivered the request transparently or the client hit the
 * front controller (`/index.php`) directly. Kept stateless and free of
 * superglobals so it can be unit-tested without an HTTP environment.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Http;

final class RequestPath
{
    /**
     * Resolve a router-friendly path from `REQUEST_URI` and `SCRIPT_NAME`.
     *
     * Rules:
     *  - Strip the query string and decode percent-encoded octets.
     *  - Remove the script's directory prefix when the app is deployed in a
     *    subdirectory (derived from SCRIPT_NAME).
     *  - Treat `/index.php` and `/index.php/<rest>` as equivalent to `/` and
     *    `/<rest>` respectively, so direct front-controller hits route the
     *    same way as rewritten URLs.
     *  - Collapse duplicate slashes that proxies may introduce.
     *  - Drop the trailing slash from non-root paths so the router does not
     *    need to register both variants.
     *
     * @param string $requestUri Raw value of `$_SERVER['REQUEST_URI']`.
     * @param string $scriptName Raw value of `$_SERVER['SCRIPT_NAME']`.
     * @return string Canonical path beginning with `/`.
     */
    public static function resolve(string $requestUri, string $scriptName): string
    {
        // Strip the query/fragment portion ourselves so we can also handle
        // pathological cases like leading "//" that confuse parse_url's
        // authority detection.
        $path = $requestUri;
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $path = substr($path, 0, $qPos);
        }
        $hPos = strpos($path, '#');
        if ($hPos !== false) {
            $path = substr($path, 0, $hPos);
        }
        if ($path === '') {
            $path = '/';
        }
        $path = rawurldecode($path);

        $scriptDir = '';
        if ($scriptName !== '') {
            $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        }
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir . '/')) {
            $path = substr($path, strlen($scriptDir));
        } elseif ($scriptDir !== '' && $scriptDir !== '/' && $path === $scriptDir) {
            $path = '/';
        }

        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        if ($path === '/index.php') {
            $path = '/';
        } elseif (str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php'));
        }

        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }
}
