<?php
/**
 * WebhookDispatcher: signed, idempotent delivery of run lifecycle
 * events to subscriber URLs.
 *
 * Event envelope (POST, Content-Type: application/json):
 *   { "event": "run.completed", "event_id": "<uuid>",
 *     "created_at": "<ISO8601 UTC>", "data": { ... } }
 *
 * Signing (header X-RevoAp-HMAC-SHA256):
 *   string-to-sign = "<unix-timestamp>.<raw-body>"
 *   value          = "t=<unix>;v1=<lowercase hex hmac-sha256(secret, string-to-sign)>"
 * Receivers MUST recompute the same string and compare with hash_equals.
 *
 * Delivery model:
 *   - one row per (webhook_id, event_id) → retries are idempotent;
 *   - a retry re-sends the SAME stored payload with a fresh signature;
 *   - 2xx response → success; any other outcome → exponential backoff;
 *   - after max_attempts the row becomes `giving_up`;
 *   - transport/network errors NEVER abort the caller (fail-open).
 *
 * An injectable send seam (setSender) makes the class testable without
 * network access.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Core\Logger;

final class WebhookDispatcher
{
    public const SIGNATURE_HEADER = 'X-RevoAp-HMAC-SHA256';

    /** @var list<int> backoff ramp (seconds) for attempts 2..N */
    private array $backoff;
    private int $maxAttempts;
    private int $timeoutSeconds;

    private ?\Closure $sender = null;
    private ?\Closure $uuid = null;
    private ?\Closure $now = null;

    public function __construct(
        private readonly WebhookRepository $webhooks,
        private readonly WebhookDeliveryRepository $deliveries,
    ) {
        $backoff = Config::get('webhooks.retry_backoff_seconds', [60, 300, 900, 1800]);
        $this->backoff = is_array($backoff)
            ? array_values(array_map(static fn($v): int => max(1, (int)$v), $backoff))
            : [60];
        if ($this->backoff === []) {
            $this->backoff = [60];
        }
        $this->maxAttempts = max(1, (int)Config::get('webhooks.max_attempts', 6));
        $this->timeoutSeconds = max(1, (int)Config::get('webhooks.timeout_seconds', 5));
    }

    /**
     * Test seam: replace the HTTP transport.
     * fn(string $url, array $headers, string $body): array{code:int, body:string}
     */
    public function setSender(\Closure $fn): void { $this->sender = $fn; }

    /** Test seam: deterministic event ids. fn(): string */
    public function setUuid(\Closure $fn): void { $this->uuid = $fn; }

    /** Test seam: deterministic timestamps. fn(): int (unix seconds) */
    public function setNow(\Closure $fn): void { $this->now = $fn; }

    /**
     * Dispatch ONE event to every active subscription that listens to
     * it. Fail-open: delivery problems are recorded on the delivery
     * rows, never thrown.
     *
     * @param  string               $event run.completed|run.failed|run.cancelled
     * @param  array<string,mixed>  $data  event payload
     * @return array{sent:int,queued:int,failed:int}
     */
    public function dispatch(string $event, array $data): array
    {
        $summary = ['sent' => 0, 'queued' => 0, 'failed' => 0];
        foreach ($this->webhooks->all() as $hook) {
            if (!$hook->active || !$hook->listensTo($event)) {
                continue;
            }
            try {
                $eventId = $this->newEventId();
                $payload = json_encode([
                    'event'      => $event,
                    'event_id'   => $eventId,
                    'created_at' => gmdate('Y-m-d\\TH:i:s\\Z', $this->nowUnix()),
                    'data'       => $data,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($payload)) {
                    Logger::warn('webhook.dispatch.encode_failed', ['webhook_id' => $hook->id, 'event' => $event]);
                    $summary['failed']++;
                    continue;
                }
                $delivery = $this->deliveries->createPending(
                    webhookId:   $hook->id,
                    eventId:     $eventId,
                    eventType:   $event,
                    payload:     $payload,
                    nextRetryAt: null,
                );
                $outcome = $this->attempt($hook, $delivery);
                if ($outcome === 'success') { $summary['sent']++; }
                elseif ($outcome === 'retry') { $summary['queued']++; }
                else { $summary['failed']++; }
            } catch (\Throwable $e) {
                Logger::warn('webhook.dispatch.row_failed', [
                    'webhook_id' => $hook->id,
                    'event'      => $event,
                    'class'      => (new \ReflectionClass($e))->getShortName(),
                ]);
                $summary['failed']++;
            }
        }
        return $summary;
    }

    /**
     * Retry pump: re-send every pending delivery whose next_retry_at has
     * arrived. Returns how many deliveries were processed this round.
     */
    public function pumpDue(): int
    {
        $now = gmdate('Y-m-d H:i:s', $this->nowUnix());
        $processed = 0;
        foreach ($this->deliveries->due($now) as $delivery) {
            $hook = $this->webhooks->findById($delivery->webhookId);
            if ($hook === null || !$hook->active) {
                // Subscription vanished or was disabled: stop retrying.
                $this->deliveries->updateOutcome(
                    id: $delivery->id, attempt: $delivery->attempt,
                    status: WebhookDelivery::STATUS_GIVING_UP,
                    responseCode: null, responseBody: 'webhook removed or disabled',
                    nextRetryAt: null,
                );
                $processed++;
                continue;
            }
            try {
                $this->attempt($hook, $delivery);
            } catch (\Throwable $e) {
                Logger::warn('webhook.retry.row_failed', [
                    'delivery_id' => $delivery->id,
                    'class'       => (new \ReflectionClass($e))->getShortName(),
                ]);
            }
            $processed++;
        }
        return $processed;
    }

    /**
     * Verify a received signature (for receivers and integration tests).
     *
     * @param string $secret   shared secret
     * @param string $body     RAW request body (unescaped), byte-exact
     * @param string $header   value of X-RevoAp-HMAC-SHA256 (`t=..;v1=..`)
     * @param int    $tolerance maximum allowed clock skew in seconds
     */
    public static function verifySignature(
        string $secret,
        string $body,
        string $header,
        int    $tolerance = 300,
    ): bool {
        $t = null;
        $v1 = null;
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) { $t = substr($part, 2); }
            if (str_starts_with($part, 'v1=')) { $v1 = substr($part, 3); }
        }
        if ($t === null || $v1 === null || !ctype_digit($t)) {
            return false;
        }
        if (abs((int)$t - time()) > $tolerance) {
            return false;
        }
        $expected = self::computeSignature($secret, (int)$t, $body);
        return hash_equals($expected, strtolower($v1));
    }

    // ----------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------

    /**
     * Send one stored delivery. Returns 'success' | 'retry' | 'dead'.
     */
    private function attempt(Webhook $hook, WebhookDelivery $delivery): string
    {
        $body = (string)($delivery->payload ?? '');
        $stamp = $this->nowUnix();
        $signature = 't=' . $stamp . ';v1=' . self::computeSignature($hook->getSecret(), $stamp, $body);
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: RevoAp-Webhook/1.0',
            self::SIGNATURE_HEADER . ': ' . $signature,
        ];

        $outcome = $this->transmit($hook->url, $headers, $body);
        $code = $outcome['code'];
        $resp = isset($outcome['body']) ? substr((string)$outcome['body'], 0, 500) : '';

        if ($code >= 200 && $code < 300) {
            $this->deliveries->updateOutcome(
                id: $delivery->id, attempt: $delivery->attempt,
                status: WebhookDelivery::STATUS_SUCCESS,
                responseCode: $code, responseBody: $resp, nextRetryAt: null,
            );
            return 'success';
        }

        if ($delivery->attempt >= $this->maxAttempts) {
            $this->deliveries->updateOutcome(
                id: $delivery->id, attempt: $delivery->attempt,
                status: WebhookDelivery::STATUS_GIVING_UP,
                responseCode: $code, responseBody: $resp, nextRetryAt: null,
            );
            Logger::warn('webhook.delivery.giving_up', [
                'delivery_id' => $delivery->id,
                'webhook_id'  => $hook->id,
                'attempt'     => $delivery->attempt,
                'code'        => $code,
            ]);
            return 'dead';
        }

        $nextAttempt = $delivery->attempt + 1;
        $delay = $this->backoff[min($delivery->attempt - 1, count($this->backoff) - 1)] ?? 60;
        $nextRetryAt = gmdate('Y-m-d H:i:s', $this->nowUnix() + $delay);
        $this->deliveries->updateOutcome(
            id: $delivery->id, attempt: $nextAttempt,
            status: WebhookDelivery::STATUS_PENDING,
            responseCode: $code, responseBody: $resp, nextRetryAt: $nextRetryAt,
        );
        return 'retry';
    }

    /**
     * @param  array<int,string> $headers
     * @return array{code:?int, body:?string}
     */
    private function transmit(string $url, array $headers, string $body): array
    {
        if ($this->sender !== null) {
            $result = ($this->sender)($url, $headers, $body);
            if (!is_array($result)) {
                return ['code' => null, 'body' => null];
            }
            return [
                'code' => isset($result['code']) ? (int)$result['code'] : null,
                'body' => isset($result['body']) ? (string)$result['body'] : null,
            ];
        }

        // Prefer cURL; fall back to a stream context when unavailable.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                    CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                return [
                    'code' => is_int($code) || is_float($code) ? (int)$code : null,
                    'body' => is_string($resp) ? $resp : null,
                ];
            }
        }

        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => $this->timeoutSeconds,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $context);
        $code = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\d(\.\d)?\s+([0-9]{3})#', (string)$line, $m) === 1) {
                $code = (int)$m[2];
                break;
            }
        }
        return ['code' => $code, 'body' => is_string($resp) ? $resp : null];
    }

    /** Lowercase hex HMAC-SHA256 of `t.body`. */
    private static function computeSignature(string $secret, int $t, string $body): string
    {
        return strtolower(hash_hmac('sha256', $t . '.' . $body, $secret));
    }

    private function newEventId(): string
    {
        if ($this->uuid !== null) {
            $v = ($this->uuid)();
            $v = is_string($v) ? $v : '';
            if (is_string($v) && strlen($v) >= 8) {
                return $v;
            }
        }
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $hex = bin2hex($b);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function nowUnix(): int
    {
        if ($this->now !== null) {
            return (int)(($this->now)());
        }
        return time();
    }
}
