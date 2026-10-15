<?php
/**
 * ExtensionsSettingsTest: offline coverage for the `.md.ini` half of the
 * Extensions tab.
 *
 * The operator's report was: "Download Installer works, Install fails with
 * `The installer has no usable API_KEY in .md.ini`". Two things were wrong
 * behind that message and both are pinned down here, without a socket, a
 * subprocess, a database or a browser:
 *
 *   1. THE FILE. The install relied on a first browser visit to create
 *      .md.ini. It is now written by the server, and this suite checks the
 *      file byte-for-byte against the shape the customer specified - every
 *      one of the six keys present (an omitted key makes MD.Viewer's own
 *      readIni() apply ITS defaults, which are not ours), atomically, and
 *      with the machine API key generated once and then preserved.
 *   2. THE KEY. It is a secret that authorises file installs. These checks
 *      read the file with the same parser the installer uses, prove the key
 *      survives every write except an explicit rotation, and scan every
 *      endpoint payload for key material - the UI may only ever learn
 *      "set / not set" plus a mask.
 *
 * Runs without PHPUnit, database or network access:
 *   php tests/ExtensionsSettingsTest.php
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

/** Read .md.ini as the installer would: values + key. */
function readIniFile(string $path): array
{
    if (!is_file($path)) {
        return ['raw' => '', 'parsed' => MdViewerIni::parse(''), 'key' => null];
    }
    $raw = (string)file_get_contents($path);
    return ['raw' => $raw, 'parsed' => MdViewerIni::parse($raw), 'key' => MdViewerIni::extractKey($raw)];
}

// ---------------------------------------------------------------------
echo "== the file the customer asked for, byte for byte ==\n";
// ---------------------------------------------------------------------
$fixtureKey = MdViewerIni::KEY_PREFIX . str_repeat('0', 48);
$expectedLines = [
    '; MD.Viewer server-side configuration',
    '; Edit this file to hard-override settings.',
    '; Changes here cannot be undone from the Settings panel.',
    '',
    '; Disable the "Upload .md" button in the file browser (recommended: true)',
    'DISABLE_UPLOAD = true',
    '',
    '; Disable the "Preview Clipboard" button in the file browser',
    'DISABLE_CLIPBOARD = false',
    '',
    '; Disable the "Save to File" button in clipboard preview (saved into the folder',
        '; set by BROWSE_DIR, otherwise uploads.md/)',
    'DISABLE_SAVE_CLIPBOARD_TO_FILE = true',
    '',
    'ALLOW_UPDATE = true',
    '; Allow restoring a backup via updater.php?restore=latest or ?restore=[version]',
    'ALLOW_RESTORE = false',
    '',
    '; Allow creating/removing the index.php hard link from the Settings panel',
    'ALLOW_CREATE_INDEX_PHP_LINK = false',
    '',
    '; Folder whose documents the viewer lists and opens. A relative value',
    '; is resolved against DOCUMENT_ROOT; leave it empty for the folder',
    '; md.php itself lives in (default: /share/, where RevoAp stores runs)',
    'BROWSE_DIR = /share/',
    '',
    '; Machine API key - used by external clients (e.g. RevoAp) for',
    '; updater.php?api_key=<value>&action=status|conflicts|install&format=json',
    '; Keep it secret: it authorises file installs. Rotate by editing this line.',
    'API_KEY = ' . $fixtureKey,
];
$expected = implode("\n", $expectedLines) . "\n";

$rendered = MdViewerIni::render(MdViewerIni::DEFAULTS, $fixtureKey);
check('render() reproduces the required file exactly', $rendered === $expected,
    $rendered === $expected ? '' : 'the rendered file differs from the specification');
check('the file ends with a newline', str_ends_with($rendered, "\n"));
check('the key line has the documented shape',
    preg_match('/^API_KEY = mdv_[0-9a-f]{48}$/m', $rendered) === 1);
check('all six keys are present exactly once', (static function () use ($rendered): bool {
    foreach (MdViewerIni::KEYS as $key) {
        if (preg_match('/^' . $key . ' = (true|false)$/m', $rendered) !== 1) { return false; }
    }
    return true;
})());
check('the parser sees all seven keys', MdViewerIni::parse($rendered)['present'] === MdViewerIni::allKeys(),
    implode(',', MdViewerIni::parse($rendered)['present']));
check('BROWSE_DIR is written exactly once, in the style of the booleans',
    substr_count($rendered, 'BROWSE_DIR = ') === 1 && str_contains($rendered, "\nBROWSE_DIR = /share/\n"),
    'occurrences: ' . substr_count($rendered, 'BROWSE_DIR = '));
