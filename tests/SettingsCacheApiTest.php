<?php
/**
 * SettingsCacheApiTest: offline checks for the Settings → Cache API
 * (Task B). Runs without PHPUnit, without MySQL and without network
 * access — `php tests/SettingsCacheApiTest.php` on any host with PHP 8.4+
 * that has the pdo_sqlite extension (the database backend is exercised
 * with an in-memory SQLite table; the SQL is portable enough for it).
 *
 * Verified:
 *   (a) the admin gate: GET /api/settings/cache and
 *       POST /api/settings/cache/purge refuse with 401 without a valid
 *       token, hand out no statistics and delete nothing;
 *   (b) statistics over a *prepared directory*: entry count, byte total,
 *       oldest/newest mtime, layout paths and the provider/model
 *       distribution derived from cache/<provider>/<model>/<sha>.json,
 *       plus the legacy flat-layout counter;
 *   (c) statistics over an *in-memory database*: counts, payload bytes,
 *       oldest/newest created_at, the hit counter (recorded hits, entries
 *       with hits, entries never hit, last_hit_at) and the derived
 *       hit rate — including the "entries exist but nothing was ever
 *       hit" case the amber hint in the UI keys off;
 *   (d) the filesystem-only deployment reports the hit counter as
 *       unavailable instead of inventing a zero;
 *   (e) purge refuses without the PURGE control word, with the wrong
 *       case, and with an out-of-range window — and deletes nothing in
 *       any of those cases;
 *   (f) purge with older_than_days removes only entries older than the
 *       window and reports the reclaimed bytes per backend; a following
 *       older_than_days=0 run empties what is left;
 *   (g) a missing cache directory is a valid empty state for both
 *       endpoints — statistics render, purge reports zero deletions, and
 *       nothing throws;
 *   (h) the token never appears in any response body.
 *
 * Known, deliberate gaps (documented in the task report): the 503
 * `admin_not_configured` branch and the 404 `settings_disabled` branch
 * need a host with no configured token / SettingsTab disabled — neither
 * can be produced without mutating the working copy or the runtime
 * overlay, so they are not asserted here.
 *
 * v1.8.47: `ModelResponseCache::purge()` now walks the legacy flat
 * `var/cache/model-responses/` tree as well, honouring the same
 * `older_than_days` window, and reports it as `deleted.filesystem_legacy`
 * / `deleted.bytes_legacy`. Section (f) pins both halves of that: the
 * stale legacy entry goes, the fresh one stays, and `deleted.bytes` stays
 * scoped to the hierarchical tree so it still matches the difference of
 * `before`/`after`, neither of which counts the legacy tree.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

// The CLI SAPI marks headers as sent the moment anything reaches STDOUT,
// and Response::json() only sets a status code while headers are still
// open. Buffering the whole run keeps headers_sent() false so the
// assertions below can read the *real* status code each endpoint chose;
// PHP flushes the buffer on exit, including after a fatal error.
ob_start();

require __DIR__ . '/../src/autoload.php';

use App\Cache\ModelResponseCache;
use App\Core\AdminAuth;
use App\Core\Config;
use App\Http\Controllers\SettingsController;

$repo = dirname(__DIR__);
$passed = 0;
$failed = 0;

function check(bool $cond, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo '  ok  ' . $label . "\n";
    } else {
        $failed++;
        echo '  FAIL ' . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
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

function makeTempRoot(string $prefix): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(5));
    @mkdir($dir, 0777, true);
    return $dir;
}

/** Write one cache entry; returns [path, size]. */
function writeEntry(string $root, string $relative, string $body, int $mtime): array
{
    $path = $root . '/' . $relative;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $body);
    @touch($path, $mtime);
    return [$path, strlen($body)];
}

/** Count the .json files under a directory (recursively). */
function countJson(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iter as $f) {
        if ($f->isFile() && substr($f->getFilename(), -5) === '.json') {
            $n++;
        }
    }
    return $n;
}

