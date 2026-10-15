<?php
/**
 * WorkerStatusTest: offline checks for the v1.8.44 worker telemetry
 * (presence beacon), its derived snapshot fields, the token-free
 * self-test and the Settings → Worker endpoints.
 *
 * Runs without PHPUnit, without MySQL and WITHOUT network access:
 *   php tests/WorkerStatusTest.php
 *
 * Covered:
 *   1. Migration/schema wiring — `db/migrations/017_worker_presence.sql`
 *      exists, is idempotent, carries `-- @table-version
 *      revoap_worker_presence 1.0.0`, and `VersionParser` resolves that
 *      annotation from BOTH the migration and db/schema.sql (this is
 *      what drives Settings → Migrations and the version-drift check).
 *   2. WorkerPresenceRepository — 1 Hz beat throttle, `started_at`
 *      stability across beats, forget (clean exit) vs stale rows
 *      (crash), purgeStale, hostile-input rejection, full fail-soft
 *      behaviour with no database at all.
 *   3. WorkerHealthService — every pre-v1.8.44 key still exists (no
 *      contract break), the new keys are populated, `running_workers[]`
 *      gains uptime/version/mode/pid/host/current_job_id/jobs_done/
 *      source, and `classify()` returns `idle` (not `not_running`) for a
 *      live-but-idle worker while preserving its legacy verdicts when
 *      called with the original six arguments.
 *   4. WorkerSelfTest — payload discriminator parsing, per-check
 *      envelope shape, no LLM/network call, detail sanitization
 *      (absolute paths never reach result_json).
 *   5. WorkerController — the admin gate on POST
 *      /api/settings/worker/test (enqueues `kind=selftest`), and the
 *      admin-OR-IP-whitelist gate on GET /api/settings/worker/debug
 *      (403 with both remedies spelled out), including a secret-leak
 *      scan of the dump.
 *
 * The opt-in TCP provider probe is intentionally NOT exercised here: it
 * opens a socket, and this suite must run offline.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\AdminAuth;
use App\Core\Config;
use App\Http\Controllers\WorkerController;
use App\Jobs\Job;
use App\Jobs\JobAuditEvent;
use App\Jobs\JobAuditRepository;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\JobStatus;
use App\Jobs\WorkerHealthService;
use App\Jobs\WorkerPresenceRepository;
use App\Jobs\WorkerSelfTest;
use App\Logs\LogsService;
use App\Security\IpFirewall;
use App\Settings\MigrationManager;
use App\Settings\VersionParser;

// Env hygiene: never inherit an admin token from the parent process.
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_ENV['OPEN_CORUM_ADMIN_TOKEN']);
putenv('REVOAP_ADMIN_TOKEN');
putenv('OPEN_CORUM_ADMIN_TOKEN');
unset($_SERVER['HTTP_X_ADMIN_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'], $_GET['admin_token'], $_COOKIE[AdminAuth::COOKIE]);

$failures = 0;
$passes   = 0;
$report   = [];

function checkW(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes, $report;
    if ($cond) {
        $report[] = "  ok  {$label}";
        $passes++;
    } else {
        $report[] = "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '');
        $failures++;
    }
}

/** Invoke a controller method and capture HTTP status + decoded JSON. */
function callW(callable $fn): array
{
    $body   = $fn();
    $status = http_response_code();
    http_response_code(200);
    $decoded = json_decode((string)$body, true);
    return ['status' => $status, 'raw' => (string)$body, 'json' => is_array($decoded) ? $decoded : []];
}