check('the seventh key is written after the six booleans and before the key',
    strpos($rendered, "\nBROWSE_DIR = ") > strpos($rendered, "\nALLOW_CREATE_INDEX_PHP_LINK = ")
    && strpos($rendered, "\nBROWSE_DIR = ") < strpos($rendered, "\nAPI_KEY = "));
check('the written folder reads back as the value the panel showed',
    MdViewerIni::parse($rendered)['values']['BROWSE_DIR'] === '/share/'
    && MdViewerIni::parse($rendered)['invalid'] === [] && MdViewerIni::parse($rendered)['unknown'] === []);
check('the keys are written in the documented order', (static function () use ($rendered): bool {
    $at = -1;
    foreach (MdViewerIni::KEYS as $key) {
        $pos = strpos($rendered, "\n" . $key . ' = ');
        if ($pos === false || $pos < $at) { return false; }
        $at = $pos;
    }
    return true;
})());
check('DISABLE_UPLOAD is marked as the recommended value', str_contains($rendered, '(recommended: true)'));
check('the doc line that mentions api_key= is a comment, not a setting',
    substr_count($rendered, 'updater.php?api_key=') === 1);
check('the parser ignores that doc line', MdViewerIni::parse($rendered)['unknown'] === []);

// ---------------------------------------------------------------------
echo "\n== the defaults are the ones the customer specified ==\n";
// ---------------------------------------------------------------------
check('six keys, in order', MdViewerIni::KEYS === [
    'DISABLE_UPLOAD', 'DISABLE_CLIPBOARD', 'DISABLE_SAVE_CLIPBOARD_TO_FILE',
    'ALLOW_UPDATE', 'ALLOW_RESTORE', 'ALLOW_CREATE_INDEX_PHP_LINK',
]);
check('default values match the specification', MdViewerIni::DEFAULTS === [
    'DISABLE_UPLOAD'                 => true,
    'DISABLE_CLIPBOARD'              => false,
    'DISABLE_SAVE_CLIPBOARD_TO_FILE' => true,
    'ALLOW_UPDATE'                   => true,
    'ALLOW_RESTORE'                  => false,
    'ALLOW_CREATE_INDEX_PHP_LINK'    => false,
]);
check('the seventh key is the BROWSE_DIR string setting, in file order',
    MdViewerIni::STRING_KEYS === ['BROWSE_DIR']
    && MdViewerIni::allKeys() === array_merge(MdViewerIni::KEYS, ['BROWSE_DIR']),
    implode(',', MdViewerIni::allKeys()));
check('BROWSE_DIR defaults to /share/ and is not a boolean',
    MdViewerIni::STRING_DEFAULTS === ['BROWSE_DIR' => '/share/']
    && MdViewerIni::defaults() === MdViewerIni::DEFAULTS + ['BROWSE_DIR' => '/share/']
    && MdViewerIni::isStringKey('BROWSE_DIR') === true
    && MdViewerIni::isStringKey('ALLOW_UPDATE') === false);
check('the documented default /share/ is the one the contract asked for',
    MdViewerIni::STRING_DEFAULTS['BROWSE_DIR'] === '/share/');
check('ALLOW_UPDATE is true by default (the machine API needs it)', MdViewerIni::DEFAULTS['ALLOW_UPDATE'] === true);
check('DISABLE_UPLOAD is the recommended value', MdViewerIni::RECOMMENDED['DISABLE_UPLOAD'] === true);
check('every key has a human label', count(MdViewerIni::LABELS) === count(MdViewerIni::allKeys()));
check('the seventh key has its own label',
    isset(MdViewerIni::LABELS['BROWSE_DIR']) && MdViewerIni::LABELS['BROWSE_DIR'] !== '');

// ---------------------------------------------------------------------
echo "\n== one key at a time: render -> parse round trip ==\n";
// ---------------------------------------------------------------------
$flips = 0;
foreach (MdViewerIni::KEYS as $key) {
    $values        = MdViewerIni::defaults();
    $values[$key]  = !$values[$key];
    $back          = MdViewerIni::parse(MdViewerIni::render($values, $fixtureKey));
    if ($back['values'] === $values) { $flips++; }
}
check('every single-key flip survives the round trip', $flips === count(MdViewerIni::KEYS),
    "{$flips}/" . count(MdViewerIni::KEYS));

$allTrue = array_fill_keys(MdViewerIni::KEYS, true);
check('an all-true file parses back as all-true',
    MdViewerIni::parse(MdViewerIni::render($allTrue, $fixtureKey))['values']
        === $allTrue + MdViewerIni::STRING_DEFAULTS);
$allFalse = array_fill_keys(MdViewerIni::KEYS, false);
check('an all-false file parses back as all-false',
    MdViewerIni::parse(MdViewerIni::render($allFalse, $fixtureKey))['values']
        === $allFalse + MdViewerIni::STRING_DEFAULTS);

