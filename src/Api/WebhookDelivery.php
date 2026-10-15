<?php
/**
 * WebhookDelivery: read-only model of one delivery attempt recorded in
 * the `webhook_deliveries` table.
 *
 * One row exists per (webhook_id, event_id) - the unique key makes
 * retries idempotent: while the event is not acknowledged the row is
 * re-used, `attempt` counts up and `next_retry_at` schedules the next
 * send. The stored `payload` is the exact JSON body that was sent, so
 * a retry re-sends the SAME payload (with a fresh signature).
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

final class WebhookDelivery
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_GIVING_UP = 'giving_up';

    public function __construct(
        public readonly int     $id,
        public readonly int     $webhookId,
        public readonly string  $eventId,
        public readonly string  $eventType,
        public readonly int     $attempt,
        public readonly string  $status,
        public readonly ?string $payload = null,
        public readonly ?int    $responseCode = null,
        public readonly ?string $responseBody = null,
        public readonly ?string $nextRetryAt = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:           (int)($row['id'] ?? 0),
            webhookId:    (int)($row['webhook_id'] ?? 0),
            eventId:      (string)($row['event_id'] ?? ''),
            eventType:    (string)($row['event_type'] ?? ''),
            attempt:      (int)($row['attempt'] ?? 0),
            status:       (string)($row['status'] ?? self::STATUS_PENDING),
            payload:      isset($row['payload']) ? (string)$row['payload'] : null,
            responseCode: isset($row['response_code']) ? (int)$row['response_code'] : null,
            responseBody: isset($row['response_body']) ? (string)$row['response_body'] : null,
            nextRetryAt:  isset($row['next_retry_at']) ? (string)$row['next_retry_at'] : null,
            createdAt:    isset($row['created_at']) ? (string)$row['created_at'] : null,
            updatedAt:    isset($row['updated_at']) ? (string)$row['updated_at'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toPublicArray(): array
    {
        return [
            'id'            => $this->id,
            'webhook_id'    => $this->webhookId,
            'event_id'      => $this->eventId,
            'event_type'    => $this->eventType,
            'attempt'       => $this->attempt,
            'status'        => $this->status,
            'response_code' => $this->responseCode,
            'next_retry_at' => $this->nextRetryAt,
            'created_at'    => $this->createdAt,
            'updated_at'    => $this->updatedAt,
        ];
    }
}
