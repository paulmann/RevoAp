<?php
/**
 * bin/revoap-worker.php - RevoAp durable LLM pipeline worker.
 *
 * Phase 2 of Worker Architecture v2. Runs the existing PHP
 * `App\Pipeline\PipelineEngine` *outside* of PHP-FPM as a long-lived
 * CLI process. nginx, PHP-FPM, MariaDB idle timeouts, and EventSource
 * disconnects therefore can no longer abort a multi-cycle consensus
 * run mid-flight: the worker has its own lifecycle and writes every
 * pipeline event into `pipeline_events` so the existing
 * /api/council/session/{id}/events/stream endpoint can replay them
 * to a freshly-opened browser tab.
 *
 * Modes:
 *   --once                  Run a single iteration, exit. Default.
 *   --loop                  Daemon mode. Polls forever until SIGTERM.
 *   --sleep=N               Seconds between polls in --loop mode.
 *                           Default: 1.
 *   --worker-id=ID          Lock identifier written to `locked_by`.
 *                           Default: <hostname>-<pid>.
 *   --max-jobs=N            Stop after N jobs, even in --loop mode.
 *                           Default: 0 (no cap).
 *   --stale-after=N         Heartbeat threshold in seconds. Jobs whose
 *                           heartbeat is older are reclaimed and
 *                           auto-requeued (v1.8.39) until the retry
 *                           budget is exhausted. Default: from
 *                           `pipeline.jobs.recovery.stale_after_seconds`
 *                           (300 unless overridden).
 *   --max-attempts=N        Override the auto-recovery retry ceiling
 *                           for THIS process. The per-row `max_attempts`
 *                           column captured at create time still wins
 *                           - this flag only changes the upper bound
 *                           applied to rows missing that stamp.
 *                           Default: config-driven.
 *   --backoff-base=N        Override `pipeline.jobs.recovery
 *                           .backoff_base_seconds`; rebuilds the
 *                           auto-recovery ramp (x1, x4, x10 of base).
 *   --backoff-max=N         Cap (seconds) the auto-recovery backoff
 *                           may grow to per attempt. Combined with
 *                           --backoff-base the rebuilt ramp is capped
 *                           at this value.
 *   --no-auto-recovery      Disable v1.8.39 auto-recovery for this
 *                           process. Stale jobs are flipped to `stale`
 *                           and pinned there (pre-v1.8.39 behaviour).
 *   --status                Print the same diagnostic JSON that
 *                           /api/health/worker emits and exit. Useful
 *                           for systemd ExecStart pre-flight checks
 *                           and operator copy/paste reports.
 *   --help                  Print this help and exit.
 *
 * Default behaviour with no flags is `--once`: one acquire-execute-
 * exit cycle, safe for foreground / cron-style smoke checks.
 *
 * The script refuses to run from a non-CLI SAPI. Structured JSON log
 * lines are emitted to STDOUT (`info`/`debug`) and STDERR (`warn`/
 * `error`). Secrets never reach the log stream - both Logger::sanitize
 * and Job::sanitizePayload strip API keys before formatting.
 *
 * v1.8.44 - while it runs, the worker also upserts one row in
 * `revoap_worker_presence` (throttled to 1/s) so operators can tell
 * "alive and idle" from "not started", and deletes that row on a clean
 * exit so "stopped" stays distinguishable from "crashed". Presence
 * writes are best-effort and never abort the loop.
 *
 * v1.8.47 - the worker survives a MySQL restart without a restart of its
 * own. A long-lived process holds its connection for hours, so
 * `systemctl restart mysql` used to leave it with a permanently dead
 * handle: every job died ~5s later with `2006 MySQL server has gone
 * away`, the row was marked `failed`, the session stayed `running` with
 * no events, and the presence beacon kept reporting healthy. Now:
 *
 *   * every repository that lives for the whole process is built on the
 *     resilient App\Core\Database wrapper, not on a `PDO` snapshot, so
 *     its handle is replaced after a reconnect;
 *   * the connection is verified before each job, and a lost connection
 *     during a job is retried once on a fresh handle;
 *   * a job that still cannot run is requeued WITHOUT spending its
 *     model retry budget, with a backoff;
 *   * a genuinely unreachable database makes the loop wait with an
 *     exponential backoff instead of spinning, and lets the presence row
 *     go stale rather than claim the worker is healthy;
 *   * `worker.db.lost` / `worker.db.reconnected` make the transition
 *     visible instead of silent.
 *
 * Exit codes:
 *   0  Success (loop terminated cleanly, or single-shot completed,
 *      even when the queue was empty).
 *   2  Invoked from the web SAPI.
 *   3  Help text printed.
 *   4  Fatal bootstrap error (config missing, DB unreachable, …).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.2.0
 * @since    2026-05-13
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error'   => 'cli_only',
        'message' => 'revoap-worker.php must be run from the CLI',
    ]);
    exit(2);
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);
@set_time_limit(0);

/**
 * Minimal arg parser. Accepts `--key=value`, `--flag`, ignores unknown
 * positionals so a wrapper script can pass through additional tokens.
 *
 * @param  list<string> $argv
 * @return array<string,string|bool>
 */