// ---------------------------------------------------------------------
echo "\n== reading a hand-edited file ==\n";
// ---------------------------------------------------------------------
$lenient = MdViewerIni::parse("; comment\nALLOW_UPDATE = on\nALLOW_RESTORE = 0\nDISABLE_UPLOAD = yes\n");
check('the spellings MD.Viewer accepts are read as their real value',
    $lenient['values']['ALLOW_UPDATE'] === true && $lenient['values']['ALLOW_RESTORE'] === false
    && $lenient['values']['DISABLE_UPLOAD'] === true, json_encode($lenient));
check('keys the file omits fall back to the defaults',
    $lenient['values']['DISABLE_SAVE_CLIPBOARD_TO_FILE'] === true
    && $lenient['values']['ALLOW_CREATE_INDEX_PHP_LINK'] === false);
check('a missing file reads as the defaults', MdViewerIni::parse('')['values'] === MdViewerIni::defaults());
check('a missing file reads BROWSE_DIR as /share/',
    MdViewerIni::parse('')['values']['BROWSE_DIR'] === '/share/'
    && MdViewerIni::parse('')['present'] === []);

$junk = MdViewerIni::parse("ALLOW_RESTORE = banana\n");
check('an unreadable value is reported, not silently kept',
    $junk['invalid'] === ['ALLOW_RESTORE'] && $junk['values']['ALLOW_RESTORE'] === false,
    json_encode($junk));
check('a commented-out key is not read', MdViewerIni::extractKey("; API_KEY = mdv_abc\n") === null);
check('an empty key is not read', MdViewerIni::extractKey("API_KEY = \n") === null);
check('a quoted key is unwrapped', MdViewerIni::extractKey("API_KEY = \"mdv_abc\"\n") === 'mdv_abc');

// ---------------------------------------------------------------------
echo "\n== BROWSE_DIR: the string setting, and what it refuses ==\n";
// ---------------------------------------------------------------------
$custom = MdViewerIni::parse(MdViewerIni::render(['BROWSE_DIR' => 'docs/runs'], $fixtureKey));
check('a custom folder survives render -> parse',
    $custom['values']['BROWSE_DIR'] === 'docs/runs' && $custom['invalid'] === [],
    json_encode($custom['values']));
$emptyDir = MdViewerIni::parse(MdViewerIni::render(['BROWSE_DIR' => ''], $fixtureKey));
check('an empty value is kept as empty, not replaced by the default',
    $emptyDir['values']['BROWSE_DIR'] === '' && $emptyDir['present'] === MdViewerIni::allKeys());
check('the pasted value is trimmed',
    MdViewerIni::toPath('  /share/  ') === '/share/' && MdViewerIni::toPath(" /share/\t") === '/share/');
check('a line break is refused by toPath()',
    MdViewerIni::toPath("a\nb") === null && MdViewerIni::toPath("a\rb") === null
    && MdViewerIni::toPath("a\0b") === null);
check('a value past 255 characters is refused',
    MdViewerIni::toPath(str_repeat('a', 255)) !== null && MdViewerIni::toPath(str_repeat('a', 256)) === null);
check('render() cannot be used to append a line to .md.ini',
    substr_count(MdViewerIni::render(['BROWSE_DIR' => "/share/\nALLOW_UPDATE = false"], $fixtureKey),
        'ALLOW_UPDATE = ') === 1);
check('the write path takes the folder and refuses a line break',
    MdViewerIni::validate(['BROWSE_DIR' => '/share/'])['ok'] === true
    && MdViewerIni::validate(['BROWSE_DIR' => '/share/'])['values'] === ['BROWSE_DIR' => '/share/']
    && MdViewerIni::validate(['BROWSE_DIR' => "/share/\nALLOW_UPDATE = false"])['ok'] === false);
check('a non-string value is refused', MdViewerIni::validate(['BROWSE_DIR' => true])['ok'] === false
    && MdViewerIni::validate(['BROWSE_DIR' => 42])['ok'] === false);
check('a refused folder is named in the error',
    (static function (): bool {
        $r = MdViewerIni::validate(['BROWSE_DIR' => str_repeat('x', 300)]);
        return $r['ok'] === false && str_contains($r['errors'][0], 'BROWSE_DIR');
    })());
check('an unknown key is still refused',
    MdViewerIni::validate(['BROWSE_DIR' => '/share/', 'NOPE' => true])['ok'] === false
    && MdViewerIni::validate(['API_KEY' => '/share/'])['ok'] === false);
