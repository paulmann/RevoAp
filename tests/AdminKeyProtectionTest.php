<?php
/**
 * AdminKeyProtectionTest: `Protect_Settings_by_Admin_Key`.
 *
 * The operator asked for a switch — "add `Protect_Settings_by_Admin_Key = 0`
 * to config.php and the same to .config.php, I am tired of typing the key;
 * if it is 1, ask for the key from .admin.key" — and for it to work
 * *everywhere* the key is required, in one place, server and client.
 *
 * Verified here:
 *   - the flag is read from the loaded config tree, so `.config.php` (the
 *     deployment overlay) wins over config.php;
 *   - every accepted spelling: `1`/`0`, `"1"`/`"0"`, `true`/`false`,
 *     `"true"`/`"false"`, `on`/`off`, `yes`/`no`;
 *   - an ABSENT or unusable value keeps the gate ON — the setting is
 *     opt-out, never opt-in by accident, so a config that fails to load
 *     cannot open the operator surface;
 *   - with the flag at 1 the historical behaviour is unchanged: 503
 *     `admin_not_configured` while nothing is configured, 401
 *     `unauthorized` while a token exists but was not presented;
 *   - with the flag at 0 every guard passes without a token — checked
 *     against one representative action of each gated controller, so a
 *     guard that forgets the flag is a failure here rather than a surprise
 *     in production;
 *   - the status probes, `/api/tabs` and the boot config report
 *     `admin_protection` and an `admin_unlocked` that is true whenever the
 *     gate is open (which is what removes every lock screen in the UI);
 *   - the client modules do not ask for a key when the flag is off, and the
 *     page says out loud that the surface is unprotected.
 *
 * Runs without PHPUnit, a database or a network:
 *   php tests/AdminKeyProtectionTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

ob_start();

require __DIR__ . '/../src/autoload.php';

use App\Analyzer\RequestAnalyzer;
use App\Api\ApiKeyController;
use App\Api\ApiKeyRepository;
use App\Api\ApiKeyService;
use App\Api\WebhookController;
use App\Api\WebhookDeliveryRepository;
use App\Api\WebhookRepository;
use App\Api\WebhookService;
use App\Connectors\ConnectorRegistry;
use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\RuntimeConfig;
use App\DomainPresets\DomainPresetRepository;
use App\Http\Controllers\AnalyzerController;
use App\Http\Controllers\DomainPresetsController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettingsController;
use App\Jobs\JobAuditRepository;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\WorkerHealthService;
use App\Logs\LogsService;
use App\Models\ModelsActualizeSettings;
use App\Models\ModelsActualizer;
use App\Models\ModelsSnapshotStore;
use App\Models\ModelImporter;
use App\Profiles\UserProfileRepository;
use App\Settings\ConfigRepository;
use App\Storage\SessionRepository;
use App\Http\Controllers\ModelsController;

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
    if (!is_dir($dir)) { return; }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-admin-protection-' . bin2hex(random_bytes(4));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'logs', 0o775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'cache', 0o775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'Connectors', 0o775, true);

// ---------------------------------------------------------------------
// Boot scaffold. `$flag` is written EXACTLY as the caller passes it, so the
// accepted-spelling cases are exercised through a real config load rather
// than through the coercion function alone.
// ---------------------------------------------------------------------

/** @param mixed $flag value to place in config.php, or null to omit the key */
function boot(mixed $flag, mixed $overlayFlag = false, bool $withKeyFile = false): void
{
    global $tmp;
    Config::reset();
    RuntimeConfig::reset();

    $config = [
        'app'         => ['name' => 'RevoAp', 'version' => '1.8.60', 'debug' => false],
        'logging'     => ['path' => $tmp . DIRECTORY_SEPARATOR . 'logs'],
        'SettingsTab' => true,
        'LogsTab'     => true,
        'SecurityTab' => true,
        'ApiTab'      => true,
        'ModelsTab'   => true,
        'cache'       => ['model_responses' => ['enabled' => true, 'ttl_seconds' => 0]],
        'connectors'  => ['stub' => ['active' => true, 'api_key' => 'x']],
        'models'      => [['provider' => 'stub', 'model' => 'stub/echo', 'active' => true]],
        'pipeline'    => ['synthesis_provider' => 'stub', 'synthesis_model' => 'stub/echo'],
        'analysis'    => [],
    ];
    if ($flag !== null) {
        $config[AdminAuth::PROTECT_KEY] = $flag;
    }
    file_put_contents(
        $tmp . DIRECTORY_SEPARATOR . 'config.php',
        "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n",
    );

    // A `.config.php` ALWAYS exists in the temp root, even in the scenario
    // that wants no overlay: `RootFiles` resolves the file to the first
    // candidate directory that has one, so without a file here the
    // repository's own deployment overlay would be applied on top of the
    // generated tree — and this is the one suite whose subject IS that file.
    $overlay = $tmp . DIRECTORY_SEPARATOR . '.config.php';
    if ($overlayFlag === false) {
        file_put_contents($overlay, "<?php\ndeclare(strict_types=1);\nreturn [];\n");
    } else {
        file_put_contents(
            $overlay,
            "<?php\ndeclare(strict_types=1);\nreturn " . var_export([AdminAuth::PROTECT_KEY => $overlayFlag], true) . ";\n",
        );
    }
    @unlink($tmp . DIRECTORY_SEPARATOR . RuntimeConfig::FILE_RELATIVE);
    // The temp root ALWAYS holds `.admin.key` (empty unless the scenario
    // wants a token): RootFiles resolves a dot-file to the first candidate
    // that has one, so without a file here the repository own key file —
    // which exists on a developer machine — would decide every case below.
    file_put_contents($tmp . DIRECTORY_SEPARATOR . '.admin.key', '');

    Config::load($tmp . DIRECTORY_SEPARATOR . 'config.php', $tmp);
    AdminAuth::setRootDir($tmp);
    unset($_SERVER['HTTP_X_ADMIN_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'], $_GET['admin_token'], $_COOKIE[AdminAuth::COOKIE]);
    unset($_ENV[AdminAuth::ENV], $_ENV[AdminAuth::LEGACY_ENV]);
    putenv(AdminAuth::ENV);
    putenv(AdminAuth::LEGACY_ENV);
    if ($withKeyFile) {
        file_put_contents($tmp . DIRECTORY_SEPARATOR . '.admin.key', "test-token\n");
    }
}

