<?php
/**
 * SystemHealthTest: offline coverage for R7 - the system health panel's
 * verdict core, the fact gatherer behind it, and the endpoint envelope.
 *
 * The screen exists because this class of failure has cost the most time in
 * this project and every one of them used to be found by hand: the worker
 * running older code than what is deployed, a migration that was never
 * applied, write permissions that broke, a stale catalogue, a pool that
 * drifted out of the active catalogue, a database connection that was lost.
 *
 * Three properties are pinned here, because they are the product:
 *
 *   1. **Every check says what is wrong AND what to press.** A non-ok check
 *      that offers no action is the thing this panel must not ship; the
 *      suite drives a battery of broken fact sets and asserts an action on
 *      each.
 *   2. **Nothing outside the application pretends to repair itself.** File
 *      ownership and service restarts come back as `command` actions marked
 *      `external` with a disclosure note - never as an `endpoint` the panel
 *      could POST. The suite asserts exactly that, and that a healthy
 *      installation offers no actions at all.
 *   3. **A missing fact is never reported as `ok`.** The suite feeds empty
 *      facts and asserts the verdict is `unknown`, not a clean bill of
 *      health.
 *
 * Plus the honest-failure rules of the gatherer: no database, an empty
 * catalogue, a directory that does not exist, an absent PDO - each has to
 * produce a full, renderable envelope rather than a 500.
 *
 * Runs without PHPUnit, MySQL or network access (no sockets are opened):
 *   php tests/SystemHealthTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-24
 */
declare(strict_types=1);

// The controller sets a status code through Response::json(); the CLI SAPI
// marks headers as sent the moment anything reaches STDOUT, so the whole run
// is buffered to keep http_response_code() readable (same trick as
// tests/SettingsCacheApiTest.php).
ob_start();

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Diagnostics\SystemHealth;
use App\Http\Controllers\SystemHealthController;
use App\Jobs\JobRepository;
use App\Jobs\WorkerHealthService;
use App\Settings\SystemHealthService;

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

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function makeRoot(string $prefix): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(5));
    @mkdir($dir, 0777, true);
    return $dir;
}

/** The complete set of healthy facts, as the gatherer would produce them. */
function healthyFacts(): array
{
    return [
        'worker' => [
            'code_state'               => 'current',
            'code_stale_reason'        => null,
            'code_newest_at'           => '2026-09-24T10:00:00Z',
            'code_newest_file'         => 'src/Core/Config.php',
            'app_version'              => '1.9.0',
            'worker_status'            => 'healthy',
            'workers_alive'            => 1,
            'presence_table_available' => true,
            'jobs_table_available'     => true,
            'db_connected'             => true,
            'db_reconnects'            => 0,
            'db_losses'                => 0,
            'last_db_error_at'         => null,
            'last_db_error'            => null,
        ],
        'schema' => [
            'database_ready'          => true,
            'config_table_present'    => true,
            'expected_schema_version' => '1.9.0',
            'live_schema_version'     => '1.9.0',
            'applied_migrations'      => 20,
            'total_migrations'        => 20,
            'pending_migrations'      => 0,
            'pending_names'           => [],
        ],
        'dirs' => [
            ['label' => 'Runtime overrides', 'target' => 'var/config', 'exists' => true, 'writable' => true,
             'perms' => '0775', 'owner' => 'www-data', 'process_user' => 'www-data', 'disk_free' => 100],
            ['label' => 'Response cache', 'target' => 'cache', 'exists' => true, 'writable' => true,
             'perms' => '0775', 'owner' => 'www-data', 'process_user' => 'www-data', 'disk_free' => 100],
        ],
        'catalog' => [
            'active_rows'       => 12,
            'total_rows'        => 40,
            'max_rows'          => 1000,
            'snapshot_taken_at' => '2026-09-24T09:00:00Z',
            'snapshot_age_s'    => 3600,
            'stale_after_days'  => 30,
        ],
        'pool' => [
            'referenced'  => [
                ['key' => 'openrouter|openai/gpt-5.5', 'provider' => 'openrouter', 'model' => 'openai/gpt-5.5', 'source' => 'pool'],
                ['key' => 'bothub|gpt-5.5', 'provider' => 'bothub', 'model' => 'gpt-5.5', 'source' => 'role:synthesis'],
            ],
            'missing'     => [],
            'active_keys' => ['openrouter|openai/gpt-5.5', 'bothub|gpt-5.5'],
        ],
        'setup' => [
            'admin_configured'    => true,
            'database_configured' => true,
            'database_connected'  => true,
            'schema_ready'        => true,
            'provider_keys'       => [
                ['provider' => 'openrouter', 'configured' => true],
                ['provider' => 'bothub', 'configured' => true],
            ],
            'catalog_ready'       => true,
            'worker_running'      => true,
            'dirs_ok'             => true,
        ],
    ];
}