/**
 * Decode a controller response and capture the status code it set.
 *
 * @return array{status:int,body:array<string,mixed>,raw:string}
 */
function call(callable $fn): array
{
    // The run-wide buffer (see the top of this file) keeps headers open,
    // so Response::json() really does set the status code the endpoint
    // chose and it can be read back here.
    http_response_code(200);
    $raw = (string)$fn();
    $status = (int)http_response_code();
    $decoded = json_decode($raw, true);
    return [
        'status' => $status,
        'body'   => is_array($decoded) ? $decoded : [],
        'raw'    => $raw,
    ];
}

/** In-memory SQLite stand-in for model_response_cache. */
function sqliteCache(array $rows): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE model_response_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cache_key TEXT NOT NULL UNIQUE,
            provider TEXT NOT NULL,
            model TEXT NOT NULL,
            payload TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            hits INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            last_hit_at TEXT NULL
        )'
    );
    $insert = $pdo->prepare(
        'INSERT INTO model_response_cache (cache_key, provider, model, payload, version, hits, created_at, last_hit_at)
         VALUES (:k, :p, :m, :payload, 3, :hits, :created, :last_hit)'
    );
    foreach ($rows as $i => $row) {
        $insert->execute([
            'k'        => hash('sha256', 'key-' . $i),
            'p'        => $row['provider'],
            'm'        => $row['model'],
            'payload'  => $row['payload'],
            'hits'     => $row['hits'],
            'created'  => $row['created_at'],
            'last_hit' => $row['last_hit_at'],
        ]);
    }
    return $pdo;
}

// ---------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------
// The purge API logs its work at warn/error level, and the shipped
// config.php pins `logging.path` to its OWN directory
// (`__DIR__ . '/logs'`) — an absolute path that wins over the root passed
// to Config::load(). Loading the real config.php therefore made every run
// append to the repository's own logs/revoap-error.log: a write into the
// live tree from a test, the same class of leak as the SmokeTest
// runtime.json one fixed in this release.
//
// The config DATA is still the real config.php, required through a
// generated wrapper that overrides the one key that has to move. That is
// the only way to redirect the logger, because `logging.path` is read from
// the config tree rather than derived from the root.
$logRoot = makeTempRoot('revoap-cache-api-logs');
$cfgFile = $logRoot . '/config.php';
file_put_contents($cfgFile, sprintf(
    "<?php\n\$config = require %s;\n\$config['logging']['path'] = %s;\nreturn \$config;\n",
    var_export($repo . '/config.php', true),
    var_export($logRoot . '/logs', true),
));
// v1.8.60 — two reasons this file has to exist in the temp root.
//
// 1. This suite is ABOUT the admin gate, and the shipped config.php may
//    carry `Protect_Settings_by_Admin_Key => 0` (an install that asks for
//    no key). The overlay is applied after the base tree, so opting back in
//    here — rather than in the wrapper above — is both the way to say it and
//    a demonstration that `.config.php` wins over `config.php`.
// 2. Hermeticity: `RootFiles` resolves `.config.php` to the first candidate
//    directory that HAS one, so without this file the repository's own
//    deployment overlay would be applied on top of the generated tree.
file_put_contents($logRoot . '/.config.php', "<?php\ndeclare(strict_types=1);\nreturn ['Protect_Settings_by_Admin_Key' => 1];\n");
Config::load($cfgFile, $logRoot);
AdminAuth::setRootDir($repo);

$token = 'test-token-' . bin2hex(random_bytes(6));
putenv('REVOAP_ADMIN_TOKEN=' . $token);
$_ENV['REVOAP_ADMIN_TOKEN'] = $token;
$auth = static fn(): array => [];