/** Call a guarded action and return [httpStatus, decodedBody]. */
function call(callable $action): array
{
    ob_start();
    $body = $action();
    ob_end_clean();
    $status = (int)http_response_code();
    $decoded = json_decode((string)$body, true);
    // Response::json() only sets a status while headers are open; in the CLI
    // that is true because of the ob_start() at the top of this file.
    return [$status, is_array($decoded) ? $decoded : []];
}

/**
 * One representative gated action per controller, so "every guard obeys the
 * flag" is checked rather than asserted in a comment.
 *
 * @return array<string,callable():string>
 */
function gatedActions(): array
{
    global $tmp;
    $pdo = new PDO('sqlite::memory:');
    return [
        'SettingsController::get' => (new SettingsController($tmp))->get(...),
        'LogsController::list'    => (new LogsController(new LogsService($tmp . '/logs')))->list(...),
        'SecurityController::save' => (new SecurityController($tmp))->save(...),
        'AnalyzerController::settings' => (new AnalyzerController(
            new RequestAnalyzer(new ConnectorRegistry($tmp . '/Connectors')),
        ))->settings(...),
        'DomainPresetsController::index' => (new DomainPresetsController(
            new DomainPresetRepository(null),
        ))->index(...),
        'ProfileController::get' => (new ProfileController(new UserProfileRepository(null)))->get(...),
        'ApiKeyController::index' => (new ApiKeyController(new ApiKeyService(new ApiKeyRepository($pdo))))->index(...),
        'WebhookController::index' => (new WebhookController(
            new WebhookService(new WebhookRepository($pdo)),
            new WebhookDeliveryRepository($pdo),
        ))->index(...),
        'ModelsController::actualizeStatus' => (function () use ($tmp, $pdo): ModelsController {
            $repo = new ConfigRepository($pdo);
            $audit = new JobAuditRepository($pdo);
            $jobs = new JobRepository($pdo);
            $jobs->setAuditRepository($audit);
            $store = new ModelsSnapshotStore($repo);
            $prefs = new ModelsActualizeSettings($repo);
            $actualizer = new ModelsActualizer(
                new ConnectorRegistry($tmp . '/Connectors'),
                new DomainPresetRepository($pdo),
                $store,
                $audit,
                null,
                new ModelImporter(),
                $prefs,
            );
            return new ModelsController(
                new JobService($jobs, new \App\Storage\SessionRepository($pdo)),
                $jobs,
                $store,
                $actualizer,
                null,
                $prefs,
            );
        })()->actualizeStatus(...),
    ];
}