check('the six booleans still take true/false only',
    (static function (): bool {
        $ok = MdViewerIni::validate(['ALLOW_UPDATE' => 'true', 'DISABLE_UPLOAD' => false]);
        return $ok['ok'] === true
            && $ok['values'] === ['ALLOW_UPDATE' => true, 'DISABLE_UPLOAD' => false]
            && MdViewerIni::validate(['ALLOW_UPDATE' => 'yes'])['ok'] === false
            && MdViewerIni::validate(['ALLOW_UPDATE' => '1'])['ok'] === false
            && MdViewerIni::validate(['ALLOW_UPDATE' => 1])['ok'] === false
            && MdViewerIni::validate(['ALLOW_UPDATE' => null])['ok'] === false;
    })());
check('a file whose folder line is unusable is reported as invalid',
    (static function (): bool {
        $p = MdViewerIni::parse("BROWSE_DIR = over " . str_repeat('x', 300) . "\nALLOW_UPDATE = true\n");
        return $p['invalid'] === ['BROWSE_DIR'] && $p['values']['BROWSE_DIR'] === '/share/'
            && $p['values']['ALLOW_UPDATE'] === true;
    })());
check('a hand-edited folder line is read as written',
    MdViewerIni::parse("BROWSE_DIR = share\n")['values']['BROWSE_DIR'] === 'share');

// ---------------------------------------------------------------------
echo "\n== the write path is strict: true/false only ==\n";
// ---------------------------------------------------------------------
check('a boolean is accepted', MdViewerIni::validate(['ALLOW_UPDATE' => true])['ok'] === true);
check('the string "true" is accepted', MdViewerIni::validate(['ALLOW_UPDATE' => 'true'])['ok'] === true);
check('the string "false" is accepted', MdViewerIni::validate(['ALLOW_UPDATE' => 'false'])['values']['ALLOW_UPDATE'] === false);
check('"yes" is rejected', MdViewerIni::validate(['ALLOW_UPDATE' => 'yes'])['ok'] === false);
check('"1" is rejected', MdViewerIni::validate(['ALLOW_UPDATE' => '1'])['ok'] === false);
check('an integer is rejected', MdViewerIni::validate(['ALLOW_UPDATE' => 1])['ok'] === false);
check('null is rejected', MdViewerIni::validate(['ALLOW_UPDATE' => null])['ok'] === false);
check('an unknown key is rejected', MdViewerIni::validate(['NOPE' => true])['ok'] === false);
check('an empty payload is valid (and changes nothing)', MdViewerIni::validate([]) === ['ok' => true, 'values' => [], 'errors' => []]);
check('a rejected value is named in the error', (static function (): bool {
    $r = MdViewerIni::validate(['ALLOW_RESTORE' => 'maybe']);
    return count($r['errors']) === 1 && str_contains($r['errors'][0], 'ALLOW_RESTORE');
})());

// ---------------------------------------------------------------------
echo "\n== the generated key ==\n";
// ---------------------------------------------------------------------
$keys = [];
for ($i = 0; $i < 50; $i++) { $keys[MdViewerIni::generateKey()] = true; }
check('50 generations are 50 distinct keys', count($keys) === 50, (string)count($keys));
$sample = array_key_first($keys);
check('the key is mdv_ + 48 hex characters = 52 characters',
    is_string($sample) && strlen($sample) === MdViewerIni::KEY_LENGTH
    && preg_match('/^mdv_[0-9a-f]{48}$/', $sample) === 1, (string)$sample === '' ? '' : 'shape mismatch');
check('looksGenerated() accepts our own key', MdViewerIni::looksGenerated((string)$sample));
check('looksGenerated() rejects rand()-style junk', !MdViewerIni::looksGenerated('mdv_ABC') && !MdViewerIni::looksGenerated(''));
check('the mask carries the prefix and nothing else', MdViewerIni::maskKey((string)$sample) === MdViewerIni::KEY_PREFIX . '••••');
check('the mask leaks no key material', !str_contains(MdViewerIni::maskKey((string)$sample), substr((string)$sample, 4, 10)));
check('a foreign key masks to dots only', MdViewerIni::maskKey('legacy-key') === '••••');
check('an empty key masks to an empty string', MdViewerIni::maskKey('') === '');
check('render() refuses a key with a newline in it', (static function () use ($fixtureKey): bool {
    try {
        MdViewerIni::render(MdViewerIni::DEFAULTS, "mdv_bad\nALLOW_UPDATE = false");
    } catch (\InvalidArgumentException) {
        return true;
    }
    return false;
})());
check('isSafeKey() rejects empty / control characters / absurd length',
    !MdViewerIni::isSafeKey('') && !MdViewerIni::isSafeKey("a\nb")
    && !MdViewerIni::isSafeKey(str_repeat('a', 201)) && MdViewerIni::isSafeKey('mdv_ok'));