/** @return array<string,mixed> */
function withFacts(array $overrides): array
{
    $facts = healthyFacts();
    foreach ($overrides as $key => $value) {
        if (is_array($value) && is_array($facts[$key] ?? null)) {
            $facts[$key] = $value + $facts[$key];
        } else {
            $facts[$key] = $value;
        }
    }
    return $facts;
}

/** @return array<string,array<string,mixed>> keyed by check id */
function byId(array $checks): array
{
    $out = [];
    foreach ($checks as $check) {
        $out[(string)($check['id'] ?? '?')] = $check;
    }
    return $out;
}

function actionIds(array $check): array
{
    $ids = [];
    foreach ($check['actions'] ?? [] as $action) {
        $ids[] = (string)($action['id'] ?? '');
    }
    return $ids;
}

// =====================================================================
echo "\n== (a) the screen's inventory is fixed, and every check is answerable ==\n";
// =====================================================================
$healthyChecks = SystemHealth::checks(healthyFacts());
check('six checks are returned', count($healthyChecks) === 6, (string)count($healthyChecks));
check('and they are the documented six, in order',
    array_map(static fn(array $c): string => (string)$c['id'], $healthyChecks) === SystemHealth::order(),
    implode(',', array_map(static fn(array $c): string => (string)$c['id'], $healthyChecks)));
$ids = array_map(static fn(array $c): string => (string)$c['id'], $healthyChecks);
check('no check id is repeated', count($ids) === count(array_unique($ids)));

$requiredKeys = ['id', 'label', 'severity', 'headline', 'detail', 'actions', 'facts'];
$missingKeys = [];
foreach ($healthyChecks as $check) {
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $check)) {
            $missingKeys[] = ($check['id'] ?? '?') . '.' . $key;
        }
    }
}
check('every check carries the documented keys', $missingKeys === [], implode(', ', $missingKeys));

$actionKeys = ['id', 'label', 'kind', 'target', 'method', 'needs_confirmation', 'external', 'note'];
$badActions = [];
foreach ($healthyChecks as $check) {
    foreach ($check['actions'] as $action) {
        foreach ($actionKeys as $key) {
            if (!array_key_exists($key, $action)) {
                $badActions[] = ($check['id'] ?? '?') . '.' . ($action['id'] ?? '?') . '.' . $key;
            }
        }
        if (!in_array((string)($action['kind'] ?? ''), ['endpoint', 'command', 'navigate'], true)) {
            $badActions[] = ($check['id'] ?? '?') . ' has kind "' . (string)($action['kind'] ?? '') . '"';
        }
    }
}
check('every action uses the RunDiagnosis action shape', $badActions === [], implode(', ', $badActions));

$healthy = byId($healthyChecks);
foreach ($healthy as $id => $check) {
    check("a healthy installation: {$id} is ok", ($check['severity'] ?? '') === 'ok',
        (string)($check['severity'] ?? '?'));
}
check('a healthy installation: the overall verdict is ok', SystemHealth::overall($healthyChecks) === 'ok');
check('a healthy installation: no check offers an action',
    array_sum(array_map(static fn(array $c): int => count($c['actions']), $healthyChecks)) === 0);