/** Short label for a status/error pair. */
function describe(array $result): string
{
    [$status, $body] = $result;
    return 'HTTP ' . $status . ' ' . (string)($body['error'] ?? $body['ok'] ?? '');
}

// =====================================================================
echo "\n== the flag is configuration, and .config.php wins ==\n";
// =====================================================================

boot(1);
check('flag 1 → the gate is on', AdminAuth::protectionEnabled() === true);
boot(0);
check('flag 0 → the gate is off', AdminAuth::protectionEnabled() === false);
boot(null);
check('an ABSENT flag keeps the gate ON (opt-out, never opt-in by accident)',
    AdminAuth::protectionEnabled() === true);
boot(1, 0);
check('.config.php wins over config.php (1 in config.php, 0 in the overlay)',
    AdminAuth::protectionEnabled() === false);
boot(0, 1);
check('and the other way round (0 in config.php, 1 in the overlay)',
    AdminAuth::protectionEnabled() === true);

$spellings = [
    1 => true, 0 => false,
    '1' => true, '0' => false,
    true => true, false => false,
    'true' => true, 'false' => false,
    'on' => true, 'off' => false,
    'yes' => true, 'no' => false,
    ' 0 ' => false, ' 1 ' => true,
    2 => true, -1 => true,
    'banana' => true, '' => true,
];
foreach ($spellings as $value => $expected) {
    boot($value);
    check('spelling ' . json_encode($value) . ' → ' . ($expected ? 'protected' : 'open'),
        AdminAuth::protectionEnabled() === $expected, (string)AdminAuth::protectionEnabled());
}
boot([]);
check('an unusable value (an array) keeps the gate ON', AdminAuth::protectionEnabled() === true);

// =====================================================================
echo "\n== with the flag at 1 nothing changes ==\n";
// =====================================================================

boot(1);
$state = AdminAuth::gateState();
check('gateState reports protected + not configured + not allowed',
    $state['protected'] === true && $state['configured'] === false && $state['allowed'] === false
    && $state['unlocked'] === false,
    json_encode($state));

foreach (gatedActions() as $label => $action) {
    [$status, $body] = call($action);
    check($label . ' refuses without a token (' . describe([$status, $body]) . ')',
        $status === 503 && ($body['error'] ?? '') === 'admin_not_configured',
        describe([$status, $body]));
}

boot(1, false, withKeyFile: true);
check('a key file makes the gate configured', AdminAuth::isConfigured() === true);
$state = AdminAuth::gateState();
check('and an unpresented request is still refused',
    $state['configured'] === true && $state['unlocked'] === false && $state['allowed'] === false,
    json_encode($state));
foreach (gatedActions() as $label => $action) {
    [$status, $body] = call($action);
    check($label . ' answers 401 unauthorized without the token (' . describe([$status, $body]) . ')',
        $status === 401 && ($body['error'] ?? '') === 'unauthorized',
        describe([$status, $body]));
}
// The token is accepted when presented.
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'test-token';
$state = AdminAuth::gateState();
check('presenting the token opens the gate as before',
    $state['unlocked'] === true && $state['allowed'] === true, json_encode($state));
unset($_SERVER['HTTP_X_ADMIN_TOKEN']);

// =====================================================================
echo "\n== with the flag at 0 every guard stands down ==\n";
// =====================================================================

boot(0);
$state = AdminAuth::gateState();
check('gateState reports unprotected + unlocked + allowed, with no token at all',
    $state['protected'] === false && $state['unlocked'] === true && $state['allowed'] === true
    && $state['configured'] === false,
    json_encode($state));

