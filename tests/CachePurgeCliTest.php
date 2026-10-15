<?php
/**
 * CachePurgeCliTest: standalone regression test for the three admin-token
 * sources of `bin/cache-purge.php` (--token= → env → project key file).
 *
 * Runs without PHPUnit, without a database and without network access —
 * `php tests/CachePurgeCliTest.php` on any host with PHP 8.4+.
 *
 * Why a sandbox: the token-file source resolves the key file relative to
 * the *project root of the executed script* (`dirname(__DIR__)`). The
 * working copy of this repository always carries a real `.admin.key`, so
 * the "no source at all" case cannot be produced in place without
 * touching that file. Every case therefore runs the real entry point
 * from a generated throwaway root that contains:
 *
 *   bin/cache-purge.php   — byte-for-byte copy of the shipped script
 *   src/**                — copy of the shipped source tree, so
 *                           AdminAuth/RootFiles root discovery can only
 *                           ever see sandbox paths (with the autoloader
 *                           pointing at the real tree, the candidate walk
 *                           would find the working copy's `.admin.key`)
 *   config.php            — minimal tree with `database.dsn = ''`, so the
 *                           database backend stays off and nothing tries
 *                           to reach MySQL
 *   .admin.key            — created per case
 *   cache/<provider>/<model>/<sha>.json — fixture entries with controlled
 *                           mtimes (one stale, one fresh)
 *
 * Verified:
 *   (a) --help exits 0, documents all three sources and reveals no token;
 *   (b) no source at all -> exit 3, error=unauthorized, reason=missing,
 *       and the message names --token=, the env var and the key file;
 *   (c) an empty (BOM-only) key file is reported as such;
 *   (d) a populated .admin.key alone authorizes the command (token_source
 *       = "file") — no token pasted into the shell needed;
 *   (e) the documented precedence --token= → env → file is observable:
 *       a wrong --token= shadows a correct key file, the flag is reported
 *       over an applicable env var, the env var is consulted before the
 *       key file, and a wrong --token= is refused instead of silently
 *       falling back to the configured (env) token;
 *       (note: env and file content *define* the configured token inside
 *       AdminAuth, so those two sources always agree with themselves —
 *       only --token= can fail the comparison);
 *   (f) --older-than-days removes only the stale entry and reports the
 *       reclaimed bytes per backend; the fresh entry survives;
 *   (g) an invalid --older-than-days is refused with exit 5 *before*
 *       authentication (a typo can never become a full wipe);
 *   (h) the configured token is never echoed to STDOUT or STDERR, and
 *       STDOUT is always exactly one JSON document.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Cache\ModelResponseCache;

$root = dirname(__DIR__);
$cli  = $root . '/bin/cache-purge.php';

$passed = 0;
$failed = 0;

function check(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo '  ok  ' . $label . "\n";
    } else {
        $failed++;
        echo '  FAIL ' . $label . "\n";
    }
}

/** Recursively delete a directory tree (best effort). */
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
        $path = $item->getPathname();
        $item->isDir() ? @rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** Recursively copy a directory tree. */
function copyTree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
        $target = $to . DIRECTORY_SEPARATOR . $items->getSubPathname();
        if ($item->isDir()) {
            @mkdir($target, 0777, true);
            continue;
        }
        copy($item->getPathname(), $target);
    }
}

/**
 * Build a throwaway project root that runs the real CLI entry point
 * without touching the working copy.
 *
 * @return string absolute sandbox root
 */
function makeSandbox(string $repoRoot, string $cli): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'revoap-cache-purge-' . bin2hex(random_bytes(6));
    @mkdir($base . '/bin', 0777, true);
    @mkdir($base . '/logs', 0777, true);

    // 1. The entry point under test, unmodified.
    copy($cli, $base . '/bin/cache-purge.php');

    // 2. The shipped source tree. A shim pointing at the repository's own
    //    src/ would leave the real project root in RootFiles' candidate
    //    list, so the "no token anywhere" case would resolve the working
    //    copy's .admin.key and always authorize.
    copyTree($repoRoot . '/src', $base . '/src');

    // 3. Minimal config: no DSN, cache enabled with the shipped "no TTL".
    file_put_contents($base . '/config.php', <<<'PHP'
<?php
declare(strict_types=1);
return [
    'app' => [
        'name'     => 'RevoAp',
        'version'  => 'test',
        'timezone' => 'UTC',
        'debug'    => false,
    ],
    'database' => ['dsn' => '', 'user' => '', 'password' => ''],
    'cache' => [
        'model_responses' => ['enabled' => true, 'ttl_seconds' => 0],
    ],
    'logging' => ['path' => __DIR__ . '/logs', 'level' => 'error'],
];
PHP);

    return $base;
}