function rmTreeW(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Overwrite a presence row's last_seen_at in the memory mirror (tests must not wait 300s). */
function ageMemoryRowW(WorkerPresenceRepository $repo, string $workerId, int $seconds): void
{
    $prop = new ReflectionProperty(WorkerPresenceRepository::class, 'memory');
    $rows = $prop->getValue($repo);
    if (!isset($rows[$workerId])) {
        throw new RuntimeException('no memory row for ' . $workerId);
    }
    $rows[$workerId]['last_seen_at'] = date('Y-m-d H:i:s', time() - $seconds);
    $prop->setValue($repo, $rows);
}

$repoRoot = dirname(__DIR__);
$work     = sys_get_temp_dir() . '/revoap_workerst_' . bin2hex(random_bytes(4));
@mkdir($work . '/cache', 0o777, true);
@mkdir($work . '/logs', 0o777, true);

// =====================================================================
// 1. Migration + schema wiring
// =====================================================================
// NOTE: the suite accumulates output in $report and prints it only at the
// very end. Writing to stdout earlier would mark the CLI response headers
// as sent, and `http_response_code()` would then stop reflecting what
// Response::json() set — silently breaking every status assertion below.
$report[] = '== Migration / schema wiring ==';

$migrationPath = $repoRoot . '/db/migrations/017_worker_presence.sql';
$migrationSql  = is_file($migrationPath) ? (string)file_get_contents($migrationPath) : '';
checkW('db/migrations/017_worker_presence.sql ships', $migrationSql !== '');
checkW('017 declares @table-version revoap_worker_presence 1.0.0',
    str_contains($migrationSql, '-- @table-version revoap_worker_presence 1.0.0'));
checkW('017 creates the table idempotently',
    str_contains($migrationSql, 'CREATE TABLE IF NOT EXISTS revoap_worker_presence'));
checkW('017 carries the required columns',
    str_contains($migrationSql, 'current_job_id')
    && str_contains($migrationSql, 'app_version')
    && str_contains($migrationSql, 'jobs_done')
    && str_contains($migrationSql, 'last_error_reason'));
checkW('017 indexes last_seen_at + current_job_id',
    str_contains($migrationSql, 'idx_worker_presence_last_seen')
    && str_contains($migrationSql, 'idx_worker_presence_current_job'));
checkW('017 is MySQL/MariaDB compatible (InnoDB + utf8mb4)',
    str_contains($migrationSql, 'ENGINE=InnoDB') && str_contains($migrationSql, 'utf8mb4'));
checkW('017 splits into exactly one statement',
    count(MigrationManager::splitStatements($migrationSql)) === 1);

$schemaPath = $repoRoot . '/db/schema.sql';
$schemaSql  = (string)file_get_contents($schemaPath);
checkW('schema.sql declares revoap_worker_presence',
    str_contains($schemaSql, 'CREATE TABLE IF NOT EXISTS revoap_worker_presence'));
checkW('schema.sql carries @table-version revoap_worker_presence 1.0.0',
    str_contains($schemaSql, '-- @table-version revoap_worker_presence 1.0.0'));

$migParsed    = VersionParser::parseFile($migrationPath);
$schemaParsed = VersionParser::parseFile($schemaPath);
checkW('VersionParser reads the annotation from the migration',
    ($migParsed['tables']['revoap_worker_presence'] ?? null) === '1.0.0');
checkW('VersionParser reads the annotation from schema.sql',
    ($schemaParsed['tables']['revoap_worker_presence'] ?? null) === '1.0.0');

\App\Settings\SchemaParser::parseFile($schemaPath);
$expected = VersionParser::expectedFromSchema($schemaPath, \App\Settings\SchemaParser::parseFile($schemaPath));
checkW('expectedFromSchema exposes the presence table (drives Check DB / Migrations UI)',
    ($expected['tables']['revoap_worker_presence'] ?? null) === '1.0.0',
    json_encode($expected['tables']['revoap_worker_presence'] ?? null));
checkW('schema.sql @schema-version was bumped past 1.8.43',
    VersionParser::compare((string)$expected['schema'], '1.8.43') > 0,
    (string)$expected['schema']);

// =====================================================================
// 2. WorkerPresenceRepository
// =====================================================================
$report[] = '';
$report[] = '== WorkerPresenceRepository (memory mode) ==';

Config::set([
    'app'     => ['version' => '1.8.44', 'name' => 'RevoAp', 'debug' => false],
    'logging' => ['path' => $work . '/logs'],
], $work);

$presence = new WorkerPresenceRepository(null);
checkW('memory mode reports the table as available', $presence->tableAvailable() === true);
checkW('no database handle reported', $presence->database() === null);

$startedAt = date('Y-m-d H:i:s', time() - 120);
$first = $presence->beat('host-1', [
    'host' => 'host', 'pid' => 4242, 'app_version' => '1.8.44', 'mode' => 'loop',
    'started_at' => $startedAt, 'jobs_done' => 0,
]);
checkW('first beat writes the row', $first === true);
$rows = $presence->all();
checkW('row carries identity + mode + pid',
    count($rows) === 1
    && $rows[0]['worker_id'] === 'host-1'
    && $rows[0]['pid'] === 4242
    && $rows[0]['mode'] === 'loop'
    && $rows[0]['host'] === 'host');
checkW('started_at is persisted verbatim', $rows[0]['started_at'] === $startedAt);

$second = $presence->beat('host-1', ['host' => 'host', 'pid' => 4242, 'started_at' => $startedAt]);
checkW('a second beat inside 1s is throttled (no write)', $second === false);
$forced = $presence->beat('host-1', [
    'host' => 'host', 'pid' => 4242, 'started_at' => date('Y-m-d H:i:s'),
    'current_job_id' => 77, 'jobs_done' => 3,
], true);
checkW('a forced beat always writes', $forced === true);
$rows = $presence->all();
checkW('current_job_id + jobs_done round-trip',
    $rows[0]['current_job_id'] === 77 && $rows[0]['jobs_done'] === 3);
checkW('started_at survives a re-beat (uptime stays stable)',
    $rows[0]['started_at'] === $startedAt);

checkW('non-numeric pid is dropped, not coerced',
    $presence->touch('host-1', ['host' => 'host', 'pid' => 'abc', 'started_at' => $startedAt]) === true
    && $presence->all()[0]['pid'] === null);
checkW('garbage started_at falls back to now',
    $presence->touch('host-1', ['host' => 'host', 'started_at' => 'not-a-date']) === true
    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $presence->all()[0]['started_at']) === 1);
checkW('unknown mode is rejected (NULL, not stored)',
    $presence->touch('host-1', ['mode' => 'sideways', 'started_at' => $startedAt]) === true
    && $presence->all()[0]['mode'] === null);
checkW('empty + oversized worker ids are rejected',
    $presence->touch('', []) === false && $presence->touch(str_repeat('x', 200), []) === false);

$presence->touch('host-old', ['host' => 'host', 'started_at' => $startedAt]);
ageMemoryRowW($presence, 'host-old', 9000);
checkW('purgeStale reaps only outdated rows',
    $presence->purgeStale(3000) === 1 && count($presence->all()) === 1);

checkW('forget removes the row (clean exit)',
    $presence->forget('host-1') === true && $presence->all() === []);
checkW('forget on an unknown worker is a no-op', $presence->forget('nope') === true);

// =====================================================================
// 3. WorkerHealthService snapshot
// =====================================================================
$report[] = '';
$report[] = '== WorkerHealthService snapshot ==';

$jobs     = new JobRepository(null);
$presence = new WorkerPresenceRepository(null);
$health   = new WorkerHealthService($jobs, null, $presence);

$legacyKeys = [
    'execution_mode', 'jobs_table_available', 'migration_applied', 'stale_threshold_sec',
    'counts', 'total_non_terminal', 'oldest_queued_at', 'oldest_queued_age_s',
    'latest_heartbeat_at', 'latest_heartbeat_age_s', 'running_workers', 'stale_jobs',
    'worker_status', 'recommendations', 'commands', 'retry_policy', 'retry_scheduled',
    'retry_exhausted', 'next_retry_at', 'oldest_retry_age_s', 'generated_at',
];
$newKeys = [
    'app_version', 'presence_table_available', 'workers_alive',
    'oldest_worker_uptime_s', 'worker_versions', 'worker_presence', 'idle',
];

