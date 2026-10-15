<?php
/**
 * ExtensionsPhpCliTest: the "Install" failure of 2026-09-26, reproduced and
 * pinned down offline.
 *
 * WHAT BROKE. `Download Installer` worked. `Install` then failed with
 *
 *     The installer has no usable API_KEY in .md.ini
 *
 * which points at the key. The actual cause, measured on the production host
 * (PHP-FPM): `MdViewerInstaller::runUpdater()` started `PHP_BINARY`, and under
 * FPM that constant is `/opt/php85/sbin/php-fpm`. The installer was therefore
 * never executed - php-fpm printed its usage and exited 64 - so no .md.ini and
 * no key were ever written, and the tab reported the missing key. On any CLI
 * host `PHP_BINARY` *is* the CLI binary, which is why every local test passed.
 *
 * WHAT THIS SUITE DOES ABOUT IT. It builds a php-fpm stand-in binary that
 * behaves exactly like the reported one (prints `Usage: php-fpm ...`, exits
 * 64), runs the PRE-FIX child invocation against it to reproduce the failure
 * (exit 64, no installer run, no .md.ini, `api_key_unavailable` - the message
 * the operator saw), and then runs the same world through the fixed code,
 * which probes its candidates and finds a real CLI interpreter instead.
 *
 * The regression is asserted where it hurts: with only the FPM stand-in
 * available the answer must be `no_php_cli` (a restart-the-thing diagnosis),
 * never `api_key_unavailable` (a wrong-key diagnosis). Pre-fix code cannot
 * produce `no_php_cli` at all, so this file fails on it.
 *
 * No network, no database, no PHPUnit:
 *   php tests/ExtensionsPhpCliTest.php
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
use App\Extensions\PhpCliLocator;
use App\Http\Controllers\ExtensionsController;

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

function rrm(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { rrm($path . '/' . $entry); }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

/**
 * A stand-in for the binary the production host has as `PHP_BINARY`:
 * it prints php-fpm's usage text and exits 64, exactly like
 * `/opt/php85/sbin/php-fpm -r 'echo PHP_SAPI;'` did.
 */
function fpmStandIn(string $dir): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $path = $dir . '/php-fpm.cmd';
        file_put_contents($path, "@echo off\r\necho Usage: php-fpm [-n] [-c inifile] [-y configfile] ...\r\nexit /b 64\r\n");
        return $path;
    }
    $path = $dir . '/php-fpm';
    file_put_contents($path, "#!/bin/sh\necho 'Usage: php-fpm [-n] [-c inifile] [-y configfile] ...'\nexit 64\n");
    @chmod($path, 0755);
    return $path;
}

/**
 * The PRE-FIX child invocation, reproduced verbatim: the same argv, the same
 * hidden `DOCUMENT_ROOT=""`, the same synthesised $_GET. Used to show what the
 * server did before the fix - and, with a CLI binary, what it did locally.
 *
 * @return array{exit:int,out:string,err:string}
 */
