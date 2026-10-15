<?php
/**
 * MdScribeTest: the MD.Viewer → MD.Scribe migration (v2.1.0, T4).
 *
 * Runs without PHPUnit, MySQL or network access:
 * `php tests/MdScribeTest.php` — the release archive is a locally built zip
 * fed through the installer's test seam, so the WHOLE install path runs
 * offline: fetch → sha256 verify → extract → shape-validate → sample-key
 * destruction → .md.ini → machine API through the v2-compatible shim.
 *
 * What is covered, and the failure each case exists to prevent
 * -----------------------------------------------------------
 *  - Product selection: a fresh install is MD.Scribe in extensions/md-scribe;
 *    the mere EXISTENCE of extensions/md-viewer keeps a legacy install on
 *    the legacy path byte-for-byte (backward compatibility is the promise).
 *  - The pinned release digest is ENFORCED: a wrong sha256 writes nothing
 *    into the install tree (verify-before-extract, not extract-and-hope).
 *  - The extracted shape is validated (md.php shim, updater.php shim,
 *    src/bootstrap.php, src/Core/Version.php): a truncated or foreign
 *    archive is refused rather than half-installed.
 *  - The PUBLIC sample API_KEY inside the release .md.ini is destroyed on
 *    extraction — keeping it would authorise the machine API with a
 *    credential everyone can download.
 *  - Version reads: Scribe from src/Core/Version.php (its shims carry no
 *    Version: banner), per-product machine-API floors (Scribe 3.1.0 must NOT
 *    fail the viewer-era >= 3.9.0 check).
 *  - .md.ini surgery on Scribe: RevoAp's seven keys round-trip while
 *    Scribe-managed keys (ADMIN_PASSWORD_HASH …) survive every write.
 *  - The machine API through the shim: one subprocess, JSON envelope, key
 *    via environment only, and a real mismatch surfaces as `unauthorized`.
 *  - installedViewerDir() preference: Scribe wins over legacy when both
 *    exist; DocumentShareController links follow the installed product.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Extensions\MdViewerApi;
use App\Extensions\MdViewerIni;
use App\Extensions\MdViewerInstaller;
use App\Extensions\PhpCliLocator;
use App\Http\Controllers\DocumentShareController;

$passes = 0;
$failures = 0;
$report = [];

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

if (!class_exists(ZipArchive::class)) {
    echo "MdScribeTest: SKIP — ext-zip is required to build the fixture archive\n";
    echo "MdScribeTest: 0 passed, 0 failed\n";
    exit(0);
}

/** The updater stub baked into the fixture archive. */
function scribeUpdaterStub(): string
{
    return <<<'PHP'
<?php
/**
 * Fixture MD.Scribe updater shim (test double for tests/MdScribeTest.php).
 * Emulates the v2-compatible machine API: reads $_GET, answers the envelope.
 */
declare(strict_types=1);
$action = (string)($_GET['action'] ?? '');
$format = (string)($_GET['format'] ?? '');
$key    = (string)($_GET['api_key'] ?? '');
if ($format !== 'json' || $action === '') {
    echo 'landing page';
    exit;
}
$ini = @parse_ini_file(__DIR__ . '/.md.ini') ?: [];
// MDV_EXPECT_KEY lets the test force a mismatch: RevoAp passes its whole
// environment to the child, so the stub can expect a key OTHER than the one
// in the ini (and therefore other than the one presented).
$expected = (string)(getenv('MDV_EXPECT_KEY') ?: ($ini['API_KEY'] ?? ''));
if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'action' => $action, 'error' => 'unauthorized']);
    exit;
}
echo json_encode([
    'ok' => true, 'action' => $action, 'version' => '9.9.9',
    'files' => [['path' => 'md.php', 'status' => 'present']],
    'steps' => [], 'conflicts' => [], 'errors' => [],
]);
exit;
PHP;
}