function rv_worker_parse_args(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (!is_string($arg)) { continue; }
        if (!str_starts_with($arg, '--')) { continue; }
        $body = substr($arg, 2);
        if ($body === '') { continue; }
        if (str_contains($body, '=')) {
            [$k, $v] = explode('=', $body, 2);
            $out[strtolower(trim($k))] = trim($v);
        } else {
            $out[strtolower(trim($body))] = true;
        }
    }
    return $out;
}

/**
 * Emit one JSON log line. `info` → STDOUT, everything else → STDERR.
 * No secrets: the worker only passes app-defined event names and
 * already-sanitized context envelopes from PipelineJobRunner.
 *
 * @param array<string,mixed> $ctx
 */
function rv_worker_log(string $level, string $event, array $ctx = []): void
{
    $line = json_encode([
        'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
        'level'   => $level,
        'event'   => $event,
        'ctx'     => $ctx,
        'service' => 'revoap-worker',
        'pid'     => getmypid(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) { return; }
    $stream = ($level === 'info' || $level === 'debug') ? STDOUT : STDERR;
    fwrite($stream, $line . "\n");
}

function rv_worker_print_help(): void
{
    $help = <<<HELP
RevoAp Worker - durable LLM pipeline runner (Phase 2).

Usage:
  php bin/revoap-worker.php [--once|--loop] [--sleep=SEC]
                               [--worker-id=ID] [--max-jobs=N]
                               [--stale-after=SEC]

Flags:
  --once                  Acquire and run a single job, then exit (default).
  --loop                  Daemon mode: poll forever, run jobs as they arrive.
  --sleep=SEC             Sleep between polls in --loop mode (default 1).
  --worker-id=ID          Lock identifier written to revoap_jobs.locked_by
                          (default: "<hostname>-<pid>").
  --max-jobs=N            Stop after N successful acquires, even in --loop
                          (default 0 = no cap).
  --stale-after=SEC       Reclaim Running jobs whose heartbeat is older
                          than SEC seconds (default from config).
  --max-attempts=N        Override the auto-recovery retry ceiling (v1.8.39).
  --backoff-base=SEC      Override backoff_base_seconds for auto-recovery
                          and rebuild the ramp (x1, x4, x10 of SEC).
  --backoff-max=SEC       Cap auto-recovery backoff. Combined with
                          --backoff-base the rebuilt ramp is capped.
  --no-auto-recovery      Disable v1.8.39 auto-recovery (legacy behaviour).
  --status                Print the worker diagnostic snapshot (same JSON
                          as /api/health/worker) and exit.
  --help                  Print this message and exit.

Exit codes:
  0   success (including "queue was empty" in --once mode)
  2   invoked from the web SAPI
  3   help printed
  4   fatal bootstrap error

HELP;
    fwrite(STDOUT, $help);
}

$argvParsed = rv_worker_parse_args($argv ?? []);
if (isset($argvParsed['help'])) {
    rv_worker_print_help();
    exit(3);
}

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require $root . '/src/autoload.php';
}

// Bootstrap config + connectors + DB the same way the web front
// controller does. We do NOT instantiate any HTTP layer (no Router,
// no Response, no SSE) - the worker only talks to the engine + the
// durable job repository.
set_exception_handler(static function (\Throwable $e): void {
    rv_worker_log('error', 'worker.bootstrap.exception', [
        'class'   => (new \ReflectionClass($e))->getShortName(),
        'message' => $e->getMessage(),
    ]);
    exit(4);
});

try {
    $app = new \App\Core\Application($root);
} catch (\Throwable $e) {
    rv_worker_log('error', 'worker.bootstrap.config_failed', ['message' => $e->getMessage()]);
    exit(4);
}

$db  = $app->database();
$pdo = $app->pdo();
$connectors = $app->connectors();

if ($db === null && $pdo === null) {
    rv_worker_log('warn', 'worker.bootstrap.no_database', [
        'detail' => 'Database not configured; using in-memory job mirror. '
                  . 'Jobs created by the web front controller will not be visible.',
    ]);
}

// v1.8.47 - make a database reconnect observable.
//
// Database::run() already heals a lost connection transparently, which is
// exactly why the production incident was invisible: the operator saw
// jobs fail with 2006 and a green worker indicator, and nothing told
// them the process had lost its database. The wrapper reports each
// transition through its observer; we turn that into two dedicated log
// events carrying the running reconnect count.
if ($db !== null) {
    $db->addObserver(static function (string $event, array $context): void {
        rv_worker_log(
            $event === 'db.reconnected' ? 'info' : 'error',
            'worker.' . $event,
            $context,
        );
    });
}

$responseCache = \App\Cache\ModelResponseCache::fromConfig($db, $root);
$sessions      = new \App\Storage\SessionRepository($db ?? $pdo);
$stepRepo      = new \App\Storage\StepRepository($db ?? $pdo);
$responseRepo  = new \App\Storage\ResponseRepository($db ?? $pdo);
// v1.8.47 - `$db ?? $pdo`, not `$pdo`. The engine resolves a skill slug
// and bumps `use_count` on every run, and these repositories hold their
// handle for the lifetime of the process. A raw PDO snapshot here means
// a MySQL restart breaks EVERY subsequent job, because the handle can
// never be replaced. Passing the wrapper is what makes them heal.
$skillRepo     = new \App\Skills\SkillRepository($db ?? $pdo);
// Seed bundled skills so domain auto-routing finds them in worker
// contexts where the web SAPI has not been hit yet on this host.
try {
    $loader = new \App\Skills\SkillLoader($root . '/src/Skills/definitions');
    foreach ($loader->loadAll() as $skill) {
        if ($skillRepo->findBySlug($skill->slug) === null) {
            $skillRepo->save($skill);
        }
    }
} catch (\Throwable $e) {
    rv_worker_log('warn', 'worker.bootstrap.skill_seed_failed', ['message' => $e->getMessage()]);
}

$skillRouter = new \App\Skills\SkillRouter($skillRepo);
$autoCreator = new \App\Skills\SkillAutoCreator($skillRepo, $connectors);
$skillSelector = new \App\Skills\SkillSelector($skillRepo, $connectors, $autoCreator);
$normalizer  = new \App\Consensus\NormalizationService();
$consensus   = new \App\Consensus\ConsensusEngine();
$collector   = new \App\Stats\StatsCollector();

$engine = new \App\Pipeline\PipelineEngine(
    $connectors, $sessions, $stepRepo, $responseRepo, $skillRepo,
    $skillRouter, $autoCreator, $normalizer, $consensus, $collector,
    null, null, $responseCache, $skillSelector,
);
// Task 5 - wire the optional vector memory facade (mirrors index.php).
// All components degrade softly when the DB / embedder is unavailable;
// recall/store are additionally gated by pipeline.memory_top_k > 0.
if (method_exists($engine, 'setMemory')) {
    $engine->setMemory(new \App\Memory\EmbeddingMemory(
        vectorStore: \App\Storage\Vector\VectorStoreFactory::create($pdo),
        embedder:    new \App\Memory\ConnectorMemoryEmbedder($connectors),
        repository:  new \App\Memory\PdoMemoryRepository($pdo),
    ));
}
$runControl = new \App\Pipeline\RunControl($root);
$engine->setRunControl($runControl);

// Profile + domain presets + analyzer parity with the web front
// controller so the worker reproduces the same routing decisions.
// v1.8.47 - all three receive `$db ?? $pdo`: the engine reads the local
// profile and the default domain preset on every run.
$profileRepo     = new \App\Profiles\UserProfileRepository($db ?? $pdo);
$promptAssembler = new \App\Profiles\SystemPromptAssembler($root);
$presetsRepo     = new \App\DomainPresets\DomainPresetRepository($db ?? $pdo);
try { $presetsRepo->seedDefaultsIfEmpty(); } catch (\Throwable) {}
$domainRouter = new \App\DomainPresets\DomainRouter();
$analyzer     = new \App\Analyzer\RequestAnalyzer($connectors);
if (method_exists($engine, 'setSystemPromptAssembler')) {
    $engine->setSystemPromptAssembler($promptAssembler);
}
if (method_exists($engine, 'setUserProfileRepository')) {
    $engine->setUserProfileRepository($profileRepo);
}
if (method_exists($engine, 'setDomainRouter')) {
    $engine->setDomainRouter($domainRouter, $presetsRepo, $skillRepo);
}
if (method_exists($engine, 'setRequestAnalyzer')) {
    $engine->setRequestAnalyzer($analyzer);
}

$jobRepo  = new \App\Jobs\JobRepository($db ?? $pdo);

// --status: pure diagnostic - print the same JSON envelope the web
// endpoint emits and exit. Runs after bootstrap so it can see real
// queue state; never touches the engine or the runner.
if (isset($argvParsed['status'])) {
    $threshold = isset($argvParsed['stale-after']) && is_numeric($argvParsed['stale-after'])
        ? max(30, (int)$argvParsed['stale-after'])
        : null;
    $diag = (new \App\Jobs\WorkerHealthService($jobRepo, $db))->snapshot($threshold);
    $json = json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite(STDOUT, ($json === false ? '{}' : $json) . "\n");
    exit(0);
}

// v1.8.49 - Models tab -> "Actualize linked models". The `models_actualize`
// job kind imports the chosen provider catalogue first, then reconciles the
// persisted catalogue and the consensus presets with the live provider
// catalogues. It needs the connector registry (live lists + the analyzer
// call), the preset repository, the app_config-backed snapshot store that
// makes the run reversible, and the job-event sink the Models tab polls for
// progress. ConfigRepository needs a real PDO handle, so the snapshot store
// and the section's own setting (`models.actualize.analyzer`) degrade to
// "unavailable" (and the run refuses to write) when the worker has no
// database at all. The importer is the same implementation behind the Models
// tab's "Import models" button, so the two cannot fetch a catalogue
// differently.
$workerConfigPdo  = $pdo ?? ($db?->pdo());
$workerConfigRepo = $workerConfigPdo !== null ? new \App\Settings\ConfigRepository($workerConfigPdo) : null;
$modelsActualizer = new \App\Models\ModelsActualizer(
    $connectors,
    new \App\DomainPresets\DomainPresetRepository($db ?? $pdo),
    new \App\Models\ModelsSnapshotStore($workerConfigRepo),
    new \App\Jobs\JobAuditRepository($db ?? $pdo),
    \App\Cache\ModelResponseCache::fromConfig($db, $root),
    new \App\Models\ModelImporter(),
    new \App\Models\ModelsActualizeSettings($workerConfigRepo),
);
unset($workerConfigPdo, $workerConfigRepo);

// Batch API runs (`payload.kind = 'batch_pipeline'`). The orchestrator is
// given the engine as its L1 planner (it implements
// `BatchL1PlannerInterface`) and a Batch client whose base URL and key come
// from the same `bothub` connector the synchronous path uses. When ext-curl
// is unavailable the client's transport raises a self-explaining
// ConnectorException, which the orchestrator turns into a failed job with
// that message rather than a fatal.
// `getByProvider()` is typed as ConnectorInterface; the Batch client needs
// the AbstractConnector surface (endpointBase/credential/authHeaderLines).
// Anything else falls back to a fresh BothubConnector, which resolves the
// same base URL and key from config.
$bothubConnector = $connectors->getByProvider('bothub');
$batchClient   = new \App\Connectors\Batch\BothubBatchClient(
    $bothubConnector instanceof \App\Connectors\AbstractConnector ? $bothubConnector : null
);
$batchPipeline = new \App\Batch\BatchPipeline($jobRepo, $engine, $batchClient);

// v1.9.3 - the session repository closes the session a non-pipeline job
// kind opened (models actualization); without it such a run stayed
// `running` in History while its job was already `completed`.
$runner   = new \App\Jobs\PipelineJobRunner($jobRepo, $engine, $runControl, null, $modelsActualizer, $batchPipeline, $sessions);

// v1.8.47 - resilience wrapper around the runner. It verifies the
// connection before each job, retries once on a fresh handle after a
// lost connection, and requeues (rather than fails) work that could not
// run because the database was unavailable.
$executor = new \App\Jobs\WorkerJobExecutor($jobRepo, $runner, $db);

// v1.8.43 - outbound webhooks. The worker emits signed run lifecycle
// events after each job and pumps due delivery retries while the queue
// is idle. Fail-open: dispatcher errors never abort the worker loop.
$webhookRepo        = new \App\Api\WebhookRepository($pdo);
$webhookDeliveryRepo = new \App\Api\WebhookDeliveryRepository($pdo);
$webhookDispatcher  = new \App\Api\WebhookDispatcher($webhookRepo, $webhookDeliveryRepo);

$hostname  = gethostname();
$hostname  = is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';
$pid       = getmypid();
$pidStr    = is_int($pid) && $pid > 0 ? (string)$pid : 'na';
$workerId  = isset($argvParsed['worker-id']) && is_string($argvParsed['worker-id']) && $argvParsed['worker-id'] !== ''
    ? (string)$argvParsed['worker-id']
    : $hostname . '-' . $pidStr;
$mode      = isset($argvParsed['loop']) ? 'loop' : 'once';
$sleepSec  = isset($argvParsed['sleep']) && is_numeric($argvParsed['sleep'])
    ? max(0, (int)$argvParsed['sleep'])
    : 1;
$maxJobs   = isset($argvParsed['max-jobs']) && is_numeric($argvParsed['max-jobs'])
    ? max(0, (int)$argvParsed['max-jobs'])
    : 0;
// v1.8.39 - resolved retry/backoff policy. The CLI flags override the
// in-process config values; the per-row `max_attempts` column captured
// at create time always wins over this process-level ceiling.
$recoveryPolicy = \App\Core\Config::jobsRecoveryPolicy();
$staleSec = isset($argvParsed['stale-after']) && is_numeric($argvParsed['stale-after'])
    ? max(30, (int)$argvParsed['stale-after'])
    : (int)$recoveryPolicy['stale_after_seconds'];

$autoRecoveryEnabled = (bool)$recoveryPolicy['auto_recovery_enabled'];
if (isset($argvParsed['no-auto-recovery'])) {
    $autoRecoveryEnabled = false;
}
if (isset($argvParsed['max-attempts']) && is_numeric($argvParsed['max-attempts'])) {
    $recoveryPolicy['max_attempts'] = max(1, (int)$argvParsed['max-attempts']);
}
if (isset($argvParsed['backoff-base']) && is_numeric($argvParsed['backoff-base'])) {
    $recoveryPolicy['backoff_base_seconds'] = max(1, (int)$argvParsed['backoff-base']);
}
if (isset($argvParsed['backoff-max']) && is_numeric($argvParsed['backoff-max'])) {
    $recoveryPolicy['backoff_max_seconds'] = max(1, (int)$argvParsed['backoff-max']);
}
// v1.8.39 - a CLI backoff override rebuilds the ramp from the
// (possibly overridden) base as x1/x4/x10, each entry capped at
// backoff_max. Without an override the config schedule is used as-is.
$backoffBaseOverride = isset($argvParsed['backoff-base']) && is_numeric($argvParsed['backoff-base']);
$backoffMaxOverride  = isset($argvParsed['backoff-max']) && is_numeric($argvParsed['backoff-max']);
if ($backoffBaseOverride || $backoffMaxOverride) {
    $ramp = [];
    foreach ([1, 4, 10] as $multiplier) {
        $entry = (int)$recoveryPolicy['backoff_base_seconds'] * $multiplier;
        $cap   = (int)$recoveryPolicy['backoff_max_seconds'];
        if ($cap > 0 && $entry > $cap) { $entry = $cap; }
        $ramp[] = max(1, $entry);
    }
    /** @var list<int> $backoffSchedule */
    $backoffSchedule = array_values(array_unique($ramp));
} else {
    /** @var list<int> $backoffSchedule */
    $backoffSchedule = is_array($recoveryPolicy['backoff_seconds'])
        ? array_values(array_map(static fn($v): int => max(0, (int)$v), $recoveryPolicy['backoff_seconds']))
        : [];
    if ($backoffSchedule === []) {
        $backoffSchedule = [(int)$recoveryPolicy['backoff_base_seconds']];
    }
}

// SIGTERM/SIGINT graceful shutdown. pcntl is optional; on builds
// without it the worker still terminates on hard SIGKILL (which we
// can't trap anyway) and the next start reclaims stale rows.
$shouldStop = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $signalHandler = static function (int $sig) use (&$shouldStop): void {
        $shouldStop = true;
        rv_worker_log('info', 'worker.signal', ['signal' => $sig, 'action' => 'graceful_shutdown']);
    };
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGINT,  $signalHandler);
}