$now = time();
$fsRoot = makeTempRoot('revoap-cache-api');
[$stale30, $size30] = writeEntry($fsRoot, 'cache/openrouter/anthropic/claude-sonnet/' . str_repeat('a', 64) . '.json', str_repeat('x', 300), $now - 30 * 86400);
[$stale10, $size10] = writeEntry($fsRoot, 'cache/openrouter/anthropic/claude-sonnet/' . str_repeat('b', 64) . '.json', str_repeat('x', 200), $now - 10 * 86400);
[$fresh1, $size1]   = writeEntry($fsRoot, 'cache/openrouter/openai/gpt-5.5/' . str_repeat('c', 64) . '.json', str_repeat('x', 100), $now - 3600);
[$fresh2, $size2]   = writeEntry($fsRoot, 'cache/bothub/gpt-5.5/' . str_repeat('d', 64) . '.json', str_repeat('x', 50), $now - 7200);
[$legacyFile]       = writeEntry($fsRoot, 'var/cache/model-responses/' . str_repeat('e', 64) . '.json', str_repeat('x', 40), $now - 40 * 86400);
[$legacyFreshFile, $legacyFreshSize] = writeEntry($fsRoot, 'var/cache/model-responses/' . str_repeat('f', 64) . '.json', str_repeat('x', 25), $now - 2 * 86400);
$legacyStaleSize = 40;
$fsTotalBytes = $size30 + $size10 + $size1 + $size2;

$ctrlFs = new SettingsController($fsRoot, null);

echo "== (a) admin gate ==\n";
unset($_SERVER['HTTP_X_ADMIN_TOKEN'], $_SERVER['HTTP_AUTHORIZATION']);
$_GET = [];
$_COOKIE = [];
$res = call(fn(): string => $ctrlFs->cacheStats());
check($res['status'] === 401, 'stats without a token: HTTP 401');
check(($res['body']['error'] ?? '') === 'unauthorized', 'stats without a token: error=unauthorized');
check(!array_key_exists('backends', $res['body']), 'stats without a token: no statistics leaked');
$res = call(fn(): string => $ctrlFs->purgeCache());
check($res['status'] === 401, 'purge without a token: HTTP 401');
check(countJson($fsRoot . '/cache') === 4, 'purge without a token: nothing deleted');

$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'WRONG-' . $token;
$res = call(fn(): string => $ctrlFs->cacheStats());
check($res['status'] === 401, 'stats with a wrong token: HTTP 401');
check(!array_key_exists('backends', $res['body']), 'stats with a wrong token: no statistics leaked');

$_SERVER['HTTP_X_ADMIN_TOKEN'] = $token;
$res = call(fn(): string => $ctrlFs->cacheStats());
check($res['status'] === 200, 'stats with the admin token: HTTP 200');

echo "\n== (b) statistics over a prepared directory ==\n";
$stats = $res['body'];
check(($stats['ok'] ?? null) === true, 'stats: ok=true');
check(is_bool($stats['enabled'] ?? null), 'stats: enabled is a boolean');
check(($stats['cache_version'] ?? null) === ModelResponseCache::VERSION, 'stats: cache version reported');
check(is_int($stats['ttl_seconds'] ?? null), 'stats: ttl_seconds is an integer');
check(($stats['ttl_human'] ?? '') !== '', 'stats: ttl_human rendered');
check(strpos((string)$stats['ttl_human'], 'no expiry') !== false, 'stats: shipped ttl=0 reported as "no expiry"');

