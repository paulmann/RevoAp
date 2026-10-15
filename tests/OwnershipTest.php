<?php
/**
 * OwnershipTest: multi-user read scoping (v2.1.0, T5).
 *
 * Runs without PHPUnit, MySQL or network access:
 * `php tests/OwnershipTest.php` — sqlite in-memory.
 *
 * What is covered, and the failure each case exists to prevent
 * -----------------------------------------------------------
 *  - Identity semantics: exactly one of canSeeAll()/scopedUserId()/
 *    seesNothing() is the answer for each kind, and a malformed user
 *    identity fails CLOSED (a broken session must never degrade into
 *    "no filter", which is the whole table).
 *  - Identity::fromRequest(): an end-user session outranks anonymous; an
 *    open operator gate outranks both; no session and no gate is anonymous.
 *  - HistoryService::list()/detail(): admin sees all, user sees own,
 *    anonymous-strict sees an EMPTY ok answer (not an error — "you own
 *    nothing" must be indistinguishable from "nothing matches"), and the
 *    legacy null-viewer call shape still sees all (v1.8.6 installs must
 *    not change behaviour on upgrade).
 *  - Unattributed sessions (user_id NULL) belong to NO end user: invisible
 *    to every scoped viewer, visible to operators. Adopting them into a
 *    user's history would fabricate ownership.
 *  - A scoped viewer probing a foreign session id gets the same null a
 *    missing session gets: the detail endpoint must not double as an
 *    existence oracle.
 *  - StatisticsService::summary(): the user filter applies to BOTH the
 *    session counts and the per-model token money, and echoes user_id so
 *    two panels can never show different "totals" for the same period.
 *  - ShareController::create(): minting a share republishes full run
 *    content, so ownership is enforced at the mint — a scoped user sharing
 *    a foreign session gets 404 session_not_found (not 403: existence),
 *    anonymous-strict gets 404, owners/operators/legacy get tokens.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Auth\Identity;
use App\Core\Config;
use App\History\HistoryService;
use App\Http\Controllers\ShareController;
use App\Statistics\StatisticsAggregator;
use App\Statistics\StatisticsService;
use App\Storage\SessionRepository;
use App\Storage\ShareRepository;
use App\Storage\StepRepository;

$passes = 0;
$failures = 0;
$report   = [];

// NOTE: like ApiLaunchTest, this file deliberately prints NOTHING until the
// very end. Any earlier output makes PHP CLI consider headers sent, and the
// ShareController scenarios assert on http_response_code() — which silently
// stops working once a single "ok" line has been echoed.
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passes, $failures, $report;
    if ($ok) { $passes++; $report[] = "  ok  $label"; }
    else { $failures++; $report[] = "  FAIL $label" . ($detail !== '' ? " — $detail" : ''); }
}

function section(string $title): void
{
    global $report;
    $report[] = "";
    $report[] = "== $title ==";
}

function makeDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        external_id TEXT NOT NULL UNIQUE, email TEXT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE TABLE skills (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, description TEXT NULL,
        definition TEXT NOT NULL DEFAULT \'{}\', is_system INTEGER DEFAULT 0,
        use_count INTEGER DEFAULT 0,
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
        summary TEXT NULL, meta TEXT NULL,
        started_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        ended_at TEXT NULL
    )');
    $pdo->exec('CREATE TABLE council_model_responses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        step_id INTEGER NOT NULL,
        model TEXT NOT NULL DEFAULT \'\', provider TEXT NOT NULL DEFAULT \'\',
        role_name TEXT NULL, raw_content TEXT NOT NULL DEFAULT \'\',
        normalized_response TEXT NULL,
        prompt_tokens INTEGER DEFAULT 0, completion_tokens INTEGER DEFAULT 0,
        total_tokens INTEGER DEFAULT 0, latency_ms INTEGER DEFAULT 0,
        tokens_estimated INTEGER DEFAULT 0,
        cache_read_tokens INTEGER NULL, cache_write_tokens INTEGER NULL,
        cache_discount REAL NULL, cost REAL NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    // detail() also reads the event log and two system-fact tables; they
    // exist empty rather than letting a missing-table error masquerade as
    // an ownership result.
    $pdo->exec('CREATE TABLE pipeline_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        step_id INTEGER NULL,
        event_type TEXT NOT NULL DEFAULT \'\',
        payload TEXT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE TABLE app_config (
        config_key TEXT PRIMARY KEY,
        config_value TEXT NULL
    )');
    $pdo->exec('CREATE TABLE revoap_worker_presence (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        worker_id TEXT NOT NULL DEFAULT \'\',
        app_version TEXT NULL,
        code_state TEXT NULL,
        status TEXT NULL,
        current_job_id INTEGER NULL,
        last_seen_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('INSERT INTO skills (slug, name) VALUES (\'generic\', \'Generic\')');
    return $pdo;
}

function makeUser(PDO $pdo, string $ext): int
{
    $pdo->prepare('INSERT INTO users (external_id) VALUES (:e)')->execute(['e' => $ext]);
    return (int)$pdo->lastInsertId();
}

function makeSession(PDO $pdo, ?int $uid, string $query, string $status = 'completed', int $tokens = 0): int
{
    $pdo->prepare(
        'INSERT INTO council_sessions (user_id, skill_id, skill_slug, query_text, status, total_tokens, created_at)
         VALUES (:u, 1, \'generic\', :q, :s, :t, datetime(\'now\'))'
    )->execute(['u' => $uid, 'q' => $query, 's' => $status, 't' => $tokens]);
    return (int)$pdo->lastInsertId();
}

/** One L1 step with meta + a matching response row (the Statistics source). */
function makeCall(PDO $pdo, int $sid, string $provider, string $model, int $pt, int $ct, ?float $cost): void
{
    $pdo->prepare(
        'INSERT INTO council_steps (session_id, step_key, label, order_index, status, meta)
         VALUES (:s, :k, \'L1\', 1, \'completed\', :m)'
    )->execute(['s' => $sid, 'k' => $provider . '/' . $model, 'm' => json_encode([
        'provider' => $provider, 'model' => $model,
        'prompt_tokens' => $pt, 'completion_tokens' => $ct, 'total_tokens' => $pt + $ct,
        'latency_ms' => 100, 'cost' => $cost,
    ])]);
    $stepId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO council_model_responses (step_id, model, provider, prompt_tokens, completion_tokens, total_tokens, cost)
         VALUES (:st, :m, :p, :pt, :ct, :tt, :c)'
    )->execute(['st' => $stepId, 'm' => $model, 'p' => $provider, 'pt' => $pt, 'ct' => $ct, 'tt' => $pt + $ct, 'c' => $cost]);
}

