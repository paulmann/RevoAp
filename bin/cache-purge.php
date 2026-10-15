<?php
/**
 * bin/cache-purge.php — CLI invalidation for the model response cache.
 *
 * The shipped default is `cache.model_responses.ttl_seconds = 0`, which
 * means "keep every entry until it is manually invalidated". Before this
 * command, "manually" meant deleting files under `cache/` by hand and
 * hoping the database backend (if present) was reached too. This command
 * is the supported entry point for every backend at once.
 *
 * v1.8.47: the legacy flat tree (`var/cache/model-responses/`) is included.
 * It used to be lookup-only, so `--status` could report the leftovers while
 * no command could actually remove them.
 *
 * Typical uses:
 *
 *   # what is in there? (dry run — the default)
 *   php bin/cache-purge.php --status
 *
 *   # drop everything written more than 7 days ago
 *   php bin/cache-purge.php --older-than-days=7 --yes
 *
 *   # drop everything (after a key-format change, for example)
 *   php bin/cache-purge.php --yes
 *
 * Like every other maintenance command in this repository it refuses to
 * run from a web SAPI and requires the configured admin token. Three
 * sources are consulted, in priority order:
 *
 *   1. `--token=<value>`  — explicit argument. Visible in `ps`, so use it
 *      only for a one-off run on a machine nobody else can inspect.
 *   2. `admin.token_env` (default `REVOAP_ADMIN_TOKEN`) — the environment
 *      variable. Recommended for cron/systemd/CI.
 *   3. `{project_root}/.admin.key` (`admin.token_file`, see
 *      {@see \App\Core\AdminAuth::fileName()}) — read through
 *      AdminAuth::loadToken(), so the same root discovery, BOM stripping
 *      and trailing-newline handling the web UI uses apply here too.
 *
 * Source 3 exists because the operator running this command on the server
 * already has read access to the project directory: reading the key file
 * grants no privilege the operator did not already have, and it removes
 * the "copy the token out of .admin.key and paste it into the shell"
 * step (which leaked the secret into shell history). When none of the
 * three provides a token, the command refuses with exit code 3 and lists
 * all three remedies.
 *
 * Exit codes:
 *   0  success
 *   1  generic failure
 *   2  invoked from a web context
 *   3  admin token missing / not configured / mismatch
 *   4  no cache backend reachable (neither database nor filesystem)
 *   5  invalid --older-than-days value
 *
 * Output: one JSON document on STDOUT; PHP warnings go to STDERR.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.2.0
 * @since    2026-09-20
 */
declare(strict_types=1);

// Maintenance commands do not belong in a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'cli_only', 'message' => 'cache-purge.php must be run from the CLI']);
    exit(2);
}

// Keep STDOUT pure JSON; deprecation noise from vendor/config code goes
// nowhere (PHP 8.5 emits a lot of it and it is not a failure signal).
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    $deprecationMask = E_DEPRECATED | E_USER_DEPRECATED;
    if (($errno & $deprecationMask) !== 0) {
        return true;
    }
    fwrite(STDERR, sprintf("[php:%d] %s at %s:%d\n", $errno, $errstr, $errfile, $errline));
    return true;
});
set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, sprintf("[exception:%s] %s\n", get_class($e), $e->getMessage()));
    echo json_encode([
        'ok'      => false,
        'error'   => 'cache_purge_failed',
        'class'   => get_class($e),
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
});

$root = dirname(__DIR__);

$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require $root . '/src/autoload.php';
}

use App\Cache\ModelResponseCache;
use App\Core\AdminAuth;
use App\Core\Application;
use App\Core\Config;

Config::load($root . '/config.php', $root);
AdminAuth::setRootDir($root);

$options = getopt('', ['older-than-days:', 'token:', 'status', 'yes', 'help']);

if (isset($options['help'])) {
    $envName  = AdminAuth::envName();
    $fileName = AdminAuth::fileName();
    echo <<<TXT
cache-purge.php - invalidate cached model responses (database + filesystem).

Usage:
  php bin/cache-purge.php [--older-than-days=N] [--status] [--yes] [--token=...]

Options:
  --older-than-days=N  remove entries older than N days (default: 0 = all)
  --status             report what the cache holds; deletes nothing
  --yes                actually delete; without this flag the run is a dry run
  --token=...          admin token (see "Admin token" below)
  --help               show this help

What is removed:
  All three stores the cache can hold entries in:
    - the database table `model_response_cache`;
    - the hierarchical filesystem tree cache/<provider>/<model>/<sha>.json;
    - the legacy flat tree var/cache/model-responses/<sha>.json, written by
      v1.8.20 and earlier and otherwise still readable as a fallback.
  Only *.json entries are touched (never the .tmp staging files), a file is
  removed only when its mtime is strictly older than the window, and
  --older-than-days=0 removes everything.

  The "deleted" object reports the legacy tree separately, as
  filesystem_legacy / bytes_legacy. Its bytes are NOT folded into "bytes":
  that figure has always covered the hierarchical tree alone (it is what the
  Settings card subtracts to show how much a purge freed), so mixing the two
  would make the reported size and the reported delta disagree.

Admin token sources, in priority order:
  1. --token=<value>            explicit argument (visible in `ps`)
  2. {$envName} env var
  3. {$fileName} file in the project root ({$fileName} is read via AdminAuth,
                                so BOM/newline handling matches the web UI)

Only when none of the three yields a token does the command fail with
exit code 3. Deployments that already have a readable {$fileName} can run
this command with no extra arguments at all.

Exit codes: 0 ok, 1 generic, 2 web-context, 3 unauthorized,
4 no backend, 5 invalid window.

TXT;
    exit(0);
}

