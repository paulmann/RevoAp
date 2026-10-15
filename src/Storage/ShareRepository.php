<?php
/**
 * ShareRepository: persistent + in-memory storage for shared_sessions.
 *
 * Generates high-entropy, opaque tokens (32 random bytes hex-encoded =
 * 64 chars). Tokens are NOT derived from the session id — they cannot
 * be predicted, enumerated, or brute-forced.
 *
 * When no database is configured the repository keeps tokens in process
 * memory so single-request flows still work in CI / smoke; persistent
 * sharing across requests requires a configured DB.
 *
 * v1.1.0 (1.8.24): Self-heal on live databases where migration 006 was
 * not applied. Before the first INSERT we issue the migration's exact
 * `CREATE TABLE IF NOT EXISTS shared_sessions ...` DDL, memoized per
 * repository instance so it does not repeat on every call. If the DDL
 * cannot run (no privileges, driver-specific failure) the repository
 * throws SchemaMissingException so the HTTP layer can surface a precise
 * 409 migration_required diagnostic instead of an opaque 500.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-12
 */
declare(strict_types=1);

namespace App\Storage;

use App\Core\Database;
use PDO;
use PDOException;

final class ShareRepository
{
    /** @var array<string,array<string,mixed>> */
    private array $memory = [];
    private readonly ?Database $db;

    /**
     * Memoization flag: true once the table is known to exist for this
     * repository instance, so we only run the DDL once per request.
     */
    private bool $tableEnsured = false;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function isPersistent(): bool
    {
        return $this->db !== null;
    }

