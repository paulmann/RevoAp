<?php
/**
 * ExtensionsApiTest: offline coverage for the Extensions tab's machine-API
 * layer.
 *
 * Everything the Extensions tab decides about MD.Viewer is decided from one
 * JSON answer that arrives over a network that may not exist, from a
 * third-party installer that may be older than the machine API or may not
 * speak JSON at all. This suite pins that interpretation down - success,
 * unauthorized, non-JSON, and an installer older than 3.9.0 - plus the
 * install plan, without a single socket or subprocess.
 *
 * A small set of static wiring checks follows, because a tab flag declared
 * in only one of config.php's two blocks (or a missing route) is the kind of
 * mistake that leaves the tab dead while every unit below stays green.
 *
 * Runs without PHPUnit, database or network access:
 *   php tests/ExtensionsApiTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-26
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Extensions\MdViewerApi;
use App\Extensions\MdViewerIni;
use App\Extensions\MdViewerInstaller;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------
// Fixtures - the real shapes the installer's machine API answers with.
// ---------------------------------------------------------------------

$updaterHeader = <<<'PHP'
<?php
/**
 * Markdown Viewer - Self-Updater
 * Version: 3.9.0
 * Author: Mikhail Deynekin
 */
PHP;

$oldUpdaterHeader = <<<'PHP'
<?php
/**
 * Markdown Viewer - older build
 * Version: 3.8.2
 */
PHP;

// The task's captured action=conflicts answer (2 rows shown, counts for 11).
$conflictsJson = '{"ok":true,"action":"conflicts","version":"3.9.0",'
    . '"steps":[{"step":"conflicts","status":"warn","ms":3,"detail":"11 entries: 7 ok, 3 exists, 1 same_name_elsewhere"}],'
    . '"files":[],'
    . '"conflicts":['
    . '{"path":"js/md/md.js","status":"exists","existing_path":"js/md/md.js","size_bytes":40,"managed":false},'
    . '{"path":"css/md/settings.css","status":"same_name_elsewhere","existing_path":"assets/css/components/settings.css","size_bytes":27,"managed":false}'
    . '],'
    . '"errors":[],'
    . '"counts":{"total":11,"ok":7,"exists":3,"same_name_elsewhere":1},'
    . '"dry_run":true,"force":false}';

$installPlanJson = '{"ok":true,"action":"install","version":"3.9.0",'
    . '"dry_run":true,"force":false,'
    . '"steps":[{"step":"scan","status":"warn","ms":1,"detail":"3 entries"},{"step":"dry-run","status":"ok","ms":1,"detail":"Dry run: 0 written, 0 skipped, 0 error(s)"}],'
    . '"files":['
    . '{"path":"md.php","status":"would_write","decision":"create","conflict_status":"ok","existing_path":"","version":"","from_version":"","error":null},'
    . '{"path":"updater.php","status":"would_write","decision":"update","conflict_status":"exists","existing_path":"updater.php","version":"3.9.0","from_version":"3.9.0","error":null},'
    . '{"path":"LICENSE","status":"skipped","decision":"skipped_conflict","conflict_status":"exists","existing_path":"LICENSE","version":"","from_version":"","error":null},'
    . '{"path":"js/md/md.js","status":"error","decision":"create","conflict_status":"ok","existing_path":"","version":"","from_version":"","error":"boom"}'
    . '],'
    . '"conflicts":['
    . '{"path":"updater.php","status":"exists","existing_path":"updater.php","size_bytes":88377,"managed":true},'
    . '{"path":"LICENSE","status":"exists","existing_path":"LICENSE","size_bytes":1080,"managed":false},'
    . '{"path":"css/md/settings.css","status":"same_name_elsewhere","existing_path":"assets/css/components/settings.css","size_bytes":27,"managed":false}'
    . '],'
    . '"errors":[{"path":"js/md/md.js","error":"boom"}],'
    . '"counts":{"total":4,"ok":2,"exists":2,"same_name_elsewhere":1,"written":0,"skipped":0,"errors":1}}';

// ---------------------------------------------------------------------
echo "== version extraction (first 1000 bytes only) ==\n";
// ---------------------------------------------------------------------
check('reads the `* Version: X.Y.Z` banner', MdViewerApi::extractVersion($updaterHeader) === '3.9.0',
    var_export(MdViewerApi::extractVersion($updaterHeader), true));
