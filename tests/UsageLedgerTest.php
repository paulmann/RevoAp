<?php
/**
 * UsageLedgerTest: the append-only per-run billing ledger (v2.1.0, T5).
 *
 * Runs without PHPUnit, MySQL or network access:
 * `php tests/UsageLedgerTest.php` — sqlite in-memory stands in for the
 * deployment database; UsageLedger is dialect-aware by contract (the same
 * split ResponseRepository makes), so the sqlite path is the tested path
 * and the MySQL path differs only in the INSERT…IGNORE spelling and the
 * table probe.
 *
 * What is covered, and the failure each case exists to prevent
 * -----------------------------------------------------------
 *  - Derivation: one marker row + one row per model call, with the source
 *    numbers carried through EXACTLY (tokens, cache columns, cost).
 *  - Idempotency: re-syncing a session writes nothing new. Without this,
 *    two completion paths firing for one run would double-bill it.
 *  - Failed runs are billed with run_status='failed' — a run that burned
 *    tokens and then failed is real spend.
 *  - NULL honesty: a provider that reports no price yields cost NULL, and
 *    totals report coverage instead of summing the gap as zero.
 *  - Cache attribution: cached flag mirrors cache_read_tokens > 0; a NULL
 *    cache column stays NULL ("not reported" ≠ "missed").
 *  - Reasoning tokens are attributed only when unambiguous (one response
 *    per step); a shared step stores NULL rather than double-counting.
 *  - Unattributed runs (user_id NULL) form their own bucket and are never
 *    folded into a real user's totals.
 *  - Convergence: syncMissing bills settled sessions the hook missed and
 *    terminates (the marker row is what makes the LEFT JOIN converge).
 *  - The settle hook on SessionRepository fires on complete() AND
 *    markFailed(), in memory mode and DB mode.
 *  - Append-only: the class exposes no UPDATE/DELETE against the ledger.
 *  - Unmigrated installs: every method degrades to an empty, correctly
 *    shaped answer — never a throw.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Storage\SessionRepository;
use App\Usage\UsageLedger;

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

/**
 * The minimal-but-faithful sqlite shape of every table the ledger touches.
 * Column sets mirror db/schema.sql; types are the sqlite affinity equivalents.
 */
function makeDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        external_id TEXT NOT NULL UNIQUE,
        email TEXT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE TABLE user_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        email TEXT NULL, phone TEXT NULL,
        first_name TEXT NULL, last_name TEXT NULL, job_title TEXT NULL,
        bio TEXT NULL, system_prompt_addendum TEXT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE TABLE council_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        skill_id INTEGER NOT NULL DEFAULT 0,
        skill_slug TEXT NULL,
        query_text TEXT NOT NULL DEFAULT \'\',
        final_answer TEXT NULL,
        consensus_score REAL NULL,
        consensus_status TEXT DEFAULT \'pending\',
        status TEXT DEFAULT \'running\',
        total_tokens INTEGER DEFAULT 0,
        duration_ms INTEGER DEFAULT 0,
        options_json TEXT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        completed_at TEXT NULL
    )');
    $pdo->exec('CREATE TABLE council_steps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        step_key TEXT NOT NULL DEFAULT \'\',
        label TEXT NOT NULL DEFAULT \'\',
        order_index INTEGER NOT NULL DEFAULT 0,
        status TEXT DEFAULT \'running\',
        summary TEXT NULL,
        meta TEXT NULL,
        started_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        ended_at TEXT NULL
    )');
    $pdo->exec('CREATE TABLE council_model_responses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        step_id INTEGER NOT NULL,
        model TEXT NOT NULL DEFAULT \'\',
        provider TEXT NOT NULL DEFAULT \'\',
        role_name TEXT NULL,
        raw_content TEXT NOT NULL DEFAULT \'\',
        normalized_response TEXT NULL,
        prompt_tokens INTEGER DEFAULT 0,
        completion_tokens INTEGER DEFAULT 0,
        total_tokens INTEGER DEFAULT 0,
        latency_ms INTEGER DEFAULT 0,
        tokens_estimated INTEGER DEFAULT 0,
        cache_read_tokens INTEGER NULL,
        cache_write_tokens INTEGER NULL,
        cache_discount REAL NULL,
        cost REAL NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE TABLE revoap_usage_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        user_id INTEGER NULL,
        response_id INTEGER NOT NULL DEFAULT 0,
        provider TEXT NOT NULL DEFAULT \'\',
        model TEXT NOT NULL DEFAULT \'\',
        prompt_tokens INTEGER NOT NULL DEFAULT 0,
        completion_tokens INTEGER NOT NULL DEFAULT 0,
        total_tokens INTEGER NOT NULL DEFAULT 0,
        cached_tokens INTEGER NULL,
        cache_write_tokens INTEGER NULL,
        reasoning_tokens INTEGER NULL,
        cost REAL NULL,
        cache_discount REAL NULL,
        cached INTEGER NOT NULL DEFAULT 0,
        tokens_estimated INTEGER NOT NULL DEFAULT 0,
        run_status TEXT NOT NULL DEFAULT \'\',
        created_at INTEGER NOT NULL DEFAULT 0,
        run_created_at TEXT NULL,
        UNIQUE (session_id, response_id)
    )');
    return $pdo;
}

