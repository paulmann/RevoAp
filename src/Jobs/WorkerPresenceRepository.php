<?php
/**
 * WorkerPresenceRepository: durable liveness beacon for the job worker.
 *
 * Background — before v1.8.44 the ONLY database trace a worker left came
 * from `revoap_jobs` (`locked_by` / `heartbeat_at`). A worker that was
 * alive but had nothing to do wrote nothing at all, so
 * {@see WorkerHealthService} could not tell "running and idle" apart
 * from "never started" and the Settings UI showed "Waiting for worker"
 * while a perfectly healthy process was polling the queue.
 *
 * This repository owns the `revoap_worker_presence` table (one row per
 * worker process, migration 017):
 *
 *   row present + `last_seen_at` fresh → worker is alive
 *   row present + `last_seen_at` stale → worker crashed mid-flight
 *                                        (SIGKILL / OOM / host reboot)
 *   row absent                         → the worker exited cleanly
 *                                        (it deletes its own row on
 *                                        SIGTERM) or never started
 *
 * Design rules:
 *   * Failing to record presence MUST NEVER take the worker down. Every
 *     write is wrapped in try/catch, logged once (Logger de-duplicates
 *     identical lines per process) and reported as `false`.
 *   * Writes are throttled to at most one per second
 *     ({@see MIN_BEAT_INTERVAL_SECONDS}) so a 1 Hz poll loop cannot
 *     hammer the database with redundant UPDATEs.
 *   * A missing table (migration 017 not applied) short-circuits the
 *     write path; the negative lookup is re-probed at most once a
 *     minute so a worker started before the migration picks the table
 *     up without a restart.
 *   * A global write failure (DB unreachable) backs off for
 *     {@see FAILURE_BACKOFF_SECONDS} so the poll loop stays responsive
 *     instead of paying a connect timeout every single iteration.
 *   * No secrets: worker ids are already public via
 *     `revoap_jobs.locked_by`; nothing else is derived from them.
 *
 * The class mirrors the memory-mode pattern used by JobRepository: when
 * constructed without a database it keeps rows in a process-local array
 * so the self-test suite can exercise the presence → snapshot path
 * offline, without MySQL.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use App\Core\Logger;
use PDO;
use Throwable;

final class WorkerPresenceRepository
{
    /** Physical table name — the only place it is spelled out. */
    public const TABLE = 'revoap_worker_presence';

    /** Maximum gap between two presence writes for one worker (seconds). */
    public const MIN_BEAT_INTERVAL_SECONDS = 1.0;

    /**
     * How long write attempts are skipped after a failure. A dead
     * database must not turn every poll iteration into a connect
     * timeout, and a per-second log flood is worse than the outage.
     */
    public const FAILURE_BACKOFF_SECONDS = 30.0;

    /**
     * How long a negative `tableAvailable()` probe is trusted before it
     * is re-checked. Long enough to keep the poll loop cheap, short
     * enough that applying migration 017 on a running worker starts
     * producing presence rows within a minute.
     */
    public const TABLE_REPROBE_SECONDS = 60.0;

    /** Default row cap for {@see all()} — the UI never needs more. */
    public const DEFAULT_LIST_LIMIT = 50;

    private const MAX_WORKER_ID_LEN = 128;

    /**
     * Process-local mirror used when no database is configured. Keyed
     * by worker id so it has the same semantics as the real PK.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $memory = [];

    private readonly ?Database $db;

    /** Cached `SHOW TABLES LIKE` result; null = not probed yet. */
    private ?bool $tableExists = null;

    /** Timestamp of the last `tableAvailable()` probe. */
    private float $tableProbedAt = 0.0;

    /** Timestamp of the last successful {@see touch()}. */
    private float $lastTouchAt = 0.0;

    /** Timestamp of the last failed write (0.0 = no failure pending). */
    private float $lastFailureAt = 0.0;

    /**
     * Consecutive failed writes since the last success. Non-zero means
     * the beacon is NOT currently able to prove the worker is alive —
     * the caller must not present the process as healthy in that state.
     */
    private int $consecutiveFailures = 0;

    /**
     * Last write failure, for honest reporting. `db_lost` tells a lost
     * connection (the server went away / was restarted) apart from a
     * genuine schema or permission problem.
     *
     * @var array{class:string,message:string,db_lost:bool,at:string}|null
     */
    private ?array $lastWriteError = null;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function database(): ?Database
    {
        return $this->db;
    }

    /**
     * Whether the beacon is currently known to be unhealthy, i.e. the
     * most recent write attempt failed and no success has followed.
     *
     * A stale presence row already reads as "crashed" to
     * {@see WorkerHealthService}, so this accessor exists for the OTHER
     * half of the contract: a process that cannot write presence must
     * not claim to be healthy in its own logs either.
     */
    public function isDegraded(): bool
    {
        return $this->consecutiveFailures > 0;
    }

    /** Consecutive failed presence writes since the last success. */
    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    /** @return array{class:string,message:string,db_lost:bool,at:string}|null */
    public function lastWriteError(): ?array
    {
        return $this->lastWriteError;
    }

    /**
     * Record one failed presence write and report it. Never throws.
     *
     * `worker.presence.write_failed` stays the event name for
     * compatibility, but a lost connection is now flagged as such
     * (`db_lost`) instead of looking like a schema fault, and it is
     * reported at `warn` rather than being repeated per poll iteration:
     * {@see FAILURE_BACKOFF_SECONDS} plus Logger's per-process
     * de-duplication keep a database outage from flooding the log.
     */
    private function recordWriteFailure(Throwable $e, string $event, string $workerId): void
    {
        $this->lastFailureAt       = microtime(true);
        $this->consecutiveFailures++;
        $dbLost                    = $e instanceof \PDOException && Database::isConnectionError($e);
        $this->lastWriteError      = [
            'class'   => (new \ReflectionClass($e))->getShortName(),
            'message' => Logger::sanitizeScalar($e->getMessage()),
            'db_lost' => $dbLost,
            'at'      => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        Logger::warn($event, [
            'class'                => $this->lastWriteError['class'],
            'message'              => $this->lastWriteError['message'],
            'db_lost'              => $dbLost,
            'consecutive_failures' => $this->consecutiveFailures,
            'worker_id'            => $workerId,
        ]);
    }

    /**
     * Whether `revoap_worker_presence` exists. Cached: an exists-result
     * forever, a missing-result for {@see TABLE_REPROBE_SECONDS} so a
     * migration applied under a running worker is noticed.
     */
    public function tableAvailable(): bool
    {
        if ($this->db === null) {
            return true;
        }
        $now = microtime(true);
        if ($this->tableExists === true) {
            return true;
        }
        if ($this->tableExists === false && ($now - $this->tableProbedAt) < self::TABLE_REPROBE_SECONDS) {
            return false;
        }
        $this->tableProbedAt = $now;
        try {
            $stmt = $this->db->run(static function (PDO $pdo): \PDOStatement|false {
                return $pdo->query('SHOW TABLES LIKE "' . self::TABLE . '"');
            });
            if ($stmt === false) {
                $this->tableExists = false;
                return false;
            }
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $stmt->closeCursor();
            $this->tableExists = is_array($row) && $row !== [];
        } catch (Throwable) {
            $this->tableExists = false;
        }
        return $this->tableExists;
    }

    /**
     * Upsert the presence row for one worker. Always writes the full
     * state so a single statement is enough; `started_at` is written on
     * INSERT only and deliberately never updated on a re-beat, which is
     * what makes `now - started_at` a meaningful uptime.
     *
     * Never throws. Returns true when the row was written (or mirrored
     * in memory), false when it was skipped or failed.
     *
     * @param array{
     *     host?:?string,pid?:?int,app_version?:?string,mode?:?string,
     *     started_at?:?string,current_job_id?:?int,jobs_done?:?int,
     *     last_error_at?:?string,last_error_reason?:?string
     * } $state
     */
    public function touch(string $workerId, array $state = []): bool
    {
        $workerId = self::normalizeWorkerId($workerId);
        if ($workerId === '') {
            return false;
        }
        $row = $this->buildRow($workerId, $state);

        if ($this->db === null) {
            // Memory mirror keeps `started_at` from the first beat, like
            // the real ON DUPLICATE KEY UPDATE does.
            if (!isset($this->memory[$workerId])) {
                $this->memory[$workerId] = $row;
            } else {
                $startedAt = $this->memory[$workerId]['started_at'];
                $this->memory[$workerId] = $row;
                $this->memory[$workerId]['started_at'] = $startedAt;
            }
            $this->lastTouchAt         = microtime(true);
            $this->lastFailureAt       = 0.0;
            $this->consecutiveFailures = 0;
            $this->lastWriteError      = null;
            return true;
        }

        if (!$this->tableAvailable()) {
            return false;
        }

        try {
            $this->db->run(static function (PDO $pdo) use ($row): void {
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . self::TABLE . '
                        (worker_id, host, pid, app_version, mode,
                         started_at, last_seen_at,
                         current_job_id, jobs_done, last_error_at, last_error_reason)
                     VALUES
                        (:worker_id, :host, :pid, :app_version, :mode,
                         :started_at, :last_seen_at,
                         :current_job_id, :jobs_done, :last_error_at, :last_error_reason)
                     ON DUPLICATE KEY UPDATE
                        host              = VALUES(host),
                        pid               = VALUES(pid),
                        app_version       = VALUES(app_version),
                        mode              = VALUES(mode),
                        last_seen_at      = VALUES(last_seen_at),
                        current_job_id    = VALUES(current_job_id),
                        jobs_done         = VALUES(jobs_done),
                        last_error_at     = VALUES(last_error_at),
                        last_error_reason = VALUES(last_error_reason)'
                );
                $stmt->execute($row);
                $stmt->closeCursor();
            });
            $this->lastTouchAt   = microtime(true);
            $this->lastFailureAt = 0.0;
            $this->consecutiveFailures = 0;
            $this->lastWriteError = null;
            return true;
        } catch (Throwable $e) {
            $this->recordWriteFailure($e, 'worker.presence.write_failed', $workerId);
            return false;
        }
    }

    /**
     * Throttled, fail-soft {@see touch()} — the entry point the worker
     * poll loop calls. Skips the write when the previous beat is younger
     * than {@see MIN_BEAT_INTERVAL_SECONDS} (unless `$force`), when the
     * presence table is missing, and while a failure backoff is pending.
     *
     * @param array<string,mixed> $state Same shape as {@see touch()}.
     */
    public function beat(string $workerId, array $state = [], bool $force = false): bool
    {
        $now = microtime(true);
        if (!$force) {
            if (($now - $this->lastTouchAt) < self::MIN_BEAT_INTERVAL_SECONDS) {
                return false;
            }
            if ($this->lastFailureAt > 0.0 && ($now - $this->lastFailureAt) < self::FAILURE_BACKOFF_SECONDS) {
                return false;
            }
        }
        return $this->touch($workerId, $state);
    }

    /**
     * Delete the worker's own row. Called on graceful shutdown so a
     * clean stop is distinguishable from a crash: a clean stop leaves
     * no row, a crash leaves a stale one.
     */
    public function forget(string $workerId): bool
    {
        $workerId = self::normalizeWorkerId($workerId);
        if ($workerId === '') {
            return false;
        }
        if ($this->db === null) {
            unset($this->memory[$workerId]);
            return true;
        }
        if (!$this->tableAvailable()) {
            return false;
        }
        try {
            $this->db->run(static function (PDO $pdo) use ($workerId): void {
                $stmt = $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE worker_id = :worker_id');
                $stmt->execute(['worker_id' => $workerId]);
                $stmt->closeCursor();
            });
            $this->lastTouchAt        = microtime(true);
            $this->lastFailureAt      = 0.0;
            $this->consecutiveFailures = 0;
            $this->lastWriteError     = null;
            return true;
        } catch (Throwable $e) {
            $this->recordWriteFailure($e, 'worker.presence.forget_failed', $workerId);
            return false;
        }
    }

    /**
     * Every presence row, newest heartbeat first. Used by the worker
     * health snapshot and the Settings → Worker / Debug panels.
     *
     * @return list<array<string,mixed>>
     */
    public function all(int $limit = self::DEFAULT_LIST_LIMIT): array
    {
        $limit = max(1, min(500, $limit));
        if ($this->db === null) {
            $rows = array_values($this->memory);
            usort($rows, static fn(array $a, array $b): int => strcmp((string)$b['last_seen_at'], (string)$a['last_seen_at']));
            $rows = array_slice($rows, 0, $limit);
            // Same normalization as the DB path so callers cannot tell
            // which backend produced the rows.
            return array_map(self::castRow(...), $rows);
        }
        if (!$this->tableAvailable()) {
            return [];
        }
        try {
            $rows = $this->db->run(static function (PDO $pdo) use ($limit): array {
                $stmt = $pdo->prepare(
                    'SELECT worker_id, host, pid, app_version, mode,
                            started_at, last_seen_at, current_job_id, jobs_done,
                            last_error_at, last_error_reason
                     FROM ' . self::TABLE . '
                     ORDER BY last_seen_at DESC
                     LIMIT :lim'
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
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::castRow($row);
            }
        }
        return $out;
    }

    /**
     * Drop presence rows whose heartbeat is older than the threshold.
     * The worker calls this once on start so rows left behind by
     * SIGKILLed workers cannot accumulate forever. Returns the number
     * of rows removed.
     */
    public function purgeStale(int $olderThanSeconds): int
    {
        if ($olderThanSeconds < 1) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', time() - $olderThanSeconds);
        if ($this->db === null) {
            $removed = 0;
            foreach ($this->memory as $id => $row) {
                if (strcmp((string)$row['last_seen_at'], $cutoff) < 0) {
                    unset($this->memory[$id]);
                    $removed++;
                }
            }
            return $removed;
        }
        if (!$this->tableAvailable()) {
            return 0;
        }
        try {
            return $this->db->run(static function (PDO $pdo) use ($cutoff): int {
                $stmt = $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE last_seen_at < :cutoff');
                $stmt->execute(['cutoff' => $cutoff]);
                $removed = $stmt->rowCount();
                $stmt->closeCursor();
                return max(0, $removed);
            });
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Build the fully-populated column map for one write. Missing keys
     * fall back to NULL / 0 so the prepared statement always binds the
     * same parameter set.
     *
     * @param  array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function buildRow(string $workerId, array $state): array
    {
        $now = date('Y-m-d H:i:s');
        $startedAt = self::timestampOrNull($state['started_at'] ?? null) ?? $now;
        return [
            'worker_id'         => $workerId,
            'host'              => self::shortString($state['host'] ?? null, 190),
            'pid'               => self::positiveIntOrNull($state['pid'] ?? null),
            'app_version'       => self::shortString($state['app_version'] ?? null, 32),
            'mode'              => self::modeOrNull($state['mode'] ?? null),
            'started_at'        => $startedAt,
            'last_seen_at'      => $now,
            'current_job_id'    => self::positiveIntOrNull($state['current_job_id'] ?? null),
            'jobs_done'         => max(0, (int)($state['jobs_done'] ?? 0)),
            'last_error_at'     => self::timestampOrNull($state['last_error_at'] ?? null),
            'last_error_reason' => self::shortString($state['last_error_reason'] ?? null, 190),
        ];
    }

    /**
     * Normalize a row coming out of PDO: numeric columns are cast so the
     * JSON envelope the Settings UI consumes is type-stable across
     * MySQL versions (which return strings for INT columns).
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castRow(array $row): array
    {
        return [
            'worker_id'         => (string)($row['worker_id'] ?? ''),
            'host'              => self::nullableString($row['host'] ?? null),
            'pid'               => self::positiveIntOrNull($row['pid'] ?? null),
            'app_version'       => self::nullableString($row['app_version'] ?? null),
            'mode'              => self::nullableString($row['mode'] ?? null),
            'started_at'        => (string)($row['started_at'] ?? ''),
            'last_seen_at'      => (string)($row['last_seen_at'] ?? ''),
            'current_job_id'    => self::positiveIntOrNull($row['current_job_id'] ?? null),
            'jobs_done'         => max(0, (int)($row['jobs_done'] ?? 0)),
            'last_error_at'     => self::nullableString($row['last_error_at'] ?? null),
            'last_error_reason' => self::nullableString($row['last_error_reason'] ?? null),
        ];
    }

    /**
     * `pid` is INT UNSIGNED in the schema — no process can have pid 0,
     * and a negative value must never reach the driver as unsigned.
     */
    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private static function modeOrNull(mixed $value): ?string
    {
        $mode = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($mode, ['loop', 'once'], true) ? $mode : null;
    }

    /**
     * Accept only a MySQL DATETIME shape. Anything else (garbage from a
     * hostile caller, a full ISO-8601 stamp) becomes NULL rather than a
     * driver error.
     */
    private static function timestampOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }
        return $value;
    }

    private static function shortString(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $string = trim((string)$value);
        if ($string === '') {
            return null;
        }
        if (mb_strlen($string) > $max) {
            $string = mb_substr($string, 0, $max);
        }
        return $string;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string)$value;
        return $string === '' ? null : $string;
    }

    private static function normalizeWorkerId(string $workerId): string
    {
        $workerId = trim($workerId);
        if ($workerId === '' || mb_strlen($workerId) > self::MAX_WORKER_ID_LEN) {
            return '';
        }
        return $workerId;
    }
}