$dbSection = $stats['backends']['database'];
$fsSection = $stats['backends']['filesystem'];
check(($dbSection['available'] ?? true) === false, 'stats: database backend unavailable without a PDO');
check(($dbSection['note'] ?? '') !== '', 'stats: database section explains why');
check(($fsSection['available'] ?? false) === true, 'stats: filesystem backend available');
check(($fsSection['count'] ?? null) === 4, 'stats: filesystem entry count');
check(($fsSection['size_bytes'] ?? null) === $fsTotalBytes, 'stats: filesystem byte total', (string)($fsSection['size_bytes'] ?? 'null'));
check(($fsSection['size_human'] ?? '') !== '', 'stats: filesystem human size');
check(str_contains(str_replace('\\', '/', (string)$fsSection['root']), '/cache'), 'stats: absolute cache root reported');
check(($fsSection['root_display'] ?? '') === 'cache', 'stats: project-relative display path');
check(($fsSection['layout'] ?? '') === 'cache/<provider>/<model>/<sha>.json', 'stats: layout string');
check(($fsSection['legacy_flat_count'] ?? null) === 2, 'stats: legacy flat-layout entries counted');
check(($fsSection['oldest'] ?? null) === date('c', $now - 30 * 86400), 'stats: oldest entry is the 30-day-old file');
check(($fsSection['newest'] ?? null) === date('c', $now - 3600), 'stats: newest entry is the freshest file');

check(($stats['totals']['count'] ?? null) === 4, 'stats: totals count matches the filesystem');
check(($stats['totals']['size_bytes'] ?? null) === $fsTotalBytes, 'stats: totals bytes match');
check(($stats['totals']['oldest'] ?? null) === date('c', $now - 30 * 86400), 'stats: totals oldest');
check(($stats['totals']['newest'] ?? null) === date('c', $now - 3600), 'stats: totals newest');
check(($stats['summary']['count'] ?? null) === 4, 'stats: raw summary() echoed (CLI parity)');
check(($stats['summary']['size_bytes'] ?? null) === $fsTotalBytes, 'stats: raw summary() bytes echoed');
check(($stats['purge_available'] ?? false) === true, 'stats: purge available');

$dist = $fsSection['distribution'];
check(($fsSection['distribution_available'] ?? false) === true, 'stats: filesystem distribution available');
check(count($dist) === 3, 'stats: filesystem distribution has three provider/model rows');
check(($dist[0]['provider'] ?? '') === 'openrouter' && ($dist[0]['model'] ?? '') === 'anthropic/claude-sonnet', 'stats: top row is the 2-entry model', (string)json_encode($dist[0] ?? []));
check(($dist[0]['entries'] ?? null) === 2, 'stats: top row entry count');
check(($dist[0]['bytes_human'] ?? '') !== '', 'stats: distribution rows carry human sizes');
$distKeys = array_map(static fn(array $r): string => ($r['provider'] ?? '') . '/' . ($r['model'] ?? ''), $dist);
check(in_array('bothub/gpt-5.5', $distKeys, true), 'stats: bothub model listed');

check(($stats['hits']['counter_available'] ?? true) === false, 'stats: hit counter unavailable with the filesystem backend only');
check(array_key_exists('hit_rate_percent', $stats['hits']) && $stats['hits']['hit_rate_percent'] === null, 'stats: no invented hit rate for the filesystem backend');
check(strpos((string)$stats['hits']['note'], 'database') !== false, 'stats: hit note points at the database backend');
check(strpos($res['raw'], $token) === false, 'stats: token never echoed');