foreach (gatedActions() as $label => $action) {
    [$status, $body] = call($action);
    check($label . ' answers without a token (' . describe([$status, $body]) . ')',
        $status === 200 && ($body['error'] ?? '') !== 'unauthorized'
        && ($body['error'] ?? '') !== 'admin_not_configured',
        describe([$status, $body]));
}

// A wrong token must not become a refusal either: there is nothing to match
// it against, and the request is allowed regardless.
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'not-the-token';
foreach (gatedActions() as $label => $action) {
    [$status, $body] = call($action);
    check($label . ' ignores a bogus token rather than reporting unauthorized ('
        . describe([$status, $body]) . ')',
        $status === 200 && ($body['error'] ?? '') !== 'unauthorized',
        describe([$status, $body]));
}
unset($_SERVER['HTTP_X_ADMIN_TOKEN']);

// =====================================================================
echo "\n== the probes tell the UI the gate is open ==\n";
// =====================================================================

$status = json_decode((new SettingsController($tmp))->status(), true);
check('SettingsController::status reports admin_protection=false and admin_unlocked=true',
    is_array($status) && ($status['admin_protection'] ?? null) === false
    && ($status['admin_unlocked'] ?? null) === true,
    json_encode($status));
check('it still reports the literal admin_configured truth',
    is_array($status) && ($status['admin_configured'] ?? null) === false,
    json_encode($status['admin_configured'] ?? null));
check('the diagnostics name the flag and where it was set',
    ($status['admin_diagnostics']['protection']['key'] ?? '') === AdminAuth::PROTECT_KEY
    && ($status['admin_diagnostics']['protection']['enabled'] ?? null) === false,
    json_encode($status['admin_diagnostics']['protection'] ?? null));

// The Models tab's own probe, built the same way `gatedActions()` builds it
// so there is one construction to keep correct rather than two.
$modelsProbe = (static function () use ($tmp): ModelsController {
    $pdo = new PDO('sqlite::memory:');
    $repo = new ConfigRepository($pdo);
    $audit = new JobAuditRepository($pdo);
    $jobs = new JobRepository($pdo);
    $jobs->setAuditRepository($audit);
    $store = new ModelsSnapshotStore($repo);
    $prefs = new ModelsActualizeSettings($repo);
    $actualizer = new ModelsActualizer(
        new ConnectorRegistry($tmp . '/Connectors'),
        new DomainPresetRepository($pdo),
        $store,
        $audit,
        null,
        new ModelImporter(),
        $prefs,
    );
    return new ModelsController(
        new JobService($jobs, new SessionRepository($pdo)),
        $jobs,
        $store,
        $actualizer,
        null,
        $prefs,
    );
})();
$models = json_decode($modelsProbe->status(), true);
check('ModelsController::status reports it the same way',
    is_array($models) && ($models['admin_protection'] ?? null) === false
    && ($models['admin_unlocked'] ?? null) === true,
    json_encode($models));

$unlock = json_decode((new SettingsController($tmp))->unlock(), true);
check('POST /api/settings/unlock says there is nothing to unlock, and is not an error',
    is_array($unlock) && ($unlock['ok'] ?? false) === true && ($unlock['skipped'] ?? false) === true,
    json_encode($unlock));

boot(1);
$protected = json_decode((new SettingsController($tmp))->status(), true);
check('with the flag back at 1 the probe reports the historical pair',
    ($protected['admin_protection'] ?? null) === true && ($protected['admin_unlocked'] ?? null) === false,
    json_encode($protected));

// =====================================================================
echo "\n== the interface does not ask for a key, and says why ==\n";
// =====================================================================

$js = static fn(string $rel): string => (string)file_get_contents(__DIR__ . '/../assets/js/' . $rel);

check('admin-ui.js exposes the one predicate every module uses',
    str_contains($js('admin-ui.js'), 'export function adminProtected(')
    && str_contains($js('admin-ui.js'), 'oc-admin-protection'),
    'adminProtected() is gone');
check('and a probe that failed defaults to PROTECTED',
    preg_match('/return \{ tab_enabled: true, admin_protection: (\w+),/', $js('admin-ui.js'), $m) === 1
    && $m[1] === 'true',
    $m[1] ?? 'no match');
