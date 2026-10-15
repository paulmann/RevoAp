<?php
/**
 * WorkerCodeStateTest: offline coverage for v1.8.51's "the worker is
 * running stale code" detector, the failure text it adds, and the banner
 * the Settings → Worker card renders for it.
 *
 * Why the detector exists (the production incident): a long-lived worker
 * keeps the PHP classes it loaded at start-up, so a freshly deployed
 * caller can meet an old callee inside one process — PHP loads a class
 * once per process and never reloads it. The mix has no syntax error
 * anywhere and no test could provoke it; it surfaced only as
 * `Call to undefined method App\Core\RuntimeConfig::updateOrFail()`, with
 * nothing in the UI distinguishing "the worker is alive" from "the worker
 * is alive and running last week's code".
 *
 * Covered here:
 *
 *   1. `WorkerHealthService::codeState()` — `stale` on a version
 *      mismatch, `stale` when a code file is newer than the process start,
 *      `current` when both signals agree, `unknown` with no presence row
 *      and `unknown` when the beacon proves nothing either way.
 *   2. `WorkerHealthService::scanCodeMtimes()` — what it walks, and the
 *      in-process TTL cache (a file created between two scans is invisible
 *      until the cache expires or is dropped), with the measured cost.
 *   3. The snapshot wiring: the four keys reach `snapshot()` on every exit
 *      path, including the `migration_missing` early return.
 *   4. `PipelineJobRunner::staleCodeHint()` and `fail()` — an
 *      "undefined method/function" failure keeps its original text and
 *      gains the restart advice; an ordinary failure gains nothing.
 *   5. The Settings → Worker card itself, driven through the real
 *      assets/js/settings.js in the stub DOM
 *      (tests/harness/settings-worker.mjs).
 *
 * Runs without PHPUnit, MySQL or network access:
 *   php tests/WorkerCodeStateTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Jobs\JobRepository;
use App\Jobs\PipelineJobRunner;
use App\Jobs\WorkerHealthService;
use App\Jobs\WorkerPresenceRepository;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failures++;
    }
}

/** A throwaway project root holding the files the scan looks at. */
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-code-state-' . bin2hex(random_bytes(4));
@mkdir($root . '/src/Jobs', 0o775, true);
@mkdir($root . '/src/Core', 0o775, true);
file_put_contents($root . '/src/Jobs/WorkerHealthService.php', "<?php\n");
file_put_contents($root . '/src/Core/RuntimeConfig.php', "<?php\n");
file_put_contents($root . '/index.php', "<?php\n");
file_put_contents($root . '/config.php', "<?php\n");
// A file the scan must IGNORE, so "newest" cannot be a stray artefact.
file_put_contents($root . '/src/Jobs/README.md', 'not php');

$old = time() - 7200;
$new = time();
foreach ([
    $root . '/src/Jobs/WorkerHealthService.php',
    $root . '/src/Core/RuntimeConfig.php',
    $root . '/index.php',
    $root . '/config.php',
] as $file) {
    @touch($file, $old);
}

$config = [
    'app'     => ['name' => 'RevoAp', 'version' => '1.8.51', 'debug' => true, 'timezone' => 'UTC'],
    'logging' => ['path' => $root . '/logs'],
];
file_put_contents(
    $root . '/config.php',
    "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n",
);
Config::load($root . '/config.php', $root);
@touch($root . '/config.php', $old);
WorkerHealthService::resetCodeScanCache();

/** A normalized presence row, as {@see WorkerHealthService} renders it. */
function presenceRow(array $overrides = []): array
{
    return $overrides + [
        'worker_id'   => 'host-1-4242',
        'app_version' => '1.8.51',
        'started_at'  => gmdate('Y-m-d H:i:s'),
        'alive'       => true,
    ];
}

// =====================================================================
echo "== the code scan: what it looks at ==\n";
// =====================================================================
$scan = WorkerHealthService::scanCodeMtimes($root);
check('the scan reports the newest code file', $scan['newest_file'] !== null, var_export($scan, true));
check('it walks the four PHP files and ignores the rest', $scan['scanned'] === 4, (string)$scan['scanned']);
check('it only considers files under src/ , index.php and config.php',
    in_array($scan['newest_file'], [
        'src/Jobs/WorkerHealthService.php',
        'src/Core/RuntimeConfig.php',
        'index.php',
        'config.php',
    ], true),
    (string)$scan['newest_file']);
check('the newest mtime is the newest among them',
    $scan['newest_mtime'] === $old, (string)$scan['newest_mtime'] . ' vs ' . (string)$old);