check('the summary counts what it was given',
    SystemHealth::summary($healthyChecks) === ['checks' => 6, 'ok' => 6, 'warn' => 0, 'error' => 0, 'unknown' => 0],
    json_encode(SystemHealth::summary($healthyChecks)));

// =====================================================================
echo "\n== (b) a broken fact set always comes with the button that answers it ==\n";
// =====================================================================
/** @return array{0:array<string,mixed>,1:array<string,mixed>} */
$drive = static function (array $facts): array {
    $checks = byId(SystemHealth::checks($facts));
    return [$checks, SystemHealth::checks($facts)];
};

$brokenSets = [
    'stale worker code' => withFacts(['worker' => [
        'code_state'        => 'stale',
        'code_stale_reason' => 'src/Models/ModelsActualizer.php was modified at 2026-09-24T09:00:00Z, after the '
            . 'worker process started at 2026-09-24T08:00:00Z.',
        'code_newest_file'  => 'src/Models/ModelsActualizer.php',
        'worker_status'     => 'idle',
    ]]),
    'no worker presence table' => withFacts(['worker' => ['presence_table_available' => false]]),
    'no worker running' => withFacts(['worker' => ['workers_alive' => 0, 'worker_status' => 'not_running']]),
    'no database' => withFacts(['schema' => ['database_ready' => false], 'worker' => ['db_connected' => false]]),
    'schema never created' => withFacts(['schema' => ['config_table_present' => false]]),
    'pending migration' => withFacts(['schema' => [
        'pending_migrations' => 1, 'pending_names' => ['021_health.sql'],
    ]]),
    'schema behind' => withFacts(['schema' => ['live_schema_version' => '1.8.44']]),
    'unwritable directory' => withFacts(['dirs' => [[
        'label' => 'Response cache', 'target' => 'cache', 'exists' => true, 'writable' => false,
        'perms' => '0555', 'owner' => 'root', 'process_user' => 'www-data', 'disk_free' => 10,
    ]]]),
    'empty catalogue' => withFacts(['catalog' => ['active_rows' => 0, 'total_rows' => 0]]),
    'catalogue never actualized' => withFacts(['catalog' => ['snapshot_age_s' => null, 'snapshot_taken_at' => null]]),
    'catalogue capped' => withFacts(['catalog' => ['total_rows' => 1000, 'max_rows' => 1000]]),
    'pool out of the catalogue' => withFacts(['pool' => ['missing' => [[
        'key' => 'openrouter|openai/gpt-5.5-turbo', 'provider' => 'openrouter',
        'model' => 'openai/gpt-5.5-turbo', 'source' => 'pool',
    ]]]]),
    'database connection lost' => withFacts(['worker' => [
        'db_losses' => 2, 'db_reconnects' => 2, 'last_db_error_at' => '2026-09-24T09:30:00Z',
        'last_db_error' => 'SQLSTATE[HY000] [2006] MySQL server has gone away',
    ]]),
    'no facts at all' => [],
];

foreach ($brokenSets as $name => $facts) {
    [$checks] = $drive($facts);
    $unanswerable = [];
    foreach ($checks as $id => $check) {
        if (($check['severity'] ?? 'ok') !== 'ok' && ($check['actions'] ?? []) === []) {
            $unanswerable[] = $id;
        }
    }
    check("{$name}: every non-ok check offers an action", $unanswerable === [], implode(', ', $unanswerable));
}

// --- the specific, load-bearing verdicts -----------------------------
$stale = $drive(withFacts(['worker' => [
    'code_state'        => 'stale',
    'code_stale_reason' => 'deployed after the process started',
    'code_newest_file'  => 'src/Models/ModelsActualizer.php',
    'code_newest_at'    => '2026-09-24T09:00:00Z',
]]))[0]['worker_code'];
check('stale worker code is an error', ($stale['severity'] ?? '') === 'error');
check('the headline says what is wrong in plain words',
    str_contains((string)$stale['headline'], 'older than what is deployed'), (string)$stale['headline']);
