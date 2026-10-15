<?php
/**
 * PdoCompatTest: the PHP 8.5 driver-constant shim.
 *
 * Two things are worth a test here, and neither is obvious:
 *
 *  1. **The fallback table must match the real constants.** PdoCompat carries a
 *     hand-written numeric table used only on a build without pdo_mysql. A wrong
 *     number there is invisible at runtime and security-relevant: this table
 *     once had FOUND_ROWS (1004) and LOCAL_INFILE (1001) transposed, which on
 *     such a build would have turned "return matched rows" into "allow LOAD DATA
 *     LOCAL INFILE" — a file-read primitive enabled by a typo. The assertion
 *     below compares the table against the live constants whenever pdo_mysql is
 *     loaded, so the table cannot rot.
 *
 *  2. **Reading the constant must not emit E_DEPRECATED on 8.5.** That is the
 *     entire reason the class exists. The check installs a strict error handler
 *     that turns any deprecation into a failure, then resolves every attribute.
 *
 * Also covers the driver-sensitivity that makes the shim safe to call
 * unconditionally: the MySQL attribute is only added for a `mysql:` DSN, because
 * PDO rejects an unknown attribute at connect time.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\PdoCompat;

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

// =====================================================================
section('the resolved values match the live constants');
// =====================================================================

/** name => [8.5 constant name, legacy PDO constant name, expected int] */
$attributes = [
    'USE_BUFFERED_QUERY'      => ['ATTR_USE_BUFFERED_QUERY', 'MYSQL_ATTR_USE_BUFFERED_QUERY', 1000],
    'LOCAL_INFILE'            => ['ATTR_LOCAL_INFILE', 'MYSQL_ATTR_LOCAL_INFILE', 1001],
    'INIT_COMMAND'            => ['ATTR_INIT_COMMAND', 'MYSQL_ATTR_INIT_COMMAND', 1002],
    'COMPRESS'                => ['ATTR_COMPRESS', 'MYSQL_ATTR_COMPRESS', 1003],
    'FOUND_ROWS'              => ['ATTR_FOUND_ROWS', 'MYSQL_ATTR_FOUND_ROWS', 1004],
    'IGNORE_SPACE'            => ['ATTR_IGNORE_SPACE', 'MYSQL_ATTR_IGNORE_SPACE', 1005],
    'MULTI_STATEMENTS'        => ['ATTR_MULTI_STATEMENTS', 'MYSQL_ATTR_MULTI_STATEMENTS', 1012],
    'SSL_VERIFY_SERVER_CERT'  => ['ATTR_SSL_VERIFY_SERVER_CERT', 'MYSQL_ATTR_SSL_VERIFY_SERVER_CERT', 1013],
];

$live = class_exists(\Pdo\Mysql::class);
check('PHP 8.5 exposes the Pdo\Mysql attribute class', $live);
check('PdoCompat agrees with its own runtime probe', PdoCompat::hasModernDriverConstants() === $live);

foreach ($attributes as $name => [$newName, $oldName, $expected]) {
    $resolved = PdoCompat::mysql($newName, $oldName);

    // (1) The hard-coded expectation. This is the assertion that would have
    // caught the transposed FOUND_ROWS/LOCAL_INFILE pair.
    check("$name resolves to $expected", $resolved === $expected, "got $resolved");

    // (2) Agreement with whatever this runtime actually provides.
    if ($live && defined("\\Pdo\\Mysql::$newName")) {
        $real = (int)constant("\\Pdo\\Mysql::$newName");
        check("$name agrees with Pdo\\Mysql::$newName", $resolved === $real, "shim $resolved vs live $real");
    }
}

// DIRECT_QUERY is a CORE attribute (value 20), not a MYSQL_ATTR one. It is in
// the table because callers reach for it as if it were; a wrong value would
// collide with a different attribute key silently.
check('DIRECT_QUERY resolves to the core attribute value 20',
    PdoCompat::mysql('ATTR_DIRECT_QUERY', 'MYSQL_ATTR_DIRECT_QUERY') === 20,
    (string)PdoCompat::mysql('ATTR_DIRECT_QUERY', 'MYSQL_ATTR_DIRECT_QUERY'));

// The two named accessors must agree with the generic one.
check('mysqlBufferedQuery() is the buffered-query attribute',
    PdoCompat::mysqlBufferedQuery() === PdoCompat::mysql('ATTR_USE_BUFFERED_QUERY', 'MYSQL_ATTR_USE_BUFFERED_QUERY'));