// ---------------------------------------------------------------------
echo "\n== the install directory: write, preserve, rotate ==\n";
// ---------------------------------------------------------------------
$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/rv_ext_ini_' . bin2hex(random_bytes(4));
$extDir  = $tmpRoot . '/extensions/md-viewer';
@mkdir($extDir, 0777, true);

$installer = new MdViewerInstaller($tmpRoot);
$iniPath   = $installer->iniPath();
check('the ini path is the one the installer reads',
    str_ends_with(str_replace('\\', '/', $iniPath), '/extensions/md-viewer/.md.ini'), $iniPath);

$fresh = $installer->settingsState();
check('with no file the panel shows the defaults', $fresh['values'] === MdViewerIni::defaults());
check('with no file the browse folder defaults to /share/',
    $fresh['values']['BROWSE_DIR'] === '/share/' && $fresh['string_defaults'] === MdViewerIni::STRING_DEFAULTS);
check('with no file: absent, no key, but writable',
    $fresh['present'] === false && $fresh['key_present'] === false && $fresh['writable'] === true,
    json_encode($fresh));
check('with no file the key mask is null', $fresh['key_mask'] === null);
check('with no file every key is reported as missing (the seventh too)',
    $fresh['missing_keys'] === MdViewerIni::allKeys());

$first = $installer->writeIni();
check('writeIni() creates the file with the defaults',
    $first['ok'] === true && $first['created'] === true && $first['changed'] === true, json_encode($first['error']));
$after = readIniFile($iniPath);
check('the created file declares all seven keys', $installer->settingsState()['missing_keys'] === []);
check('the created file declares the browse folder',
    readIniFile($iniPath)['parsed']['values']['BROWSE_DIR'] === '/share/');
check('the created file has a generated key', $after['key'] !== null && MdViewerIni::looksGenerated((string)$after['key']));
check('the created file says ALLOW_UPDATE = true', $after['parsed']['values']['ALLOW_UPDATE'] === true);
$key1 = (string)$after['key'];
check('no write response contains the key',
    !str_contains(json_encode($first), $key1) && !str_contains(json_encode($installer->settingsState()), $key1));

// A second write with different values must keep the key.
$second = $installer->writeIni(['ALLOW_UPDATE' => false, 'DISABLE_UPLOAD' => false]);
$after2 = readIniFile($iniPath);
check('a second write is not a "create"', $second['created'] === false && $second['changed'] === true);
check('the supplied values are applied',
    $after2['parsed']['values']['ALLOW_UPDATE'] === false && $after2['parsed']['values']['DISABLE_UPLOAD'] === false);
check('values that were not supplied keep what the file said',
    $after2['parsed']['values']['DISABLE_CLIPBOARD'] === false
    && $after2['parsed']['values']['DISABLE_SAVE_CLIPBOARD_TO_FILE'] === true
    && $after2['parsed']['values']['ALLOW_RESTORE'] === false);
check('an existing key is NOT regenerated on a write', $after2['key'] === $key1,
    'the key changed: clients configured with it would break');

$third = $installer->writeIni(['ALLOW_UPDATE' => false, 'DISABLE_UPLOAD' => false]);
check('writing identical bytes is a no-op', $third['changed'] === false && $third['ok'] === true);
check('the no-op did not touch the key', readIniFile($iniPath)['key'] === $key1);

// The seventh key through the file writer: written once, read back as it was
// typed, and unable to append a line of its own.
$dirWrite = $installer->writeIni(['BROWSE_DIR' => 'runs/']);
$afterDir = readIniFile($iniPath);
check('writeIni() stores a non-default folder',
    $dirWrite['ok'] === true && $dirWrite['changed'] === true
    && $afterDir['parsed']['values']['BROWSE_DIR'] === 'runs/');
check('the stored folder appears exactly once in the file',
    substr_count($afterDir['raw'], 'BROWSE_DIR = ') === 1
    && str_contains($afterDir['raw'], "\nBROWSE_DIR = runs/\n"));
check('that write left the six booleans alone',
    $afterDir['parsed']['values']['ALLOW_UPDATE'] === false
    && $afterDir['parsed']['values']['DISABLE_UPLOAD'] === false
    && $afterDir['parsed']['values']['DISABLE_SAVE_CLIPBOARD_TO_FILE'] === true);
check('that write did not rotate the key', $afterDir['key'] === $key1);
$emptyWrite = $installer->writeIni(['BROWSE_DIR' => '']);
check('an empty folder is written and read back as empty',
    $emptyWrite['ok'] === true && $emptyWrite['changed'] === true
    && readIniFile($iniPath)['parsed']['values']['BROWSE_DIR'] === '');
check('a line break in the folder is refused by writeIni',
    $installer->writeIni(['BROWSE_DIR' => "/share/\nALLOW_UPDATE = true"])['changed'] === false);
