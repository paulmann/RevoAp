<?php
/**
 * ApiKeyRepository: PDO-backed storage for the `api_keys` table plus
 * an in-memory fallback when the database is unavailable (tests, CI,
 * smoke runs).
 *
 * Security invariant: the repository NEVER sees or stores the plaintext
 * API key. Callers pass only the SHA-256 hex digest produced by
 * App\Api\ApiKeyService::hashKey(). A leaked database dump therefore
 * contains only irreversible digests.
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

final class ApiKeyRepository
{
    public const TABLE = 'api_keys';

    /** @var array<int,ApiKey> in-memory mirror keyed by id */
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
     * Insert a new key row (hash only - never the plaintext).
     *
     * @param  string       $label       Human-readable key name.
     * @param  string       $keyHash     SHA-256 hex digest of the plaintext key.
     * @param  string       $prefix      Readable `rv_XXXXXXXX` prefix.
     * @param  list<string> $scopes      Active capability list.
     */
    public function create(
        string  $label,
        string  $keyHash,
        string  $prefix,
        array   $scopes,
        int     $rpmLimit = 30,
        int     $dailyLimit = 1000,
        ?string $expiresAt = null,
    ): ApiKey {
        $scopesValue = implode(',', $scopes);
        if (!$this->useDatabase()) {
            $id = ++$this->memoryAutoId;
            $key = new ApiKey(
                id:         $id,
                label:      $label,
                keyHash:    strtolower($keyHash),
                prefix:     strtolower($prefix),
                scopes:     $scopes,
                rpmLimit:   max(0, $rpmLimit),
                dailyLimit: max(0, $dailyLimit),
                expiresAt:  $expiresAt,
                lastUsedAt: null,
                revokedAt:  null,
                createdAt:  gmdate('Y-m-d H:i:s'),
            );
            $this->memory[$id] = $key;
            return $key;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . '
                 (label, key_hash, prefix, scopes, rpm_limit, daily_limit, expires_at)
                 VALUES (:label, :key_hash, :prefix, :scopes, :rpm_limit, :daily_limit, :expires_at)'
            );
            $stmt->execute([
                'label'       => $label,
                'key_hash'    => strtolower($keyHash),
                'prefix'      => strtolower($prefix),
                'scopes'      => $scopesValue,
                'rpm_limit'   => max(0, $rpmLimit),
                'daily_limit' => max(0, $dailyLimit),
                'expires_at'  => $expiresAt,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            return $this->findById($id)
                ?? new ApiKey($id, $label, strtolower($keyHash), strtolower($prefix), $scopes, max(0, $rpmLimit), max(0, $dailyLimit), $expiresAt);
        } catch (PDOException) {
            // Degrade to memory. The flag sends the retry straight to the
            // memory branch instead of looping against the broken PDO.
            $this->forceMemory = true;
            return $this->create($label, $keyHash, $prefix, $scopes, $rpmLimit, $dailyLimit, $expiresAt);
        }
    }

    public function findById(int $id): ?ApiKey
    {
        if (!$this->useDatabase()) {
            return $this->memory[$id] ?? null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? ApiKey::fromRow($row) : null;
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->memory[$id] ?? null;
        }
    }

    public function findByHash(string $keyHash): ?ApiKey
    {
        $keyHash = strtolower($keyHash);
        if (!$this->useDatabase()) {
            foreach ($this->memory as $key) {
                if (hash_equals($key->keyHash, $keyHash)) {
                    return $key;
                }
            }
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE key_hash = :hash LIMIT 1');
            $stmt->execute(['hash' => $keyHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? ApiKey::fromRow($row) : null;
        } catch (PDOException) {
            $this->forceMemory = true;
            foreach ($this->memory as $key) {
                if (hash_equals($key->keyHash, $keyHash)) {
                    return $key;
                }
            }
            return null;
        }
    }

    public function findByPrefix(string $prefix): ?ApiKey
    {
        $prefix = strtolower($prefix);
        if (!$this->useDatabase()) {
            foreach ($this->memory as $key) {
                if ($key->prefix === $prefix) {
                    return $key;
                }
            }
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE prefix = :prefix LIMIT 1');
            $stmt->execute(['prefix' => $prefix]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? ApiKey::fromRow($row) : null;
        } catch (PDOException) {
            $this->forceMemory = true;
            foreach ($this->memory as $key) {
                if ($key->prefix === $prefix) {
                    return $key;
                }
            }
            return null;
        }
    }

    /** @return list<ApiKey> Active keys first, newest to oldest. */
    public function all(): array
    {
        if (!$this->useDatabase()) {
            $rows = array_values($this->memory);
            usort($rows, static fn(ApiKey $a, ApiKey $b): int => ($b->id <=> $a->id));
            return $rows;
        }
        try {
            $stmt = $this->pdo->query(
                'SELECT * FROM ' . self::TABLE . ' ORDER BY (revoked_at IS NOT NULL) ASC, id DESC'
            );
            $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            return array_map(static fn(array $r): ApiKey => ApiKey::fromRow($r), is_array($rows) ? $rows : []);
        } catch (PDOException) {
            $this->forceMemory = true;
            $rows = array_values($this->memory);
            usort($rows, static fn(ApiKey $a, ApiKey $b): int => ($b->id <=> $a->id));
            return $rows;
        }
    }

    /**
     * Mark a key as revoked (non-destructive: keeps the audit record).
     * Returns the updated key or null when unknown.
     */
    public function revoke(int $id): ?ApiKey
    {
        if (!$this->useDatabase()) {
            $key = $this->memory[$id] ?? null;
            if ($key === null) {
                return null;
            }
            $updated = new ApiKey(
                id:         $key->id,
                label:      $key->label,
                keyHash:    $key->keyHash,
                prefix:     $key->prefix,
                scopes:     $key->scopes,
                rpmLimit:   $key->rpmLimit,
                dailyLimit: $key->dailyLimit,
                expiresAt:  $key->expiresAt,
                lastUsedAt: $key->lastUsedAt,
                revokedAt:  gmdate('Y-m-d H:i:s'),
                createdAt:  $key->createdAt,
            );
            $this->memory[$id] = $updated;
            return $updated;
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::TABLE . ' SET revoked_at = COALESCE(revoked_at, :now) WHERE id = :id'
            );
            $stmt->execute(['id' => $id, 'now' => gmdate('Y-m-d H:i:s')]);
            return $this->findById($id);
        } catch (PDOException) {
            $this->forceMemory = true;
            return $this->revoke($id);
        }
    }

    /**
     * Replace the key material of an existing key: inserts a new row
     * with fresh hash/prefix and revokes the old row. Preserves label
     * and quotas. Returns the new key or null when the old key does
     * not exist.
     */
    public function rotate(int $id, string $newHash, string $newPrefix): ?ApiKey
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        if ($existing->revokedAt === null) {
            $this->revoke($id);
        }
        return $this->create(
            label:      $existing->label,
            keyHash:    $newHash,
            prefix:     $newPrefix,
            scopes:     $existing->scopes,
            rpmLimit:   $existing->rpmLimit,
            dailyLimit: $existing->dailyLimit,
            expiresAt:  $existing->expiresAt,
        );
    }

    /** Record a successful authenticated use of the key. */
    public function touchLastUsed(int $id): void
    {
        $now = gmdate('Y-m-d H:i:s');
        if (!$this->useDatabase()) {
            $key = $this->memory[$id] ?? null;
            if ($key === null) {
                return;
            }
            $this->memory[$id] = new ApiKey(
                id:         $key->id,
                label:      $key->label,
                keyHash:    $key->keyHash,
                prefix:     $key->prefix,
                scopes:     $key->scopes,
                rpmLimit:   $key->rpmLimit,
                dailyLimit: $key->dailyLimit,
                expiresAt:  $key->expiresAt,
                lastUsedAt: $now,
                revokedAt:  $key->revokedAt,
                createdAt:  $key->createdAt,
            );
            return;
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::TABLE . ' SET last_used_at = :now WHERE id = :id'
            );
            $stmt->execute(['id' => $id, 'now' => $now]);
        } catch (PDOException) {
            // Non-fatal bookkeeping.
        }
    }

    /**
     * Aggregate stats across keys (never exposes hashes).
     *
     * @return array{total:int,active:int,revoked:int,expired:int}
     */
    public function stats(): array
    {
        $total = $active = $revoked = $expired = 0;
        foreach ($this->all() as $key) {
            $total++;
            if ($key->revokedAt !== null) {
                $revoked++;
                continue;
            }
            if (!$key->isUsable()) {
                $expired++;
                continue;
            }
            $active++;
        }
        return [
            'total'   => $total,
            'active'  => $active,
            'revoked' => $revoked,
            'expired' => $expired,
        ];
    }
}