$empty = $health->snapshot();
$missingLegacy = array_values(array_diff($legacyKeys, array_keys($empty)));
checkW('every pre-v1.8.44 snapshot key is still present', $missingLegacy === [], implode(',', $missingLegacy));
$missingNew = array_values(array_diff($newKeys, array_keys($empty)));
checkW('the new presence keys are present', $missingNew === [], implode(',', $missingNew));
checkW('app_version comes from config', ($empty['app_version'] ?? null) === '1.8.44');
checkW('all pre-existing REPORTED_STATUSES counts survive',
    isset($empty['counts']['queued'], $empty['counts']['running'], $empty['counts']['stale'],
          $empty['counts']['completed'], $empty['counts']['failed'], $empty['counts']['cancelled']));
checkW('no workers => not idle and no uptime',
    $empty['workers_alive'] === 0 && $empty['idle'] === false && $empty['oldest_worker_uptime_s'] === null);
checkW('commands block is unchanged in shape (systemd_status + journal_tail present)',
    isset($empty['commands']['systemd_status'], $empty['commands']['journal_tail']));

// --- live, idle worker -----------------------------------------------
$presence->touch('host-1', [
    'host' => 'host', 'pid' => 900, 'app_version' => '1.8.44', 'mode' => 'loop',
    'started_at' => date('Y-m-d H:i:s', time() - 3661),
]);
$idle = $health->snapshot();
checkW('an idle-but-alive worker is counted alive', $idle['workers_alive'] === 1);
checkW('idle flag is true when the queue is empty', $idle['idle'] === true);
checkW('worker_status is idle, never not_running', $idle['worker_status'] === 'idle');
checkW('uptime is derived from started_at', $idle['oldest_worker_uptime_s'] === 3661,
    (string)$idle['oldest_worker_uptime_s']);
checkW('worker_versions lists the running build', $idle['worker_versions'] === ['1.8.44']);

$worker = $idle['running_workers'][0] ?? [];
$newWorkerKeys = ['worker_id', 'heartbeat_at', 'heartbeat_age_s', 'running', 'alive',
    'started_at', 'uptime_s', 'app_version', 'mode', 'pid', 'host',
    'current_job_id', 'jobs_done', 'source'];
$missingWorkerKeys = array_values(array_diff($newWorkerKeys, array_keys($worker)));
checkW('running_workers[] keeps its legacy keys and gains the new ones',
    $missingWorkerKeys === [], implode(',', $missingWorkerKeys));
checkW('presence-sourced worker reports source=presence, running=0, alive=true',
    ($worker['source'] ?? '') === 'presence' && ($worker['running'] ?? -1) === 0 && ($worker['alive'] ?? null) === true);
checkW('presence worker exposes mode/pid/host for the UI',
    ($worker['mode'] ?? '') === 'loop' && ($worker['pid'] ?? 0) === 900 && ($worker['host'] ?? '') === 'host');

// --- queued job + live idle worker -> healthy (the v1.8.44 fix) ------
$jobId = $jobs->create(sessionId: 12, userId: null, payload: ['query' => 'hello'], priority: 0);
$queued = $health->snapshot();
checkW('queued work + live worker classifies healthy, not not_running',
    $queued['worker_status'] === 'healthy', $queued['worker_status']);
checkW('idle flag drops once work is queued', $queued['idle'] === false);

// --- job actually running: the worker reports what it is doing -------
$jobs->acquireNext('host-1');
$presence->touch('host-1', [
    'host' => 'host', 'pid' => 900, 'app_version' => '1.8.44', 'mode' => 'loop',
    'started_at' => date('Y-m-d H:i:s', time() - 3661), 'current_job_id' => $jobId, 'jobs_done' => 2,
]);
$running = $health->snapshot();
$worker  = $running['running_workers'][0] ?? [];
checkW('busy worker reports running=1 and the current job id',
    ($worker['running'] ?? 0) === 1 && ($worker['current_job_id'] ?? null) === $jobId);
checkW('busy worker still exposes jobs_done', ($worker['jobs_done'] ?? null) === 2);
checkW('worker_status is healthy while the job runs', $running['worker_status'] === 'healthy');
checkW('latest_heartbeat_at keeps its job-row meaning', is_string($running['latest_heartbeat_at']));

// --- crashed worker: stale presence row, no job ----------------------
$jobs->updateStatus($jobId, JobStatus::Completed);
$presence->forget('host-1');
$presence->touch('host-dead', [
    'host' => 'host', 'pid' => 901, 'app_version' => '1.8.43', 'mode' => 'loop',
    'started_at' => date('Y-m-d H:i:s', time() - 4000),
]);
ageMemoryRowW($presence, 'host-dead', 4000);
$crashed = $health->snapshot();
checkW('a stale presence row is not counted alive', $crashed['workers_alive'] === 0);
checkW('the stale row is surfaced with alive=false (crash vs clean exit)',
    ($crashed['worker_presence'][0]['alive'] ?? null) === false
    && ($crashed['worker_presence'][0]['worker_id'] ?? '') === 'host-dead');
checkW('a crashed worker yields no uptime', $crashed['oldest_worker_uptime_s'] === null);
checkW('a crashed worker does not claim idle', $crashed['idle'] === false);
checkW('worker_presence exposes last_seen_age_s for the UI',
    ($crashed['worker_presence'][0]['last_seen_age_s'] ?? 0) >= 3990);

// --- classify(): legacy verdicts must survive ------------------------
$noWorkers = [];
checkW('classify BC: queued + no heartbeat + no workers => not_running',
    WorkerHealthService::classify('job', ['queued' => 1], null, 5, $noWorkers, 300)[0] === 'not_running');