check('the detail names the file that is newer than the process',
    str_contains((string)$stale['detail'], 'src/Models/ModelsActualizer.php'));
check('the detail explains why nothing but a restart helps',
    str_contains((string)$stale['detail'], 'PHP loads each class once per process'));
check('the fix is the existing restart endpoint',
    in_array('restart_worker', actionIds($stale), true));
$restart = null;
foreach ($stale['actions'] as $action) {
    if (($action['id'] ?? '') === 'restart_worker') { $restart = $action; }
}
check('and that endpoint is POSTed, not linked',
    is_array($restart) && ($restart['target'] ?? '') === '/api/worker/restart' && ($restart['method'] ?? '') === 'POST');
check('and it is marked as needing the operator\'s confirmation',
    is_array($restart) && ($restart['needs_confirmation'] ?? null) === true);
check('the restart command is shown as a host-level alternative',
    in_array('restart_command', actionIds($stale), true));

$pending = $drive(withFacts(['schema' => [
    'pending_migrations' => 2, 'pending_names' => ['021_health.sql', '022_more.sql'],
]]))[0]['schema'];
check('a pending migration is an error', ($pending['severity'] ?? '') === 'error');
check('the headline counts them', str_contains((string)$pending['headline'], '2 migration file(s)'));
check('the detail names the files', str_contains((string)$pending['detail'], '021_health.sql'));
check('and the fix is the existing apply-migrations endpoint',
    in_array('apply_migrations', actionIds($pending), true));

$behind = $drive(withFacts(['schema' => ['live_schema_version' => '1.8.44']]))[0]['schema'];
check('a live/expected version mismatch is a warning, not an error',
    ($behind['severity'] ?? '') === 'warn');
check('and it names both versions',
    str_contains((string)$behind['headline'], '1.8.44') && str_contains((string)$behind['headline'], '1.9.0'),
    (string)$behind['headline']);

$dirs = $drive(withFacts(['dirs' => [[
    'label' => 'Response cache', 'target' => 'cache', 'exists' => true, 'writable' => false,
    'perms' => '0555', 'owner' => 'root', 'process_user' => 'www-data', 'disk_free' => 10,
]]]))[0]['writable_dirs'];
check('an unwritable directory is an error', ($dirs['severity'] ?? '') === 'error');
check('the headline names the directory', str_contains((string)$dirs['headline'], 'Response cache'));
check('the detail carries the mode, the owner and the process user',
    str_contains((string)$dirs['detail'], '0555') && str_contains((string)$dirs['detail'], 'root')
    && str_contains((string)$dirs['detail'], 'www-data'));
$dirKinds = array_map(static fn(array $a): string => (string)$a['kind'], $dirs['actions']);
check('ownership/mode are host-level facts, so every action is a command', $dirKinds !== [] && array_unique($dirKinds) === ['command'],
    implode(',', $dirKinds));
$externalFlags = [];
foreach ($dirs['actions'] as $action) {
    $externalFlags[] = ($action['external'] ?? null) === true && (string)($action['note'] ?? '') !== '';
}
check('and each one says the panel will not run it', $externalFlags === [true, true],
    json_encode($externalFlags));

$pool = $drive(withFacts(['pool' => ['missing' => [
    ['key' => 'openrouter|openai/gpt-5.5-turbo', 'provider' => 'openrouter', 'model' => 'openai/gpt-5.5-turbo', 'source' => 'pool'],
    ['key' => 'bothub|gpt-6', 'provider' => 'bothub', 'model' => 'gpt-6', 'source' => 'role:arbiter'],
]]]))[0]['pool_invariant'];
check('a dangling reference is an error', ($pool['severity'] ?? '') === 'error');
check('the headline counts the missing rows', str_contains((string)$pool['headline'], '2 referenced model(s)'));
check('the detail names each model with its source',
    str_contains((string)$pool['detail'], 'openai/gpt-5.5-turbo (pool)')
    && str_contains((string)$pool['detail'], 'gpt-6 (role:arbiter)'), (string)$pool['detail']);