// Validate the window BEFORE authenticating so a typo can never become a
// full wipe.
$olderThanDays = isset($options['older-than-days']) ? (int)$options['older-than-days'] : 0;
if ($olderThanDays < 0) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'invalid_window',
        'message' => '--older-than-days must be 0 (everything) or a positive number of days',
        'value'   => $olderThanDays,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(5);
}

// Resolve the admin token from the three documented sources. `--token=`
// wins over the environment variable, which wins over the project key
// file; the file source goes through AdminAuth::loadToken() so root
// discovery, BOM stripping and newline trimming are identical to the web
// UI (and so a private `admin.token` config overlay keeps working on
// hosts where the key file is unreadable).
$tokenSource = 'none';
$token       = '';
if (array_key_exists('token', $options)) {
    $token       = trim((string)$options['token']);
    $tokenSource = 'flag';
} else {
    $envName = AdminAuth::envName();
    $envRaw  = getenv($envName);
    if (is_string($envRaw) && trim($envRaw) !== '') {
        $token       = trim($envRaw);
        $tokenSource = 'env';
    } else {
        $fromFile = AdminAuth::loadToken();
        if (is_string($fromFile) && $fromFile !== '') {
            $token = $fromFile;
            // diagnostics() reports which of the two file-level sources
            // actually produced the value: `file` (.admin.key) or
            // `config` (admin.token overlay).
            $diag        = AdminAuth::diagnostics();
            $tokenSource = ($diag['source'] ?? '') === 'config' ? 'config' : 'file';
        }
    }
}

if (!AdminAuth::verifyToken($token)) {
    $envName  = AdminAuth::envName();
    $fileName = AdminAuth::fileName();
    $diag     = AdminAuth::diagnostics();
    $fileDiag = is_array($diag['file'] ?? null) ? $diag['file'] : [];
    $rootDiag = is_array($diag['root'] ?? null) ? $diag['root'] : [];
    $sources  = [
        ['source' => 'flag', 'hint' => '--token=<value> (explicit argument; visible in `ps`)'],
        ['source' => 'env',  'hint' => 'export ' . $envName . '=<value> (environment variable)'],
        ['source' => 'file', 'hint' => 'write the token to ' . $fileName . ' in the project root (chmod 600)'],
    ];
    if ($tokenSource === 'none') {
        // Nothing was supplied at all: spell out every remedy plus the
        // state of the key file so the operator does not have to guess
        // why the file "that exists" was not picked up.
        $state = 'not found';
        if (($fileDiag['exists'] ?? false) === true) {
            if (($fileDiag['read_ok'] ?? null) === false) {
                $state = 'present but unreadable' . (($fileDiag['read_error'] ?? null) ? ' (' . $fileDiag['read_error'] . ')' : '');
            } elseif (($fileDiag['empty'] ?? null) === true) {
                $state = ($fileDiag['had_bom'] ?? false) === true
                    ? 'present but empty (starts with a UTF-8 BOM)'
                    : 'present but empty';
            } else {
                $state = 'present';
            }
        }
        $message = 'no admin token supplied and no token is configured on this host: '
            . 'the environment variable ' . $envName . ' is not set and ' . $fileName . ' is ' . $state . '. '
            . 'Provide a token with one of: (1) --token=<value>, '
            . '(2) export ' . $envName . '=<value>, '
            . '(3) write the token to ' . $fileName . ' in the project root.';
        if (is_string($rootDiag['basename'] ?? null) && $rootDiag['basename'] !== '') {
            $message .= ' Project root searched: "' . $rootDiag['basename'] . '" (resolved via ' . (string)($rootDiag['source'] ?? '?') . ').';
        }
        echo json_encode([
            'ok'       => false,
            'error'    => 'unauthorized',
            'reason'   => 'missing',
            'message'  => $message,
            'sources'  => $sources,
            'key_file' => [
                'name'      => (string)($fileDiag['name'] ?? $fileName),
                'exists'    => (bool)($fileDiag['exists'] ?? false),
                'read_ok'   => (bool)($fileDiag['read_ok'] ?? false),
                'empty'     => $fileDiag['empty'] ?? null,
                'had_bom'   => (bool)($fileDiag['had_bom'] ?? false),
                'read_error'=> $fileDiag['read_error'] ?? null,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(3);
    }
    // A token WAS supplied (flag / env / file) but did not match the
    // configured one. Never echo either value.
    echo json_encode([
        'ok'      => false,
        'error'   => 'unauthorized',
        'reason'  => 'mismatch',
        'message' => 'the admin token from `' . $tokenSource . '` does not match the configured one '
            . '(checked against ' . $envName . ' / ' . $fileName . ')',
        'sources' => $sources,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(3);
}

$app   = new Application($root);
$cache = ModelResponseCache::fromConfig($app->database(), $root);
$info  = $cache->backendInfo();

if (!$info['database'] && !$info['filesystem']) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'no_cache_backend',
        'message' => 'neither the database table nor the filesystem root is reachable',
        'backends' => $info,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(4);
}

$dryRun = array_key_exists('status', $options) || !array_key_exists('yes', $options);
$before = $cache->summary();

if ($dryRun) {
    echo json_encode([
        'ok'             => true,
        'dry_run'        => true,
        'older_than_days'=> $olderThanDays,
        'token_source'   => $tokenSource,
        'before'         => $before,
        'backends'       => $info,
        'hint'           => 'pass --yes to delete',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$purged = $cache->purge($olderThanDays > 0 ? $olderThanDays * 86400 : 0);
$after  = $cache->summary();

echo json_encode([
    'ok'              => true,
    'dry_run'         => false,
    'older_than_days' => $olderThanDays,
    'token_source'    => $tokenSource,
    'deleted'         => $purged,
    'before'          => $before,
    'after'           => $after,
    'cache_version'   => ModelResponseCache::VERSION,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
