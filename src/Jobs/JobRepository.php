<?php
/**
 * JobRepository: PDO-backed CRUD for `revoap_jobs`.
 *
 * Accepts either a raw PDO (legacy) or a resilient App\Core\Database
 * wrapper. When a Database is supplied each call survives a single
 * "MySQL server has gone away" disconnect via Database::run(). When
 * no database is supplied the repository falls back to an in-memory
 * mirror so tests and memory-mode boots keep working.
 *
 * v1.8.39 — adds the retry-policy / backoff bookkeeping required by
 * the stale-job auto-recovery sweep: each row carries `attempts`,
 * `max_attempts`, `next_run_at`, `last_recovered_at`, `recovery_reason`,
 * and `last_error_at`. `acquireNext()` now skips rows whose
 * `next_run_at` is in the future. `recoverStale()` is the new sweep
 * entry-point that requeues stale rows below the attempt cap and
 * fails-terminally any that have exhausted retries. The pre-v1.8.39
 * `reclaimStale()` method is kept as a thin compatibility shim.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use App\Helpers\JsonHelper;
use PDO;

final class JobRepository
{
    /** @var array<int,array<string,mixed>> */
    private array $memory = [];
    private int $autoId = 0;
    private readonly ?Database $db;

    /**
     * Toggle that hides the retry-policy columns from generated SQL on
     * deploys that have not applied migration 012 yet. Auto-detected on
     * the first DB-backed call and cached for the lifetime of the
     * process so older schemas keep working without an extra round-trip
     * per query.
     */
    private ?bool $hasRecoveryColumns = null;

    /**
     * Optional audit sink (v1.8.40). When wired the repository writes
     * one row per notable lifecycle transition to `revoap_job_events`
     * so the History UI can render a complete retry/recovery timeline.
     * The sink is intentionally best-effort: a failure to write an
     * audit event never aborts the underlying job mutation.
     */
    private ?JobAuditRepository $audit = null;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function database(): ?Database { return $this->db; }

    /**
     * v1.8.40 — attach the optional audit sink so lifecycle transitions
     * write a row into `revoap_job_events`. The sink is best-effort:
     * a failed audit insert is logged but never propagated to callers.
     */
    public function setAuditRepository(?JobAuditRepository $audit): void
    {
        $this->audit = $audit;
    }

    public function auditRepository(): ?JobAuditRepository
    {
        return $this->audit;
    }

    /**
     * Best-effort audit emit. Swallows every Throwable so a missing
     * migration or schema drift cannot abort the underlying job
     * mutation.
     *
     * @param array<string,mixed> $fields
     */
    private function emitAudit(array $fields): void
    {
        if ($this->audit === null) { return; }
        try {
            $this->audit->record($fields);
        } catch (\Throwable) {
            // Audit is advisory only — never propagate the failure.
        }
    }

    /**
     * Whether the underlying `revoap_jobs` table is present. Tests
     * and memory-mode boots return true unconditionally because the
     * in-memory mirror always exists.
     */
    public function tableAvailable(): bool
    {
        if ($this->db === null) { return true; }
        try {
            return (bool)$this->db->run(static function (PDO $pdo): bool {
                $stmt = $pdo->query('SHOW TABLES LIKE "revoap_jobs"');
                if ($stmt === false) { return false; }
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $stmt->closeCursor();
                return is_array($row) && $row !== [];
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create a new job row. Returns the new id.
     *
     * @param array<string,mixed> $payload
     */
    public function create(int $sessionId, ?int $userId, array $payload, int $priority = 0, ?int $maxAttempts = null): int
    {
        $payloadJson = JsonHelper::encode($payload);
        $maxAttemptsValue = $maxAttempts !== null && $maxAttempts > 0 ? $maxAttempts : 3;
        $newId = 0;
        if ($this->db === null) {
            $id = ++$this->autoId;
            $now = self::now();
            $this->memory[$id] = [
                'id'                => $id,
                'session_id'        => $sessionId,
                'user_id'           => $userId,
                'status'            => JobStatus::Queued->value,
                'priority'          => $priority,
                'attempts'          => 0,
                'max_attempts'      => $maxAttemptsValue,
                'payload_json'      => $payloadJson,
                'result_json'       => null,
                'error_json'        => null,
                'locked_by'         => null,
                'locked_at'         => null,
                'heartbeat_at'      => null,
                'next_run_at'       => null,
                'last_recovered_at' => null,
                'recovery_reason'   => null,
                'last_error_at'     => null,
                'created_at'        => $now,
                'started_at'        => null,
                'finished_at'       => null,
                'updated_at'        => $now,
            ];
            $newId = $id;
            $this->emitAudit([
                'job_id'       => $newId,
                'session_id'   => $sessionId,
                'user_id'      => $userId,
                'event_type'   => 'job.created',
                'status_to'    => JobStatus::Queued->value,
                'attempt'      => 0,
                'max_attempts' => $maxAttemptsValue,
                'reason'       => 'enqueue',
                'message'      => 'Job enqueued.',
                'context'      => [
                    'priority' => $priority,
                ],
            ]);
            return $id;
        }
        $newId = (int)$this->db->run(function (PDO $pdo) use ($sessionId, $userId, $priority, $payloadJson, $maxAttemptsValue): int {
            if ($this->detectRecoveryColumns($pdo)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO revoap_jobs
                        (session_id, user_id, status, priority, attempts, max_attempts, payload_json)
                     VALUES
                        (:sid, :uid, :status, :priority, 0, :max_attempts, :payload)'
                );
                $stmt->execute([
                    'sid'          => $sessionId,
                    'uid'          => $userId,
                    'status'       => JobStatus::Queued->value,
                    'priority'     => $priority,
                    'max_attempts' => $maxAttemptsValue,
                    'payload'      => $payloadJson,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO revoap_jobs (session_id, user_id, status, priority, payload_json)
                     VALUES (:sid, :uid, :status, :priority, :payload)'
                );
                $stmt->execute([
                    'sid'      => $sessionId,
                    'uid'      => $userId,
                    'status'   => JobStatus::Queued->value,
                    'priority' => $priority,
                    'payload'  => $payloadJson,
                ]);
            }
            $id = (int)$pdo->lastInsertId();
            $stmt->closeCursor();
            return $id;
        });
        $this->emitAudit([
            'job_id'       => $newId,
            'session_id'   => $sessionId,
            'user_id'      => $userId,
            'event_type'   => 'job.created',
            'status_to'    => JobStatus::Queued->value,
            'attempt'      => 0,
            'max_attempts' => $maxAttemptsValue,
            'reason'       => 'enqueue',
            'message'      => 'Job enqueued.',
            'context'      => [
                'priority' => $priority,
            ],
        ]);
        return $newId;
    }

    public function find(int $id): ?Job
    {
        $row = $this->fetchRow($id);
        return $row === null ? null : self::hydrate($row);
    }

    public function findBySessionId(int $sessionId): ?Job
    {
        if ($this->db === null) {
            foreach ($this->memory as $row) {
                if ((int)$row['session_id'] === $sessionId) {
                    return self::hydrate($row);
                }
            }
            return null;
        }
        $row = $this->db->run(static function (PDO $pdo) use ($sessionId): ?array {
            $stmt = $pdo->prepare(
                'SELECT * FROM revoap_jobs WHERE session_id = :sid
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['sid' => $sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return is_array($row) ? $row : null;
        });
        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Update the lifecycle status. Optionally records a started/finished
     * timestamp transition based on the destination status.
     */
    public function updateStatus(int $id, JobStatus $status): bool
    {
        $prevJob = $this->find($id);
        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return false; }
            $this->memory[$id]['status']     = $status->value;
            $this->memory[$id]['updated_at'] = self::now();
            if ($status === JobStatus::Running && $this->memory[$id]['started_at'] === null) {
                $this->memory[$id]['started_at'] = self::now();
            }
            if ($status->isTerminal() && $this->memory[$id]['finished_at'] === null) {
                $this->memory[$id]['finished_at'] = self::now();
            }
            $this->emitStatusAudit($prevJob, $id, $status);
            return true;
        }
        $ok = (bool)$this->db->run(function (PDO $pdo) use ($id, $status): bool {
            $sql = 'UPDATE revoap_jobs SET status = :status';
            if ($status === JobStatus::Running) {
                $sql .= ', started_at = COALESCE(started_at, CURRENT_TIMESTAMP)';
            }
            if ($status->isTerminal()) {
                $sql .= ', finished_at = COALESCE(finished_at, CURRENT_TIMESTAMP)';
            }
            $sql .= ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id, 'status' => $status->value]);
            $changed = $stmt->rowCount() > 0;
            $stmt->closeCursor();
            // 0 changed rows: either the row is absent, or it already
            // carries the requested status (a no-op UPDATE — see
            // heartbeat() for the changed-vs-matched trap). Only the
            // second case means the post-condition holds.
            return $changed || $this->jobRowExists($pdo, $id);
        });
        if ($ok) {
            $this->emitStatusAudit($prevJob, $id, $status);
        }
        return $ok;
    }

    /**
     * Map an updateStatus() transition to the right audit event. Keeps
     * `event_type` predictable so the timeline UI can render the
     * correct icon/badge for each lifecycle hop.
     */
    private function emitStatusAudit(?Job $previous, int $id, JobStatus $status): void
    {
        if ($this->audit === null) { return; }
        $job = $this->find($id);
        if ($job === null) { return; }
        $eventType = match ($status) {
            JobStatus::Queued             => 'job.queued',
            JobStatus::Running            => 'worker.running',
            JobStatus::PauseRequested     => 'pause_requested',
            JobStatus::Paused             => 'paused',
            JobStatus::ResumeRequested    => 'resume_requested',
            JobStatus::CancelRequested    => 'cancel_requested',
            JobStatus::Cancelled          => 'cancelled',
            JobStatus::ConsensusRequested => 'consensus_requested',
            JobStatus::Completed          => 'job.completed',
            JobStatus::Failed             => 'job.failed',
            JobStatus::Stale              => 'stale.detected',
            default                        => 'status.changed',
        };
        $reason = match ($status) {
            JobStatus::PauseRequested,
            JobStatus::ResumeRequested,
            JobStatus::CancelRequested,
            JobStatus::ConsensusRequested => 'user_request',
            JobStatus::Stale              => 'stale_heartbeat',
            default                        => null,
        };
        $message = match ($status) {
            JobStatus::Running   => 'Worker started running this job.',
            JobStatus::Paused    => 'Job paused at worker checkpoint.',
            JobStatus::Cancelled => 'Job cancelled.',
            JobStatus::Completed => 'Job completed successfully.',
            JobStatus::Failed    => 'Job transitioned to failed.',
            JobStatus::Stale     => 'Job heartbeat lapsed — flagged as stale.',
            default               => 'Status transition: ' . ($previous?->status->value ?? '?') . ' -> ' . $status->value . '.',
        };
        $this->emitAudit([
            'job_id'       => $id,
            'session_id'   => $job->sessionId,
            'user_id'      => $job->userId,
            'event_type'   => $eventType,
            'status_from'  => $previous?->status->value,
            'status_to'    => $status->value,
            'attempt'      => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            'worker_id'    => $job->lockedBy,
            'reason'       => $reason,
            'message'      => $message,
        ]);
    }

    /**
     * Record an intent transition that only takes effect on an active
     * job. Returns the resulting status, or null if the job is missing
     * or already terminal (intent ignored).
     */
    public function requestIntent(int $id, JobStatus $intent): ?JobStatus
    {
        $job = $this->find($id);
        if ($job === null || $job->status->isTerminal()) { return null; }
        $this->updateStatus($id, $intent);
        return $intent;
    }

    /**
     * Record the worker-emitted final result envelope. Transitions to
     * Completed unless an error envelope is recorded separately.
     *
     * @param array<string,mixed> $result
     */
    public function recordResult(int $id, array $result): bool
    {
        $json = JsonHelper::encode($result);
        $job = $this->find($id);
        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return false; }
            $this->memory[$id]['result_json'] = $json;
            $this->memory[$id]['updated_at']  = self::now();
            $this->emitResultAudit($job, $id);
            return true;
        }
        $ok = (bool)$this->db->run(function (PDO $pdo) use ($id, $json): bool {
            $stmt = $pdo->prepare(
                'UPDATE revoap_jobs SET result_json = :r WHERE id = :id'
            );
            $stmt->execute(['id' => $id, 'r' => $json]);
            $changed = $stmt->rowCount() > 0;
            $stmt->closeCursor();
            // 0 changed rows: absent row, or the identical envelope was
            // already stored (a no-op UPDATE — see heartbeat()).
            return $changed || $this->jobRowExists($pdo, $id);
        });
        if ($ok) {
            $this->emitResultAudit($job, $id);
        }
        return $ok;
    }

    private function emitResultAudit(?Job $previous, int $id): void
    {
        if ($this->audit === null) { return; }
        $job = $this->find($id);
        if ($job === null) { return; }
        $this->emitAudit([
            'job_id'      => $id,
            'session_id'  => $job->sessionId,
            'user_id'     => $job->userId,
            'event_type'  => 'result.recorded',
            'status_from' => $previous?->status->value,
            'status_to'   => $job->status->value,
            'attempt'     => $job->attempts,
            'max_attempts'=> $job->maxAttempts,
            'worker_id'   => $job->lockedBy,
            'message'     => 'Worker recorded result envelope.',
        ]);
    }

    /**
     * @param array<string,mixed> $error
     */
    public function recordError(int $id, array $error): bool
    {
        $json = JsonHelper::encode($error);
        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return false; }
            $this->memory[$id]['error_json']    = $json;
            $this->memory[$id]['last_error_at'] = self::now();
            $this->memory[$id]['updated_at']    = self::now();
            $this->emitErrorAudit($id, $error);
            return true;
        }
        $ok = (bool)$this->db->run(function (PDO $pdo) use ($id, $json): bool {
            $sql = $this->detectRecoveryColumns($pdo)
                ? 'UPDATE revoap_jobs SET error_json = :e, last_error_at = CURRENT_TIMESTAMP WHERE id = :id'
                : 'UPDATE revoap_jobs SET error_json = :e WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id, 'e' => $json]);
            $changed = $stmt->rowCount() > 0;
            $stmt->closeCursor();
            // 0 changed rows: absent row, or the same error envelope was
            // already recorded on a schema without `last_error_at`, so
            // nothing differs (a no-op UPDATE — see heartbeat()).
            return $changed || $this->jobRowExists($pdo, $id);
        });
        if ($ok) {
            $this->emitErrorAudit($id, $error);
        }
        return $ok;
    }

    /**
     * @param array<string,mixed> $error
     */
    private function emitErrorAudit(int $id, array $error): void
    {
        if ($this->audit === null) { return; }
        $job = $this->find($id);
        if ($job === null) { return; }
        $sanitized = Job::sanitizeError($error);
        $reason = !empty($sanitized['exhausted_retries']) ? 'exhausted_retries' : 'error_recorded';
        $msg    = !empty($sanitized['message']) ? (string)$sanitized['message'] : 'Error envelope recorded.';
        $this->emitAudit([
            'job_id'       => $id,
            'session_id'   => $job->sessionId,
            'user_id'      => $job->userId,
            'event_type'   => 'error.recorded',
            'status_from'  => $job->status->value,
            'status_to'    => $job->status->value,
            'attempt'      => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            'worker_id'    => $job->lockedBy,
            'reason'       => $reason,
            'message'      => $msg,
            'context'      => $sanitized,
        ]);
    }

    /**
     * Whether one job row exists. Existence probe that disambiguates
     * MySQL's "no rows CHANGED" from "no rows MATCHED".
     *
     * The trap: this deployment does NOT set
     * `PDO::MYSQL_ATTR_FOUND_ROWS`, so an UPDATE that MATCHES a row but
     * writes the values the row already carries reports
     * `rowCount() === 0`. Callers that read `rowCount() > 0` as "the row
     * exists and my write landed" must fall back to this probe rather
     * than report a phantom failure. See {@see heartbeat()} for the
     * end-to-end story.
     *
     * Costs one round-trip, and only on the no-op path.
     */
    private function jobRowExists(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM revoap_jobs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $found = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();
        return $found;
    }

    /**
     * Read the current lock owner of one job row. `found` distinguishes
     * "row absent" from "row present and unlocked" — a distinction
     * {@see heartbeat()} and {@see releaseLock()} both need.
     *
     * @return array{found:bool,locked_by:?string}
     */
    private function lockState(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT locked_by FROM revoap_jobs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!is_array($row)) {
            return ['found' => false, 'locked_by' => null];
        }
        $owner = $row['locked_by'] ?? null;
        return [
            'found'     => true,
            'locked_by' => is_string($owner) && $owner !== '' ? $owner : null,
        ];
    }

    /**
     * Refresh the heartbeat timestamp. Worker calls this every N seconds
     * so a hung process can be reclaimed as Stale by another worker.
     *
     * The return value means "a heartbeat for this job is recorded for
     * THIS worker" — NOT "the UPDATE changed a row". Those differ here,
     * and conflating them is the v1.8.44 `job_roundtrip` self-test false
     * negative:
     *
     *   * `PDO::MYSQL_ATTR_FOUND_ROWS` is not set
     *     ({@see \App\Core\Config::normalizeDatabaseDescriptor()}), so
     *     MySQL reports the number of rows whose values ACTUALLY
     *     CHANGED, not the number MATCHED by the WHERE clause.
     *   * `heartbeat_at` is a DATETIME without fractional seconds and
     *     `locked_by` already holds this worker's id, so two heartbeats
     *     inside the same second write exactly the values already
     *     stored: a legitimately no-op UPDATE whose `rowCount()` is 0.
     *   * A self-test job heartbeats its own row milliseconds after
     *     creation, so it hits precisely that window — hence
     *     "heartbeat UPDATE matched no row for job #N (row missing?)".
     *
     * Fix: keep `rowCount()` as the fast path and, on 0, probe for
     * existence + ownership. The WHERE clause also refuses to touch a
     * row locked by a DIFFERENT worker, so a heartbeat carrying a stale
     * or reused worker id can no longer steal a live lock.
     *
     * Removing this guard without restoring an equivalent ownership
     * check would re-open that lock-theft hole.
     *
     * @param string $lockedBy Worker id claiming the lock.
     */
    public function heartbeat(int $id, string $lockedBy): bool
    {
        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return false; }
            $owner = $this->memory[$id]['locked_by'] ?? null;
            // Mirror the SQL guard below: never steal a foreign lock.
            if (is_string($owner) && $owner !== '' && $owner !== $lockedBy) {
                return false;
            }
            $this->memory[$id]['locked_by']    = $lockedBy;
            $this->memory[$id]['locked_at']    = self::now();
            $this->memory[$id]['heartbeat_at'] = self::now();
            $this->memory[$id]['updated_at']   = self::now();
            return true;
        }
        return (bool)$this->db->run(function (PDO $pdo) use ($id, $lockedBy): bool {
            // `:who` and `:guard` carry the same value: a named
            // placeholder may not be reused when emulated prepares are
            // disabled, which is the case on this deployment.
            $stmt = $pdo->prepare(
                'UPDATE revoap_jobs
                 SET locked_by = :who, locked_at = COALESCE(locked_at, CURRENT_TIMESTAMP),
                     heartbeat_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND (locked_by = :guard OR locked_by IS NULL)'
            );
            $stmt->execute(['id' => $id, 'who' => $lockedBy, 'guard' => $lockedBy]);
            $changed = $stmt->rowCount() > 0;
            $stmt->closeCursor();
            if ($changed) {
                return true;
            }
            // No-op UPDATE: either we already own the lock and re-wrote
            // the same second + worker id, or the guard refused the
            // write. Only the first is a successful heartbeat.
            $state = $this->lockState($pdo, $id);
            return $state['found'] && $state['locked_by'] === $lockedBy;
        });
    }

    /**
     * Phase 2: Atomically acquire the next pickable job for this worker.
     *
     * Pickable statuses are `queued` and `resume_requested`. The chosen
     * row is transitioned to `running`, stamped with `locked_by`,
     * `locked_at`, `heartbeat_at`, and `started_at` (when not already
     * set). Returns the hydrated Job, or null when nothing is waiting.
     *
     * v1.8.39: rows whose `next_run_at` lies in the future (the worker
     * scheduled a retry) are skipped until the backoff elapses.
     *
     * MySQL/MariaDB strategy:
     *   1. Open a transaction.
     *   2. `SELECT ... FOR UPDATE SKIP LOCKED` against the highest-
     *      priority oldest pickable row. SKIP LOCKED is available on
     *      MySQL 8.0+ and MariaDB 10.6+; on older servers it raises
     *      ER_PARSE_ERROR and we transparently fall back to a regular
     *      `FOR UPDATE` (which still serializes correctly across
     *      workers, just less concurrently).
     *   3. UPDATE the row to status=running, set lock columns.
     *   4. Commit. Two workers therefore can never both observe the
     *      same job in `queued`/`resume_requested`.
     *
     * In-memory mode performs a single-pass scan and atomic flip;
     * concurrency is irrelevant because there is at most one PHP
     * process backing the mirror.
     */
    public function acquireNext(string $workerId): ?Job
    {
        if ($this->db === null) {
            $now = self::now();
            foreach ($this->memory as &$row) {
                if ($row['status'] !== JobStatus::Queued->value
                 && $row['status'] !== JobStatus::ResumeRequested->value) {
                    continue;
                }
                $nextRunAt = $row['next_run_at'] ?? null;
                if (is_string($nextRunAt) && $nextRunAt !== '' && strcmp($nextRunAt, $now) > 0) {
                    continue;
                }
                $previousStatus = (string)$row['status'];
                $row['status']      = JobStatus::Running->value;
                $row['locked_by']   = $workerId;
                $row['locked_at']   = $now;
                $row['heartbeat_at']= $now;
                if (($row['started_at'] ?? null) === null) { $row['started_at'] = $now; }
                $row['updated_at']  = $now;
                $hydrated = self::hydrate($row);
                unset($row);
                $this->emitAudit([
                    'job_id'       => $hydrated->id,
                    'session_id'   => $hydrated->sessionId,
                    'user_id'      => $hydrated->userId,
                    'event_type'   => 'worker.acquired',
                    'status_from'  => $previousStatus,
                    'status_to'    => JobStatus::Running->value,
                    'attempt'      => $hydrated->attempts,
                    'max_attempts' => $hydrated->maxAttempts,
                    'worker_id'    => $workerId,
                    'reason'       => 'lock_acquired',
                    'message'      => 'Worker acquired lock and started running.',
                ]);
                return $hydrated;
            }
            unset($row);
            return null;
        }
        // MySQL/MariaDB path with explicit transaction + row lock.
        $acquired = $this->db->run(function (PDO $pdo) use ($workerId): ?Job {
            $hasRecovery = $this->detectRecoveryColumns($pdo);
            $pickable = [JobStatus::Queued->value, JobStatus::ResumeRequested->value];
            $placeholders = implode(',', array_fill(0, count($pickable), '?'));
            $nextRunClause = $hasRecovery
                ? ' AND (next_run_at IS NULL OR next_run_at <= CURRENT_TIMESTAMP)'
                : '';
            $pdo->beginTransaction();
            try {
                $sql = 'SELECT * FROM revoap_jobs
                        WHERE status IN (' . $placeholders . ')' . $nextRunClause . '
                        ORDER BY priority DESC, id ASC
                        LIMIT 1
                        FOR UPDATE SKIP LOCKED';
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($pickable);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                } catch (\PDOException $e) {
                    $sql2 = 'SELECT * FROM revoap_jobs
                             WHERE status IN (' . $placeholders . ')' . $nextRunClause . '
                             ORDER BY priority DESC, id ASC
                             LIMIT 1
                             FOR UPDATE';
                    $stmt = $pdo->prepare($sql2);
                    $stmt->execute($pickable);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                }
                if (!is_array($row) || $row === []) {
                    $pdo->commit();
                    return null;
                }
                $id = (int)$row['id'];
                $upd = $pdo->prepare(
                    'UPDATE revoap_jobs
                     SET status = :status,
                         locked_by = :who,
                         locked_at = CURRENT_TIMESTAMP,
                         heartbeat_at = CURRENT_TIMESTAMP,
                         started_at = COALESCE(started_at, CURRENT_TIMESTAMP)
                     WHERE id = :id'
                );
                $upd->execute([
                    'status' => JobStatus::Running->value,
                    'who'    => $workerId,
                    'id'     => $id,
                ]);
                $upd->closeCursor();
                $pdo->commit();
            } catch (\Throwable $e) {
                try { $pdo->rollBack(); } catch (\Throwable) {}
                throw $e;
            }
            $stmt = $pdo->prepare('SELECT * FROM revoap_jobs WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return is_array($fresh) ? self::hydrate($fresh) : null;
        });
        if ($acquired !== null) {
            $this->emitAudit([
                'job_id'       => $acquired->id,
                'session_id'   => $acquired->sessionId,
                'user_id'      => $acquired->userId,
                'event_type'   => 'worker.acquired',
                'status_to'    => JobStatus::Running->value,
                'attempt'      => $acquired->attempts,
                'max_attempts' => $acquired->maxAttempts,
                'worker_id'    => $workerId,
                'reason'       => 'lock_acquired',
                'message'      => 'Worker acquired lock and started running.',
            ]);
        }
        return $acquired;
    }

    /**
     * Park a non-terminal job until a future moment, without recording a
     * failure and without consuming the retry budget.
     *
     * This is the durable form of "the task is not blocked on us, and it
     * is not done either". It is the same scheduling primitive
     * {@see self::requeueForInfrastructure()} uses — status back to
     * `queued`, lock cleared, `next_run_at` pushed into the future, which
     * `acquireNext()` filters on — with three deliberate differences:
     *
     *   1. **No failure is recorded.** `requeueForInfrastructure()` writes a
     *      `DatabaseUnavailable` envelope into `error_json`, which is
     *      correct for its case and wrong for this one: a batch that is
     *      running on the provider has not failed at anything. Writing a
     *      database error there would put a fabricated infrastructure fault
     *      on a healthy run and on the operator's screen.
     *   2. **`result_json` is preserved.** The caller owns it — for the
     *      Batch path it holds the phase state — so this method only
     *      schedules the row and touches nothing else.
     *   3. **A reason is recorded in the recovery columns** so the timeline
     *      can say why the row went to sleep, without pretending it was an
     *      error: `recovery_reason` is the neutral bookkeeping column.
     *
     * Terminal rows are never resurrected, and a row locked by a live
     * worker is left alone (its own phase transition will park it).
     *
     * @param string $reason       Short machine-readable cause, e.g. `batch_poll_wait`.
     * @param int    $delaySeconds Seconds before the row becomes pickable (>= 1).
     */
    public function parkUntil(int $id, int $delaySeconds, string $reason): bool
    {
        $delaySeconds = max(1, $delaySeconds);
        $nextRunAt    = date('Y-m-d H:i:s', time() + $delaySeconds);
        $terminal     = [JobStatus::Completed->value, JobStatus::Failed->value, JobStatus::Cancelled->value];

        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return false; }
            if (in_array((string)$this->memory[$id]['status'], $terminal, true)) { return false; }
            $this->memory[$id]['status']            = JobStatus::Queued->value;
            $this->memory[$id]['locked_by']         = null;
            $this->memory[$id]['locked_at']         = null;
            $this->memory[$id]['next_run_at']       = $nextRunAt;
            $this->memory[$id]['last_recovered_at'] = self::now();
            $this->memory[$id]['recovery_reason']   = $reason;
            $this->memory[$id]['updated_at']        = self::now();
            return true;
        }

        return (bool)$this->db->run(function (PDO $pdo) use ($id, $nextRunAt, $reason, $terminal): bool {
            $changed = $this->runParkStatement($pdo, $id, $nextRunAt, $reason, $terminal, true);
            if ($changed === null) {
                // Pre-migration-012 schema has no scheduling columns; the
                // worker's own anti-spin sleep covers the missed delay.
                $changed = $this->runParkStatement($pdo, $id, $nextRunAt, $reason, $terminal, false);
            }
            if ($changed > 0) {
                return true;
            }
            return $this->nonTerminalRowExists($pdo, $id, $terminal);
        });
    }

    /**
     * Execute the park UPDATE. Mirrors {@see self::runRequeueStatement()}
     * but writes no `error_json`, so a parked run keeps whatever diagnosis
     * it already had.
     *
     * @param list<string> $terminal
     */
    private function runParkStatement(
        PDO $pdo,
        int $id,
        string $nextRunAt,
        string $reason,
        array $terminal,
        bool $withRecoveryColumns,
    ): ?int {
        $params = [
            'id'     => $id,
            'status' => JobStatus::Queued->value,
            't1'     => $terminal[0],
            't2'     => $terminal[1],
            't3'     => $terminal[2],
        ];
        if ($withRecoveryColumns) {
            $params['next']   = $nextRunAt;
            $params['reason'] = $reason;
            $sql = 'UPDATE revoap_jobs
                       SET status            = :status,
                           locked_by         = NULL,
                           locked_at         = NULL,
                           next_run_at       = :next,
                           last_recovered_at = CURRENT_TIMESTAMP,
                           recovery_reason   = :reason
                     WHERE id = :id AND status NOT IN (:t1, :t2, :t3)';
        } else {
            $sql = 'UPDATE revoap_jobs
                       SET status     = :status,
                           locked_by  = NULL,
                           locked_at  = NULL
                     WHERE id = :id AND status NOT IN (:t1, :t2, :t3)';
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $changed = $stmt->rowCount();
            $stmt->closeCursor();
            return $changed;
        } catch (\PDOException $e) {
            if (!$withRecoveryColumns || !self::isMissingColumn($e)) {
                throw $e;
            }
            return null;
        }
    }

    /**
     * Re-arm a parked job for immediate pickup.
     *
     * The parking design has one hole, and this method is the whole of the
     * fix. `acquireNext()` only ever picks rows in `queued` /
     * `resume_requested`, so once a run parks itself with a future
     * `next_run_at` it is invisible to the queue. `requestIntent()` writes
     * the operator's intent by *changing the status* — a cancel becomes
     * `cancel_requested` — which is not in the pickable set. The result is
     * a row that no worker will ever look at again while the provider keeps
     * running (and billing) the batch nobody stopped.
     *
     * Re-arming returns the row to `queued` with `next_run_at` cleared, so
     * the next poll of the worker loop picks it up within a second and the
     * phase that owns the intent can act on it.
     *
     * The intent is read from the row BEFORE the status is reset, and
     * handed back to the caller, because after the reset it is no longer
     * observable. Callers persist it into `result_json` (the state the
     * runner actually reads) and then call this.
     *
     * Only a parked row is touched: a row a worker currently holds is left
     * alone, since that worker is already going to observe the intent
     * through its own checkpoints.
     *
     * @param string|null $hadStatus The status the row carried, or null when
     *                               it was not parked and nothing changed.
     */
    public function rearmParked(int $id): ?JobStatus
    {
        $job = $this->find($id);
        if ($job === null || $job->status->isTerminal()) {
            return null;
        }
        $parked = $job->status === JobStatus::Queued
            || ($job->status === JobStatus::CancelRequested && ($job->lockedBy === null || $job->lockedBy === ''))
            || $job->status === JobStatus::PauseRequested;
        if (!$parked) {
            return null;
        }

        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return null; }
            $previous = JobStatus::tryFromString((string)$this->memory[$id]['status']);
            $this->memory[$id]['status']      = JobStatus::Queued->value;
            $this->memory[$id]['next_run_at'] = null;
            $this->memory[$id]['updated_at']  = self::now();
            return $previous;
        }

        $ok = (bool)$this->db->run(function (PDO $pdo) use ($id): bool {
            $stmt = $pdo->prepare(
                'UPDATE revoap_jobs SET status = :status, next_run_at = NULL WHERE id = :id'
            );
            $stmt->execute(['status' => JobStatus::Queued->value, 'id' => $id]);
            $changed = $stmt->rowCount();
            $stmt->closeCursor();
            return $changed > 0 || $this->jobRowExists($pdo, $id);
        });
        return $ok ? $job->status : null;
    }

    /**
     * Finish cancel requests whose owner disappeared.
     *
     * `cancel_requested` is an intent: the worker that owns the job is expected to
     * notice it, stop and write `cancelled`. If that worker dies - a deploy, a crash,
     * a host restart - nobody ever does: the row is not in the pickable set, and the
     * stale sweep only looks at `running` rows, so the job sits in
     * `cancel_requested` for ever and its session stays `running` with it. One such
     * row was found on the production host, days old.
     *
     * Abandoned is measured by `updated_at`: the same clock the rest of the row uses,
     * so it needs no heartbeat and no timezone guess.
     *
     * @return int Number of rows finished as cancelled.
     */
    public function cancelAbandoned(int $thresholdSeconds): int
    {
        $seconds = max(1, $thresholdSeconds);
        if ($this->db === null) {
            $cutoff = time() - $seconds;
            $n = 0;
            foreach ($this->memory as &$row) {
                if (($row['status'] ?? '') !== JobStatus::CancelRequested->value) { continue; }
                $updated = (string)($row['updated_at'] ?? '');
                $ts = $updated !== '' ? (int)strtotime($updated) : 0;
                if ($ts !== 0 && $ts >= $cutoff) { continue; }
                $row['status']          = JobStatus::Cancelled->value;
                $row['updated_at']      = self::now();
                $row['finished_at']     = $row['finished_at'] ?? self::now();
                $row['locked_by']       = null;
                $row['locked_at']       = null;
                $row['recovery_reason'] = 'cancel_abandoned';
                $n++;
            }
            unset($row);
            return $n;
        }

        return (int)$this->db->run(function (PDO $pdo) use ($seconds): int {
            $stmt = $pdo->prepare(
                'UPDATE revoap_jobs
                    SET status = :to,
                        updated_at = CURRENT_TIMESTAMP,
                        finished_at = COALESCE(finished_at, CURRENT_TIMESTAMP),
                        locked_by = NULL,
                        locked_at = NULL,
                        recovery_reason = :reason
                  WHERE status = :from
                    AND updated_at < DATE_SUB(NOW(), INTERVAL ' . $seconds . ' SECOND)'
            );
            $stmt->execute([
                ':to'     => JobStatus::Cancelled->value,
                ':from'   => JobStatus::CancelRequested->value,
                ':reason' => 'cancel_abandoned',
            ]);
            return (int)$stmt->rowCount();
        });
    }

    /**
     * v1.8.39 — auto-recovery sweep over running jobs whose heartbeat
     * lapsed. Each candidate is either requeued (when `attempts <
     * max_attempts`) with `next_run_at` set to a future timestamp per
     * the supplied backoff schedule, or terminally failed (when
     * `attempts >= max_attempts`).
     *
     * The sweep operates in two SQL passes wrapped in a single
     * transaction so two workers calling `recoverStale()` concurrently
     * never double-recover the same row. The in-memory mirror performs
     * the same logic synchronously.
     *
     * @param int       $thresholdSeconds  Heartbeat lapse threshold.
     * @param list<int> $backoffSeconds    Per-attempt delay list. The
     *                                     value used for attempt N is
     *                                     `backoffSeconds[min(N-1, count-1)]`.
     *                                     Defaults to [30, 120, 300].
     * @param bool      $enabled           When false the sweep falls
     *                                     back to the pre-v1.8.39
     *                                     "flip to stale" behaviour
     *                                     and returns the count of
     *                                     stale rows recorded.
     *
     * @return array{requeued:int,exhausted:int,stale:int} Per-row counters.
     */
    public function recoverStale(int $thresholdSeconds, array $backoffSeconds = [30, 120, 300], bool $enabled = true): array
    {
        $thresholdSeconds = max(1, $thresholdSeconds);
        if ($backoffSeconds === []) { $backoffSeconds = [30]; }
        $now = time();

        if (!$enabled) {
            $stale = $this->reclaimStaleLegacy($thresholdSeconds);
            return ['requeued' => 0, 'exhausted' => 0, 'stale' => $stale];
        }

        if ($this->db === null) {
            $requeued = 0;
            $exhausted = 0;
            $cutoff = $now - $thresholdSeconds;
            foreach ($this->memory as $id => &$row) {
                if ($row['status'] !== JobStatus::Running->value) { continue; }
                $hb = $row['heartbeat_at'] ?? null;
                $ts = is_string($hb) && $hb !== '' ? (int)strtotime($hb) : 0;
                if ($ts === 0 || $ts >= $cutoff) { continue; }
                $attempts    = (int)($row['attempts'] ?? 0) + 1;
                $maxAttempts = max(1, (int)($row['max_attempts'] ?? 3));
                $sessionId   = (int)($row['session_id'] ?? 0);
                $userId      = isset($row['user_id']) && $row['user_id'] !== null ? (int)$row['user_id'] : null;
                $workerId    = isset($row['locked_by']) ? (string)$row['locked_by'] : null;
                $this->emitAudit([
                    'job_id'       => (int)$id,
                    'session_id'   => $sessionId,
                    'user_id'      => $userId,
                    'event_type'   => 'stale.detected',
                    'status_from'  => JobStatus::Running->value,
                    'status_to'    => JobStatus::Running->value,
                    'attempt'      => $attempts - 1,
                    'max_attempts' => $maxAttempts,
                    'worker_id'    => $workerId,
                    'reason'       => 'stale_heartbeat',
                    'message'      => 'Worker heartbeat lapsed beyond threshold (' . $thresholdSeconds . 's).',
                    'context'      => [
                        'threshold_seconds' => $thresholdSeconds,
                        'heartbeat_at'      => $hb,
                    ],
                ]);
                $row['attempts']          = $attempts;
                $row['last_recovered_at'] = self::now();
                $row['recovery_reason']   = 'stale_heartbeat';
                $row['locked_by']         = null;
                $row['locked_at']         = null;
                $row['updated_at']        = self::now();
                if ($attempts > $maxAttempts) {
                    $row['status']        = JobStatus::Failed->value;
                    $row['finished_at']   = self::now();
                    $row['error_json']    = JsonHelper::encode([
                        'class'             => 'StaleJobRecoveryExhausted',
                        'message'           => 'Job heartbeat lapsed and the retry budget is exhausted.',
                        'stage'             => 'recovery',
                        'exhausted_retries' => true,
                        'attempts'          => $attempts - 1,
                        'at'                => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                    $row['last_error_at'] = self::now();
                    $exhausted++;
                    $this->emitAudit([
                        'job_id'       => (int)$id,
                        'session_id'   => $sessionId,
                        'user_id'      => $userId,
                        'event_type'   => 'stale.exhausted',
                        'status_from'  => JobStatus::Running->value,
                        'status_to'    => JobStatus::Failed->value,
                        'attempt'      => $attempts - 1,
                        'max_attempts' => $maxAttempts,
                        'worker_id'    => $workerId,
                        'reason'       => 'exhausted_retries',
                        'message'      => 'Retry budget exhausted; job marked as failed.',
                        'context'      => [
                            'exhausted_retries' => true,
                            'attempts'          => $attempts - 1,
                        ],
                    ]);
                } else {
                    $delay = self::backoffFor($attempts, $backoffSeconds);
                    $row['status']      = JobStatus::Queued->value;
                    $row['next_run_at'] = date('Y-m-d H:i:s', $now + $delay);
                    $requeued++;
                    $this->emitAudit([
                        'job_id'          => (int)$id,
                        'session_id'      => $sessionId,
                        'user_id'         => $userId,
                        'event_type'      => 'stale.requeued',
                        'status_from'     => JobStatus::Running->value,
                        'status_to'       => JobStatus::Queued->value,
                        'attempt'         => $attempts,
                        'max_attempts'    => $maxAttempts,
                        'backoff_seconds' => $delay,
                        'next_run_at'     => $row['next_run_at'],
                        'worker_id'       => $workerId,
                        'reason'          => 'stale_heartbeat',
                        'message'         => 'Job auto-requeued (attempt ' . $attempts . '/' . $maxAttempts . ', backoff ' . $delay . 's).',
                    ]);
                }
            }
            unset($row);
            $this->cancelAbandoned($thresholdSeconds);
            return ['requeued' => $requeued, 'exhausted' => $exhausted, 'stale' => 0];
        }

        $this->cancelAbandoned($thresholdSeconds);
        return $this->db->run(function (PDO $pdo) use ($thresholdSeconds, $backoffSeconds, $now): array {
            if (!$this->detectRecoveryColumns($pdo)) {
                // Schema not migrated yet — fall back to legacy behaviour
                // so a half-deployed host doesn't crash the worker.
                $stale = $this->reclaimStaleLegacyPdo($pdo, $thresholdSeconds);
                return ['requeued' => 0, 'exhausted' => 0, 'stale' => $stale];
            }
            $requeued = 0;
            $exhausted = 0;
            $pdo->beginTransaction();
            try {
                $sql = 'SELECT id, session_id, user_id, locked_by, heartbeat_at, attempts, max_attempts
                          FROM revoap_jobs
                         WHERE status = :running
                           AND heartbeat_at IS NOT NULL
                           AND heartbeat_at < (CURRENT_TIMESTAMP - INTERVAL :sec SECOND)';
                try {
                    $sel = $pdo->prepare($sql . ' FOR UPDATE SKIP LOCKED');
                    $sel->execute(['running' => JobStatus::Running->value, 'sec' => $thresholdSeconds]);
                    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
                    $sel->closeCursor();
                } catch (\PDOException) {
                    $sel = $pdo->prepare($sql . ' FOR UPDATE');
                    $sel->execute(['running' => JobStatus::Running->value, 'sec' => $thresholdSeconds]);
                    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
                    $sel->closeCursor();
                }
                $upRequeue = $pdo->prepare(
                    'UPDATE revoap_jobs
                        SET status            = :status,
                            attempts          = :attempts,
                            locked_by         = NULL,
                            locked_at         = NULL,
                            next_run_at       = :next_run_at,
                            last_recovered_at = CURRENT_TIMESTAMP,
                            recovery_reason   = :reason
                      WHERE id = :id'
                );
                $upExhausted = $pdo->prepare(
                    'UPDATE revoap_jobs
                        SET status            = :status,
                            attempts          = :attempts,
                            locked_by         = NULL,
                            locked_at         = NULL,
                            error_json        = :err,
                            last_recovered_at = CURRENT_TIMESTAMP,
                            recovery_reason   = :reason,
                            last_error_at     = CURRENT_TIMESTAMP,
                            finished_at       = COALESCE(finished_at, CURRENT_TIMESTAMP)
                      WHERE id = :id'
                );
                $auditEmits = [];
                foreach ($rows as $row) {
                    $id          = (int)$row['id'];
                    $attempts    = (int)($row['attempts'] ?? 0) + 1;
                    $maxAttempts = max(1, (int)($row['max_attempts'] ?? 3));
                    $sessionId   = (int)($row['session_id'] ?? 0);
                    $userId      = isset($row['user_id']) && $row['user_id'] !== null ? (int)$row['user_id'] : null;
                    $workerId    = isset($row['locked_by']) ? (string)$row['locked_by'] : null;
                    $hb          = isset($row['heartbeat_at']) ? (string)$row['heartbeat_at'] : null;
                    $auditEmits[] = [
                        'job_id'       => $id,
                        'session_id'   => $sessionId,
                        'user_id'      => $userId,
                        'event_type'   => 'stale.detected',
                        'status_from'  => JobStatus::Running->value,
                        'status_to'    => JobStatus::Running->value,
                        'attempt'      => $attempts - 1,
                        'max_attempts' => $maxAttempts,
                        'worker_id'    => $workerId,
                        'reason'       => 'stale_heartbeat',
                        'message'      => 'Worker heartbeat lapsed beyond threshold (' . $thresholdSeconds . 's).',
                        'context'      => [
                            'threshold_seconds' => $thresholdSeconds,
                            'heartbeat_at'      => $hb,
                        ],
                    ];
                    if ($attempts > $maxAttempts) {
                        $err = JsonHelper::encode([
                            'class'             => 'StaleJobRecoveryExhausted',
                            'message'           => 'Job heartbeat lapsed and the retry budget is exhausted.',
                            'stage'             => 'recovery',
                            'exhausted_retries' => true,
                            'attempts'          => $attempts - 1,
                            'at'                => gmdate('Y-m-d\TH:i:s\Z'),
                        ]);
                        $upExhausted->execute([
                            'status'   => JobStatus::Failed->value,
                            'attempts' => $attempts,
                            'err'      => $err,
                            'reason'   => 'stale_heartbeat_exhausted',
                            'id'       => $id,
                        ]);
                        $upExhausted->closeCursor();
                        $exhausted++;
                        $auditEmits[] = [
                            'job_id'       => $id,
                            'session_id'   => $sessionId,
                            'user_id'      => $userId,
                            'event_type'   => 'stale.exhausted',
                            'status_from'  => JobStatus::Running->value,
                            'status_to'    => JobStatus::Failed->value,
                            'attempt'      => $attempts - 1,
                            'max_attempts' => $maxAttempts,
                            'worker_id'    => $workerId,
                            'reason'       => 'exhausted_retries',
                            'message'      => 'Retry budget exhausted; job marked as failed.',
                            'context'      => [
                                'exhausted_retries' => true,
                                'attempts'          => $attempts - 1,
                            ],
                        ];
                    } else {
                        $delay = self::backoffFor($attempts, $backoffSeconds);
                        $nextRunAtSql = date('Y-m-d H:i:s', $now + $delay);
                        $upRequeue->execute([
                            'status'      => JobStatus::Queued->value,
                            'attempts'    => $attempts,
                            'next_run_at' => $nextRunAtSql,
                            'reason'      => 'stale_heartbeat',
                            'id'          => $id,
                        ]);
                        $upRequeue->closeCursor();
                        $requeued++;
                        $auditEmits[] = [
                            'job_id'          => $id,
                            'session_id'      => $sessionId,
                            'user_id'         => $userId,
                            'event_type'      => 'stale.requeued',
                            'status_from'     => JobStatus::Running->value,
                            'status_to'       => JobStatus::Queued->value,
                            'attempt'         => $attempts,
                            'max_attempts'    => $maxAttempts,
                            'backoff_seconds' => $delay,
                            'next_run_at'     => $nextRunAtSql,
                            'worker_id'       => $workerId,
                            'reason'          => 'stale_heartbeat',
                            'message'         => 'Job auto-requeued (attempt ' . $attempts . '/' . $maxAttempts . ', backoff ' . $delay . 's).',
                        ];
                    }
                }
                $pdo->commit();
                foreach ($auditEmits as $emit) {
                    $this->emitAudit($emit);
                }
            } catch (\Throwable $e) {
                try { $pdo->rollBack(); } catch (\Throwable) {}
                throw $e;
            }
            $this->cancelAbandoned($thresholdSeconds);
            return ['requeued' => $requeued, 'exhausted' => $exhausted, 'stale' => 0];
        });
    }

    /**
     * v1.8.47 — put a job back in the queue after an INFRASTRUCTURE
     * failure (lost database connection, not a model failure).
     *
     * `attempts` is deliberately NOT touched. That counter is the
     * budget for retrying a task whose MODEL failed; burning it because
     * MySQL was restarted would terminally fail work that never got a
     * chance to run — and with `max_attempts = 3` a single server
     * restart would kill every job in the queue. Infrastructure faults
     * are the environment's problem, not the job's.
     *
     * The row is moved back to `queued`, the lock is cleared, and
     * `next_run_at` is pushed `$delaySeconds` into the future so the
     * worker cannot pick the same job up again immediately (the
     * anti-spin guard for a database that is genuinely down).
     *
     * Terminal rows are never resurrected, so a late arrival cannot undo
     * a cancellation.
     *
     * @param string              $reason  Short machine-readable cause (e.g. `database_unavailable`).
     * @param int                 $delaySeconds Backoff before the row becomes pickable again (>= 1).
     * @param array<string,mixed> $context Extra, already-sanitized detail for `error_json`.
     */
    public function requeueForInfrastructure(int $id, string $reason, int $delaySeconds, array $context = []): bool
    {
        $delaySeconds = max(1, $delaySeconds);
        $nextRunAt    = date('Y-m-d H:i:s', time() + $delaySeconds);
        $errorJson    = JsonHelper::encode([
            'class'             => 'DatabaseUnavailable',
            'message'           => (string)($context['message'] ?? 'Database connection lost during execution.'),
            'stage'             => 'infrastructure',
            'infrastructure'    => true,
            'reason'            => $reason,
            'backoff_seconds'   => $delaySeconds,
            'at'                => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $previous = $this->find($id);
        $terminal = [JobStatus::Completed->value, JobStatus::Failed->value, JobStatus::Cancelled->value];

        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return false; }
            if (in_array((string)$this->memory[$id]['status'], $terminal, true)) { return false; }
            $this->memory[$id]['status']            = JobStatus::Queued->value;
            $this->memory[$id]['locked_by']         = null;
            $this->memory[$id]['locked_at']         = null;
            $this->memory[$id]['next_run_at']       = $nextRunAt;
            $this->memory[$id]['last_recovered_at'] = self::now();
            $this->memory[$id]['recovery_reason']   = $reason;
            $this->memory[$id]['error_json']        = $errorJson;
            $this->memory[$id]['last_error_at']     = self::now();
            $this->memory[$id]['updated_at']        = self::now();
            $this->emitInfrastructureAudit($previous, $id, $reason, $delaySeconds, $nextRunAt);
            return true;
        }

        $ok = (bool)$this->db->run(function (PDO $pdo) use ($id, $reason, $nextRunAt, $errorJson, $terminal): bool {
            $changed = $this->runRequeueStatement($pdo, $id, $nextRunAt, $reason, $errorJson, $terminal, true);
            if ($changed === null) {
                // Pre-migration-012 schema: no `next_run_at`, no
                // `recovery_reason`. Requeue without the scheduling
                // columns — the worker's own backoff covers the missed
                // anti-spin delay in that case.
                $changed = $this->runRequeueStatement($pdo, $id, $nextRunAt, $reason, $errorJson, $terminal, false);
            }
            if ($changed > 0) {
                return true;
            }
            // 0 rows changed: either the row already carries these exact
            // values (a no-op UPDATE — see heartbeat()), or it is absent
            // / terminal. Only the first is a successful requeue.
            return $this->nonTerminalRowExists($pdo, $id, $terminal);
        });

        if ($ok) {
            $this->emitInfrastructureAudit($previous, $id, $reason, $delaySeconds, $nextRunAt);
        }
        return $ok;
    }

    /**
     * Execute the requeue UPDATE. Returns the changed-row count, or null
     * when the columns this shape needs are absent from the schema.
     *
     * Each shape binds ONLY the placeholders its own SQL declares:
     * this deployment runs with emulated prepares disabled, and a
     * native-prepared statement rejects an array carrying extra keys
     * with HY093 "Invalid parameter number".
     *
     * @param list<string> $terminal
     */
    private function runRequeueStatement(
        PDO $pdo,
        int $id,
        string $nextRunAt,
        string $reason,
        string $errorJson,
        array $terminal,
        bool $withRecoveryColumns,
    ): ?int {
        $params = [
            'id'     => $id,
            'status' => JobStatus::Queued->value,
            'err'    => $errorJson,
            't1'     => $terminal[0],
            't2'     => $terminal[1],
            't3'     => $terminal[2],
        ];
        if ($withRecoveryColumns) {
            $params['next']   = $nextRunAt;
            $params['reason'] = $reason;
            $sql = 'UPDATE revoap_jobs
                       SET status            = :status,
                           locked_by         = NULL,
                           locked_at         = NULL,
                           next_run_at       = :next,
                           last_recovered_at = CURRENT_TIMESTAMP,
                           recovery_reason   = :reason,
                           error_json        = :err,
                           last_error_at     = CURRENT_TIMESTAMP
                     WHERE id = :id AND status NOT IN (:t1, :t2, :t3)';
        } else {
            $sql = 'UPDATE revoap_jobs
                       SET status     = :status,
                           locked_by  = NULL,
                           locked_at  = NULL,
                           error_json = :err
                     WHERE id = :id AND status NOT IN (:t1, :t2, :t3)';
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $changed = $stmt->rowCount();
            $stmt->closeCursor();
            return $changed;
        } catch (\PDOException $e) {
            if (!$withRecoveryColumns || !self::isMissingColumn($e)) {
                throw $e;
            }
            return null;
        }
    }

    /**
     * Does an absent/unknown COLUMN explain this error? Used to fall back
     * from the migration-012 UPDATE shape to the portable one without
     * paying for a `SHOW COLUMNS` probe on every call.
     *
     * MySQL raises 1054 (ER_BAD_FIELD_ERROR); SQLite reports
     * "no such column", PostgreSQL "column ... does not exist".
     */
    private static function isMissingColumn(\PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        if ($code === 1054 || $code === 42703) {
            return true;
        }
        $message = strtolower($e->getMessage());
        return str_contains($message, 'unknown column')
            || str_contains($message, 'no such column')
            || str_contains($message, 'does not exist');
    }

    /**
     * Existence probe for a non-terminal row. Same purpose as
     * {@see jobRowExists()} but narrowed to statuses a requeue may
     * touch, so a terminal row is never reported as successfully
     * requeued.
     *
     * @param list<string> $terminal
     */
    private function nonTerminalRowExists(PDO $pdo, int $id, array $terminal): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM revoap_jobs WHERE id = :id AND status NOT IN (:t1, :t2, :t3) LIMIT 1'
        );
        $stmt->execute(['id' => $id, 't1' => $terminal[0], 't2' => $terminal[1], 't3' => $terminal[2]]);
        $found = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();
        return $found;
    }

    private function emitInfrastructureAudit(?Job $previous, int $id, string $reason, int $delay, string $nextRunAt): void
    {
        if ($this->audit === null) { return; }
        $job = $this->find($id);
        if ($job === null) { return; }
        $this->emitAudit([
            'job_id'          => $id,
            'session_id'      => $job->sessionId,
            'user_id'         => $job->userId,
            'event_type'      => 'infrastructure.requeued',
            'status_from'     => $previous?->status->value,
            'status_to'       => JobStatus::Queued->value,
            // The retry budget is intentionally unchanged — record the
            // value as-is so the timeline shows no attempt was spent.
            'attempt'         => $job->attempts,
            'max_attempts'    => $job->maxAttempts,
            'backoff_seconds' => $delay,
            'next_run_at'     => $nextRunAt,
            'worker_id'       => $previous?->lockedBy,
            'reason'          => $reason,
            'message'         => 'Job requeued after an infrastructure failure; the model retry budget was not spent.',
        ]);
    }

    /**
     * Pre-v1.8.39 compatibility shim. Flips orphaned `running` rows to
     * `stale` without auto-recovery. Kept so callers that still want
     * the old behaviour (or hosts whose migration 012 has not run) get
     * a predictable result.
     *
     * @return int Number of rows transitioned to `stale`.
     */
    public function reclaimStale(int $thresholdSeconds): int
    {
        return $this->reclaimStaleLegacy($thresholdSeconds);
    }

    /**
     * Phase 2 — release the worker lock without changing status. Used
     * when a worker transitions a job to `paused` and another worker
     * (or a future incarnation of this one) should be free to resume.
     *
     * The boolean means "the row is not locked any more" — the same
     * changed-vs-matched caveat as {@see heartbeat()} applies: on a row
     * whose lock was already cleared the UPDATE is a no-op, `rowCount()`
     * is 0, and the row is nonetheless released. The audit event is
     * deliberately tied to the ACTUAL release (`rowCount() > 0`) rather
     * than to the return value, so calling this twice records exactly
     * one `lock.released` event — a second call has nothing to report.
     */
    public function releaseLock(int $id): bool
    {
        $job = $this->find($id);
        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return false; }
            $previousWorker = $this->memory[$id]['locked_by'] ?? null;
            $hadLock = (is_string($previousWorker) && $previousWorker !== '')
                || ($this->memory[$id]['locked_at'] ?? null) !== null;
            $this->memory[$id]['locked_by'] = null;
            $this->memory[$id]['locked_at'] = null;
            $this->memory[$id]['updated_at'] = self::now();
            if ($hadLock) {
                $this->emitLockReleaseAudit($job, is_string($previousWorker) ? $previousWorker : null);
            }
            return true;
        }
        $previousWorker = $job?->lockedBy;
        $released = false;
        $ok = (bool)$this->db->run(function (PDO $pdo) use ($id, &$released): bool {
            $stmt = $pdo->prepare(
                'UPDATE revoap_jobs
                 SET locked_by = NULL, locked_at = NULL
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);
            $released = $stmt->rowCount() > 0;
            $stmt->closeCursor();
            if ($released) {
                return true;
            }
            // No-op UPDATE: the lock was already gone. Confirm the row
            // still exists AND is genuinely free before reporting
            // success — a missing row must stay `false`.
            $state = $this->lockState($pdo, $id);
            return $state['found'] && $state['locked_by'] === null;
        });
        if ($released) {
            $this->emitLockReleaseAudit($job, $previousWorker);
        }
        return $ok;
    }

    private function emitLockReleaseAudit(?Job $job, ?string $previousWorker): void
    {
        if ($this->audit === null || $job === null) { return; }
        $this->emitAudit([
            'job_id'      => $job->id,
            'session_id'  => $job->sessionId,
            'user_id'     => $job->userId,
            'event_type'  => 'lock.released',
            'status_from' => $job->status->value,
            'status_to'   => $job->status->value,
            'attempt'     => $job->attempts,
            'max_attempts'=> $job->maxAttempts,
            'worker_id'   => $previousWorker,
            'reason'      => 'lock_released',
            'message'     => 'Worker lock released.',
        ]);
    }

    /**
     * Phase 2 — read the live status from the database without
     * hydrating the full Job. Used inside the worker's per-checkpoint
     * poll so we don't waste a round-trip on payload/result decoding.
     */
    public function readStatus(int $id): ?JobStatus
    {
        if ($this->db === null) {
            if (!isset($this->memory[$id])) { return null; }
            return JobStatus::tryFromString((string)$this->memory[$id]['status']);
        }
        $value = $this->db->run(static function (PDO $pdo) use ($id): ?string {
            $stmt = $pdo->prepare('SELECT status FROM revoap_jobs WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return is_array($row) && isset($row['status']) ? (string)$row['status'] : null;
        });
        return $value === null ? null : JobStatus::tryFromString($value);
    }

    /**
     * v1.8.39 — aggregate retry-policy counters surfaced by the Worker
     * Status diagnostic endpoint. Cheap to compute even on the DB path
     * thanks to the new `idx_jobs_attempts` index.
     *
     * Returned shape:
     *   - retry_scheduled    : queued jobs whose attempts > 0
     *   - retry_exhausted    : failed jobs whose error_json marks
     *                          exhausted_retries
     *   - next_retry_at      : earliest future next_run_at, or null
     *   - oldest_retry_age_s : age (s) of the oldest scheduled retry
     *                          (i.e. furthest in the past), or null
     *
     * @return array{retry_scheduled:int,retry_exhausted:int,next_retry_at:?string,oldest_retry_age_s:?int}
     */
    public function recoveryStats(): array
    {
        $out = [
            'retry_scheduled'    => 0,
            'retry_exhausted'    => 0,
            'next_retry_at'      => null,
            'oldest_retry_age_s' => null,
        ];
        if ($this->db === null) {
            $now = time();
            $oldestNext = null;
            foreach ($this->memory as $row) {
                $status = (string)($row['status'] ?? '');
                $attempts = (int)($row['attempts'] ?? 0);
                if ($status === JobStatus::Queued->value && $attempts > 0) {
                    $out['retry_scheduled']++;
                    $nra = $row['next_run_at'] ?? null;
                    if (is_string($nra) && $nra !== '') {
                        if ($oldestNext === null || strcmp($nra, $oldestNext) < 0) {
                            $oldestNext = $nra;
                        }
                    }
                }
                if ($status === JobStatus::Failed->value) {
                    $err = $row['error_json'] ?? null;
                    if (is_string($err) && $err !== '') {
                        $decoded = JsonHelper::tryDecode($err);
                        if (is_array($decoded) && !empty($decoded['exhausted_retries'])) {
                            $out['retry_exhausted']++;
                        }
                    }
                }
            }
            if ($oldestNext !== null) {
                $out['next_retry_at'] = $oldestNext;
                $ts = (int)strtotime($oldestNext);
                $out['oldest_retry_age_s'] = $ts > 0 ? max(0, $now - $ts) : null;
            }
            return $out;
        }
        try {
            return $this->db->run(function (PDO $pdo) use ($out): array {
                if (!$this->detectRecoveryColumns($pdo)) {
                    return $out;
                }
                $stmt = $pdo->query(
                    'SELECT
                        SUM(CASE WHEN status = "queued" AND attempts > 0 THEN 1 ELSE 0 END) AS retry_scheduled,
                        SUM(CASE WHEN status = "failed"
                                  AND error_json IS NOT NULL
                                  AND JSON_EXTRACT(error_json, "$.exhausted_retries") = TRUE
                                 THEN 1 ELSE 0 END) AS retry_exhausted,
                        MIN(CASE WHEN status = "queued" AND attempts > 0 AND next_run_at IS NOT NULL
                                 THEN next_run_at ELSE NULL END) AS next_retry_at
                     FROM revoap_jobs'
                );
                if ($stmt === false) { return $out; }
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                if (!is_array($row)) { return $out; }
                $out['retry_scheduled'] = (int)($row['retry_scheduled'] ?? 0);
                $out['retry_exhausted'] = (int)($row['retry_exhausted'] ?? 0);
                $nra = isset($row['next_retry_at']) && $row['next_retry_at'] !== null
                    ? (string)$row['next_retry_at'] : null;
                $out['next_retry_at'] = $nra;
                if ($nra !== null) {
                    $ts = (int)strtotime($nra);
                    $out['oldest_retry_age_s'] = $ts > 0 ? max(0, time() - $ts) : null;
                }
                return $out;
            }) ?? $out;
        } catch (\Throwable) {
            return $out;
        }
    }

    /**
     * List recent jobs (debug / admin). Read-only and capped.
     *
     * @return list<Job>
     */
    public function listRecent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        if ($this->db === null) {
            $rows = array_values($this->memory);
            usort($rows, static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);
            $rows = array_slice($rows, 0, $limit);
            return array_map(self::hydrate(...), $rows);
        }
        $rows = $this->db->run(static function (PDO $pdo) use ($limit): array {
            $stmt = $pdo->prepare('SELECT * FROM revoap_jobs ORDER BY id DESC LIMIT :lim');
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return is_array($rows) ? $rows : [];
        });
        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRow(int $id): ?array
    {
        if ($this->db === null) {
            return $this->memory[$id] ?? null;
        }
        return $this->db->run(static function (PDO $pdo) use ($id): ?array {
            $stmt = $pdo->prepare('SELECT * FROM revoap_jobs WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return is_array($row) ? $row : null;
        });
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function hydrate(array $row): Job
    {
        $statusValue = (string)($row['status'] ?? 'queued');
        $status = JobStatus::tryFromString($statusValue) ?? JobStatus::Queued;
        $payload = self::decodeJson($row['payload_json'] ?? null);
        $result  = self::decodeJsonNullable($row['result_json'] ?? null);
        $error   = self::decodeJsonNullable($row['error_json'] ?? null);
        return new Job(
            id:               (int)$row['id'],
            sessionId:        (int)$row['session_id'],
            userId:           isset($row['user_id']) ? ($row['user_id'] === null ? null : (int)$row['user_id']) : null,
            status:           $status,
            priority:         (int)($row['priority'] ?? 0),
            payload:          is_array($payload) ? $payload : [],
            result:           $result,
            error:            $error,
            lockedBy:         isset($row['locked_by'])    ? (string)$row['locked_by']    : null,
            lockedAt:         isset($row['locked_at'])    ? (string)$row['locked_at']    : null,
            heartbeatAt:      isset($row['heartbeat_at']) ? (string)$row['heartbeat_at'] : null,
            createdAt:        (string)($row['created_at'] ?? self::now()),
            startedAt:        isset($row['started_at'])   ? (string)$row['started_at']   : null,
            finishedAt:       isset($row['finished_at'])  ? (string)$row['finished_at']  : null,
            updatedAt:        isset($row['updated_at'])   ? (string)$row['updated_at']   : null,
            attempts:         isset($row['attempts'])     ? (int)$row['attempts']        : 0,
            maxAttempts:      isset($row['max_attempts']) && $row['max_attempts'] !== null
                              ? (int)$row['max_attempts'] : 3,
            nextRunAt:        isset($row['next_run_at'])       && $row['next_run_at']       !== null ? (string)$row['next_run_at']       : null,
            lastRecoveredAt:  isset($row['last_recovered_at']) && $row['last_recovered_at'] !== null ? (string)$row['last_recovered_at'] : null,
            recoveryReason:   isset($row['recovery_reason'])   && $row['recovery_reason']   !== null ? (string)$row['recovery_reason']   : null,
            lastErrorAt:      isset($row['last_error_at'])     && $row['last_error_at']     !== null ? (string)$row['last_error_at']     : null,
        );
    }

    /**
     * @param list<int> $backoffSeconds
     */
    private static function backoffFor(int $attempt, array $backoffSeconds): int
    {
        if ($attempt <= 0) { $attempt = 1; }
        $idx = $attempt - 1;
        if ($idx >= count($backoffSeconds)) {
            $idx = count($backoffSeconds) - 1;
        }
        $value = $backoffSeconds[$idx] ?? 0;
        return max(0, (int)$value);
    }

    private function reclaimStaleLegacy(int $thresholdSeconds): int
    {
        if ($this->db === null) {
            $count = 0;
            $cutoff = time() - $thresholdSeconds;
            foreach ($this->memory as &$row) {
                if ($row['status'] !== JobStatus::Running->value) { continue; }
                $hb = $row['heartbeat_at'];
                $ts = is_string($hb) && $hb !== '' ? (int)strtotime($hb) : 0;
                if ($ts === 0 || $ts >= $cutoff) { continue; }
                $row['status']     = JobStatus::Stale->value;
                $row['updated_at'] = self::now();
                $count++;
            }
            unset($row);
            return $count;
        }
        return (int)$this->db->run(function (PDO $pdo) use ($thresholdSeconds): int {
            return $this->reclaimStaleLegacyPdo($pdo, $thresholdSeconds);
        });
    }

    private function reclaimStaleLegacyPdo(PDO $pdo, int $thresholdSeconds): int
    {
        $stmt = $pdo->prepare(
            'UPDATE revoap_jobs
             SET status = :stale
             WHERE status = :running
               AND heartbeat_at IS NOT NULL
               AND heartbeat_at < (CURRENT_TIMESTAMP - INTERVAL :sec SECOND)'
        );
        $stmt->execute([
            'stale'   => JobStatus::Stale->value,
            'running' => JobStatus::Running->value,
            'sec'     => $thresholdSeconds,
        ]);
        $n = $stmt->rowCount();
        $stmt->closeCursor();
        return $n;
    }

    /**
     * Lazily probe whether the v1.8.39 recovery columns exist on the
     * live `revoap_jobs` table. Cached for the lifetime of this
     * repository instance.
     */
    private function detectRecoveryColumns(PDO $pdo): bool
    {
        if ($this->hasRecoveryColumns !== null) {
            return $this->hasRecoveryColumns;
        }
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM revoap_jobs LIKE 'attempts'");
            if ($stmt === false) {
                $this->hasRecoveryColumns = false;
                return false;
            }
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $stmt->closeCursor();
            $this->hasRecoveryColumns = is_array($row) && $row !== [];
        } catch (\Throwable) {
            $this->hasRecoveryColumns = false;
        }
        return $this->hasRecoveryColumns;
    }

    private static function decodeJson(mixed $v): mixed
    {
        if (is_array($v)) { return $v; }
        if (!is_string($v) || $v === '') { return []; }
        $decoded = JsonHelper::tryDecode($v);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function decodeJsonNullable(mixed $v): ?array
    {
        if ($v === null) { return null; }
        if (is_array($v)) { return $v; }
        if (!is_string($v) || $v === '') { return null; }
        $decoded = JsonHelper::tryDecode($v);
        return is_array($decoded) ? $decoded : null;
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
