<?php
/**
 * Webhook: read-only model of an outbound webhook subscription stored
 * in the `webhooks` table.
 *
 * A subscription listens to a set of run lifecycle events
 * (run.completed / run.failed / run.cancelled) and receives a signed
 * HTTP POST for each one. The `secret` is the HMAC-SHA256 signing key:
 * it must remain RECOVERABLE (unlike API-key hashes) because the
 * dispatcher recomputes a signature for every delivery.
 *
 * toPublicArray() therefore never exposes the raw secret - it masks it
 * to the last 4 characters (`***abcd`) so UI listings stay safe. Only
 * WebhookDispatcher (which actually signs outgoing requests) reads the
 * full secret through getSecret().
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

final class Webhook
{
    /** Canonical event set a subscription may listen to. */
    public const KNOWN_EVENTS = ['run.completed', 'run.failed', 'run.cancelled'];

    public function __construct(
        public readonly int     $id,
        public readonly string  $label,
        public readonly string  $url,
        public readonly string  $secret,
        /** @var list<string> */
        public readonly array   $events,
        public readonly bool    $active,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $eventsRaw = (string)($row['events'] ?? 'run.completed');
        $events    = array_values(array_filter(
            array_map('trim', explode(',', $eventsRaw)),
            static fn(string $e): bool => $e !== '',
        ));
        return new self(
            id:        (int)($row['id'] ?? 0),
            label:     (string)($row['label'] ?? ''),
            url:       (string)($row['url'] ?? ''),
            secret:    (string)($row['secret'] ?? ''),
            events:    $events === [] ? ['run.completed'] : $events,
            active:    (int)($row['active'] ?? 1) === 1,
            createdAt: isset($row['created_at']) ? (string)$row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string)$row['updated_at'] : null,
        );
    }

    /**
     * Public representation for JSON listings. NEVER includes the raw
     * signing secret.
     *
     * @return array<string,mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id'         => $this->id,
            'label'      => $this->label,
            'url'        => $this->url,
            'events'     => $this->events,
            'active'     => $this->active,
            'secret_tip' => $this->maskSecret(),
            'created_at' => $this->createdAt,
        ];
    }

    /** Raw secret - call ONLY from the delivery/signing layer. */
    public function getSecret(): string
    {
        return $this->secret;
    }

    /** Whether the subscription listens to the given event. */
    public function listensTo(string $event): bool
    {
        return in_array($event, $this->events, true);
    }

    /** Masked secret for UI hints: `***abcd` (or empty when absent). */
    public function maskSecret(): string
    {
        if ($this->secret === '') {
            return '';
        }
        if (strlen($this->secret) <= 4) {
            return '***';
        }
        return '***' . substr($this->secret, -4);
    }
}
