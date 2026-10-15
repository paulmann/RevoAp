<?php
/**
 * SessionSafetyNetTest: the orphan-closing safety net writes LEGAL enum
 * values (v2.1.0, T6 finding #7).
 *
 * Runs without PHPUnit, MySQL or network: `php tests/SessionSafetyNetTest.php`.
 *
 * The bug this pins: closeIfOrphaned() wrote `consensus_status = ''`.
 * That column is an ENUM; '' is not a member. A strict-mode MySQL/MariaDB
 * refused the whole UPDATE (error 1265), the catch below it logged
 * `worker.session.safety_net_failed`, and the orphaned session stayed
 * `running` FOREVER — the safety net silently failing at the one job it
 * exists for. Non-strict servers stored '' instead, which is how empty
 * consensus_status rows appeared in production (ROAD_MAP item 8). The
 * suite's sqlite fixtures never caught it because sqlite does not enforce
 * ENUMs — so this test builds the sqlite table WITH a CHECK constraint
 * that has exactly the enum's value set: violating it throws, faithfully
 * reproducing strict-mode semantics offline.
 *
 * Migration 025 adds the honest value 'none' ("closed without a verdict,
 * none owed"); the safety net now writes it, and an actualize report writes
 * 'single' (one model produced the answer — the 022 semantics).
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Jobs\Job;
use App\Jobs\JobStatus;
use App\Jobs\SessionSafetyNet;
use App\Models\ModelsActualizeReport;
use App\Storage\SessionRepository;

$passes = 0;
$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passes, $failures;
    if ($ok) { $passes++; echo "  ok  $label\n"; }
    else { $failures++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

/** sqlite council_sessions whose consensus_status/status are CHECK-bound to
 *  exactly the MySQL enum value sets — a violation throws like strict mode. */
function makeDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE council_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        skill_id INTEGER NOT NULL DEFAULT 0,
        query_text TEXT NOT NULL DEFAULT '',
        final_answer TEXT NULL,
        consensus_score REAL NULL,
        consensus_status TEXT NOT NULL DEFAULT 'pending'
            CHECK (consensus_status IN ('pending','agreement','partial','conflict','single','none')),
        status TEXT NOT NULL DEFAULT 'running'
            CHECK (status IN ('running','completed','failed')),
        total_tokens INTEGER NOT NULL DEFAULT 0,
        duration_ms INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        completed_at TEXT NULL
    )");
    return $pdo;
}

function makeJob(int $id, int $sessionId, JobStatus $status, array $payload = []): Job
{
    return new Job(
        id: $id, sessionId: $sessionId, userId: null, status: $status,
        priority: 0, payload: $payload + ['query' => 'q'], result: null, error: null,
        lockedBy: null, lockedAt: null, heartbeatAt: null,
        createdAt: '2026-10-15 12:00:00', startedAt: null, finishedAt: null,
    );
}