echo "\n== (c) statistics over an in-memory database ==\n";
$payloadA = str_repeat('p', 120);
$payloadB = str_repeat('q', 200);
$payloadC = str_repeat('r', 80);
$pdo = sqliteCache([
    ['provider' => 'openrouter', 'model' => 'openai/gpt-5.5', 'payload' => $payloadA, 'hits' => 5, 'created_at' => '2026-09-01 10:00:00', 'last_hit_at' => '2026-09-10 12:00:00'],
    ['provider' => 'openrouter', 'model' => 'openai/gpt-5.5', 'payload' => $payloadB, 'hits' => 0, 'created_at' => '2026-09-05 10:00:00', 'last_hit_at' => null],
    ['provider' => 'bothub',     'model' => 'gpt-5.5',        'payload' => $payloadC, 'hits' => 0, 'created_at' => '2026-09-12 10:00:00', 'last_hit_at' => null],
]);
$ctrlDb = new SettingsController($fsRoot, $pdo);
$res = call(fn(): string => $ctrlDb->cacheStats());
check($res['status'] === 200, 'db stats: HTTP 200');
$stats = $res['body'];
$dbSection = $stats['backends']['database'];
check(($dbSection['available'] ?? false) === true, 'db stats: database backend available');
check(($dbSection['count'] ?? null) === 3, 'db stats: entry count');
check(($dbSection['size_bytes'] ?? null) === strlen($payloadA) + strlen($payloadB) + strlen($payloadC), 'db stats: payload byte total');
check(($dbSection['size_source'] ?? '') === 'payload_bytes', 'db stats: size source documented');
check(($dbSection['hits'] ?? null) === 5, 'db stats: recorded hits summed');
check(($dbSection['entries_with_hits'] ?? null) === 1, 'db stats: entries with at least one hit');
check(($dbSection['entries_never_hit'] ?? null) === 2, 'db stats: entries never hit');
check(($dbSection['oldest'] ?? null) === date('c', strtotime('2026-09-01 10:00:00')), 'db stats: oldest created_at');
check(($dbSection['newest'] ?? null) === date('c', strtotime('2026-09-12 10:00:00')), 'db stats: newest created_at');
check(($dbSection['last_hit_at'] ?? null) === date('c', strtotime('2026-09-10 12:00:00')), 'db stats: last hit timestamp');
check(array_key_exists('table_bytes', $dbSection) && $dbSection['table_bytes'] === null, 'db stats: table footprint stays null when information_schema is not available');
check(($dbSection['distribution_available'] ?? false) === true, 'db stats: distribution available');
$dbDist = $dbSection['distribution'];
check(($dbDist[0]['provider'] ?? '') === 'openrouter' && ($dbDist[0]['model'] ?? '') === 'openai/gpt-5.5', 'db stats: distribution grouped by provider+model');
check(($dbDist[0]['entries'] ?? null) === 2 && ($dbDist[0]['hits'] ?? null) === 5, 'db stats: distribution carries entries and hits');

check(($stats['hits']['counter_available'] ?? false) === true, 'db stats: hit counter available');
check(($stats['hits']['hit_rate_percent'] ?? '') === '33.3%', 'db stats: hit rate = entries hit / entries', (string)($stats['hits']['hit_rate_percent'] ?? 'null'));
check(($stats['hits']['recorded_total'] ?? null) === 5, 'db stats: recorded total hits');
check(($stats['hits']['entries_never_hit'] ?? null) === 2, 'db stats: never-hit entries surfaced');
check(($stats['totals']['count'] ?? null) === 7, 'db stats: totals add both backends');
check(($stats['totals']['size_bytes'] ?? null) === $fsTotalBytes + strlen($payloadA) + strlen($payloadB) + strlen($payloadC), 'db stats: totals add both byte counts');

// "nothing was ever hit" — the state the UI flags amber.
$pdoCold = sqliteCache([
    ['provider' => 'openrouter', 'model' => 'openai/gpt-5.5', 'payload' => $payloadA, 'hits' => 0, 'created_at' => '2026-09-01 10:00:00', 'last_hit_at' => null],
    ['provider' => 'openrouter', 'model' => 'openai/gpt-5.5', 'payload' => $payloadB, 'hits' => 0, 'created_at' => '2026-09-05 10:00:00', 'last_hit_at' => null],
]);
$res = call(fn(): string => (new SettingsController($fsRoot, $pdoCold))->cacheStats());
$cold = $res['body'];
check(($cold['hits']['counter_available'] ?? false) === true, 'cold db: counter available');
check(($cold['hits']['hit_rate_percent'] ?? null) === '0.0%', 'cold db: hit rate is 0.0%');
check(($cold['hits']['recorded_total'] ?? null) === 0, 'cold db: no hits recorded');
check(strpos((string)$cold['hits']['note'], 'never') !== false, 'cold db: note says nothing was ever served', (string)$cold['hits']['note']);
check(($cold['backends']['database']['count'] ?? null) === 2, 'cold db: entries are still reported');