rv_worker_log('info', 'worker.start', [
    'mode'                  => $mode,
    'worker_id'             => $workerId,
    'sleep'                 => $sleepSec,
    'max_jobs'              => $maxJobs,
    'stale_after'           => $staleSec,
    'auto_recovery_enabled' => $autoRecoveryEnabled,
    'max_attempts'          => (int)$recoveryPolicy['max_attempts'],
    'backoff_seconds'       => $backoffSchedule,
    'app_version'           => (string)\App\Core\Config::get('app.version', '0.0.0'),
    'db_backed'             => $db !== null || $pdo !== null,
]);

// v1.8.44 - worker presence beacon (`revoap_worker_presence`).
//
// Before this the ONLY database trace a running worker left came from
// `revoap_jobs.locked_by` / `heartbeat_at`, so a process that was alive
// with an empty queue was indistinguishable from a process that was not
// started at all - Settings showed "Waiting for worker" for both. We now
// upsert ONE row per worker (throttled to 1/s inside the repository):
//
//   row present + fresh  -> alive         (Settings: healthy / idle)
//   row present + stale  -> crashed       (SIGKILL / OOM / host reboot)
//   row absent           -> clean exit    (we delete our own row below)
//
// The whole block is fail-soft by design: a presence write failure is
// logged once and never aborts the poll loop.
$presence = new \App\Jobs\WorkerPresenceRepository($db ?? $pdo);
$presenceAvailable = false;
$presencePurged    = 0;
try {
    $presenceAvailable = $presence->tableAvailable();
    if ($presenceAvailable) {
        // Rows left behind by SIGKILLed workers would otherwise pile up
        // forever. 10x the stale threshold keeps a crash visible for a
        // reasonable investigation window before it is reaped.
        $presencePurged = $presence->purgeStale($staleSec * 10);
    }
} catch (\Throwable $e) {
    rv_worker_log('warn', 'worker.presence.unavailable', [
        'class'   => (new \ReflectionClass($e))->getShortName(),
        'message' => \App\Core\Logger::sanitizeScalar($e->getMessage()),
    ]);
}