/**
 * Write one cache fixture file. Returns its absolute path.
 */
function writeCacheEntry(string $root, string $provider, string $model, string $sha, string $body, int $mtime): string
{
    $dir = $root . '/cache/' . $provider . '/' . $model;
    @mkdir($dir, 0777, true);
    $path = $dir . '/' . $sha . '.json';
    file_put_contents($path, $body);
    @touch($path, $mtime);
    return $path;
}

/**
 * Write one *legacy flat* cache fixture file, i.e. the v1.8.20-and-earlier
 * layout `var/cache/model-responses/<sha>.json` that the CLI must now purge
 * alongside the hierarchical tree. Returns its absolute path.
 */
function writeLegacyEntry(string $root, string $sha, string $body, int $mtime): string
{
    $dir = $root . '/var/cache/model-responses';
    @mkdir($dir, 0777, true);
    $path = $dir . '/' . $sha . '.json';
    file_put_contents($path, $body);
    @touch($path, $mtime);
    return $path;
}

/**
 * Spawn `php bin/cache-purge.php` with the given argv and a controlled
 * environment. Returns [exitCode, stdout, stderr].
 *
 * @param  list<string>          $args      extra CLI arguments
 * @param  array<string,string>  $envSet    env vars to set in the child
 * @param  list<string>          $envRemove env vars to delete in the child
 * @return array{0:int,1:string,2:string}
 */