echo "\n== (d) purge validation deletes nothing ==\n";
$badBodies = [
    ['body' => [],                        'label' => 'no confirm'],
    ['body' => ['confirm' => 'purge'],    'label' => 'wrong case'],
    ['body' => ['confirm' => 'RECREATE'], 'label' => 'wrong control word'],
];
foreach ($badBodies as $case) {
    $res = call(fn(): string => purgeWithBody($fsRoot, $case['body']));
    check($res['status'] === 400 && ($res['body']['error'] ?? '') === 'confirmation_required', 'purge ' . $case['label'] . ': 400 confirmation_required', (string)$res['status']);
    check(countJson($fsRoot . '/cache') === 4, 'purge ' . $case['label'] . ': nothing deleted');
}

echo "\n== (e) purge with the wrong window is refused before deleting ==\n";
foreach ([-1, 40000] as $bad) {
    $res = call(fn(): string => purgeWithBody($fsRoot, ['confirm' => 'PURGE', 'older_than_days' => $bad]));
    check($res['status'] === 400 && ($res['body']['error'] ?? '') === 'invalid_window', 'purge window ' . $bad . ': 400 invalid_window');
    check(countJson($fsRoot . '/cache') === 4, 'purge window ' . $bad . ': nothing deleted');
}
$res = call(fn(): string => purgeWithBody($fsRoot, ['confirm' => 'PURGE', 'older_than_days' => 'soon']));
check($res['status'] === 400 && ($res['body']['error'] ?? '') === 'invalid_window', 'purge window "soon": 400 invalid_window');
check(countJson($fsRoot . '/cache') === 4, 'purge window "soon": nothing deleted');

echo "\n== (f) purge honours older_than_days ==\n";
$res = call(fn(): string => purgeWithBody($fsRoot, ['confirm' => 'PURGE', 'older_than_days' => 7]));
$body = $res['body'];
check($res['status'] === 200 && ($body['ok'] ?? null) === true, 'purge 7d: HTTP 200 ok=true');
check(($body['deleted']['filesystem'] ?? null) === 2, 'purge 7d: two stale entries deleted');
check(($body['deleted']['database'] ?? null) === 0, 'purge 7d: database backend reported separately');
check(($body['deleted']['bytes'] ?? null) === $size30 + $size10, 'purge 7d: reclaimed bytes match the deleted files');
check(($body['deleted']['bytes_human'] ?? '') !== '', 'purge 7d: human-readable reclaimed size');
check(($body['before']['count'] ?? null) === 4, 'purge 7d: before-count reported');
check(($body['after']['count'] ?? null) === 2, 'purge 7d: after-count reported');
check(!is_file($stale30) && !is_file($stale10), 'purge 7d: the two stale files are gone');
check(is_file($fresh1) && is_file($fresh2), 'purge 7d: the fresh files survive');
// v1.8.47: the legacy flat tree is purged under the same window instead of
// being left behind by design.
check(($body['deleted']['filesystem_legacy'] ?? null) === 1, 'purge 7d: the stale legacy flat entry is reported');
check((int)($body['deleted']['bytes_legacy'] ?? 0) === $legacyStaleSize, 'purge 7d: legacy reclaimed bytes match the deleted file', (string)($body['deleted']['bytes_legacy'] ?? 'null'));
check(($body['deleted']['bytes_legacy_human'] ?? '') !== '', 'purge 7d: human-readable legacy reclaimed size');
check(!is_file($legacyFile), 'purge 7d: the stale legacy flat entry is gone from disk');
check(is_file($legacyFreshFile), 'purge 7d: the fresh legacy flat entry survives the window');
// bytes/bytes_human stay hierarchical-only: summary() — and therefore
// before/after — has never measured the legacy tree, so folding legacy bytes
// in would make the size and the delta contradict each other.
check(($body['deleted']['bytes'] ?? null) === $size30 + $size10, 'purge 7d: bytes still covers the hierarchical tree only');
// summary() (and so before/after) still ignores the legacy tree, which is why
// the two legacy files were never part of the count of 4.
check(($body['before']['count'] ?? null) === 4 && ($body['after']['count'] ?? null) === 2,
    'purge 7d: before/after counts still exclude the legacy tree');