$presenceState = [
    'host'              => $hostname,
    'pid'               => is_int($pid) && $pid > 0 ? $pid : null,
    'app_version'       => (string)\App\Core\Config::get('app.version', '0.0.0'),
    'mode'              => $mode,
    'started_at'        => gmdate('Y-m-d H:i:s'),
    'current_job_id'    => null,
    'jobs_done'         => 0,
    'last_error_at'     => null,
    'last_error_reason' => null,
];
// First beat is forced: `started_at` must land before the loop starts
// sleeping, otherwise a `--loop --sleep=60` worker would be invisible
// for a whole minute after start.
$presence->beat($workerId, $presenceState, true);


// Operator-requested restart (R2 follow-up).
//
// The web panel cannot restart a systemd unit, and it does not need to: the
// unit is Restart=always, so a worker that stops cleanly is started again by
// the supervisor - on the code that is actually deployed. The button writes a
// request; this is where it is honoured, once per poll, before anything else.
$restartSignal   = new \App\Jobs\WorkerRestartSignal($db ?? $pdo);
$processStartedAt = time();
rv_worker_log('info', 'worker.presence', [
    'table_available' => $presenceAvailable,
    'purged_rows'     => $presencePurged,
    'started_at'      => $presenceState['started_at'],
]);

// Keep the beacon fresh while a job runs. The poll loop is blocked
// inside the engine for the whole (potentially many-minute) pipeline
// run, so without this hook the row would look stale and the UI would
// report a healthy worker as crashed.
$runner->setPresenceBeat(static function () use ($presence, $workerId, &$presenceState): void {
    $presence->beat($workerId, $presenceState);
});