function legacyChildRun(string $binary, string $extDir, string $action = 'status'): array
{
    $updater = $extDir . '/updater.php';
    $code = 'chdir(' . var_export($extDir, true) . ');'
        . '$_SERVER["DOCUMENT_ROOT"]="";'
        . '$_SERVER["REQUEST_METHOD"]="GET";'
        . '$_GET=["format"=>"json","action"=>' . var_export($action, true) . ','
        . '"api_key"=>"","dry_run"=>"0","force"=>"0"];'
        . '$_POST=[];$_REQUEST=$_GET;'
        . 'require ' . var_export($updater, true) . ';';

    $pipes = [];
    $proc  = @proc_open(
        [$binary, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $extDir,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) {
        return ['exit' => -1, 'out' => '', 'err' => 'proc_open failed'];
    }
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['exit' => is_int($exit) ? $exit : -1, 'out' => $out, 'err' => $err];
}

// ---------------------------------------------------------------------
echo "== candidate selection (substituted probe, no subprocess) ==\n";
// ---------------------------------------------------------------------
$poisonPath = '/nonexistent/sbin/php-fpm';
$goodPath   = PHP_BINARY;
$probed     = [];
$locator = new PhpCliLocator([$poisonPath, $goodPath], static function (string $bin) use (&$probed): bool {
    $probed[] = $bin;
    return $bin === PHP_BINARY; // pretend only the CLI candidate answers `cli`
});
check('a candidate that does not answer cli is rejected', $locator->resolve() === PHP_BINARY);
check('both candidates were probed, in order', $probed === [$poisonPath, $goodPath], implode(' , ', $probed));
check('the accepted candidate is reported with its source',
    $locator->source() === PhpCliLocator::SOURCE_PHP_BINARY, (string)$locator->source());
check('the probe is not repeated on a second resolve', (static function () use ($locator, &$probed): bool {
    $again = $locator->resolve();
    return $again === PHP_BINARY && count($probed) === 2;
})());
check('candidates() keeps the documented order', $locator->candidates() === [$poisonPath, $goodPath]);
check('no acceptable candidate resolves to null', (new PhpCliLocator(['/a', '/b'], static fn(): bool => false))->resolve() === null);
check('duplicate candidates are collapsed', (new PhpCliLocator(['/a', '/a'], static fn(): bool => false))->candidates() === ['/a']);
check('the summary is non-secret and honest', (static function (): bool {
    $s = (new PhpCliLocator(['/nope'], static fn(): bool => false))->summary();
    return $s['available'] === false && $s['binary'] === null && str_contains((string)$s['error'], 'CLI');
})());
check('the summary of a working locator names the basename, not the path', (static function (): bool {
    $s = (new PhpCliLocator(['/opt/php/bin/php'], static fn(): bool => true))->summary();
    return $s['available'] === true && $s['binary'] === 'php' && !str_contains((string)$s['binary'], '/');
})());

// ---------------------------------------------------------------------
echo "\n== the documented candidate order ==\n";
// ---------------------------------------------------------------------
$slots = PhpCliLocator::defaultSlots('/opt/configured/php', '/opt/env/php');
$paths = array_column($slots, 'path');
$sources = array_column($slots, 'source');
check('an explicitly configured path is tried first (env, then config)',
    $paths[0] === '/opt/env/php' && $paths[1] === '/opt/configured/php', implode(',', array_slice($paths, 0, 2)));
check('the sources are labelled', $sources[0] === PhpCliLocator::SOURCE_ENV && $sources[1] === PhpCliLocator::SOURCE_CONFIG);
check('PHP_BINDIR/php comes before PHP_BINARY', (static function () use ($paths): bool {
    $bindir = strtolower(rtrim((string)PHP_BINDIR, "/\\") . '/php');
    $lower  = array_map('strtolower', $paths);
    $iBin   = array_search($bindir, $lower, true);
    $iRaw   = array_search(strtolower(PHP_BINARY), $lower, true);
    return $iBin !== false && $iRaw !== false && $iBin < $iRaw;
})());
check('the system paths are in the list',
    in_array('/usr/bin/php', $paths, true) && in_array('/usr/local/bin/php', $paths, true));
check('PHP_BINARY is the last resort', end($paths) === PHP_BINARY, (string)end($paths));
$noOverride = array_column(PhpCliLocator::defaultSlots(null, ''), 'path');
check('without an override the list starts at PHP_BINDIR/php',
    $noOverride[0] === rtrim((string)PHP_BINDIR, "/\\") . '/php', $noOverride[0]);

// ---------------------------------------------------------------------
echo "\n== the real probe ==\n";
// ---------------------------------------------------------------------
if (!function_exists('proc_open')) {
    echo "  note  proc_open() is unavailable on this host - the subprocess checks are skipped\n";
} else {
    $realCli = PhpCliLocator::defaults()->resolve();
    check('this host has a CLI interpreter the probe accepts', $realCli !== null);
    if ($realCli !== null) {
        $inspect = PhpCliLocator::defaults()->inspect($realCli);
        check('the probe reads PHP_SAPI = cli', $inspect['ok'] === true && $inspect['sapi'] === 'cli',
            json_encode(['exit' => $inspect['exit'], 'sapi' => $inspect['sapi']]));
    }
}

// ---------------------------------------------------------------------
echo "\n== the fixture: a real updater.php double in extensions/md-viewer ==\n";
// ---------------------------------------------------------------------
$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/rv_ext_cli_' . bin2hex(random_bytes(4));
$extDir  = $tmpRoot . '/extensions/md-viewer';
@mkdir($extDir, 0777, true);

$stub = <<<'PHP'
<?php
/**
 * Fixture MD.Viewer updater (test double for tests/ExtensionsPhpCliTest.php).
 * Version: 3.9.0
 * Machine API: format=json, action=status|conflicts|install
 */

$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
$source  = $docRoot !== '' ? 'document_root' : 'dirname';
$dir     = $docRoot !== '' ? $docRoot : __DIR__;
$iniPath = rtrim($dir, '/\\') . '/.md.ini';

$ini      = is_file($iniPath) ? (array)parse_ini_file($iniPath, false, INI_SCANNER_TYPED) : [];
$settings = [];
foreach ([
    'DISABLE_UPLOAD', 'DISABLE_CLIPBOARD', 'DISABLE_SAVE_CLIPBOARD_TO_FILE',
    'ALLOW_UPDATE', 'ALLOW_RESTORE', 'ALLOW_CREATE_INDEX_PHP_LINK',
] as $name) {
    $settings[strtolower($name)] = (bool)filter_var($ini[$name] ?? true, FILTER_VALIDATE_BOOLEAN);
}

$given  = isset($_GET['api_key']) ? (string)$_GET['api_key'] : '';
$stored = isset($ini['API_KEY']) ? (string)$ini['API_KEY'] : '';
$keyOk  = $stored !== '' && $given !== '' && hash_equals($stored, $given);

$keyInArgv = false;
foreach ((array)($_SERVER['argv'] ?? []) as $arg) {
    if (str_contains((string)$arg, 'mdv_')) { $keyInArgv = true; }
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
@file_put_contents(__DIR__ . '/.ran-' . $action, (string)time());

$iniBlock = [
    'path'        => '.md.ini',
    'source'      => $source,
    'key_present' => $stored !== '',
    'key_ok'      => $keyOk,
    'key_in_argv' => $keyInArgv,
    'settings'    => $settings,
    'browse_dir'  => (string)($ini['BROWSE_DIR'] ?? ''),
];

if (!$keyOk) {
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'action' => $action, 'ini' => $iniBlock]);
    return;
}