/** Build a minimal-but-faithful MD.Scribe release archive. */
function makeReleaseZip(string $zipPath, string $version = '9.9.9', bool $withSampleIni = true): void
{
    $stage = dirname($zipPath) . '/stage-' . bin2hex(random_bytes(4));
    @mkdir($stage . '/src/Core', 0777, true);
    @mkdir($stage . '/config', 0777, true);
    file_put_contents($stage . '/md.php', "<?php\n/**\n * MD.Viewer 3.0 — compatibility shim (v2 → v3).\n */\necho 'shim';\n");
    file_put_contents($stage . '/updater.php', scribeUpdaterStub());
    file_put_contents($stage . '/src/bootstrap.php', "<?php\n// fixture engine bootstrap\n");
    file_put_contents(
        $stage . '/src/Core/Version.php',
        "<?php\nnamespace MdViewer\\Core;\nfinal class Version {\n    public const string VERSION = '{$version}';\n}\n"
    );
    file_put_contents($stage . '/config/defaults.php', "<?php\nreturn [];\n");
    if ($withSampleIni) {
        // Single-quoted on purpose: a bcrypt hash is full of dollar signs
        // and a double-quoted string would interpolate them into nothing.
        $sample = '; MD.Scribe sample' . "\n"
            . 'SITE_NAME = MD.Scribe' . "\n"
            . 'ADMIN_PASSWORD_HASH = ' . "'\$2y\$10\$keepme'" . "\n"
            . 'API_KEY = ' . MdViewerApi::SCRIBE_SAMPLE_API_KEY . "\n"
            . 'ALLOW_UPDATE = false' . "\n";
        file_put_contents($stage . '/.md.ini', $sample);
    }
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($stage) + 1));
        }
    }
    $zip->close();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($stage);
}

function tmpRoot(string $tag): string
{
    $root = rtrim(sys_get_temp_dir(), '/\\') . '/rv_scribe_' . $tag . '_' . bin2hex(random_bytes(4));
    @mkdir($root . '/share', 0777, true);
    return $root;
}

/** Locator pinned to THIS interpreter (8.5 in the suite): no PATH roulette. */
function pinnedLocator(): PhpCliLocator
{
    return new PhpCliLocator([PHP_BINARY], static fn(string $bin): bool => is_file($bin));
}

/* ====================================================================== */

section('product selection: existence, not preference');

$root = tmpRoot('fresh');
$inst = new MdViewerInstaller($root, pinnedLocator());
check('a fresh install is MD.Scribe', $inst->product() === MdViewerInstaller::PRODUCT_SCRIBE);
check('scribe lives in extensions/md-scribe', str_ends_with(str_replace('\\', '/', $inst->dir()), '/extensions/md-scribe'), $inst->dir());
check('legacy tree is reported absent', ($inst->state()['legacy']['present'] ?? true) === false);

$rootL = tmpRoot('legacy');
@mkdir($rootL . '/extensions/md-viewer', 0777, true);
$instL = new MdViewerInstaller($rootL, pinnedLocator());
check('the EXISTENCE of extensions/md-viewer keeps the legacy product', $instL->product() === MdViewerInstaller::PRODUCT_VIEWER);
check('legacy paths are untouched by the migration', str_ends_with(str_replace('\\', '/', $instL->dir()), '/extensions/md-viewer')
    && str_ends_with(str_replace('\\', '/', $instL->iniPath()), '/extensions/md-viewer/.md.ini'));
check('legacy state() reports the scribe tree separately', array_key_exists('scribe', $instL->state()));

section('pinned release facts');

check('release tag and asset agree', str_contains(MdViewerApi::SCRIBE_RELEASE_URL, MdViewerApi::SCRIBE_RELEASE_TAG)
    && str_contains(MdViewerApi::SCRIBE_RELEASE_URL, MdViewerApi::SCRIBE_RELEASE_ASSET));
check('the digest pin is a sha256 hex string', preg_match('/^[0-9a-f]{64}$/', MdViewerApi::SCRIBE_RELEASE_SHA256) === 1);
check('the machine-API floor is per product', MdViewerApi::minVersionFor('md-scribe') === '3.0.0'
    && MdViewerApi::minVersionFor('md-viewer') === '3.9.0');