check('the lock-screen entry points only prompt a protected install',
    str_contains($js('models.js'), 'if (adminProtected(state.status) && !state.status.admin_configured)')
    && str_contains($js('settings.js'), 'if (adminProtected(state.status) && !state.status.admin_configured)'),
    'a module still treats "no token" as a fault');
check('the "token not configured" banners are gated too',
    str_contains($js('api.js'), 'admin_protection !== false && !state.status.admin_configured')
    && str_contains($js('logs.js'), 'admin_protection !== false && !state.status.admin_configured')
    && str_contains($js('security.js'), 'admin_protection !== false && !state.status.admin_configured'),
    'a banner would fire on an unprotected install');
check('the unauthorized hints are gated as well',
    str_contains($js('api.js'), 'admin_protection !== false && (status === 401')
    && str_contains($js('security.js'), 'admin_protection !== false && (status === 401'),
    'a 401 hint would fire on an unprotected install');
check('the panels disclose the state they are in',
    str_contains($js('admin-ui.js'), 'export function renderProtectionNotice(')
    && str_contains($js('models.js'), 'renderProtectionNotice(ROOT, state.status)')
    && str_contains($js('settings.js'), 'renderProtectionNotice(ROOT, state.status)'),
    'no in-panel disclosure');

$layout = (string)file_get_contents(__DIR__ . '/../assets/templates/layout.html');
check('the page renders the disclosure server-side, so it is visible before any script',
    str_contains($layout, 'data-oc-protection-notice')
    && str_contains($layout, 'Settings are not protected by an admin key'),
    'the notice is missing from layout.html');
check('it is hidden when the gate is on',
    str_contains($layout, '{{admin_protection_hidden}}'),
    'the notice has no hide switch');
check('the first paint carries the flag as a meta tag',
    str_contains($layout, 'name="oc-admin-protection"')
    && str_contains($layout, '{{admin_protection}}'),
    'the meta tag is missing');
check('and the meta tag names the key to change',
    str_contains($layout, 'name="oc-admin-protection-key"')
    && str_contains($layout, '{{admin_protection_flag}}'),
    'the key name is not surfaced');

$index = (string)file_get_contents(__DIR__ . '/../index.php');
check('renderHome() passes the three template variables',
    str_contains($index, "'admin_protection'        => \\App\\Core\\AdminAuth::protectionEnabled()")
    && str_contains($index, "'admin_protection_flag'")
    && str_contains($index, "'admin_protection_hidden'"),
    'the template variables are not wired');
check('/api/tabs reports admin_protection',
    str_contains($index, "'admin_protection'  => \\App\\Core\\AdminAuth::protectionEnabled(),"),
    '/api/tabs is missing the flag');
check('/api/boot-config reports the admin gate block',
    str_contains($index, "'admin' => [")
    && str_contains($index, "'protection' => \\App\\Core\\AdminAuth::protectionEnabled(),"),
    '/api/boot-config is missing the gate block');

// =====================================================================
echo "\n== config.php and .config.php carry the key, with the risk stated ==\n";
// =====================================================================

$configPhp = (string)file_get_contents(__DIR__ . '/../config.php');
check('config.php sets Protect_Settings_by_Admin_Key',
    preg_match("/'Protect_Settings_by_Admin_Key'\s*=>\s*([01]),/", $configPhp, $m) === 1,
    'the key is not in config.php');
check('and documents what 0 and 1 mean, and what 0 exposes',
    str_contains($configPhp, 'WHAT IT UNLOCKS AT 0')
    && str_contains($configPhp, 'guards pass without one, no unlock'),
    'the risk is not documented in config.php');
$localConfig = (string)@file_get_contents(__DIR__ . '/../.config.php');
check('.config.php carries the same setting as the local override',
    $localConfig !== ''
    && str_contains($localConfig, 'Protect_Settings_by_Admin_Key'),
    '.config.php has no override');
check('.config.php is git-ignored, as its own documentation claims',
    str_contains((string)file_get_contents(__DIR__ . '/../.gitignore'), '/.config.php'),
    'a deployment overlay could be committed');

rmTree($tmp);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