$payload = [
    'ok'         => true,
    'action'     => $action,
    'version'    => '3.9.0',
    'updater_version' => '3.9.0',
    'installed'  => true,
    'dry_run'    => (string)($_GET['dry_run'] ?? '0') === '1',
    'force'      => (string)($_GET['force'] ?? '0') === '1',
    'steps'      => [['step' => $action, 'status' => 'ok', 'ms' => 1, 'detail' => 'fixture']],
    'files'      => [],
    'conflicts'  => [],
    'errors'     => [],
    'counts'     => ['tracked' => 1, 'present' => 1, 'missing' => 0, 'written' => 0, 'skipped' => 0, 'errors' => 0],
    'ini'        => $iniBlock,
];

if ($action === 'install') {
    $payload['files'] = [[
        'path' => 'md.php', 'status' => $payload['dry_run'] ? 'would_write' : 'created',
        'decision' => 'create', 'conflict_status' => 'ok', 'existing_path' => '',
        'version' => '', 'from_version' => '', 'error' => null,
    ]];
    $payload['counts']['total'] = 1;
    $payload['counts']['ok']    = 1;
}

echo json_encode($payload);
PHP;

file_put_contents($extDir . '/updater.php', $stub);
$installer = new MdViewerInstaller($tmpRoot);
check('the fixture is recognised as an installer', $installer->installerPresent());
check('the fixture banner is read', $installer->installedVersion() === '3.9.0', (string)$installer->installedVersion());
check('the fixture clears the machine-API floor', MdViewerApi::supportsMachineApi($installer->installedVersion()));
$poison = fpmStandIn($tmpRoot);
check('the php-fpm stand-in exists', is_file($poison), $poison);