check('the refused folder injected no line',
    readIniFile($iniPath)['parsed']['values']['ALLOW_UPDATE'] === false
    && substr_count(readIniFile($iniPath)['raw'], 'ALLOW_UPDATE = ') === 1);

$rotated = $installer->rotateIniKey();
$after3  = readIniFile($iniPath);
check('rotation reports rotated=true', $rotated['rotated'] === true && $rotated['changed'] === true);
check('rotation generates a different, well-shaped key',
    $after3['key'] !== null && $after3['key'] !== $key1 && MdViewerIni::looksGenerated((string)$after3['key']));
check('rotation keeps the settings',
    $after3['parsed']['values']['ALLOW_UPDATE'] === false && $after3['parsed']['values']['DISABLE_UPLOAD'] === false);
check('the rotate response carries neither the old nor the new key',
    !str_contains(json_encode($rotated), $key1) && !str_contains(json_encode($rotated), (string)$after3['key']));
$key2 = (string)$after3['key'];

// A key written by somebody else is kept as it is - only "rotate" replaces it.
file_put_contents($iniPath, "; hand-written by the operator\nALLOW_UPDATE = true\nAPI_KEY = legacy-key-1234567890\n");
$foreign = $installer->writeIni(['DISABLE_CLIPBOARD' => true]);
$afterF  = readIniFile($iniPath);
check('a foreign but usable key is preserved verbatim', $afterF['key'] === 'legacy-key-1234567890', (string)$afterF['key']);
check('the panel says that key was not generated here', $installer->settingsState()['key_generated'] === false);
check('the hand-edited value survives', $afterF['parsed']['values']['ALLOW_UPDATE'] === true);
check('the missing keys are filled in', $afterF['parsed']['present'] === MdViewerIni::allKeys(), implode(',', $afterF['parsed']['present']));
check('a file that predates the seventh key gets the default folder',
    $afterF['parsed']['values']['BROWSE_DIR'] === '/share/');
check('that write reported a change', $foreign['changed'] === true);

// ensureIni(): the install path - complete and with a key, without rotating.
file_put_contents($iniPath, "ALLOW_UPDATE = false\n");
$ensured = $installer->ensureIni();
$afterE  = readIniFile($iniPath);
check('ensureIni() completes a partial file', $ensured['ok'] === true && $afterE['parsed']['present'] === MdViewerIni::allKeys());
check('ensureIni() completes a file written before the seventh key existed',
    $afterE['parsed']['values']['BROWSE_DIR'] === '/share/'
    && str_contains((string)file_get_contents($iniPath), 'BROWSE_DIR = /share/'));
check('ensureIni() adds a key when the file has none', $afterE['key'] !== null && MdViewerIni::looksGenerated((string)$afterE['key']));
check('ensureIni() keeps the values it found', $afterE['parsed']['values']['ALLOW_UPDATE'] === false);
$ensured2 = $installer->ensureIni();
check('ensureIni() is a no-op on a complete file',
    $ensured2['changed'] === false && $ensured2['created'] === false && readIniFile($iniPath)['key'] === $afterE['key']);

check('no leftover temp files in the extension directory', (static function () use ($extDir): bool {
    foreach (scandir($extDir) ?: [] as $entry) {
        if (str_contains($entry, '.tmp-')) { return false; }
    }
    return true;
})());

if (PHP_OS_FAMILY === 'Windows') {
    echo "  note  chmod 0600 is not observable on Windows - the request itself is checked below\n";
} else {
    check('the file is mode 0600', substr(sprintf('%o', (int)fileperms($iniPath)), -4) === '0600',
        substr(sprintf('%o', (int)fileperms($iniPath)), -4));
}
check('the writer asks for 0600 on both the temp file and the final file', (static function (): bool {
    $src = (string)file_get_contents(dirname(__DIR__) . '/src/Extensions/MdViewerInstaller.php');
    return str_contains($src, 'chmod($tmp, 0600)') && str_contains($src, 'chmod($path, 0600)');
})());

// ---------------------------------------------------------------------
echo "\n== the endpoints: no key material, honest states ==\n";
// ---------------------------------------------------------------------
Config::set(['Protect_Settings_by_Admin_Key' => 0], $tmpRoot);
$controller = new ExtensionsController($installer);

$settingsJson = $controller->settings();
$settings     = json_decode($settingsJson, true);
$liveKey      = (string)(readIniFile($iniPath)['key'] ?? '');
check('settings() answers ok', is_array($settings) && ($settings['ok'] ?? false) === true);
check('settings() ships the six boolean keys and their labels',
    ($settings['keys'] ?? null) === MdViewerIni::KEYS && count($settings['labels'] ?? []) === 7);