check('reads an older banner', MdViewerApi::extractVersion($oldUpdaterHeader) === '3.8.2',
    var_export(MdViewerApi::extractVersion($oldUpdaterHeader), true));
check('a body without a banner has no version', MdViewerApi::extractVersion('<?php // nothing here') === null);
check('a banner past the first 1000 bytes is ignored',
    MdViewerApi::extractVersion(str_repeat("/* pad */\n", 200) . ' * Version: 9.9.9') === null);

// ---------------------------------------------------------------------
echo "\n== the machine-API floor ==\n";
// ---------------------------------------------------------------------
check('3.9.0 is supported', MdViewerApi::supportsMachineApi('3.9.0'));
check('3.9.1 is supported', MdViewerApi::supportsMachineApi('3.9.1'));
check('4.0.0 is supported', MdViewerApi::supportsMachineApi('4.0.0'));
check('3.8.9 is NOT supported', !MdViewerApi::supportsMachineApi('3.8.9'));
check('an unknown version is NOT supported', !MdViewerApi::supportsMachineApi(null) && !MdViewerApi::supportsMachineApi(''));
check('the old-installer message names the floor and the missing API',
    str_contains(MdViewerApi::errorLabel(MdViewerApi::ERR_OLD_INSTALLER), '3.9.0')
    && str_contains(MdViewerApi::errorLabel(MdViewerApi::ERR_OLD_INSTALLER), 'machine API'));

// ---------------------------------------------------------------------
echo "\n== response parsing: success, unauthorized, non-JSON, empty ==\n";
// ---------------------------------------------------------------------
$good = MdViewerApi::parseResponse($conflictsJson);
check('a conflicts answer parses as JSON', $good['format_valid'] === true && $good['error'] === null);
check('the parsed envelope keeps its ok flag', MdViewerApi::isOk((array)$good['json']));

$unauth = MdViewerApi::parseResponse('{"ok":false,"error":"unauthorized"}');
check('an unauthorized answer is still valid JSON', $unauth['format_valid'] === true);
check('an unauthorized answer is not ok', !MdViewerApi::isOk((array)$unauth['json']));
check('the unauthorized error code survives', MdViewerApi::errorOf((array)$unauth['json']) === 'unauthorized');

$html = MdViewerApi::parseResponse("<html>\n<head><title>502 Bad Gateway</title></head></html>");
check('an HTML body is flagged as a format mismatch', $html['format_valid'] === false && $html['error'] === MdViewerApi::ERR_NON_JSON);
check('a non-JSON body keeps a short excerpt', $html['excerpt'] !== '' && str_contains($html['excerpt'], '502'));

$empty = MdViewerApi::parseResponse('   ');
check('an empty body is flagged as empty', $empty['format_valid'] === false && $empty['error'] === MdViewerApi::ERR_EMPTY);

// ---------------------------------------------------------------------
echo "\n== conflict table + counts ==\n";
// ---------------------------------------------------------------------
$rows = MdViewerApi::conflictRows((array)$good['json']);
check('both conflict rows are kept', count($rows) === 2, (string)count($rows));
check('an occupied path keeps status, existing_path and size',
    $rows[0]['path'] === 'js/md/md.js' && $rows[0]['status'] === 'exists'
    && $rows[0]['existing_path'] === 'js/md/md.js' && $rows[0]['size_bytes'] === 40);
check('a same-name-elsewhere row keeps its real path',
    $rows[1]['status'] === 'same_name_elsewhere' && $rows[1]['existing_path'] === 'assets/css/components/settings.css');
check('each row carries a human status label', $rows[0]['status_label'] === 'Path occupied', $rows[0]['status_label']);

$counts = MdViewerApi::conflictCounts((array)$good['json']);
check('counts come from the payload, not the row count', $counts['total'] === 11 && $counts['ok'] === 7);
check('clashes = exists + same_name_elsewhere', $counts['clashes'] === 4, (string)$counts['clashes']);

// A payload with no counts block must still yield a truthful total.
$noCounts = MdViewerApi::conflictCounts(['conflicts' => [
    ['path' => 'a', 'status' => 'ok'],
    ['path' => 'b', 'status' => 'exists', 'managed' => true],
    ['path' => 'c', 'status' => 'same_name_elsewhere'],
]]);
check('missing counts fall back to counting the rows',
    $noCounts['total'] === 3 && $noCounts['ok'] === 1 && $noCounts['exists'] === 1 && $noCounts['same_name_elsewhere'] === 1,
    json_encode($noCounts));

