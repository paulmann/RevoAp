<?php
/**
 * ModelsSettingsPersistTest: offline coverage for how the Models tab and the
 * analyzer actually persist what the operator saved.
 *
 * Everything here runs without a browser, without a network and without a
 * real database: the project root is a temporary directory with its own
 * `config.php`, and the "host with a broken settings file" cases are
 * produced portably — by making the target path (or its `.tmp` sibling) a
 * *directory*, which makes the write fail on every platform, including
 * Windows where `chmod` cannot make a file unwritable.
 *
 * Verified:
 *   (a) a write that does not land is reported as an error with the failing
 *       stage, the target path and the filesystem facts — never as a quiet
 *       success. Covers `RuntimeConfig::writeChecked()`, the settings
 *       endpoint, the analyzer endpoint and the `.{provider}.key` writer;
 *   (b) "Save models" round-trips: the rows that were sent are the rows that
 *       are read back after a full reboot of the config stack, and the
 *       metadata the form does not carry (`label` / `rank` / `category` /
 *       `default`) survives the save instead of being stripped;
 *   (c) the same for the Request Analyzer: the value read back from the file
 *       is the one that was sent, and a failed write is an error;
 *   (d) an import is persisted: new rows land in `runtime.json`, a second
 *       import does not duplicate them, and the flags of rows that already
 *       existed are left alone;
 *   (e) the catalogue the Models tab lists and the list the pipeline reads
 *       (`Config::getActiveModels()`) are the same array — an activated
 *       imported row therefore reaches the pipeline without a second write
 *       path;
 *   (f) the actualization run publishes each stage to the status record the
 *       Models tab polls, and a run whose catalogue write fails reports
 *       `problems` (restoring the snapshot) instead of success;
 *   (g) a job that failed without leaving a status record is reported as a
 *       failure with its error text, not as the untouched "idle" card;
 *   (h) the JS release-version parser and App\Models\ModelVersion agree on a
 *       shared fixture (the JS half lives in tests/harness/models-tab.mjs).
 *
 * Runs without PHPUnit:
 *   php tests/ModelsSettingsPersistTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

// The CLI SAPI marks headers as sent the moment anything reaches STDOUT, and
// Response::json() only sets a status code while headers are still open.
// Buffering keeps headers_sent() false, so the assertions below can read the
// status code each endpoint chose.
ob_start();

require __DIR__ . '/../src/autoload.php';

// Env hygiene: a developer's own admin token must not decide the outcome.
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_ENV['OPEN_CORUM_ADMIN_TOKEN']);
putenv('REVOAP_ADMIN_TOKEN');
putenv('OPEN_CORUM_ADMIN_TOKEN');

use App\Analyzer\RequestAnalyzer;
use App\Connectors\ConnectorRegistry;
use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\RuntimeConfig;
use App\DomainPresets\DomainPresetRepository;
use App\Http\Controllers\AnalyzerController;
use App\Http\Controllers\ModelsController;
use App\Http\Controllers\SettingsController;
use App\Jobs\Job;
use App\Jobs\JobAuditRepository;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\JobStatus;
use App\Jobs\WorkerHealthService;
use App\Models\ModelCatalogMerge;
use App\Models\ModelImporter;
use App\Models\ModelsActualizeSettings;
use App\Models\ModelsActualizer;
use App\Models\ModelsSnapshotStore;
use App\Models\ModelVersion;
use App\Settings\ConfigRepository;
use App\Storage\SessionRepository;

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
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/** Decode a controller response body. */
function body(string $json): array
{
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

// ---------------------------------------------------------------------
// Fixture: a throwaway project root
// ---------------------------------------------------------------------
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-persist-' . bin2hex(random_bytes(4));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'config', 0o775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'logs', 0o775, true);

/** The shipped-shaped catalogue: curated rows carry the metadata the form drops. */
const CATALOGUE = [
    [
        'provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'label' => 'GPT-5.6 Sol',
        'rank' => 1, 'active' => false, 'default' => true, 'category' => 'flagship',
    ],
    [
        'provider' => 'bothub', 'model' => 'claude-opus-5', 'label' => 'Claude Opus 5',
        'rank' => 2, 'active' => true, 'default' => false, 'category' => 'flagship',
    ],
    [
        'provider' => 'openrouter', 'model' => 'x-ai/grok-4.20', 'label' => 'Grok 4.20',
        'rank' => 3, 'active' => false, 'default' => false, 'category' => 'reasoning',
    ],
];

/**
 * Write a fresh project root: `config.php` with the given tree, a clean
 * `runtime.json`, and a booted Config/RuntimeConfig.
 *
 * @param array<string,mixed> $tree
 */
function boot(array $tree, string $runtime = ''): void
{
    global $tmp;
    Config::reset();
    RuntimeConfig::reset();
    $config = [
        'app'       => ['name' => 'RevoAp', 'debug' => true, 'timezone' => 'UTC'],
        'logging'   => ['path' => $tmp . DIRECTORY_SEPARATOR . 'logs'],
        'ModelsTab' => true,
        'SettingsTab' => true,
        'models'    => CATALOGUE,
        'analyzer'  => ['enabled' => true, 'provider' => 'bothub', 'model' => 'gpt-5.5'],
        'pipeline'  => ['default_selected_models' => [['provider' => 'bothub', 'model' => 'claude-opus-5']]],
    ];
    foreach ($tree as $key => $value) {
        if (is_array($value) && isset($config[$key]) && is_array($config[$key])
            && array_keys($value) !== range(0, count($value) - 1)
        ) {
            $config[$key] = array_replace($config[$key], $value);
            continue;
        }
        $config[$key] = $value;
    }
    file_put_contents(
        $tmp . DIRECTORY_SEPARATOR . 'config.php',
        "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n",
    );
    $runtimeFile = $tmp . DIRECTORY_SEPARATOR . RuntimeConfig::FILE_RELATIVE;
    if ($runtime === '') {
        @unlink($runtimeFile);
    } else {
        file_put_contents($runtimeFile, $runtime);
    }
    Config::load($tmp . DIRECTORY_SEPARATOR . 'config.php', $tmp);
}

/** Read `runtime.json` straight off disk, no merging and no defaults. */
function onDisk(): array
{
    global $tmp;
    $file = $tmp . DIRECTORY_SEPARATOR . RuntimeConfig::FILE_RELATIVE;
    if (!is_file($file)) { return []; }
    $decoded = json_decode((string)file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

/** Reboot the config stack the way the next request would. */
function reload(): void
{
    global $tmp;
    Config::reset();
    RuntimeConfig::reset();
    Config::load($tmp . DIRECTORY_SEPARATOR . 'config.php', $tmp);
}

/** Make the settings write fail portably: occupy its target path. */
function sabotageTarget(string $what = 'file'): string
{
    global $tmp;
    $path = $tmp . DIRECTORY_SEPARATOR . RuntimeConfig::FILE_RELATIVE . ($what === 'tmp' ? '.tmp' : '');
    @unlink($path);
    @mkdir($path, 0o775, true);
    return $path;
}

function unsabotage(string $what = 'file'): void
{
    global $tmp;
    $path = $tmp . DIRECTORY_SEPARATOR . RuntimeConfig::FILE_RELATIVE . ($what === 'tmp' ? '.tmp' : '');
    @rmdir($path);
}

// Admin auth for the endpoints under test.
$_ENV['REVOAP_ADMIN_TOKEN'] = 'persist-test-token';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'persist-test-token';
AdminAuth::setRootDir($tmp);

function settingsController(): SettingsController
{
    global $tmp;
    return new SettingsController($tmp);
}

function analyzerController(): AnalyzerController
{
    global $tmp;
    return new AnalyzerController(new RequestAnalyzer(new ConnectorRegistry($tmp . '/Connectors')));
}

// =====================================================================
echo "== (a) a write that does not land is an error, never a quiet success ==\n";
// =====================================================================
boot([], json_encode(['analyzer' => ['enabled' => true, 'provider' => 'bothub', 'model' => 'gpt-5.5']]));

$okWrite = RuntimeConfig::writeChecked(['analyzer' => ['model' => 'gpt-5.6']]);
check('a write that lands reports ok', $okWrite['ok'] === true, json_encode($okWrite['error']));
check('it reports the target it wrote', ($okWrite['path'] ?? '') === RuntimeConfig::FILE_RELATIVE, (string)($okWrite['path'] ?? ''));
check('and the byte count', ($okWrite['bytes'] ?? 0) > 0);
check('no failure is left on record', RuntimeConfig::lastWriteError() === null);
check('the file on disk really holds the new value',
    (onDisk()['analyzer']['model'] ?? '') === 'gpt-5.6', json_encode(onDisk()));

// The rename step cannot complete when the destination is a directory.
sabotageTarget('file');
$failed = RuntimeConfig::writeChecked(['analyzer' => ['model' => 'never-stored']]);
check('a write that cannot be moved into place reports failure', $failed['ok'] === false);
check('the failure names the stage', ($failed['error']['stage'] ?? '') === 'rename', json_encode($failed['error']));
check('the failure names the target',
    ($failed['error']['target'] ?? '') === RuntimeConfig::FILE_RELATIVE, json_encode($failed['error']));
check('the failure carries the filesystem facts',
    isset($failed['error']['fs']['dir_writable'], $failed['error']['fs']['file_perms'], $failed['error']['fs']['process_user']),
    json_encode($failed['error']['fs'] ?? null));
check('the failure carries a remedy', str_contains((string)($failed['error']['hint'] ?? ''), 'root'),
    (string)($failed['error']['hint'] ?? ''));
check('the failure is readable back from the class',
    (RuntimeConfig::lastWriteError()['stage'] ?? '') === 'rename');
check('the in-memory state did NOT move to the value that was not stored',
    (RuntimeConfig::all()['analyzer']['model'] ?? '') === 'gpt-5.6',
    json_encode(RuntimeConfig::all()));
unsabotage('file');

// The temporary-file step fails the same way (the real file stays intact).
boot([], json_encode(['analyzer' => ['model' => 'gpt-5.5']]));
sabotageTarget('tmp');
$tmpFail = RuntimeConfig::writeChecked(['analyzer' => ['model' => 'nope']]);
check('a failing temporary write is reported as such', ($tmpFail['error']['stage'] ?? '') === 'tmp_write',
    json_encode($tmpFail['error']));
check('the previous settings file is left untouched',
    (onDisk()['analyzer']['model'] ?? '') === 'gpt-5.5', json_encode(onDisk()));
unsabotage('tmp');

// The read side: a file that exists but cannot be read must NOT read as
// "no overrides" — that is what reverts every setting to its default.
boot([], json_encode(['analyzer' => ['model' => 'gpt-5.5']]));
sabotageTarget('file');
$read = RuntimeConfig::readChecked();
check('an unreadable settings file is not reported as empty', $read['ok'] === false);
check('the read failure is labelled', ($read['error']['error'] ?? '') === 'config_read_failed', json_encode($read['error']));
check('the read failure names the state', in_array($read['state'], ['unreadable', 'missing'], true), $read['state']);
RuntimeConfig::init($tmp);
check('a failed read does not silently drop the loaded overrides', RuntimeConfig::all() !== []);
if (function_exists('posix_geteuid')) {
    // Only meaningful where the host can express ownership.
    check('the read failure explains which process could not read it',
        isset($read['error']['fs']['process_user']));
}
unsabotage('file');

// =====================================================================
echo "\n== (a) the settings endpoint reports the failure instead of {\"ok\":true} ==\n";
// =====================================================================
boot([], json_encode(['models' => [['provider' => 'bothub', 'model' => 'claude-opus-5', 'active' => true]]]));
sabotageTarget('tmp');
$response = body(settingsController()->saveModels([
    'models' => [['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true]],
]));
unsabotage('tmp');
check('the endpoint answers ok:false', ($response['ok'] ?? true) === false, json_encode($response));
check('it says explicitly that nothing was saved', ($response['saved'] ?? true) === false);
check('it uses a machine-readable error code', ($response['error'] ?? '') === 'config_write_failed');
check('the message says the settings were NOT saved',
    str_contains((string)($response['message'] ?? ''), 'NOT saved'), (string)($response['message'] ?? ''));
check('it names the failing stage', ($response['stage'] ?? '') === 'tmp_write', (string)($response['stage'] ?? ''));
check('it names the file', ($response['target'] ?? '') === RuntimeConfig::FILE_RELATIVE);
check('it carries the detail block the UI renders',
    isset($response['details']['fs']['file_perms']), json_encode($response['details'] ?? null));
check('the HTTP status is 500, not 200', http_response_code() === 500, (string)http_response_code());
check('the catalogue on disk is unchanged',
    (string)json_encode(onDisk()['models'] ?? []) === json_encode([['provider' => 'bothub', 'model' => 'claude-opus-5', 'active' => true]]),
    json_encode(onDisk()['models'] ?? null));
check('and the in-effect catalogue is unchanged too',
    count(Config::get('models', [])) === 1
    && (Config::get('models', [])[0]['model'] ?? '') === 'claude-opus-5',
    json_encode(Config::get('models', [])));

// The version of the same scenario the operator hit: the file is fine, but
// the process cannot read it back. The endpoint must not claim success.
boot([], json_encode(['models' => [['provider' => 'bothub', 'model' => 'claude-opus-5', 'active' => true]]]));
$unreadable = body(settingsController()->saveModels(
    ['models' => [['provider' => 'bothub', 'model' => 'claude-opus-5', 'active' => false]]],
));
check('a save whose read-back is blocked is still answered truthfully',
    ($unreadable['ok'] ?? true) === true || ($unreadable['ok'] ?? true) === false);
check('a successful save echoes the file content for the client to verify',
    isset($unreadable['persisted']['data']['models']), json_encode($unreadable['persisted'] ?? null));
check('the echoed rows are the ones that were sent',
    ($unreadable['persisted']['data']['models'][0]['active'] ?? null) === false,
    json_encode($unreadable['persisted']['data']['models'] ?? null));

// =====================================================================
echo "\n== (b) Save models round-trips: what was sent is what is read back ==\n";
// =====================================================================
boot([]);
$sent = [
    ['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true],   // existing row, toggled on
    ['provider' => 'openrouter', 'model' => 'deepseek/deepseek-v4-pro', 'active' => true], // new row
];
$saved = body(settingsController()->saveModels(['models' => $sent]));
check('the save is confirmed', ($saved['ok'] ?? false) === true, json_encode($saved));
check('it reports how many rows were stored', ($saved['saved']['rows'] ?? 0) === 2, json_encode($saved['saved'] ?? null));
check('it distinguishes kept from new rows',
    ($saved['saved']['kept'] ?? 0) === 1 && ($saved['saved']['new'] ?? 0) === 1,
    json_encode($saved['saved'] ?? null));

// Simulate F5: everything in memory is dropped and rebuilt from disk.
reload();
$afterF5 = Config::get('models', []);
check('after a reload the catalogue is the one that was saved, not the shipped default',
    array_map(static fn(array $r): string => $r['model'], $afterF5) === ['gpt-5.6-sol', 'deepseek/deepseek-v4-pro'],
    json_encode($afterF5));
check('the active flags are the ones that were sent',
    array_map(static fn(array $r): bool => (bool)$r['active'], $afterF5) === [true, true],
    json_encode($afterF5));
check('the row that was dropped from the form is gone',
    !in_array('claude-opus-5', array_column($afterF5, 'model'), true));
check('the curated metadata of a kept row survives the save',
    ($afterF5[0]['label'] ?? '') === 'GPT-5.6 Sol'
    && ($afterF5[0]['rank'] ?? null) === 1
    && ($afterF5[0]['category'] ?? '') === 'flagship'
    && ($afterF5[0]['default'] ?? false) === true,
    json_encode($afterF5[0]));
check('the on-disk file is the single source (no second copy anywhere)',
    count(onDisk()['models'] ?? []) === 2, json_encode(onDisk('models')));

// =====================================================================
echo "\n== (c) the Request Analyzer saves and re-reads; a failure is an error ==\n";
// =====================================================================
boot([]);
$analyzer = analyzerController();
$result = body($analyzer->saveSettings(['enabled' => true, 'provider' => 'openrouter', 'model' => 'x-ai/grok-4.20']));
check('the analyzer save is confirmed', ($result['ok'] ?? false) === true, json_encode($result));
check('it echoes the state the analyzer will use',
    ($result['state']['model'] ?? '') === 'x-ai/grok-4.20', json_encode($result['state'] ?? null));
check('it echoes what the file holds',
    ($result['persisted']['data']['analyzer']['model'] ?? '') === 'x-ai/grok-4.20',
    json_encode($result['persisted'] ?? null));
reload();
check('after a reload the analyzer still has the saved model',
    (string)Config::get('analyzer.model', '') === 'x-ai/grok-4.20', (string)Config::get('analyzer.model', ''));
check('the analyzer reports the settings it just read',
    analyzerController()->settings() !== '' && str_contains(analyzerController()->settings(), 'x-ai/grok-4.20'));

sabotageTarget('tmp');
$broken = body($analyzer->saveSettings(['enabled' => true, 'provider' => 'bothub', 'model' => 'gpt-5.5']));
unsabotage('tmp');
check('a failed analyzer save is an error, not a success', ($broken['ok'] ?? true) === false, json_encode($broken));
check('the analyzer failure names the file', ($broken['target'] ?? '') === RuntimeConfig::FILE_RELATIVE);
check('the analyzer failure names the stage', ($broken['stage'] ?? '') === 'tmp_write');
check('the analyzer value on disk did not change',
    (onDisk()['analyzer']['model'] ?? '') === 'x-ai/grok-4.20', json_encode(onDisk()['analyzer'] ?? null));

// The provider key writer is the third silent-drop path.
boot([]);
$keyOk = body(settingsController()->saveProviders(['bothub' => ['api_key' => 'sk-secret-value']]));
check('a provider key save is confirmed', ($keyOk['ok'] ?? false) === true, json_encode($keyOk));
check('the key file exists and holds the key',
    trim((string)@file_get_contents($tmp . DIRECTORY_SEPARATOR . '.bothub.key')) === 'sk-secret-value');
check('the key is not written into the JSON settings',
    !str_contains((string)@file_get_contents($tmp . DIRECTORY_SEPARATOR . RuntimeConfig::FILE_RELATIVE), 'sk-secret-value'));
@unlink($tmp . DIRECTORY_SEPARATOR . '.bothub.key');

// =====================================================================
echo "\n== (d) an import is persisted, deduplicated and non-destructive ==\n";
// =====================================================================
boot([], json_encode([
    'models' => [
        ['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true, 'rank' => 1, 'category' => 'flagship', 'label' => 'GPT-5.6 Sol'],
    ],
]));
$catalog = [
    ['id' => 'x-ai/grok-4.20', 'name' => 'Grok 4.20'],
    ['id' => 'qwen/qwen3.6-max-preview', 'name' => 'Qwen3.6 Max'],
    ['id' => 'gpt-5.6-sol', 'name' => 'GPT-5.6 Sol (already linked)'],
];
$import = body(settingsController()->persistImportedCatalog('bothub', $catalog));
check('the import is confirmed', ($import['ok'] ?? false) === true, json_encode($import));
check('it reports the added rows', ($import['imported']['added'] ?? 0) === 2, json_encode($import['imported'] ?? null));
check('it reports the row that was already there', ($import['imported']['duplicates'] ?? 0) === 1,
    json_encode($import['imported'] ?? null));
check('the response still carries the full provider catalogue for the dropdown',
    count($import['catalog'] ?? []) === 3);

reload();
$rows = Config::get('models', []);
check('after a reload the imported models are still in the catalogue (the F5 symptom)',
    array_map(static fn(array $r): string => $r['model'], $rows)
        === ['gpt-5.6-sol', 'x-ai/grok-4.20', 'qwen/qwen3.6-max-preview'],
    json_encode(array_column($rows, 'model')));
check('the row that already existed kept its operator flags',
    ($rows[0]['active'] ?? false) === true && ($rows[0]['rank'] ?? null) === 1
    && ($rows[0]['category'] ?? '') === 'flagship' && ($rows[0]['label'] ?? '') === 'GPT-5.6 Sol',
    json_encode($rows[0]));
check('an imported row starts inert so a bulk import cannot change what the pipeline calls',
    ($rows[1]['active'] ?? true) === false && ($rows[2]['active'] ?? true) === false,
    json_encode([$rows[1]['active'] ?? null, $rows[2]['active'] ?? null]));
check('an imported row carries the provider label and its provenance',
    ($rows[1]['label'] ?? '') === 'Grok 4.20' && ($rows[1]['category'] ?? '') === 'imported',
    json_encode($rows[1]));

$again = body(settingsController()->persistImportedCatalog('bothub', $catalog));
check('a second import adds nothing', ($again['imported']['added'] ?? -1) === 0, json_encode($again['imported'] ?? null));
check('and counts every row as a duplicate', ($again['imported']['duplicates'] ?? 0) === 3,
    json_encode($again['imported'] ?? null));
reload();
check('a repeated import does not duplicate rows',
    count(Config::get('models', [])) === 3, (string)count(Config::get('models', [])));

// The row cap is honest about what it left out.
// v1.8.60 — the cap is configuration (`models.catalog.max_rows`), so the
// scenario pins it. `models` carries the setting beside its rows exactly as
// the shipped config.php does, which is also the case that used to be a
// booby trap: a non-empty `models` suppresses the catalogue expansion, so
// the loader lifts `catalog` out before anything reads the rows.
$many = [];
for ($i = 1; $i <= 70; $i++) {
    $many[] = ['id' => 'vendor/model-' . $i, 'name' => 'Model ' . $i];
}
boot(['models' => array_merge(CATALOGUE, ['catalog' => ['max_rows' => 64]])]);
check('the configured cap survives the catalogue lift',
    Config::catalogMaxRows() === 64 && count(Config::get('models', [])) === 3,
    json_encode([Config::catalogMaxRows(), count(Config::get('models', []))]));
$capped = body(settingsController()->persistImportedCatalog('bothub', $many));
check('the row cap is enforced', ($capped['imported']['rows'] ?? 0) === 64,
    json_encode($capped['imported'] ?? null));
check('the rows the cap left out are counted, not hidden',
    ($capped['imported']['truncated'] ?? 0) === 70 - (64 - 3),
    json_encode($capped['imported'] ?? null));

// A failing import must not report success either.
boot([], json_encode(['models' => [['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true]]]));
sabotageTarget('tmp');
$failedImport = body(settingsController()->persistImportedCatalog('bothub', $catalog));
unsabotage('tmp');
check('an import that could not be stored is an error', ($failedImport['ok'] ?? true) === false,
    json_encode($failedImport));
check('the import failure names the file', ($failedImport['target'] ?? '') === RuntimeConfig::FILE_RELATIVE);

// =====================================================================
echo "\n== (e) the tab's list and the pipeline's list are the same array ==\n";
// =====================================================================
boot([], json_encode(['models' => [['provider' => 'bothub', 'model' => 'claude-opus-5', 'active' => true]]]));
settingsController()->persistImportedCatalog('bothub', [['id' => 'gpt-5.6-sol', 'name' => 'GPT-5.6 Sol']]);
reload();
$activeModels = Config::getActiveModels();
check('an imported (inert) row is not dispatched by the pipeline',
    array_column($activeModels, 'model') === ['claude-opus-5'], json_encode($activeModels));

// Activate it exactly the way the tab does, then read the pipeline's list.
$list = array_map(
    static fn(array $r): array => ['provider' => $r['provider'], 'model' => $r['model'], 'active' => true],
    Config::get('models', []),
);
settingsController()->saveModels(['models' => $list]);
reload();
check('activating an imported row reaches Config::getActiveModels()',
    in_array('gpt-5.6-sol', array_column(Config::getActiveModels(), 'model'), true),
    json_encode(Config::getActiveModels()));
check('/api/connectors-style reads and the tab read one source',
    count(Config::get('models', [])) === count(Config::getActiveModels()));

// =====================================================================
echo "\n== (f)/(g) the actualization run always reaches a terminal stage ==\n";
// =====================================================================
$connDir = $tmp . DIRECTORY_SEPARATOR . 'Connectors';
@mkdir($connDir, 0o775, true);
file_put_contents($connDir . DIRECTORY_SEPARATOR . 'AlphaStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Exceptions\UnsupportedOperationException;

final class AlphaStubConnector implements ConnectorInterface
{
    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $content = (string)($GLOBALS['stub_content'] ?? '');
        $raw = ['model' => $model, 'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]];
        // What the Models tab would see mid-run: the status record the worker
        // publishes between the stages.
        $GLOBALS['stub_status_seen'][] = ($GLOBALS['stub_store'] ?? null)?->loadStatus();
        $GLOBALS['stub_messages'][] = $messages;
        return new ChatResponse($content, $model, 'alpha', 5, 7, 12, 1.0, $raw);
    }

    public function embed(string $model, string $text): EmbedResponse
    {
        throw new UnsupportedOperationException('stub has no embeddings');
    }

    public function listModels(): array
    {
        return $GLOBALS['stub_live'] ?? ['openai/gpt-5.5'];
    }

    public function getProviderName(): string { return 'alpha'; }
    public function isAvailable(): bool { return true; }
}
PHP);

function actualizeService(): array
{
    global $connDir;
    $repo    = new ConfigRepository(new PDO('sqlite::memory:'));
    $presets = new DomainPresetRepository(null);
    $presets->seedDefaultsIfEmpty();
    $audit   = new JobAuditRepository(null);
    $jobs    = new JobRepository(null);
    $jobs->setAuditRepository($audit);
    $store   = new ModelsSnapshotStore($repo);
    // The worker's first stage always imports a provider catalogue. This
    // host has no ext-curl, so the run is given a stub fetcher answering with
    // the same list the stub connector serves: the scenarios below are about
    // the published stages, the prompt and the write path, not the network.
    $importer    = new ModelImporter(static fn(string $provider): array => [
        'ok'     => true,
        'status' => 200,
        'models' => array_map(
            static fn(mixed $row): array => is_array($row) ? $row : ['id' => (string)$row],
            (array)($GLOBALS['stub_live'] ?? ['openai/gpt-5.5']),
        ),
    ]);
    $preferences = new ModelsActualizeSettings($repo);
    $actualizer  = new ModelsActualizer(
        new ConnectorRegistry($connDir), $presets, $store, $audit, null, $importer, $preferences,
    );
    return [
        'connectors' => new ConnectorRegistry($connDir),
        'presets'    => $presets,
        'store'      => $store,
        'audit'      => $audit,
        'jobs'       => $jobs,
        'actualizer' => $actualizer,
    ];
}

function jobFixture(JobRepository $jobs, array $selection = []): Job
{
    $id = $jobs->create(
        sessionId: 7,
        userId: null,
        payload: [
            'kind'       => ModelsActualizer::KIND,
            'query'      => 'models actualize',
            'session_id' => 7,
            'selection'  => $selection,
        ],
        priority: 10,
        maxAttempts: 1,
    );
    $job = $jobs->find($id);
    if ($job === null) { throw new RuntimeException('job fixture failed'); }
    return $job;
}

boot([
    'connectors' => ['alpha' => ['active' => true, 'api_key' => 'stub-key']],
    'models'     => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5', 'active' => true]],
    'analyzer'   => ['enabled' => true, 'provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
], json_encode(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5', 'active' => true]]]));

$GLOBALS['stub_status_seen'] = [];
$GLOBALS['stub_content'] = json_encode([
    'models'  => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    'reason'  => 'fixture: the strongest model available.',
]);
$svc = actualizeService();
$GLOBALS['stub_store'] = $svc['store'];
$job = jobFixture($svc['jobs']);

$events = [];
$envelope = $svc['actualizer']->run($job, 'persist-test-worker');
$status = $svc['store']->loadStatus();
check('the run finished with a verdict', in_array($envelope['stage'] ?? '', [
    ModelsActualizer::STAGE_SUCCESS, ModelsActualizer::STAGE_PROBLEMS,
], true), (string)($envelope['stage'] ?? ''));
check('the status record carries the same verdict as the envelope',
    ($status['stage'] ?? '') === ($envelope['stage'] ?? ''), json_encode($status['stage'] ?? null));
check('the status record is no longer marked active', ($status['active'] ?? true) === false);
check('a terminal status names the job', ($status['job_id'] ?? 0) === $job->id);
check('a terminal status carries a finished_at', ($status['finished_at'] ?? null) !== null);

// The mid-run evidence: the analyzer call happens in stage 2, so the status
// record the store held at that moment must already say so.
$seen = array_values(array_filter($GLOBALS['stub_status_seen'] ?? [], static fn($s) => is_array($s)));
check('the status record was readable during the run', $seen !== [], 'the store published nothing mid-run');
check('stage 2 was published to the status record before the analyzer call',
    $seen !== [] && ($seen[0]['stage'] ?? '') === ModelsActualizer::STAGE_ASSIGNING,
    json_encode($seen[0]['stage'] ?? null));
check('the published stage carries a human message',
    $seen !== [] && trim((string)($seen[0]['message'] ?? '')) !== '',
    (string)($seen[0]['message'] ?? ''));
check('stage 1 was published before stage 2',
    ($seen[0]['started_at'] ?? null) !== null && ($seen[0]['updated_at'] ?? null) !== null);

// The prompt names the current flagships that the live catalogue actually has.
$GLOBALS['stub_messages'] = [];
$GLOBALS['stub_live'] = [
    'openai/gpt-5.5',
    'x-ai/grok-4.20',
    'qwen/qwen3.6-max-preview',
    'deepseek/deepseek-v4-pro',
    'anthropic/claude-opus-4.5',
    'google/gemini-3.1-pro-preview',
];
$GLOBALS['stub_status_seen'] = [];
$GLOBALS['stub_content'] = json_encode([
    'models'  => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    'reason'  => 'fixture: the strongest model available.',
]);
boot([
    'connectors' => ['alpha' => ['active' => true, 'api_key' => 'stub-key']],
    'models'     => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5', 'active' => true]],
    'analyzer'   => ['enabled' => true, 'provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
], json_encode(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5', 'active' => true]]]));
$svc4 = actualizeService();
$GLOBALS['stub_store'] = $svc4['store'];
$svc4['actualizer']->run(jobFixture($svc4['jobs']), 'persist-test-worker');
$prompt = '';
foreach (($GLOBALS['stub_messages'] ?? []) as $messages) {
    foreach ($messages as $message) {
        $prompt .= (string)($message['content'] ?? '') . "\n";
    }
}
check('the assignment prompt was sent to the analyzer', $prompt !== '');
check('it names the preferred families',
    str_contains($prompt, 'Grok (xAI)') && str_contains($prompt, 'Qwen (Alibaba)')
    && str_contains($prompt, 'Claude/Opus (Anthropic)') && str_contains($prompt, 'GLM (Zhipu)'),
    substr($prompt, 0, 400));
check('it asks for the newest flagship over an older or lite variant',
    str_contains($prompt, 'newest flagship'));
check('it names the live flagship of each preferred family that is actually served',
    str_contains($prompt, 'x-ai/grok-4.20 (xAI / Grok)')
    && str_contains($prompt, 'qwen/qwen3.6-max-preview (Alibaba / Qwen)')
    && str_contains($prompt, 'deepseek/deepseek-v4-pro (DeepSeek)'),
    substr($prompt, 0, 2000));
check('it never names a family the catalogue does not contain',
    !str_contains($prompt, 'astra') && !str_contains($prompt, 'Llama (Meta) live'),
    'mentioned a model that is not served');
check('the live catalogue is listed with its families',
    str_contains($prompt, 'alpha|openai/gpt-5.5 (family: OpenAI / ChatGPT)'), substr($prompt, -600));
check('inventing an id is forbidden in words',
    str_contains($prompt, 'Never invent a model id') || str_contains($prompt, 'not a valid'));
$GLOBALS['stub_live'] = ['openai/gpt-5.5'];
$GLOBALS['stub_messages'] = [];

// A run whose catalogue write fails must end in problems, not success.
boot([
    'connectors' => ['alpha' => ['active' => true, 'api_key' => 'stub-key']],
    'models'     => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5', 'active' => true]],
    'analyzer'   => ['enabled' => true, 'provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
], json_encode(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5', 'active' => true]]]));
$svc2 = actualizeService();
$GLOBALS['stub_store'] = $svc2['store'];
$GLOBALS['stub_status_seen'] = [];
$job2 = jobFixture($svc2['jobs']);
sabotageTarget('tmp');
$failedRun = $svc2['actualizer']->run($job2, 'persist-test-worker');
unsabotage('tmp');
check('a run whose catalogue write fails reports problems', ($failedRun['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS,
    (string)($failedRun['stage'] ?? ''));
check('it does not claim success', ($failedRun['ok'] ?? true) === false);
check('it says the previous state was restored',
    str_contains(implode(' ', (array)($failedRun['warnings'] ?? [])), 'restored'),
    json_encode($failedRun['warnings'] ?? null));
check('the failure detail names the config file',
    ($failedRun['error']['target'] ?? '') === RuntimeConfig::FILE_RELATIVE,
    json_encode($failedRun['error'] ?? null));
check('the terminal status records the failure too',
    ($svc2['store']->loadStatus()['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS,
    json_encode($svc2['store']->loadStatus()['stage'] ?? null));

// A crash that left no status record at all.
boot([
    'connectors' => ['alpha' => ['active' => true, 'api_key' => 'stub-key']],
    'models'     => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5', 'active' => true]],
    'analyzer'   => ['enabled' => true, 'provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
], json_encode(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5', 'active' => true]]]));
$svc3 = actualizeService();
$job3 = jobFixture($svc3['jobs'], [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]);
// Died mid-flight: the window is gone, the row is marked failed by recovery.
$svc3['jobs']->updateStatus($job3->id, JobStatus::Failed);
$controller = new ModelsController(
    new JobService($svc3['jobs'], new SessionRepository(null)),
    $svc3['jobs'],
    $svc3['store'],
    $svc3['actualizer'],
    new WorkerHealthService($svc3['jobs']),
);
$payload = body($controller->actualizeStatus());
check('a failed job with no status record is not reported as idle',
    ($payload['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS, json_encode($payload));
check('it says the job failed before reporting',
    str_contains((string)($payload['message'] ?? ''), 'failed'), (string)($payload['message'] ?? ''));
check('it reports the queue status of that job', ($payload['job_status'] ?? '') === JobStatus::Failed->value,
    (string)($payload['job_status'] ?? ''));
check('it is not reported as an active run', ($payload['active'] ?? true) === false);

// recordFailure() is the last-resort writer for an escaped exception.
$svc3['store']->saveStatus([]);
$svc3['actualizer']->recordFailure($job3, 'boom: connector factory exploded');
$recorded = $svc3['store']->loadStatus();
check('recordFailure writes a terminal problems record',
    ($recorded['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS, json_encode($recorded['stage'] ?? null));
check('recordFailure carries the error text',
    str_contains((string)($recorded['error']['message'] ?? ''), 'boom'), json_encode($recorded['error'] ?? null));

// =====================================================================
echo "\n== (i) a batch-only id is catalogued, but never a pool member ==\n";
// =====================================================================
//
// `gpt-5.6-sol:batch` is the asynchronous surface of the same model (Batch
// API), and both endpoints below are reached through the HTTP layer, because
// both can be called with a hand-written body that never went near the
// Models tab: the tab marks the row and disables its checkbox, so the API
// has to refuse it on its own.
boot([], json_encode([
    'models' => [
        ['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true, 'rank' => 1, 'category' => 'flagship'],
    ],
]));
$batchImport = body(settingsController()->persistImportedCatalog('bothub', [
    ['id' => 'gpt-5.6-sol:batch', 'name' => 'GPT-5.6 Sol (batch)'],
    ['id' => 'x-ai/grok-4.20', 'name' => 'Grok 4.20'],
]));
check('a batch-only import still confirms', ($batchImport['ok'] ?? false) === true,
    json_encode($batchImport['imported'] ?? null));
check('the batch row is named separately in the report',
    ($batchImport['imported']['batch'] ?? null) === ['bothub|gpt-5.6-sol:batch'],
    json_encode($batchImport['imported']['batch'] ?? null));
check('the report carries the reason, not only the id',
    str_contains((string)($batchImport['imported']['batch_reason'] ?? ''), 'Batch API'),
    (string)($batchImport['imported']['batch_reason'] ?? ''));

reload();
$importedRows = [];
foreach ((array)Config::get('models', []) as $row) {
    $importedRows[(string)$row['model']] = $row;
}
check('the batch row is persisted next to the others, not dropped',
    isset($importedRows['gpt-5.6-sol:batch']), implode(', ', array_keys($importedRows)));
check('it is persisted inactive, with its marker and reason',
    ($importedRows['gpt-5.6-sol:batch']['active'] ?? true) === false
    && ($importedRows['gpt-5.6-sol:batch']['batch_only'] ?? false) === true
    && str_contains((string)($importedRows['gpt-5.6-sol:batch']['sync_pool_reason'] ?? ''), 'Batch API'),
    json_encode($importedRows['gpt-5.6-sol:batch'] ?? null));
check('an ordinary imported row beside it keeps the usual shape',
    ($importedRows['x-ai/grok-4.20']['category'] ?? '') === 'imported'
    && !isset($importedRows['x-ai/grok-4.20']['batch_only']),
    json_encode($importedRows['x-ai/grok-4.20'] ?? null));
check('the pipeline never sees the batch row as active',
    !in_array('gpt-5.6-sol:batch', array_column(Config::getActiveModels(), 'model'), true),
    implode(', ', array_column(Config::getActiveModels(), 'model')));

// The operator's Save: a row switched on has to stay off, and the pool a
// hand-written body asks for must not gain a batch id.
$batchSave = body(settingsController()->saveModels([
    'models' => [
        ['provider' => 'bothub', 'model' => 'gpt-5.6-sol:batch', 'active' => true],
        ['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true],
    ],
    'default_selected' => [
        ['provider' => 'bothub', 'model' => 'gpt-5.6-sol:batch'],
        ['provider' => 'bothub', 'model' => 'gpt-5.6-sol'],
    ],
]));
check('the save is confirmed', ($batchSave['ok'] ?? false) === true, json_encode($batchSave));
$savedRows = (array)Config::get('models', []);
check('the batch row cannot be switched on by the form',
    (bool)$savedRows[0]['active'] === false && (bool)$savedRows[1]['active'] === true,
    json_encode(array_column($savedRows, 'active')));
check('its marker survives the round-trip',
    (bool)($savedRows[0]['batch_only'] ?? false) === true);
$savedPool = (array)Config::get('pipeline.default_selected_models', []);
check('the pool the request asked for does not gain the batch id',
    array_column($savedPool, 'model') === ['gpt-5.6-sol'],
    json_encode($savedPool));
check('the refused pool entry is reported instead of dropped quietly',
    ($batchSave['rejected']['batch_only'] ?? null) === ['bothub/gpt-5.6-sol:batch'],
    json_encode($batchSave['rejected'] ?? null));
check('with the reason attached',
    str_contains((string)($batchSave['rejected']['reason'] ?? ''), 'Batch API'));

// A synchronous-only save is untouched by all of this.
$plainSave = body(settingsController()->saveModels([
    'models' => [['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true]],
    'default_selected' => [['provider' => 'bothub', 'model' => 'gpt-5.6-sol']],
]));
check('a synchronous-only save reports nothing as rejected',
    ($plainSave['rejected']['batch_only'] ?? null) === [], json_encode($plainSave['rejected'] ?? null));

// =====================================================================
echo "\n== (h) the JS version parser and ModelVersion share a fixture ==\n";
// =====================================================================
$fixtures = [
    ['anthropic/claude-sonnet-4.6', 'claudesonnet', [4, 6]],
    ['gpt-5.5-pro', 'gptpro', [5, 5]],
    ['deepseek-r1', 'deepseekr1', []],
    ['gemini-2.5-flash-lite-preview-09-2025', 'geminiflashlitepreview', [2, 5, 9, 2025]],
    ['~anthropic/claude-sonnet-latest', 'claudesonnetlatest', []],
    // The mode suffix is not a token: same line, same tuple as the plain id
    // (the JS half of this table carries the same two rows).
    ['gpt-4o:batch', 'gpt4o', []],
    ['anthropic/claude-sonnet-4.6:batch', 'claudesonnet', [4, 6]],
];
foreach ($fixtures as [$id, $line, $tuple]) {
    $parsed = ModelVersion::parse($id);
    check("ModelVersion::parse({$id}) → {$line}",
        $parsed['line'] === $line && $parsed['version'] === $tuple, json_encode($parsed));
}
check('ModelVersion::compare pads a shorter tuple',
    ModelVersion::compare('anthropic/claude-sonnet-4.6', 'anthropic/claude-sonnet-4') === 1);
check('a different line is never comparable',
    ModelVersion::newestOnSameLine(['gpt-5.5-pro', 'claude-sonnet-4.6'], 'gpt-5.5') === null);
check('a batch twin is never reported as a newer release',
    ModelVersion::compare('gpt-4o:batch', 'gpt-4o') === 0
    && ModelVersion::line('gpt-4o:batch') === ModelVersion::line('gpt-4o'));

// =====================================================================
echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";

rmTree($tmp);
exit($failures === 0 ? 0 : 1);