/* ------------------------------------------------------------------ */
/* Session harness: Identity::fromRequest reads $_SESSION, and CLI has   */
/* none. A real (file-backed) session is started once; each scenario     */
/* rewrites the keys it needs.                                           */
/* ------------------------------------------------------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id('ownership-test-' . bin2hex(random_bytes(6)));
    session_start();
}
function setEndUser(?int $uid): void
{
    unset($_SESSION[Identity::SESSION_USER_KEY]);
    if ($uid !== null) {
        $_SESSION[Identity::SESSION_USER_KEY] = $uid;
    }
}
function clearAdminEnv(): void
{
    putenv('REVOAP_ADMIN_TOKEN');
    unset($_ENV['REVOAP_ADMIN_TOKEN'], $_SERVER['HTTP_X_ADMIN_TOKEN'], $_SERVER['HTTP_AUTHORIZATION']);
}

/* ====================================================================== */

section('Identity: the value object is the whole ownership contract');

$admin = Identity::admin('operator');
check('admin sees all', $admin->canSeeAll() && $admin->scopedUserId() === null && !$admin->seesNothing());
$user = Identity::user(42);
check('user is scoped to its own id', !$user->canSeeAll() && $user->scopedUserId() === 42 && !$user->seesNothing());
$anonOpen = Identity::anonymous(false);
check('anonymous on a legacy-open install sees all', $anonOpen->canSeeAll() && !$anonOpen->seesNothing());
$anonStrict = Identity::anonymous(true);
check('anonymous under enforcement sees NOTHING', $anonStrict->seesNothing() && !$anonStrict->canSeeAll() && $anonStrict->scopedUserId() === null);
$brokenUser = Identity::user(0);
check('a user identity with no usable id fails CLOSED', $brokenUser->seesNothing() && !$brokenUser->canSeeAll());