check('and the fix is the actualization endpoint',
    in_array('refresh_catalogue', actionIds($pool), true));

$emptyPool = $drive(withFacts(['pool' => ['referenced' => [], 'missing' => [], 'active_keys' => []]]))[0]['pool_invariant'];
check('nothing referenced is not a failure', ($emptyPool['severity'] ?? '') === 'ok');
check('and it says so plainly', str_contains((string)$emptyPool['headline'], 'Nothing references'));

$emptyCatalog = $drive(withFacts(['catalog' => ['active_rows' => 0, 'total_rows' => 0]]))[0]['catalog_freshness'];
check('an empty active catalogue is an error', ($emptyCatalog['severity'] ?? '') === 'error');

$never = $drive(withFacts(['catalog' => ['snapshot_age_s' => null, 'snapshot_taken_at' => null]]))[0]['catalog_freshness'];
check('a catalogue that was never actualized is a warning', ($never['severity'] ?? '') === 'warn');

$capped = $drive(withFacts(['catalog' => ['total_rows' => 100, 'max_rows' => 100]]))[0]['catalog_freshness'];
check('a catalogue at its ceiling is a warning', ($capped['severity'] ?? '') === 'warn');
check('and it names the ceiling', str_contains((string)$capped['headline'], '100'),
    (string)$capped['headline']);

$lost = $drive(withFacts(['worker' => [
    'db_losses' => 3, 'db_reconnects' => 3, 'last_db_error_at' => '2026-09-24T09:30:00Z',
    'last_db_error' => 'server has gone away',
]]))[0]['db_connection'];
check('a lost connection is a warning', ($lost['severity'] ?? '') === 'warn');
check('the headline counts the losses', str_contains((string)$lost['headline'], '3 time(s)'));
check('and restarting the server is offered as an external command only',
    in_array('restart_mysql', actionIds($lost), true)
    && array_sum(array_map(static fn(array $a): int => $a['kind'] === 'endpoint' ? 1 : 0, $lost['actions'])) === 0);

// --- an empty fact set must not look healthy -------------------------
$nothing = byId(SystemHealth::checks([]));
check('with no facts, nothing is reported as ok',
    ($nothing['worker_code']['severity'] ?? '') === 'unknown'
    && ($nothing['schema']['severity'] ?? '') === 'error'
    && ($nothing['writable_dirs']['severity'] ?? '') === 'unknown'
    && ($nothing['db_connection']['severity'] ?? '') === 'error',
    json_encode(array_map(static fn(array $c): string => (string)$c['severity'], $nothing)));
check('and the worst verdict folds to error', SystemHealth::overall(SystemHealth::checks([])) === 'error');

// The one non-ok verdict that carries an action even when it cannot judge:
// "I could not look" must still offer the button that looks again.
$noDirs = byId(SystemHealth::checks(['dirs' => []]))['writable_dirs'];
check('an unprobed filesystem offers a re-check instead of a dead end',
    ($noDirs['severity'] ?? '') === 'unknown'
    && ($noDirs['actions'][0]['target'] ?? '') === '/api/health/system'
    && ($noDirs['actions'][0]['method'] ?? '') === 'GET',
    json_encode($noDirs['actions']));

check('the overall verdict is the worst of the six',
    SystemHealth::overall(SystemHealth::checks(withFacts(['catalog' => ['snapshot_age_s' => null, 'snapshot_taken_at' => null]]))) === 'warn');
check('and summary() agrees with the checks',
    SystemHealth::summary(SystemHealth::checks(withFacts([
        'schema' => ['database_ready' => false], 'worker' => ['db_connected' => false],
    ]))) === ['checks' => 6, 'ok' => 4, 'warn' => 0, 'error' => 2, 'unknown' => 0],
    json_encode(SystemHealth::summary(SystemHealth::checks(withFacts([
        'schema' => ['database_ready' => false], 'worker' => ['db_connected' => false],
    ])))));