check('Scribe 3.1.0 passes its own floor', MdViewerApi::supportsMachineApiFor('md-scribe', '3.1.0'));
check('Scribe 3.1.0 would FAIL the viewer floor — why the split exists', !MdViewerApi::supportsMachineApi('3.1.0'));
check('viewer 3.14 passes the viewer floor', MdViewerApi::supportsMachineApiFor('md-viewer', '3.14.0'));
check('the sample key is an mdv_ key (recognized shape, public value)', str_starts_with(MdViewerApi::SCRIBE_SAMPLE_API_KEY, 'mdv_'));

section('extractRelease: shape validation, sample-key destruction, backups');

$tmp = tmpRoot('extract');
$zipPath = $tmp . '/release.zip';
makeReleaseZip($zipPath, '9.9.9');
$target = $tmp . '/extensions/md-scribe';

$bogus = MdViewerInstaller::extractRelease($tmp . '/missing.zip', $target);
check('a missing archive is refused', ($bogus['ok'] ?? true) === false && ($bogus['error'] ?? '') === MdViewerApi::ERR_EXTRACT);

$res = MdViewerInstaller::extractRelease($zipPath, $target);
check('the faithful archive extracts', ($res['ok'] ?? false) === true, json_encode($res));
check('the version was read from src/Core/Version.php', ($res['version'] ?? '') === '9.9.9');
check('shims and engine landed', is_file($target . '/md.php') && is_file($target . '/updater.php')
    && is_file($target . '/src/bootstrap.php') && is_file($target . '/src/Core/Version.php'));
check('the bundled sample .md.ini was DESTROYED (public api key)', !is_file($target . '/.md.ini'));

$again = MdViewerInstaller::extractRelease($zipPath, $target);
check('extracting over an install is refused without force', ($again['ok'] ?? true) === false && ($again['error'] ?? '') === MdViewerApi::ERR_ALREADY_INSTALLED);
$forced = MdViewerInstaller::extractRelease($zipPath, $target, true);
$backups = glob($target . '.bak-*') ?: [];
check('force keeps the old tree as a timestamped backup, never deletes it', ($forced['ok'] ?? false) === true && count($backups) === 1, json_encode($backups));