check(($body['cache_version'] ?? null) === ModelResponseCache::VERSION, 'purge 7d: cache version echoed');
check(strpos((string)$body['window'], '7 day') !== false, 'purge 7d: window described');

$res = call(fn(): string => purgeWithBody($fsRoot, ['confirm' => 'PURGE', 'older_than_days' => 0]));
$body = $res['body'];
check(($body['deleted']['filesystem'] ?? null) === 2, 'purge all: removes the remaining entries');
check(($body['after']['count'] ?? null) === 0, 'purge all: cache is empty afterwards');
check(countJson($fsRoot . '/cache') === 0, 'purge all: no json files left on disk');
check(($body['deleted']['filesystem_legacy'] ?? null) === 1, 'purge all: the surviving legacy entry is removed too');
check(!is_file($legacyFreshFile), 'purge all: the legacy flat tree is empty on disk');
check(countJson($fsRoot . '/var/cache/model-responses') === 0, 'purge all: no legacy json files left');

echo "\n== (g) a missing cache directory is an empty state, not an error ==\n";
$emptyRoot = makeTempRoot('revoap-cache-empty');
$ctrlEmpty = new SettingsController($emptyRoot, null);
$res = call(fn(): string => $ctrlEmpty->cacheStats());
check($res['status'] === 200 && ($res['body']['ok'] ?? null) === true, 'empty root: stats still render');
check(($res['body']['backends']['filesystem']['available'] ?? true) === false, 'empty root: filesystem backend reported unavailable');
check(($res['body']['backends']['filesystem']['count'] ?? null) === 0, 'empty root: zero entries');
check(($res['body']['backends']['filesystem']['note'] ?? '') !== '', 'empty root: note explains the empty state');
check(($res['body']['totals']['count'] ?? null) === 0, 'empty root: totals are zero');
check(($res['body']['purge_available'] ?? null) === false, 'empty root: purge reported unavailable');
$res = call(fn(): string => purgeWithBody($emptyRoot, ['confirm' => 'PURGE', 'older_than_days' => 0]));
check($res['status'] === 200 && ($res['body']['ok'] ?? null) === true, 'empty root: purge does not throw');
check(($res['body']['deleted']['filesystem'] ?? null) === 0, 'empty root: nothing deleted');
// summary() answers null (not 0) when no backend can be measured, and the
// purge payload passes that through unchanged — the card renders it as "—".
check(array_key_exists('count', $res['body']['after']) && $res['body']['after']['count'] === null, 'empty root: "not measurable" is reported as null');

rmTree($fsRoot);
rmTree($emptyRoot);

echo "\n----------------------------------------\n";
if ($failed === 0) {
    echo 'ALL OK: ' . $passed . " passed, 0 failed\n";
    exit(0);
}
echo 'FAILED: ' . $failed . ' failed, ' . $passed . " passed\n";
exit(1);

/**
 * Drive SettingsController::purgeCache() with a fixed JSON body.
 *
 * The controller reads `php://input`, which is empty under the CLI SAPI,
 * so the fixture is injected through the endpoint's own body parameter
 * seam (`purgeCache(?array $body)`, the same injection style as the
 * `?PDO $pdo` dependency). The endpoint's own validation order — confirm,
 * then window, then backend — stays under test; only the transport is
 * replaced.
 *
 * @param array<string,mixed> $body
 */
function purgeWithBody(string $root, array $body, ?PDO $pdo = null): string
{
    return (new SettingsController($root, $pdo))->purgeCache($body);
}