// ---------------------------------------------------------------------
echo "\n== THE DEFECT, REPRODUCED: PHP_BINARY is php-fpm, not a CLI ==\n";
// ---------------------------------------------------------------------
// The pre-fix rule, verbatim: spawn PHP_BINARY. Here PHP_BINARY is stood in
// for by the fpm binary, exactly as it was on the production host.
$legacy = legacyChildRun($poison, $extDir);
check('the old path started php-fpm instead of the installer',
    $legacy['exit'] === 64 && str_contains($legacy['out'], 'php-fpm'),
    'exit=' . $legacy['exit'] . ' out=' . substr($legacy['out'], 0, 60));
check('... so the installer never ran (no action marker)', !is_file($extDir . '/.ran-status'));
check('... and no .md.ini was created', !is_file($extDir . '/.md.ini'));
check('... and the operator was told the key was missing',
    str_contains(MdViewerApi::errorLabel(MdViewerApi::ERR_API_KEY_UNAVAILABLE), 'API_KEY'));
check('... and the misleading message is a different code from the honest one',
    MdViewerApi::ERR_API_KEY_UNAVAILABLE !== MdViewerApi::ERR_NO_PHP_CLI);

// The same pre-fix rule on a host where PHP_BINARY *is* the CLI binary - i.e.
// every developer machine, which is why the local suite stayed green.
$legacyLocal = legacyChildRun(PHP_BINARY, $extDir);
$legacyJson  = json_decode($legacyLocal['out'], true);
check('the old path works on a CLI host (the local blind spot)',
    $legacyLocal['exit'] === 0 && is_array($legacyJson),
    'exit=' . $legacyLocal['exit'] . ' out=' . substr($legacyLocal['out'], 0, 80));
check('the old path answered unauthorized there (no key yet)',
    ($legacyJson['error'] ?? '') === 'unauthorized');
check('the old child resolved .md.ini through dirname() because DOCUMENT_ROOT was empty',
    ($legacyJson['ini']['source'] ?? '') === 'dirname', (string)($legacyJson['ini']['source'] ?? ''));
@unlink($extDir . '/.ran-status');