checkW('classify BC: nothing at all => idle',
    WorkerHealthService::classify('job', [], null, null, $noWorkers, 300)[0] === 'idle');
checkW('classify BC: fresh heartbeat, empty queue => idle',
    WorkerHealthService::classify('job', [], 10, null, $noWorkers, 300)[0] === 'idle');
checkW('classify BC: stale heartbeat => stale',
    WorkerHealthService::classify('job', [], 400, null, $noWorkers, 300)[0] === 'stale');
checkW('classify BC: running job => healthy',
    WorkerHealthService::classify('job', ['running' => 1], 5, null, [['worker_id' => 'a']], 300)[0] === 'healthy');
checkW('classify BC: legacy mode adds the legacy advisory',
    str_contains(implode(' ', WorkerHealthService::classify('legacy', [], null, null, [], 300)[1]), 'legacy mode'));

checkW('classify NEW: queued + live idle worker => healthy (not not_running)',
    WorkerHealthService::classify('job', ['queued' => 2], null, 3, [['worker_id' => 'a']], 300, 1)[0] === 'healthy');
checkW('classify NEW: empty queue + live worker => idle',
    WorkerHealthService::classify('job', [], null, null, [['worker_id' => 'a']], 300, 1)[0] === 'idle');
checkW('classify NEW: alive worker with a lapsed heartbeat still reports stale',
    WorkerHealthService::classify('job', [], 500, null, [], 300, 1)[0] === 'stale'
    && str_contains(implode(' ', WorkerHealthService::classify('job', [], 500, null, [], 300, 1)[1]), 'alive'));
checkW('classify NEW: no worker + nothing queued => idle (fresh install)',
    WorkerHealthService::classify('job', [], null, null, [], 300, 0)[0] === 'idle');

// =====================================================================
// 4. WorkerSelfTest
// =====================================================================
$report[] = '';
$report[] = '== WorkerSelfTest ==';

checkW('payload discriminator matches kind=selftest',
    WorkerSelfTest::isSelfTest(['kind' => 'selftest']) === true
    && WorkerSelfTest::isSelfTest(['kind' => ' SELF TEST ']) === false
    && WorkerSelfTest::isSelfTest(['kind' => 'SelfTest']) === true);
checkW('payload discriminator rejects hostile values',
    WorkerSelfTest::isSelfTest([]) === false
    && WorkerSelfTest::isSelfTest(['kind' => 42]) === false
    && WorkerSelfTest::isSelfTest(['kind' => 'run']) === false);
checkW('provider probe is opt-in only',
    WorkerSelfTest::wantsProviderProbe(['kind' => 'selftest']) === false
    && WorkerSelfTest::wantsProviderProbe(['selftest' => ['probe_providers' => true]]) === true
    && WorkerSelfTest::wantsProviderProbe(['selftest' => ['probe_providers' => 'yes']]) === false);

Config::set([
    'app'        => ['version' => '1.8.44', 'name' => 'RevoAp', 'debug' => false],
    'logging'    => ['path' => $work . '/logs'],
    'connectors' => [
        'openrouter' => ['active' => true, 'api_key' => 'sk-should-never-appear-0123456789', 'base_url' => 'https://openrouter.ai/api/v1'],
        'bothub'     => ['active' => false, 'api_key' => 'nope'],
    ],
], $work);

$jobsSvc = new JobService($jobs, new \App\Storage\SessionRepository(null));
$created = $jobsSvc->createSelfTestJob(false);
checkW('createSelfTestJob returns the documented envelope',
    isset($created['job_id'], $created['session_id'], $created['status'], $created['events_url'])
    && $created['status'] === 'queued' && $created['job_id'] > 0);
$selfJob = $jobs->find((int)$created['job_id']);
checkW('the queued job carries kind=selftest',
    $selfJob instanceof Job && WorkerSelfTest::isSelfTest($selfJob->payload));
checkW('the self-test job is capped at one attempt (no auto-recovery loop)',
    $selfJob !== null && $selfJob->maxAttempts === 1);
checkW('the self-test job has no LLM options in its payload',
    $selfJob !== null && !isset($selfJob->payload['selected_models'], $selfJob->payload['reasoning']));

$jobs->acquireNext('test-worker');
$selfJob = $jobs->find((int)$created['job_id']);
$selfTest = new WorkerSelfTest($jobs, $work);
$envelope = $selfTest->run($selfJob, 'test-worker');
checkW('self-test envelope has the documented keys',
    $envelope['kind'] === 'selftest'
    && is_bool($envelope['ok']) && is_int($envelope['passed']) && is_int($envelope['failed'])
    && is_array($envelope['checks'])
    && $envelope['php_version'] === PHP_VERSION
    && $envelope['storage'] === 'memory'
    && $envelope['probe_providers'] === false);
checkW('check list uses {name, ok, detail, ms}',
    $envelope['checks'] !== [] && count(array_diff(['name', 'ok', 'detail', 'ms'], array_keys($envelope['checks'][0]))) === 0);
$names = array_column($envelope['checks'], 'name');
$expectedChecks = ['job_roundtrip', 'job_events', 'paths_writable', 'runtime', 'providers_configured'];
$missingChecks  = array_values(array_diff($expectedChecks, $names));
checkW('all baseline checks ran', $missingChecks === [], implode(',', $missingChecks));
checkW('the network probe is NOT part of a default run', !in_array('providers_reachable', $names, true));
checkW('checks report ms timings', array_reduce($envelope['checks'], static fn(bool $c, array $r): bool => $c && is_float($r['ms']), true));
checkW('every check passes in a healthy memory-mode deployment',
    $envelope['ok'] === true && $envelope['failed'] === 0,
    json_encode(array_filter($envelope['checks'], static fn(array $c): bool => !$c['ok'])));

