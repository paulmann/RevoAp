<?php
/**
 * Response: Tiny helpers for emitting JSON and HTML responses.
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

use App\Helpers\JsonHelper;

final class Response
{
    /**
     * @param array<string,mixed>|list<mixed> $data
     */
    public static function json(array $data, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        return JsonHelper::encode($data, false, true);
    }

    public static function html(string $body, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            // v1.8.29: KaTeX is served from cdn.jsdelivr.net — the
            // integrity-pinned <script> / <link> tags in the templates
            // load the KaTeX runtime, stylesheet, and the auto-render
            // contrib helper. We extend script-src, style-src and
            // font-src (KaTeX woff2 glyphs) to the same origin. Hashes
            // are pinned in the HTML via Subresource Integrity so a
            // tampered CDN payload is rejected by the browser before
            // it ever executes. All other sources stay self-only.
            //
            // v1.8.31: Some installs render LLM output that references
            // the Perplexity FKGroteskNeue webfont hosted at
            // frontend-cdn.perplexity.ai. Allow that origin in font-src
            // only — script-src / style-src / connect-src stay locked.
            $cdn   = 'https://cdn.jsdelivr.net';
            $pplx  = 'https://frontend-cdn.perplexity.ai';
            header(
                "Content-Security-Policy: default-src 'self'; "
                . "script-src 'self' {$cdn}; "
                . "style-src 'self' 'unsafe-inline' {$cdn}; "
                . "img-src 'self' data:; "
                . "font-src 'self' data: {$cdn} {$pplx}; "
                . "connect-src 'self'; base-uri 'self'; frame-ancestors 'none'"
            );
        }
        return $body;
    }

    public static function text(string $body, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
        }
        return $body;
    }
}
