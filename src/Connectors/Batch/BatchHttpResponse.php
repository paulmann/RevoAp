<?php
/**
 * BatchHttpResponse: one raw HTTP answer from the Batch API transport.
 *
 * The Batch client cannot reuse {@see \App\Connectors\AbstractConnector::httpJson()}.
 * That helper is shaped for a synchronous chat call: it decodes JSON
 * itself, throws on any 4xx, and can only send an `application/json`
 * body. The Batch surface is different in all three respects — one of
 * its endpoints takes a multipart upload, another returns a JSONL
 * document as raw bytes, and a `failed` batch is a perfectly valid
 * answer that carries its diagnosis in the body at HTTP 200.
 *
 * So the transport hands back the response verbatim: status, raw body,
 * and a decoded view *only when the caller asks for one*. Everything
 * that knows what a particular answer means lives in the client and in
 * the parser, never here.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Connectors\Batch;

use App\Core\Logger;
use App\Helpers\JsonHelper;

final readonly class BatchHttpResponse
{
    /**
     * How much of a failure body is kept in the operator-facing message.
     * Provider errors are useful ("input_file_id is required"), but an
     * unbounded excerpt of a 2 MB HTML error page is not — it would bury
     * the status code the operator actually needs.
     */
    public const MESSAGE_EXCERPT_CHARS = 300;

    public function __construct(
        public int    $statusCode,
        public string $body,
        public string $url = '',
        public string $method = '',
    ) {}

    /** HTTP 2xx — the call reached the endpoint and was accepted. */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * The body decoded as a JSON object, or null when the body is not a
     * JSON object (an HTML error page, a JSONL document, an empty 204).
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        $decoded = JsonHelper::tryDecode($this->body);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A one-line, secret-free description of a non-2xx answer.
     *
     * The provider's own message is preferred over the bare status —
     * "HTTP 400: input_file_id is required" is actionable, "HTTP 400" is
     * not. Every value is passed through {@see Logger::sanitizeScalar()}
     * because a provider that echoes the request can echo a header.
     */
    public function errorMessage(): string
    {
        $detail = $this->json();
        $providerMessage = '';
        if (is_array($detail)) {
            $providerMessage = self::extractProviderMessage($detail);
        }
        if ($providerMessage === '') {
            $providerMessage = trim(self::excerpt($this->body));
        }
        $where = $this->method !== '' && $this->url !== ''
            ? sprintf(' [%s %s]', $this->method, $this->url)
            : '';
        if ($providerMessage === '') {
            return sprintf('HTTP %d%s', $this->statusCode, $where);
        }
        return sprintf('HTTP %d%s: %s', $this->statusCode, $where, $providerMessage);
    }

    /**
     * Pull the human-readable cause out of the three shapes the Batch
     * API uses: `{"error": "…"}`, `{"error": {"message": "…"}}` and
     * `{"message": "…"}`. Anything else yields '' and the caller falls
     * back to a body excerpt.
     *
     * @param array<string,mixed> $body
     */
    public static function extractProviderMessage(array $body): string
    {
        $error = $body['error'] ?? null;
        if (is_string($error) && $error !== '') {
            return Logger::sanitizeScalar($error);
        }
        if (is_array($error)) {
            $message = $error['message'] ?? $error['detail'] ?? $error['type'] ?? null;
            if (is_string($message) && $message !== '') {
                return Logger::sanitizeScalar($message);
            }
        }
        $message = $body['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return Logger::sanitizeScalar($message);
        }
        return '';
    }

    /** Whitespace-collapsed, length-capped, secret-free body excerpt. */
    public static function excerpt(string $body, int $maxChars = self::MESSAGE_EXCERPT_CHARS): string
    {
        $flat = preg_replace('/\s+/', ' ', $body) ?? $body;
        $flat = trim($flat);
        if ($flat === '') {
            return '';
        }
        if (mb_strlen($flat) > $maxChars) {
            $flat = mb_substr($flat, 0, $maxChars) . '…';
        }
        return Logger::sanitizeScalar($flat);
    }
}