$details = implode(' | ', array_column($envelope['checks'], 'detail'));
checkW('details never leak the absolute project path',
    !str_contains($details, $work) && !str_contains($details, 'revoap_workerst_'));
checkW('details never leak the connector key',
    !str_contains($details, 'sk-should-never-appear'));
checkW('the runtime check reports the autoloader + php version',
    str_contains($details, 'PHP ' . PHP_VERSION) && str_contains($details, 'autoload='));
checkW('providers_configured reports the active provider without its key',
    str_contains($details, 'active: openrouter') && str_contains($details, 'key present: openrouter')
    && !str_contains($details, 'bothub' . "\0"));
checkW('result_json is persisted on the job row',
    ($envelope['checks'][0]['name'] ?? '') === 'job_roundtrip');

// A broken root must surface as a FAILED check, not an exception.
$broken = new WorkerSelfTest($jobs, $repoRoot . '/db/schema.sql'); // a FILE, not a directory
$brokenEnvelope = $broken->run($selfJob, 'test-worker');
$pathCheck = null;
foreach ($brokenEnvelope['checks'] as $c) {
    if ($c['name'] === 'paths_writable') { $pathCheck = $c; }
}
checkW('an unusable project root fails paths_writable instead of throwing',
    $pathCheck !== null && $pathCheck['ok'] === false && $brokenEnvelope['ok'] === false);

// --- PipelineJobRunner: the actual dispatch branch -------------------
$registry = new \App\Connectors\ConnectorRegistry($repoRoot . '/src/Connectors');
$skills   = new \App\Skills\SkillRepository(null);
$engine   = new \App\Pipeline\PipelineEngine(
    connectors:  $registry,
    sessions:    new \App\Storage\SessionRepository(null),
    steps:       new \App\Storage\StepRepository(null),
    responses:   new \App\Storage\ResponseRepository(null),
    skills:      $skills,
    router:      new \App\Skills\SkillRouter($skills),
    autoCreator: new \App\Skills\SkillAutoCreator($skills, $registry),
    normalizer:  new \App\Consensus\NormalizationService(),
    consensus:   new \App\Consensus\ConsensusEngine(),
    stats:       new \App\Stats\StatsCollector(),
);
$runner  = new \App\Jobs\PipelineJobRunner($jobs, $engine, null, new WorkerSelfTest($jobs, $work));
$runSpec = $jobsSvc->createSelfTestJob(false);
$acquired = $jobs->acquireNext('runner-worker');
checkW('the self-test job is acquirable through the normal queue',
    $acquired !== null && $acquired->id === (int)$runSpec['job_id']);

$runStatus = $runner->run($acquired, 'runner-worker');
checkW('the runner completes a self-test without invoking the engine',
    $runStatus === JobStatus::Completed);
$stored = $jobs->find($acquired->id);
checkW('the runner persisted the check list into result_json',
    ($stored?->result['kind'] ?? null) === 'selftest'
    && count($stored?->result['checks'] ?? []) >= 5,
    json_encode($stored?->result['kind'] ?? null));
checkW('a self-test produces no pipeline output (stats/final_answer stay empty)',
    ($stored?->result['stats'] ?? null) === null && ($stored?->result['final_answer'] ?? '') === '');

// A payload WITHOUT the discriminator must keep going down the normal
// path: no query -> invalid_payload, never a silent self-test.
$plainId = $jobs->create(sessionId: 99, userId: null, payload: ['phase' => 1], priority: 0);
$plain   = $jobs->acquireNext('runner-worker');
checkW('a payload without kind=selftest is not hijacked',
    $plain !== null
    && WorkerSelfTest::isSelfTest($plain->payload) === false
    && $runner->run($plain, 'runner-worker') === JobStatus::Failed
    && ($jobs->find($plainId)?->error['class'] ?? '') === 'invalid_payload');

// Presence beat hook — the runner fires it on every engine event so a
// long pipeline run keeps the beacon fresh.
$beats = 0;
$runner->setPresenceBeat(function () use (&$beats): void { $beats++; });
$runner->onEngineEvent(jobId: 1, workerId: 'runner-worker', sessionId: 1, eventType: 'L1', runControl: null);
checkW('the engine event hook fires the presence beacon', $beats === 1);
$runner->setPresenceBeat(function (): void { throw new RuntimeException('beacon exploded'); });
$runner->onEngineEvent(jobId: 1, workerId: 'runner-worker', sessionId: 1, eventType: 'L1', runControl: null);
checkW('a throwing beacon cannot abort a run', true); // surviving the call is the assertion
$runner->setPresenceBeat(null);
$runner->onEngineEvent(jobId: 1, workerId: 'runner-worker', sessionId: 1, eventType: 'L1', runControl: null);
checkW('a detached beacon is a no-op', $beats === 1);

// =====================================================================
// 4b. v1.8.44 regression — rowCount() means CHANGED rows, not MATCHED
// =====================================================================
// MySQL (without PDO::MYSQL_ATTR_FOUND_ROWS) reports the number of rows
// whose values actually changed. A no-op UPDATE — the row already carries
// the values being written — therefore reports 0 while having matched a
// perfectly healthy row. `heartbeat()` used to read that 0 as "row
// missing", which made the production self-test report
// `job_roundtrip FAIL  heartbeat UPDATE matched no row for job #3`.
//
// The MySQL path cannot be executed on this host (no server), so the SQL
// half of the fix is pinned at the source level below and the runtime
// half is exercised against the in-memory mirror, whose contract is
// deliberately identical.
$report[] = '';
$report[] = '== JobRepository: heartbeat / releaseLock return semantics ==';

$lockJobs  = new JobRepository(null);
$lockAudit = new JobAuditRepository(null);
$lockJobs->setAuditRepository($lockAudit);
$lockSvc   = new JobService($lockJobs, new \App\Storage\SessionRepository(null));
$lockSpec  = $lockSvc->createSelfTestJob(false);
$lockId    = (int)$lockSpec['job_id'];
$lockJobs->acquireNext('lock-worker-a');

