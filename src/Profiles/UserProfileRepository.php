<?php
/**
 * UserProfileRepository: PDO-backed CRUD for users + user_profiles.
 *
 * The repository owns the deterministic `local` user — it lazily
 * seeds the row on the first lookup so deployments that have yet to
 * apply migration 009 still get a usable in-memory profile (with
 * persistence quietly disabled until the migration runs).
 *
 * Accepts either a raw PDO (legacy) or a resilient App\Core\Database
 * wrapper. When a Database is supplied every statement survives a
 * single "MySQL server has gone away" / "Server shutdown in progress"
 * disconnect via Database::run(). The engine reads the local profile on
 * every pipeline run ({@see PipelineEngine}), so a handle cached at boot
 * used to break every job after a MySQL restart.
 *
 * When the underlying tables are absent or the database handle is null
 * the repository falls back to an in-memory store keyed by
 * external_id so the rest of the application — and the SmokeTest —
 * keep working.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Profiles;

use App\Core\Database;
use PDO;
use PDOException;

final class UserProfileRepository
{
    public const LOCAL_EXTERNAL_ID = 'local';

    /** @var array<string,array<string,mixed>> in-memory fallback when no database is available */
    private array $memoryUsers = [];
    /** @var array<int,array<string,mixed>> */
    private array $memoryProfiles = [];
    private int $autoUserId = 0;
    private int $autoProfileId = 0;
    private readonly ?Database $db;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    /** The resilient wrapper backing this repository, when there is one. */
    public function database(): ?Database
    {
        return $this->db;
    }

    /**
     * Resolve (and lazily create) the deterministic local user. Returns
     * the user_id used by the council/profile tables.
     */
    public function ensureLocalUser(): int
    {
        return $this->ensureUser(self::LOCAL_EXTERNAL_ID);
    }

    public function ensureUser(string $externalId): int
    {
        $externalId = $externalId === '' ? self::LOCAL_EXTERNAL_ID : $externalId;
        if ($this->db === null || !$this->tablesAvailable()) {
            if (!isset($this->memoryUsers[$externalId])) {
                $id = ++$this->autoUserId;
                $this->memoryUsers[$externalId] = [
                    'id' => $id,
                    'external_id' => $externalId,
                    'email' => null,
                ];
            }
            return (int)$this->memoryUsers[$externalId]['id'];
        }

        return (int)$this->db->run(static function (PDO $pdo) use ($externalId): int {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE external_id = :ext LIMIT 1');
            $stmt->execute(['ext' => $externalId]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            if (is_array($row) && isset($row['id'])) {
                return (int)$row['id'];
            }
            $ins = $pdo->prepare('INSERT INTO users (external_id) VALUES (:ext)');
            $ins->execute(['ext' => $externalId]);
            $id = (int)$pdo->lastInsertId();
            $ins->closeCursor();
            return $id;
        });
    }

    public function findByExternalId(string $externalId): UserProfile
    {
        $userId = $this->ensureUser($externalId);
        return $this->findByUserId($userId, $externalId);
    }

    public function findByUserId(int $userId, string $externalId = self::LOCAL_EXTERNAL_ID): UserProfile
    {
        if ($this->db === null || !$this->tablesAvailable()) {
            $row = $this->memoryProfiles[$userId] ?? null;
            if (!is_array($row)) {
                return UserProfile::empty($userId, $externalId);
            }
            return self::hydrate($row, $externalId);
        }

        $row = $this->db->run(static function (PDO $pdo) use ($userId): ?array {
            $stmt = $pdo->prepare(
                'SELECT p.*, u.external_id FROM user_profiles p
                 JOIN users u ON u.id = p.user_id
                 WHERE p.user_id = :uid LIMIT 1'
            );
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            return is_array($row) ? $row : null;
        });
        if ($row === null) {
            return UserProfile::empty($userId, $externalId);
        }
        return self::hydrate($row, (string)($row['external_id'] ?? $externalId));
    }

    /**
     * Upsert the profile for the supplied external id. Returns the
     * persisted profile (post-write read so the caller sees the
     * canonical row).
     *
     * @param array<string,string> $input
     */
    public function save(string $externalId, array $input): UserProfile
    {
        $externalId = $externalId === '' ? self::LOCAL_EXTERNAL_ID : $externalId;
        $userId = $this->ensureUser($externalId);

        $clean = [
            'email'                  => self::sanitize($input['email']       ?? '', 191),
            'phone'                  => self::sanitize($input['phone']       ?? '', 64),
            'first_name'             => self::sanitize($input['first_name']  ?? '', 128),
            'last_name'              => self::sanitize($input['last_name']   ?? '', 128),
            'job_title'              => self::sanitize($input['job_title']   ?? '', 191),
            'bio'                    => self::sanitizeMultiline($input['bio'] ?? '', 8192),
            'system_prompt_addendum' => self::sanitizeMultiline($input['system_prompt_addendum'] ?? '', 8192),
        ];

        if ($this->db === null || !$this->tablesAvailable()) {
            $existing = $this->memoryProfiles[$userId] ?? null;
            $id = is_array($existing) ? (int)($existing['id'] ?? ++$this->autoProfileId) : ++$this->autoProfileId;
            $this->memoryProfiles[$userId] = array_merge([
                'id'      => $id,
                'user_id' => $userId,
            ], $clean);
            return $this->findByUserId($userId, $externalId);
        }

        try {
            // Keep users.email aligned with the profile's email so a future
            // identity layer can resolve users by email without reading the
            // profile table. Non-fatal — the profile UPSERT below is
            // authoritative, and a transient failure must not block it.
            $this->db->run(static function (PDO $pdo) use ($clean, $userId): void {
                $u = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
                $u->execute(['email' => $clean['email'] === '' ? null : $clean['email'], 'id' => $userId]);
                $u->closeCursor();
            });
        } catch (PDOException) {
            // See above.
        }

        $sql = 'INSERT INTO user_profiles
                    (user_id, email, phone, first_name, last_name, job_title, bio, system_prompt_addendum)
                VALUES
                    (:uid, :email, :phone, :first_name, :last_name, :job_title, :bio, :addendum)
                ON DUPLICATE KEY UPDATE
                    email = VALUES(email),
                    phone = VALUES(phone),
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    job_title = VALUES(job_title),
                    bio = VALUES(bio),
                    system_prompt_addendum = VALUES(system_prompt_addendum)';
        $this->db->run(static function (PDO $pdo) use ($sql, $clean, $userId): void {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'uid'        => $userId,
                'email'      => $clean['email'],
                'phone'      => $clean['phone'],
                'first_name' => $clean['first_name'],
                'last_name'  => $clean['last_name'],
                'job_title'  => $clean['job_title'],
                'bio'        => $clean['bio'],
                'addendum'   => $clean['system_prompt_addendum'],
            ]);
            $stmt->closeCursor();
        });
        return $this->findByUserId($userId, $externalId);
    }

    public function tablesAvailable(): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            return (bool)$this->db->run(static function (PDO $pdo): bool {
                $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users','user_profiles')");
                    $names = $r ? $r->fetchAll(PDO::FETCH_COLUMN) : [];
                    return is_array($names) && count($names) === 2;
                }
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (\'users\', \'user_profiles\')'
                );
                $stmt->execute();
                $count = (int)$stmt->fetchColumn();
                $stmt->closeCursor();
                return $count === 2;
            });
        } catch (PDOException) {
            return false;
        }
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row, string $externalId): UserProfile
    {
        return new UserProfile(
            id:                   isset($row['id']) ? (int)$row['id'] : null,
            userId:               (int)($row['user_id'] ?? 0),
            externalId:           $externalId,
            email:                (string)($row['email'] ?? ''),
            phone:                (string)($row['phone'] ?? ''),
            firstName:            (string)($row['first_name'] ?? ''),
            lastName:             (string)($row['last_name'] ?? ''),
            jobTitle:             (string)($row['job_title'] ?? ''),
            bio:                  (string)($row['bio'] ?? ''),
            systemPromptAddendum: (string)($row['system_prompt_addendum'] ?? ''),
        );
    }

    private static function sanitize(string $s, int $max): string
    {
        $s = trim($s);
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? $s;
        return mb_substr($s, 0, $max);
    }

    private static function sanitizeMultiline(string $s, int $max): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $s) ?? $s;
        $s = trim($s);
        return mb_substr($s, 0, $max);
    }
}