check('the measured cost of one cold scan is reported', $scan['seconds'] > 0.0, 'unmeasured');
printf("     (cold scan of %d file(s): %.3f ms; the real tree: ", $scan['scanned'], $scan['seconds'] * 1000);
$realScan = WorkerHealthService::scanCodeMtimes(dirname(__DIR__));
printf("%d files in %.2f ms)\n", $realScan['scanned'], $realScan['seconds'] * 1000);
check('a cold scan of this repository stays far below a request budget',
    $realScan['seconds'] < 2.0, sprintf('%.3f s', $realScan['seconds']));

// The mtime of the class that ran the scan is by definition never newer
// than the deployed tree, so "current" cannot be decided by it alone: the
// check below drives both signals explicitly.

// =====================================================================
echo "\n== code_state: stale, current, unknown ==\n";
// =====================================================================
check('CODE_SCAN_TTL_SECONDS is a short, stated TTL',
    WorkerHealthService::CODE_SCAN_TTL_SECONDS === 10, (string)WorkerHealthService::CODE_SCAN_TTL_SECONDS);

$noRows = WorkerHealthService::codeState([]);
check('no presence row at all → unknown', $noRows['state'] === 'unknown', $noRows['state']);
check('and the reason says why nothing can be compared',
    str_contains((string)$noRows['reason'], 'presence row'), (string)$noRows['reason']);

// (a) the beacon says it was started on a different build.
$versionStale = WorkerHealthService::codeState([presenceRow(['app_version' => '1.8.44'])]);
check('a presence version that differs from app.version → stale',
    $versionStale['state'] === 'stale', $versionStale['state']);
check('the reason names both versions',
    str_contains((string)$versionStale['reason'], '1.8.44')
    && str_contains((string)$versionStale['reason'], '1.8.51'),
    (string)$versionStale['reason']);
check('the reason names the worker that is behind',
    str_contains((string)$versionStale['reason'], 'host-1-4242'), (string)$versionStale['reason']);

// (b) a code file changed after the process started.
$startedBefore = gmdate('Y-m-d H:i:s', $new - 3600);
@touch($root . '/src/Core/RuntimeConfig.php', $new);
WorkerHealthService::resetCodeScanCache();
$mtimeStale = WorkerHealthService::codeState([presenceRow(['started_at' => $startedBefore])]);
check('a code file newer than the process start → stale',
    $mtimeStale['state'] === 'stale', $mtimeStale['state']);
check('the reason names the file that is newer',
    str_contains((string)$mtimeStale['reason'], 'src/Core/RuntimeConfig.php'),
    (string)$mtimeStale['reason']);
check('the reason names the process start',
    str_contains((string)$mtimeStale['reason'], $startedBefore), (string)$mtimeStale['reason']);
check('the snapshot exposes the newest file and its time',
    $mtimeStale['newest_file'] === 'src/Core/RuntimeConfig.php'
    && $mtimeStale['newest_at'] === gmdate('Y-m-d\TH:i:s\Z', $new),
    (string)$mtimeStale['newest_file'] . ' @ ' . (string)$mtimeStale['newest_at']);

// The process started AFTER the newest file: nothing is stale.
$startedAfter = gmdate('Y-m-d H:i:s', $new + 60);
WorkerHealthService::resetCodeScanCache();
$current = WorkerHealthService::codeState([presenceRow(['started_at' => $startedAfter])]);
check('a process started after the newest file → current',
    $current['state'] === 'current', $current['state']);
check('current carries no stale reason', $current['reason'] === null, var_export($current['reason'], true));

// A beacon that proves nothing either way is not dressed up as "current".
$inconclusive = WorkerHealthService::codeState([
    ['worker_id' => 'host-1-4242', 'app_version' => null, 'started_at' => null],
]);
check('a beacon with no version and no start time → unknown',
    $inconclusive['state'] === 'unknown', $inconclusive['state']);
check('and says what is missing',
    str_contains((string)$inconclusive['reason'], 'neither an app version nor a start time'),
    (string)$inconclusive['reason']);

// One worker behind is enough, even beside a healthy one.
$mixed = WorkerHealthService::codeState([
    presenceRow(['worker_id' => 'host-1-1', 'started_at' => $startedAfter]),
    presenceRow(['worker_id' => 'host-1-2', 'app_version' => '1.8.44', 'started_at' => $startedAfter]),
]);
check('one stale worker among healthy ones is still reported',
    $mixed['state'] === 'stale', $mixed['state']);

// An empty app.version (Config not loaded) must not turn into "stale".
Config::set(['app' => ['name' => 'RevoAp']] + $config, $root);
WorkerHealthService::resetCodeScanCache();
$noVersion = WorkerHealthService::codeState([presenceRow(['started_at' => $startedAfter])]);
check('an unloaded app.version cannot produce a false stale',
    $noVersion['state'] === 'current', $noVersion['state']);