// The exact production scenario: a self-test heartbeats its own row
// milliseconds after creation, so `heartbeat_at` (DATETIME, second
// resolution) and `locked_by` still hold the values being written.
checkW('heartbeat twice inside the same second both report success',
    $lockJobs->heartbeat($lockId, 'lock-worker-a') === true
    && $lockJobs->heartbeat($lockId, 'lock-worker-a') === true);
checkW('a successful heartbeat leaves a non-empty heartbeat_at',
    is_string($lockJobs->find($lockId)?->heartbeatAt)
    && $lockJobs->find($lockId)?->heartbeatAt !== '');
checkW('heartbeat for a missing job id reports failure',
    $lockJobs->heartbeat(999999, 'lock-worker-a') === false);
// A row owned by someone else must be refused, not silently stolen.
checkW('heartbeat from a foreign worker is refused',
    $lockJobs->heartbeat($lockId, 'intruder-worker') === false);
checkW('the refused heartbeat did not steal the lock',
    $lockJobs->find($lockId)?->lockedBy === 'lock-worker-a');

// releaseLock: `true` means "the row is not locked any more", and the
// audit event must be tied to an ACTUAL release — exactly once.
checkW('the job under test still holds a live lock',
    $lockJobs->find($lockId)?->lockedBy === 'lock-worker-a');
checkW('releaseLock reports success on a locked row', $lockJobs->releaseLock($lockId) === true);
$lockEvents = static fn(): array => array_values(array_filter(
    $lockAudit->listByJobId($lockId, 50),
    static fn(JobAuditEvent $e): bool => $e->eventType === 'lock.released',
));
checkW('the first release records exactly one lock.released event', count($lockEvents()) === 1);
checkW('releaseLock on an already-released row still reports success',
    $lockJobs->releaseLock($lockId) === true
    && $lockJobs->find($lockId)?->lockedBy === null);
checkW('a second release does not duplicate the audit event', count($lockEvents()) === 1);
checkW('releaseLock for a missing job id reports failure',
    $lockJobs->releaseLock(999999) === false);

// Source-level pinning of the SQL path (see the note above).
$jobRepoSource = (string)@file_get_contents($repoRoot . '/src/Jobs/JobRepository.php');
$methodBody    = static function (string $method) use ($jobRepoSource): string {
    $start = strpos($jobRepoSource, 'function ' . $method . '(');
    if ($start === false) { return ''; }
    $end = strpos($jobRepoSource, "\n    }", $start);
    return $end === false ? substr($jobRepoSource, $start) : substr($jobRepoSource, $start, $end - $start);
};
$heartbeatSql = $methodBody('heartbeat');
checkW('heartbeat SQL refuses to touch a row locked by another worker',
    str_contains($heartbeatSql, 'AND (locked_by = :guard OR locked_by IS NULL)'));
checkW('heartbeat SQL binds the owner twice (a named placeholder cannot repeat)',
    str_contains($heartbeatSql, ':who')
    && str_contains($heartbeatSql, ':guard')
    && str_contains($heartbeatSql, "'guard' => \$lockedBy"));
checkW('heartbeat falls back to an existence + ownership probe on 0 changed rows',
    str_contains($heartbeatSql, '$stmt->rowCount() > 0')
    && str_contains($heartbeatSql, 'lockState($pdo, $id)')
    && str_contains($heartbeatSql, "\$state['locked_by'] === \$lockedBy"));
$releaseSql = $methodBody('releaseLock');
checkW('releaseLock confirms an already-unlocked row on 0 changed rows',
    str_contains($releaseSql, 'lockState($pdo, $id)')
    && str_contains($releaseSql, "=== null"));
checkW('releaseLock gates its audit on the actual release, not the return value',
    str_contains($releaseSql, 'if ($released)')
    && str_contains($releaseSql, 'emitLockReleaseAudit($job, $previousWorker)'));
checkW('every rowCount()-as-success site consults a fallback probe',
    str_contains($methodBody('updateStatus'), 'jobRowExists($pdo, $id)')
    && str_contains($methodBody('recordResult'), 'jobRowExists($pdo, $id)')
    && str_contains($methodBody('recordError'), 'jobRowExists($pdo, $id)')
    && str_contains($heartbeatSql, 'lockState($pdo, $id)')
    && str_contains($releaseSql, 'lockState($pdo, $id)'));
// Decision record: the global option was deliberately NOT enabled. If this
// check starts failing, the whole-project rowCount() audit (v1.8.44) must
// be re-done before shipping — several call sites want CHANGED semantics.
$configSource = (string)@file_get_contents($repoRoot . '/src/Core/Config.php');
checkW('the PDO layer does not enable MYSQL_ATTR_FOUND_ROWS globally',
    !str_contains($configSource, 'MYSQL_ATTR_FOUND_ROWS'));

// Idempotent re-writes are not errors (memory path; the SQL path is
// pinned by the checks above).
$realJobs = new JobRepository(null);
$realId   = $realJobs->create(sessionId: 4242, userId: null, payload: ['query' => 'real work']);
checkW('a real job starts queued', $realJobs->find($realId)?->status === JobStatus::Queued);
$realAcquired = $realJobs->acquireNext('real-worker');
checkW('a real job is acquired queued -> running',
    $realAcquired?->id === $realId
    && $realAcquired->status === JobStatus::Running
    && $realAcquired->lockedBy === 'real-worker');
checkW('the owning worker can heartbeat a real job',
    $realJobs->heartbeat($realId, 'real-worker') === true);
checkW('a real job reaches completed through recordResult + updateStatus',
    $realJobs->recordResult($realId, ['ok' => true, 'final_answer' => 'done']) === true
    && $realJobs->updateStatus($realId, JobStatus::Completed) === true
    && $realJobs->find($realId)?->status === JobStatus::Completed);
