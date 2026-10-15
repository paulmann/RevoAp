<?php
/**
 * WorkerHealthService: diagnostics for the durable job worker.
 *
 * Produces a sanitized snapshot of the `revoap_jobs` queue plus a
 * derived `worker_status` classification, distinct active workers, and
 * a set of actionable recommendations / safe CLI commands. The service
 * is intentionally read-only and survives a missing schema — when the
 * `revoap_jobs` table has not been migrated yet it returns a
 * `migration_missing` envelope instead of raising a 500.
 *
 * v1.8.39 — surfaces the auto-recovery retry policy plus aggregate
 * counters (`retry_scheduled`, `retry_exhausted`, `next_retry_at`) so
 * the Settings UI can show how many crashed jobs are waiting for their
 * next attempt and how many have exhausted the retry budget.
 *
 * v1.8.44 — merges the `revoap_worker_presence` beacon into every
 * worker row and the classification. Before that table existed the only
 * worker trace came from `revoap_jobs.locked_by`/`heartbeat_at`, so an
 * alive-but-idle worker was indistinguishable from a dead one and the
 * UI rendered "Waiting for worker" while the process was healthy.
 * `running_workers[]` therefore gains `started_at` / `uptime_s` /
 * `app_version` / `mode` / `pid` / `host` / `current_job_id` /
 * `jobs_done` / `source`, and the snapshot gains `workers_alive`,
 * `oldest_worker_uptime_s`, `worker_versions`, `idle`,
 * `worker_presence`, `presence_table_available` and `app_version`.
 * Every pre-existing key keeps its name, type and meaning — the new
 * fields are strictly additive so existing consumers (and the CLI
 * `--status` envelope) keep working unchanged.
 *
 * v1.8.51 — `code_state` / `code_stale_reason` / `code_newest_at` /
 * `code_newest_file`: whether the running worker process is executing the
 * code that is on disk. A long-lived process keeps the classes it loaded
 * at start-up, so "new caller, old callee" is possible with no syntax
 * error anywhere; it used to announce itself only as `Call to undefined
 * method …`. The verdict comes from the presence beacon's `app_version`
 * versus `app.version` and from the process start versus the newest mtime
 * under `src/` (plus `index.php` / `config.php`), and is cached for
 * {@see self::CODE_SCAN_TTL_SECONDS} so the 5 s panel poll does not walk
 * the tree on every refresh.
 *
 * Never exposes secrets: only structural counts, timestamps, worker
 * identifiers (already public via `locked_by`), host/pid/version of the
 * worker process, and the literal CLI commands a sysop would
 * copy/paste. No payload content, no result envelopes, no API keys.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.2.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Config;
use App\Core\Database;
use App\Core\DbResilienceJournal;
use App\Core\ErrorDiagnostics;
use App\Core\Logger;
use PDO;
use Throwable;

final class WorkerHealthService
{
    /**
     * Default heartbeat threshold (seconds). A running job whose
     * `heartbeat_at` is older than this is considered stale; the
     * worker's own `--stale-after` flag defaults to the same value.
     */
    public const DEFAULT_STALE_THRESHOLD = 300;

    /**
     * v1.8.51 — how long one code scan is reused inside this process.
     *
     * The Worker card polls every 5s and every snapshot wants the newest
     * mtime under `src/`; walking the tree on each poll would be pure
     * waste for a fact that changes at most once per deployment. Ten
     * seconds keeps the answer fresh to within one poll yet reduces the
     * scan to at most one per two panel refreshes.
     */
    public const CODE_SCAN_TTL_SECONDS = 10;

    /** Relative files/dirs whose mtime describes the deployed code. */
    private const CODE_PATHS = ['src', 'index.php', 'config.php'];

    /**
     * Cached result of {@see self::scanCodeMtimes()}, keyed by root:
     * a `stale` verdict must not cost a directory walk per poll.
     *
     * @var array<string,array{at:int,newest_mtime:int,newest_file:?string,scanned:int,seconds:float}>
     */
    private static array $codeScanCache = [];

    /**
     * The full list of statuses returned in `counts`, even when zero,
     * so the UI can render a stable table without conditional cells.
     *
     * @var list<string>
     */
    private const REPORTED_STATUSES = [
        'queued',
        'running',
        'paused',
        'pause_requested',
        'resume_requested',
        'cancel_requested',
        'consensus_requested',
        'cancelled',
        'completed',
        'failed',
        'stale',
    ];

    /**
     * v1.8.44 — durable liveness beacon. Provides uptime / version /
     * "what is it doing right now" for workers that hold no job row.
     */
    private readonly WorkerPresenceRepository $presence;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ?Database     $db = null,
        ?WorkerPresenceRepository      $presence = null,
    ) {
        // Fall back to whatever handle the job repository already holds
        // so a caller that only passes the repository still gets the
        // real presence table (index.php passes `$db`, the worker passes
        // `$db` too — this covers the `$pdo`-only and test paths).
        $this->presence = $presence ?? new WorkerPresenceRepository($db ?? $this->jobs->database());
    }

    /**
     * Build the full health snapshot.
     *
     * @return array<string,mixed>
     */
    public function snapshot(?int $staleThresholdSeconds = null): array
    {
        $threshold = self::clampThreshold($staleThresholdSeconds);
        $mode      = Config::pipelineExecutionMode();
        $now       = time();

        $base = [
            'execution_mode'        => $mode,
            'jobs_table_available'  => false,
            'migration_applied'     => false,
            'stale_threshold_sec'   => $threshold,
            'counts'                => self::zeroCounts(),
            'total_non_terminal'    => 0,
            'oldest_queued_at'      => null,
            'oldest_queued_age_s'   => null,
            'latest_heartbeat_at'   => null,
            'latest_heartbeat_age_s' => null,
            // v1.8.48 — strictly additive: which signal `latest_heartbeat_*`
            // describes, plus each component on its own so the two can never
            // silently disagree in the UI again.
            'latest_heartbeat_source' => null,
            'latest_presence_at'      => null,
            'latest_presence_age_s'   => null,
            'latest_job_heartbeat_at' => null,
            'latest_job_heartbeat_age_s' => null,
            // v1.8.48 — the last job the queue saw (model/time/outcome).
            'last_job'                => null,
            'running_workers'       => [],
            'stale_jobs'            => 0,
            'worker_status'         => 'unknown',
            'recommendations'       => [],
            'commands'              => self::commands(),
            'retry_policy'          => self::resolveRetryPolicy(),
            'retry_scheduled'       => 0,
            'retry_exhausted'       => 0,
            'next_retry_at'         => null,
            'oldest_retry_age_s'    => null,
            'generated_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            // ---- v1.8.44 presence additions -------------------------
            'app_version'             => self::appVersion(),
            'presence_table_available' => false,
            'workers_alive'           => 0,
            'oldest_worker_uptime_s'  => null,
            'worker_versions'         => [],
            'worker_presence'         => [],
            'idle'                    => false,
            // ---- v1.8.51 deployed-vs-running code --------------------
            // A long-lived worker holds the classes it loaded at start, so
            // "new caller, old callee" (a fresh job runner calling a method
            // the running process does not have) is possible and used to
            // announce itself only through a bizarre failure text. Strictly
            // additive: every pre-existing key keeps its shape.
            'code_state'              => 'unknown',
            'code_stale_reason'       => null,
            'code_newest_at'          => null,
            'code_newest_file'        => null,
        ];

        // ---- v1.8.47 database-resilience additions ------------------
        // Strictly additive: every pre-existing key keeps its shape and
        // its meaning. These keys exist because a worker that survived a
        // MySQL restart used to be indistinguishable from one that had
        // never touched the database — the indicator stayed green while
        // every job failed with 2006.
        $base += $this->databaseResilience();

        // v1.8.44 — presence beacon. Read first so the worker rows below
        // can be enriched, and so the early `migration_missing` return
        // still reports whether the beacon table itself is present.
        $presenceRows = $this->presence->all();
        $base['presence_table_available'] = $this->presence->tableAvailable();
        $base['worker_presence']          = $this->presentPresenceRows($presenceRows, $threshold);

        // v1.8.51 — is the running process executing the code that is on
        // disk? Computed here, from the same normalized rows the card
        // renders, so the warning and the worker table can never disagree.
        $code = self::codeState($base['worker_presence']);
        $base['code_state']        = $code['state'];
        $base['code_stale_reason'] = $code['reason'];
        $base['code_newest_at']    = $code['newest_at'];
        $base['code_newest_file']  = $code['newest_file'];

        $available = false;
        try {
            $available = $this->jobs->tableAvailable();
        } catch (Throwable) {
            $available = false;
        }
        $base['jobs_table_available'] = $available;
        $base['migration_applied']    = $available;

        if (!$available) {
            $base['worker_status']   = 'migration_missing';
            $base['recommendations'] = [
                'Import db/schema.sql (Settings → ReCreate DB, or mysql CLI) so the durable job queue can be created.',
                'Open Settings → Database and click "ReCreate DB" (type RECREATE to confirm) — the `revoap_jobs` table is required for job-mode execution.',
            ];
            return $base;
        }

        // Counts by status. When the DB is missing we fall back to the
        // in-memory mirror via JobRepository::listRecent(), which also
        // means tests can drive this service end-to-end without MySQL.
        $counts = $this->aggregateCounts();
        $base['counts'] = $counts;

        $nonTerminal = 0;
        foreach ($counts as $status => $n) {
            if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                $nonTerminal += $n;
            }
        }
        $base['total_non_terminal'] = $nonTerminal;

        // Oldest queued + latest heartbeat. v1.8.48 — the freshness signal
        // is now the newest liveness evidence from EITHER source; see
        // resolveFreshness() for why the job-only version reported a
        // perfectly healthy idle worker as `degraded`.
        [$oldestQueuedAt, $latestHeartbeat] = $this->aggregateTimestamps();
        $base['oldest_queued_at']       = $oldestQueuedAt;
        $base['oldest_queued_age_s']    = $oldestQueuedAt === null
            ? null
            : max(0, $now - self::toUnix($oldestQueuedAt));

        $freshness = $this->resolveFreshness($latestHeartbeat, $presenceRows, $now);
        $base['latest_heartbeat_at']     = $freshness['at'];
        $base['latest_heartbeat_age_s']  = $freshness['age_s'];
        $base['latest_heartbeat_source'] = $freshness['source'];
        $base['latest_presence_at']      = $freshness['presence_at'];
        $base['latest_presence_age_s']   = $freshness['presence_age_s'];
        $base['latest_job_heartbeat_at'] = $freshness['job_at'];
        $base['latest_job_heartbeat_age_s'] = $freshness['job_age_s'];

        // Active workers — job holders merged with the presence beacon so
        // an idle-but-alive process is visible instead of invisible.
        $base['running_workers'] = $this->mergePresenceWorkers(
            $this->aggregateRunningWorkers($threshold),
            $presenceRows,
            $threshold,
        );
        $base['stale_jobs']      = $counts['stale'] ?? 0;

        // v1.8.44 — derived worker aggregates. `workers_alive` counts the
        // entries the merge above marked alive (fresh heartbeat OR fresh
        // presence OR an unfinished job), so it can never disagree with
        // the list the UI renders.
        $aliveWorkers = array_values(array_filter(
            $base['running_workers'],
            static fn(array $w): bool => ($w['alive'] ?? false) === true,
        ));
        $base['workers_alive'] = count($aliveWorkers);

        $uptimes = [];
        foreach ($aliveWorkers as $worker) {
            $uptime = $worker['uptime_s'] ?? null;
            if (is_int($uptime) && $uptime >= 0) {
                $uptimes[] = $uptime;
            }
        }
        $base['oldest_worker_uptime_s'] = $uptimes === [] ? null : max($uptimes);

        $versions = [];
        foreach ($aliveWorkers as $worker) {
            $version = $worker['app_version'] ?? null;
            if (is_string($version) && $version !== '' && !in_array($version, $versions, true)) {
                $versions[] = $version;
            }
        }
        sort($versions, SORT_STRING);
        $base['worker_versions'] = $versions;

        // `idle` is true only when at least one worker is alive AND the
        // whole queue is quiet — no queued/running/paused/stale rows. This
        // is the state the UI could not express before v1.8.44.
        $base['idle'] = $base['workers_alive'] > 0 && $base['total_non_terminal'] === 0;

        // v1.8.39 — retry policy bookkeeping. Cheap to compute thanks
        // to the new idx_jobs_attempts index.
        try {
            $stats = $this->jobs->recoveryStats();
            $base['retry_scheduled']    = (int)($stats['retry_scheduled']    ?? 0);
            $base['retry_exhausted']    = (int)($stats['retry_exhausted']    ?? 0);
            $base['next_retry_at']      = $stats['next_retry_at']      ?? null;
            $base['oldest_retry_age_s'] = isset($stats['oldest_retry_age_s']) && $stats['oldest_retry_age_s'] !== null
                ? (int)$stats['oldest_retry_age_s'] : null;
        } catch (Throwable) {
            // Keep zeros.
        }

        // Derive the high-level status + recommendations last so they
        // can reason over everything collected above.
        [$status, $recommendations] = self::classify(
            $mode,
            $counts,
            $base['latest_heartbeat_age_s'],
            $base['oldest_queued_age_s'],
            $base['running_workers'],
            $threshold,
            $base['workers_alive'],
            (string)($base['latest_heartbeat_source'] ?? ''),
        );
        $base['worker_status']    = $status;
        $base['recommendations']  = $recommendations;

        // v1.8.39 — surface retry context as a non-blocking advisory
        // alongside the primary classification. These hints help the
        // operator understand why some jobs are not picked up right now.
        if ($base['retry_scheduled'] > 0) {
            $when = $base['next_retry_at'] !== null
                ? ' (next attempt at ' . (string)$base['next_retry_at'] . ' UTC)'
                : '';
            $base['recommendations'][] = $base['retry_scheduled']
                . ' job(s) waiting for auto-recovery retry' . $when . '.';
        }
        if ($base['retry_exhausted'] > 0) {
            $base['recommendations'][] = $base['retry_exhausted']
                . ' job(s) exhausted the retry budget and are in failed status with exhausted_retries=true.';
        }

        // v1.8.48 — the last job, in one block: which models it used, how
        // long it took and whether it succeeded. Computed after the
        // classification so it can be attached unconditionally (it is
        // diagnostics, not part of the verdict).
        $base['last_job'] = $this->lastJob($now);

        return $base;
    }

    /**
     * v1.8.48 — "the last job" for the Worker card.
     *
     * Everything here is already public elsewhere (the job feeds serve the
     * same rows, and `payload.selected_models` is what the operator typed
     * into the Models tab), but the three facts an operator actually wants
     * after a run — which models, how long, and did it work — were spread
     * across three different panes.
     *
     * Selection rule: the newest job, preferring a real pipeline run over a
     * self-test so clicking "Test" in Settings does not erase the record of
     * the last real query (`kind` says which one was reported). The newest
     * rows only — a 50-row scan to find the last pipeline run would be
     * wasted work on every 5 s poll.
     *
     * `outcome` is the field the UI colours: a job whose pipeline finished
     * but whose L4 synthesis failed (see PipelineEngine::$lastSynthesisFailure)
     * is reported as `error`, because from the operator's chair that run did
     * not produce an answer. `status` keeps the raw queue status so nothing
     * is hidden.
     *
     * @return array<string,mixed>|null null when the queue has no job at all.
     */
    private function lastJob(int $now): ?array
    {
        try {
            $rows = $this->jobs->listRecent(20);
        } catch (Throwable) {
            return null;
        }

        $pipeline = null;
        $any      = null;
        foreach ($rows as $job) {
            if (!$job instanceof Job) {
                continue;
            }
            $any ??= $job;
            if (($job->payload['kind'] ?? '') === WorkerSelfTest::KIND) {
                continue;
            }
            $pipeline = $job;
            break;
        }
        $job = $pipeline ?? $any;
        if (!$job instanceof Job) {
            return null;
        }

        $status   = $job->status->value;
        $terminal = in_array($status, ['completed', 'failed', 'cancelled'], true);
        $started  = $job->startedAt ?? $job->createdAt;
        $finished = $job->finishedAt;

        // Duration: the real span for a finished job, the elapsed time for
        // one that is still going. `duration_state` is what lets the card
        // say "running for 42s" instead of presenting a live number as if
        // it were final.
        $durationS = null;
        if ($started !== null && $started !== '') {
            $from = self::toUnix($started);
            if ($from > 0) {
                $end = $finished !== null && $finished !== ''
                    ? self::toUnix($finished)
                    : ($terminal ? self::toUnix((string)($job->updatedAt ?? '')) : $now);
                $durationS = max(0, ($end > 0 ? $end : $now) - $from);
            }
        }

        $models = $this->jobModels($job);

        // Two ways a job can carry a problem, in priority order: the job
        // itself failed (error_json), or the job ran but the pipeline inside
        // it did not produce an answer (result_json). Both are real failures
        // for the operator; the second one is the case this release is about.
        $error        = null;
        $providerCode = null;
        $synthesisFailed = false;
        if (is_array($job->error) && trim((string)($job->error['message'] ?? '')) !== '') {
            $error = Logger::sanitizeScalar((string)$job->error['message']);
        }
        // v1.8.60 — the run's own terminal verdict, as the engine recorded
        // it in the result envelope. `synthesis_failure` alone was too
        // narrow: a run that resolved an empty pool, or whose every L1 call
        // failed, reported `success` here while History showed the very same
        // session as Error.
        $pipelineFailed = is_array($job->result)
            && strtolower(trim((string)($job->result['status'] ?? ''))) === 'failed';
        $pipelineReason = is_array($job->result)
            ? trim((string)($job->result['status_reason'] ?? ''))
            : '';
        $failure = is_array($job->result) && is_array($job->result['synthesis_failure'] ?? null)
            ? $job->result['synthesis_failure']
            : null;
        if ($failure !== null) {
            $synthesisFailed = true;
            // The engine already parsed the provider's own code out of the
            // body; only fall back to parsing the composed sentence below
            // when it did not (a legacy row), because a sentence is not a
            // body and the parser would rightly ignore it.
            $code = trim((string)($failure['provider_code'] ?? ''));
            if ($code !== '') { $providerCode = $code; }
            $parts = [];
            $model = trim((string)($failure['model'] ?? ''));
            if ($model !== '') { $parts[] = 'model "' . $model . '"'; }
            $msg = trim((string)($failure['message'] ?? ''));
            $error ??= Logger::sanitizeScalar('Final synthesis failed'
                . ($parts !== [] ? ' for ' . implode(', ', $parts) : '')
                . ($msg !== '' ? ': ' . $msg : '.'));
        }
        if ($error === null && $pipelineFailed) {
            // No synthesis message to quote (the run died earlier than L4):
            // say what the engine decided, in the engine's own words.
            $detail = is_array($job->result)
                ? trim((string)($job->result['status_detail'] ?? ''))
                : '';
            $error = Logger::sanitizeScalar(
                'The run ended without an answer'
                . ($pipelineReason !== '' ? ' (' . $pipelineReason . ')' : '')
                . ($detail !== '' ? ': ' . $detail : '.')
            );
        }
        if ($error !== null && $providerCode === null) {
            $providerCode = ErrorDiagnostics::providerErrorCodeFromMessage($error);
        }

        $outcome = 'success';
        if (!$terminal) {
            $outcome = $status === 'queued' ? 'queued' : 'running';
        } elseif ($status !== 'completed') {
            $outcome = 'error';
        } elseif ($synthesisFailed) {
            $outcome = 'error';
        } elseif ($pipelineFailed) {
            // v1.8.60 — the run's OWN terminal verdict is `failed`, for a
            // reason other than a synthesis fallback (an empty pool that
            // resolved to no callable model, every L1 call failing, an L4
            // step recorded failed). The job row still says `completed`
            // because the job *did* run — but the panel must not paint it
            // green while History paints the same session red.
            $outcome = 'error';
        }

        return [
            'job_id'          => $job->id,
            'session_id'      => $job->sessionId,
            'status'          => $status,
            'outcome'         => $outcome,
            'kind'            => $pipeline === $job ? 'pipeline' : 'other',
            'started_at'      => $started !== '' ? $started : null,
            'finished_at'     => $finished !== null && $finished !== '' ? $finished : null,
            'duration_s'      => $durationS,
            'duration_state'  => $terminal ? 'final' : 'running',
            'models'          => $models,
            'error'           => $error,
            'provider_code'   => $providerCode,
            'synthesis_failed'=> $synthesisFailed,
            'attempts'        => $job->attempts,
            'max_attempts'    => $job->maxAttempts,
            'locked_by'       => $job->lockedBy,
        ];
    }

    /**
     * The models that took part in a job, as `[{provider, model}]`.
     *
     * Source order: the job payload's `selected_models` (what the request
     * asked for, and the only source that exists before the run starts),
     * then the session's own `options_json.selected_models` when the
     * payload predates that field. Nothing is invented: when neither is
     * available the list is empty and the card says so, rather than naming
     * the configured default pool and implying it was used.
     *
     * Every field is coerced to a bounded string, so a hostile row cannot
     * smuggle markup or a 1 MB model id into the panel.
     *
     * @return list<array{provider:string,model:string}>
     */
    private function jobModels(Job $job): array
    {
        $raw = $job->payload['selected_models'] ?? null;
        if (!is_array($raw) || $raw === []) {
            $raw = $this->sessionSelectedModels($job->sessionId);
        }
        $out = [];
        foreach ($raw as $entry) {
            $provider = '';
            $model    = '';
            if (is_array($entry)) {
                $provider = (string)($entry['provider'] ?? '');
                $model    = (string)($entry['model'] ?? '');
            } elseif (is_string($entry)) {
                $model = $entry;
            }
            $provider = strtolower(trim(preg_replace('/[^\x20-\x7E]/', '', $provider) ?? ''));
            $model    = trim(preg_replace('/[^\x20-\x7E]/', '', $model) ?? '');
            if ($model === '') {
                continue;
            }
            $row = ['provider' => mb_substr($provider, 0, 60), 'model' => mb_substr($model, 0, 160)];
            if (in_array($row, $out, true)) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    /**
     * `council_sessions.options_json.selected_models` for one session.
     * Read-only, fail-soft, and never returns inside a thrown path: the
     * panel is diagnostics and must still render when the table is missing.
     *
     * @return list<array<string,mixed>>
     */
    private function sessionSelectedModels(int $sessionId): array
    {
        if ($sessionId <= 0 || $this->db === null) {
            return [];
        }
        try {
            $json = $this->db->run(static function (PDO $pdo) use ($sessionId): ?string {
                try {
                    $stmt = $pdo->prepare('SELECT options_json FROM council_sessions WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $sessionId]);
                    $value = $stmt->fetchColumn();
                    $stmt->closeCursor();
                } catch (Throwable) {
                    // Pre-004 deployments have no options_json column.
                    return null;
                }
                return is_string($value) && $value !== '' ? $value : null;
            });
        } catch (Throwable) {
            return [];
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        $models  = is_array($decoded) ? ($decoded['selected_models'] ?? null) : null;
        return is_array($models) ? $models : [];
    }

    /**
     * Static list of safe CLI / systemd commands to copy/paste. No
     * secrets, no host-specific paths beyond the repo-relative one a
     * sysop is already expected to know.
     *
     * @return array<string,string>
     */
    public static function commands(): array
    {
        return [
            'once'             => 'php bin/revoap-worker.php --once',
            'loop'             => 'php bin/revoap-worker.php --loop --sleep=1 --stale-after=300',
            'status'           => 'php bin/revoap-worker.php --status',
            'systemd_status'   => 'sudo systemctl status revoap-worker.service',
            'systemd_start'    => 'sudo systemctl start revoap-worker.service',
            'systemd_restart'  => 'sudo systemctl restart revoap-worker.service',
            'systemd_enable'   => 'sudo systemctl enable --now revoap-worker.service',
            'journal_tail'     => 'sudo journalctl -u revoap-worker.service -f -o cat',
        ];
    }

    /**
     * Classify the worker into a coarse status bucket and emit
     * targeted recommendations the UI can render verbatim.
     *
     * v1.8.44 — `$workersAlive` (the count of living worker processes,
     * presence beacon included) makes the "the worker is running but has
     * nothing to do" state expressible. Without it a queued job plus an
     * idle-but-alive worker whose heartbeat had not been written yet was
     * reported as `not_running`. The parameter is optional and defaults
     * to 0, which reproduces the pre-v1.8.44 classification exactly for
     * any external caller that passes the original six arguments.
     *
     * v1.8.48 — `$heartbeatSource` only *words* the stale verdict
     * ('presence' | 'job' | ''); it changes no branch. The verdict itself
     * was already fixed upstream, in {@see self::resolveFreshness()},
     * which is what now hands this method an age derived from the presence
     * beacon rather than from the last job. `degraded`/`stale` therefore
     * means "the freshest liveness signal this deployment has is older than
     * the threshold" — nothing more, and nothing that a stale *job* can
     * trigger while a worker is beaconing.
     *
     * @param  array<string,int> $counts
     * @param  list<array<string,mixed>> $workers
     * @return array{0:string,1:list<string>}
     */
    public static function classify(
        string $mode,
        array  $counts,
        ?int   $latestHeartbeatAgeS,
        ?int   $oldestQueuedAgeS,
        array  $workers,
        int    $threshold,
        int    $workersAlive = 0,
        string $heartbeatSource = '',
    ): array {
        $running    = $counts['running'] ?? 0;
        $queued     = $counts['queued']  ?? 0;
        $stale      = $counts['stale']   ?? 0;
        $hbAge      = $latestHeartbeatAgeS;
        $queueAge   = $oldestQueuedAgeS;
        $alive      = max(0, $workersAlive);

        $recs = [];

        if ($mode === 'legacy') {
            $recs[] = 'Pipeline is in legacy mode — set `pipeline.execution_mode = job` in .config.php or runtime.json to use the durable worker.';
        }

        // Hard "queued for a long time but no heartbeat at all" check.
        // Only valid when NO worker process is alive: with the presence
        // beacon a living worker that has not touched the queue yet
        // reports no job heartbeat either, and that is not "not running".
        if ($queued > 0 && $hbAge === null && $alive === 0) {
            $recs[] = 'Jobs are queued but no worker heartbeat is recorded. Start the worker:';
            $recs[] = '  sudo systemctl start revoap-worker.service';
            $recs[] = '  (or for foreground: php bin/revoap-worker.php --loop --sleep=1)';
            return ['not_running', $recs];
        }

        // Heartbeat exists but is stale relative to the threshold. Since
        // v1.8.48 this age is the freshest of {presence beacon, job
        // heartbeat} — see resolveFreshness() — so reaching here means
        // nothing has been heard from the worker on EITHER channel, not
        // that the queue happens to have been empty for a while.
        if ($hbAge !== null && $hbAge > $threshold) {
            $signal = $heartbeatSource === 'presence'
                ? 'Last worker presence beacon'
                : ($heartbeatSource === 'job' ? 'Last worker heartbeat' : 'Last worker heartbeat');
            $recs[] = "{$signal} is {$hbAge}s old — exceeds the stale threshold of {$threshold}s.";
            if ($alive > 0) {
                $recs[] = "{$alive} worker process(es) are alive but none reported a heartbeat within the threshold — "
                    . 'check the journal for a stuck pipeline stage.';
            }
            $recs[] = 'Check the worker process and restart if needed:';
            $recs[] = '  sudo systemctl restart revoap-worker.service';
            $recs[] = '  sudo journalctl -u revoap-worker.service -f -o cat';
            return ['stale', $recs];
        }

        if ($stale > 0) {
            $recs[] = "There are {$stale} stale jobs (heartbeat lapsed). The worker reclaims them automatically; investigate the journal if this recurs.";
        }

        // Old queued items but no running workers AND no live process.
        if ($queued > 0 && $running === 0 && empty($workers) && $alive === 0) {
            $age = $queueAge !== null ? "{$queueAge}s" : '?';
            $recs[] = "There are {$queued} queued jobs (oldest age {$age}) but no running workers. Start the worker:";
            $recs[] = '  sudo systemctl start revoap-worker.service';
            return ['not_running', $recs];
        }

        // Work is in flight — unambiguously healthy regardless of how the
        // worker was discovered.
        if ($running > 0) {
            $recs[] = 'Worker is processing jobs.';
            return ['healthy', $recs];
        }

        // Queued work plus a live worker (presence beacon or job row):
        // the job simply has not been acquired yet. Before v1.8.44 this
        // returned `not_running` because only job rows could prove the
        // worker existed.
        if ($queued > 0 && ($alive > 0 || !empty($workers))) {
            $recs[] = "{$queued} jobs queued — worker is alive and picking them up.";
            return ['healthy', $recs];
        }

        // Alive workers, nothing running, nothing queued: the idle state.
        if (!empty($workers)) {
            $idleCount = max($alive, count($workers));
            $recs[] = "Worker is alive and idle — {$idleCount} process(es) polling, no queued or running jobs."
                . ' Nothing to do right now.';
            return ['idle', $recs];
        }

        // No running and no queued — idle.
        if ($hbAge !== null && $hbAge <= $threshold) {
            $recs[] = 'Worker is idle (no queued or running jobs) and the heartbeat is fresh.';
            return ['idle', $recs];
        }

        // Nothing in flight and no heartbeat — never started, but also
        // no work waiting; reasonable for a fresh install.
        $recs[] = 'No jobs have been enqueued yet. Submit a query to verify the worker dispatch path:';
        $recs[] = '  php bin/revoap-worker.php --once';
        return ['idle', $recs];
    }

    /**
     * @return array<string,int>
     */
    private function aggregateCounts(): array
    {
        $base = self::zeroCounts();

        // DB path — single GROUP BY query is enough.
        if ($this->db !== null) {
            try {
                $rows = $this->db->run(static function (PDO $pdo): array {
                    $stmt = $pdo->query('SELECT status, COUNT(*) AS n FROM revoap_jobs GROUP BY status');
                    if ($stmt === false) { return []; }
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    return is_array($rows) ? $rows : [];
                });
                foreach ($rows as $row) {
                    $status = (string)($row['status'] ?? '');
                    if ($status === '') { continue; }
                    $n = (int)($row['n'] ?? 0);
                    if (!array_key_exists($status, $base)) {
                        $base[$status] = 0;
                    }
                    $base[$status] += $n;
                }
                return $base;
            } catch (Throwable) {
                // Fall through to the in-memory mirror.
            }
        }

        // Memory-mode mirror — listRecent() with a high cap is fine.
        try {
            $rows = $this->jobs->listRecent(500);
            foreach ($rows as $job) {
                $key = $job->status->value;
                if (!array_key_exists($key, $base)) {
                    $base[$key] = 0;
                }
                $base[$key]++;
            }
        } catch (Throwable) {
            // Keep zeros.
        }
        return $base;
    }

    /**
     * v1.8.48 — the freshest liveness signal, from either source.
     *
     * The reported bug: an idle-but-healthy worker pool rendered as
     * `degraded` — both workers "1s ago" in the per-process table, while the
     * top-level line read "Last heartbeat: … (6h 06m ago)". The two numbers
     * came from different tables. Per-worker rows are merged with the 1 Hz
     * presence beacon, but `latest_heartbeat_at` was a bare
     * `MAX(revoap_jobs.heartbeat_at)`: the timestamp of the last *job*, not
     * of the last *worker*. On a deployment with no jobs for six hours the
     * beacon was beating every second while the classifier compared a
     * six-hour-old job heartbeat against the 300 s threshold and returned
     * `stale` → the UI's red "degraded" pill. The `Test` button "fixed" it
     * by enqueuing a job, which is the tell: the value described the queue,
     * not the worker.
     *
     * The rule now, in order:
     *   1. Presence beacons, when any row exists — that is what a live
     *      worker writes every second, and it is the only signal that keeps
     *      updating while the queue is empty.
     *   2. The job heartbeat, when there is no presence row at all — either
     *      the table is missing (older deployment) or the worker exits
     *      cleanly and forgets its row. Job-only behaviour is therefore
     *      preserved exactly for those deployments.
     *
     * The two are combined with `max`, not "presence wins outright", because
     * each source legitimately goes quiet while the other keeps ticking: the
     * poll loop is blocked inside the engine during a long pipeline run, so
     * the beacon lapses while the job heartbeat keeps updating; and an idle
     * worker writes beacons with no job row to update. Taking the newest of
     * the two is what makes "nothing has been heard from this worker" mean
     * exactly that, instead of "nothing has been heard from this worker's
     * current job".
     *
     * `source` names the winner so the UI can word the line honestly
     * (a beacon is not a heartbeat), and both component ages are returned
     * so nothing is hidden from a diagnosis.
     *
     * @param  ?string $jobHeartbeatAt MAX(heartbeat_at) over revoap_jobs
     * @param  list<array<string,mixed>> $presenceRows
     * @return array{at:?string,age_s:?int,source:?string,presence_at:?string,
     *               presence_age_s:?int,job_at:?string,job_age_s:?int}
     */
    private function resolveFreshness(?string $jobHeartbeatAt, array $presenceRows, int $now): array
    {
        $jobAge = $jobHeartbeatAt === null || $jobHeartbeatAt === ''
            ? null
            : max(0, $now - self::toUnix($jobHeartbeatAt));

        $presenceAt = null;
        foreach ($presenceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $seen = (string)($row['last_seen_at'] ?? '');
            if ($seen === '') {
                continue;
            }
            // ISO-8601 UTC stamps sort lexicographically; both repository
            // paths write that shape, and the job heartbeats are compared
            // the same way in aggregateTimestamps().
            if ($presenceAt === null || strcmp($seen, $presenceAt) > 0) {
                $presenceAt = $seen;
            }
        }
        if ($presenceAt === null) {
            return [
                'at'              => $jobHeartbeatAt,
                'age_s'           => $jobAge,
                'source'          => $jobHeartbeatAt === null ? null : 'job',
                'presence_at'     => null,
                'presence_age_s'  => null,
                'job_at'          => $jobHeartbeatAt,
                'job_age_s'       => $jobAge,
            ];
        }

        $presenceAge = max(0, $now - self::toUnix($presenceAt));
        // Freshest wins; a tie goes to the presence beacon, which is the
        // signal the worker itself publishes.
        $presenceWins = $jobAge === null || $presenceAge <= $jobAge;

        return [
            'at'             => $presenceWins ? $presenceAt : $jobHeartbeatAt,
            'age_s'          => $presenceWins ? $presenceAge : $jobAge,
            'source'         => $presenceWins ? 'presence' : 'job',
            'presence_at'    => $presenceAt,
            'presence_age_s' => $presenceAge,
            'job_at'         => $jobHeartbeatAt,
            'job_age_s'      => $jobAge,
        ];
    }

    /**
     * @return array{0:?string,1:?string}  [oldestQueuedAt, latestHeartbeatAt]
     */
    private function aggregateTimestamps(): array
    {
        if ($this->db !== null) {
            try {
                $row = $this->db->run(static function (PDO $pdo): ?array {
                    $stmt = $pdo->query(
                        'SELECT MIN(CASE WHEN status = "queued"
                                          THEN COALESCE(created_at, updated_at)
                                          ELSE NULL END) AS oldest_queued_at,
                                MAX(heartbeat_at) AS latest_heartbeat_at
                         FROM revoap_jobs'
                    );
                    if ($stmt === false) { return null; }
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    return is_array($row) ? $row : null;
                });
                if (is_array($row)) {
                    $oldest = isset($row['oldest_queued_at']) && $row['oldest_queued_at'] !== null
                        ? (string)$row['oldest_queued_at'] : null;
                    $latest = isset($row['latest_heartbeat_at']) && $row['latest_heartbeat_at'] !== null
                        ? (string)$row['latest_heartbeat_at'] : null;
                    return [$oldest, $latest];
                }
            } catch (Throwable) {
                // Fall through.
            }
        }

        $oldest = null;
        $latest = null;
        try {
            foreach ($this->jobs->listRecent(500) as $job) {
                if ($job->status === JobStatus::Queued) {
                    $ts = $job->createdAt;
                    if ($ts !== '' && ($oldest === null || strcmp($ts, $oldest) < 0)) {
                        $oldest = $ts;
                    }
                }
                $hb = $job->heartbeatAt;
                if (is_string($hb) && $hb !== '' && ($latest === null || strcmp($hb, $latest) > 0)) {
                    $latest = $hb;
                }
            }
        } catch (Throwable) {
            // Keep nulls.
        }
        return [$oldest, $latest];
    }

    /**
     * Distinct workers currently holding a running lock, with their
     * latest heartbeat and a per-worker running count. Stale rows are
     * excluded from the "alive" set so the UI doesn't list ghosts.
     *
     * @return list<array{worker_id:string,heartbeat_at:?string,heartbeat_age_s:?int,running:int,alive:bool}>
     */
    private function aggregateRunningWorkers(int $threshold): array
    {
        $now = time();
        $out = [];

        if ($this->db !== null) {
            try {
                $rows = $this->db->run(static function (PDO $pdo): array {
                    $stmt = $pdo->query(
                        'SELECT locked_by, MAX(heartbeat_at) AS heartbeat_at, COUNT(*) AS running
                         FROM revoap_jobs
                         WHERE status = "running" AND locked_by IS NOT NULL
                         GROUP BY locked_by'
                    );
                    if ($stmt === false) { return []; }
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    return is_array($rows) ? $rows : [];
                });
                foreach ($rows as $row) {
                    $wid = (string)($row['locked_by'] ?? '');
                    if ($wid === '') { continue; }
                    $hb  = isset($row['heartbeat_at']) && $row['heartbeat_at'] !== null
                        ? (string)$row['heartbeat_at'] : null;
                    $age = $hb === null ? null : max(0, $now - self::toUnix($hb));
                    $out[] = [
                        'worker_id'        => $wid,
                        'heartbeat_at'     => $hb,
                        'heartbeat_age_s'  => $age,
                        'running'          => (int)($row['running'] ?? 0),
                        'alive'            => $age !== null && $age <= $threshold,
                    ];
                }
                return $out;
            } catch (Throwable) {
                // Fall through.
            }
        }

        // Memory-mode mirror — aggregate from listRecent().
        try {
            $agg = [];
            foreach ($this->jobs->listRecent(500) as $job) {
                if ($job->status !== JobStatus::Running) { continue; }
                $wid = $job->lockedBy ?? '';
                if ($wid === '') { continue; }
                if (!isset($agg[$wid])) {
                    $agg[$wid] = ['heartbeat_at' => null, 'running' => 0];
                }
                $agg[$wid]['running']++;
                $hb = $job->heartbeatAt;
                if (is_string($hb) && $hb !== '' &&
                    ($agg[$wid]['heartbeat_at'] === null || strcmp($hb, $agg[$wid]['heartbeat_at']) > 0)) {
                    $agg[$wid]['heartbeat_at'] = $hb;
                }
            }
            foreach ($agg as $wid => $info) {
                $hb  = $info['heartbeat_at'];
                $age = $hb === null ? null : max(0, $now - self::toUnix($hb));
                $out[] = [
                    'worker_id'       => (string)$wid,
                    'heartbeat_at'    => $hb,
                    'heartbeat_age_s' => $age,
                    'running'         => (int)$info['running'],
                    'alive'           => $age !== null && $age <= $threshold,
                ];
            }
        } catch (Throwable) {
            // Keep empty.
        }
        return $out;
    }

    /**
     * Translate a SQL timestamp (UTC or local) into a Unix integer. We
     * deliberately use strtotime() with the server's default TZ — the
     * comparison only has to be self-consistent for the lifetime of
     * one snapshot, and the alternative (UTC_TIMESTAMP() round-trips)
     * would break under SQLite tests.
     */
    private static function toUnix(string $ts): int
    {
        $t = strtotime($ts);
        return is_int($t) && $t > 0 ? $t : 0;
    }

    /**
     * v1.8.44 — Normalize raw presence rows for the JSON envelope:
     * numeric casting plus the two derived fields the UI needs (uptime
     * and heartbeat age). Crashed workers are kept in the list with
     * `alive=false` so "exited cleanly" (no row) stays distinguishable
     * from "died mid-flight" (stale row).
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function presentPresenceRows(array $rows, int $threshold): array
    {
        $now = time();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $workerId = (string)($row['worker_id'] ?? '');
            if ($workerId === '') {
                continue;
            }
            $seenAt = (string)($row['last_seen_at'] ?? '');
            $seenAge = $seenAt === '' ? null : max(0, $now - self::toUnix($seenAt));
            $out[] = [
                'worker_id'         => $workerId,
                'host'              => self::nullableString($row['host'] ?? null),
                'pid'               => self::nullableInt($row['pid'] ?? null),
                'app_version'       => self::nullableString($row['app_version'] ?? null),
                'mode'              => self::nullableString($row['mode'] ?? null),
                'started_at'        => self::nullableString($row['started_at'] ?? null),
                'uptime_s'          => self::uptimeSeconds($row['started_at'] ?? null, $now),
                'last_seen_at'      => $seenAt === '' ? null : $seenAt,
                'last_seen_age_s'   => $seenAge,
                'alive'             => $seenAge !== null && $seenAge <= $threshold,
                'current_job_id'    => self::nullableInt($row['current_job_id'] ?? null),
                'jobs_done'         => max(0, (int)($row['jobs_done'] ?? 0)),
                'last_error_at'     => self::nullableString($row['last_error_at'] ?? null),
                // Free-form error text — same sanitizer the Logs tab uses.
                'last_error_reason' => self::sanitizedString($row['last_error_reason'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Merge the job-derived worker list with the presence beacon, keyed
     * by worker id.
     *
     * Semantics:
     *   * `heartbeat_at` / `heartbeat_age_s` keep their pre-v1.8.44
     *     meaning (the job row's heartbeat) whenever a running job
     *     exists; for an idle worker they carry the presence beacon so
     *     the UI still has something to show.
     *   * `alive` is true when EITHER signal is fresh. This matters
     *     during a long pipeline run: the poll loop is blocked inside
     *     the engine, so the presence beacon legitimately lapses while
     *     the job heartbeat keeps ticking.
     *   * `source` says which signal supplied the worker identity —
     *     `presence` when a beacon row exists, `job` otherwise.
     *   * `running` stays the number of `running` rows locked by that
     *     worker, so `0` plus `alive: true` means "idle".
     *
     * @param  list<array<string,mixed>> $jobWorkers
     * @param  list<array<string,mixed>> $presenceRows
     * @return list<array<string,mixed>>
     */
    private function mergePresenceWorkers(array $jobWorkers, array $presenceRows, int $threshold): array
    {
        $now = time();
        $byId = [];

        foreach ($jobWorkers as $worker) {
            $id = (string)($worker['worker_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = $worker + [
                'started_at'     => null,
                'uptime_s'       => null,
                'app_version'    => null,
                'mode'           => null,
                'pid'            => null,
                'host'           => null,
                'current_job_id' => null,
                'jobs_done'      => null,
                'source'         => 'job',
            ];
        }

        foreach ($presenceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)($row['worker_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $seenAt = (string)($row['last_seen_at'] ?? '');
            $seenAge = $seenAt === '' ? null : max(0, $now - self::toUnix($seenAt));
            $presenceAlive = $seenAge !== null && $seenAge <= $threshold;

            if (isset($byId[$id])) {
                $byId[$id]['host']           = self::nullableString($row['host'] ?? null);
                $byId[$id]['pid']            = self::nullableInt($row['pid'] ?? null);
                $byId[$id]['app_version']    = self::nullableString($row['app_version'] ?? null);
                $byId[$id]['mode']           = self::nullableString($row['mode'] ?? null);
                $byId[$id]['started_at']     = self::nullableString($row['started_at'] ?? null);
                $byId[$id]['uptime_s']       = self::uptimeSeconds($row['started_at'] ?? null, $now);
                $byId[$id]['current_job_id'] = self::nullableInt($row['current_job_id'] ?? null);
                $byId[$id]['jobs_done']      = max(0, (int)($row['jobs_done'] ?? 0));
                $byId[$id]['source']         = 'presence';
                $byId[$id]['alive']          = $presenceAlive || ($byId[$id]['alive'] ?? false);
                if (($byId[$id]['heartbeat_at'] ?? null) === null) {
                    $byId[$id]['heartbeat_at']    = $seenAt === '' ? null : $seenAt;
                    $byId[$id]['heartbeat_age_s'] = $seenAge;
                }
                continue;
            }

            $byId[$id] = [
                'worker_id'       => $id,
                'heartbeat_at'    => $seenAt === '' ? null : $seenAt,
                'heartbeat_age_s' => $seenAge,
                'running'         => 0,
                'alive'           => $presenceAlive,
                'started_at'      => self::nullableString($row['started_at'] ?? null),
                'uptime_s'        => self::uptimeSeconds($row['started_at'] ?? null, $now),
                'app_version'     => self::nullableString($row['app_version'] ?? null),
                'mode'            => self::nullableString($row['mode'] ?? null),
                'pid'             => self::nullableInt($row['pid'] ?? null),
                'host'            => self::nullableString($row['host'] ?? null),
                'current_job_id'  => self::nullableInt($row['current_job_id'] ?? null),
                'jobs_done'       => max(0, (int)($row['jobs_done'] ?? 0)),
                'source'          => 'presence',
            ];
        }

        // Deterministic order (worker ids are hostname-pid, so this also
        // groups a multi-worker pool readably). The pre-v1.8.44 order
        // came straight out of a GROUP BY and was never specified.
        ksort($byId, SORT_STRING);
        return array_values($byId);
    }

    /**
     * Seconds since the beacon's `started_at`, or null when the stamp is
     * missing/unparsable. Never negative — a clock skew must not render
     * as a nonsense uptime.
     */
    private static function uptimeSeconds(mixed $startedAt, int $now): ?int
    {
        if (!is_string($startedAt) || trim($startedAt) === '') {
            return null;
        }
        $started = self::toUnix($startedAt);
        return $started > 0 ? max(0, $now - $started) : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $string = (string)$value;
        return $string === '' ? null : $string;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (int)$value;
    }

    /**
     * Presence error reasons are free-form connector/DB text. Run them
     * through the same sanitizer the Logs tab uses so an embedded DSN
     * password or API key can never reach a browser.
     */
    private static function sanitizedString(mixed $value): ?string
    {
        $string = self::nullableString($value);
        return $string === null ? null : Logger::sanitizeScalar($string);
    }

    /**
     * `app.version` as configured, for the UI's "worker is older than
     * the deployed build" comparison. Never throws — a Config that has
     * not been loaded yields an empty string.
     */
    private static function appVersion(): string
    {
        try {
            $version = Config::get('app.version', '');
        } catch (Throwable) {
            return '';
        }
        return is_scalar($version) ? (string)$version : '';
    }

    /**
     * v1.8.51 — is the worker process running the code that has been
     * deployed?
     *
     * The production incident this answers: a long-lived worker kept the
     * PHP classes it had loaded at start-up, so a freshly deployed
     * `ModelsActualizer` called `RuntimeConfig::updateOrFail()` on a
     * process whose `RuntimeConfig` predated that method. PHP loads a
     * class once per process, so the mix "new caller + old callee" is not
     * a syntax error anywhere — it surfaced only as
     * `Call to undefined method`, hours into a debugging session, because
     * nothing in the UI distinguished "the worker is alive" from "the
     * worker is alive and running last week's code".
     *
     * `stale` when either signal says so:
     *   (a) a presence row's `app_version` differs from the deployed
     *       `app.version`, or
     *   (b) a PHP file under `src` — or `index.php` / `config.php` — was
     *       modified AFTER the process started (the beacon's `started_at`
     *       is written on INSERT only, so it is a real uptime) — the
     *       files on disk are newer than the running process.
     *
     * `unknown` when there is no presence row at all: nothing is running
     * to compare against. `current` when every signal that could be
     * checked agreed.
     *
     * @param  list<array<string,mixed>> $presenceRows Normalized rows from
     *         {@see self::presentPresenceRows()} — the same ones the card
     *         renders, so the warning cannot contradict the worker table.
     * @return array{state:string,reason:?string,newest_at:?string,newest_file:?string}
     */
    public static function codeState(array $presenceRows): array
    {
        $scan     = self::scanCodeMtimes();
        $newestAt = $scan['newest_mtime'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $scan['newest_mtime']) : null;
        $answer   = static fn(string $state, ?string $reason): array => [
            'state'       => $state,
            'reason'      => $reason,
            'newest_at'   => $newestAt,
            'newest_file' => $scan['newest_file'],
        ];

        $rows = [];
        foreach ($presenceRows as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            return $answer('unknown', 'No worker presence row: there is no running process to compare the deployed code against.');
        }

        $deployed = self::appVersion();
        $checked  = false;
        foreach ($rows as $row) {
            $running = trim((string)($row['app_version'] ?? ''));
            if ($running === '' || $deployed === '') {
                continue;
            }
            $checked = true;
            if ($running !== $deployed) {
                return $answer('stale', sprintf(
                    'Worker "%s" reports app version %s while this deployment is %s — it was started before the current code was deployed.',
                    (string)($row['worker_id'] ?? '?'),
                    $running,
                    $deployed,
                ));
            }
        }

        if ($scan['newest_mtime'] > 0) {
            foreach ($rows as $row) {
                $startedAt = trim((string)($row['started_at'] ?? ''));
                $started   = $startedAt === '' ? 0 : self::toUnix($startedAt);
                if ($started <= 0) {
                    continue;
                }
                $checked = true;
                if ($scan['newest_mtime'] > $started) {
                    return $answer('stale', sprintf(
                        '%s was modified at %s, after the worker process started at %s — the running process still holds the classes it loaded then.',
                        (string)($scan['newest_file'] ?? 'a code file'),
                        (string)$newestAt,
                        $startedAt,
                    ));
                }
            }
        }

        if (!$checked) {
            // Honest "I cannot tell": a beacon row that carries neither a
            // version nor a start time proves nothing about the code it runs.
            return $answer('unknown', 'The presence row carries neither an app version nor a start time, so the running code cannot be compared with the deployed one.');
        }

        return $answer('current', null);
    }

    /**
     * The newest modification time among the files that make up the
     * running application, plus which file it was.
     *
     * mtime only — no file is opened — and the answer is cached in-process
     * for {@see self::CODE_SCAN_TTL_SECONDS}, so the Worker card's 5s poll
     * walks the tree at most once per TTL instead of on every refresh.
     *
     * @return array{newest_mtime:int,newest_file:?string,scanned:int,seconds:float,cached:bool}
     */
    public static function scanCodeMtimes(?string $root = null): array
    {
        $root = $root ?? Config::rootDir();
        if ($root === '') {
            // Config not loaded (a CLI caller): the class's own location is
            // the same root, since this file lives in `<root>/src/Jobs`.
            $root = dirname(__DIR__, 2);
        }
        $key = str_replace('\\', '/', $root);
        $now = time();
        $cached = self::$codeScanCache[$key] ?? null;
        if (is_array($cached) && ($now - $cached['at']) < self::CODE_SCAN_TTL_SECONDS) {
            return $cached + ['cached' => true];
        }

        $startedAt = microtime(true);
        $newest    = 0;
        $newestF   = null;
        $scanned   = 0;
        $consider  = static function (string $path, string $label) use (&$newest, &$newestF, &$scanned): void {
            if (!is_file($path)) {
                return;
            }
            $mtime = @filemtime($path);
            if ($mtime === false) {
                return;
            }
            $scanned++;
            if ($mtime > $newest) {
                $newest  = $mtime;
                $newestF = $label;
            }
        };

        foreach (self::CODE_PATHS as $relative) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_dir($path)) {
                try {
                    $walker = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::LEAVES_ONLY,
                    );
                    foreach ($walker as $item) {
                        $pathname = (string)$item->getPathname();
                        if (strtolower(pathinfo($pathname, PATHINFO_EXTENSION)) !== 'php') {
                            continue;
                        }
                        $consider($pathname, self::relativeTo($root, $pathname));
                    }
                } catch (Throwable) {
                    // An unreadable tree is not a health failure: the scan
                    // reports what it could see and the caller says 'current'
                    // or 'unknown' on the evidence it has.
                }
                continue;
            }
            $consider($path, $relative);
        }

        $result = [
            'at'           => $now,
            'newest_mtime' => $newest,
            'newest_file'  => $newestF,
            'scanned'      => $scanned,
            'seconds'      => microtime(true) - $startedAt,
        ];
        self::$codeScanCache[$key] = $result;
        return $result + ['cached' => false];
    }

    /** A root-relative, forward-slash path for the "which file changed" text. */
    private static function relativeTo(string $root, string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $path);
        return str_starts_with($normalizedPath, $normalizedRoot)
            ? substr($normalizedPath, strlen($normalizedRoot))
            : $normalizedPath;
    }

    /**
     * Drop the in-process scan cache. Tests point `Config::rootDir()` at
     * different trees; production never needs this.
     */
    public static function resetCodeScanCache(): void
    {
        self::$codeScanCache = [];
    }

    /**
     * @return array<string,int>
     */
    private static function zeroCounts(): array
    {
        $out = [];
        foreach (self::REPORTED_STATUSES as $s) { $out[$s] = 0; }
        return $out;
    }

    /**
     * v1.8.39 — Snapshot-safe view of the resolved auto-recovery
     * policy. Reads `pipeline.jobs.recovery.*` via Config so the
     * Settings UI can display the live values without re-implementing
     * the clamps.
     *
     * @return array<string,mixed>
     */
    private static function resolveRetryPolicy(): array
    {
        try {
            $policy = Config::jobsRecoveryPolicy();
        } catch (Throwable) {
            return [
                'auto_recovery_enabled' => true,
                'max_attempts'          => 3,
                'stale_after_seconds'   => self::DEFAULT_STALE_THRESHOLD,
                'backoff_seconds'       => [30, 120, 300],
                'backoff_base_seconds'  => 30,
                'backoff_max_seconds'   => 900,
            ];
        }
        // Cast to plain values so the JSON envelope is stable for the UI.
        return [
            'auto_recovery_enabled' => (bool)$policy['auto_recovery_enabled'],
            'max_attempts'          => (int)$policy['max_attempts'],
            'stale_after_seconds'   => (int)$policy['stale_after_seconds'],
            'backoff_seconds'       => array_map(static fn($v): int => (int)$v, $policy['backoff_seconds']),
            'backoff_base_seconds'  => (int)$policy['backoff_base_seconds'],
            'backoff_max_seconds'   => (int)$policy['backoff_max_seconds'],
        ];
    }

    /**
     * Clamp the heartbeat threshold so a hostile query string cannot
     * push the classifier to a useless extreme.
     */
    /**
     * v1.8.47 — database connection-resilience telemetry.
     *
     * `Database` keeps authoritative per-instance counters, but this
     * service is usually built inside a short-lived FPM request: the
     * process that actually survived a MySQL restart is the worker.
     * {@see DbResilienceJournal} carries those counters across
     * processes, so the endpoint can answer "has this deployment lost
     * its database connection since I last looked?" instead of always
     * reporting zero.
     *
     * The in-process counter is used as a floor, which keeps the value
     * correct when the journal is unavailable (offline tests, a
     * read-only `runtime/`), and keeps it monotonically non-decreasing
     * when a journal write was lost.
     *
     * @return array{db_connected:bool,db_reconnects:int,db_losses:int,
     *               last_db_error_at:?string,last_db_error:?string}
     */
    private function databaseResilience(): array
    {
        $journal = ['reconnects' => 0, 'losses' => 0, 'last_error_at' => null, 'last_error' => null];
        try {
            $journal = DbResilienceJournal::read() + $journal;
        } catch (Throwable) {
            // Diagnostics are advisory: never fail the snapshot.
        }

        $liveReconnects = $this->db?->reconnects() ?? 0;
        $liveLosses     = $this->db?->losses() ?? 0;
        $liveErrorAt    = $this->db?->lastErrorAt();
        $liveError      = $this->db?->lastErrorMessage();

        $journalErrorAt = is_string($journal['last_error_at'] ?? null) ? (string)$journal['last_error_at'] : null;
        // ISO-8601 UTC stamps sort lexicographically, so the later of the
        // two IS the most recent — take whichever describes the newer
        // incident rather than assuming the journal exists.
        $lastErrorAt = $liveErrorAt;
        if ($journalErrorAt !== null && ($lastErrorAt === null || strcmp($journalErrorAt, $lastErrorAt) > 0)) {
            $lastErrorAt = $journalErrorAt;
        }

        return [
            // A cheap, process-local answer to "is a handle held right
            // now". `last_db_error_at` is the durable half of the pair:
            // together they distinguish "healthy" from "reconnected a
            // minute ago" from "still down".
            'db_connected'      => $this->db !== null && $this->db->isConnected(),
            'db_reconnects'     => max($liveReconnects, (int)($journal['reconnects'] ?? 0)),
            'db_losses'         => max($liveLosses, (int)($journal['losses'] ?? 0)),
            'last_db_error_at'  => $lastErrorAt,
            'last_db_error'     => $liveError ?? (is_string($journal['last_error'] ?? null) ? (string)$journal['last_error'] : null),
        ];
    }

    private static function clampThreshold(?int $value): int
    {
        if ($value === null || $value <= 0) {
            return self::DEFAULT_STALE_THRESHOLD;
        }
        if ($value < 30)    { return 30; }
        if ($value > 86400) { return 86400; }
        return $value;
    }
}
