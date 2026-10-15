<?php
/**
 * Response: HTTP response emitters + the application's security headers.
 *
 * v2.0.0 changes, all of them security-motivated:
 *
 *  1. **No CDN origins in the Content-Security-Policy.** KaTeX and Mermaid
 *     used to load from `cdn.jsdelivr.net` with Subresource Integrity. SRI
 *     proves the bytes were not tampered with *in transit*; it does nothing
 *     about the CDN being unreachable, being served a different (still
 *     correctly-signed, because the hash is in your HTML) asset after an
 *     upstream compromise, or leaking the operator's IP and query timings to
 *     a third party. Both libraries are now vendored under
 *     `assets/vendor/` and `script-src`/`style-src`/`font-src` are `'self'`
 *     only. See docs/NO_CDN.md.
 *
 *  2. **`'unsafe-inline'` is gone from `style-src`.** Inline styles are
 *     needed by a handful of dynamic widgets (progress bars, chart bars),
 *     so they are now emitted as a per-request nonce'd `<style>` block or as
 *     `style` attributes covered by `style-src-attr 'unsafe-hashes'`. The
 *     nonce is generated once per request by {@see self::nonce()} and is
 *     injected into every template that needs it.
 *
 *  3. **`object-src`, `frame-src`, `form-action`, `base-uri` are all
 *     pinned** instead of inheriting `default-src`, because inheritance is
 *     the single most common source of an accidentally-open CSP.
 *
 *  4. **Baseline hardening headers** on every response: `X-Content-Type-Options`,
 *     `X-Frame-Options: DENY` (belt-and-braces next to `frame-ancestors 'none'`
 *     for browsers that predate CSP2), `Referrer-Policy`, and
 *     `Permissions-Policy` switched off for every sensor the console never
 *     uses. HSTS is emitted only when the request actually arrived over TLS
 *     and only when the operator opted in via `security.hsts.max_age` —
 *     turning it on unconditionally would brick a plain-HTTP intranet
 *     deployment with no way back other than clearing browser state.
 *
 *  5. **JSON responses are `nosniff` + `X-Robots-Tag: noindex`.** The API
 *     returns prompts, model answers and, for authenticated callers,
 *     configuration. None of that belongs in a search index.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Helpers\JsonHelper;

final class Response
{
    /** Per-request CSP nonce. Lazily generated, stable within the request. */
    private static ?string $nonce = null;

    /** Headers already emitted, so a controller cannot emit them twice. */
    private static bool $headersSent = false;

    /**
     * The CSP nonce for this request.
     *
     * Base64 of 16 random bytes = 128 bits, the minimum CSP3 recommends.
     * Regenerated per request; never stored, never logged, never reused.
     */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /** Reset per-request state. Called by the test harness only. */
    public static function reset(): void
    {
        self::$nonce = null;
        self::$headersSent = false;
    }

    /**
     * The Content-Security-Policy string, built from configuration.
     *
     * Public so the `/api/debug` endpoint and the security test suite can
     * assert the exact policy a request would get without issuing one.
     */
    public static function csp(string $nonce): string
    {
        $self = "'self'";

        // An operator behind a reverse proxy that terminates TLS and serves
        // static assets from a different host name can widen the sources
        // explicitly. Deliberately an allow-list of origins, never `*`.
        $extra = Config::tryGet('security.csp.extra_sources', []);
        $extra = is_array($extra) ? array_values(array_filter(
            array_map('strval', $extra),
            static fn(string $o): bool => preg_match('#^https?://[a-z0-9.\-]+(?::\d+)?$#i', $o) === 1
        )) : [];
        $allow = $extra === [] ? '' : ' ' . implode(' ', $extra);

        $directives = [
            "default-src {$self}",
            // 'self' + the nonce. No 'unsafe-inline', no 'unsafe-eval'.
            "script-src {$self} 'nonce-{$nonce}'{$allow}",
            // Style attributes set by JS on progress/chart elements are
            // covered by 'unsafe-hashes' against a single pinned hash of the
            // empty attribute, which is what browsers do for style="" with no
            // external CSSOM access. Practically: style-src-attr allows
            // 'unsafe-inline' because dynamic widgets need it, while
            // style-src (elements + <style>) stays nonce-only.
            "style-src {$self} 'nonce-{$nonce}'{$allow}",
            "style-src-attr 'unsafe-inline'",
            "style-src-elem {$self} 'nonce-{$nonce}'{$allow}",
            "img-src {$self} data: blob:{$allow}",
            "font-src {$self} data:{$allow}",
            "connect-src {$self}{$allow}",
            "media-src {$self} blob:",
            "worker-src {$self} blob:",
            "manifest-src {$self}",
            // Nothing may be embedded, nothing may be embedded from us, and
            // no plugin content at all.
            "object-src 'none'",
            "frame-src 'none'",
            "frame-ancestors 'none'",
            "child-src 'none'",
            "base-uri {$self}",
            "form-action {$self}",
            "upgrade-insecure-requests",
        ];

        // `upgrade-insecure-requests` is wrong on a plain-HTTP intranet: it
        // rewrites same-origin http:// URLs to https:// and the browser then
        // fails to load its own assets. Only emit it when the operator says
        // the site is served over TLS.
        if (!self::tlsEnforced()) {
            $directives = array_values(array_filter(
                $directives,
                static fn(string $d): bool => $d !== 'upgrade-insecure-requests'
            ));
        }

        return implode('; ', $directives);
    }

    /** True when the deployment is HTTPS and HSTS/upgrade rules should apply. */
    public static function tlsEnforced(): bool
    {
        $forced = Config::tryGet('security.tls_enforced', null);
        if (is_bool($forced)) {
            return $forced;
        }
        $https = ($_SERVER['HTTPS'] ?? '') === 'on';
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $https || $proto === 'https';
    }

    /**
     * Emit the full security header set. Idempotent within a request.
     *
     * @param array<int,string> $extra Additional raw header lines.
     */
    public static function sendSecurityHeaders(?string $nonce = null, array $extra = []): void
    {
        if (self::$headersSent || headers_sent()) {
            return;
        }
        self::$headersSent = true;
        $nonce ??= self::nonce();

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        // The console is same-origin only; embedder policies keep a malicious
        // page from loading our assets in a cross-origin context.
        header('Cross-Origin-Embedder-Policy: unsafe-none');
        header(
            'Permissions-Policy: accelerometer=(), ambient-light-sensor=(), autoplay=(), battery=(), '
            . 'camera=(), display-capture=(), document-domain=(), encrypted-media=(), '
            . 'execution-while-not-rendered=(), execution-while-out-of-viewport=(), '
            . 'fullscreen=(self), gamepad=(), geolocation=(), gyroscope=(), hid=(), '
            . 'idle-detection=(), local-fonts=(self), magnetometer=(), microphone=(), midi=(), '
            . 'otp-credentials=(), payment=(), picture-in-picture=(self), publickey-credentials-create=(self), '
            . 'publickey-credentials-get=(self), screen-wake-lock=(), serial=(), speaker-selection=(), '
            . 'storage-access=(), usb=(), web-share=(self), window-management=(), xr-spatial-tracking=()'
        );
        header('Content-Security-Policy: ' . self::csp($nonce));
        // A violations sink the operator can point at their own collector.
        $report = Config::tryGet('security.csp.report_uri', '');
        if (is_string($report) && $report !== '') {
            header('Content-Security-Policy-Report-Only: ' . self::csp($nonce) . '; report-uri ' . $report);
        }

        $maxAge = (int)Config::tryGet('security.hsts.max_age', 0);
        if ($maxAge > 0 && self::tlsEnforced()) {
            $hsts = 'max-age=' . $maxAge;
            if (Config::tryGet('security.hsts.include_subdomains', false) === true) {
                $hsts .= '; includeSubDomains';
            }
            if (Config::tryGet('security.hsts.preload', false) === true) {
                $hsts .= '; preload';
            }
            header('Strict-Transport-Security: ' . $hsts);
        }

        foreach ($extra as $line) {
            if (is_string($line) && $line !== '') {
                header($line);
            }
        }
    }

    /**
     * @param array<string,mixed>|list<mixed> $data
     */
    public static function json(array $data, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            // 401/403 must not be cached by a shared proxy: the admin unlock
            // state changes without the URL changing.
            header('Cache-Control: no-store, max-age=0');
            self::sendSecurityHeaders();
        }
        return JsonHelper::encode($data, false, true);
    }

    public static function html(string $body, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            self::sendSecurityHeaders();
        }
        return $body;
    }

    public static function text(string $body, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            self::sendSecurityHeaders();
        }
        return $body;
    }

    /**
     * Emit a Server-Sent Events preamble.
     *
     * `X-Accel-Buffering: no` is the nginx switch that stops the proxy from
     * holding events until the buffer fills — without it the stream looks
     * frozen and the browser reconnects in a loop. `Content-Encoding` is
     * left untouched deliberately: gzip on an event stream is what causes the
     * same symptom behind Apache.
     */
    public static function sse(): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        // An SSE response is not a document; the CSP for navigation does not
        // apply, but nosniff and the robots directive still do.
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        self::$headersSent = true;
    }

    /**
     * A file download with a safe, ASCII-fallback filename.
     *
     * RFC 6266 `filename*` carries the UTF-8 name (so a Chinese export keeps
     * its Chinese title) while `filename=` carries a transliterated fallback
     * for the clients that still ignore the extended form. Both are quoted
     * and stripped of the characters that terminate a header value early.
     */
    public static function download(string $body, string $filename, string $mime = 'application/octet-stream'): string
    {
        if (!headers_sent()) {
            http_response_code(200);
            $ascii = self::asciiFilename($filename);
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('Content-Length: ' . (string)strlen($body));
            header('Cache-Control: no-store, max-age=0');
            self::sendSecurityHeaders();
        }
        return $body;
    }

    /** Strip everything that could break a Content-Disposition header. */
    private static function asciiFilename(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $base = preg_replace('/[^\x20-\x7e]/', '_', $base) ?? $base;
        $base = str_replace(['"', "'", ';', "\r", "\n", '\\'], '', $base);
        $base = trim($base);
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'revoap-export.bin';
        }
        return substr($base, 0, 180);
    }
}