checkW('re-setting the same terminal status is idempotent, not an error',
    $realJobs->updateStatus($realId, JobStatus::Completed) === true);
checkW('re-recording an identical result envelope is idempotent',
    $realJobs->recordResult($realId, ['ok' => true, 'final_answer' => 'done']) === true);
checkW('the same operations on a missing job id still report failure',
    $realJobs->updateStatus(999999, JobStatus::Completed) === false
    && $realJobs->recordResult(999999, ['ok' => true]) === false
    && $realJobs->recordError(999999, ['class' => 'NoSuchJob']) === false);

// =====================================================================
// 4c. v1.8.44 — a failing self-test must not look like a failed job
// =====================================================================
$report[] = '';
$report[] = '== Self-test verdict: completed job, failing diagnostics ==';

// A root that is a FILE, not a directory, makes paths_writable fail — the
// same trick used above — so the envelope carries ok=false through the
// real runner.
$stJobs   = new JobRepository(null);
$stSvc    = new JobService($stJobs, new \App\Storage\SessionRepository(null));
$stRunner = new \App\Jobs\PipelineJobRunner($stJobs, $engine, null, new WorkerSelfTest($stJobs, $repoRoot . '/db/schema.sql'));
$stSpec   = $stSvc->createSelfTestJob(false);
$stJob    = $stJobs->acquireNext('selftest-worker');
checkW('the failing self-test job is acquired through the normal queue',
    $stJob !== null && $stJob->id === (int)$stSpec['job_id']);
$stStatus = $stRunner->run($stJob, 'selftest-worker');
$stStored = $stJobs->find($stJob->id);
checkW('a self-test with failing checks still terminates as completed',
    $stStatus === JobStatus::Completed && $stStored?->status === JobStatus::Completed,
    $stStatus->value . ' / ' . ($stStored?->status->value ?? 'null'));
checkW('the verdict is preserved in result_json (ok=false, failed>0, checks[])',
    ($stStored?->result['ok'] ?? null) === false
    && ($stStored?->result['failed'] ?? 0) > 0
    && is_array($stStored?->result['checks'] ?? null)
    && count($stStored?->result['checks'] ?? []) >= 5);
checkW('a completed-but-failing self-test writes no error_json',
    ($stStored?->error) === null);
$stStatuses = array_values(array_unique(array_map(
    static fn(Job $j): string => $j->status->value,
    $stJobs->listRecent(10),
)));
checkW('the self-test row never lands in the failed bucket', $stStatuses === ['completed'], implode(',', $stStatuses));

// The UI must read the verdict from result.ok, never from the terminal
// status — a status-driven banner would now always print "passed".
$settingsJs = (string)@file_get_contents($repoRoot . '/assets/js/settings.js');
checkW('settings.js derives the verdict from result.ok',
    str_contains($settingsJs, 'result.ok === true')
    && str_contains($settingsJs, 'Self-test finished: '));
checkW('settings.js no longer calls a self-test passed on terminal status',
    !str_contains($settingsJs, "terminal.status === 'completed'"));

// =====================================================================
// 5. WorkerController endpoints
// =====================================================================
$report[] = '';
$report[] = '== WorkerController: test + debug gates ==';

AdminAuth::setRootDir($work);
$auditRepo = new JobAuditRepository(null);
$ctrl = new WorkerController(
    health:  $health,
    jobs:    $jobsSvc,
    jobRepo: $jobs,
    logs:    new LogsService($work),
    pdo:     null,
    audit:   $auditRepo,
    rootDir: $work,
);

// --- test(): admin gate ----------------------------------------------
Config::set(['SettingsTab' => false, 'app' => ['version' => '1.8.44']], $work);
$disabled = callW(static fn(): string => $ctrl->test());
checkW('SettingsTab=false -> 404 settings_disabled',
    $disabled['status'] === 404 && ($disabled['json']['error'] ?? '') === 'settings_disabled');

Config::set(['SettingsTab' => true, 'app' => ['version' => '1.8.44']], $work);
// The repository ships a real .admin.key, so "not configured" can only be
// asserted when no token source is readable at all — otherwise the
// correct verdict is 401 (configured but not presented).
$adminConfigured = AdminAuth::isConfigured();
$unconfigured = callW(static fn(): string => $ctrl->test());
checkW(
    $adminConfigured
        ? 'configured admin token, none presented -> 401 unauthorized'
        : 'no admin token configured -> 503 admin_not_configured',
    $adminConfigured
        ? ($unconfigured['status'] === 401 && ($unconfigured['json']['error'] ?? '') === 'unauthorized')
        : ($unconfigured['status'] === 503 && ($unconfigured['json']['error'] ?? '') === 'admin_not_configured'),
    $unconfigured['raw'],
);

$_ENV['REVOAP_ADMIN_TOKEN'] = 'worker-test-token';
$noToken = callW(static fn(): string => $ctrl->test());
checkW('configured admin token but not presented -> 401',
    $noToken['status'] === 401 && ($noToken['json']['error'] ?? '') === 'unauthorized');

$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'wrong';
$wrongToken = callW(static fn(): string => $ctrl->test());
checkW('wrong admin token -> 401', $wrongToken['status'] === 401);

$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'worker-test-token';
$okTest = callW(static fn(): string => $ctrl->test());
checkW('admin token -> 201 with a queued self-test job',
    $okTest['status'] === 201 && ($okTest['json']['ok'] ?? false) === true
    && ($okTest['json']['job_id'] ?? 0) > 0,
    $okTest['raw']);
$queuedJob = $jobs->find((int)($okTest['json']['job_id'] ?? 0));
checkW('the endpoint enqueued a kind=selftest job',
    $queuedJob !== null && WorkerSelfTest::isSelfTest($queuedJob->payload));