// Config::load() is a no-op once a tree is set (its "load once" contract),
// so the deployed version is restored through set() — the same tree the
// file holds.
Config::set($config, $root);
@touch($root . '/config.php', $old);
WorkerHealthService::resetCodeScanCache();

// =====================================================================
echo "\n== the scan is cached for the poller, and the cache is honest ==\n";
// =====================================================================
WorkerHealthService::resetCodeScanCache();
$first = WorkerHealthService::scanCodeMtimes($root);
$second = WorkerHealthService::scanCodeMtimes($root);
check('the first scan is a real walk', $first['cached'] === false);
check('the second call inside the TTL is served from the cache',
    $second['cached'] === true, var_export($second['cached'], true));
check('the cached answer is the same answer',
    $second['newest_mtime'] === $first['newest_mtime']
    && $second['newest_file'] === $first['newest_file'],
    json_encode($second));

// A deploy that happens inside the TTL is invisible until the cache drops
// — the trade the TTL explicitly makes (at most one poll's worth of lag).
$deployed = $root . '/src/Jobs/FreshlyDeployed.php';
file_put_contents($deployed, "<?php\n");
@touch($deployed, time() + 30);
$duringTtl = WorkerHealthService::scanCodeMtimes($root);
check('a file deployed inside the TTL does not appear yet',
    $duringTtl['newest_file'] !== 'src/Jobs/FreshlyDeployed.php', (string)$duringTtl['newest_file']);
WorkerHealthService::resetCodeScanCache();
$afterTtl = WorkerHealthService::scanCodeMtimes($root);
check('after the cache drops the new file is the newest one',
    $afterTtl['newest_file'] === 'src/Jobs/FreshlyDeployed.php', (string)$afterTtl['newest_file']);
@unlink($deployed);
WorkerHealthService::resetCodeScanCache();
@touch($root . '/src/Core/RuntimeConfig.php', $new);

// =====================================================================
echo "\n== the snapshot carries the verdict on every exit path ==\n";
// =====================================================================
$presence = new WorkerPresenceRepository(null);
$presence->beat('host-1-4242', [
    'host'        => 'host-1',
    'pid'         => 4242,
    'app_version' => '1.8.44',
    'mode'        => 'loop',
    'started_at'  => $startedAfter,
], true);

$service = new WorkerHealthService(new JobRepository(null), null, $presence);
$snapshot = $service->snapshot();
check('the snapshot reports code_state', ($snapshot['code_state'] ?? '') === 'stale', json_encode($snapshot['code_state'] ?? null));
check('it carries the human-readable reason',
    trim((string)($snapshot['code_stale_reason'] ?? '')) !== '',
    json_encode($snapshot['code_stale_reason'] ?? null));
check('it carries the newest file and its time',
    ($snapshot['code_newest_file'] ?? null) !== null && ($snapshot['code_newest_at'] ?? null) !== null,
    json_encode([$snapshot['code_newest_file'] ?? null, $snapshot['code_newest_at'] ?? null]));
check('the pre-existing keys are untouched by the addition',
    array_key_exists('worker_status', $snapshot) && array_key_exists('app_version', $snapshot)
    && array_key_exists('running_workers', $snapshot));

// The early `migration_missing` return must describe the code too: that is
// the state a half-installed host is in while an operator debugs it.
$noQueue = new WorkerHealthService(new JobRepository(null))->snapshot();
check('a missing queue table does not drop the code verdict',
    array_key_exists('code_state', $noQueue) && $noQueue['code_state'] === 'unknown',
    json_encode($noQueue['code_state'] ?? 'missing'));
check('with no worker running the state is unknown, not current',
    in_array($noQueue['code_state'], ['unknown', 'stale'], true) && $noQueue['code_state'] === 'unknown',
    json_encode([$noQueue['worker_status'] ?? null, $noQueue['code_state'] ?? null]));
check('an absent worker is never reported as running current code',
    ($noQueue['worker_status'] ?? '') !== 'running' && $noQueue['code_state'] !== 'current',
    json_encode([$noQueue['worker_status'] ?? null, $noQueue['code_state'] ?? null]));

// =====================================================================
echo "\n== the failure text names the remedy, without hiding the cause ==\n";
// =====================================================================
check('an undefined method is recognised',
    PipelineJobRunner::staleCodeHint('Call to undefined method App\Core\RuntimeConfig::updateOrFail()') !== null);
check('an undefined function is recognised too',
    PipelineJobRunner::staleCodeHint('Call to undefined function array_is_list()') !== null);
check('an ordinary failure is left alone',
    PipelineJobRunner::staleCodeHint('The settings file could not be written.') === null);
check('a connection loss is not dressed up as stale code',
    PipelineJobRunner::staleCodeHint('SQLSTATE[HY000] [2006] MySQL server has gone away') === null);
