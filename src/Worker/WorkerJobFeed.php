<?php
/**
 * WorkerJobFeed: secret-free view of the durable job queue for the
 * header's worker-status indicator (the "worker is busy" tooltip).
 *
 * Serves the public GET /api/health/worker/jobs endpoint. It answers
 * exactly one narrow question — "what is the worker doing right now, and
 * how long has each job been waiting / running?" — with identifiers,
 * statuses and timings ONLY:
 *
 *   * no payload content (never the query text),
 *   * no session titles / prompts,
 *   * no user ids or names,
 *   * no result or error envelopes.
 *
 * Selection contract (in this order, capped at {@see MAX_LIMIT} rows):
 *   1. every unfinished job — queued / running / paused / pause_requested
 *      / resume_requested / cancel_requested / consensus_requested /
 *      stale — newest first;
 *   2. then jobs that reached a terminal status inside the recent window
 *      ({@see DEFAULT_RECENT_WINDOW_SECONDS} by default), newest first.
 *
 * Timing semantics (integer seconds, never negative, null when the
 * underlying stamp is missing or unparsable):
 *   * `wait_s`  — `queued_at` → `started_at`. While a job has not been
 *                 picked up yet this is the time it has been waiting so
 *                 far, which is what the tooltip renders as "waiting…".
 *   * `run_s`   — `started_at` → `finished_at`. For a job that is still
 *                 running this is the elapsed time so far.
 *   * `total_s` — `queued_at` → `finished_at`, or → now while the job is
 *                 unfinished.
 *
 * `stage` is the label of the step the session is currently running
 * (read through {@see StepRepository::listRunningSteps()}), or null when
 * no step is in flight / no step repository was wired. It is best-effort:
 * the queue listing must render even when the step table is missing.
 *
 * The class is deliberately fail-soft — a missing `revoap_jobs` table, a
 * dead connection or a schema without the v1.8.39 recovery columns all
 * degrade to a shorter (or empty) list instead of a 500, and the
 * memory-mode mirror used by the offline tests is fully supported.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Worker;

use App\Core\Database;
use App\Core\Logger;
use App\Jobs\Job;
use App\Jobs\JobRepository;
use App\Storage\StepRepository;
use PDO;
use Throwable;

final class WorkerJobFeed
{
    /** Rows returned by default, and the hard ceiling — the endpoint is a tooltip feed. */
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT     = 10;

    /** How far back a terminal job is still considered "recently finished". */
    public const DEFAULT_RECENT_WINDOW_SECONDS = 1800;

    /** Clamps for `?window=` so a hostile query string cannot force a full scan. */
    private const MIN_RECENT_WINDOW_SECONDS = 60;
    private const MAX_RECENT_WINDOW_SECONDS = 86400;

    /** Rows inspected by the fallback path (no DB / unusable query). */
    private const FALLBACK_SCAN_LIMIT = 500;

    public function __construct(
        private readonly JobRepository   $jobs,
        private readonly ?StepRepository $steps = null,
        private readonly ?Database       $db = null,
    ) {}

    /**
     * Build the whole response envelope.
     *
     * @param  ?int $limit         Row cap (clamped to [1, MAX_LIMIT]).
     * @param  ?int $windowSeconds Recently-finished window (clamped).
     * @return array<string,mixed>
     */
    public function feed(?int $limit = null, ?int $windowSeconds = null): array
    {
        $limit  = self::clampLimit($limit);
        $window = self::clampWindow($windowSeconds);
        $now    = time();

        $available = false;
        try {
            $available = $this->jobs->tableAvailable();
        } catch (Throwable) {
            $available = false;
        }

        $selected = $available
            ? $this->selectJobs($limit, $window)
            : ['jobs' => [], 'truncated' => false];

        $unfinished     = 0;
        $recentFinished = 0;
        $stageMemo      = [];

        $out = [];
        foreach ($selected['jobs'] as $job) {
            if ($job->status->isTerminal()) {
                $recentFinished++;
            } else {
                $unfinished++;
            }
            $out[] = $this->present($job, $now, $stageMemo);
        }

        return [
            'ok'                    => true,
            'generated_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'jobs_table_available'  => $available,
            'limit'                 => $limit,
            'recent_window_s'       => $window,
            'unfinished_returned'   => $unfinished,
            'recent_finished_returned' => $recentFinished,
            // True when more rows qualified than the cap allowed, i.e.
            // the tooltip is showing a prefix of the backlog rather than
            // all of it. The UI pairs this with `counts`/`oldest_queued_age_s`
            // from GET /api/health/worker to render "showing N of M".
            'truncated'             => $selected['truncated'],
            'jobs'                  => $out,
        ];
    }

    /**
     * Pick the job rows for this snapshot.
     *
     * DB path: one index-ordered id query (`unfinished` first, then the
     * newest terminal rows inside the window) followed by at most
     * `$limit` primary-key lookups through the repository, so hydration
     * stays in exactly one place.
     *
     * Fallback (memory mirror, missing table, schema drift): scan the
     * newest rows the repository can list and filter them here.
     *
     * @return array{jobs:list<Job>,truncated:bool}
     */
    private function selectJobs(int $limit, int $windowSeconds): array
    {
        // One row over the cap so truncation is detectable without a
        // second COUNT query.
        $candidates = $this->candidateIds($limit + 1, $windowSeconds);
        if ($candidates !== null) {
            $jobs = [];
            foreach ($candidates as $id) {
                $job = $this->find($id);
                if ($job !== null) {
                    $jobs[] = $job;
                }
            }
            // The query already ordered the ids; re-applying the ordering
            // keeps this path byte-identical to the fallback path.
            return self::orderAndSlice($jobs, $limit, $windowSeconds);
        }

        try {
            $recent = $this->jobs->listRecent(self::FALLBACK_SCAN_LIMIT);
        } catch (Throwable) {
            return ['jobs' => [], 'truncated' => false];
        }
        return self::orderAndSlice($recent, $limit, $windowSeconds);
    }

    /**
     * @return list<int>|null  null when the DB path is unusable
     */
    private function candidateIds(int $limit, int $windowSeconds): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        try {
            $rows = $this->db->run(static function (PDO $pdo) use ($limit, $since): array {
                // The ORDER BY key puts every unfinished row first (0)
                // and breaks ties by recency, so the LIMIT cut — when the
                // backlog is deeper than the feed — never drops a job
                // that is still in flight.
                $stmt = $pdo->prepare(
                    'SELECT id FROM revoap_jobs
                     WHERE status NOT IN ("completed", "failed", "cancelled")
                        OR COALESCE(finished_at, updated_at, created_at) >= :since
                     ORDER BY (status IN ("completed", "failed", "cancelled")) ASC, id DESC
                     LIMIT :lim'
                );
                $stmt->bindValue(':since', $since, PDO::PARAM_STR);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($fetched) ? $fetched : [];
            });
        } catch (Throwable) {
            // Older schema (no updated_at/finished_at), different SQL
            // dialect or a dead connection — fall back to listRecent().
            return null;
        }

        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function find(int $id): ?Job
    {
        try {
            return $this->jobs->find($id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Apply the published ordering (unfinished first, then newest) and
     * the row cap to any candidate list.
     *
     * @param  list<Job> $jobs
     * @return array{jobs:list<Job>,truncated:bool}
     */
    private static function orderAndSlice(array $jobs, int $limit, int $windowSeconds): array
    {
        $now    = time();
        $cutoff = $now - $windowSeconds;
        $keep   = [];
        foreach ($jobs as $job) {
            if ($job->status->isTerminal()) {
                $stamp = self::toUnix(self::firstStamp($job->finishedAt, $job->updatedAt, $job->createdAt));
                if ($stamp === null || $stamp < $cutoff) {
                    continue;
                }
            }
            $keep[] = $job;
        }
        usort($keep, static function (Job $a, Job $b): int {
            $ta = $a->status->isTerminal() ? 1 : 0;
            $tb = $b->status->isTerminal() ? 1 : 0;
            return $ta === $tb ? $b->id <=> $a->id : $ta <=> $tb;
        });
        return [
            'jobs'      => array_slice($keep, 0, $limit),
            'truncated' => count($keep) > $limit,
        ];
    }

    /**
     * Project one job onto the public, secret-free row shape.
     *
     * @param  array<int,?string> $stageMemo Session-id keyed stage cache.
     * @return array<string,mixed>
     */
    private function present(Job $job, int $now, array &$stageMemo): array
    {
        $queuedAt   = self::nullableStamp($job->createdAt);
        $startedAt  = self::nullableStamp($job->startedAt);
        $finishedAt = self::nullableStamp($job->finishedAt);
        $terminal   = $job->status->isTerminal();

        $queuedUnix   = $queuedAt === null ? null : self::toUnix($queuedAt);
        $startedUnix  = $startedAt === null ? null : self::toUnix($startedAt);
        $finishedUnix = $finishedAt === null ? null : self::toUnix($finishedAt);

        return [
            'job_id'       => $job->id,
            'session_id'   => $job->sessionId,
            'status'       => $job->status->value,
            'priority'     => $job->priority,
            'attempts'     => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            // A lock left behind on a terminal row is history, not a
            // holder — only in-flight rows name their worker.
            'worker_id'    => $terminal ? null : self::nullableStamp($job->lockedBy),
            'queued_at'    => $queuedAt,
            'started_at'   => $startedAt,
            'finished_at'  => $finishedAt,
            'wait_s'       => self::waitSeconds($queuedUnix, $startedUnix, $terminal, $now),
            'run_s'        => self::runSeconds($startedUnix, $finishedUnix, $terminal, $now),
            'total_s'      => self::totalSeconds($queuedUnix, $finishedUnix, $now),
            'stage'        => $terminal ? null : $this->stage($job, $stageMemo),
            'next_retry_at' => self::nullableStamp($job->nextRunAt),
        ];
    }

    /**
     * Label of the step the job's session is currently running. Only
     * meaningful for an unfinished job, and only when a step repository
     * was wired; every failure path returns null so the queue listing
     * never depends on `council_steps`.
     *
     * @param array<int,?string> $memo
     */
    private function stage(Job $job, array &$memo): ?string
    {
        if ($this->steps === null || $job->sessionId <= 0) {
            return null;
        }
        if (!array_key_exists($job->sessionId, $memo)) {
            $label = null;
            try {
                $running = $this->steps->listRunningSteps($job->sessionId);
                foreach ($running as $step) {
                    $candidate = trim((string)($step['label'] ?? ''));
                    if ($candidate !== '') {
                        $label = $candidate;
                        break;
                    }
                }
            } catch (Throwable) {
                $label = null;
            }
            $memo[$job->sessionId] = $label === null ? null : Logger::sanitizeScalar($label);
        }
        return $memo[$job->sessionId];
    }

    /**
     * `started_at` → `finished_at`; while unfinished it is the elapsed
     * run time so far. Null for a job that never started.
     */
    private static function runSeconds(?int $started, ?int $finished, bool $terminal, int $now): ?int
    {
        if ($started === null) {
            return null;
        }
        if ($finished !== null) {
            return max(0, $finished - $started);
        }
        return $terminal ? null : max(0, $now - $started);
    }

    /**
     * `queued_at` → `started_at`; while the job has not been picked up
     * yet it is the waiting time so far.
     */
    private static function waitSeconds(?int $queued, ?int $started, bool $terminal, int $now): ?int
    {
        if ($queued === null) {
            return null;
        }
        if ($started !== null) {
            return max(0, $started - $queued);
        }
        return $terminal ? null : max(0, $now - $queued);
    }

    /**
     * `queued_at` → `finished_at`, or → now while the job is unfinished.
     */
    private static function totalSeconds(?int $queued, ?int $finished, int $now): ?int
    {
        if ($queued === null) {
            return null;
        }
        return max(0, ($finished ?? $now) - $queued);
    }

    private static function clampLimit(?int $limit): int
    {
        if ($limit === null || $limit <= 0) {
            return self::DEFAULT_LIMIT;
        }
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    private static function clampWindow(?int $window): int
    {
        if ($window === null || $window <= 0) {
            return self::DEFAULT_RECENT_WINDOW_SECONDS;
        }
        return max(self::MIN_RECENT_WINDOW_SECONDS, min(self::MAX_RECENT_WINDOW_SECONDS, $window));
    }

    private static function firstStamp(?string ...$stamps): ?string
    {
        foreach ($stamps as $stamp) {
            $value = self::nullableStamp($stamp);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private static function nullableStamp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Translate a SQL timestamp into a Unix integer. Same deliberate
     * simplification as {@see \App\Jobs\WorkerHealthService}: strtotime()
     * with the server's default TZ, which only has to be self-consistent
     * within one snapshot.
     */
    private static function toUnix(?string $ts): ?int
    {
        if ($ts === null) {
            return null;
        }
        $t = strtotime($ts);
        return is_int($t) && $t > 0 ? $t : null;
    }
}