$ranCount = 0;
// v1.8.47 - anti-spin guard. When the database is genuinely down the
// loop must wait, not hot-poll: the connection is needed for the
// presence beacon, the recovery sweep and the queue itself, so a spin
// would burn CPU while producing nothing but log lines. Doubling from
// 2s and capped at 30s means a `systemctl restart mysql` that takes ten
// seconds is invisible, while a real outage costs two log lines a
// minute instead of thousands.
$dbBackoffBaseSec = 2;
$dbBackoffMaxSec  = 30;
$dbFailureStreak  = 0;
do {
        // A restart the operator asked for from the panel. Checked before the
        // database health gate on purpose: a worker stuck on a broken connection
        // is exactly one an operator wants to replace.
        if (\App\Jobs\WorkerRestartSignal::shouldExit($restartSignal->requestedAt(), $processStartedAt)) {
            rv_worker_log('info', 'worker.restart_requested', [
                'process_started_at' => gmdate('Y-m-d H:i:s', $processStartedAt),
                'action'             => 'graceful_shutdown_for_restart',
            ]);
            $shouldStop = true;
        }

    // v1.8.47 - verify the connection BEFORE touching anything else.
    //
    // A long-lived process holds its handle for hours; `isConnected()`
    // only proves a handle is held, not that the server is still there.
    // Checking here means a restart of MySQL is repaired once per poll
    // cycle instead of poisoning the first write of the next job.
    if ($db !== null && !$db->ensureConnected()) {
        $dbFailureStreak++;
        $pause = min($dbBackoffMaxSec, $dbBackoffBaseSec * (2 ** min($dbFailureStreak - 1, 5)));
        $presenceState['last_error_at']     = gmdate('Y-m-d H:i:s');
        $presenceState['last_error_reason'] = 'database_unavailable';
        rv_worker_log('error', 'worker.db.unavailable', [
            'streak'             => $dbFailureStreak,
            'retry_in_s'         => $pause,
            'reconnects'         => $db->reconnects(),
            'losses'             => $db->losses(),
            'last_db_error'      => $db->lastErrorMessage(),
            'presence_degraded'  => $presence->isDegraded(),
        ]);
        // The beacon row cannot be refreshed while the server is
        // unreachable, so it goes stale - which is the honest signal
        // ("this worker is not healthy right now") rather than a
        // heartbeat that would keep the indicator green.
        if ($mode === 'once') { break; }
        if ($shouldStop) { break; }
        sleep($pause);
        continue;
    }
    if ($dbFailureStreak > 0) {
        rv_worker_log('info', 'worker.db.recovered', [
            'failed_streak' => $dbFailureStreak,
            'reconnects'    => $db?->reconnects() ?? 0,
        ]);
        $dbFailureStreak = 0;
    }

    // One throttled beacon per poll iteration (the repository collapses
    // anything faster than 1/s, so `--sleep=0` cannot spam the DB).
    $presence->beat($workerId, $presenceState);

    // v1.8.39 - auto-recovery sweep. A worker that died mid-pipeline
    // (SIGKILL, OOM, host reboot, ...) is now automatically requeued
    // with a backoff, instead of pinning the job in `stale` until an
    // operator clicks Resume. Recovery runs before acquire so the
    // current process can immediately re-pick up its own crashed work.
    try {
        $recovered = $jobRepo->recoverStale($staleSec, $backoffSchedule, $autoRecoveryEnabled);
        if (($recovered['requeued'] ?? 0) > 0) {
            rv_worker_log('info', 'worker.stale.requeued', [
                'count'           => (int)$recovered['requeued'],
                'threshold_sec'   => $staleSec,
                'backoff_seconds' => $backoffSchedule,
            ]);
        }
        if (($recovered['exhausted'] ?? 0) > 0) {
            rv_worker_log('warn', 'worker.stale.exhausted', [
                'count'         => (int)$recovered['exhausted'],
                'threshold_sec' => $staleSec,
                'max_attempts'  => (int)$recoveryPolicy['max_attempts'],
            ]);
        }
        if (($recovered['stale'] ?? 0) > 0) {
            rv_worker_log('info', 'worker.reclaim.stale', [
                'count'         => (int)$recovered['stale'],
                'threshold_sec' => $staleSec,
                'note'          => 'auto_recovery_disabled',
            ]);
        }
    } catch (\Throwable $e) {
        rv_worker_log('warn', 'worker.reclaim.failed', ['message' => $e->getMessage()]);
    }

    try {
        $job = $jobRepo->acquireNext($workerId);
    } catch (\Throwable $e) {
        $job = null;
        // v1.8.47 - the connection can die between the pre-flight check
        // above and this point. Count it so the backoff below engages,
        // instead of paying a connect attempt on every poll iteration.
        $connectionLost = $e instanceof \PDOException && \App\Core\Database::isConnectionError($e);
        if ($connectionLost) {
            $dbFailureStreak++;
        }
        rv_worker_log('error', 'worker.acquire.failed', [
            'class'           => (new \ReflectionClass($e))->getShortName(),
            'message'         => $e->getMessage(),
            'connection_lost' => $connectionLost,
            'streak'          => $dbFailureStreak,
        ]);
    }
    if ($job === null) {
        // Idle cycle: pump due webhook retries before sleeping. No
        // subscriptions or no due rows -> a cheap no-op.
        try {
            $pumped = $webhookDispatcher->pumpDue();
            if ($pumped > 0) {
                rv_worker_log('debug', 'worker.webhook.pumped', ['count' => $pumped]);
            }
        } catch (\Throwable $e) {
            rv_worker_log('warn', 'worker.webhook.pump_failed', [
                'class'   => (new \ReflectionClass($e))->getShortName(),
                'message' => $e->getMessage(),
            ]);
        }
        if ($mode === 'once') {
            rv_worker_log('info', 'worker.queue.empty', ['mode' => 'once']);
            break;
        }
        if ($shouldStop) { break; }
        // v1.8.47 - a failed acquire on a dead connection waits on the
        // outage backoff, not on the (much shorter) poll interval.
        $idleSeconds = $dbFailureStreak > 0
            ? min($dbBackoffMaxSec, $dbBackoffBaseSec * (2 ** min($dbFailureStreak - 1, 5)))
            : $sleepSec;
        if ($idleSeconds > 0) { sleep($idleSeconds); }
        continue;
    }
    rv_worker_log('info', 'worker.acquired', [
        'job_id'     => $job->id,
        'session_id' => $job->sessionId,
        'priority'   => $job->priority,
        'worker_id'  => $workerId,
    ]);

    // Mark ourselves busy in the beacon so the Settings → Worker card
    // can answer "what is the worker doing right now" instead of just
    // "something is alive".
    $presenceState['current_job_id'] = $job->id;
    $presence->beat($workerId, $presenceState, true);

    try {
        // v1.8.47 - the executor absorbs database faults: it retries once
        // on a fresh connection, and returns the job to the queue (with
        // its retry budget intact) when the database is the problem.
        // Anything else propagates and is handled below exactly as before.
        $outcome = $executor->execute($job, $workerId);
    } catch (\Throwable $e) {
        $outcome     = null;
        $finalStatus = null;
        rv_worker_log('error', 'worker.run.uncaught', [
            'job_id'  => $job->id,
            'class'   => (new \ReflectionClass($e))->getShortName(),
            'message' => $e->getMessage(),
        ]);
        try {
            $jobRepo->recordError($job->id, [
                'class'   => (new \ReflectionClass($e))->getShortName(),
                'message' => \App\Core\Logger::sanitizeScalar($e->getMessage()),
                'at'      => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $jobRepo->updateStatus($job->id, \App\Jobs\JobStatus::Failed);
        } catch (\Throwable) {}
        $finalStatus = \App\Jobs\JobStatus::Failed;
        $presenceState['last_error_at']     = gmdate('Y-m-d H:i:s');
        $presenceState['last_error_reason'] = mb_substr(
            (new \ReflectionClass($e))->getShortName() . ': ' . \App\Core\Logger::sanitizeScalar($e->getMessage()),
            0,
            190,
        );
    }

    // v1.8.47 - the job could not run because the DATABASE was
    // unavailable. It is already back in the queue with a future
    // `next_run_at` and an UNCHANGED `attempts` value, so there is no
    // failed run to report and no webhook to fire.
    if ($outcome !== null && $outcome['status'] === null) {
        rv_worker_log('error', 'worker.job.requeued_infrastructure', [
            'job_id'            => $job->id,
            'session_id'        => $job->sessionId,
            'worker_id'         => $workerId,
            'requeued'          => $outcome['requeued'],
            'attempts_consumed' => 0,
            'retried_in_place'  => $outcome['retried'],
            'reconnects'        => $outcome['reconnects'],
            'losses'            => $outcome['losses'],
            'requeue_delay_s'   => $outcome['requeue_delay_s'],
            'message'           => $outcome['infrastructure_error'],
        ]);
        $presenceState['last_error_at']     = gmdate('Y-m-d H:i:s');
        $presenceState['last_error_reason'] = 'database_unavailable';
        $presenceState['current_job_id']    = null;
        $presence->beat($workerId, $presenceState, true);
        // The row carries a future `next_run_at` now, so acquireNext()
        // skips it. Pause anyway: with a deep queue we would otherwise
        // walk N jobs through one infrastructure failure each at full
        // speed, and on a pre-migration-012 schema there is no
        // `next_run_at` to skip on at all.
        if ($mode === 'once') { break; }
        if ($shouldStop) { break; }
        sleep($outcome['requeue_delay_s']);
        continue;
    }

    if ($outcome !== null) {
        $finalStatus = $outcome['status'] ?? \App\Jobs\JobStatus::Failed;
        if ($outcome['retried']) {
            // The run survived a lost connection on the second attempt.
            // Worth a warning: it means the database was restarted or
            // the socket was reaped, and the fix is the reconnect, not
            // the model.
            rv_worker_log('warn', 'worker.job.retried_after_db_loss', [
                'job_id'     => $job->id,
                'session_id' => $job->sessionId,
                'worker_id'  => $workerId,
                'status'     => $finalStatus->value,
                'reconnects' => $outcome['reconnects'],
                'losses'     => $outcome['losses'],
            ]);
        }
    }
    if ($finalStatus === null) {
        $finalStatus = \App\Jobs\JobStatus::Failed;
    }

    // v1.9.x - a job kind that ends without closing its session used to leave
    // a run History shows as running for ever. Close it here, and only when the
    // session still has no answer of its own.
    if (isset($sessions, $pdo) && $sessions instanceof \App\Storage\SessionRepository) {
        \App\Jobs\SessionSafetyNet::closeIfOrphaned(
            $job,
            $finalStatus,
            $sessions,
            static function (int $sid) use ($pdo): ?array {
                $stmt = $pdo->prepare('SELECT status, final_answer FROM council_sessions WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $sid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return is_array($row)
                    ? ['status' => (string)($row['status'] ?? ''), 'answer' => $row['final_answer'] ?? null]
                    : null;
            },
        );
    }
    
    rv_worker_log('info', 'worker.completed', [
        'job_id'     => $job->id,
        'session_id' => $job->sessionId,
        'status'     => $finalStatus->value,
    ]);

    // v1.8.43 - notify webhook subscribers about terminal run states.
    try {
        $webhookEvent = match ($finalStatus) {
            \App\Jobs\JobStatus::Completed => 'run.completed',
            \App\Jobs\JobStatus::Failed    => 'run.failed',
            \App\Jobs\JobStatus::Cancelled => 'run.cancelled',
            default                        => null,
        };
        if ($webhookEvent !== null) {
            $webhookDispatcher->dispatch($webhookEvent, [
                'run_id'     => 'run_' . $job->sessionId,
                'session_id' => $job->sessionId,
                'job_id'     => $job->id,
                'status'     => $finalStatus->value,
            ]);
        }
    } catch (\Throwable $e) {
        rv_worker_log('warn', 'worker.webhook.dispatch_failed', [
            'job_id'  => $job->id,
            'class'   => (new \ReflectionClass($e))->getShortName(),
            'message' => $e->getMessage(),
        ]);
    }

    $ranCount++;
    // Back to "idle" in the beacon: keep the row alive but clear the
    // busy marker, and publish the process-local completion counter.
    $presenceState['jobs_done']      = $ranCount;
    $presenceState['current_job_id'] = null;
    $presence->beat($workerId, $presenceState, true);
    if ($maxJobs > 0 && $ranCount >= $maxJobs) {
        rv_worker_log('info', 'worker.max_jobs.reached', ['max_jobs' => $maxJobs, 'ran' => $ranCount]);
        break;
    }
    if ($mode === 'once') { break; }
    if ($shouldStop) { break; }
} while (true);

// Graceful shutdown: remove OUR row. This is what makes "stopped" and
// "crashed" distinguishable - a clean exit leaves no presence row,
// while a SIGKILLed worker leaves a stale one for the operator to see.
$presenceForgotten = $presence->forget($workerId);

// v1.8.47 - do not report a clean stop we could not record, and do not
// let a degraded beacon pass silently. `forget()` needs the database: if
// it failed, the row is still there and will age into "crashed" even
// though we exited on purpose. That is exactly the kind of lie this
// block exists to prevent.
if (!$presenceForgotten || $presence->isDegraded()) {
    rv_worker_log('warn', 'worker.presence.degraded', [
        'worker_id'            => $workerId,
        'forget_succeeded'     => $presenceForgotten,
        'consecutive_failures' => $presence->consecutiveFailures(),
        'last_write_error'     => $presence->lastWriteError(),
        'note'                 => 'Presence could not be maintained; the beacon row may be stale '
                                . 'even though this process exited cleanly.',
    ]);
}

rv_worker_log('info', 'worker.stop', [
    'mode'       => $mode,
    'ran_count'  => $ranCount,
    'worker_id'  => $workerId,
    'reconnects' => $db?->reconnects() ?? 0,
    'db_losses'  => $db?->losses() ?? 0,
]);
exit(0);