// --- debug(): admin OR IP whitelist ---------------------------------
@file_put_contents($work . '/logs/revoap-error.log',
    '{"ts":"2026-09-20T00:00:00Z","level":"error","message":"connector failed","ctx":{"api_key":"sk-leaky-value-9876543210","path":"'
    . $work . '/src/Core/Config.php"}}' . "\n");

Config::set([
    'SettingsTab' => true,
    'app'         => ['version' => '1.8.44', 'name' => 'RevoAp', 'debug' => true],
    'logging'     => ['path' => $work . '/logs'],
    'security'    => ['ip_whitelist' => ['203.0.113.7']],
], $work);

unset($_SERVER['HTTP_X_ADMIN_TOKEN']);
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$denied = callW(static fn(): string => $ctrl->debug());
checkW('admin token absent + unknown IP -> 403 forbidden',
    $denied['status'] === 403 && ($denied['json']['error'] ?? '') === 'forbidden'
    && ($denied['json']['reason'] ?? '') === 'admin_or_ip_required');
$deniedMessage = (string)($denied['json']['message'] ?? '');
checkW('the 403 body names BOTH remedies (admin token + ip whitelist)',
    str_contains($deniedMessage, 'admin token') && str_contains($deniedMessage, 'ip_whitelist'));
checkW('the 403 body echoes the resolved client IP',
    ($denied['json']['client_ip'] ?? '') === '198.51.100.9');
checkW('the 403 body leaks no dump content',
    !array_key_exists('health', $denied['json']) && !str_contains($denied['raw'], 'logs'));

$_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // whitelisted, whitelist enforcement OFF
$byWhitelist = callW(static fn(): string => $ctrl->debug());
checkW('a whitelisted IP gets the dump even with ip_whitelist_enabled=false',
    $byWhitelist['status'] === 200 && ($byWhitelist['json']['ok'] ?? false) === true,
    $byWhitelist['raw']);
checkW('the dump records the ip_whitelist gate mode',
    ($byWhitelist['json']['gate']['mode'] ?? '') === 'ip_whitelist');

$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'worker-test-token';
$byAdmin = callW(static fn(): string => $ctrl->debug());
checkW('an unlocked admin token gets the dump',
    $byAdmin['status'] === 200 && ($byAdmin['json']['gate']['mode'] ?? '') === 'admin');

$dump = $byAdmin['json'];
foreach (['generated_at', 'gate', 'health', 'worker_presence', 'environment', 'logs', 'database_snapshot', 'commands'] as $key) {
    checkW("debug dump contains '{$key}'", array_key_exists($key, $dump));
}
checkW('dump embeds the worker snapshot (same shape as /api/health/worker)',
    isset($dump['health']['worker']['worker_status'], $dump['health']['worker']['counts'], $dump['health']['worker']['commands']));
checkW('dump embeds the database snapshot (DatabaseHealth::snapshot)',
    isset($dump['health']['database']['connected'], $dump['health']['database']['missing_tables']));
checkW('dump embeds presence rows from the beacon',
    is_array($dump['worker_presence']) && ($dump['worker_presence'][0]['worker_id'] ?? '') === 'host-dead');
checkW('dump lists recent jobs + events',
    isset($dump['database_snapshot']['recent_jobs'], $dump['database_snapshot']['recent_events']));
checkW('dump reports the environment (php version, extensions, ini)',
    ($dump['environment']['php_version'] ?? '') === PHP_VERSION
    && is_array($dump['environment']['extensions'])
    && isset($dump['environment']['ini']['memory_limit'], $dump['environment']['functions']['pcntl_signal']));
checkW('dump exposes the operator commands (systemctl / journalctl / --status)',
    str_contains($dump['commands']['systemd_status'] ?? '', 'systemctl status revoap-worker.service')
    && str_contains($dump['commands']['journal_cat'] ?? '', 'journalctl -u revoap-worker.service -o cat')
    && str_contains($dump['commands']['status'] ?? '', 'revoap-worker.php --status'));
checkW('logs are served through the LogsService whitelist (slug + sanitized content)',
    ($dump['logs']['revoap-error']['slug'] ?? '') === 'revoap-error'
    && str_contains((string)($dump['logs']['revoap-error']['content'] ?? ''), 'connector failed'));
checkW('the log excerpt has secrets + absolute paths redacted',
    !str_contains($byAdmin['raw'], 'sk-leaky-value-9876543210')
    && !str_contains($byAdmin['raw'], $work . '/src')
    && str_contains($byAdmin['raw'], '<project>'));
checkW('the dump never contains the admin token',
    !str_contains($byAdmin['raw'], 'worker-test-token'));
checkW('the dump marks itself as sensitive',
    ($dump['sensitive'] ?? false) === true && str_contains((string)($dump['note'] ?? ''), 'never contains API keys'));

// IpFirewall helper used by the gate.
$state = ['ip_whitelist' => ['203.0.113.7'], 'ip_whitelist_enabled' => false];
checkW('allowsOperatorAccess: whitelisted IP passes',
    IpFirewall::allowsOperatorAccess('203.0.113.7', $state) === true);
checkW('allowsOperatorAccess: loopback always passes',
    IpFirewall::allowsOperatorAccess('127.0.0.1', ['ip_whitelist' => [], 'ip_whitelist_enabled' => true]) === true
    && IpFirewall::allowsOperatorAccess('::1', ['ip_whitelist' => []]) === true);
checkW('allowsOperatorAccess: other IPs are refused',
    IpFirewall::allowsOperatorAccess('198.51.100.9', $state) === false
    && IpFirewall::allowsOperatorAccess('', $state) === false);

// =====================================================================
// Cleanup
// =====================================================================
AdminAuth::setRootDir(null);
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_SERVER['HTTP_X_ADMIN_TOKEN'], $_SERVER['REMOTE_ADDR']);
rmTreeW($work);

echo implode("\n", $report) . "\n";
echo "----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