function runCli(string $root, array $args, array $envSet = [], array $envRemove = []): array
{
    $cmd = array_merge([PHP_BINARY, $root . '/bin/cache-purge.php'], $args);
    // Minimal child environment: only OS plumbing is inherited, so a token
    // exported in the parent shell can never leak into the assertions.
    $env = [];
    foreach (['PATH', 'SystemRoot', 'windir', 'ComSpec', 'PATHEXT', 'TEMP', 'TMP'] as $name) {
        $value = getenv($name);
        if ($value !== false) {
            $env[$name] = $value;
        }
    }
    unset($env['OPEN_CORUM_ADMIN_TOKEN'], $env['REVOAP_ADMIN_TOKEN']);
    foreach ($envSet as $k => $v) {
        $env[$k] = (string)$v;
    }
    foreach ($envRemove as $k) {
        unset($env[$k]);
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    if (!is_resource($proc)) {
        return [-1, '', 'proc_open failed'];
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($proc), $stdout, $stderr];
}

/** STDOUT must be exactly one JSON document (no notices, no trailing junk). */
function jsonDoc(string $stdout): ?array
{
    $decoded = json_decode(trim($stdout), true);
    return is_array($decoded) ? $decoded : null;
}

$sandbox = makeSandbox($root, $cli);
$token   = 'test-token-' . bin2hex(random_bytes(6));
$now     = time();

$stalePath = writeCacheEntry($sandbox, 'openrouter', 'anthropic/claude-sonnet', str_repeat('a', 64), '{"v":3,"stale":true}', $now - 30 * 86400);
$freshPath = writeCacheEntry($sandbox, 'bothub', 'gpt-5.5', str_repeat('b', 64), '{"v":3,"fresh":true}', $now - 3600);
// v1.8.47: the legacy flat layout is part of the same purge window. The body
// sizes are kept in variables so the byte counters can be asserted exactly.
$legacyStaleBody = '{"v":2,"legacy_stale":true}';
$legacyFreshBody = '{"v":2,"legacy_fresh":true}';
$legacyStalePath = writeLegacyEntry($sandbox, str_repeat('c', 64), $legacyStaleBody, $now - 30 * 86400);
$legacyFreshPath = writeLegacyEntry($sandbox, str_repeat('d', 64), $legacyFreshBody, $now - 3600);

echo "== (a) --help ==\n";
[$rc, $out, $err] = runCli($sandbox, ['--help']);
check($rc === 0, 'help: exit code 0');
check(strpos($out, 'Usage') !== false, 'help: usage text on stdout');
check(strpos($out, '--token=<value>') !== false, 'help: documents --token=');
check(strpos($out, 'REVOAP_ADMIN_TOKEN') !== false, 'help: documents the env var');
check(strpos($out, '.admin.key') !== false, 'help: documents the key file');
check(strpos($out, 'Prefer the environment variable') === false, 'help: stale "prefer env" wording is gone');
check(strpos($out, 'What is removed') !== false, 'help: explains what a purge removes');
check(strpos($out, 'var/cache/model-responses') !== false, 'help: names the legacy flat tree');
check(strpos($out, 'filesystem_legacy') !== false, 'help: documents the legacy counter');
check(trim($err) === '', 'help: stderr stays empty');

echo "\n== (b) no source at all -> exit 3 ==\n";
@unlink($sandbox . '/.admin.key');
[$rc, $out, $err] = runCli($sandbox, ['--status'], [], ['REVOAP_ADMIN_TOKEN', 'OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 3, 'no source: exit code 3');
check(($doc['error'] ?? '') === 'unauthorized', 'no source: error=unauthorized');
check(($doc['reason'] ?? '') === 'missing', 'no source: reason=missing');
$msg = (string)($doc['message'] ?? '');
check(strpos($msg, '--token=<value>') !== false, 'no source: message lists --token=');
check(strpos($msg, 'REVOAP_ADMIN_TOKEN') !== false, 'no source: message lists the env var');
check(strpos($msg, '.admin.key') !== false, 'no source: message lists the key file');
check(strpos($msg, 'not found') !== false, 'no source: message says the file is missing');
$sources = $doc['sources'] ?? [];
check(is_array($sources) && count($sources) === 3, 'no source: three machine-readable sources');
check(($doc['key_file']['exists'] ?? null) === false, 'no source: key_file.exists=false');
check(strpos($out, $token) === false && strpos($err, $token) === false, 'no source: no token leaked');

echo "\n== (c) empty key file -> exit 3 with an actionable reason ==\n";
file_put_contents($sandbox . '/.admin.key', "\xEF\xBB\xBF\r\n");
[$rc, $out, $err] = runCli($sandbox, ['--status'], [], ['REVOAP_ADMIN_TOKEN', 'OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 3, 'empty file: exit code 3');
check(($doc['reason'] ?? '') === 'missing', 'empty file: reason=missing');
check(strpos((string)($doc['message'] ?? ''), 'empty') !== false, 'empty file: message says "empty"');
check(strpos((string)($doc['message'] ?? ''), 'BOM') !== false, 'empty file: message mentions the BOM');
check(($doc['key_file']['had_bom'] ?? null) === true, 'empty file: key_file.had_bom=true');

echo "\n== (d) key file alone authorizes (--status) ==\n";
file_put_contents($sandbox . '/.admin.key', $token . "\n");
[$rc, $out, $err] = runCli($sandbox, ['--status'], [], ['REVOAP_ADMIN_TOKEN', 'OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 0, 'file token: exit code 0');
check(($doc['ok'] ?? null) === true, 'file token: ok=true');
check(($doc['token_source'] ?? '') === 'file', 'file token: token_source=file');
check(($doc['dry_run'] ?? null) === true, 'file token: --status stays a dry run');
check(($doc['before']['count'] ?? null) === 2, 'file token: sees both fixture entries');
check(($doc['backends']['database'] ?? true) === false, 'file token: database backend off (no MySQL in this test)');
// --status is a read-only report: it must not delete from either tree.
check(is_file($legacyStalePath) && is_file($legacyFreshPath), 'file token: --status deletes nothing from the legacy tree');
check(is_file($stalePath) && is_file($freshPath), 'file token: --status deletes nothing from the hierarchical tree');
check(strpos($out, $token) === false && strpos($err, $token) === false, 'file token: token never echoed');

echo "\n== (e) priority: --token= > env > file ==\n";
// (e1) --token is WRONG while the key file holds the right value: the flag
// is consulted first and shadows the file (no silent fall-through).
file_put_contents($sandbox . '/.admin.key', $token);
[$rc, $out] = runCli($sandbox, ['--status', '--token=WRONG-' . $token], [], ['REVOAP_ADMIN_TOKEN', 'OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 3, 'priority: wrong --token shadows a correct key file (exit 3)');
check(($doc['reason'] ?? '') === 'mismatch', 'priority: reason=mismatch');

// (e2) both --token and env hold the correct value: the flag is reported
// as the source, i.e. the flag really is consulted first.
[$rc, $out] = runCli(
    $sandbox,
    ['--status', '--token=' . $token],
    ['REVOAP_ADMIN_TOKEN' => $token],
    ['OPEN_CORUM_ADMIN_TOKEN'],
);
$doc = jsonDoc($out);
check($rc === 0, 'priority: correct --token + correct env -> exit 0');
check(($doc['token_source'] ?? '') === 'flag', 'priority: --token= is reported over the env');

// (e3) env correct, key file WRONG: the env var is used first, so the run
// succeeds even though the file would have failed the comparison.
file_put_contents($sandbox . '/.admin.key', 'WRONG-' . $token);
[$rc, $out] = runCli($sandbox, ['--status'], ['REVOAP_ADMIN_TOKEN' => $token], ['OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 0, 'priority: correct env beats a wrong key file (exit 0)');
check(($doc['token_source'] ?? '') === 'env', 'priority: token_source=env');

// (e4) env correct, --token WRONG: a presented flag is never silently
// replaced by the configured (env) token — the run is refused.
[$rc, $out] = runCli(
    $sandbox,
    ['--status', '--token=WRONG-' . $token],
    ['REVOAP_ADMIN_TOKEN' => $token],
    ['OPEN_CORUM_ADMIN_TOKEN'],
);
$doc = jsonDoc($out);
check($rc === 3, 'priority: wrong --token is refused even when env holds the token (exit 3)');
check(($doc['reason'] ?? '') === 'mismatch', 'priority: wrong --token + env -> reason=mismatch');

echo "\n== (f) --older-than-days prunes only the stale entry ==\n";
// Back to "key file only" so the file source authorizes the delete.
file_put_contents($sandbox . '/.admin.key', $token);
[$rc, $out] = runCli($sandbox, ['--older-than-days=7', '--yes'], [], ['REVOAP_ADMIN_TOKEN', 'OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 0, 'prune: exit code 0');
check(($doc['deleted']['filesystem'] ?? null) === 1, 'prune: exactly one filesystem entry deleted');
check(($doc['deleted']['bytes'] ?? 0) > 0, 'prune: reclaimed bytes reported');
check(($doc['after']['count'] ?? null) === 1, 'prune: one entry left');
check(!is_file($stalePath), 'prune: stale entry removed');
check(is_file($freshPath), 'prune: fresh entry kept');
// v1.8.47: the same window is applied to the legacy flat tree, and its
// counters stay separate from the hierarchical ones.
check(($doc['deleted']['filesystem_legacy'] ?? null) === 1, 'prune: the stale legacy entry is deleted too');
// The CLI echoes ModelResponseCache::purge() verbatim, so the counters are
// raw ints — the human-readable sizes are the API layer's addition.
check(($doc['deleted']['bytes_legacy'] ?? null) === strlen($legacyStaleBody), 'prune: legacy reclaimed bytes match the deleted file', (string)($doc['deleted']['bytes_legacy'] ?? 'null'));
check(!is_file($legacyStalePath), 'prune: stale legacy entry removed');
check(is_file($legacyFreshPath), 'prune: fresh legacy entry kept');
check(($doc['cache_version'] ?? null) === ModelResponseCache::VERSION, 'prune: cache version echoed');

echo "\n== (g) invalid window is refused before authentication ==\n";
@unlink($sandbox . '/.admin.key');
[$rc, $out] = runCli($sandbox, ['--older-than-days=-1', '--yes'], [], ['REVOAP_ADMIN_TOKEN', 'OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 5, 'invalid window: exit code 5');
check(($doc['error'] ?? '') === 'invalid_window', 'invalid window: error=invalid_window');
check($doc !== null, 'invalid window: stdout is one JSON document');

echo "\n== (h) dry run still refuses --yes-less pruning ==\n";
file_put_contents($sandbox . '/.admin.key', $token);
[$rc, $out] = runCli($sandbox, ['--older-than-days=1'], [], ['REVOAP_ADMIN_TOKEN', 'OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 0 && ($doc['dry_run'] ?? null) === true, 'dry run: no --yes means no deletion');
check(is_file($freshPath), 'dry run: fresh entry still present');

echo "\n== (i) --older-than-days=0 --yes wipes both trees ==\n";
[$rc, $out] = runCli($sandbox, ['--older-than-days=0', '--yes'], [], ['REVOAP_ADMIN_TOKEN', 'OPEN_CORUM_ADMIN_TOKEN']);
$doc = jsonDoc($out);
check($rc === 0, 'wipe: exit code 0');
check(($doc['deleted']['filesystem'] ?? null) === 1, 'wipe: the last hierarchical entry goes');
check(($doc['deleted']['filesystem_legacy'] ?? null) === 1, 'wipe: the last legacy entry goes');
check(!is_file($freshPath) && !is_file($legacyFreshPath), 'wipe: both trees are empty on disk');
check(($doc['older_than_days'] ?? null) === 0, 'wipe: window echoed as 0');
check(($doc['deleted']['bytes_legacy'] ?? null) === strlen($legacyFreshBody), 'wipe: legacy bytes match the last legacy file');

rmTree($sandbox);

echo "\n----------------------------------------\n";
if ($failed === 0) {
    echo 'ALL OK: ' . $passed . " passed, 0 failed\n";
    exit(0);
}
echo 'FAILED: ' . $failed . ' failed, ' . $passed . " passed\n";
exit(1);
