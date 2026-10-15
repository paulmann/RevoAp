<?php
/**
 * PdoMemoryRepository: Persistence for MemoryItem instances.
 *
 * Reads/writes the `memory_items` table declared in db/schema.sql and
 * shipped incrementally as db/migrations/014_memory_items.sql. Mirrors
 * the project's repository convention: when the PDO handle is null (or
 * any database operation fails) it degrades to an in-process array store
 * so the pipeline never breaks because memory is unavailable.
 *
 * The Task-5 adaptation of PROTO
 * Consensus-AI-main/src/Infrastructure/Memory/PdoMemoryRepository.php
 * drops the missing-table footgun of the prototype: row operations no
 * longer assume the table exists and catch every \Throwable instead.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

namespace App\Memory;

use App\Core\Logger;
use App\Helpers\JsonHelper;
use DateTimeImmutable;
use PDO;
use Throwable;

final class PdoMemoryRepository
{
    public const TABLE = 'memory_items';

    /** @var array<int,MemoryItem> */
    private array $memory = [];

    private int $autoId = 0;

    /** @var bool|null Lazily-decided flag: can the table actually be used? */
    private ?bool $tableReady = null;

    public function __construct(private readonly ?PDO $pdo = null) {}

    /**
     * Persist a new memory item and return its id. Returns 0 when the
     * write is impossible (no PDO, missing table, storage error).
     */
    public function add(MemoryItem $item): int
    {
        if ($this->pdo === null) {
            return $this->addInMemory($item);
        }
        if (!$this->ensureTableReady()) {
            return $this->addInMemory($item);
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (session_id, content, metadata, type)
                 VALUES (:s, :c, :m, :t)'
            );
            $stmt->execute([
                's' => $item->sessionId,
                'c' => $item->content,
                'm' => JsonHelper::encode($item->metadata),
                't' => $this->coerceType($item->type),
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            Logger::warn('memory.repo.add_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
            return $this->addInMemory($item);
        }
    }

    public function findById(int $id): ?MemoryItem
    {
        if (isset($this->memory[$id])) {
            return $this->memory[$id];
        }
        if ($this->pdo === null || !$this->ensureTableReady()) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            return is_array($row) ? MemoryItem::fromRow($row) : null;
        } catch (Throwable $e) {
            Logger::warn('memory.repo.find_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
            return null;
        }
    }

    /**
     * Latest items of one session first.
     *
     * @return list<MemoryItem>
     */
    public function findBySession(int $sessionId, int $limit = 100): array
    {
        $rows = [];
        if ($this->pdo !== null && $this->ensureTableReady()) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT * FROM ' . self::TABLE . ' WHERE session_id = :s ORDER BY id DESC LIMIT ' . max(1, (int)$limit)
                );
                $stmt->execute(['s' => $sessionId]);
                foreach ($stmt->fetchAll() ?: [] as $row) {
                    if (is_array($row)) {
                        $rows[] = MemoryItem::fromRow($row);
                    }
                }
            } catch (Throwable $e) {
                Logger::warn('memory.repo.by_session_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
                $rows = [];
            }
        }
        // Merge in-memory items for the same session (degraded writes).
        foreach ($this->memory as $item) {
            if ($item->sessionId === $sessionId) {
                $rows[] = $item;
            }
        }
        usort($rows, static fn(MemoryItem $a, MemoryItem $b): int => ($b->id ?? 0) <=> ($a->id ?? 0));
        return array_slice($rows, 0, max(1, (int)$limit));
    }

    /**
     * Every item across all sessions, newest first. Used by the rebuild
     * maintenance CLI and the benchmark.
     *
     * @return list<MemoryItem>
     */
    public function all(): array
    {
        $rows = [];
        if ($this->pdo !== null && $this->ensureTableReady()) {
            try {
                $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC');
                foreach ($stmt->fetchAll() ?: [] as $row) {
                    if (is_array($row)) {
                        $rows[] = MemoryItem::fromRow($row);
                    }
                }
            } catch (Throwable $e) {
                Logger::warn('memory.repo.all_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
                $rows = [];
            }
        }
        foreach ($this->memory as $item) {
            $rows[] = $item;
        }
        usort($rows, static fn(MemoryItem $a, MemoryItem $b): int => ($a->id ?? 0) <=> ($b->id ?? 0));
        return $rows;
    }

    /**
     * Delete items created before the threshold; returns deleted count.
     * The in-memory degraded store is kept in sync when present.
     */
    public function deleteOlderThan(DateTimeImmutable $threshold): int
    {
        $deleted = 0;
        foreach ($this->memory as $id => $item) {
            $created = $item->createdAt;
            if ($created !== null && $created !== '' && strtotime($created) < $threshold->getTimestamp()) {
                unset($this->memory[$id]);
                $deleted++;
            }
        }
        if ($this->pdo === null || !$this->ensureTableReady()) {
            return $deleted;
        }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE created_at < :t');
            $stmt->execute(['t' => $threshold->format('Y-m-d H:i:s')]);
            return $deleted + $stmt->rowCount();
        } catch (Throwable $e) {
            Logger::warn('memory.repo.cleanup_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
            return $deleted;
        }
    }

    /** Total persisted rows (table + degraded store), for CLI status. */
    public function count(): int
    {
        $n = count($this->memory);
        if ($this->pdo !== null && $this->ensureTableReady()) {
            try {
                $n += (int)$this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn();
            } catch (Throwable) {
                // degraded count only
            }
        }
        return $n;
    }

    /**
     * Earliest created_at (SQL timestamp) of all persisted rows, or null
     * when the table is unavailable or empty. The degradation contract
     * matches the rest of the repository: schema stays encapsulated here
     * and the caller never touches SQL.
     */
    public function oldest(): ?string
    {
        if ($this->pdo === null || !$this->ensureTableReady()) {
            return null;
        }
        try {
            $stmt = $this->pdo->query('SELECT MIN(created_at) FROM ' . self::TABLE);
            $value = $stmt->fetchColumn();
            return $value !== false && $value !== null ? (string)$value : null;
        } catch (Throwable $e) {
            Logger::warn('memory.repo.oldest_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
            return null;
        }
    }

    /**
     * Probe table readiness once. A failing probe is cached so every
     * subsequent call degrades instantly instead of re-throwing.
     */
    private function ensureTableReady(): bool
    {
        if ($this->tableReady !== null) {
            return $this->tableReady;
        }
        if ($this->pdo === null) {
            $this->tableReady = false;
            return false;
        }
        try {
            $this->pdo->query('SELECT 1 FROM ' . self::TABLE . ' LIMIT 1');
            $this->tableReady = true;
        } catch (Throwable $e) {
            $this->tableReady = false;
            Logger::warn('memory.repo.table_missing', [
                'error'  => Logger::sanitizeScalar($e->getMessage()),
                // The table is declared in db/schema.sql AND shipped as
                // migration 014. On a database where 014 is already
                // recorded in schema_migrations, "Apply Migrations"
                // skips it — hence the per-file Apply / ReCreate DB
                // wording instead of a bare "apply the migration".
                'hint'   => 'Run Settings → Database → ReCreate DB, or re-apply '
                    . 'db/migrations/014_memory_items.sql with the per-file Apply button '
                    . '(it is also declared in db/schema.sql)',
            ]);
        }
        return $this->tableReady;
    }

    private function addInMemory(MemoryItem $item): int
    {
        $id = ++$this->autoId;
        $this->memory[$id] = new MemoryItem(
            id:        $id,
            sessionId: $item->sessionId,
            content:   $item->content,
            metadata:  $item->metadata,
            type:      $item->type,
            createdAt: $item->createdAt ?? gmdate('Y-m-d H:i:s'),
        );
        return $id;
    }

    private function coerceType(string $type): string
    {
        return in_array($type, MemoryItem::TYPES, true) ? $type : MemoryItem::TYPE_TEXT;
    }
}