check('the hint names the restart command',
    str_contains((string)PipelineJobRunner::staleCodeHint('Call to undefined method X::y()'), 'systemctl restart revoap-worker'),
    (string)PipelineJobRunner::staleCodeHint('Call to undefined method X::y()'));

/**
 * A runner whose `$jobs` is injected without building a live pipeline
 * engine: `fail()` (the single funnel every failure goes through) reads
 * the queue and writes the error text, and that is the whole subject here.
 */
function runnerWithJobs(JobRepository $jobs): PipelineJobRunner
{
    $class = new ReflectionClass(PipelineJobRunner::class);
    $runner = $class->newInstanceWithoutConstructor();
    $class->getProperty('jobs')->setValue($runner, $jobs);
    return $runner;
}

$jobs = new JobRepository(null);
$jobId = $jobs->create(
    sessionId: 3,
    userId: null,
    payload: ['kind' => 'pipeline', 'query' => 'why did this fail', 'session_id' => 3],
    priority: 5,
    maxAttempts: 1,
);
$job = $jobs->find($jobId);
$fail = new ReflectionMethod(PipelineJobRunner::class, 'fail');
$fail->setAccessible(true);

$fail->invoke(
    runnerWithJobs($jobs),
    $job,
    'Error',
    'Call to undefined method App\Core\RuntimeConfig::updateOrFail()',
);
$recorded = (string)($jobs->find($jobId)?->error['message'] ?? '');
$queueStatus = $jobs->readStatus($jobId);
check('a failed job keeps its own error text intact',
    str_contains($recorded, 'Call to undefined method App\Core\RuntimeConfig::updateOrFail()'), $recorded);
check('and gains the stale-code advice',
    str_contains($recorded, 'STALE CODE'), $recorded);
check('the advice names the restart, not just the symptom',
    str_contains($recorded, 'sudo systemctl restart revoap-worker'), $recorded);
check('the advice points at the card that confirms it',
    str_contains($recorded, 'code_state=stale'), $recorded);
check('the failure is marked machine-readably as well',
    ($jobs->find($jobId)?->error['stale_code'] ?? null) === true,
    json_encode($jobs->find($jobId)?->error));
check('the job is still failed, not requeued', $queueStatus?->value === 'failed', (string)($queueStatus?->value));

$plainId = $jobs->create(
    sessionId: 3,
    userId: null,
    payload: ['kind' => 'pipeline', 'query' => 'plain failure', 'session_id' => 3],
    priority: 5,
    maxAttempts: 1,
);
$fail->invoke(runnerWithJobs($jobs), $jobs->find($plainId), 'RuntimeException', 'The analyzer timed out.');
$plain = (string)($jobs->find($plainId)?->error['message'] ?? '');
check('an ordinary failure carries no stale-code advice',
    $plain === 'The analyzer timed out.' && !str_contains($plain, 'STALE CODE'), $plain);
check('and no stale_code flag', !isset($jobs->find($plainId)?->error['stale_code']));

// =====================================================================
echo "\n== the Settings → Worker card renders the warning ==\n";
// =====================================================================
$harness = __DIR__ . '/harness/settings-worker.mjs';
$node = null;
foreach (['node', 'nodejs'] as $candidate) {
    $probe = @shell_exec(escapeshellarg($candidate) . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
        $node = $candidate;
        break;
    }
}
if (!is_file($harness)) {
    check('tests/harness/settings-worker.mjs exists', false);
} elseif ($node === null) {
    echo "  SKIP the DOM harness: node is not available on this host\n";
} else {
    $cmd = escapeshellarg($node) . ' --experimental-vm-modules '
        . escapeshellarg(str_replace('\\', '/', $harness)) . ' 2>&1';
    $output = (string)shell_exec($cmd);
    $harnessPassed = 0;
    $harnessFailed = 0;
    foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
        // Node's own ExperimentalWarning noise is filtered; every check
        // line the harness printed is forwarded verbatim.
        if ($line === '' || str_contains($line, 'ExperimentalWarning')
            || str_contains($line, 'trace-warnings') || str_contains($line, '(node:')
        ) {
            continue;
        }
        echo $line . "\n";
        if (preg_match('/^\s*ok\s/', $line) === 1) { $harnessPassed++; }
        if (preg_match('/^\s*FAIL\s/', $line) === 1) { $harnessFailed++; }
    }
    check('the DOM harness ran its checks', $harnessPassed + $harnessFailed > 0,
        'no check lines found — did the harness crash?');
    check('every DOM-harness check passed', $harnessFailed === 0, "{$harnessFailed} failed");
}

// =====================================================================
// Clean up the fixture
// =====================================================================
$rm = static function (string $path) use (&$rm): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $rm($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};
$rm($root);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