section('Identity::fromRequest: one resolver, no per-controller reinvention');

clearAdminEnv();
setEndUser(null);
$id = Identity::fromRequest(false);
check('no session + closed gate → anonymous (legacy)', $id->isAnonymous() && !$id->seesNothing());
$id = Identity::fromRequest(true);
check('no session + enforcement flag → anonymous strict', $id->isAnonymous() && $id->seesNothing());
setEndUser(9);
$id = Identity::fromRequest(true);
check('end-user session → scoped identity (flag irrelevant)', $id->isUser() && $id->scopedUserId() === 9);
putenv('REVOAP_ADMIN_TOKEN=t5-secret-token-value');
$_ENV['REVOAP_ADMIN_TOKEN'] = 't5-secret-token-value';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 't5-secret-token-value';
$id = Identity::fromRequest(true);
check('open operator gate outranks everything → admin', $id->isAdmin() && $id->canSeeAll());
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'wrong-token';
$id = Identity::fromRequest(true);
check('wrong token falls back to the end-user session', $id->isUser() && $id->scopedUserId() === 9);
setEndUser(null);
clearAdminEnv();
$id = Identity::fromRequest(false);
check('gate closed again → anonymous', $id->isAnonymous());

/* ====================================================================== */

section('HistoryService: list and detail are scoped by viewer');

$pdo = makeDb();
$alice = makeUser($pdo, 'alice');
$bob   = makeUser($pdo, 'bob');
$sA = makeSession($pdo, $alice, 'Alice secret plan', 'completed', 100);
$sB = makeSession($pdo, $bob, 'Bob secret plan', 'completed', 200);
$sN = makeSession($pdo, null, 'Unattributed run', 'completed', 50);
$history = new HistoryService($pdo);

$res = $history->list(['limit' => 50], null);
check('legacy null viewer sees everything (v1.8.6 unchanged)', ($res['total'] ?? 0) === 3 && ($res['scope'] ?? '') === 'all', json_encode($res['total'] ?? null));

$res = $history->list(['limit' => 50], Identity::admin());
check('admin sees everything', ($res['total'] ?? 0) === 3);

$res = $history->list(['limit' => 50], Identity::user($alice));
$ids = array_map(static fn($i) => (int)$i['id'], $res['items'] ?? []);
check('user sees ONLY its own sessions', ($res['total'] ?? 0) === 1 && $ids === [$sA], json_encode($ids));
check('scoped answer announces its scope', ($res['scope'] ?? '') === 'user');

$res = $history->list(['limit' => 50], Identity::anonymous(true));
check('anonymous-strict gets an EMPTY ok answer, not an error', ($res['ok'] ?? false) === true && ($res['total'] ?? -1) === 0 && ($res['items'] ?? [1]) === [] && ($res['ownership'] ?? '') === 'restricted', json_encode(['ok' => $res['ok'] ?? null, 'total' => $res['total'] ?? null]));

$res = $history->list(['limit' => 50], Identity::anonymous(false));
check('anonymous on a legacy-open install still sees all', ($res['total'] ?? 0) === 3);

