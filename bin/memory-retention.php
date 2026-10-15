<?php
/**
 * bin/memory-retention.php - CLI retention for the vector memory (Task 5).
 *
 * Deletes memory_items rows older than the retention window via
 * PdoMemoryRepository::deleteOlderThan(). Retention is a maintenance
 * operation and intentionally MUST NOT run inside a web request; this
 * command is the single supported entry point. Schedule it with cron:
 *
 *   30 3 * * * /usr/bin/php /var/www/revoap.com/bin/memory-retention.php --yes
 *
 * Authentication: the operator must present the configured admin token
 * (the same sources AdminAuth uses: the configured env var, .admin.key
 * file or admin.token config). The token is accepted via --token=... or
 * the configured env var (admin.token_env, default REVOAP_ADMIN_TOKEN)
 * and compared with hash_equals(); the configured token is never echoed.
 * Note: prefer the environment variable over --token= where possible,
 * because a literal command-line argument may appear in process
 * listings (ps).
 *
 * Modes:
 *   --days=N      retention window in days (default memory.retention_days, 90)
 *   --status      dry-run report only (count, oldest row); no deletes
 *   --yes         actually delete; without this flag the command stays dry-run
 *   --token=...   admin token (alternative: REVOAP_ADMIN_TOKEN env)
 *   --help        usage
 *
 * Exit codes:
 *   0  success
 *   1  generic failure
 *   2  invoked from a web context
 *   3  admin token missing / not configured / mismatch
 *   4  database unavailable (no PDO connection)
 *   5  invalid retention window
 *
 * Output: one JSON document on STDOUT; PHP warnings go to STDERR.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

// Refuse to run from the web SAPI: retention is a maintenance command.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'cli_only', 'message' => 'memory-retention.php must be run from the CLI']);
    exit(2);
}

// Route every notice/warning to STDERR so STDOUT stays pure JSON.
// Deprecation notices are skipped: on PHP 8.5 they are environment noise
// from third-party code (e.g. PDO constants in config.php), not failures.
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
        'error'   => 'retention_failed',
        'class'   => get_class($e),
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
    echo "\n";
    exit(1);
});

$root = dirname(__DIR__);

// Bootstrap autoloader: Composer wins when vendor/ is deployed,
// otherwise fall back to the bundled PSR-4 loader.
$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require $root . '/src/autoload.php';
}

use App\Core\AdminAuth;
use App\Core\Application;
use App\Core\Config;
use App\Core\Logger;
use App\Memory\PdoMemoryRepository;

Config::load($root . '/config.php', $root);
AdminAuth::setRootDir($root);

$options = getopt('', ['days:', 'token:', 'status', 'yes', 'help']);

if (isset($options['help'])) {
    echo <<<TXT
memory-retention.php - delete vector-memory items older than N days.

Usage:
  php bin/memory-retention.php [--days=N] [--status] [--yes] [--token=...]

Options:
  --days=N      retention window in days (default memory.retention_days, 90)
  --status      dry-run report only (count, oldest row); no rows are deleted
  --yes         actually delete rows; without this flag the run stays dry-run
  --token=...   admin token (alternative: REVOAP_ADMIN_TOKEN environment
                variable; prefers the token file / env used by the web UI)
  --help        show this help

Exit codes: 0 ok, 1 generic, 2 web-context, 3 unauthorized, 4 no database,
5 invalid window.

TXT;
    exit(0);
}

// Validate the window BEFORE touching authentication, so a typo never
// silently turns into a destructive full-history wipe.
$days = isset($options['days'])
    ? (int)$options['days']
    : (int)Config::get('memory.retention_days', 90);
if ($days <= 0) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'invalid_retention_days',
        'message' => 'retention window must be a positive number of days',
        'days'    => $days,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(5);
}

// Authorize with the same token sources the web UI uses. The configured
// token is never printed; comparison happens via AdminAuth::verifyToken()
// (hash_equals) so CLI and web share one verification path.
$token = isset($options['token'])
    ? trim((string)$options['token'])
    : trim((string)getenv(AdminAuth::envName()));
if (!AdminAuth::verifyToken($token)) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'unauthorized',
        'message' => 'admin token missing or does not match the configured one',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(3);
}

$pdo = (new Application($root))->pdo();
if ($pdo === null) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'database_unavailable',
        'message' => 'no database connection; nothing was deleted',
        'hint'    => 'inspect database.* in config.php, then retry',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(4);
}

$repo       = new PdoMemoryRepository($pdo);
$modeStatus = array_key_exists('status', $options);
$dryRun     = $modeStatus || !array_key_exists('yes', $options);
$before     = $repo->count();
$threshold  = new DateTimeImmutable('now - ' . $days . ' days');

$deleted = 0;
if (!$dryRun) {
    $deleted = $repo->deleteOlderThan($threshold);
    Logger::info('memory.retention.completed', [
        'days'    => $days,
        'deleted' => $deleted,
        'before'  => $before,
    ]);
}

$remaining = $repo->count();
$oldest    = $repo->oldest();

echo json_encode([
    'ok'        => true,
    'dry_run'   => $dryRun,
    'days'      => $days,
    'threshold' => $threshold->format('Y-m-d H:i:s'),
    'before'    => $before,
    'deleted'   => $deleted,
    'remaining' => $remaining,
    'oldest'    => $oldest,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