// A foreign archive (missing the engine) must be refused whole.
$tmp2 = tmpRoot('shape');
$badZip = $tmp2 . '/bad.zip';
$z = new ZipArchive();
$z->open($badZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('md.php', '<?php // not scribe');
$z->addFromString('readme.txt', 'x');
$z->close();
$bad = MdViewerInstaller::extractRelease($badZip, $tmp2 . '/extensions/md-scribe');
check('an archive without src/bootstrap.php + Version.php is refused', ($bad['ok'] ?? true) === false && ($bad['error'] ?? '') === MdViewerApi::ERR_EXTRACT, json_encode($bad));
check('a refused extraction leaves no install tree behind', !is_dir($tmp2 . '/extensions/md-scribe'));

section('download(): verify-before-extract, end to end');

$root3 = tmpRoot('download');
$zip3 = $root3 . '/MD.Scribe-fixture.zip';
makeReleaseZip($zip3, '9.9.9');
$goodSha = (string)hash_file('sha256', $zip3);

$badInst = new MdViewerInstaller($root3, pinnedLocator(), $zip3, str_repeat('0', 64));
$badDl = $badInst->download();
check('a wrong pin refuses the archive', ($badDl['ok'] ?? true) === false && ($badDl['error'] ?? '') === MdViewerApi::ERR_CHECKSUM, json_encode($badDl['error'] ?? null));
check('nothing was installed by the refused download', !$badInst->instancePresent() && !is_file($root3 . '/extensions/md-scribe/md.php'));
check('the refusal reports both digests', str_contains((string)$badDl['message'], str_repeat('0', 64)) && str_contains((string)$badDl['message'], $goodSha));

$goodInst = new MdViewerInstaller($root3, pinnedLocator(), $zip3, $goodSha);
$dl = $goodInst->download();
check('the verified archive installs', ($dl['ok'] ?? false) === true, json_encode($dl['error'] ?? $dl['message'] ?? null));
check('download reports the version and api support', ($dl['version'] ?? '') === '9.9.9' && ($dl['api_supported'] ?? false) === true);
check('instance + installer presence follow the extraction', $goodInst->instancePresent() && $goodInst->installerPresent());
check('installedVersion() reads Scribe\'s Version.php, not a missing banner', $goodInst->installedVersion() === '9.9.9');
$state = $goodInst->state();
check('state() says which product it is talking about', ($state['product'] ?? '') === 'md-scribe' && ($state['directory'] ?? '') === 'extensions/md-scribe');
check('state() carries the release facts for the panel', ($state['release']['tag'] ?? '') === MdViewerApi::SCRIBE_RELEASE_TAG
    && ($state['release']['sha256'] ?? '') === MdViewerApi::SCRIBE_RELEASE_SHA256
    && array_key_exists('zip_supported', $state['release']));
check('a lone shim without its engine is NOT installerPresent', (function (): bool {
    $root = tmpRoot('loneshim');
    @mkdir($root . '/extensions/md-scribe', 0777, true);
    file_put_contents($root . '/extensions/md-scribe/updater.php', '<?php // shim only');
    return (new MdViewerInstaller($root))->installerPresent() === false;
})());

section('.md.ini: fresh write, sample-key rejection, surgical merge');

$iniFresh = $goodInst->writeIni();
check('a fresh ini is written with a GENERATED key', ($iniFresh['ok'] ?? false) === true && ($iniFresh['key_present'] ?? false) === true);
$key1 = $goodInst->readApiKey(false);
check('the key is not the public sample key', is_string($key1) && $key1 !== '' && $key1 !== MdViewerApi::SCRIBE_SAMPLE_API_KEY);

// An operator (or a restore) puts the sample key back: it must read as ABSENT.
file_put_contents($goodInst->iniPath(), 'API_KEY = ' . MdViewerApi::SCRIBE_SAMPLE_API_KEY . "\n");
check('the sample key reads as no key at all', $goodInst->readApiKey(false) === null);
$rotated = $goodInst->readApiKey(true);
check('bootstrap rotates the sample key away', is_string($rotated) && $rotated !== MdViewerApi::SCRIBE_SAMPLE_API_KEY && $rotated !== '');

// Scribe-managed keys must survive a RevoAp write. The hash is built by
// concatenation: inside a double-quoted string its dollar signs would be
// interpolated away — the exact class of bug this fixture must not have.
$hash = '$2y$10$' . 'keepmeplease';
$scribeIni = "; MD.Scribe 3.1.0 — server-side configuration\nSITE_NAME = MD.Scribe\nADMIN_PASSWORD_HASH = '" . $hash . "'\n"
    . "DISABLE_UPLOAD = false\nBROWSE_DIR = /docs/\nAPI_KEY = " . (string)$rotated . "\nCACHE_TTL = 60\n";
file_put_contents($goodInst->iniPath(), $scribeIni);
$write = $goodInst->writeIni(['DISABLE_UPLOAD' => true, 'BROWSE_DIR' => '/share/']);
check('the merge write succeeds', ($write['ok'] ?? false) === true, json_encode($write['error'] ?? null));
$after = (string)file_get_contents($goodInst->iniPath());
check('ADMIN_PASSWORD_HASH survived the write (no admin lockout)', str_contains($after, 'ADMIN_PASSWORD_HASH') && str_contains($after, 'keepmeplease'));
check('SITE_NAME/CACHE_TTL survived too', str_contains($after, 'SITE_NAME = MD.Scribe') && str_contains($after, 'CACHE_TTL = 60'));
check('the key was NOT rotated by a settings write', $goodInst->readApiKey(false) === $rotated);
$parsed = MdViewerIni::parse($after);
check('our seven keys read back as written', ($parsed['values']['DISABLE_UPLOAD'] ?? null) === true && ($parsed['values']['BROWSE_DIR'] ?? '') === '/share/');
check('scribe-managed keys are recognized, not "unknown"', $parsed['unknown'] === [] && in_array('ADMIN_PASSWORD_HASH', $parsed['scribe_managed'], true), json_encode($parsed['unknown']));
$write2 = $goodInst->writeIni(['DISABLE_UPLOAD' => true, 'BROWSE_DIR' => '/share/']);
check('the merge is idempotent (second identical write changes nothing)', ($write2['changed'] ?? true) === false, json_encode($write2));

section('machine API through the v2-compatible shim (one subprocess)');

$call = $goodInst->callApi('status');
check('the shim answers the status envelope', ($call['ok'] ?? false) === true && ($call['api_ok'] ?? false) === true, json_encode(['error' => $call['error'] ?? null, 'excerpt' => $call['excerpt'] ?? '', 'cmd' => $call['command_error'] ?? null]));
check('the envelope version came through', ((string)($call['json']['version'] ?? '')) === '9.9.9');

// Force a real mismatch: the stub expects another key than the presented
// one, exactly like a Scribe install whose key was rotated behind RevoAp's
// back. The refusal must surface as the API's own 'unauthorized' error code,
// not as unparsed junk.
putenv('MDV_EXPECT_KEY=mdv_' . str_repeat('f', 48));
$unauth = $goodInst->callApi('status');
putenv('MDV_EXPECT_KEY');
check('a wrong key is the API\'s unauthorized, cleanly parsed', ($unauth['ok'] ?? true) === false && ($unauth['error'] ?? '') === 'unauthorized', json_encode($unauth['error'] ?? null));

section('share links follow the installed product');

check('installedViewerDir prefers Scribe when both trees exist', (function (): bool {
    $root = tmpRoot('both');
    @mkdir($root . '/extensions/md-viewer', 0777, true);
    @mkdir($root . '/extensions/md-scribe', 0777, true);
    file_put_contents($root . '/extensions/md-viewer/md.php', '<?php');
    file_put_contents($root . '/extensions/md-scribe/md.php', '<?php');
    return (new MdViewerInstaller($root))->installedViewerDir() === MdViewerInstaller::SCRIBE_DIR;
})());
check('legacy-only installs keep the legacy share path', (function (): bool {
    $root = tmpRoot('legacyonly');
    @mkdir($root . '/extensions/md-viewer', 0777, true);
    file_put_contents($root . '/extensions/md-viewer/md.php', '<?php');
    return (new MdViewerInstaller($root))->installedViewerDir() === MdViewerInstaller::DIR;
})());
check('no viewer → null (the plain file link wins)', (new MdViewerInstaller(tmpRoot('none')))->installedViewerDir() === null);

Config::set(['app' => ['url' => 'https://example.test', 'debug' => false, 'version' => '2.1.0', 'name' => 'RevoAp']], __DIR__);
$shareRoot = tmpRoot('share');
@mkdir($shareRoot . '/extensions/md-scribe', 0777, true);
file_put_contents($shareRoot . '/extensions/md-scribe/md.php', '<?php // scribe shim');
$docCtrl = new DocumentShareController($shareRoot, new MdViewerInstaller($shareRoot, pinnedLocator()));
$pub = $docCtrl->publish('Scribe share test', "# Hello\n\nWorld.");
check('publishing succeeds against a scribe install', ($pub['ok'] ?? false) === true, json_encode($pub['error'] ?? null));
check('the viewer link points into extensions/md-scribe', str_contains((string)($pub['viewer_url'] ?? ''), '/extensions/md-scribe/md.php?file='), (string)($pub['viewer_url'] ?? ''));
check('the copy-worthy url IS the viewer url when installed', ($pub['url'] ?? '') === ($pub['viewer_url'] ?? ''));

$legacyShareRoot = tmpRoot('sharelegacy');
@mkdir($legacyShareRoot . '/extensions/md-viewer', 0777, true);
file_put_contents($legacyShareRoot . '/extensions/md-viewer/md.php', '<?php // legacy viewer');
$docCtrlLegacy = new DocumentShareController($legacyShareRoot, new MdViewerInstaller($legacyShareRoot, pinnedLocator()));
$pubLegacy = $docCtrlLegacy->publish('Legacy share test', "# Hello\n\nWorld.");
check('a legacy install keeps its historical share URL', str_contains((string)($pubLegacy['viewer_url'] ?? ''), '/extensions/md-viewer/md.php?file='), (string)($pubLegacy['viewer_url'] ?? ''));

/* ====================================================================== */

echo implode("\n", $report), "\n";
echo "\n==================================\n";
echo "MdScribeTest: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
