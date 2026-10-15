<?php
/**
 * WorkerFeedsTest: offline checks for the v1.8.45 header worker-status
 * indicator feeds — the two NEW public endpoints
 *
 *   GET /api/health/worker/jobs
 *   GET /api/health/worker/diagnostics
 *
 * and the services behind them ({@see \App\Worker\WorkerJobFeed},
 * {@see \App\Worker\WorkerDiagnostics}), plus the wiring in index.php.
 *
 * Runs without PHPUnit, without MySQL and WITHOUT network access:
 *   php tests/WorkerFeedsTest.php
 *
 * Covered:
 *   1. Routing — both routes exist, are GET and PUBLIC (no admin gate),
 *      and the pre-existing /api/health/worker route is untouched.
 *   2. WorkerJobFeed — unfinished-before-recent selection, ordering,
 *      the 10-row cap, the per-job field contract, and the exact
 *      wait_s/run_s/total_s semantics for queued / running / finished
 *      rows.
 *   3. Secret hygiene of the job feed — no payload text, no session
 *      title, no user id, no result envelope, whatever the row holds.
 *   4. WorkerDiagnostics — last `kind=selftest` result only,
 *      `available:false` when there is none (not an error), check-list
 *      shape, and the absence of provider identities in the public
 *      detail while the admin dump keeps them.
 *   5. `derived` — reflects the passed snapshot field by field, with the
 *      render-ready problems and their copy/paste commands
 *      (db/schema.sql for a missing queue, systemctl start for a
 *      stopped worker).
 *   6. WorkerFeedController — 200 + the published JSON shape, limit
 *      clamping, and no admin token required.
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
use App\Core\Database;
use App\Http\Controllers\WorkerFeedController;
use App\Jobs\JobRepository;
use App\Jobs\JobStatus;
use App\Jobs\WorkerHealthService;
use App\Worker\WorkerDiagnostics;
use App\Worker\WorkerJobFeed;

// Env hygiene: never inherit an admin token from the parent process — the
// new endpoints must answer with it absent.
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_ENV['OPEN_CORUM_ADMIN_TOKEN']);
putenv('REVOAP_ADMIN_TOKEN');
putenv('OPEN_CORUM_ADMIN_TOKEN');
unset($_SERVER['HTTP_X_ADMIN_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'], $_GET['admin_token'], $_COOKIE[AdminAuth::COOKIE]);

$failures = 0;
$passes   = 0;
$report   = [];

function checkF(string $label, bool $cond, string $detail = ''): void
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

/**
 * Section banner. Buffered (never echoed immediately): the controller
 * assertions read http_response_code(), and in the CLI SAPI any output
 * before the call freezes the status at "already sent".
 */
function sectionF(string $title): void
{
    global $report;
    $report[] = '';
    $report[] = '== ' . $title . ' ==';
}

/** True when the key exists AND is explicitly null (?? cannot express this). */
function isNullF(array $row, string $key): bool
{
    return array_key_exists($key, $row) && $row[$key] === null;
}

/** Invoke a controller method and capture HTTP status + decoded JSON. */
function callF(callable $fn): array
{
    $body   = $fn();
    $status = http_response_code();
    http_response_code(200);
    $decoded = json_decode((string)$body, true);
    return ['status' => $status, 'raw' => (string)$body, 'json' => is_array($decoded) ? $decoded : []];
}

/**
 * Insert a row straight into the repository's in-memory mirror. The
 * public API cannot back-date a job, and these assertions are all about
 * timings, so the mirror is driven directly — the hydrate path
 * (listRecent/find) is the real one.
 *
 * @param array<string,mixed> $overrides
 */
