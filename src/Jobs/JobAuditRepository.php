<?php
/**
 * JobAuditRepository: PDO-backed append-only audit log for the durable
 * job layer.
 *
 * Every notable lifecycle transition of an `revoap_jobs` row is
 * persisted as one immutable row in `revoap_job_events` so the
 * History UI ("Retry & recovery" timeline) can render the complete
 * story of when a job became stale, when it was requeued, which
 * attempt was running, what backoff was used, and why it finally
 * failed or completed.
 *
 * Best-effort write contract: a failure to persist an audit event must
 * NOT corrupt the underlying job state. Callers wrap every `record()`
 * call in `try { ... } catch (\Throwable) {}` and continue. The repo
 * itself swallows DB errors from inside `record()` so even a missing
 * migration (the audit table never created) cannot break job creation.
 *
 * Sanitization contract:
 *   * `message` is capped at 500 chars and any embedded secret-looking
 *     tokens are scrubbed (re-uses Logger::sanitizeScalar()).
 *   * `context_json` is recursively walked by `Job::sanitizePayload()`
 *     so any key that looks like a secret (api_key, token, password,
 *     secret, authorization, …) is dropped before the insert.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use PDO;
use Throwable;

final class JobAuditRepository
{
    /** @var array<int,array<string,mixed>> */
    private array $memory = [];
    private int $autoId = 0;
    private readonly ?Database $db;

    /**
     * Cached probe — `null` means "not yet checked"; `false` means the
     * migration is missing on this host and we should keep silent.
     */
    private ?bool $tableExists = null;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function database(): ?Database { return $this->db; }

    /**
     * Whether the `revoap_job_events` table is present. Cached for
     * the lifetime of the repository instance — the in-memory mirror
     * always reports true.
     */
    public function tableAvailable(): bool
    {
        if ($this->db === null) { return true; }
        if ($this->tableExists !== null) { return $this->tableExists; }
        try {
            $this->tableExists = (bool)$this->db->run(static function (PDO $pdo): bool {
                $stmt = $pdo->query('SHOW TABLES LIKE "revoap_job_events"');
                if ($stmt === false) { return false; }
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $stmt->closeCursor();
                return is_array($row) && $row !== [];
            });
        } catch (Throwable) {
            $this->tableExists = false;
        }
        return $this->tableExists;
    }

    /**
     * Record one immutable audit row. Returns the new id, or null on
     * any failure (missing migration, DB unreachable, etc.). Callers
     * must NOT block on this method — it is purely advisory.
     *
     * @param array<string,mixed> $fields  Free-form named values matching
     *                                     the migration 013 columns.
     */
    public function record(array $fields): ?int
    {
        $payload = self::normalize($fields);
        if ($payload === null) {
            return null;
        }

        if ($this->db === null) {
            $id = ++$this->autoId;
            $this->memory[$id] = $payload + [
                'id'         => $id,
                'created_at' => self::now(),
            ];
            return $id;
        }

        if (!$this->tableAvailable()) {
            return null;
        }

        try {
            return (int)$this->db->run(static function (PDO $pdo) use ($payload): int {
                $stmt = $pdo->prepare(
                    'INSERT INTO revoap_job_events
                        (job_id, session_id, user_id, event_type,
                         status_from, status_to,
                         attempt, max_attempts, backoff_seconds, next_run_at,
                         worker_id, reason, message, context_json)
                     VALUES
                        (:job_id, :session_id, :user_id, :event_type,
                         :status_from, :status_to,
                         :attempt, :max_attempts, :backoff_seconds, :next_run_at,
                         :worker_id, :reason, :message, :context_json)'
                );
                $stmt->execute([
                    'job_id'          => $payload['job_id'],
                    'session_id'      => $payload['session_id'],
                    'user_id'         => $payload['user_id'],
                    'event_type'      => $payload['event_type'],
                    'status_from'     => $payload['status_from'],
                    'status_to'       => $payload['status_to'],
                    'attempt'         => $payload['attempt'],
                    'max_attempts'    => $payload['max_attempts'],
                    'backoff_seconds' => $payload['backoff_seconds'],
                    'next_run_at'     => $payload['next_run_at'],
                    'worker_id'       => $payload['worker_id'],
                    'reason'          => $payload['reason'],
                    'message'         => $payload['message'],
                    'context_json'    => $payload['context_json'],
                ]);
                $id = (int)$pdo->lastInsertId();
                $stmt->closeCursor();
                return $id;
            });
        } catch (Throwable $e) {
            Logger::warn('job_audit.record.failed', [
                'class'   => (new \ReflectionClass($e))->getShortName(),
                'message' => Logger::sanitizeScalar($e->getMessage()),
                'event'   => (string)$payload['event_type'],
                'job_id'  => $payload['job_id'],
            ]);
            return null;
        }
    }

    /**
     * Chronologically ordered events for a single job. Capped at 500
     * to keep the timeline payload predictable.
     *
     * @return list<JobAuditEvent>
     */
    public function listByJobId(int $jobId, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        if ($this->db === null) {
            $rows = array_values($this->memory);
            $rows = array_filter($rows, static fn(array $r): bool => (int)$r['job_id'] === $jobId);
            usort($rows, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
            $rows = array_slice($rows, 0, $limit);
            return array_map(self::hydrate(...), $rows);
        }
        if (!$this->tableAvailable()) { return []; }
        try {
            $rows = $this->db->run(static function (PDO $pdo) use ($jobId, $limit): array {
                $stmt = $pdo->prepare(
                    'SELECT * FROM revoap_job_events
                     WHERE job_id = :jid
                     ORDER BY id ASC LIMIT :lim'
                );
                $stmt->bindValue(':jid', $jobId, PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($rows) ? $rows : [];
            });
        } catch (Throwable) {
            return [];
        }
        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Chronologically ordered events for an entire session (may span
     * multiple job ids when a session is retried after exhaustion).
     *
     * @return list<JobAuditEvent>
     */
    public function listBySessionId(int $sessionId, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        if ($this->db === null) {
            $rows = array_values($this->memory);
            $rows = array_filter($rows, static fn(array $r): bool => (int)$r['session_id'] === $sessionId);
            usort($rows, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
            $rows = array_slice($rows, 0, $limit);
            return array_map(self::hydrate(...), $rows);
        }
        if (!$this->tableAvailable()) { return []; }
        try {
            $rows = $this->db->run(static function (PDO $pdo) use ($sessionId, $limit): array {
                $stmt = $pdo->prepare(
                    'SELECT * FROM revoap_job_events
                     WHERE session_id = :sid
                     ORDER BY id ASC LIMIT :lim'
                );
                $stmt->bindValue(':sid', $sessionId, PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($rows) ? $rows : [];
            });
        } catch (Throwable) {
            return [];
        }
        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Most recent N events (admin debug). Capped at 200.
     *
     * @return list<JobAuditEvent>
     */
    public function listRecent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        if ($this->db === null) {
            $rows = array_values($this->memory);
            usort($rows, static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);
            $rows = array_slice($rows, 0, $limit);
            return array_map(self::hydrate(...), $rows);
        }
        if (!$this->tableAvailable()) { return []; }
        try {
            $rows = $this->db->run(static function (PDO $pdo) use ($limit): array {
                $stmt = $pdo->prepare(
                    'SELECT * FROM revoap_job_events
                     ORDER BY id DESC LIMIT :lim'
                );
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($rows) ? $rows : [];
            });
        } catch (Throwable) {
            return [];
        }
        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Hard cap — total number of rows in the audit table. Used by tests
     * to assert that a "control intent" lifecycle wrote N rows.
     */
    public function count(): int
    {
        if ($this->db === null) { return count($this->memory); }
        if (!$this->tableAvailable()) { return 0; }
        try {
            return (int)$this->db->run(static function (PDO $pdo): int {
                $stmt = $pdo->query('SELECT COUNT(*) AS n FROM revoap_job_events');
                if ($stmt === false) { return 0; }
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($row) ? (int)($row['n'] ?? 0) : 0;
            });
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Normalize an arbitrary input map to the canonical column shape,
     * pre-sanitize anything that could carry secrets, and reject rows
     * that are missing the required (job_id, session_id, event_type)
     * triple.
     *
     * @param  array<string,mixed> $fields
     * @return array<string,mixed>|null
     */
    private static function normalize(array $fields): ?array
    {
        $jobId     = isset($fields['job_id'])     ? (int)$fields['job_id']     : 0;
        $sessionId = isset($fields['session_id']) ? (int)$fields['session_id'] : 0;
        $eventType = isset($fields['event_type']) ? trim((string)$fields['event_type']) : '';
        if ($jobId <= 0 || $sessionId <= 0 || $eventType === '') {
            return null;
        }
        if (mb_strlen($eventType) > 64) {
            $eventType = mb_substr($eventType, 0, 64);
        }

        $message = isset($fields['message']) ? (string)$fields['message'] : null;
        if (is_string($message) && $message !== '') {
            $message = Logger::sanitizeScalar($message);
            if (mb_strlen($message) > 500) {
                $message = mb_substr($message, 0, 497) . '...';
            }
        } else {
            $message = null;
        }

        $reason = isset($fields['reason']) ? (string)$fields['reason'] : null;
        if (is_string($reason) && $reason !== '') {
            $reason = Logger::sanitizeScalar($reason);
            if (mb_strlen($reason) > 190) { $reason = mb_substr($reason, 0, 190); }
        } else {
            $reason = null;
        }

        $context = $fields['context'] ?? null;
        $contextJson = null;
        if (is_array($context) && $context !== []) {
            $sanitized = Job::sanitizePayload($context);
            try {
                $contextJson = JsonHelper::encode($sanitized);
            } catch (Throwable) {
                $contextJson = null;
            }
        }

        return [
            'job_id'          => $jobId,
            'session_id'      => $sessionId,
            'user_id'         => isset($fields['user_id']) && $fields['user_id'] !== null ? (int)$fields['user_id'] : null,
            'event_type'      => $eventType,
            'status_from'     => self::shortStr($fields['status_from'] ?? null, 32),
            'status_to'       => self::shortStr($fields['status_to']   ?? null, 32),
            'attempt'         => self::intOrNull($fields['attempt']         ?? null),
            'max_attempts'    => self::intOrNull($fields['max_attempts']    ?? null),
            'backoff_seconds' => self::intOrNull($fields['backoff_seconds'] ?? null),
            'next_run_at'     => self::shortStr($fields['next_run_at']  ?? null, 32),
            'worker_id'       => self::shortStr($fields['worker_id']    ?? null, 128),
            'reason'          => $reason,
            'message'         => $message,
            'context_json'    => $contextJson,
        ];
    }

    private static function shortStr(mixed $v, int $max): ?string
    {
        if ($v === null) { return null; }
        if (!is_scalar($v)) { return null; }
        $s = trim((string)$v);
        if ($s === '') { return null; }
        if (mb_strlen($s) > $max) { $s = mb_substr($s, 0, $max); }
        return $s;
    }

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null) { return null; }
        if (is_int($v)) { return $v; }
        if (is_string($v) && is_numeric($v)) { return (int)$v; }
        if (is_float($v)) { return (int)$v; }
        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function hydrate(array $row): JobAuditEvent
    {
        $ctx = $row['context_json'] ?? null;
        $decoded = null;
        if (is_array($ctx)) {
            $decoded = $ctx;
        } elseif (is_string($ctx) && $ctx !== '') {
            $tmp = JsonHelper::tryDecode($ctx);
            $decoded = is_array($tmp) ? $tmp : null;
        }
        return new JobAuditEvent(
            id:              (int)$row['id'],
            jobId:           (int)$row['job_id'],
            sessionId:       (int)$row['session_id'],
            userId:          isset($row['user_id']) && $row['user_id'] !== null ? (int)$row['user_id'] : null,
            eventType:       (string)$row['event_type'],
            statusFrom:      isset($row['status_from'])     && $row['status_from']     !== null ? (string)$row['status_from']     : null,
            statusTo:        isset($row['status_to'])       && $row['status_to']       !== null ? (string)$row['status_to']       : null,
            attempt:         isset($row['attempt'])         && $row['attempt']         !== null ? (int)$row['attempt']            : null,
            maxAttempts:     isset($row['max_attempts'])    && $row['max_attempts']    !== null ? (int)$row['max_attempts']       : null,
            backoffSeconds:  isset($row['backoff_seconds']) && $row['backoff_seconds'] !== null ? (int)$row['backoff_seconds']    : null,
            nextRunAt:       isset($row['next_run_at'])     && $row['next_run_at']     !== null ? (string)$row['next_run_at']     : null,
            workerId:        isset($row['worker_id'])       && $row['worker_id']       !== null ? (string)$row['worker_id']       : null,
            reason:          isset($row['reason'])          && $row['reason']          !== null ? (string)$row['reason']          : null,
            message:         isset($row['message'])         && $row['message']         !== null ? (string)$row['message']         : null,
            context:         $decoded,
            createdAt:       (string)($row['created_at'] ?? self::now()),
        );
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