// ---------------------------------------------------------------------
echo "\n== install plan ==\n";
// ---------------------------------------------------------------------
$plan = MdViewerApi::installPlan((array)MdViewerApi::parseResponse($installPlanJson)['json']);
$s = $plan['summary'];
check('create / update / skip / error are bucketed', $s['create'] === 1 && $s['update'] === 1 && $s['skip'] === 1 && $s['error'] === 1,
    json_encode($s));
check('the summary total matches the rows', $s['total'] === 4 && count($plan['rows']) === 4);
check('the installer counts are carried through for a real run',
    $s['written'] === 0 && $s['skipped'] === 0 && $s['errors'] === 1, json_encode($s));
check('a created row is labelled Create', $plan['rows'][0]['action'] === 'create' && $plan['rows'][0]['action_label'] === 'Create');
check('an updated row is labelled Update', $plan['rows'][1]['action'] === 'update' && $plan['rows'][1]['action_label'] === 'Update');
check('a conflicting row is labelled Skip', $plan['rows'][2]['action'] === 'skip' && $plan['rows'][2]['action_label'] === 'Skip');
check('a failed row is labelled Error and keeps its message',
    $plan['rows'][3]['action'] === 'error' && $plan['rows'][3]['error'] === 'boom');
check('steps are normalised', count($plan['steps']) === 2 && $plan['steps'][1]['step'] === 'dry-run');
check('a foreign file at an occupied path is a warning',
    count(array_filter($plan['warnings'], fn(string $w): bool => str_contains($w, 'not part of MD.Viewer'))) === 1,
    implode(' | ', $plan['warnings']));
check('a same-name-elsewhere file is a warning',
    count(array_filter($plan['warnings'], fn(string $w): bool => str_contains($w, 'already exists at'))) === 1,
    implode(' | ', $plan['warnings']));

// A real (non-dry) run reports created/updated statuses into the same buckets.
$realJson = '{"ok":true,"action":"install","version":"3.9.0","dry_run":false,"force":false,"steps":[],"errors":[],'
    . '"counts":{"written":1,"skipped":1,"errors":0},'
    . '"files":[{"path":"md.php","status":"created","decision":"create"},{"path":"md.js","status":"current","decision":"update"}]}';
$real = MdViewerApi::installPlan((array)MdViewerApi::parseResponse($realJson)['json']);
check('a real run buckets created as create and current as skip',
    $real['summary']['create'] === 1 && $real['summary']['skip'] === 1 && $real['summary']['update'] === 0,
    json_encode($real['summary']));

// ---------------------------------------------------------------------
echo "\n== status summary ==\n";
// ---------------------------------------------------------------------
$statusJson = '{"ok":true,"action":"status","version":"3.9.0","updater_version":"3.9.0","installed":false,'
    . '"files":[{"path":"md.php","status":"missing"}],"counts":{"tracked":11,"present":10,"missing":1},'
    . '"ini":{"api_key":"configured","api_install":true}}';
$status = MdViewerApi::statusSummary((array)MdViewerApi::parseResponse($statusJson)['json']);
check('status summary folds installed/flags/counts',
    $status['installed'] === false && $status['tracked'] === 11 && $status['missing'] === 1 && $status['api_install'] === true,
    json_encode($status));

// ---------------------------------------------------------------------
echo "\n== installer plumbing (paths, transport, .md.ini key) ==\n";
// ---------------------------------------------------------------------
$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/rv_ext_' . bin2hex(random_bytes(4));
$extDir  = $tmpRoot . '/extensions/md-viewer';
@mkdir($extDir, 0777, true);

$installer = new MdViewerInstaller($tmpRoot);
check('installer dir is rooted at the project root', $installer->dir() === $tmpRoot . '/extensions/md-viewer');
check('installer path points at updater.php', str_ends_with($installer->installerPath(), '/extensions/md-viewer/updater.php'));
check('ini path points at .md.ini next to the installer', str_ends_with($installer->iniPath(), '/extensions/md-viewer/.md.ini'));
check('no installer present before download', !$installer->installerPresent());
check('readApiKey is null without .md.ini', $installer->readApiKey(false) === null);