function seedJobF(JobRepository $repo, int $id, JobStatus $status, array $overrides = []): void
{
    $now = time();
    $row = [
        'id'           => $id,
        'session_id'   => $overrides['session_id'] ?? ($id * 10),
        'user_id'      => $overrides['user_id'] ?? 7,
        'status'       => $status->value,
        'priority'     => $overrides['priority'] ?? 0,
        'attempts'     => $overrides['attempts'] ?? 0,
        'max_attempts' => $overrides['max_attempts'] ?? 3,
        'payload_json' => isset($overrides['payload'])
            ? json_encode($overrides['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null,
        'result_json'  => isset($overrides['result'])
            ? json_encode($overrides['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null,
        'error_json'   => null,
        'locked_by'    => $overrides['locked_by'] ?? null,
        'locked_at'    => null,
        'heartbeat_at' => $overrides['heartbeat_at'] ?? null,
        'next_run_at'  => $overrides['next_run_at'] ?? null,
        'created_at'   => $overrides['created_at'] ?? date('Y-m-d H:i:s', $now),
        'started_at'   => $overrides['started_at'] ?? null,
        'finished_at'  => $overrides['finished_at'] ?? null,
        'updated_at'   => $overrides['updated_at'] ?? ($overrides['finished_at'] ?? $overrides['started_at'] ?? null),
    ];
    $prop = new ReflectionProperty(JobRepository::class, 'memory');
    $rows = $prop->getValue($repo);
    $rows[$id] = $row;
    $prop->setValue($repo, $rows);
}

/** The feed runs without a StepRepository (no council_steps table offline), so `stage` stays null. */
Config::set([
    'app' => ['version' => '1.8.44', 'name' => 'RevoAp'],
    'connectors' => [
        'bothub' => [
            'active'   => true,
            'api_key'  => 'sk-bothub-super-secret-value',
            'base_url' => 'https://api.bothub.chat/v1',
        ],
        'openrouter' => [
            'active'   => true,
            'api_key'  => 'sk-openrouter-super-secret-value',
            'base_url' => 'https://openrouter.ai/api/v1',
        ],
        'zhipu' => [
            'active'   => false,
            'api_key'  => '',
            'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        ],
    ],
], __DIR__);

$now = time();
$ts  = static fn(int $agoSeconds): string => date('Y-m-d H:i:s', $now - $agoSeconds);

// =====================================================================
// 1. Routing: the two new public endpoints exist next to /api/health/worker
// =====================================================================
sectionF('Routes: public worker feeds');

$indexSrc = (string)file_get_contents(__DIR__ . '/../index.php');

checkF('index.php registers GET /api/health/worker/jobs',
    (bool)preg_match(
        '#\$router->get\(\'/api/health/worker/jobs\',\s*fn\(\): string => \$workerFeedCtrl->jobs\(\)\)#',
        $indexSrc,
    ));
checkF('index.php registers GET /api/health/worker/diagnostics',
    (bool)preg_match(
        '#\$router->get\(\'/api/health/worker/diagnostics\',\s*fn\(\): string => \$workerFeedCtrl->diagnostics\(\)\)#',
        $indexSrc,
    ));
checkF('the pre-existing GET /api/health/worker route is untouched',
    str_contains($indexSrc, "\$router->get('/api/health/worker', static function () use (\$workerHealth): string {"));
checkF('the new routes are registered after the snapshot route (no shadowing)',
    strpos($indexSrc, "'/api/health/worker',") < strpos($indexSrc, "'/api/health/worker/jobs',"));
checkF('the new routes carry no admin gate (no AdminAuth on those lines)',
    !preg_match('#/api/health/worker/(jobs|diagnostics)\'.*AdminAuth#', $indexSrc));
checkF('WorkerFeedController never consults AdminAuth (public by construction)',
    !str_contains(
        (string)file_get_contents(__DIR__ . '/../src/Http/Controllers/WorkerFeedController.php'),
        'AdminAuth',
    ));
checkF('the controller is wired to both services in index.php',
    str_contains($indexSrc, 'new WorkerJobFeed($jobRepo, $stepRepo, $db ?? null)')
    && str_contains($indexSrc, 'new WorkerDiagnostics($jobRepo, $workerHealth, $jobAuditRepo, $db ?? null)'));

// =====================================================================
// 2. WorkerJobFeed — selection, ordering, timings
// =====================================================================
sectionF('WorkerJobFeed: selection + timings');

$repo = new JobRepository();
seedJobF($repo, 1, JobStatus::Queued, ['created_at' => $ts(30)]);
seedJobF($repo, 2, JobStatus::Running, [
    'created_at' => $ts(120), 'started_at' => $ts(100), 'locked_by' => 'revoap-host-9001',
    'payload' => ['query' => 'TOP SECRET PROMPT TEXT', 'session_title' => 'My private session'],
    'user_id' => 4242,
]);
seedJobF($repo, 3, JobStatus::Completed, ['created_at' => $ts(600), 'started_at' => $ts(590), 'finished_at' => $ts(580)]);
seedJobF($repo, 4, JobStatus::Completed, ['created_at' => $ts(7200), 'started_at' => $ts(7190), 'finished_at' => $ts(7180)]);
seedJobF($repo, 5, JobStatus::Failed, ['created_at' => $ts(900), 'started_at' => $ts(890), 'finished_at' => $ts(880)]);
seedJobF($repo, 6, JobStatus::Paused, ['created_at' => $ts(400), 'started_at' => $ts(300), 'locked_by' => 'revoap-host-9001']);
seedJobF($repo, 7, JobStatus::ConsensusRequested, ['created_at' => $ts(50), 'started_at' => $ts(40), 'locked_by' => 'revoap-host-9001']);
seedJobF($repo, 8, JobStatus::Queued, [
    'created_at' => $ts(20), 'attempts' => 1, 'next_run_at' => gmdate('Y-m-d H:i:s', $now + 60),
]);

$feedSvc = new WorkerJobFeed($repo, null, null);
$feed    = $feedSvc->feed();

checkF('the envelope carries the published keys',
    ($feed['ok'] ?? null) === true
    && ($feed['jobs_table_available'] ?? null) === true
    && is_array($feed['jobs'] ?? null)
    && isset($feed['generated_at'], $feed['limit'], $feed['recent_window_s'], $feed['truncated']));
checkF('default limit is 10 and the window 1800s',
    ($feed['limit'] ?? 0) === WorkerJobFeed::MAX_LIMIT
    && ($feed['recent_window_s'] ?? 0) === WorkerJobFeed::DEFAULT_RECENT_WINDOW_SECONDS);

$ids = array_map(static fn(array $j): int => (int)$j['job_id'], $feed['jobs']);
checkF('every unfinished job is listed before any finished one',
    array_slice($ids, 0, 5) === [8, 7, 6, 2, 1], implode(',', $ids));
checkF('finished jobs are ordered newest-first after the unfinished ones',
    array_slice($ids, 5) === [5, 3], implode(',', $ids));
checkF('a finished job older than the window is excluded',
    !in_array(4, $ids, true));
checkF('the unfinished/recent counters agree with the returned list',
    ($feed['unfinished_returned'] ?? 0) === 5 && ($feed['recent_finished_returned'] ?? 0) === 2);
checkF('truncated=false while the backlog fits the cap', ($feed['truncated'] ?? true) === false);

$byId = [];
foreach ($feed['jobs'] as $job) { $byId[(int)$job['job_id']] = $job; }

$expectedKeys = [
    'job_id', 'session_id', 'status', 'priority', 'attempts', 'max_attempts', 'worker_id',
    'queued_at', 'started_at', 'finished_at', 'wait_s', 'run_s', 'total_s', 'stage', 'next_retry_at',
];
checkF('every job row exposes exactly the published field contract',
    array_keys($byId[2]) === $expectedKeys, implode(',', array_keys($byId[2])));

// queued (never started): wait = age so far, no run time
checkF('queued job: wait_s is the waiting time so far',
    ($byId[1]['wait_s'] ?? null) === 30 && isNullF($byId[1], 'run_s')
    && ($byId[1]['total_s'] ?? null) === 30,
    json_encode($byId[1]));
checkF('queued job: timestamps are the stored ones and no worker holds it',
    ($byId[1]['queued_at'] ?? '') === $ts(30)
    && isNullF($byId[1], 'started_at')
    && isNullF($byId[1], 'finished_at')
    && isNullF($byId[1], 'worker_id'));
checkF('queued job: stage is null when no step is running', isNullF($byId[1], 'stage'));
checkF('queued retry exposes attempts and next_retry_at',
    ($byId[8]['attempts'] ?? 0) === 1 && is_string($byId[8]['next_retry_at'] ?? null));

// running: wait fixed at start, run elapsed, holder named
checkF('running job: wait_s is start - queued, run_s is the elapsed run time',
    ($byId[2]['wait_s'] ?? null) === 20 && ($byId[2]['run_s'] ?? null) === 100
    && ($byId[2]['total_s'] ?? null) === 120,
    json_encode($byId[2]));
checkF('running job names the worker holding it',
    ($byId[2]['worker_id'] ?? '') === 'revoap-host-9001');
checkF('paused / consensus_requested rows keep their holder too',
    ($byId[6]['worker_id'] ?? '') === 'revoap-host-9001'
    && ($byId[7]['worker_id'] ?? '') === 'revoap-host-9001');

// finished: run = finished - started, wait = started - queued
checkF('finished job: run_s is the time it took and wait_s the queue wait',
    ($byId[3]['run_s'] ?? null) === 10 && ($byId[3]['wait_s'] ?? null) === 10
    && ($byId[3]['total_s'] ?? null) === 20,
    json_encode($byId[3]));
checkF('finished job: a stale lock is not reported as a holder',
    isNullF($byId[3], 'worker_id'));

// cap + clamping
$limitFeed = $feedSvc->feed(50);
checkF('?limit=50 is clamped to the 10-row ceiling',
    ($limitFeed['limit'] ?? 0) === WorkerJobFeed::MAX_LIMIT && count($limitFeed['jobs']) === 7);
$oneFeed = $feedSvc->feed(0);
checkF('a non-positive limit falls back to the default (never an empty list by accident)',
    ($oneFeed['limit'] ?? 0) === WorkerJobFeed::DEFAULT_LIMIT);
$windowed = $feedSvc->feed(10, 60);
checkF('a 60s window drops the 5-minute-old failure and keeps the queue',
    !in_array(5, array_map(static fn(array $j): int => (int)$j['job_id'], $windowed['jobs']), true));
$clamped = $feedSvc->feed(10, 999999);
checkF('a hostile ?window= is clamped to a day',
    ($clamped['recent_window_s'] ?? 0) === 86400);

// truncation flag
$capRepo = new JobRepository();
for ($i = 100; $i < 125; $i++) {
    seedJobF($capRepo, $i, JobStatus::Queued, ['created_at' => $ts(5)]);
}
$capFeed = (new WorkerJobFeed($capRepo, null, null))->feed();
checkF('the feed never exceeds 10 rows', count($capFeed['jobs']) === 10);
checkF('truncated=true when more rows qualified than the cap allows',
    ($capFeed['truncated'] ?? false) === true);

// table missing → empty list, not an error
$unreachable = new JobRepository(new Database('mysql:host=127.0.0.1;port=1;dbname=revoap_missing'));
$missingFeed = (new WorkerJobFeed($unreachable, null, null))->feed();
checkF('a missing/unreachable job table yields an empty feed with the flag down',
    ($missingFeed['jobs_table_available'] ?? true) === false && ($missingFeed['jobs'] ?? ['x']) === []);

// =====================================================================
// 3. Secret hygiene of the job feed
// =====================================================================
sectionF('WorkerJobFeed: secret hygiene');

$rawFeed = json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
checkF('the feed never carries the stored payload / query text',
    !str_contains($rawFeed, 'TOP SECRET PROMPT TEXT') && !str_contains($rawFeed, 'payload'));
checkF('the feed never carries a session title', !str_contains($rawFeed, 'My private session'));
checkF('the feed never carries a user id', !str_contains($rawFeed, '"user_id"') && !str_contains($rawFeed, '4242'));
checkF('the feed never carries a result envelope', !str_contains($rawFeed, 'result'));
checkF('the feed only carries the allow-listed keys',
    !preg_match('/"(query|title|prompt|answer|error|model|api_key)"/', $rawFeed));

// =====================================================================
// 4. WorkerDiagnostics — last self-test, masked providers
// =====================================================================
sectionF('WorkerDiagnostics: last self-test + provider masking');

$diagRepo = new JobRepository();
seedJobF($diagRepo, 10, JobStatus::Completed, [
    'created_at' => $ts(900), 'finished_at' => $ts(880),
    'payload'    => ['kind' => 'selftest', 'selftest' => ['probe_providers' => false]],
    'result'     => [
        'kind' => 'selftest', 'ok' => true, 'passed' => 5, 'failed' => 0, 'duration_ms' => 61,
        'checks' => [
            ['name' => 'job_roundtrip', 'ok' => true, 'detail' => 'job #10 write+read-back ok', 'ms' => 2.1],
            ['name' => 'providers_configured', 'ok' => true,
             'detail' => 'active: bothub, openrouter; key present: bothub, openrouter; network probe: skipped (no tokens spent)', 'ms' => 0.4],
        ],
    ],
]);
seedJobF($diagRepo, 11, JobStatus::Queued, ['created_at' => $ts(60)]);           // not a self-test
seedJobF($diagRepo, 12, JobStatus::Queued, [                                      // self-test still queued
    'created_at' => $ts(50), 'payload' => ['kind' => 'selftest'],
]);
seedJobF($diagRepo, 13, JobStatus::Failed, [                                      // newest self-test, result present, FAILED
    'created_at' => $ts(40), 'started_at' => $ts(35), 'finished_at' => $ts(30),
    'payload'    => ['kind' => 'selftest', 'selftest' => ['probe_providers' => true]],
    'result'     => [
        'kind' => 'selftest', 'ok' => false, 'passed' => 4, 'failed' => 1, 'duration_ms' => 1204,
        'checks' => [
            ['name' => 'job_roundtrip', 'ok' => true, 'detail' => 'job #13 write+read-back ok', 'ms' => 1.9],
            ['name' => 'paths_writable', 'ok' => false, 'detail' => 'cache=not_writable logs=ok', 'ms' => 3.3],
            ['name' => 'providers_configured', 'ok' => true,
             'detail' => 'active: bothub, openrouter; key present: bothub; network probe: skipped (no tokens spent); active without key: openrouter', 'ms' => 0.5],
            ['name' => 'providers_reachable', 'ok' => false,
             'detail' => 'bothub:api.bothub.chat:443 ok (12ms); openrouter:openrouter.ai:443 failed (timeout) — TCP connect only, no request sent, no tokens spent.', 'ms' => 3001.4],
        ],
    ],
]);

$healthSvc = new WorkerHealthService($diagRepo);
$diagSvc   = new WorkerDiagnostics($diagRepo, $healthSvc, null, null);
$diag      = $diagSvc->report();

checkF('the newest self-test with a result wins (older ones are ignored)',
    ($diag['available'] ?? false) === true && ($diag['job_id'] ?? 0) === 13);
checkF('the verdict is exposed verbatim',
    ($diag['ok'] ?? true) === false && ($diag['passed'] ?? 0) === 4
    && ($diag['failed'] ?? 0) === 1 && ($diag['duration_ms'] ?? 0) === 1204);
// The window is deliberately generous. `$ts(30)` stamps the fixture at
// seeding time and `age_s` is `time()` at report time minus that stamp, so
// the check measures wall clock across however long this file has been
// running. A 2-second allowance (the original `<= 32`) made it fail on a
// busy machine — reproduced by running this file alongside six SmokeTest
// processes, where `age_s` came out 33 and the whole file went red for a
// reason that has nothing to do with the code under test. What the check
// asserts is that `age_s` is derived from the row's own timestamp, which a
// 15-second allowance proves just as well.
checkF('`at` is the row timestamp and age_s is derived from it',
    ($diag['at'] ?? '') === $ts(30) && ($diag['age_s'] ?? 0) >= 30 && ($diag['age_s'] ?? 0) <= 45,
    json_encode(['at' => $diag['at'] ?? null, 'age' => $diag['age_s'] ?? null]));
checkF('every check keeps name/ok/detail/ms',
    ($diag['checks'][0]['name'] ?? '') === 'job_roundtrip'
    && ($diag['checks'][0]['ok'] ?? false) === true
    && ($diag['checks'][1]['ok'] ?? true) === false
    && ($diag['checks'][1]['detail'] ?? '') === 'cache=not_writable logs=ok'
    && ($diag['checks'][1]['ms'] ?? null) === 3.3);

$rawDiag = json_encode($diag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
checkF('the public diagnostics payload never names a provider',
    !str_contains($rawDiag, 'bothub') && !str_contains($rawDiag, 'openrouter')
    && !str_contains($rawDiag, 'api.bothub.chat') && !str_contains($rawDiag, 'openrouter.ai'));
checkF('providers_configured keeps the counts instead of the names',
    str_contains($rawDiag, 'active: 2 provider(s)')
    && str_contains($rawDiag, 'key present: yes')
    && str_contains($rawDiag, 'active without key: 1 provider(s)'));
checkF('providers_reachable keeps the tally instead of the targets',
    str_contains($rawDiag, 'probed 2 provider host(s): 1 reachable, 1 unreachable'));
checkF('the masked details survive re-encoding without provider leakage',
    !str_contains($rawDiag, 'sk-bothub-super-secret-value')
    && !str_contains($rawDiag, 'sk-openrouter-super-secret-value'));

// The admin dump is NOT affected: it reads the same result_json verbatim.
$adminDetail = (string)($diagRepo->find(13)?->result['checks'][2]['detail'] ?? '');
checkF('the stored (admin-visible) detail still carries the raw provider names',
    str_contains($adminDetail, 'bothub') && str_contains($adminDetail, 'openrouter'));

// A hostile stored detail cannot smuggle a connector name either.
$hostileRepo = new JobRepository();
seedJobF($hostileRepo, 21, JobStatus::Completed, [
    'created_at' => $ts(10), 'finished_at' => $ts(9),
    'payload'    => ['kind' => 'selftest'],
    'result'     => [
        'kind' => 'selftest', 'ok' => true, 'passed' => 1, 'failed' => 0, 'duration_ms' => 5,
        'checks'     => [['name' => 'providers_configured', 'ok' => true,
            'detail' => 'weird: bothub|openrouter|zhipu|api.bothub.chat|open.bigmodel.cn configured', 'ms' => 0.1]],
    ],
]);
$hostile = (new WorkerDiagnostics($hostileRepo, null, null, null))->report(null);
$rawHostile = json_encode($hostile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
checkF('an unrecognised providers_configured detail is reduced to a summary',
    !str_contains($rawHostile, 'bothub') && !str_contains($rawHostile, 'openrouter')
    && !str_contains($rawHostile, 'zhipu') && !str_contains($rawHostile, 'bigmodel'));
checkF('that summary still passes through the sanitizer',
    str_contains($rawHostile, 'provider configuration could not be summarized'));

// No self-test at all → available:false, not an error.
$emptyRepo = new JobRepository();
seedJobF($emptyRepo, 30, JobStatus::Queued, ['created_at' => $ts(5)]);
$noDiag = (new WorkerDiagnostics($emptyRepo, null, null, null))->report(null);
checkF('without a self-test the answer is available:false with empty checks',
    ($noDiag['available'] ?? true) === false && ($noDiag['checks'] ?? ['x']) === []
    && isNullF($noDiag, 'job_id') && isNullF($noDiag, 'ok') && isNullF($noDiag, 'passed')
    && isNullF($noDiag, 'failed') && isNullF($noDiag, 'duration_ms')
    && isNullF($noDiag, 'at') && isNullF($noDiag, 'age_s'));
checkF('and it still carries a derived block', is_array($noDiag['derived'] ?? null));

// selftest row without a usable result is treated as "no self-test"
$halfRepo = new JobRepository();
seedJobF($halfRepo, 40, JobStatus::Queued, ['created_at' => $ts(5), 'payload' => ['kind' => 'selftest']]);
checkF('a queued self-test (no result yet) does not invent a verdict',
    ((new WorkerDiagnostics($halfRepo, null, null, null))->report(null)['available'] ?? true) === false);

// =====================================================================
// 5. derived — reflects the passed snapshot
// =====================================================================
sectionF('WorkerDiagnostics: derived problems');

$healthySnap = [
    'jobs_table_available' => true, 'migration_applied' => true, 'presence_table_available' => true,
    'worker_status' => 'idle', 'stale_jobs' => 0, 'retry_exhausted' => 0,
    'retry_scheduled' => 0, 'next_retry_at' => null,
];
$okDerived = WorkerDiagnostics::derive($healthySnap, true);
checkF('a healthy snapshot has no problems and ok:true',
    ($okDerived['problems'] ?? ['x']) === [] && ($okDerived['ok'] ?? false) === true);
checkF('the derived scalars echo the snapshot verbatim',
    ($okDerived['jobs_table_available'] ?? false) === true
    && ($okDerived['migration_applied'] ?? false) === true
    && ($okDerived['presence_table_available'] ?? false) === true
    && ($okDerived['worker_status'] ?? '') === 'idle'
    && ($okDerived['stale_jobs'] ?? -1) === 0
    && ($okDerived['retry_exhausted'] ?? -1) === 0);

$brokenSnap = [
    'jobs_table_available' => false, 'migration_applied' => false, 'presence_table_available' => false,
    'worker_status' => 'migration_missing', 'stale_jobs' => 3, 'retry_exhausted' => 2,
    'retry_scheduled' => 4, 'next_retry_at' => '2026-09-20 12:00:00',
];
$badDerived = WorkerDiagnostics::derive($brokenSnap, false);
$codes = array_map(static fn(array $p): string => (string)$p['code'], $badDerived['problems']);
checkF('a missing queue reports the schema problem once (no duplicate variants)',
    in_array('jobs_table_missing', $codes, true)
    && !in_array('migration_not_applied', $codes, true)
    && !in_array('presence_table_missing', $codes, true));
checkF('the schema problem names db/schema.sql and carries the mysql command',
    str_contains($badDerived['problems'][0]['message'], 'db/schema.sql')
    && ($badDerived['problems'][0]['command'] ?? '') === 'mysql -u <user> -p <database> < db/schema.sql');
checkF('stale jobs and exhausted retries are reported from the snapshot counters',
    in_array('stale_jobs', $codes, true) && in_array('retry_exhausted', $codes, true)
    && ($badDerived['stale_jobs'] ?? 0) === 3 && ($badDerived['retry_exhausted'] ?? 0) === 2);
checkF('the queued-retry summary is exposed for the tooltip',
    ($badDerived['retry_scheduled'] ?? 0) === 4
    && ($badDerived['next_retry_at'] ?? '') === '2026-09-20 12:00:00');
checkF('a broken snapshot flips ok to false', ($badDerived['ok'] ?? true) === false);

$stoppedDerived = WorkerDiagnostics::derive([
    'jobs_table_available' => true, 'migration_applied' => true, 'presence_table_available' => true,
    'worker_status' => 'not_running', 'stale_jobs' => 0, 'retry_exhausted' => 0,
], true);
checkF('a stopped worker yields the exact start command',
    ($stoppedDerived['problems'][0]['code'] ?? '') === 'worker_not_running'
    && ($stoppedDerived['problems'][0]['command'] ?? '') === 'sudo systemctl start revoap-worker.service');

$staleDerived = WorkerDiagnostics::derive([
    'jobs_table_available' => true, 'migration_applied' => true, 'presence_table_available' => true,
    'worker_status' => 'stale', 'stale_jobs' => 0, 'retry_exhausted' => 0,
], true);
checkF('a stale heartbeat yields the restart + journal advice',
    ($staleDerived['problems'][0]['code'] ?? '') === 'worker_stale'
    && str_contains((string)($staleDerived['problems'][0]['command'] ?? ''), 'systemctl restart revoap-worker.service'));

$satelliteDerived = WorkerDiagnostics::derive($healthySnap, false);
checkF('a missing audit table is reported while the queue exists',
    ($satelliteDerived['problems'][0]['code'] ?? '') === 'events_table_missing'
    && ($satelliteDerived['problems'][0]['severity'] ?? '') === 'error');
$presenceDerived = WorkerDiagnostics::derive(
    ['jobs_table_available' => true, 'migration_applied' => true, 'presence_table_available' => false,
     'worker_status' => 'healthy', 'stale_jobs' => 0, 'retry_exhausted' => 0],
    true,
);
checkF('a missing presence table is a warning, not a hard error',
    ($presenceDerived['problems'][0]['code'] ?? '') === 'presence_table_missing'
    && ($presenceDerived['problems'][0]['severity'] ?? '') === 'warning');

// report() feeds the live snapshot into derived
$live = (new WorkerDiagnostics($repo, new WorkerHealthService($repo), null, null))->report();
checkF('report() derives from the live snapshot (queued work, no worker)',
    ($live['derived']['worker_status'] ?? '') !== ''
    && ($live['derived']['jobs_table_available'] ?? false) === true
    && is_array($live['derived']['problems'] ?? null));

// =====================================================================
// 6. WorkerFeedController — public, correct shape
// =====================================================================
sectionF('WorkerFeedController: public endpoints');

$ctrl = new WorkerFeedController(new WorkerJobFeed($repo, null, null), $diagSvc);

$jobsRes = callF(static fn(): string => $ctrl->jobs());
checkF('GET /api/health/worker/jobs answers 200 without an admin token',
    $jobsRes['status'] === 200 && ($jobsRes['json']['ok'] ?? false) === true);
checkF('the jobs response exposes the job list', is_array($jobsRes['json']['jobs'] ?? null)
    && count($jobsRes['json']['jobs']) === 7);

$_GET = ['limit' => '50'];
$clampedRes = callF(static fn(): string => $ctrl->jobs());
$_GET = ['limit' => 'not-a-number', 'window' => 'abc'];
$junkRes = callF(static fn(): string => $ctrl->jobs());
$_GET = [];
checkF('the controller clamps ?limit and ignores junk parameters',
    ($clampedRes['json']['limit'] ?? 0) === 10 && ($junkRes['json']['limit'] ?? 0) === 10);

$diagRes = callF(static fn(): string => $ctrl->diagnostics());
checkF('GET /api/health/worker/diagnostics answers 200 without an admin token',
    $diagRes['status'] === 200);
checkF('the diagnostics response carries the self-test block and derived',
    array_key_exists('available', $diagRes['json'])
    && array_key_exists('checks', $diagRes['json'])
    && array_key_exists('ok', $diagRes['json'])
    && array_key_exists('passed', $diagRes['json'])
    && array_key_exists('failed', $diagRes['json'])
    && array_key_exists('duration_ms', $diagRes['json'])
    && array_key_exists('at', $diagRes['json'])
    && array_key_exists('age_s', $diagRes['json'])
    && array_key_exists('job_id', $diagRes['json'])
    && is_array($diagRes['json']['derived'] ?? null));
checkF('the diagnostics response leaks no provider identity',
    !str_contains($diagRes['raw'], 'bothub') && !str_contains($diagRes['raw'], 'openrouter'));
checkF('the diagnostics response leaks no payload or key material',
    !str_contains($diagRes['raw'], 'sk-') && !str_contains($diagRes['raw'], 'payload'));

// A controller built on services whose database cannot be reached must
// still answer structured JSON (never a 500 HTML page).
$brokenDb = new Database('mysql:host=127.0.0.1;port=1;dbname=revoap_missing');
$exploded = new WorkerFeedController(
    new WorkerJobFeed(new JobRepository($brokenDb), null, null),
    new WorkerDiagnostics(new JobRepository($brokenDb), null, null, $brokenDb),
);
$fallbackJobs = callF(static fn(): string => $exploded->jobs());
$fallbackDiag = callF(static fn(): string => $exploded->diagnostics());
checkF('an unreachable database still yields a readable JSON envelope for jobs',
    $fallbackJobs['status'] === 200 && ($fallbackJobs['json']['jobs_table_available'] ?? true) === false);
checkF('and for diagnostics (available:false, no self-test)',
    $fallbackDiag['status'] === 200 && ($fallbackDiag['json']['available'] ?? true) === false);

sectionF('SQL path on a real engine (SQLite)');

// The deployment engine is MySQL, which this suite must not require. The
// two raw statements the services issue (the unfinished-first candidate
// query and the self-test marker query) are therefore executed against a
// throwaway SQLite database — a real PDO engine, no server, no network.
$sqliteDrivers = PDO::getAvailableDrivers();
if (!in_array('sqlite', $sqliteDrivers, true)) {
    checkF('pdo_sqlite present (skipped: raw-SQL checks not executed)', true);
} else {
    $sqliteFile = sys_get_temp_dir() . '/revoap-worker-feeds-' . bin2hex(random_bytes(4)) . '.sqlite';
    $sqlPdo = new PDO('sqlite:' . $sqliteFile);
    $sqlPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlPdo->exec(
        'CREATE TABLE revoap_jobs ('
        . 'id INTEGER PRIMARY KEY, session_id INTEGER, user_id INTEGER, status TEXT, priority INTEGER,'
        . ' attempts INTEGER, max_attempts INTEGER, payload_json TEXT, result_json TEXT, error_json TEXT,'
        . ' locked_by TEXT, locked_at TEXT, heartbeat_at TEXT, next_run_at TEXT,'
        . ' created_at TEXT, started_at TEXT, finished_at TEXT, updated_at TEXT)'
    );
    $insert = $sqlPdo->prepare(
        'INSERT INTO revoap_jobs (id, session_id, status, payload_json, result_json, created_at, started_at, finished_at, updated_at)'
        . ' VALUES (:id, :sid, :status, :payload, :result, :created, :started, :finished, :updated)'
    );
    $seedSql = static function (int $id, string $status, ?array $payload, ?array $result, int $createdAgo, ?int $startedAgo, ?int $finishedAgo) use ($insert, $ts): void {
        $insert->execute([
            'id'       => $id,
            'sid'      => $id * 10,
            'status'   => $status,
            'payload'  => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES),
            'result'   => $result === null ? null : json_encode($result, JSON_UNESCAPED_SLASHES),
            'created'  => $ts($createdAgo),
            'started'  => $startedAgo === null ? null : $ts($startedAgo),
            'finished' => $finishedAgo === null ? null : $ts($finishedAgo),
            'updated'  => $finishedAgo === null ? $ts($createdAgo) : $ts($finishedAgo),
        ]);
    };
    // 1: old queued, 2: recent running, 3: recent completed, 4: stale completed,
    // 5: finished self-test, 6: queued self-test (no result yet), 7: recent non-selftest result.
    $seedSql(1, 'queued', ['kind' => 'run'], null, 5000, null, null);
    $seedSql(2, 'running', ['kind' => 'run'], null, 100, 90, null);
    $seedSql(3, 'completed', ['kind' => 'run'], ['ok' => true], 400, 390, 380);
    $seedSql(4, 'completed', ['kind' => 'run'], ['ok' => true], 9000, 8990, 8980);
    $seedSql(5, 'completed', ['kind' => 'selftest'], ['kind' => 'selftest', 'ok' => true, 'passed' => 5, 'failed' => 0, 'duration_ms' => 40, 'checks' => []], 60, 55, 50);
    $seedSql(6, 'queued', ['kind' => 'selftest'], null, 30, null, null);
    $seedSql(7, 'completed', ['kind' => 'run'], ['ok' => true], 70, 65, 60);

    $sqlDatabase = Database::fromPdo($sqlPdo);
    $sqlRepo     = new JobRepository($sqlPdo);
    $sqlFeed     = new WorkerJobFeed($sqlRepo, null, $sqlDatabase);

    $candidateIds = new ReflectionMethod(WorkerJobFeed::class, 'candidateIds');
    $idsSql = $candidateIds->invoke($sqlFeed, 11, 1800);
    checkF('the candidate SQL returns unfinished rows first (newest first), then the recent window',
        $idsSql === [6, 2, 1, 7, 5, 3], json_encode($idsSql));
    checkF('the candidate SQL excludes terminal rows older than the window',
        !in_array(4, is_array($idsSql) ? $idsSql : [], true));
    checkF('the candidate SQL honours the bound LIMIT',
        $candidateIds->invoke($sqlFeed, 2, 1800) === [6, 2]);

    $sqlDiag    = new WorkerDiagnostics($sqlRepo, null, null, $sqlDatabase);
    $selfTestRow = new ReflectionMethod(WorkerDiagnostics::class, 'selfTestRow');
    $rowSql = $selfTestRow->invoke($sqlDiag);
    checkF('the marker SQL finds the newest self-test that has a result',
        ($rowSql['id'] ?? 0) === 5 && is_array($rowSql['result'] ?? null), json_encode($rowSql));
    checkF('the marker SQL skips a self-test that is still queued (no result yet)',
        ($rowSql['id'] ?? 0) !== 6);

    $reportSql = $sqlDiag->report([
        'jobs_table_available' => true, 'migration_applied' => true, 'presence_table_available' => true,
        'worker_status' => 'idle', 'stale_jobs' => 0, 'retry_exhausted' => 0,
    ]);
    checkF('the full diagnostics report reads the self-test through the SQL path',
        ($reportSql['available'] ?? false) === true && ($reportSql['job_id'] ?? 0) === 5
        && ($reportSql['ok'] ?? false) === true && ($reportSql['duration_ms'] ?? 0) === 40);

    $degraded = $sqlFeed->feed();
    checkF('an engine without SHOW TABLES degrades to an empty feed instead of throwing',
        ($degraded['jobs_table_available'] ?? true) === false && ($degraded['jobs'] ?? ['x']) === []);

    unset($sqlPdo, $sqlDatabase, $sqlRepo, $sqlFeed, $sqlDiag);
    @unlink($sqliteFile);
}

echo implode("\n", $report) . "\n";
echo "----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