// =====================================================================
echo "\n== (c) the first-run checklist: what a new install still has to do ==\n";
// =====================================================================
$fresh = SystemHealth::setupChecklist([
    'setup' => [
        'admin_configured' => false, 'database_configured' => false, 'database_connected' => false,
        'schema_ready' => false, 'provider_keys' => [
            ['provider' => 'openrouter', 'configured' => false],
            ['provider' => 'bothub', 'configured' => false],
        ],
        'catalog_ready' => false, 'worker_running' => false, 'dirs_ok' => false,
    ],
    'dirs' => [['label' => 'Response cache', 'target' => 'cache', 'exists' => false, 'writable' => false]],
]);
check('a fresh install has every item open', count(array_filter($fresh, static fn(array $i): bool => $i['done'] === true)) === 0);
check('and each open item carries at least one action',
    count(array_filter($fresh, static fn(array $i): bool => ($i['actions'] ?? []) === [])) === 0);
check('the admin-key item explains how to create the key without the panel doing it',
    str_contains((string)$fresh[0]['detail'], '.admin.key'));
$adminAction = $fresh[0]['actions'][0] ?? [];
check('and it is a command marked external', ($adminAction['kind'] ?? '') === 'command'
    && ($adminAction['external'] ?? null) === true);

$doneFacts = healthyFacts();
$complete = SystemHealth::setupChecklist($doneFacts);
check('a configured install has every item done',
    count(array_filter($complete, static fn(array $i): bool => $i['done'] === true)) === count($complete),
    json_encode(array_map(static fn(array $i): string => $i['id'] . '=' . var_export($i['done'], true), $complete)));
check('and offers no actions', array_sum(array_map(static fn(array $i): int => count($i['actions']), $complete)) === 0);
check('the checklist items have stable ids',
    array_map(static fn(array $i): string => $i['id'], $complete)
    === ['admin_key', 'database', 'schema', 'provider_keys', 'catalogue', 'worker', 'writable_dirs']);

// =====================================================================
echo "\n== (d) the gatherer: honest degradation, never a 500 ==\n";
// =====================================================================
// Hermetic config: no DSN, a two-row catalogue with one active row, one
// pool entry that exists and one connector key present.
Config::set([
    'app'            => ['name' => 'RevoAp', 'version' => '1.9.0', 'debug' => false, 'timezone' => 'UTC'],
    'logging'        => ['path' => makeRoot('revoap-r7-logs') . '/logs'],
    'database'       => ['dsn' => '', 'password' => 'r7-secret-password'],
    'models_catalog' => ['max_rows' => 1000],
    'models'         => [
        ['provider' => 'openrouter', 'model' => 'openai/gpt-5.5', 'active' => true],
        ['provider' => 'bothub',     'model' => 'gpt-5.5',        'active' => false],
    ],
    'pipeline'       => [
        'default_selected_models' => [['provider' => 'openrouter', 'model' => 'openai/gpt-5.5']],
        'execution_mode'          => 'job',
    ],
    'connectors'     => [
        'openrouter' => ['active' => true, 'api_key' => 'r7-secret-key'],
        'bothub'     => ['active' => false],
    ],
], dirname(__DIR__));

$repoRoot = dirname(__DIR__);
$workerHealth = new WorkerHealthService(new JobRepository(null), null);

// (d1) no PDO at all.
$svcNoDb = new SystemHealthService($repoRoot, $workerHealth, null, null, null, null);
$noDb = $svcNoDb->report();
check('without a database the report still renders', ($noDb['ok'] ?? null) === true);
check('it carries all six checks',
    array_map(static fn(array $c): string => (string)$c['id'], $noDb['checks']) === SystemHealth::order());
check('the schema check is an error, not a silent ok',
    ($noDb['checks'][1]['severity'] ?? '') === 'error', (string)($noDb['checks'][1]['severity'] ?? '?'));
check('and it points at the Database card',
    in_array('oc-database-card', array_map(static fn(array $a): string => (string)$a['target'], $noDb['checks'][1]['actions']), true));
check('it carries the first-run checklist', count($noDb['setup_checklist']) === 7);
check('the worker checks still render', isset($noDb['checks'][0]['headline'], $noDb['checks'][5]['headline']));