function sessionRow(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM council_sessions WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

/** The same probe the worker passes: raw PDO read of status+answer. */
function makeProbe(PDO $pdo): callable
{
    return static function (int $sid) use ($pdo): ?array {
        $stmt = $pdo->prepare('SELECT status, final_answer FROM council_sessions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sid]);
        $row = $stmt->fetch();
        return is_array($row)
            ? ['status' => (string)($row['status'] ?? ''), 'answer' => $row['final_answer'] ?? null]
            : null;
    };
}

/* ====================================================================== */

section('the fixture enforces the enum like strict-mode MySQL');

$pdo = makeDb();
$repo = new SessionRepository($pdo);
$sid = $repo->create(0, 'fixture', null);
$threw = false;
try {
    // The historical write: '' is not in the value set.
    $repo->complete($sid, 'answer', null, '', 0, 1, 'completed');
} catch (\Throwable) {
    $threw = true;
}
check("consensus_status = '' is REFUSED by the fixture (error 1265 equivalent)", $threw);
check("the refused write left the session running", (string)(sessionRow($pdo, $sid)['status'] ?? '') === 'running');

section('closeIfOrphaned writes legal values and actually closes');

$pdo = makeDb();
$repo = new SessionRepository($pdo);
$probe = makeProbe($pdo);

$orphan = $repo->create(0, 'orphaned by a dead worker', null);
$closed = SessionSafetyNet::closeIfOrphaned(makeJob(1, $orphan, JobStatus::Completed), JobStatus::Completed, $repo, $probe);
$row = sessionRow($pdo, $orphan);
check('a completed orphan is closed', $closed === true && ($row['status'] ?? '') === 'completed', json_encode($row['status'] ?? null));
check("closed with consensus_status 'none' (legal, honest)", ($row['consensus_status'] ?? '') === 'none', (string)($row['consensus_status'] ?? ''));
check('score stays NULL — none never carries a measured score', $row['consensus_score'] === null);

$failedOrphan = $repo->create(0, 'orphaned by a failed job', null);
SessionSafetyNet::closeIfOrphaned(makeJob(2, $failedOrphan, JobStatus::Failed), JobStatus::Failed, $repo, $probe);
check('a failed orphan closes as failed', (string)(sessionRow($pdo, $failedOrphan)['status'] ?? '') === 'failed');

$cancelledOrphan = $repo->create(0, 'orphaned by a cancelled job', null);
SessionSafetyNet::closeIfOrphaned(makeJob(3, $cancelledOrphan, JobStatus::Cancelled), JobStatus::Cancelled, $repo, $probe);
check('a cancelled orphan closes as failed (it produced nothing)', (string)(sessionRow($pdo, $cancelledOrphan)['status'] ?? '') === 'failed');

section('the net stays out of sessions that are not orphans');

$answered = $repo->create(0, 'has its own answer', null);
$repo->complete($answered, 'the real final answer', 0.9, 'agreement', 10, 100, 'completed');
$touched = SessionSafetyNet::closeIfOrphaned(makeJob(4, $answered, JobStatus::Completed), JobStatus::Completed, $repo, $probe);
check('a closed session with an answer is left alone', $touched === false
    && (string)(sessionRow($pdo, $answered)['consensus_status'] ?? '') === 'agreement');

$running = $repo->create(0, 'still queued job', null);
$touched = SessionSafetyNet::closeIfOrphaned(makeJob(5, $running, JobStatus::Running), JobStatus::Running, $repo, $probe);
check('a non-terminal job does not close anything', $touched === false
    && (string)(sessionRow($pdo, $running)['status'] ?? '') === 'running');

section('the schema and its callers agree on the value set');

$schema = (string)file_get_contents(__DIR__ . '/../db/schema.sql');
check("schema.sql's enum carries 'none'", str_contains($schema, "ENUM('pending','agreement','partial','conflict','single','none')"));
check('schema.sql bumped council_sessions to 1.0.6', str_contains($schema, '@table-version council_sessions 1.0.6'));
$mig = (string)file_get_contents(__DIR__ . '/../db/migrations/025_consensus_status_none.sql');
check('migration 025 declares the same table-version', str_contains($mig, '@table-version council_sessions 1.0.6'));
check('migration 025 is probe-guarded (idempotent)', str_contains($mig, "COLUMN_TYPE LIKE '%none%'") && str_contains($mig, 'DO 0'));
$netSrc = (string)file_get_contents(__DIR__ . '/../src/Jobs/SessionSafetyNet.php');
check("the safety net no longer writes ''", !str_contains($netSrc, "consensusStatus: ''"));
check("the safety net writes 'none'", str_contains($netSrc, "consensusStatus: 'none'"));
check("actualize reports are 'single' (one model, the answer)", ModelsActualizeReport::CONSENSUS_STATUS === 'single');

/* ====================================================================== */

echo "\n==================================\n";
echo "SessionSafetyNetTest: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