$transport = MdViewerInstaller::transport();
check('transport() reports a shape, not a guess',
    isset($transport['available'], $transport['kind'], $transport['reason']) && is_bool($transport['available']),
    json_encode($transport));
check('a host without HTTPS says so', !$transport['available']
    ? MdViewerApi::errorLabel(MdViewerApi::ERR_NO_TRANSPORT) !== ''
    : true);

file_put_contents($extDir . '/.md.ini', "; MD.Viewer configuration\nAPI_KEY = mdv_0123456789abcdef0123456789abcdef0123456789abcd\n");
check('readApiKey reads API_KEY out of .md.ini', $installer->readApiKey(false) === 'mdv_0123456789abcdef0123456789abcdef0123456789abcd',
    var_export($installer->readApiKey(false), true));

file_put_contents($extDir . '/.md.ini', "; API_KEY = mdv_a_commented_key_is_not_the_key\n");
check('a commented API_KEY line is not mistaken for the key', $installer->readApiKey(false) === null,
    var_export($installer->readApiKey(false), true));

// The server-side writer is the path the Extensions tab install uses: it has
// to leave BROWSE_DIR in the file, declared once, whatever was there before.
$written = $installer->writeIni(['BROWSE_DIR' => '/share/']);
check('writeIni() writes the browse folder into .md.ini',
    $written['ok'] === true
    && str_contains((string)file_get_contents($extDir . '/.md.ini'), 'BROWSE_DIR = /share/')
    && MdViewerIni::parse((string)file_get_contents($extDir . '/.md.ini'))['values']['BROWSE_DIR'] === '/share/');
check('the written file still has a usable key', $installer->readApiKey(false) !== null);
check('the settings state reports the folder from the file',
    $installer->settingsState()['values']['BROWSE_DIR'] === '/share/'
    && !in_array('BROWSE_DIR', $installer->settingsState()['missing_keys'], true));
check('a partial file written by an older release is completed with /share/',
    (static function () use ($installer, $extDir): bool {
        file_put_contents($extDir . '/.md.ini', "ALLOW_UPDATE = true\n");
        $r   = $installer->ensureIni();
        $raw = (string)file_get_contents($extDir . '/.md.ini');
        return $r['ok'] === true && str_contains($raw, 'BROWSE_DIR = /share/')
            && MdViewerIni::parse($raw)['values']['ALLOW_UPDATE'] === true
            && substr_count($raw, 'BROWSE_DIR = ') === 1;
    })());
check('a folder with a line break never reaches the file',
    (static function () use ($installer, $extDir): bool {
        $before = (string)file_get_contents($extDir . '/.md.ini');
        $installer->writeIni(['BROWSE_DIR' => "/share/\nALLOW_UPDATE = false"]);
        $after = (string)file_get_contents($extDir . '/.md.ini');
        return $after === $before && substr_count($after, 'ALLOW_UPDATE = ') === 1;
    })());

// Cleanup (best effort - the temp tree is ours).
$rm = static function (string $path) use (&$rm): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { $rm($path . '/' . $entry); }
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$rm($tmpRoot);
check('the temp tree was removed', !is_dir($tmpRoot));

// ---------------------------------------------------------------------
echo "\n== the seventh key: BROWSE_DIR (string, not a boolean) ==\n";
// ---------------------------------------------------------------------
check('the key list is the six booleans plus BROWSE_DIR',
    MdViewerIni::KEYS === [
        'DISABLE_UPLOAD', 'DISABLE_CLIPBOARD', 'DISABLE_SAVE_CLIPBOARD_TO_FILE',
        'ALLOW_UPDATE', 'ALLOW_RESTORE', 'ALLOW_CREATE_INDEX_PHP_LINK',
    ]
    && MdViewerIni::STRING_KEYS === ['BROWSE_DIR']
    && MdViewerIni::allKeys() === array_merge(MdViewerIni::KEYS, MdViewerIni::STRING_KEYS));
check('the shipped default is /share/', MdViewerIni::STRING_DEFAULTS['BROWSE_DIR'] === '/share/');
check('a silent file defaults to /share/',
    MdViewerIni::parse("ALLOW_UPDATE = true\n")['values']['BROWSE_DIR'] === '/share/');