// ---------------------------------------------------------------------
echo "\n== THE FIX: the same world, resolved by probe ==\n";
// ---------------------------------------------------------------------
if (!function_exists('proc_open')) {
    echo "  note  proc_open() is unavailable - the end-to-end run cannot be exercised here\n";
} else {
    // Our own .md.ini, written before the installer is asked anything.
    $written = $installer->writeIni(['ALLOW_UPDATE' => true, 'DISABLE_UPLOAD' => true, 'ALLOW_RESTORE' => false]);
    check('the server wrote .md.ini before the call', $written['ok'] === true && is_file($extDir . '/.md.ini'));
    $ourKey = (string)(MdViewerIni::extractKey((string)file_get_contents($extDir . '/.md.ini')) ?? '');
    check('the written key has the documented shape', MdViewerIni::looksGenerated($ourKey));

    // The fixed locator, with the FPM stand-in first - the same order the
    // production host had, except that PHP_BINDIR/php is in the list too.
    $slots   = PhpCliLocator::defaultSlots(null, null);
    array_unshift($slots, ['path' => $poison, 'source' => PhpCliLocator::SOURCE_PHP_BINARY]);
    $locator2 = new PhpCliLocator($slots, null); // REAL probe, no substitution
    $resolved = $locator2->resolve();
    check('the real probe rejects the php-fpm stand-in and finds a CLI',
        $resolved !== null && $resolved !== $poison, (string)$resolved === '' ? 'nothing accepted' : '');
    check('the stand-in is the first candidate, and it was tried', $slots[0]['path'] === $poison);
    check('the fixed installer exposes the probed binary', method_exists(MdViewerInstaller::class, 'phpCliBinary'));
    check('no interpreter means null, not a guess', (new PhpCliLocator([$poison]))->resolve() === null);

    $fixed = new MdViewerInstaller($tmpRoot, $locator2);
    $call  = $fixed->callApi('status', [], 'GET', 25);
    check('the fixed path runs the installer and gets a machine-API answer',
        $call['ok'] === true && is_array($call['json']), json_encode($call['error']));
    check('the installer really executed (action marker)', is_file($extDir . '/.ran-status'));
    check('the child found .md.ini through the pinned DOCUMENT_ROOT',
        ($call['json']['ini']['source'] ?? '') === 'document_root', (string)($call['json']['ini']['source'] ?? ''));
    check('the child read the key the server wrote', ($call['json']['ini']['key_ok'] ?? false) === true);
    check('the child read ALLOW_UPDATE = true out of .md.ini',
        ($call['json']['ini']['settings']['allow_update'] ?? null) === true);
    check('the child read BROWSE_DIR = /share/ out of .md.ini',
        ($call['json']['ini']['browse_dir'] ?? '') === '/share/',
        (string)($call['json']['ini']['browse_dir'] ?? ''));
    check('the key is NOT on the child command line', ($call['json']['ini']['key_in_argv'] ?? true) === false);
    check('the call result carries no key material', !str_contains(json_encode($call), $ourKey));
    check('the child resolved .md.ini through DOCUMENT_ROOT, not dirname()',
        ($call['json']['ini']['source'] ?? '') !== 'dirname');

    // -----------------------------------------------------------------
    echo "\n== no CLI at all: the honest error, not the misleading one ==\n";
    // -----------------------------------------------------------------
    @unlink($extDir . '/.ran-status');
    $noCli        = new MdViewerInstaller($tmpRoot, new PhpCliLocator([$poison]));
    $noCliCall    = $noCli->callApi('status', [], 'GET', 25);
    check('the error is no_php_cli', ($noCliCall['error'] ?? '') === MdViewerApi::ERR_NO_PHP_CLI,
        (string)($noCliCall['error'] ?? ''));
    check('the error is NOT api_key_unavailable', ($noCliCall['error'] ?? '') !== MdViewerApi::ERR_API_KEY_UNAVAILABLE);
    check('the message names the interpreter, not the key',
        str_contains(MdViewerApi::errorLabel((string)$noCliCall['error']), 'CLI')
        && !str_contains(MdViewerApi::errorLabel((string)$noCliCall['error']), 'API_KEY'));
    check('nothing was executed for a missing interpreter', !is_file($extDir . '/.ran-status'));
    check('a subprocess was never started (exit -1, no output)',
        $noCliCall['exit'] === -1 && $noCliCall['bytes'] === 0, 'exit=' . $noCliCall['exit']);

    // -----------------------------------------------------------------
    echo "\n== install applies the SELECTED values before action=install ==\n";
    // -----------------------------------------------------------------
    Config::set(['Protect_Settings_by_Admin_Key' => 0], $tmpRoot);
    $controller = new ExtensionsController($fixed);
    $plan = json_decode($controller->install([
        'dry_run'  => true,
        'settings' => [
            'ALLOW_UPDATE'      => 'true',
            'DISABLE_UPLOAD'    => 'false',
            'ALLOW_RESTORE'     => 'true',
            'DISABLE_CLIPBOARD' => 'true',
            'BROWSE_DIR'        => 'share/',
        ],
    ]), true);
    $afterPlan = MdViewerIni::parse((string)file_get_contents($extDir . '/.md.ini'));
    check('install() returns the plan', ($plan['ok'] ?? false) === true, json_encode($plan['error'] ?? null));
    check('install() wrote the selected values first',
        $afterPlan['values']['ALLOW_UPDATE'] === true
        && $afterPlan['values']['DISABLE_UPLOAD'] === false
        && $afterPlan['values']['ALLOW_RESTORE'] === true
        && $afterPlan['values']['DISABLE_CLIPBOARD'] === true, json_encode($afterPlan['values']));
    check('values the menu did not send keep their defaults',
        $afterPlan['values']['DISABLE_SAVE_CLIPBOARD_TO_FILE'] === true
        && $afterPlan['values']['ALLOW_CREATE_INDEX_PHP_LINK'] === false);
    check('install() kept the existing key',
        MdViewerIni::extractKey((string)file_get_contents($extDir . '/.md.ini')) === $ourKey);
    check('the plan response carries no key material',
        !str_contains((string)json_encode($plan), $ourKey));
    check('the install() response reports the .md.ini state',
        isset($plan['ini']['present']) && ($plan['ini']['key_present'] ?? false) === true);
    check('install() applied the selected folder',
        $afterPlan['values']['BROWSE_DIR'] === 'share/', json_encode($afterPlan['values']));

    // The child is the one that has to see it: MD.Viewer resolves BROWSE_DIR
    // through parse_ini_file, so read it back exactly the way it will.
    $afterInstall = $fixed->callApi('status', [], 'GET', 25);
    check('the child then reads the selected folder out of .md.ini',
        ($afterInstall['json']['ini']['browse_dir'] ?? '') === 'share/',
        (string)($afterInstall['json']['ini']['browse_dir'] ?? ''));

    $refused = json_decode($controller->install(['dry_run' => true, 'settings' => ['ALLOW_UPDATE' => 'yes']]), true);
    check('install() refuses a non-boolean setting',
        ($refused['error'] ?? '') === 'invalid_settings' && str_contains((string)($refused['message'] ?? ''), 'ALLOW_UPDATE'));
    check('a refused install changed nothing',
        MdViewerIni::parse((string)file_get_contents($extDir . '/.md.ini'))['values'] === $afterPlan['values']);

    $unknown = json_decode($controller->install(['dry_run' => true, 'settings' => ['NOPE' => true]]), true);
    check('install() refuses an unknown setting', ($unknown['error'] ?? '') === 'invalid_settings');
}

