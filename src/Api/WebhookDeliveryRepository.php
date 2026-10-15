<?php
/**
 * WebhookDeliveryRepository: PDO-backed storage for `webhook_deliveries`
 * plus an in-memory fallback when the database is unavailable.
 *
 * The unique key (webhook_id, event_id) makes retries idempotent: at
 * most ONE row exists per subscription/event pair. While that row is
 * `pending` the dispatcher bumps `attempt` and `next_retry_at`; once it
 * reaches `success` or `giving_up` the event is never re-sent.
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

use PDO;
use PDOException;

final class WebhookDeliveryRepository
{
    public const TABLE = 'webhook_deliveries';

    /** @var array<int,WebhookDelivery> in-memory mirror keyed by id */
    private array $memory = [];
    private int $memoryAutoId = 0;
    /** Set after a PDO failure: every subsequent call degrades to memory. */
    private bool $forceMemory = false;

    public function __construct(private readonly ?PDO $pdo = null) {}

    public function tableAvailable(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $r = $this->pdo->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='" . self::TABLE . "'"
                );
                $name = $r ? $r->fetchColumn() : false;
                return is_string($name) && $name !== '';
            }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute([self::TABLE]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }

    private function useDatabase(): bool
    {
        return !$this->forceMemory && $this->pdo !== null && $this->tableAvailable();
    }

    /**
     * Find the single delivery row for a subscription/event pair.
     */
    public function findByEvent(int $webhookId, string $eventId): ?WebhookDelivery
    {
        if (!$this->useDatabase()) {
            foreach ($this->memory as $row) {
                if ($row->webhookId === $webhookId && hash_equals($row->eventId, $eventId)) {
                    return $row;
                }
            }
            return null;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ' . self::TABLE . ' WHERE webhook_id = :wh AND event_id = :ev LIMIT 1'
            );
            $stmt->execute(['wh' => $webhookId, 'ev' => $eventId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? WebhookDelivery::fromRow($row) : null;
        } catch (PDOException) {
            $this->forceMemory = true;
            foreach ($this->memory as $r) {
                if ($r->webhookId === $webhookId && hash_equals($r->eventId, $eventId)) {
                    return $r;
                }
            }
            return null;
        }
    }

    /**
     * Create the idempotent pending row for the FIRST attempt. When a
     * row already exists (race or earlier partial run) it is returned
     * unchanged instead of raising a duplicate-key error.
     */
    public function createPending(
        int     $webhookId,
        string  $eventId,
        string  $eventType,
        string  $payload,
        ?string $nextRetryAt,
    ): WebhookDelivery {
        $existing = $this->findByEvent($webhookId, $eventId);
        if ($existing !== null) {
            return $existing;
        }
        if (!$this->useDatabase()) {
            $id = ++$this->memoryAutoId;
            $row = new WebhookDelivery(
                id:          $id,
                webhookId:   $webhookId,
                eventId:     $eventId,
                eventType:   $eventType,
                attempt:     1,
                status:      WebhookDelivery::STATUS_PENDING,
                payload:     $payload,
                nextRetryAt: $nextRetryAt,
                createdAt:   gmdate('Y-m-d H:i:s'),
                updatedAt:   gmdate('Y-m-d H:i:s'),
            );
            $this->memory[$id] = $row;
            return $row;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . '
                 (webhook_id, event_id, event_type, attempt, status, payload, next_retry_at)
                 VALUES (:wh, :ev, :etype, 1, :status, :payload, :retry)'
            );
            $stmt->execute([
                'wh'      => $webhookId,
                'ev'      => $eventId,
                'etype'   => $eventType,
                'status'  => WebhookDelivery::STATUS_PENDING,
                'payload' => $payload,
                'retry'   => $nextRetryAt,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            return $this->findById($id)
                ?? new WebhookDelivery($id, $webhookId, $eventId, $eventType, 1, WebhookDelivery::STATUS_PENDING, $payload, null, null, $nextRetryAt);
        } catch (PDOException $e) {
            $code = (string)$e->getCode();
            // Duplicate key: another process created the row first. MySQL
            // reports 23000, SQLite too. Re-read and return that row.
            if ($code === '23000' || $code === '23505') {
                $again = $this->findByEvent($webhookId, $eventId);
                if ($again !== null) {
                    return $again;
                }
            }
            $this->forceMemory = true;
            return $this->createPending($webhookId, $eventId, $eventType, $payload, $nextRetryAt);
        }
    }

    public function findById(int $id): ?WebhookDelivery
    {
        if (!$this->useDatabase()) {
            return $this->memory[$id] ?? null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? WebhookDelivery::fromRow($row) : null;
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->memory[$id] ?? null;
        }
    }

    /**
     * Update attempt bookkeeping after a send outcome.
     */
    public function updateOutcome(
        int     $id,
        int     $attempt,
        string  $status,
        ?int    $responseCode,
        ?string $responseBody,
        ?string $nextRetryAt,
    ): ?WebhookDelivery {
        if (!$this->useDatabase()) {
            $row = $this->memory[$id] ?? null;
            if ($row === null) {
                return null;
            }
            $updated = new WebhookDelivery(
                id:           $row->id,
                webhookId:    $row->webhookId,
                eventId:      $row->eventId,
                eventType:    $row->eventType,
                attempt:      $attempt,
                status:       $status,
                payload:      $row->payload,
                responseCode: $responseCode,
                responseBody: $responseBody,
                nextRetryAt:  $nextRetryAt,
                createdAt:    $row->createdAt,
                updatedAt:    gmdate('Y-m-d H:i:s'),
            );
            $this->memory[$id] = $updated;
            return $updated;
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::TABLE . '
                 SET attempt = :attempt, status = :status, response_code = :code,
                     response_body = :body, next_retry_at = :retry
                 WHERE id = :id'
            );
            $stmt->execute([
                'id'      => $id,
                'attempt' => $attempt,
                'status'  => $status,
                'code'    => $responseCode,
                'body'    => $responseBody,
                'retry'   => $nextRetryAt,
            ]);
            return $this->findById($id);
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->updateOutcome($id, $attempt, $status, $responseCode, $responseBody, $nextRetryAt);
        }
    }

    /**
     * Pending deliveries whose next retry time has arrived (for the
     * dispatcher's retry pump).
     *
     * @return list<WebhookDelivery>
     */
    public function due(string $nowSql): array
    {
        if (!$this->useDatabase()) {
            $out = [];
            foreach ($this->memory as $row) {
                if ($row->status !== WebhookDelivery::STATUS_PENDING) { continue; }
                if ($row->nextRetryAt === null || $row->nextRetryAt <= $nowSql) {
                    $out[] = $row;
                }
            }
            usort($out, static fn(WebhookDelivery $a, WebhookDelivery $b): int => ($a->id <=> $b->id));
            return $out;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ' . self::TABLE . '
                 WHERE status = :status
                   AND (next_retry_at IS NULL OR next_retry_at <= :now)
                 ORDER BY id ASC'
            );
            $stmt->execute([
                'status' => WebhookDelivery::STATUS_PENDING,
                'now'    => $nowSql,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(
                static fn(array $r): WebhookDelivery => WebhookDelivery::fromRow($r),
                is_array($rows) ? $rows : [],
            );
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->due($nowSql);
        }
    }

    /** @return list<WebhookDelivery> Newest first, optional webhook filter. */
    public function recent(?int $webhookId = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        if (!$this->useDatabase()) {
            $rows = array_values($this->memory);
            if ($webhookId !== null) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn(WebhookDelivery $d): bool => $d->webhookId === $webhookId,
                ));
            }
            usort($rows, static fn(WebhookDelivery $a, WebhookDelivery $b): int => ($b->id <=> $a->id));
            return array_slice($rows, 0, $limit);
        }
        try {
            $sql  = 'SELECT * FROM ' . self::TABLE;
            $args = [];
            if ($webhookId !== null) {
                $sql   .= ' WHERE webhook_id = :wh';
                $args[] = ['wh' => $webhookId];
            }
            $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($args === [] ? [] : $args[0]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(
                static fn(array $r): WebhookDelivery => WebhookDelivery::fromRow($r),
                is_array($rows) ? $rows : [],
            );
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->recent($webhookId, $limit);
        }
    }
}