check('a file that carries the key is read as written',
    MdViewerIni::parse("BROWSE_DIR = share\n")['values']['BROWSE_DIR'] === 'share'
    && in_array('BROWSE_DIR', MdViewerIni::parse("BROWSE_DIR = share\n")['present'], true));
check('an empty value stays empty (the folder md.php lives in)',
    MdViewerIni::parse("BROWSE_DIR = \n")['values']['BROWSE_DIR'] === ''
    && MdViewerIni::parse("BROWSE_DIR = \n")['invalid'] === []);
check('a line break is refused by the write path',
    MdViewerIni::validate(['BROWSE_DIR' => "/share/\nALLOW_UPDATE = false"])['ok'] === false
    && MdViewerIni::toPath("a\nb") === null && MdViewerIni::toPath("a\rb") === null
    && MdViewerIni::toPath("a\0b") === null);
check('255 characters are accepted, 256 are not',
    MdViewerIni::toPath(str_repeat('a', 255)) !== null && MdViewerIni::toPath(str_repeat('a', 256)) === null);
check('an unknown key is still refused',
    MdViewerIni::validate(['NOPE' => '/share/'])['ok'] === false
    && MdViewerIni::validate(['BROWSE_DIR' => '/share/', 'NOPE' => true])['ok'] === false);
check('the six booleans are unchanged by the seventh key',
    MdViewerIni::validate(['ALLOW_UPDATE' => 'true'])['values'] === ['ALLOW_UPDATE' => true]
    && MdViewerIni::validate(['ALLOW_UPDATE' => 'yes'])['ok'] === false
    && MdViewerIni::validate(['DISABLE_UPLOAD' => false])['values'] === ['DISABLE_UPLOAD' => false]);
check('a rendered file carries the folder exactly once',
    substr_count(MdViewerIni::render(MdViewerIni::defaults(), MdViewerIni::generateKey()), 'BROWSE_DIR = /share/') === 1);

// ---------------------------------------------------------------------
echo "\n== static wiring ==\n";
// ---------------------------------------------------------------------
$root    = dirname(__DIR__);
$configPhp = (string)file_get_contents($root . '/config.php');
$layout    = (string)file_get_contents($root . '/assets/templates/layout.html');
$index     = (string)file_get_contents($root . '/index.php');

check('Config exposes isExtensionsTabEnabled()', method_exists(Config::class, 'isExtensionsTabEnabled'));
check('config.php declares ExtensionsTab in both blocks', substr_count($configPhp, "'ExtensionsTab'") >= 2,
    (string)substr_count($configPhp, "'ExtensionsTab'"));
check('layout.html ships the Extensions tab button',
    str_contains($layout, 'id="oc-tab-extensions"') && str_contains($layout, 'data-tab="extensions"'));
check('the Extensions button starts hidden', (bool)preg_match('/id="oc-tab-extensions"[^>]*hidden/', $layout));
check('layout.html ships the Extensions panel', str_contains($layout, 'data-tab-panel="extensions"'));
check('layout.html loads extensions.css and extensions.js',
    str_contains($layout, 'components/extensions.css') && str_contains($layout, 'js/extensions.js'));
check('index.php wires the four extension routes',
    str_contains($index, "'/api/extensions/status'") && str_contains($index, "'/api/extensions/download'")
    && str_contains($index, "'/api/extensions/check'") && str_contains($index, "'/api/extensions/install'"));
check('index.php surfaces the extensions flag in /api/tabs',
    str_contains($index, "'extensions'") && str_contains($index, 'isExtensionsTabEnabled()'));

// The tab flag resolves with the documented precedence.
$flagRoot = rtrim(sys_get_temp_dir(), '/\\') . '/rv_ext_flag_' . bin2hex(random_bytes(4));
@mkdir($flagRoot, 0777, true);
Config::set(['ExtensionsTab' => false], $flagRoot);
check('ExtensionsTab=false disables the tab', Config::isExtensionsTabEnabled() === false);
Config::set(['app' => ['ExtensionsTab' => true]], $flagRoot);
check('app.ExtensionsTab=true enables the tab', Config::isExtensionsTabEnabled() === true);
Config::set([], $flagRoot);
check('the default is enabled', Config::isExtensionsTabEnabled() === true);
@rmdir($flagRoot);

echo "----------------------------------------\n";
echo $failures === 0
    ? "ALL OK: {$passes} passed, 0 failed\n"
    : "FAILED: {$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