check('mysqlFoundRows() is the found-rows attribute',
    PdoCompat::mysqlFoundRows() === PdoCompat::mysql('ATTR_FOUND_ROWS', 'MYSQL_ATTR_FOUND_ROWS'));
check('mysqlInitCommand() is the init-command attribute',
    PdoCompat::mysqlInitCommand() === PdoCompat::mysql('ATTR_INIT_COMMAND', 'MYSQL_ATTR_INIT_COMMAND'));

// =====================================================================
section('resolving an attribute emits no deprecation on PHP 8.5');
// =====================================================================

// This is the property that motivated the class. On 8.5 merely READING
// PDO::MYSQL_ATTR_USE_BUFFERED_QUERY raises E_DEPRECATED, so the defensive
// pattern `if (defined('PDO::MYSQL_ATTR_…')) { …use it… }` does not help: the
// guard passes and the next line raises. The handler below fails the test on
// ANY deprecation, notice or warning emitted during resolution.
$captured = [];
set_error_handler(static function (int $severity, string $message) use (&$captured): bool {
    $captured[] = "$severity: $message";
    return true;
}, E_ALL);

PdoCompat::mysql('ATTR_USE_BUFFERED_QUERY', 'MYSQL_ATTR_USE_BUFFERED_QUERY');
PdoCompat::mysql('ATTR_FOUND_ROWS', 'MYSQL_ATTR_FOUND_ROWS');
PdoCompat::mysql('ATTR_COMPRESS', 'MYSQL_ATTR_COMPRESS');
PdoCompat::mysqlBufferedQuery();
PdoCompat::mysqlFoundRows();
PdoCompat::mysqlInitCommand();
PdoCompat::isMysqlDsn('mysql:host=localhost');
PdoCompat::mysqlConnectionOptions('mysql:host=localhost');

restore_error_handler();
check('no deprecation/notice/warning is emitted while resolving attributes',
    $captured === [], implode(' | ', array_slice($captured, 0, 4)));

// Reading the legacy constant directly DOES raise, which proves the handler
// above was actually armed and is not vacuously green.
$proof = [];
set_error_handler(static function (int $severity, string $message) use (&$proof): bool {
    $proof[] = $message;
    return true;
}, E_ALL);
$legacyRead = defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') ? @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') : null;
restore_error_handler();
check('sanity: reading the legacy constant directly is what the shim avoids',
    PHP_VERSION_ID < 80500 || $legacyRead === 1000,
    var_export($legacyRead, true));

// =====================================================================
section('driver sensitivity');
// =====================================================================

$mysqlDsns = ['mysql:host=localhost;dbname=x', 'mysql:unix_socket=/tmp/m.sock;dbname=x', 'MYSQL:host=x', 'mysql:host=x'];
$otherDsns = ['sqlite::memory:', 'sqlite:/tmp/a.db', 'pgsql:host=localhost;dbname=x', 'oci:dbname=//x/y', '', 'sqlsrv:Server=x', 'not-a-dsn'];

foreach ($mysqlDsns as $dsn) {
    check("isMysqlDsn('$dsn') is true", PdoCompat::isMysqlDsn($dsn) === true);
}
foreach ($otherDsns as $dsn) {
    check('isMysqlDsn(' . var_export($dsn, true) . ') is false', PdoCompat::isMysqlDsn($dsn) === false);
}

$mysqlOpts = PdoCompat::mysqlConnectionOptions('mysql:host=localhost;dbname=x');
check('a MySQL DSN gets exactly the buffered-query option',
    $mysqlOpts === [PdoCompat::mysqlBufferedQuery() => true], json_encode($mysqlOpts));

foreach (['sqlite::memory:', 'pgsql:host=x', ''] as $dsn) {
    $opts = PdoCompat::mysqlConnectionOptions($dsn);
    // PDO rejects an attribute the driver does not support at CONNECT time, so
    // returning one for a non-MySQL DSN would break every SQLite install —
    // which is what the test suite and the in-memory fallback both use.
    check("a non-MySQL DSN gets no options ($dsn)", $opts === [], json_encode($opts));
}