check('settings() ships the string setting, its label and its default',
    ($settings['string_keys'] ?? null) === MdViewerIni::STRING_KEYS
    && ($settings['string_defaults'] ?? null) === MdViewerIni::STRING_DEFAULTS
    && ($settings['labels']['BROWSE_DIR'] ?? '') !== '');
check('settings() reports the browse folder currently on disk',
    ($settings['settings']['values']['BROWSE_DIR'] ?? null) === '/share/'
    && !in_array('BROWSE_DIR', (array)($settings['settings']['missing_keys'] ?? []), true));
check('settings() ships the defaults and the recommended flag',
    ($settings['defaults'] ?? null) === MdViewerIni::DEFAULTS && ($settings['recommended']['DISABLE_UPLOAD'] ?? null) === true);
check('settings() reports the key as set, as a mask',
    ($settings['settings']['key_present'] ?? null) === true
    && ($settings['settings']['key_mask'] ?? null) === MdViewerIni::KEY_PREFIX . '••••');
check('the settings payload contains no key material', $liveKey !== '' && !str_contains($settingsJson, $liveKey));

$saveOk    = $controller->saveSettings(['settings' => ['ALLOW_RESTORE' => 'true', 'DISABLE_UPLOAD' => false]]);
$saveBody  = json_decode($saveOk, true);
$afterSave = readIniFile($iniPath);
check('saveSettings() reports the write', ($saveBody['ok'] ?? false) === true && ($saveBody['changed'] ?? false) === true);
check('saveSettings() applied the values',
    $afterSave['parsed']['values']['ALLOW_RESTORE'] === true && $afterSave['parsed']['values']['DISABLE_UPLOAD'] === false);
check('saveSettings() kept the key', $afterSave['key'] === $liveKey);
check('the save response contains no key material', !str_contains($saveOk, $liveKey));

$saveDir    = json_decode($controller->saveSettings(['settings' => ['BROWSE_DIR' => '/share/']]), true);
check('saveSettings() writes the browse folder',
    ($saveDir['ok'] ?? false) === true
    && readIniFile($iniPath)['parsed']['values']['BROWSE_DIR'] === '/share/');
check('the save response carries the value, never the machine key',
    ($saveDir['settings']['values']['BROWSE_DIR'] ?? null) === '/share/'
    && $liveKey !== '' && !str_contains(json_encode($saveDir), $liveKey)
    && !str_contains(json_encode($saveDir), 'API_KEY'));
$dirRawBefore = (string)file_get_contents($iniPath);
$saveDirBad   = json_decode($controller->saveSettings(['settings' => ['BROWSE_DIR' => "/share/\nALLOW_UPDATE = false"]]), true);
check('a folder with a line break is a 400 naming BROWSE_DIR',
    ($saveDirBad['ok'] ?? null) === false && ($saveDirBad['error'] ?? '') === 'invalid_settings'
    && str_contains(implode(' ', (array)($saveDirBad['errors'] ?? [])), 'BROWSE_DIR'));
check('the rejected folder changed nothing',
    (string)file_get_contents($iniPath) === $dirRawBefore
    && readIniFile($iniPath)['parsed']['values']['BROWSE_DIR'] === '/share/');

$rawBefore = (string)file_get_contents($iniPath);
$saveBad   = json_decode($controller->saveSettings(['settings' => ['ALLOW_RESTORE' => 'yes']]), true);
check('a non-boolean value is a 400 naming the key',
    ($saveBad['ok'] ?? null) === false && ($saveBad['error'] ?? '') === 'invalid_settings'
    && str_contains(implode(' ', (array)($saveBad['errors'] ?? [])), 'ALLOW_RESTORE'));
check('a rejected save leaves the file untouched', (string)file_get_contents($iniPath) === $rawBefore);
$saveUnknown = json_decode($controller->saveSettings(['settings' => ['NOPE' => true]]), true);
check('an unknown key is rejected', ($saveUnknown['error'] ?? '') === 'invalid_settings');
$saveEmpty = json_decode($controller->saveSettings(['settings' => []]), true);
check('an empty settings object is refused', ($saveEmpty['error'] ?? '') === 'no_settings');
$saveList = json_decode($controller->saveSettings(['settings' => ['ALLOW_UPDATE']]), true);
check('a list instead of an object is rejected', ($saveList['error'] ?? '') === 'invalid_settings');

$rotateJson = $controller->rotateKey(['confirm' => true]);
$rotateBody = json_decode($rotateJson, true);
$afterRot   = readIniFile($iniPath);
check('rotateKey() generates a new key', ($rotateBody['ok'] ?? false) === true
    && ($rotateBody['rotated'] ?? false) === true && $afterRot['key'] !== $liveKey);
check('the rotate response carries no key material',
    !str_contains($rotateJson, $liveKey) && !str_contains($rotateJson, (string)$afterRot['key']));