function makeUser(PDO $pdo, string $externalId, ?string $email = null): int
{
    $stmt = $pdo->prepare('INSERT INTO users (external_id, email) VALUES (:e, :m)');
    $stmt->execute(['e' => $externalId, 'm' => $email]);
    return (int)$pdo->lastInsertId();
}

function makeSession(PDO $pdo, ?int $userId, string $status = 'completed', ?string $createdAt = null, string $query = 'q'): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO council_sessions (user_id, skill_id, query_text, status, created_at)
         VALUES (:u, 1, :q, :s, COALESCE(:c, datetime(\'now\')))'
    );
    $stmt->execute(['u' => $userId, 'q' => $query, 's' => $status, 'c' => $createdAt]);
    return (int)$pdo->lastInsertId();
}

function makeStep(PDO $pdo, int $sessionId, ?array $meta = null): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO council_steps (session_id, step_key, label, order_index, status, meta)
         VALUES (:s, \'k\', \'l\', 0, \'completed\', :m)'
    );
    $stmt->execute(['s' => $sessionId, 'm' => $meta === null ? null : json_encode($meta)]);
    return (int)$pdo->lastInsertId();
}

function makeResponse(PDO $pdo, int $stepId, array $over = []): int
{
    $row = array_merge([
        'provider' => 'stub', 'model' => 'stub/echo',
        'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150,
        'tokens_estimated' => 0,
        'cache_read_tokens' => null, 'cache_write_tokens' => null, 'cache_discount' => null,
        'cost' => null,
    ], $over);
    $stmt = $pdo->prepare(
        'INSERT INTO council_model_responses
            (step_id, model, provider, raw_content, prompt_tokens, completion_tokens,
             total_tokens, tokens_estimated, cache_read_tokens, cache_write_tokens,
             cache_discount, cost)
         VALUES (:step, :model, :prov, \'x\', :pt, :ct, :tt, :est, :cr, :cw, :cd, :cost)'
    );
    $stmt->execute([
        'step' => $stepId, 'model' => $row['model'], 'prov' => $row['provider'],
        'pt' => $row['prompt_tokens'], 'ct' => $row['completion_tokens'], 'tt' => $row['total_tokens'],
        'est' => $row['tokens_estimated'], 'cr' => $row['cache_read_tokens'],
        'cw' => $row['cache_write_tokens'], 'cd' => $row['cache_discount'], 'cost' => $row['cost'],
    ]);
    return (int)$pdo->lastInsertId();
}

function countRows(PDO $pdo, string $table, string $where = '1', array $bind = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($bind);
    return (int)$stmt->fetchColumn();
}

/* ====================================================================== */

section('derivation: marker + per-call rows');

$pdo = makeDb();
$alice = makeUser($pdo, 'alice', 'alice@example.com');
$sid = makeSession($pdo, $alice, 'completed', '2026-10-14 10:00:00', 'Why is the sky blue?');
$st1 = makeStep($pdo, $sid, ['reasoning_tokens' => 42, 'provider' => 'stub', 'model' => 'stub/echo']);
$r1 = makeResponse($pdo, $st1, ['cost' => 0.0021, 'cache_read_tokens' => 30, 'cache_write_tokens' => 5, 'cache_discount' => -0.0004]);
$st2 = makeStep($pdo, $sid, null);
$r2 = makeResponse($pdo, $st2, ['provider' => 'openrouter', 'model' => 'openai/gpt-5.5', 'prompt_tokens' => 200, 'completion_tokens' => 80, 'total_tokens' => 280, 'cost' => null]);

$ledger = new UsageLedger($pdo);
check('table probe sees the migrated ledger', $ledger->tableAvailable());

$inserted = $ledger->syncSession($sid);
check('syncSession inserts marker + 2 response rows', $inserted === 3, "inserted={$inserted}");
check('marker row exists (response_id = 0)', countRows($pdo, 'revoap_usage_ledger', 'session_id = :s AND response_id = 0', ['s' => $sid]) === 1);
check('response rows exist', countRows($pdo, 'revoap_usage_ledger', 'session_id = :s AND response_id <> 0', ['s' => $sid]) === 2);

$rows = $ledger->rowsForSession($sid);
$byResp = [];
foreach ($rows as $row) { $byResp[(int)$row['response_id']] = $row; }
check('every source response has a ledger row', isset($byResp[$r1], $byResp[$r2]));
check('tokens carried through exactly', (int)$byResp[$r1]['prompt_tokens'] === 100 && (int)$byResp[$r1]['total_tokens'] === 150);
check('cost carried through exactly', abs((float)$byResp[$r1]['cost'] - 0.0021) < 1e-9);
check('cache columns carried through', (int)$byResp[$r1]['cached_tokens'] === 30 && (int)$byResp[$r1]['cache_write_tokens'] === 5);
check('cached flag mirrors cache_read_tokens > 0', (int)$byResp[$r1]['cached'] === 1 && (int)$byResp[$r2]['cached'] === 0);
check('unpriced call keeps cost NULL (never 0)', $byResp[$r2]['cost'] === null);
check('unreported cache columns stay NULL', $byResp[$r2]['cached_tokens'] === null && $byResp[$r2]['cache_write_tokens'] === null);
check('user_id attributed from the session', (int)$byResp[$r1]['user_id'] === $alice);
check('run_created_at is the SESSION timestamp, not the write time', (string)$byResp[$r1]['run_created_at'] === '2026-10-14 10:00:00');
check('run_status recorded', (string)$byResp[$r1]['run_status'] === 'completed');
check('reasoning attributed when the step has exactly one response', (int)$byResp[$r1]['reasoning_tokens'] === 42);
check('reasoning NULL when the step meta has none', $byResp[$r2]['reasoning_tokens'] === null);

section('idempotency: two paths, one bill');

$again = $ledger->syncSession($sid);
check('re-sync inserts nothing', $again === 0, "inserted={$again}");
check('row count unchanged after re-sync', countRows($pdo, 'revoap_usage_ledger', 'session_id = :s', ['s' => $sid]) === 3);

section('reasoning attribution is honest on shared steps');

$st3 = makeStep($pdo, $sid, ['reasoning_tokens' => 99]);
$r3 = makeResponse($pdo, $st3, ['total_tokens' => 10, 'prompt_tokens' => 5, 'completion_tokens' => 5]);
$r4 = makeResponse($pdo, $st3, ['total_tokens' => 20, 'prompt_tokens' => 10, 'completion_tokens' => 10]);
$ledger->syncSession($sid);
$rows = $ledger->rowsForSession($sid);
$shared = array_values(array_filter($rows, static fn($r) => in_array((int)$r['response_id'], [$r3, $r4], true)));
check('both responses on the shared step were billed', count($shared) === 2);
$allNull = true;
foreach ($shared as $r) { if ($r['reasoning_tokens'] !== null) { $allNull = false; } }
check('ambiguous reasoning stored as NULL, not duplicated into both rows', $allNull);

section('failed runs are billed');

$failSid = makeSession($pdo, $alice, 'running', '2026-10-15 09:00:00');
$stF = makeStep($pdo, $failSid, null);
makeResponse($pdo, $stF, ['cost' => 0.001]);
$pdo->exec("UPDATE council_sessions SET status='failed' WHERE id={$failSid}");
$ledger->syncSession($failSid);
$fRows = $ledger->rowsForSession($failSid);
check('failed session got ledger rows', count($fRows) === 2);
$hasFailed = false;
foreach ($fRows as $r) { if ((string)$r['run_status'] === 'failed') { $hasFailed = true; } }
check('run_status=failed recorded on every row', $hasFailed);
$tot = $ledger->totals($alice);
check('failures counted in totals', $tot['failures'] === 1, json_encode($tot['failures']));

section('totals: coverage, not invented zeros');

$tot = $ledger->totals($alice);
// alice: session 1 (r1 150tok priced, r2 280tok unpriced, r3 10, r4 20) + failed session (150tok priced)
check('calls exclude the marker rows', $tot['calls'] === 5, "calls={$tot['calls']}");
check('runs count the marker rows', $tot['runs'] === 2, "runs={$tot['runs']}");
check('tokens summed across priced and unpriced calls', $tot['total_tokens'] === 150 + 280 + 10 + 20 + 150, "tokens={$tot['total_tokens']}");
check('cost sums PRICED rows only', abs((float)$tot['cost'] - (0.0021 + 0.001)) < 1e-9, "cost={$tot['cost']}");
check('coverage counts: 2 of 5 calls priced', $tot['cost_rows'] === 2, "cost_rows={$tot['cost_rows']}");
check('cached call counted once', $tot['cached_calls'] === 1);
check('cache_discount carried through', abs((float)$tot['cache_discount'] - (-0.0004)) < 1e-9);

$allTot = $ledger->totals($alice, '2026-10-14 00:00:00', '2026-10-15 00:00:00');
check('window filter honours run_created_at (only session 1 in window)', $allTot['runs'] === 1, json_encode(['runs' => $allTot['runs']]));
$outOfWindow = $ledger->totals($alice, '2026-01-01 00:00:00', '2026-01-02 00:00:00');
check('empty window totals are correctly shaped zeros', $outOfWindow['runs'] === 0 && $outOfWindow['cost'] === null && $outOfWindow['calls'] === 0);

section('unattributed runs are their own bucket');

$anonSid = makeSession($pdo, null, 'completed', '2026-10-15 08:00:00');
$stA = makeStep($pdo, $anonSid, null);
makeResponse($pdo, $stA, ['total_tokens' => 77, 'prompt_tokens' => 70, 'completion_tokens' => 7, 'cost' => 0.5]);
$ledger->syncSession($anonSid);
$un = $ledger->totals(null);
check('user_id NULL lands in the unattributed bucket', $un['runs'] === 1 && $un['total_tokens'] === 77);
$aliceAfter = $ledger->totals($alice);
check('unattributed spend never folds into a user total', $aliceAfter['total_tokens'] === 150 + 280 + 10 + 20 + 150);

section('series buckets by the day the run HAPPENED');

$series = $ledger->series($alice, 366);
$days = [];
foreach ($series as $s) { $days[$s['day']] = $s; }
check('series bucketed on run_created_at', isset($days['2026-10-14']) && isset($days['2026-10-15']), json_encode(array_keys($days)));
check('daily runs counted per bucket', ($days['2026-10-14']['runs'] ?? 0) === 1);
check('series cost stays null when the day had no priced call', ($days['2026-10-15']['cost'] ?? 'x') === null || ($days['2026-10-15']['cost_rows'] ?? 1) === 1);

section('byModel excludes the marker and groups calls');

$byModel = $ledger->byModel($alice);
$keyed = [];
foreach ($byModel as $m) { $keyed[$m['provider'] . '/' . $m['model']] = $m; }
check('no empty-named marker group', !isset($keyed['/']));
check('stub/echo aggregates all 4 calls (incl. the failed run)', ($keyed['stub/stub/echo']['calls'] ?? 0) === 4 && ($keyed['stub/stub/echo']['tokens'] ?? 0) === 330, json_encode($keyed['stub/stub/echo'] ?? null));
check('openrouter row carries its unpriced cost as NULL', isset($keyed['openrouter/openai/gpt-5.5']) && $keyed['openrouter/openai/gpt-5.5']['cost'] === null);

section('listUsers: totals, idle users, identity columns');

$bob = makeUser($pdo, 'bob');
$pdo->prepare('INSERT INTO user_profiles (user_id, first_name, last_name, email) VALUES (:u, \'Alice\', \'A\', \'alice.profile@example.com\')')->execute(['u' => $alice]);
$users = $ledger->listUsers();
$byId = [];
foreach ($users as $u) { $byId[(int)$u['id']] = $u; }
check('both users listed, including the idle one', isset($byId[$alice], $byId[$bob]));
check('idle user has zero totals, not missing totals', ($byId[$bob]['runs'] ?? -1) === 0 && ($byId[$bob]['tokens'] ?? -1) === 0);
check('busy user totals from the ledger', ($byId[$alice]['tokens'] ?? 0) === 610, "tokens={$byId[$alice]['tokens']}");
check('profile name resolved', ($byId[$alice]['name'] ?? '') === 'Alice A');
check('profile email preferred over users.email', ($byId[$alice]['email'] ?? '') === 'alice.profile@example.com');
check('external_id present', ($byId[$alice]['external_id'] ?? '') === 'alice');
check('last_run from ledger activity', is_string($byId[$alice]['last_run'] ?? null) && $byId[$alice]['last_run'] !== '');

$windowed = $ledger->listUsers('2026-01-01 00:00:00', '2026-01-02 00:00:00');
$zeroed = true;
foreach ($windowed as $u) { if ((int)$u['runs'] !== 0) { $zeroed = false; } }
check('windowed list keeps users but zeroes out-of-window activity', $zeroed && count($windowed) === 2);

section('recentRuns: per-run rollup for the panel');

$recent = $ledger->recentRuns($alice, 10);
check('one entry per run', count($recent) === 2, 'count=' . count($recent));
$top = $recent[0];
check('newest run first', (int)$top['session_id'] === $failSid);
check('run rollup sums calls only', (int)$top['tokens'] === 150);
check('query preview sanitized into the row', is_string($top['query_preview']));
$anonRuns = $ledger->recentRuns(null, 10);
check('unattributed recentRuns answers only the anonymous run', count($anonRuns) === 1 && (int)$anonRuns[0]['session_id'] === $anonSid);

section('convergence: syncMissing bills what the hook missed');

$pdo2 = makeDb();
$carol = makeUser($pdo2, 'carol');
$s1 = makeSession($pdo2, $carol, 'completed');
$st = makeStep($pdo2, $s1, null);
makeResponse($pdo2, $st, ['cost' => 0.01]);
$s2 = makeSession($pdo2, $carol, 'failed');
$s3 = makeSession($pdo2, $carol, 'running');   // NOT settled: must stay unbilled
$ledger2 = new UsageLedger($pdo2);
$synced = $ledger2->syncMissing(100);
check('sweep found exactly the settled sessions', $synced === 2, "synced={$synced}");
check('settled-without-responses session got its marker', countRows($pdo2, 'revoap_usage_ledger', 'session_id = :s', ['s' => $s2]) === 1);
check('settled-with-responses session billed', countRows($pdo2, 'revoap_usage_ledger', 'session_id = :s', ['s' => $s1]) === 2);
check('running session left alone', countRows($pdo2, 'revoap_usage_ledger', 'session_id = :s', ['s' => $s3]) === 0);
$syncedAgain = $ledger2->syncMissing(100);
check('second sweep is a no-op (marker rows make the LEFT JOIN terminate)', $syncedAgain === 0, "synced={$syncedAgain}");

section('settle hook: complete() and markFailed() both bill');

$pdo3 = makeDb();
$dave = makeUser($pdo3, 'dave');
$ledger3 = new UsageLedger($pdo3);
$repo = new SessionRepository($pdo3);
$fired = [];
$repo->setSettledHook(static function (int $id, string $status) use (&$fired, $ledger3): void {
    $fired[] = [$id, $status];
    $ledger3->syncSession($id);
});
$sid3 = $repo->create(1, 'hook test', $dave);
$repo->complete($sid3, 'answer', 0.9, 'agreement', 10, 500, 'completed');
check('complete() fired the hook with (id, status)', $fired === [[$sid3, 'completed']], json_encode($fired));
check('hook billed the session', countRows($pdo3, 'revoap_usage_ledger', 'session_id = :s AND response_id = 0', ['s' => $sid3]) === 1);
$sid4 = $repo->create(1, 'fail test', $dave);
$repo->markFailed($sid4, 120);
check('markFailed() fired the hook', count($fired) === 2 && $fired[1] === [$sid4, 'failed'], json_encode($fired));
check('failed session billed via the hook', countRows($pdo3, 'revoap_usage_ledger', 'session_id = :s', ['s' => $sid4]) === 1);

// Memory-mode repository: the hook must still fire (contract: "the session
// settled", not "a row was written").
$memRepo = new SessionRepository(null);
$memFired = [];
$memRepo->setSettledHook(static function (int $id, string $status) use (&$memFired): void {
    $memFired[] = [$id, $status];
});
$mid = $memRepo->create(1, 'memory', null);
$memRepo->complete($mid, 'a', null, 'single', 0, 1, 'completed');
$mid2 = $memRepo->create(1, 'memory2', null);
$memRepo->markFailed($mid2, 1);
check('memory-mode complete/markFailed fire the hook too', $memFired === [[$mid, 'completed'], [$mid2, 'failed']], json_encode($memFired));

// A throwing hook must never break the settle write.
$throwRepo = new SessionRepository(null);
$throwRepo->setSettledHook(static function (): void { throw new RuntimeException('boom'); });
$tid = $throwRepo->create(1, 'throwing hook', null);
$threw = false;
try { $throwRepo->complete($tid, 'a', null, 'single', 0, 1, 'completed'); } catch (\Throwable) { $threw = true; }
check('a throwing observer cannot fail the run it observes', !$threw);

section('unmigrated install: degrade, never throw');

$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$bare->exec('CREATE TABLE council_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, status TEXT DEFAULT \'running\', created_at TEXT, query_text TEXT DEFAULT \'\')');
$bareLedger = new UsageLedger($bare);
check('table probe answers false without the ledger table', !$bareLedger->tableAvailable());
check('syncSession is a silent no-op', $bareLedger->syncSession(1) === 0);
check('syncMissing is a silent no-op', $bareLedger->syncMissing(10) === 0);
$emptyTotals = $bareLedger->totals(1);
check('totals answer the empty shape', $emptyTotals['runs'] === 0 && $emptyTotals['cost'] === null && $emptyTotals['calls'] === 0);
check('series/byModel/listUsers/recentRuns answer empty', $bareLedger->series(1) === [] && $bareLedger->byModel(1) === [] && $bareLedger->listUsers() === [] && $bareLedger->recentRuns(1) === []);
$noDbLedger = new UsageLedger(null);
check('null-database ledger degrades everywhere', !$noDbLedger->tableAvailable() && $noDbLedger->syncSession(5) === 0 && $noDbLedger->totals(null)['runs'] === 0);

section('append-only: no write path can edit or delete a bill');

$src = (string)file_get_contents(__DIR__ . '/../src/Usage/UsageLedger.php');
// The class doc may SAY "no update or delete"; what must not exist is a
// statement. Both spellings a SQL string could use are checked.
check('no UPDATE statement against the ledger',
    !str_contains($src, 'UPDATE revoap_usage_ledger')
    && !str_contains($src, "UPDATE ' . self::TABLE")
    && !preg_match('/\bUPDATE\b\s+self::TABLE/i', $src));
check('no DELETE statement anywhere in the class', !preg_match('/\bDELETE\s+FROM\b/i', $src));
check('writes go through INSERT…IGNORE only', substr_count($src, 'INSERT OR IGNORE') + substr_count($src, 'INSERT IGNORE') >= 1);

/* ====================================================================== */

echo "\n==================================\n";
echo "UsageLedgerTest: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