    /**
     * Generate a fresh 64-character opaque hex token. The token is built
     * from random_bytes(32) so it carries 256 bits of entropy — well
     * above the conventional 128-bit "unguessable" threshold.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Create (or reuse) an unrevoked token for a completed session.
     * Returns the token.
     *
     * @throws SchemaMissingException When the shared_sessions table is
     *         missing and the repository could not auto-create it.
     */
    public function shareSession(int $sessionId): string
    {
        if ($this->db === null) {
            foreach ($this->memory as $tok => $row) {
                if ((int)$row['session_id'] === $sessionId && empty($row['revoked_at'])) {
                    return $tok;
                }
            }
            $tok = self::generateToken();
            $this->memory[$tok] = [
                'token'      => $tok,
                'session_id' => $sessionId,
                'created_at' => date('Y-m-d H:i:s'),
                'revoked_at' => null,
            ];
            return $tok;
        }

        $this->ensureTable();

        $existing = $this->db->run(static function (PDO $pdo) use ($sessionId): ?array {
            $stmt = $pdo->prepare(
                'SELECT token FROM shared_sessions
                 WHERE session_id = :sid AND revoked_at IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['sid' => $sessionId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        });
        if ($existing !== null && isset($existing['token']) && is_string($existing['token']) && $existing['token'] !== '') {
            return (string)$existing['token'];
        }
        $token = self::generateToken();
        try {
            $this->db->run(static function (PDO $pdo) use ($sessionId, $token): void {
                $stmt = $pdo->prepare(
                    'INSERT INTO shared_sessions (session_id, token) VALUES (:sid, :tok)'
                );
                $stmt->execute(['sid' => $sessionId, 'tok' => $token]);
            });
        } catch (PDOException $e) {
            if (self::isMissingTableError($e)) {
                // Retry once: race where another worker dropped the table
                // between ensureTable() and INSERT. Re-run DDL then retry.
                $this->tableEnsured = false;
                $this->ensureTable();
                $this->db->run(static function (PDO $pdo) use ($sessionId, $token): void {
                    $stmt = $pdo->prepare(
                        'INSERT INTO shared_sessions (session_id, token) VALUES (:sid, :tok)'
                    );
                    $stmt->execute(['sid' => $sessionId, 'tok' => $token]);
                });
            } else {
                throw $e;
            }
        }
        return $token;
    }

    /**
     * Resolve a token to its session id. Revoked tokens return null.
     */
    public function findSessionId(string $token): ?int
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        if ($this->db === null) {
            $row = $this->memory[$token] ?? null;
            if ($row === null || !empty($row['revoked_at'])) {
                return null;
            }
            return (int)$row['session_id'];
        }
        try {
            return $this->db->run(static function (PDO $pdo) use ($token): ?int {
                $stmt = $pdo->prepare(
                    'SELECT session_id FROM shared_sessions
                     WHERE token = :tok AND revoked_at IS NULL LIMIT 1'
                );
                $stmt->execute(['tok' => $token]);
                $row = $stmt->fetch();
                if (!is_array($row)) { return null; }
                return (int)($row['session_id'] ?? 0) ?: null;
            });
        } catch (PDOException $e) {
            if (self::isMissingTableError($e)) {
                // No table = no tokens. Don't auto-create on read paths;
                // the table only matters for sessions that exist.
                return null;
            }
            throw $e;
        }
    }

    /**
     * Revoke a token. No-op when the token does not exist or is already revoked.
     */
    public function revoke(string $token): bool
    {
        $token = trim($token);
        if ($token === '') { return false; }
        if ($this->db === null) {
            if (!isset($this->memory[$token])) { return false; }
            $this->memory[$token]['revoked_at'] = date('Y-m-d H:i:s');
            return true;
        }
        try {
            return (bool)$this->db->run(static function (PDO $pdo) use ($token): bool {
                $stmt = $pdo->prepare(
                    'UPDATE shared_sessions SET revoked_at = CURRENT_TIMESTAMP
                     WHERE token = :tok AND revoked_at IS NULL'
                );
                $stmt->execute(['tok' => $token]);
                return $stmt->rowCount() > 0;
            });
        } catch (PDOException $e) {
            if (self::isMissingTableError($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Issue the exact DDL from db/migrations/006_shared_sessions.sql so
     * the share button self-heals on databases where the migration was
     * never applied. The DDL is idempotent (CREATE TABLE IF NOT EXISTS),
     * memoized per repository instance, and falls back to a
     * SQLite-compatible form when the live PDO is using sqlite (test
     * substrate). On failure we throw SchemaMissingException so the HTTP
     * layer can return 409 migration_required instead of 500.
     *
     * @throws SchemaMissingException
     */
    private function ensureTable(): void
    {
        if ($this->tableEnsured || $this->db === null) {
            return;
        }
        $migration = 'db/migrations/006_shared_sessions.sql';
        try {
            $this->db->run(function (PDO $pdo): void {
                $driver = '';
                try {
                    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                } catch (\Throwable) {
                    // Some drivers don't expose this; default to MySQL DDL.
                }
                if ($driver === 'sqlite') {
                    $pdo->exec(
                        'CREATE TABLE IF NOT EXISTS shared_sessions (
                            id           INTEGER PRIMARY KEY AUTOINCREMENT,
                            session_id   INTEGER NOT NULL,
                            token        TEXT    NOT NULL UNIQUE,
                            created_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            revoked_at   TEXT    NULL
                        )'
                    );
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shared_session ON shared_sessions (session_id)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shared_created ON shared_sessions (created_at)');
                    return;
                }
                // MySQL / MariaDB — exact DDL from migration 006.
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS shared_sessions (
                        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        session_id   INT UNSIGNED NOT NULL,
                        token        CHAR(64)     NOT NULL UNIQUE,
                        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        revoked_at   DATETIME     NULL,
                        INDEX idx_shared_session  (session_id),
                        INDEX idx_shared_created  (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            });
            $this->tableEnsured = true;
        } catch (\Throwable $e) {
            throw new SchemaMissingException(
                table: 'shared_sessions',
                migration: $migration,
                message: 'shared_sessions table is missing and could not be created automatically. '
                    . 'Apply ' . $migration . ' to the live database.',
                previous: $e,
            );
        }
    }

    /**
     * MySQL SQLSTATE 42S02 / errno 1146 = base table or view not found.
     * SQLite raises a generic "no such table" message.
     */
    private static function isMissingTableError(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'no such table')
            || str_contains($msg, "doesn't exist")
            || str_contains($msg, 'does not exist')
            || str_contains($msg, '1146')) {
            return true;
        }
        $sqlState = $e->errorInfo[0] ?? null;
        if ($sqlState === '42S02') {
            return true;
        }
        return false;
    }
}
