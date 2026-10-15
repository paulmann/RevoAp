<?php
/**
 * WebhookRepository: PDO-backed storage for `webhooks` plus an
 * in-memory fallback when the database is unavailable (tests, CI,
 * smoke runs).
 *
 * The repository stores the full signing secret because WebhookDispatcher
 * must be able to compute HMAC signatures for OUTBOUND requests (this is
 * different from ApiKeyRepository, which only ever sees digests). Public
 * listings should go through Webhook::toPublicArray() to mask the secret.
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

final class WebhookRepository
{
    public const TABLE = 'webhooks';

    /** @var array<int,Webhook> in-memory mirror keyed by id */
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
     * Insert a new subscription. Events are validated by the caller
     * (WebhookService::create).
     *
     * @param  list<string> $events
     */
    public function create(
        string $label,
        string $url,
        string $secret,
        array  $events,
        bool   $active = true,
    ): Webhook {
        $eventsValue = implode(',', $events);
        if (!$this->useDatabase()) {
            $id = ++$this->memoryAutoId;
            $hook = new Webhook(
                id:        $id,
                label:     $label,
                url:       $url,
                secret:    $secret,
                events:    $events,
                active:    $active,
                createdAt: gmdate('Y-m-d H:i:s'),
                updatedAt: gmdate('Y-m-d H:i:s'),
            );
            $this->memory[$id] = $hook;
            return $hook;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (label, url, secret, events, active)
                 VALUES (:label, :url, :secret, :events, :active)'
            );
            $stmt->execute([
                'label'  => $label,
                'url'    => $url,
                'secret' => $secret,
                'events' => $eventsValue,
                'active' => $active ? 1 : 0,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            return $this->findById($id)
                ?? new Webhook($id, $label, $url, $secret, $events, $active);
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->create($label, $url, $secret, $events, $active);
        }
    }

    public function findById(int $id): ?Webhook
    {
        if (!$this->useDatabase()) {
            return $this->memory[$id] ?? null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? Webhook::fromRow($row) : null;
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->memory[$id] ?? null;
        }
    }

    /** @return list<Webhook> Newest first. */
    public function all(): array
    {
        if (!$this->useDatabase()) {
            $rows = array_values($this->memory);
            usort($rows, static fn(Webhook $a, Webhook $b): int => ($b->id <=> $a->id));
            return $rows;
        }
        try {
            $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id DESC');
            $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            return array_map(
                static fn(array $r): Webhook => Webhook::fromRow($r),
                is_array($rows) ? $rows : [],
            );
        } catch (PDOException) {
            $this->forceMemory = true;
            $rows = array_values($this->memory);
            usort($rows, static fn(Webhook $a, Webhook $b): int => ($b->id <=> $a->id));
            return $rows;
        }
    }

    /**
     * Update label/url/secret/events/active of an existing subscription.
     * Returns the updated row or null when the id is unknown.
     *
     * @param  list<string> $events
     */
    public function update(
        int    $id,
        string $label,
        string $url,
        string $secret,
        array  $events,
        bool   $active,
    ): ?Webhook {
        $eventsValue = implode(',', $events);
        if (!$this->useDatabase()) {
            $hook = $this->memory[$id] ?? null;
            if ($hook === null) {
                return null;
            }
            $updated = new Webhook(
                id:        $hook->id,
                label:     $label,
                url:       $url,
                secret:    $secret,
                events:    $events,
                active:    $active,
                createdAt: $hook->createdAt,
                updatedAt: gmdate('Y-m-d H:i:s'),
            );
            $this->memory[$id] = $updated;
            return $updated;
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::TABLE . '
                 SET label = :label, url = :url, secret = :secret,
                     events = :events, active = :active
                 WHERE id = :id'
            );
            $stmt->execute([
                'id'     => $id,
                'label'  => $label,
                'url'    => $url,
                'secret' => $secret,
                'events' => $eventsValue,
                'active' => $active ? 1 : 0,
            ]);
            return $this->findById($id);
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->update($id, $label, $url, $secret, $events, $active);
        }
    }

    /** Flip `active`. Returns the updated row or null when unknown. */
    public function toggleActive(int $id, bool $active): ?Webhook
    {
        $hook = $this->findById($id);
        if ($hook === null) {
            return null;
        }
        return $this->update(
            id:     $hook->id,
            label:  $hook->label,
            url:    $hook->url,
            secret: $hook->secret,
            events: $hook->events,
            active: $active,
        );
    }

    /** Delete a subscription (cascades to webhook_deliveries via FK). */
    public function delete(int $id): bool
    {
        if (!$this->useDatabase()) {
            if (!isset($this->memory[$id])) {
                return false;
            }
            unset($this->memory[$id]);
            return true;
        }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = :id');
            $stmt->execute(['id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException) {
            $this->forceMemory = true;
            if (!isset($this->memory[$id])) {
                return false;
            }
            unset($this->memory[$id]);
            return true;
        }
    }
}