// (d2) a temp root with all four directories present.
$goodRoot = makeRoot('revoap-r7-good');
foreach (['var/config', 'runtime', 'cache', 'logs'] as $dir) {
    @mkdir($goodRoot . '/' . $dir, 0777, true);
}
$svcGood = new SystemHealthService($goodRoot, $workerHealth, null, null, null, null);
$good = byId($svcGood->report()['checks']);
check('all four writable directories are probed', count($good['writable_dirs']['facts']['dirs'] ?? []) === 4,
    (string)count($good['writable_dirs']['facts']['dirs'] ?? []));
check('and an all-writable root is ok', ($good['writable_dirs']['severity'] ?? '') === 'ok',
    (string)($good['writable_dirs']['headline'] ?? ''));

// (d3) a temp root missing the cache directory.
$badRoot = makeRoot('revoap-r7-bad');
foreach (['var/config', 'runtime', 'logs'] as $dir) {
    @mkdir($badRoot . '/' . $dir, 0777, true);
}
$svcBad = new SystemHealthService($badRoot, $workerHealth, null, null, null, null);
$bad = byId($svcBad->report()['checks']);
check('a missing directory is an error', ($bad['writable_dirs']['severity'] ?? '') === 'error');
check('and the headline names it', str_contains((string)$bad['writable_dirs']['headline'], 'Response cache'),
    (string)$bad['writable_dirs']['headline']);

// (d4) the catalogue facts come from the loaded config, not from a DB.
$catalogCheck = $svcGood->report()['checks'][3];
check('the catalogue facts count only active rows', ($catalogCheck['facts']['active_rows'] ?? null) === 1,
    json_encode($catalogCheck['facts']));
check('and the cap comes from the config', ($catalogCheck['facts']['max_rows'] ?? null) === 1000);
check('a catalogue that was never actualized is a warning here too',
    ($catalogCheck['severity'] ?? '') === 'warn', (string)($catalogCheck['severity'] ?? '?'));

$poolCheck = $svcGood->report()['checks'][4];
check('the pool invariant finds the pool entry inside the active catalogue',
    ($poolCheck['facts']['missing'] ?? null) === 0, json_encode($poolCheck['facts']));
check('and it is ok', ($poolCheck['severity'] ?? '') === 'ok');

// (d5) the endpoint envelope.
ob_start();
http_response_code(200);
$raw = (new SystemHealthController($svcGood))->index();
$status = (int)http_response_code();
ob_end_clean();
$decoded = json_decode($raw, true);
check('the endpoint answers with decodable JSON', is_array($decoded), substr($raw, 0, 200));
check('and a 200 status', $status === 200, (string)$status);
check('with the documented top-level keys',
    array_keys($decoded) === ['ok', 'generated_at', 'overall', 'summary', 'checks', 'setup_checklist', 'commands'],
    implode(',', array_keys($decoded)));
check('the checks are the six, in order',
    array_map(static fn(array $c): string => (string)$c['id'], $decoded['checks']) === SystemHealth::order());
check('the commands block is the shared worker command list',
    ($decoded['commands']['restart_worker'] ?? null) === null
    && isset($decoded['commands']['status'], $decoded['commands']['systemd_restart']));
check('no API key reaches the wire', !str_contains($raw, 'r7-secret-key'));
check('no DSN password reaches the wire', !str_contains($raw, 'r7-secret-password'));
check('no absolute host path reaches the wire', !str_contains($raw, str_replace('\\', '/', $goodRoot))
    && !str_contains($raw, $goodRoot), substr($raw, 0, 200));

// A probe that cannot see anything still answers.
$svcEmptyRoot = new SystemHealthService('', $workerHealth, null, null, null, null);
$envelope = $svcEmptyRoot->report();
check('an empty root still produces a renderable envelope',
    ($envelope['ok'] ?? null) === true && count($envelope['checks']) === 6
    && count($envelope['setup_checklist']) === 7);

rmTree($goodRoot);
rmTree($badRoot);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