// Search must stay inside the scope: q matching somebody else's run
// answers empty, not "found but hidden".
$res = $history->list(['limit' => 50, 'q' => 'Bob secret'], Identity::user($alice));
check('search cannot cross the ownership boundary', ($res['total'] ?? -1) === 0, json_encode($res['total'] ?? null));
$res = $history->list(['limit' => 50, 'q' => 'Bob secret'], Identity::admin());
check('the same search finds it for an operator', ($res['total'] ?? 0) === 1);

$detail = $history->detail($sA, Identity::user($alice));
check('owner reads its own session detail', is_array($detail) && ($detail['ok'] ?? false) !== false);
$detail = $history->detail($sB, Identity::user($alice));
check('foreign session detail → null (404, no existence oracle)', $detail === null);
$detail = $history->detail($sN, Identity::user($alice));
check('unattributed session belongs to no end user → null', $detail === null);
$detail = $history->detail($sN, Identity::admin());
check('operator sees the unattributed session', is_array($detail));
$detail = $history->detail($sB, Identity::anonymous(true));
check('anonymous-strict detail → null', $detail === null);
$detail = $history->detail($sB, null);
check('legacy null viewer detail unchanged', is_array($detail));
$missing = $history->detail(99999, Identity::admin());
check('genuinely missing id still → null', $missing === null);

/* ====================================================================== */

section('StatisticsService: the same totals, scoped');

makeCall($pdo, $sA, 'stub', 'stub/echo', 100, 50, 0.01);
makeCall($pdo, $sB, 'stub', 'stub/echo', 200, 100, 0.02);
makeCall($pdo, $sB, 'openrouter', 'openai/gpt-5.5', 40, 10, null);
$stats = new StatisticsService($pdo, new StatisticsAggregator());
// Explicit anchor, as the StatisticsPeriod docblock instructs: 'to' is
// exclusive, and a fixture row created in the same second as an implicit
// "now" anchor would fall outside the window — the test would then measure
// the clock instead of the scope.
$anchor = new DateTimeImmutable('now +5 minutes', new DateTimeZone('UTC'));

$all = $stats->summary('all', $anchor);
check('unscoped summary counts every session', ($all['sessions']['total'] ?? 0) === 3);
check('unscoped tokens cover every user', ($all['tokens']['total'] ?? 0) === 150 + 300 + 50, json_encode($all['tokens']['total'] ?? null));

$aliceStats = $stats->summary('all', $anchor, $alice);
check('scoped summary counts only owned sessions', ($aliceStats['sessions']['total'] ?? 0) === 1);
check('scoped tokens are the owner\'s alone', ($aliceStats['tokens']['total'] ?? 0) === 150, json_encode($aliceStats['tokens']['total'] ?? null));
check('per-model breakdown follows the scope', count($aliceStats['per_model'] ?? []) === 1);
check('scope is echoed back', ($aliceStats['user_id'] ?? null) === $alice);
check('cost sums only owned priced calls', abs((float)($aliceStats['cost']['total'] ?? 0) - 0.01) < 1e-9, json_encode($aliceStats['cost']['total'] ?? null));

$bobStats = $stats->summary('all', $anchor, $bob);
check('the other user gets the other numbers', ($bobStats['sessions']['total'] ?? 0) === 1 && ($bobStats['tokens']['total'] ?? 0) === 350, json_encode(['sessions' => $bobStats['sessions']['total'] ?? null, 'tokens' => $bobStats['tokens']['total'] ?? null]));

$none = $stats->summary('all', $anchor, -1);
check('an impossible id answers the empty shape (strict-anonymous path)', ($none['ok'] ?? false) === true && ($none['sessions']['total'] ?? -1) === 0 && ($none['tokens']['total'] ?? -1) === 0);

$nullOwner = $stats->summary('all', $anchor, 0);
// user_id = 0 matches no row (ids start at 1) — the unattributed bucket is
// deliberately NOT addressable through the statistics scope.
check('unattributed runs are not adopted by scope 0', ($nullOwner['sessions']['total'] ?? -1) === 0);

