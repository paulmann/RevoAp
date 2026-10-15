<?php
/**
 * UserBudgetTest: per-user quota enforcement (v2.1.0, T5).
 *
 * Runs without PHPUnit, MySQL or network access:
 * `php tests/UserBudgetTest.php` — sqlite in-memory.
 *
 * What is covered, and the failure each case exists to prevent
 * -----------------------------------------------------------
 *  - The enforcer is INERT until the operator switches on `cost.budgets`:
 *    a fresh install must behave exactly as before the feature existed.
 *  - Verdicts: under limit ok · at limit warn keeps the run going ·
 *    at limit block refuses with a Retry-After that matches the window ·
 *    throttle is downgraded to warn WITH an explicit not-enforced warning
 *    (silently pretending to steer models would be a lie).
 *  - The spend window is the CALENDAR period on run_created_at: yesterday's
 *    spend must not bill today's daily budget, and a backfilled run bills
 *    the day it ran.
 *  - Alerts fire once per (budget, period, threshold) — a channel that
 *    re-pages on every run after 95 % gets muted by the operator it was
 *    supposed to protect.
 *  - Coverage honesty: unpriced calls lower cost_coverage and raise the
 *    cost_unreported warning instead of being summed as free.
 *  - Repository validation: every closed set (period, action, currency,
 *    thresholds) refuses garbage, and an UPDATE can never cross users
 *    (an id pointing at somebody else's budget is not_found, not saved).
 *  - The rollup refresh writes spent_amount/period bounds as a side effect.
 *  - Fail-open on infrastructure: no flag registry, no tables, no identity
 *    → ok, checked=false. A missing migration must not stop the run surface.
 *  - BudgetExceededException carries the verdict so every renderer shows
 *    the same numbers.
 *  - window()/periodKey() boundaries, including the Sunday weekly case.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Admin\Budgets\BudgetExceededException;
use App\Admin\Budgets\BudgetRepository;
use App\Admin\Budgets\UserQuotaEnforcer;
use App\Admin\FeatureFlags\FeatureFlagRegistry;
use App\Core\Database;
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

function makeDb(bool $withFlags = true): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE revoap_usage_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL, user_id INTEGER NULL,
        response_id INTEGER NOT NULL DEFAULT 0,
        provider TEXT NOT NULL DEFAULT \'\', model TEXT NOT NULL DEFAULT \'\',
        prompt_tokens INTEGER NOT NULL DEFAULT 0, completion_tokens INTEGER NOT NULL DEFAULT 0,
        total_tokens INTEGER NOT NULL DEFAULT 0,
        cached_tokens INTEGER NULL, cache_write_tokens INTEGER NULL, reasoning_tokens INTEGER NULL,
        cost REAL NULL, cache_discount REAL NULL,
        cached INTEGER NOT NULL DEFAULT 0, tokens_estimated INTEGER NOT NULL DEFAULT 0,
        run_status TEXT NOT NULL DEFAULT \'\', created_at INTEGER NOT NULL DEFAULT 0,
        run_created_at TEXT NULL,
        UNIQUE (session_id, response_id)
    )');
    $pdo->exec('CREATE TABLE revoap_budgets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL DEFAULT \'\',
        scope TEXT NOT NULL DEFAULT \'global\',
        scope_value TEXT NULL,
        currency TEXT NOT NULL DEFAULT \'USD\',
        amount REAL NOT NULL DEFAULT 0,
        period TEXT NOT NULL DEFAULT \'monthly\',
        action_at_limit TEXT NOT NULL DEFAULT \'warn\',
        thresholds TEXT NOT NULL DEFAULT \'50,80,95\',
        spent_amount REAL NOT NULL DEFAULT 0,
        period_start INTEGER NOT NULL DEFAULT 0,
        period_end INTEGER NOT NULL DEFAULT 0,
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE revoap_budget_alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        budget_id INTEGER NOT NULL,
        threshold INTEGER NOT NULL DEFAULT 0,
        kind TEXT NOT NULL DEFAULT \'threshold\',
        fired_at INTEGER NOT NULL DEFAULT 0,
        period_key TEXT NOT NULL DEFAULT \'\',
        spent REAL NOT NULL DEFAULT 0,
        amount REAL NOT NULL DEFAULT 0,
        notified INTEGER NOT NULL DEFAULT 0
    )');
    if ($withFlags) {
        $pdo->exec('CREATE TABLE revoap_feature_flags (
            flag_key TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL DEFAULT 0,
            rollout TEXT NOT NULL DEFAULT \'all\',
            rollout_percent INTEGER NOT NULL DEFAULT 100,
            description TEXT NULL,
            updated_by TEXT NULL,
            updated_at INTEGER NOT NULL DEFAULT 0
        )');
    }
    return $pdo;
}

/** Direct ledger billing rows (the enforcer only ever READS the ledger). */
function bill(PDO $pdo, int $userId, float $cost, ?string $runCreatedAt = null, int $session = 1, int $resp = 1): void
{
    static $auto = 0;
    $auto++;
    $stmt = $pdo->prepare(
        'INSERT INTO revoap_usage_ledger
            (session_id, user_id, response_id, provider, model, total_tokens, cost,
             run_status, created_at, run_created_at)
         VALUES (:s, :u, :r, \'stub\', \'stub/echo\', 10, :cost, \'completed\', :w, :rc)'
    );
    $stmt->execute([
        's' => $session + $auto, 'u' => $userId, 'r' => $resp + $auto,
        'cost' => $cost, 'w' => time(),
        'rc' => $runCreatedAt ?? gmdate('Y-m-d H:i:s'),
    ]);
}

function enableBudgetsFlag(PDO $pdo): FeatureFlagRegistry
{
    // The registry's own writer speaks MySQL (ON DUPLICATE KEY), so the
    // override row goes in directly — which is also what the shipped
    // defaults + panel produce on a deployment.
    $pdo->prepare(
        'INSERT INTO revoap_feature_flags (flag_key, enabled, rollout, rollout_percent, updated_at)
         VALUES (\'cost.budgets\', 1, \'all\', 100, 0)'
    )->execute();
    return new FeatureFlagRegistry(Database::fromPdo($pdo));
}

function makeEnforcer(PDO $pdo, ?FeatureFlagRegistry $flags = null): UserQuotaEnforcer
{
    return new UserQuotaEnforcer(
        new UsageLedger($pdo),
        new BudgetRepository($pdo),
        $flags,
        null,
    );
}

/* ====================================================================== */

section('repository: validation is total');

$pdo = makeDb();
$repo = new BudgetRepository($pdo);
check('tables probe on a migrated database', $repo->tablesAvailable());

$bad = [
    'negative amount'   => ['amount' => -5],
    'non-numeric amount' => ['amount' => 'lots'],
    'unknown period'    => ['amount' => 10, 'period' => 'fortnightly'],
    'unknown action'    => ['amount' => 10, 'action_at_limit' => 'shutdown'],
    'bad currency'      => ['amount' => 10, 'currency' => 'DOLLARS'],
    'threshold zero'    => ['amount' => 10, 'thresholds' => '0,50'],
    'threshold 100'     => ['amount' => 10, 'thresholds' => '50,100'],
    'threshold garbage' => ['amount' => 10, 'thresholds' => '50,often'],
    'empty thresholds'  => ['amount' => 10, 'thresholds' => ' , '],
];
foreach ($bad as $label => $input) {
    $r = $repo->upsertUserBudget(1, $input);
    check("refuses {$label}", ($r['ok'] ?? true) === false && isset($r['error']), json_encode($r));
}
check('refuses a non-positive user id', ($repo->upsertUserBudget(0, ['amount' => 10])['ok'] ?? true) === false);

$ok = $repo->upsertUserBudget(7, [
    'amount' => 25.5, 'currency' => 'eur', 'period' => 'Monthly',
    'action_at_limit' => 'BLOCK', 'thresholds' => '95,50,50', 'name' => 'Team cap',
]);
check('accepts a valid budget', ($ok['ok'] ?? false) === true && (int)($ok['budget_id'] ?? 0) > 0, json_encode($ok));
$bid = (int)$ok['budget_id'];
$listed = $repo->listForUser(7, false);
check('normalizes case and dedupes thresholds', count($listed) === 1
    && $listed[0]['currency'] === 'EUR' && $listed[0]['period'] === 'monthly'
    && $listed[0]['action_at_limit'] === 'block' && $listed[0]['thresholds'] === '50,95',
    json_encode($listed[0] ?? null));
check('name persisted', ($listed[0]['name'] ?? '') === 'Team cap');

$other = $repo->upsertUserBudget(8, ['amount' => 5]);
$cross = $repo->upsertUserBudget(9, ['id' => $bid, 'amount' => 1]);
check('update cannot cross users (id of another user → not_found)', ($cross['ok'] ?? true) === false && ($cross['error'] ?? '') === 'not_found', json_encode($cross));
$upd = $repo->upsertUserBudget(7, ['id' => $bid, 'amount' => 30, 'period' => 'daily']);
$after = $repo->listForUser(7, false);
check('owner update succeeds', ($upd['ok'] ?? false) === true && (float)$after[0]['amount'] === 30.0 && $after[0]['period'] === 'daily', json_encode($after[0] ?? null));

$repo->upsertUserBudget(7, ['amount' => 1, 'enabled' => false]);
check('enabledOnly=true hides disabled budgets', count($repo->listForUser(7, true)) === 1);
check('enabledOnly=false shows them', count($repo->listForUser(7, false)) === 2);
check('listForUser is user-scoped', count($repo->listForUser(8, false)) === 1 && count($repo->listForUser(999, false)) === 0);

section('repository: threshold + period-key helpers');

check('normalizeThresholds sorts and dedupes', BudgetRepository::normalizeThresholds('95,50,50') === '50,95');
check('normalizeThresholds accepts arrays', BudgetRepository::normalizeThresholds([80, 50]) === '50,80');
check('normalizeThresholds refuses garbage', BudgetRepository::normalizeThresholds('often') === null);
$thu = new DateTimeImmutable('2026-10-15 13:45:00', new DateTimeZone('UTC'));
$sun = new DateTimeImmutable('2026-10-11 23:10:00', new DateTimeZone('UTC'));
check('periodKey daily', BudgetRepository::periodKey('daily', $thu) === '2026-10-15');
check('periodKey weekly (ISO)', BudgetRepository::periodKey('weekly', $thu) === '2026-W42', BudgetRepository::periodKey('weekly', $thu));
check('periodKey monthly', BudgetRepository::periodKey('monthly', $thu) === '2026-10');
check('periodKey yearly', BudgetRepository::periodKey('yearly', $thu) === '2026');
check('weekly period key identical on the Sunday of that week', BudgetRepository::periodKey('weekly', $sun) === '2026-W41', BudgetRepository::periodKey('weekly', $sun));

section('window(): calendar boundaries an operator can put in a calendar');

[$s, $e] = UserQuotaEnforcer::window('daily', $thu);
check('daily window is UTC midnight → next midnight', $s->format('Y-m-d H:i:s') === '2026-10-15 00:00:00' && $e->format('Y-m-d H:i:s') === '2026-10-16 00:00:00');
[$s, $e] = UserQuotaEnforcer::window('weekly', $thu);
check('weekly window starts on the Monday of the ISO week', $s->format('Y-m-d') === '2026-10-12' && $e->format('Y-m-d') === '2026-10-19');
[$s, $e] = UserQuotaEnforcer::window('weekly', $sun);
check('SUNDAY belongs to the week that started the previous Monday', $s->format('Y-m-d') === '2026-10-05' && $e->format('Y-m-d') === '2026-10-12', $s->format('Y-m-d'));
[$s, $e] = UserQuotaEnforcer::window('monthly', $thu);
check('monthly window is the calendar month', $s->format('Y-m-d') === '2026-10-01' && $e->format('Y-m-d') === '2026-11-01');
$jan31 = new DateTimeImmutable('2026-01-31 12:00:00', new DateTimeZone('UTC'));
[$s, $e] = UserQuotaEnforcer::window('monthly', $jan31);
check('monthly window survives a 31-day anchor', $s->format('Y-m-d') === '2026-01-01' && $e->format('Y-m-d') === '2026-02-01');
[$s, $e] = UserQuotaEnforcer::window('yearly', $thu);
check('yearly window is the calendar year', $s->format('Y-m-d') === '2026-01-01' && $e->format('Y-m-d') === '2027-01-01');

section('enforcer: inert until the operator opts in');

$pdo = makeDb();
$repo = new BudgetRepository($pdo);
$repo->upsertUserBudget(5, ['amount' => 1, 'period' => 'daily', 'action_at_limit' => 'block']);
bill($pdo, 5, 100.0);   // way over the limit

$noFlags = makeEnforcer($pdo, null);
$v = $noFlags->checkForRun(5);
check('no flag registry → not enforced', $v['action'] === 'ok' && $v['checked'] === false);

$flagsOff = new FeatureFlagRegistry(Database::fromPdo($pdo));   // no override row: default OFF
$v = makeEnforcer($pdo, $flagsOff)->checkForRun(5);
check('flag registered but OFF → not enforced', $v['action'] === 'ok' && $v['checked'] === false);

$flagsOn = enableBudgetsFlag($pdo);
$v = makeEnforcer($pdo, $flagsOn)->checkForRun(5);
check('flag ON → the block verdict arrives', $v['action'] === 'block' && $v['checked'] === true, json_encode($v['action']));
check('blocking budget is named in the verdict', (string)($v['blocking']['name'] ?? '') !== '' && (float)$v['blocking']['spent'] >= 100.0 - 1e-9, json_encode($v['blocking'] ?? null));
check('retry_after points inside the daily window', is_int($v['retry_after']) && $v['retry_after'] >= 1 && $v['retry_after'] <= 86400, json_encode($v['retry_after']));
check('verdict lists every budget evaluated', count($v['budgets']) === 1 && ($v['budgets'][0]['action_at_limit'] ?? '') === 'block');

$v = makeEnforcer($pdo, $flagsOn)->checkForRun(null);
check('no identity → nothing per-user to enforce', $v['action'] === 'ok' && $v['checked'] === false);
$v = makeEnforcer($pdo, $flagsOn)->checkForRun(6);
check('user without budgets → ok', $v['action'] === 'ok' && $v['checked'] === false);

section('enforcer: windows roll over — yesterday does not bill today');

$pdo = makeDb();
$repo = new BudgetRepository($pdo);
$flags = enableBudgetsFlag($pdo);
$repo->upsertUserBudget(11, ['amount' => 10, 'period' => 'daily', 'action_at_limit' => 'block', 'thresholds' => '50']);
bill($pdo, 11, 99.0, gmdate('Y-m-d 08:00:00', time() - 86400));   // yesterday
$v = makeEnforcer($pdo, $flags)->checkForRun(11);
check('yesterday\'s overspend does not block today', $v['action'] === 'ok' && $v['checked'] === true, json_encode($v['action']));
bill($pdo, 11, 12.0);   // today
$v = makeEnforcer($pdo, $flags)->checkForRun(11);
check('today\'s overspend blocks', $v['action'] === 'block');

section('enforcer: warn / throttle semantics at the limit');

$pdo = makeDb();
$repo = new BudgetRepository($pdo);
$flags = enableBudgetsFlag($pdo);
$repo->upsertUserBudget(21, ['amount' => 10, 'period' => 'daily', 'action_at_limit' => 'warn', 'thresholds' => '50']);
$repo->upsertUserBudget(22, ['amount' => 10, 'period' => 'daily', 'action_at_limit' => 'throttle', 'thresholds' => '50']);
bill($pdo, 21, 15.0);
bill($pdo, 22, 15.0);
$v = makeEnforcer($pdo, $flags)->checkForRun(21);
check('action_at_limit=warn at 150 % → warn, run proceeds', $v['action'] === 'warn');
$v = makeEnforcer($pdo, $flags)->checkForRun(22);
check('action_at_limit=throttle → downgraded to warn', $v['action'] === 'warn');
check('throttle downgrade is LOUD', in_array('throttle_not_enforced', $v['warnings'], true) && ($v['budgets'][0]['effective_action'] ?? '') === 'warn' && !empty($v['budgets'][0]['throttle_not_enforced']), json_encode($v['warnings']));

section('enforcer: alerts fire once per period per threshold');

$pdo = makeDb();
$repo = new BudgetRepository($pdo);
$flags = enableBudgetsFlag($pdo);
$r = $repo->upsertUserBudget(31, ['amount' => 10, 'period' => 'monthly', 'action_at_limit' => 'warn', 'thresholds' => '50,80']);
$bid = (int)$r['budget_id'];
bill($pdo, 31, 8.5);   // 85 % → crosses 50 and 80, not the limit
makeEnforcer($pdo, $flags)->checkForRun(31);
makeEnforcer($pdo, $flags)->checkForRun(31);
makeEnforcer($pdo, $flags)->checkForRun(31);
$alerts = $repo->recentAlerts($bid, 100);
$kinds = array_map(static fn($a) => $a['kind'] . '@' . $a['threshold'] . '@' . $a['period_key'], $alerts);
sort($kinds);
check('crossed thresholds fired exactly once each', $kinds === ['threshold@50@2026-10', 'threshold@80@2026-10'], json_encode($kinds));
bill($pdo, 31, 2.0);   // now 105 % → limit alert appears, thresholds stay deduped
makeEnforcer($pdo, $flags)->checkForRun(31);
$alerts = $repo->recentAlerts($bid, 100);
$kinds = array_map(static fn($a) => $a['kind'] . '@' . $a['threshold'], $alerts);
sort($kinds);
check('limit alert fires once alongside the deduped thresholds', $kinds === ['limit@100', 'threshold@50', 'threshold@80'], json_encode($kinds));
$one = $alerts[0];
check('alert row carries spend + limit for the notification layer', (float)$one['spent'] > 10.0 && (float)$one['amount'] === 10.0 && (int)$one['notified'] === 0);

section('enforcer: coverage honesty when providers do not price');

$pdo = makeDb();
$repo = new BudgetRepository($pdo);
$flags = enableBudgetsFlag($pdo);
$repo->upsertUserBudget(41, ['amount' => 10, 'period' => 'daily', 'action_at_limit' => 'warn']);
bill($pdo, 41, 3.0);
// An unpriced call: cost NULL in the ledger.
$pdo->prepare('INSERT INTO revoap_usage_ledger (session_id, user_id, response_id, provider, model, total_tokens, cost, run_status, created_at, run_created_at) VALUES (999, 41, 999, \'noprice\', \'noprice/x\', 10, NULL, \'completed\', 0, ?)')->execute([gmdate('Y-m-d H:i:s')]);
$v = makeEnforcer($pdo, $flags)->checkForRun(41);
check('unpriced calls surface as coverage < 1', $v['cost_coverage'] !== null && abs((float)$v['cost_coverage'] - 0.5) < 1e-9, json_encode($v['cost_coverage']));
check('cost_unreported warning raised', in_array('cost_unreported', $v['warnings'], true), json_encode($v['warnings']));
check('under-limit verdict stays ok despite the gap', $v['action'] === 'ok');

section('enforcer: rollup refresh as a side effect');

$pdo = makeDb();
$repo = new BudgetRepository($pdo);
$flags = enableBudgetsFlag($pdo);
$r = $repo->upsertUserBudget(51, ['amount' => 10, 'period' => 'daily']);
bill($pdo, 51, 4.25);
makeEnforcer($pdo, $flags)->checkForRun(51);
$rows = $repo->listForUser(51, false);
check('spent_amount rollup refreshed from the live sum', abs((float)$rows[0]['spent_amount'] - 4.25) < 1e-9, json_encode($rows[0]['spent_amount']));
[$ws, $we] = UserQuotaEnforcer::window('daily', new DateTimeImmutable('now', new DateTimeZone('UTC')));
check('period bounds refreshed', (int)$rows[0]['period_start'] === $ws->getTimestamp() && (int)$rows[0]['period_end'] === $we->getTimestamp());

section('enforcer: disabled budgets do not enforce');

$pdo = makeDb();
$repo = new BudgetRepository($pdo);
$flags = enableBudgetsFlag($pdo);
$r = $repo->upsertUserBudget(61, ['amount' => 1, 'period' => 'daily', 'action_at_limit' => 'block']);
$repo->upsertUserBudget(61, ['id' => (int)$r['budget_id'], 'amount' => 1, 'period' => 'daily', 'action_at_limit' => 'block', 'enabled' => false]);
bill($pdo, 61, 50.0);
$v = makeEnforcer($pdo, $flags)->checkForRun(61);
check('a disabled budget is configuration, not enforcement', $v['action'] === 'ok', json_encode($v['action']));

section('exception: the verdict travels with the refusal');

$e = new BudgetExceededException([
    'blocking' => ['name' => 'Team cap', 'spent' => 30.0, 'amount' => 25.0],
    'retry_after' => 3600,
], 5);
check('status is 429', $e->status() === 429);
check('retryAfter survives', $e->retryAfter() === 3600);
check('message names the budget', str_contains($e->getMessage(), 'Team cap'));
check('userId carried for the audit label', $e->userId === 5);
$e2 = new BudgetExceededException(['blocking' => null, 'retry_after' => null], null);
check('missing verdict fields degrade safely', $e2->retryAfter() === null && $e2->getMessage() !== '');

section('fail-open: infrastructure trouble must not stop the run surface');

$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bareRepo = new BudgetRepository($bare);
check('budget tables missing → probe false', !$bareRepo->tablesAvailable());
check('listForUser empty without tables', $bareRepo->listForUser(1) === []);
$bareEnforcer = new UserQuotaEnforcer(new UsageLedger($bare), $bareRepo, null, null);
$v = $bareEnforcer->checkForRun(1);
check('unmigrated install → ok, not enforced', $v['action'] === 'ok' && $v['checked'] === false);
$nullEnforcer = new UserQuotaEnforcer(new UsageLedger(null), new BudgetRepository(null), null, null);
$v = $nullEnforcer->checkForRun(1);
check('DB-less install → ok, not enforced', $v['action'] === 'ok' && $v['checked'] === false);
check('upsert without tables reports table_missing', ($bareRepo->upsertUserBudget(1, ['amount' => 5])['error'] ?? '') === 'table_missing');

section('SQL hygiene: no named parameter reused inside one statement');

// T6 finding #5: the budget INSERT bound :now twice. SQLite tolerates a
// reused named parameter; MySQL with ATTR_EMULATE_PREPARES = false (what
// config.php sets) refuses it with SQLSTATE[HY093] — so the offline suite
// passed while every real budget write failed. Static scan of the T5 SQL
// writers: within each quoted SQL literal, a :param may appear once.
$scanFiles = [
    __DIR__ . '/../src/Admin/Budgets/BudgetRepository.php',
    __DIR__ . '/../src/Usage/UsageLedger.php',
    __DIR__ . '/../src/Http/Controllers/UsersController.php',
];
$dupes = [];
foreach ($scanFiles as $file) {
    $src = (string)file_get_contents($file);
    // Walk single-quoted literals by splitting on the quote character: odd
    // segments are INSIDE a literal. (A regex with .*? happily matches from
    // one literal's closing quote to the next one's opening quote and then
    // "finds" :params in the PHP code between — false positives.)
    $parts = explode("'", $src);
    foreach ($parts as $i => $lit) {
        if ($i % 2 === 0) {
            continue;   // outside a literal
        }
        if (!preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b/i', $lit)) {
            continue;   // not SQL
        }
        if (preg_match_all('/:([a-z_][a-z0-9_]*)/i', $lit, $pm)) {
            foreach (array_count_values($pm[1]) as $name => $n) {
                if ($n > 1) {
                    $dupes[] = basename($file) . ':' . $name . 'x' . $n;
                }
            }
        }
    }
}
check('no SQL literal reuses a named parameter (HY093 on native prepares)',
    $dupes === [], implode(', ', $dupes));

/* ====================================================================== */

echo "\n==================================\n";
echo "UserBudgetTest: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