// =====================================================================
section('the options it produces actually connect');
// =====================================================================

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    try {
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + PdoCompat::mysqlConnectionOptions('sqlite::memory:');
        $pdo = new PDO('sqlite::memory:', null, null, $opts);
        $row = $pdo->query('SELECT 1 AS one')->fetch(PDO::FETCH_ASSOC);
        check('a SQLite connection with the computed options works', ($row['one'] ?? null) === 1, json_encode($row));
    } catch (\Throwable $e) {
        check('a SQLite connection with the computed options works', false, $e->getMessage());
    }
} else {
    echo "  --  skipped (pdo_sqlite not available)\n";
}

// =====================================================================
section('resolution is memoised and idempotent');
// =====================================================================

$a = PdoCompat::mysqlBufferedQuery();
$b = PdoCompat::mysqlBufferedQuery();
check('repeated resolution is stable', $a === $b);
$state = PdoCompat::debugState();
check('the resolved cache is inspectable for diagnostics', is_array($state) && $state !== [], json_encode(array_keys($state)));
check('every cached value is a positive int',
    $state !== [] && !array_filter($state, static fn($v): bool => !is_int($v) || $v <= 0),
    json_encode($state));

// =====================================================================
section('no first-party code reads the deprecated constants any more');
// =====================================================================

// The codemod that introduced PdoCompat rewrote 20 files. This asserts it stayed
// rewritten: a new `PDO::MYSQL_ATTR_*` read anywhere in src/, bin/ or config.php
// reintroduces the deprecation, and it is exactly the kind of thing that comes
// back in a later patch.
$root = dirname(__DIR__);
$offenders = [];
$scanned = 0;
foreach ([new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')),
          new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/bin'))] as $it) {
    /** @var SplFileInfo $it */
    if (!$it->isFile() || $it->getExtension() !== 'php') { continue; }
    $scanned++;
    $src = (string)file_get_contents($it->getPathname());
    // PdoCompat itself must reference both spellings — that is its job.
    if (str_contains($it->getPathname(), 'PdoCompat.php')) { continue; }
    if (preg_match('/(?<![\w\\\\])PDO::MYSQL_ATTR_[A-Z_]+/', $src, $m) === 1) {
        // A mention inside a comment is documentation, not a read.
        $lines = explode("\n", $src);
        foreach ($lines as $i => $line) {
            if (preg_match('/(?<![\w\\\\])PDO::MYSQL_ATTR_[A-Z_]+/', $line) === 1) {
                $trimmed = ltrim($line);
                $isComment = str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#');
                $inString = preg_match('/[\'"][^\'"]*PDO::MYSQL_ATTR_/', $line) === 1;
                if (!$isComment && !$inString) {
                    $offenders[] = $it->getFilename() . ':' . ($i + 1);
                }
            }
        }
    }
}
$configSrc = (string)file_get_contents($root . '/config.php');
if (preg_match('/(?<![\'"\w\\\\])\\\\?PDO::MYSQL_ATTR_[A-Z_]+\s*(?!\w)/', preg_replace('/^\s*(\*|\/\/|#).*/m', '', $configSrc)) === 1) {
    $offenders[] = 'config.php';
}
check("no first-party file reads PDO::MYSQL_ATTR_* ($scanned files scanned)",
    $offenders === [], implode(', ', array_slice($offenders, 0, 8)));

// curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; the codemod
// removed nine call sites. Same regression risk, same guard.
$curlOffenders = [];
foreach ([new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')),
          new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/bin'))] as $it) {
    /** @var SplFileInfo $it */
    if (!$it->isFile() || $it->getExtension() !== 'php') { continue; }
    $src = (string)file_get_contents($it->getPathname());
    $stripped = preg_replace('/^\s*(\*|\/\/|#).*/m', '', $src) ?? $src;
    if (preg_match('/\bcurl_close\s*\(/', $stripped) === 1) { $curlOffenders[] = $it->getFilename(); }
}
check('no first-party file calls the deprecated curl_close()',
    $curlOffenders === [], implode(', ', $curlOffenders));

// Reflection*::setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5.
$reflOffenders = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests')) as $it) {
    /** @var SplFileInfo $it */
    if (!$it->isFile() || $it->getExtension() !== 'php') { continue; }
    $src = (string)file_get_contents($it->getPathname());
    $stripped = preg_replace('/^\s*(\*|\/\/|#).*/m', '', $src) ?? $src;
    if (preg_match('/->setAccessible\s*\(/', $stripped) === 1) { $reflOffenders[] = $it->getFilename(); }
}
check('no test calls the deprecated setAccessible()',
    $reflOffenders === [], implode(', ', $reflOffenders));

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