/* ====================================================================== */

section('ShareController: the mint is the leak, so the mint is gated');

Config::set(['app' => ['debug' => false, 'version' => '2.1.0', 'name' => 'RevoAp']], __DIR__);
clearAdminEnv();

$shareRepo = new ShareRepository($pdo);
$sessions  = new SessionRepository($pdo);
$steps     = new StepRepository($pdo);
$ctrl = new ShareController($shareRepo, $sessions, $steps, $history, $pdo, null);

$_GET = [];
setEndUser($alice);
$_GET['session_id'] = (string)$sB;
$body = json_decode($ctrl->create(), true);
check('user sharing a FOREIGN session → 404 session_not_found', http_response_code() === 404 && ($body['error'] ?? '') === 'session_not_found', json_encode($body));

$_GET['session_id'] = (string)$sN;
http_response_code(200);
$body = json_decode($ctrl->create(), true);
check('unattributed session cannot be shared by an end user', http_response_code() === 404 && ($body['error'] ?? '') === 'session_not_found');

$_GET['session_id'] = (string)$sA;
http_response_code(200);
$body = json_decode($ctrl->create(), true);
check('owner CAN share its own session', ($body['ok'] ?? false) === true && (string)($body['token'] ?? '') !== '', json_encode($body));
$aliceToken = (string)($body['token'] ?? '');

setEndUser(null);
$_GET['session_id'] = (string)$sA;
http_response_code(200);
$body = json_decode($ctrl->create(), true);
check('anonymous on a legacy-open install keeps the v1 behaviour', ($body['ok'] ?? false) === true && ($body['token'] ?? '') === $aliceToken, json_encode($body['error'] ?? $body['ok'] ?? null));

// Enforcement ON: the controller consults the flag registry. sqlite has no
// revoap_feature_flags here; build it and insert the override row.
$pdo->exec('CREATE TABLE revoap_feature_flags (
    flag_key TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0,
    rollout TEXT NOT NULL DEFAULT \'all\', rollout_percent INTEGER NOT NULL DEFAULT 100,
    description TEXT NULL, updated_by TEXT NULL, updated_at INTEGER NOT NULL DEFAULT 0
)');
$pdo->exec("INSERT INTO revoap_feature_flags (flag_key, enabled, rollout, rollout_percent, updated_at) VALUES ('multiuser.ownership', 1, 'all', 100, 0)");
$flags = new App\Admin\FeatureFlags\FeatureFlagRegistry(App\Core\Database::fromPdo($pdo));
$ctrlStrict = new ShareController($shareRepo, $sessions, $steps, $history, $pdo, $flags);

$_GET['session_id'] = (string)$sA;
http_response_code(200);
$body = json_decode($ctrlStrict->create(), true);
check('anonymous under enforcement cannot mint shares', http_response_code() === 404 && ($body['error'] ?? '') === 'session_not_found', json_encode($body));

setEndUser($alice);
http_response_code(200);
$body = json_decode($ctrlStrict->create(), true);
check('the owner still can under enforcement', ($body['ok'] ?? false) === true);

putenv('REVOAP_ADMIN_TOKEN=t5-secret-token-value');
$_ENV['REVOAP_ADMIN_TOKEN'] = 't5-secret-token-value';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 't5-secret-token-value';
setEndUser(null);
$_GET['session_id'] = (string)$sB;
http_response_code(200);
$body = json_decode($ctrlStrict->create(), true);
check('operator can share any session under enforcement', ($body['ok'] ?? false) === true, json_encode($body['error'] ?? null));
clearAdminEnv();
setEndUser(null);
$_GET = [];

/* ====================================================================== */

echo implode("\n", $report), "\n";
echo "\n==================================\n";
echo "OwnershipTest: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
