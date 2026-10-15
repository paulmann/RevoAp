<?php
/**
 * DatabaseResilienceTest: offline checks for surviving a MySQL restart
 * without restarting the worker.
 *
 * Runs without PHPUnit, without MySQL, without network access and
 * without touching the production database:
 *   php tests/DatabaseResilienceTest.php
 *
 * The production incident this suite pins down:
 *
 *   A long-lived worker held one MySQL connection from boot. The
 *   operator restarted MySQL; the worker logged
 *   `worker.presence.write_failed` with `1053 Server shutdown in
 *   progress`, and from then on EVERY job died in ~4.6s with
 *   `SQLSTATE[HY000]: General error: 2006 MySQL server has gone away`.
 *   No event ever reached the stream, the session stayed `running` with
 *   `tokens: 0`, and the worker indicator stayed green because the
 *   presence beacon kept beating. Only a worker restart fixed it.
 *
 * Covered:
 *   1. `Database::isGoneAway()` recognises the connection-loss family,
 *      including `1053 Server shutdown in progress` (which the previous
 *      needle list missed entirely — that is why the presence write
 *      failed instead of reconnecting) and SQLSTATE class 08, while NOT
 *      misfiring on ordinary SQL errors.
 *   2. `Database::ensureConnected()` / `reconnect()` / counters /
 *      observers, and the `fromPdo()`-without-DSN case re-raising the
 *      ORIGINAL PDOException rather than a RuntimeException.
 *   3. `WorkerJobExecutor`: pre-flight connection check; a lost
 *      connection mid-job is retried once on a fresh handle; a job that
 *      still cannot run is REQUeued with `attempts` untouched and a
 *      future `next_run_at`; ordinary (non-connection) errors keep their
 *      old behaviour untouched.
 *   4. `JobRepository::requeueForInfrastructure()` — including the
 *      invariant that it never spends the model retry budget, while the
 *      pre-existing stale-recovery sweep still does.
 *   5. `WorkerPresenceRepository` beating through a dropped connection,
 *      and degrading HONESTLY (with a `db_lost` marker) when it cannot.
 *   6. `WorkerHealthService::snapshot()` publishing `db_reconnects` /
 *      `last_db_error_at` additively, with every pre-existing key intact.
 *
 * The fault injector is a real SQLite database behind a PDO subclass
 * that throws a genuine `2006` / `1053` on the first N statements. A
 * reconnect therefore has to build a genuinely new, working handle — the
 * test cannot pass by accident.
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

use App\Core\Config;
use App\Core\Database;
use App\Core\DbResilienceJournal;
use App\Jobs\Job;
use App\Jobs\JobRepository;
use App\Jobs\JobRunnerInterface;
use App\Jobs\JobStatus;
use App\Jobs\WorkerHealthService;
use App\Jobs\WorkerJobExecutor;
use App\Jobs\WorkerPresenceRepository;

$passes   = 0;
$failures = 0;
$report   = [];

function checkR(string $label, bool $cond, string $detail = ''): void
{
    global $passes, $failures, $report;
    if ($cond) {
        $passes++;
        $report[] = "  ok  {$label}";
        return;
    }
    $failures++;
    $report[] = "FAIL  {$label}" . ($detail === '' ? '' : "  [{$detail}]");
}

function sectionR(string $title): void
{
    global $report;
    $report[] = '';
    $report[] = str_repeat('=', 72);
    $report[] = $title;
    $report[] = str_repeat('=', 72);
}

function rmTreeR(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
}

// =====================================================================
// Fault injector: a real SQLite database behind a PDO that fails on
// command. `arm()` sets the number of statements that will throw the
// configured driver error before the connection starts working again.
// =====================================================================
final class FaultyPdo extends PDO
{
    /** Statements still scheduled to fail. */
    private int $faultsLeft = 0;

    /** Driver error reproduced on the next armed statement. */
    private string $faultMessage = 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';

    public function __construct(string $dsn)
    {
        parent::__construct($dsn);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** Schedule the next $count statements to fail. */
    public function arm(int $count, ?string $message = null): void
    {
        $this->faultsLeft = $count;
        if ($message !== null) {
            $this->faultMessage = $message;
        }
    }

    public function faultsLeft(): int
    {
        return $this->faultsLeft;
    }

    private function maybeFail(): void
    {
        if ($this->faultsLeft <= 0) {
            return;
        }
        $this->faultsLeft--;
        // A genuine PDOException carrying the same errorInfo a real
        // MySQL driver populates, so both detection paths (message
        // needles and errorInfo codes) are exercised for real.
        $e = new PDOException($this->faultMessage);
        $sqlstate = preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/i', $this->faultMessage, $m) === 1
            ? strtoupper($m[1])
            : 'HY000';
        $driverCode = preg_match('/(?:error|\[)\s*:?\s*(\d{4})/', $this->faultMessage, $m2) === 1
            ? (int)$m2[1]
            : null;
        $e->errorInfo = [$sqlstate, $driverCode, $this->faultMessage];
        throw $e;
    }

    /**
     * Translate the one MySQL-specific statement this suite exercises —
     * the presence beacon's upsert — into SQLite's ON CONFLICT form, so
     * the repository's real SQL path can run offline. Nothing else is
     * rewritten: every other statement the repositories issue is already
     * portable, and the reconnect-recovery assertions deliberately use
     * those.
     */
    private static function translate(string $sql): string
    {
        if (!str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            return $sql;
        }
        $sql = preg_replace('/VALUES\((\w+)\)/', 'excluded.$1', $sql) ?? $sql;
        return str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(worker_id) DO UPDATE SET', $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->maybeFail();
        return parent::prepare(self::translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->maybeFail();
        // The presence/queue repositories probe table existence with a
        // MySQL metadata statement SQLite does not implement. Rewriting
        // it onto `sqlite_master` is what lets the REAL SQL presence path
        // (including its SHOW-TABLES caching) be exercised offline
        // instead of being stubbed out.
        if (preg_match('/^SHOW\s+TABLES\s+LIKE\s+"([A-Za-z0-9_]+)"$/i', trim($query), $m) === 1) {
            $query = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '"
                . str_replace("'", "''", $m[1]) . "'";
            return parent::query($query);
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->maybeFail();
        return parent::exec($statement);
    }
}

/**
 * Runner stub implementing the real contract. It performs a genuine
 * database write through the repository so "the job succeeded" is
 * backed by an actual committed statement, not by a return value.
 */
final class ScriptedRunner implements JobRunnerInterface
{
    public int $calls = 0;

    /** @var list<Throwable> Failures thrown before any database work. */
    public array $throwNext = [];

    /** Arm this many faults on the injector the first time run() is called. */
    public int $armOnFirstCall = 0;

    public ?FaultyPdo $injector = null;
    public ?JobRepository $jobs = null;

    public function run(Job $job, string $workerId): JobStatus
    {
        $this->calls++;
        if ($this->calls === 1 && $this->armOnFirstCall > 0 && $this->injector !== null) {
            $this->injector->arm($this->armOnFirstCall);
        }
        if ($this->throwNext !== []) {
            throw array_shift($this->throwNext);
        }
        // Real work: two statements through the repository, so the
        // connection the retry picked up is proven to be usable.
        $this->jobs?->updateStatus($job->id, JobStatus::Running);
        $this->jobs?->recordResult($job->id, ['ok' => true, 'runner_calls' => $this->calls]);
        return JobStatus::Completed;
    }
}

// =====================================================================
// Fixture
// =====================================================================
$work = sys_get_temp_dir() . '/revoap-db-resilience-' . bin2hex(random_bytes(5));
if (!is_dir($work) && !mkdir($work, 0775, true)) {
    fwrite(STDERR, "cannot create work dir {$work}\n");
    exit(1);
}
// Config for the services under test, and an EXPLICIT journal root:
// persistence is opt-in, so this suite can never read or write the
// deployment's real runtime/db-resilience.json.
Config::set(['app' => ['version' => 'test']], $work);
DbResilienceJournal::reset();
DbResilienceJournal::init($work);

$sqliteFile = $work . '/revoap.sqlite';
$jobsDdl = 'CREATE TABLE revoap_jobs ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, user_id INTEGER,'
    . ' status TEXT, priority INTEGER DEFAULT 0,'
    . ' attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3,'
    . ' payload_json TEXT, result_json TEXT, error_json TEXT,'
    . ' locked_by TEXT, locked_at TEXT, heartbeat_at TEXT,'
    . ' next_run_at TEXT, last_recovered_at TEXT, recovery_reason TEXT, last_error_at TEXT,'
    . ' created_at TEXT, started_at TEXT, finished_at TEXT, updated_at TEXT)';
$presenceDdl = 'CREATE TABLE revoap_worker_presence ('
    . 'worker_id TEXT PRIMARY KEY, host TEXT, pid INTEGER, app_version TEXT, mode TEXT,'
    . ' started_at TEXT, last_seen_at TEXT, current_job_id INTEGER, jobs_done INTEGER,'
    . ' last_error_at TEXT, last_error_reason TEXT)';

$schema = new PDO('sqlite:' . $sqliteFile);
$schema->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema->exec($jobsDdl);
$schema->exec($presenceDdl);
$schema = null;

/**
 * Fresh injector over the shared SQLite file. The DSN is supplied to the
 * wrapper below so a reconnect builds a genuinely NEW handle, exactly as
 * the worker's `Application::database()` wrapper can.
 */
$injectorFor = static function (int $armFaults = 0, string $fault = '2006') use ($sqliteFile): FaultyPdo {
    $injector = new FaultyPdo('sqlite:' . $sqliteFile);
    $message  = $fault === '1053'
        ? 'SQLSTATE[HY000]: General error: 1053 Server shutdown in progress'
        : 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';
    // Verify the fixture is sound before arming: the injector must be
    // able to read the schema created above.
    $injector->query('SELECT COUNT(*) FROM revoap_jobs');
    $injector->arm($armFaults, $message);
    return $injector;
};

/** [resilient wrapper, injector] over the shared SQLite file. */
$makeStack = static function (int $armFaults = 0, string $fault = '2006') use ($sqliteFile, $injectorFor): array {
    $injector = $injectorFor($armFaults, $fault);
    $db = Database::fromPdo(
        $injector,
        'sqlite:' . $sqliteFile,
        options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    return [$db, $injector];
};

$seedJob = static function (JobRepository $repo, string $status = JobStatus::Running->value): Job {
    $id = $repo->create(4242, null, ['query' => 'resilience probe'], 0, 3);
    if ($status === JobStatus::Running->value) {
        $repo->updateStatus($id, JobStatus::Running);
    }
    $job = $repo->find($id);
    if ($job === null) {
        throw new RuntimeException('fixture could not create a job row');
    }
    return $job;
};

// =====================================================================
sectionR('1. Connection-loss detection — the 1053 that started it all');
// =====================================================================
$expectations = [
    // message => [retryable on a new handle?, connection-class at all?]
    'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'   => [true, true],
    'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query' => [true, true],
    'SQLSTATE[HY000]: General error: 1053 Server shutdown in progress'  => [true, true],
    'SQLSTATE[HY000]: General error: 1077 Normal shutdown'              => [true, true],
    'SQLSTATE[HY000]: General error: 1927 Connection was killed'        => [true, true],
    'SQLSTATE[08S01]: Communication link failure'                       => [true, true],
    'SQLSTATE[HY000]: General error: 1152 Aborting connection'          => [true, true],
    // Could not be established in the first place: infrastructure, but
    // retrying immediately would only pay the connect timeout twice.
    'SQLSTATE[HY000] [2002] Connection refused'                         => [false, true],
    'SQLSTATE[HY000] [2003] Can\'t connect to MySQL server on host'     => [false, true],
    'SQLSTATE[HY000] [2005] Unknown MySQL server host'                  => [false, true],
    'SQLSTATE[08001]: Unable to connect to host'                        => [false, true],
    // Genuine SQL problems must never be mistaken for infrastructure.
    'SQLSTATE[HY000]: General error: 1054 Unknown column \'attempts\''  => [false, false],
    'SQLSTATE[42S02]: Base table or view not found: 1146 Table missing' => [false, false],
    'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry' => [false, false],
    'SQLSTATE[HY000]: General error: 2014 Cannot execute queries while other unbuffered queries are active' => [false, false],
];
$mismatches = [];
foreach ($expectations as $message => [$retryable, $connectionClass]) {
    $e = new PDOException($message);
    if (Database::isGoneAway($e) !== $retryable || Database::isConnectionError($e) !== $connectionClass) {
        $mismatches[] = $message
            . ' [retryable=' . var_export(Database::isGoneAway($e), true)
            . ' expect=' . var_export($retryable, true) . ']';
    }
}
checkR('isGoneAway()/isConnectionError() classify all ' . count($expectations) . ' sample errors correctly',
    $mismatches === [], implode(' | ', $mismatches));

// The regression the incident exposed: the message the operator actually
// saw in worker.presence.write_failed was NOT recognised, so the wrapper
// re-raised it instead of reconnecting.
checkR('"1053 Server shutdown in progress" is recognised (was the production miss)',
    Database::isGoneAway(new PDOException('SQLSTATE[HY000]: General error: 1053 Server shutdown in progress')) === true);
// A connect failure is classified as infrastructure but must NOT trigger
// an immediate reconnect: on a down server that doubles the connect
// timeout on every single operation. Measured at ~2s per refused connect
// on the reference host, which is exactly how a full queue used to turn
// an outage into a crawl.
$refused = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
checkR('a refused connect is infrastructure but not immediately retryable',
    Database::isConnectFailure($refused) === true
    && Database::isConnectionError($refused) === true
    && Database::isGoneAway($refused) === false);

// errorInfo wins over message sniffing when a driver omits the text.
$withInfo = new PDOException('some opaque driver text');
$withInfo->errorInfo = ['08S01', 2006, 'gone'];
checkR('isGoneAway() trusts errorInfo (SQLSTATE 08S01) over the message text',
    Database::isGoneAway($withInfo) === true);
$withInfo2 = new PDOException('some opaque driver text');
$withInfo2->errorInfo = ['HY000', 1053, 'shutdown'];
checkR('isGoneAway() trusts errorInfo (driver code 1053) over the message text',
    Database::isGoneAway($withInfo2) === true);
$withInfo3 = new PDOException('some opaque driver text');
$withInfo3->errorInfo = ['HY000', 2002, 'connect'];
checkR('isConnectFailure() trusts errorInfo (driver code 2002) over the message text',
    Database::isConnectFailure($withInfo3) === true && Database::isGoneAway($withInfo3) === false);

// =====================================================================
sectionR('2. Database wrapper: reconnect, counters, observers, stats');
// =====================================================================
[$dbOne, $injOne] = $makeStack(0);
$events = [];
$dbOne->addObserver(static function (string $event, array $ctx) use (&$events): void {
    $events[] = [$event, $ctx];
});
checkR('a fresh wrapper reports zero reconnects and zero losses',
    $dbOne->reconnects() === 0 && $dbOne->losses() === 0 && $dbOne->isConnected() === true);

$injOne->arm(1, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
$rows = $dbOne->run(static fn(PDO $pdo): array => $pdo->query('SELECT COUNT(*) AS c FROM revoap_jobs')->fetchAll());
checkR('run() reconnects transparently and the retried closure returns real rows',
    is_array($rows) && $rows !== [], json_encode($rows));
checkR('exactly one reconnect and one loss were counted',
    $dbOne->reconnects() === 1 && $dbOne->losses() === 1,
    'reconnects=' . $dbOne->reconnects() . ' losses=' . $dbOne->losses());
checkR('the loss/reconnect transitions were published to the observer',
    count($events) === 2 && $events[0][0] === 'db.lost' && $events[1][0] === 'db.reconnected',
    json_encode(array_column($events, 0)));
checkR('the observer context carries the driver code, sqlstate and counters',
    ($events[0][1]['driver_code'] ?? null) === 2006
    && ($events[0][1]['sqlstate'] ?? null) === 'HY000'
    && ($events[1][1]['reconnects'] ?? null) === 1,
    json_encode($events[0][1]));
checkR('lastErrorAt()/lastErrorMessage() remember the incident for diagnostics',
    is_string($dbOne->lastErrorAt()) && str_contains((string)$dbOne->lastErrorMessage(), '2006'),
    (string)$dbOne->lastErrorMessage());

$stats = $dbOne->stats();
checkR('stats() keeps its pre-existing keys and adds the new ones',
    $stats['reconnects'] === 1 && $stats['retries'] === 1 && $stats['losses'] === 1
    && $stats['connected'] === true && $stats['dsn_configured'] === true
    && $stats['last_error_at'] === $dbOne->lastErrorAt(),
    json_encode($stats));

// ensureConnected() is the pre-job liveness check: it must repair a
// handle the server has already closed. Driven on its own stack — after
// the reconnect above the injector has been retired, so a fault armed on
// it would have nowhere to land.
[$dbE, ] = $makeStack(1, '1053');
checkR('ensureConnected() detects and repairs a connection lost to a server shutdown',
    $dbE->ensureConnected() === true && $dbE->reconnects() === 1,
    'reconnects=' . $dbE->reconnects());
checkR('ensureConnected() is a cheap no-op on a healthy connection',
    $dbE->ensureConnected() === true && $dbE->reconnects() === 1);
checkR('the repaired wrapper keeps reporting a live connection',
    $dbE->isConnected() === true && $dbE->stats()['connected'] === true);

// A wrapper with no DSN cannot rebuild its handle. It must say so by
// re-raising the ORIGINAL driver error, never by throwing a
// RuntimeException from connect() that no caller is prepared for.
$orphan = new FaultyPdo('sqlite:' . $sqliteFile);
$orphan->arm(2, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
$orphanDb = Database::fromPdo($orphan);
$orphanError = null;
try {
    $orphanDb->run(static fn(PDO $pdo): string => (string)$pdo->query('SELECT 1')->fetchColumn());
} catch (Throwable $e) {
    $orphanError = $e;
}
checkR('a DSN-less fromPdo() wrapper re-raises the original PDOException, not a RuntimeException',
    $orphanError instanceof PDOException && str_contains($orphanError->getMessage(), '2006'),
    $orphanError === null ? 'no exception' : get_class($orphanError));
checkR('canReconnect() reports whether a repair is even possible',
    $orphanDb->canReconnect() === false && $dbOne->canReconnect() === true);

// =====================================================================
sectionR('3. WorkerJobExecutor — the worker survives the restart');
// =====================================================================

// (a) Exactly one reconnect, job completes. The injector fails the very
//     first statement of the run; the repository's own run() repairs the
//     handle and the job sails through.
[$dbA, $injA] = $makeStack(0);
$repoA = new JobRepository($dbA);
$jobA  = $seedJob($repoA);
$runnerA = new ScriptedRunner();
$runnerA->jobs = $repoA;
$runnerA->injector = $injA;
$runnerA->armOnFirstCall = 1;
$execA = new WorkerJobExecutor($repoA, $runnerA, $dbA);
$outA = $execA->execute($jobA, 'worker-a');
checkR('(a) the job completed successfully', $outA['status'] === JobStatus::Completed, json_encode($outA['status']));
checkR('(a) after EXACTLY one reconnect', $outA['reconnects'] === 1, 'reconnects=' . $outA['reconnects']);
checkR('(a) one connection loss was recorded', $outA['losses'] === 1, 'losses=' . $outA['losses']);
checkR('(a) no in-place retry was needed (the repository healed itself)',
    $outA['retried'] === false && $runnerA->calls === 1, 'calls=' . $runnerA->calls);
checkR('(a) the job was not requeued', $outA['requeued'] === false);
$finalA = $repoA->find($jobA->id);
checkR('(a) the run really reached the database (result envelope persisted)',
    $finalA !== null && ($finalA->result['ok'] ?? null) === true, json_encode($finalA?->result));

// (a2) The executor's own retry (requirement 1b): the runner raises a
//      connection-loss error that the repository's own single retry
//      could not absorb (as engine code that is not wrapped in run()
//      would). The executor must reconnect and re-run the job in this
//      same iteration, and the re-run must really commit.
[$dbA2, ] = $makeStack(0);
$repoA2 = new JobRepository($dbA2);
$jobA2  = $seedJob($repoA2);
$runnerA2 = new ScriptedRunner();
$runnerA2->jobs = $repoA2;
$runnerA2->throwNext = [
    new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
];
$execA2 = new WorkerJobExecutor($repoA2, $runnerA2, $dbA2);
$outA2 = $execA2->execute($jobA2, 'worker-a2');
checkR('(a2) a lost connection mid-job is retried in the same iteration',
    $outA2['status'] === JobStatus::Completed && $outA2['retried'] === true, json_encode($outA2));
checkR('(a2) the retry re-ran the job exactly once more',
    $runnerA2->calls === 2, 'calls=' . $runnerA2->calls);
checkR('(a2) exactly one reconnect was needed and the job was not requeued',
    $outA2['reconnects'] === 1 && $outA2['requeued'] === false, json_encode($outA2));
checkR('(a2) the re-run committed its result on the fresh handle',
    $repoA2->find($jobA2->id)?->result !== null
    && ($repoA2->find($jobA2->id)?->result['runner_calls'] ?? null) === 2,
    json_encode($repoA2->find($jobA2->id)?->result));

// (b) Persistent connection failure: the job must go back to the queue
//     with its model retry budget intact.
[$dbB, $injB] = $makeStack(0);
$repoB = new JobRepository($dbB);
$jobB  = $seedJob($repoB);
$attemptsBefore = $repoB->find($jobB->id)?->attempts;
$runnerB = new ScriptedRunner();
$runnerB->jobs = $repoB;
$runnerB->throwNext = [
    new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
    new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
];
$execB = new WorkerJobExecutor($repoB, $runnerB, $dbB, requeueDelaySeconds: 42);
$outB = $execB->execute($jobB, 'worker-b');
checkR('(b) the executor reports a requeue, not a terminal status',
    $outB['status'] === null && $outB['requeued'] === true, json_encode($outB));
$afterB = $repoB->find($jobB->id);
checkR('(b) the job is back in the queue', $afterB?->status === JobStatus::Queued, $afterB?->status->value);
checkR('(b) attempts was NOT incremented (infrastructure is not a model failure)',
    $afterB->attempts === $attemptsBefore && $afterB->attempts === 0,
    'before=' . var_export($attemptsBefore, true) . ' after=' . var_export($afterB->attempts, true));
checkR('(b) next_run_at shifted into the future',
    is_string($afterB->nextRunAt) && strtotime($afterB->nextRunAt) > time(),
    var_export($afterB->nextRunAt, true));
checkR('(b) the retry budget was reported as untouched on the row and in the outcome',
    $afterB->recoveryReason === WorkerJobExecutor::REASON_DATABASE_UNAVAILABLE
    && ($afterB->error['infrastructure'] ?? null) === true
    && ($afterB->error['backoff_seconds'] ?? null) === 42
    && $outB['requeue_delay_s'] === 42,
    json_encode($afterB->error));
checkR('(b) the requeue is visible in the audit-shaped error envelope',
    ($afterB->error['class'] ?? null) === 'DatabaseUnavailable'
    && ($afterB->error['stage'] ?? null) === 'infrastructure',
    json_encode($afterB->error));
checkR('(b) the lock was released so another worker may take the job',
    $afterB->lockedBy === null);

// (b2) Nothing can be written when the server is unreachable. That must
//      not kill the worker: the outcome reports requeued=false and the
//      job is left for the stale-recovery sweep.
$deadDb   = new Database('mysql:host=127.0.0.1;port=1;dbname=revoap_missing');
$deadRepo = new JobRepository($deadDb);
// A real Job value from the in-memory mirror: only its id matters, and
// the point of this case is that the id cannot be written anywhere.
$deadSeed = new JobRepository(null);
$deadId   = $deadSeed->create(1, null, ['query' => 'unreachable']);
$deadJob  = $deadSeed->find($deadId);
$runnerB2 = new ScriptedRunner();
$execB2   = new WorkerJobExecutor($deadRepo, $runnerB2, $deadDb);
$outB2    = $execB2->execute($deadJob, 'worker-b2');
checkR('(b2) an unreachable server yields requeued=false without throwing',
    $outB2['requeued'] === false && $outB2['status'] === null, json_encode($outB2));
checkR('(b2) the runner was never started on the dead connection',
    $runnerB2->calls === 0, 'calls=' . $runnerB2->calls);
checkR('(b2) the failure is reported as an infrastructure error',
    is_string($outB2['infrastructure_error'])
    && str_contains($outB2['infrastructure_error'], 'unavailable before'),
    (string)$outB2['infrastructure_error']);

// (г) Ordinary errors are untouched: no requeue, no classification as
//     infrastructure, and the caller's existing failure handling runs.
[$dbG, $injG] = $makeStack(0);
$repoG = new JobRepository($dbG);
$jobG  = $seedJob($repoG);
$modelError = new RuntimeException('model returned an empty completion');
$runnerG = new ScriptedRunner();
$runnerG->jobs = $repoG;
$runnerG->throwNext = [$modelError];
$execG = new WorkerJobExecutor($repoG, $runnerG, $dbG);
$propagated = null;
try {
    $execG->execute($jobG, 'worker-g');
} catch (Throwable $e) {
    $propagated = $e;
}
checkR('(g) a non-connection error propagates unchanged',
    $propagated === $modelError, $propagated === null ? 'not thrown' : get_class($propagated));
checkR('(g) isConnectionLoss() does not classify it as infrastructure',
    WorkerJobExecutor::isConnectionLoss($modelError) === false
    && WorkerJobExecutor::isConnectionLoss(new PDOException('SQLSTATE[HY000]: General error: 2006 gone away')) === true);
$afterG = $repoG->find($jobG->id);
checkR('(g) no requeue was scheduled for an ordinary failure',
    $afterG->nextRunAt === null && $afterG->recoveryReason === null,
    'next_run_at=' . var_export($afterG->nextRunAt, true) . ' reason=' . var_export($afterG->recoveryReason, true));
checkR('(g) the infrastructure path left the retry budget alone',
    $afterG->attempts === 0, 'attempts=' . $afterG->attempts);
// The pre-existing failure handling still runs and still marks the job
// failed with an error envelope, exactly as before this change.
$repoG->recordError($jobG->id, ['class' => 'RuntimeException', 'message' => $modelError->getMessage()]);
$repoG->updateStatus($jobG->id, JobStatus::Failed);
$afterG2 = $repoG->find($jobG->id);
checkR('(g) the ordinary failure path still marks the job failed with an error envelope',
    $afterG2->status === JobStatus::Failed && ($afterG2->error['class'] ?? null) === 'RuntimeException',
    json_encode($afterG2->error));

// =====================================================================
sectionR('4. The two retry budgets stay separate');
// =====================================================================
// infrastructure requeue: never touches `attempts`
[$dbR, ] = $makeStack(0);
$repoR = new JobRepository($dbR);
$jobR  = $seedJob($repoR);
$okR   = $repoR->requeueForInfrastructure($jobR->id, 'database_unavailable', 30, ['message' => 'gone away']);
$afterR = $repoR->find($jobR->id);
checkR('requeueForInfrastructure() succeeds against a real engine', $okR === true);
checkR('requeueForInfrastructure() leaves attempts at 0', $afterR->attempts === 0, 'attempts=' . $afterR->attempts);
checkR('requeueForInfrastructure() sets a future next_run_at and clears the lock',
    is_string($afterR->nextRunAt) && strtotime($afterR->nextRunAt) > time() && $afterR->lockedBy === null,
    var_export($afterR->nextRunAt, true));
checkR('requeueForInfrastructure() records last_recovered_at for the timeline',
    is_string($afterR->lastRecoveredAt) && $afterR->lastRecoveredAt !== '');
checkR('requeueForInfrastructure() refuses to resurrect a terminal row',
    $repoR->requeueForInfrastructure($jobR->id, 'database_unavailable', 30) === true
    && (static function () use ($repoR, $jobR): bool {
        $repoR->updateStatus($jobR->id, JobStatus::Completed);
        return $repoR->requeueForInfrastructure($jobR->id, 'database_unavailable', 30) === false;
    })());
checkR('requeueForInfrastructure() reports false for a job that does not exist',
    $repoR->requeueForInfrastructure(987654, 'database_unavailable', 30) === false);

// The MODEL-retry budget still behaves as before: the stale-recovery
// sweep spends an attempt and schedules a backoff. Driven through the
// in-memory mirror so no server is required.
$memRepo = new JobRepository(null);
$memId   = $memRepo->create(777, null, ['query' => 'stale'], 0, 5);
$memJob  = $memRepo->acquireNext('worker-stale');
checkR('the stale-recovery fixture acquired the row', $memJob !== null && $memJob->id === $memId);
// Age the heartbeat beyond the threshold (the mirror has no clock hook).
$memProp = new ReflectionProperty(JobRepository::class, 'memory');
$mirror  = $memProp->getValue($memRepo);
$mirror[$memId]['heartbeat_at'] = date('Y-m-d H:i:s', time() - 3600);
$memProp->setValue($memRepo, $mirror);
$swept = $memRepo->recoverStale(60, [30, 120, 300], true);
$afterSweep = $memRepo->find($memId);
checkR('the MODEL retry budget still spends an attempt on a stale heartbeat',
    $swept['requeued'] === 1 && $afterSweep->attempts === 1 && $afterSweep->status === JobStatus::Queued,
    json_encode($swept) . ' attempts=' . $afterSweep->attempts);
checkR('the stale sweep still schedules its own backoff',
    is_string($afterSweep->nextRunAt) && strtotime($afterSweep->nextRunAt) > time()
    && $afterSweep->recoveryReason === 'stale_heartbeat',
    var_export($afterSweep->nextRunAt, true));

// =====================================================================
sectionR('5. Worker presence beacon survives the same fault (1d)');
// =====================================================================
// (в) A dropped connection during a beat is repaired by the wrapper.
[$dbP, $injP] = $makeStack(0);
$presence = new WorkerPresenceRepository($dbP);
$presence->touch('worker-p1', ['host' => 'test', 'pid' => 1234, 'mode' => 'loop']);
checkR('(c) presence writes land when the connection is healthy',
    $presence->isDegraded() === false && $presence->all() !== [], json_encode($presence->all()));
checkR('(c) the beacon row carries the worker identity and a fresh heartbeat',
    ($presence->all()[0]['worker_id'] ?? '') === 'worker-p1'
    && ($presence->all()[0]['mode'] ?? null) === 'loop',
    json_encode($presence->all()));

// Recovery after the break. A FRESH stack is used because the injector
// belongs to the handle, and once a reconnect replaces that handle the
// old injector can no longer fail anything.
$injP->arm(0);
[$dbP3, $injP3] = $makeStack(0);
$presence3 = new WorkerPresenceRepository($dbP3);
$recoveryEvents = [];
$dbP3->addObserver(static function (string $event) use (&$recoveryEvents): void {
    $recoveryEvents[] = $event;
});
checkR('(c) the beacon row written before the break is still there',
    ($presence3->all()[0]['worker_id'] ?? '') === 'worker-p1', json_encode($presence3->all()));

$injP3->arm(1, 'SQLSTATE[HY000]: General error: 1053 Server shutdown in progress');
$forgotten = $presence3->forget('worker-p1');
checkR('(c) presence writes recover after the connection breaks',
    $forgotten === true && $dbP3->reconnects() === 1,
    'forget=' . var_export($forgotten, true) . ' reconnects=' . $dbP3->reconnects());
checkR('(c) the write really landed on the reconnected handle',
    $presence3->all() === [], json_encode($presence3->all()));
checkR('(c) the recovery was published as a loss + reconnect pair',
    $recoveryEvents === ['db.lost', 'db.reconnected'], json_encode($recoveryEvents));
checkR('(c) the beacon never reported itself degraded across that repair',
    $presence3->isDegraded() === false && $presence3->consecutiveFailures() === 0,
    json_encode($presence3->lastWriteError()));

// A beacon that genuinely cannot write must ADMIT it rather than keep
// reporting healthy. This is the "MySQL is still down" case: the
// original handle works long enough to prove it was healthy, but the
// connection can never be rebuilt, so the failure has to surface with a
// connection-loss marker instead of being mistaken for a schema fault.
$injP2 = $injectorFor(0);
$dbP2  = Database::fromPdo($injP2, 'mysql:host=127.0.0.1;port=1;dbname=revoap_missing');
$presence2 = new WorkerPresenceRepository($dbP2);
$presence2->touch('worker-p2', ['host' => 'test', 'pid' => 4321, 'mode' => 'loop']);
checkR('a healthy beacon is not flagged as degraded', $presence2->isDegraded() === false);
$injP2->arm(1, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
$beatFailed = $presence2->touch('worker-p2', ['host' => 'test', 'pid' => 4321, 'mode' => 'loop', 'jobs_done' => 2]);
checkR('a beacon that cannot write returns false instead of pretending',
    $beatFailed === false, 'touch() returned ' . var_export($beatFailed, true));
checkR('a failed beacon reports itself as degraded with a db_lost marker',
    $presence2->isDegraded() === true
    && $presence2->consecutiveFailures() >= 1
    && ($presence2->lastWriteError()['db_lost'] ?? null) === true,
    json_encode($presence2->lastWriteError()));
checkR('the degraded beacon error is sanitized and class-labelled',
    ($presence2->lastWriteError()['class'] ?? '') === 'PDOException'
    && is_string($presence2->lastWriteError()['at'] ?? null),
    json_encode($presence2->lastWriteError()));
checkR('the unrecoverable loss was counted on the wrapper',
    $dbP2->losses() === 1 && $dbP2->reconnects() === 0,
    'losses=' . $dbP2->losses() . ' reconnects=' . $dbP2->reconnects());

// =====================================================================
sectionR('6. Snapshot observability (д) and unchanged contracts');
// =====================================================================
$snapshotKeysBefore = [
    'execution_mode', 'jobs_table_available', 'migration_applied', 'stale_threshold_sec',
    'counts', 'total_non_terminal', 'oldest_queued_at', 'oldest_queued_age_s',
    'latest_heartbeat_at', 'latest_heartbeat_age_s', 'running_workers', 'stale_jobs',
    'worker_status', 'recommendations', 'commands', 'retry_policy', 'retry_scheduled',
    'retry_exhausted', 'next_retry_at', 'oldest_retry_age_s', 'generated_at',
    'app_version', 'presence_table_available', 'workers_alive', 'oldest_worker_uptime_s',
    'worker_versions', 'worker_presence', 'idle',
];

// Point the journal at a private root so its counters are unambiguous:
// earlier sections in this same process have already recorded incidents.
Config::set(['app' => ['version' => 'test']], $work . '/snapshot-root');
DbResilienceJournal::reset();
DbResilienceJournal::init($work . '/snapshot-root');
[$dbS, $injS] = $makeStack(0);
$repoS   = new JobRepository($dbS);
$healthS = new WorkerHealthService($repoS, $dbS);
$snapBefore = $healthS->snapshot();
checkR('(d) db_reconnects is published in the snapshot and starts at 0',
    array_key_exists('db_reconnects', $snapBefore) && $snapBefore['db_reconnects'] === 0,
    json_encode($snapBefore['db_reconnects'] ?? 'MISSING'));
checkR('(d) every key the pre-existing contract published is still present',
    array_diff($snapshotKeysBefore, array_keys($snapBefore)) === [],
    implode(',', array_diff($snapshotKeysBefore, array_keys($snapBefore))));

$injS->arm(1, 'SQLSTATE[HY000]: General error: 1053 Server shutdown in progress');
$dbS->ensureConnected();
$snapAfter = $healthS->snapshot();
checkR('(d) db_reconnects increases when a reconnect happens',
    $snapAfter['db_reconnects'] === 1, json_encode($snapAfter['db_reconnects'] ?? 'MISSING'));
checkR('(d) last_db_error_at is published and records the shutdown',
    is_string($snapAfter['last_db_error_at']) && $snapAfter['last_db_error_at'] !== '',
    var_export($snapAfter['last_db_error_at'] ?? 'MISSING', true));
checkR('(d) last_db_error carries the sanitized driver message',
    is_string($snapAfter['last_db_error']) && str_contains($snapAfter['last_db_error'], '1053'),
    var_export($snapAfter['last_db_error'] ?? 'MISSING', true));
checkR('(d) db_losses counts the incident',
    ($snapAfter['db_losses'] ?? 0) === 1, json_encode($snapAfter['db_losses'] ?? 'MISSING'));
checkR('(d) db_connected reflects the repaired handle',
    ($snapAfter['db_connected'] ?? null) === true, json_encode($snapAfter['db_connected'] ?? 'MISSING'));

// The journal is what lets the FPM endpoint report a restart the WORKER
// survived, so it must be durable across instances.
$journalAfter = DbResilienceJournal::read();
checkR('the resilience journal persists the counters across processes',
    ($journalAfter['reconnects'] ?? 0) >= 1 && is_string($journalAfter['last_error_at'] ?? null),
    json_encode($journalAfter));
checkR('the journal file lands under <root>/runtime/ and holds no secrets',
    is_file(DbResilienceJournal::path())
    && str_contains(DbResilienceJournal::path(), 'snapshot-root')
    && str_contains(DbResilienceJournal::path(), 'runtime')
    && !str_contains((string)@file_get_contents(DbResilienceJournal::path()), 'password'),
    DbResilienceJournal::path());

// A fresh instance (as an FPM request would build) still sees the count.
[$dbS2, ] = $makeStack(0);
$snapFresh = (new WorkerHealthService(new JobRepository($dbS2), $dbS2))->snapshot();
checkR('a brand-new instance reports the journaled reconnect count, not zero',
    $snapFresh['db_reconnects'] >= 1, json_encode($snapFresh['db_reconnects'] ?? 'MISSING'));

// =====================================================================
sectionR('7. Hot-path repositories accept the resilient wrapper (web + worker)');
// =====================================================================
$skillRepo = new App\Skills\SkillRepository($dbS);
checkR('SkillRepository exposes its wrapper and uses it for reads',
    $skillRepo->database() === $dbS);
$profileRepo = new App\Profiles\UserProfileRepository($dbS);
checkR('UserProfileRepository exposes its wrapper', $profileRepo->database() === $dbS);
$presetRepo = new App\DomainPresets\DomainPresetRepository($dbS);
checkR('DomainPresetRepository exposes its wrapper', $presetRepo->database() === $dbS);
checkR('the three hot-path repositories still accept a bare PDO (legacy callers)',
    (new App\Skills\SkillRepository(new PDO('sqlite:' . $sqliteFile)))->database() !== null
    && (new App\Profiles\UserProfileRepository(null))->database() === null
    && (new App\DomainPresets\DomainPresetRepository())->database() === null);

// And the wrapper is genuinely used: a fault on the first statement of a
// skill lookup must still produce the answer.
[$dbK, $injK] = $makeStack(0);
$injK->exec('CREATE TABLE skills (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, name TEXT,'
    . ' description TEXT, definition TEXT, is_system INTEGER, use_count INTEGER DEFAULT 0)');
$injK->exec("INSERT INTO skills (slug, name, description, definition, is_system, use_count)"
    . " VALUES ('mathematics-reasoning', 'Math', 'd', '{}', 1, 0)");
$skillRepo2 = new App\Skills\SkillRepository($dbK);
checkR('SkillRepository reads a skill through the wrapper',
    $skillRepo2->findBySlug('mathematics-reasoning')?->slug === 'mathematics-reasoning');
$injK->arm(1, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
$found = $skillRepo2->findBySlug('mathematics-reasoning');
checkR('a skill lookup survives a connection loss (this is what killed every production job)',
    $found?->slug === 'mathematics-reasoning' && $dbK->reconnects() === 1,
    'reconnects=' . $dbK->reconnects() . ' found=' . var_export($found?->slug, true));

// A fresh stack: the previous reconnect retired the injector, so the
// fault would have nowhere to land.
[$dbK2, $injK2] = $makeStack(0);
$skillRepo3 = new App\Skills\SkillRepository($dbK2);
$injK2->arm(1, 'SQLSTATE[HY000]: General error: 1053 Server shutdown in progress');
$skillRepo3->incrementUseCount((int)$found?->id);
checkR('incrementUseCount() survives a connection loss and lands the write',
    $dbK2->reconnects() === 1
    && (int)$skillRepo3->findBySlug('mathematics-reasoning')?->useCount === 1,
    'reconnects=' . $dbK2->reconnects()
    . ' use_count=' . var_export($skillRepo3->findBySlug('mathematics-reasoning')?->useCount, true));

// =====================================================================
// Cleanup
// =====================================================================
DbResilienceJournal::reset();
Config::reset();
rmTreeR($work);

echo implode("\n", $report) . "\n";
echo "----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