// ---------------------------------------------------------------------
echo "\n== the CLI details the fix depends on are documented in the source ==\n";
// ---------------------------------------------------------------------
$installerSrc = (string)file_get_contents(dirname(__DIR__) . '/src/Extensions/MdViewerInstaller.php');
check('the child sets DOCUMENT_ROOT to the extension directory',
    str_contains($installerSrc, '\'$_SERVER["DOCUMENT_ROOT"]=\' . var_export($dir, true)'));
check('the child does not leave DOCUMENT_ROOT empty any more', !str_contains($installerSrc, '$_SERVER["DOCUMENT_ROOT"]="";'));
check('runUpdater spawns the resolved binary, not PHP_BINARY',
    !str_contains($installerSrc, "[PHP_BINARY, '-d'")
    && str_contains($installerSrc, "[\$binary, '-d', 'display_errors=0'"));
check('the no-CLI case is answered before the key is looked for', (static function () use ($installerSrc): bool {
    $posCli = strpos($installerSrc, 'ERR_NO_PHP_CLI');
    $posKey = strpos($installerSrc, 'ERR_API_KEY_UNAVAILABLE');
    return $posCli !== false && $posKey !== false && $posCli < $posKey;
})());
check('the locator explains the FPM symptom', str_contains(
    (string)file_get_contents(dirname(__DIR__) . '/src/Extensions/PhpCliLocator.php'), 'php-fpm'));

rrm($tmpRoot);
check('the temp tree was removed', !is_dir($tmpRoot));

echo "----------------------------------------\n";
echo $failures === 0
    ? "ALL OK: {$passes} passed, 0 failed\n"
    : "FAILED: {$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