check('rotateKey() keeps the settings',
    $afterRot['parsed']['values']['ALLOW_RESTORE'] === true && $afterRot['parsed']['values']['DISABLE_UPLOAD'] === false);
$unconfirmed = json_decode($controller->rotateKey([]), true);
check('rotation without an explicit confirm is refused',
    ($unconfirmed['error'] ?? '') === 'confirm_required' && readIniFile($iniPath)['key'] === $afterRot['key']);

$installBad = json_decode($controller->install([
    'dry_run' => true, 'settings' => ['BROWSE_DIR' => "/share/\nDISABLE_UPLOAD = true"],
]), true);
check('install() refuses a multi-line folder before it touches anything',
    ($installBad['error'] ?? '') === 'invalid_settings'
    && str_contains(implode(' ', (array)($installBad['errors'] ?? [])), 'BROWSE_DIR'));
$installOk = json_decode($controller->install([
    'dry_run' => true, 'settings' => ['BROWSE_DIR' => '/share/', 'ALLOW_UPDATE' => true],
]), true);
check('install() accepts a folder and then reports the missing installer',
    ($installOk['error'] ?? '') === MdViewerApi::ERR_INSTALLER_MISSING,
    json_encode($installOk['error'] ?? null));

rrm($tmpRoot);
check('the temp tree was removed', !is_dir($tmpRoot));

// ---------------------------------------------------------------------
echo "\n== static wiring ==\n";
// ---------------------------------------------------------------------
$rootDir     = dirname(__DIR__);
$indexPhp    = (string)file_get_contents($rootDir . '/index.php');
$js          = (string)file_get_contents($rootDir . '/assets/js/extensions.js');
$controllerS = (string)file_get_contents($rootDir . '/src/Http/Controllers/ExtensionsController.php');

check('index.php wires the settings routes',
    str_contains($indexPhp, "'/api/extensions/settings'") && str_contains($indexPhp, "'/api/extensions/rotate-key'"));
check('the controller exposes the three new endpoints',
    method_exists(ExtensionsController::class, 'settings')
    && method_exists(ExtensionsController::class, 'saveSettings')
    && method_exists(ExtensionsController::class, 'rotateKey'));
check('install() writes the selected values before calling the API',
    str_contains($controllerS, '$this->ensureIni($selected'));
check('the panel renders one select per key, from the server keys',
    str_contains($js, 'oc-ext-settings-grid') && str_contains($js, "'oc-ext-set-' + key"));
check('the panel sends the selected values with the install', str_contains($js, 'settings: collectSettings()'));
check('the panel shows the mask and never asks for the key',
    str_contains($js, 's.key_mask') && preg_match('/\bapi_key\b/', $js) !== 1);
check('rotating is a confirmed action in the UI',
    str_contains($js, "window.confirm('Generate a NEW machine API key"));
check('the panel reports the CLI interpreter state',
    str_contains($js, 'php_cli') && str_contains($js, 'MdViewerPhpCli'));
check('the six settings are the ones the panel knows about',
    substr_count($js, 'settingSelects[key] = select') === 1 && str_contains($js, 'collectSettings()'));
check('the panel renders a text field for every string setting',
    str_contains($js, 'payload.string_keys') && str_contains($js, "el('input', {")
    && str_contains($js, "id: 'oc-ext-set-' + key"));
check('the text field is pre-filled from the server value or its default',
    str_contains($js, 'settingInputs[key] = input') && str_contains($js, 'stringDefaults[key]'));
check('the panel sends the folder with the save and with the install',
    substr_count($js, 'collectSettings()') >= 2 && str_contains($js, 'settingInputs'));
check('the controller ships the string setting and its default',
    str_contains($controllerS, 'MdViewerIni::STRING_KEYS')
    && str_contains($controllerS, 'MdViewerIni::STRING_DEFAULTS'));
check('the controller validates through the one parser', str_contains($controllerS, 'MdViewerIni::validate'));
check('the writer accepts the seventh key', str_contains((string)file_get_contents($rootDir . '/src/Extensions/MdViewerInstaller.php'), 'MdViewerIni::STRING_KEYS'));

// The error the operator saw must not be produced for a missing interpreter.
$noCliLabel = MdViewerApi::errorLabel(MdViewerApi::ERR_NO_PHP_CLI);
check('the no-CLI error names the interpreter and not the key',
    str_contains($noCliLabel, 'CLI') && !str_contains($noCliLabel, 'API_KEY'),
    $noCliLabel);
check('the key error is still its own message',
    str_contains(MdViewerApi::errorLabel(MdViewerApi::ERR_API_KEY_UNAVAILABLE), 'API_KEY'));

echo "----------------------------------------\n";
echo $failures === 0
    ? "ALL OK: {$passes} passed, 0 failed\n"
    : "FAILED: {$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
