<?php
/**
 * bin/usage-ledger-backfill.php — converge the usage ledger with its source
 * rows (v2.1.0, T5).
 *
 * The ledger is billed in-process: SessionRepository's settle hook calls
 * UsageLedger::syncSession() whenever a run reaches a terminal status, and
 * the worker sweeps a bounded syncMissing() after every job. This command is
 * the manual/cron tool for the two cases those do not cover:
 *
 *   1. FIRST RUN after applying migration 024 — every settled session in
 *      history is unbilled and needs one full derivation pass.
 *   2. A crash window so wide the worker sweep has not caught up (or an
 *      installation whose worker is not running).
 *
 * The command is APPEND-ONLY and IDEMPOTENT: it derives rows from
 * council_model_responses under the ledger's UNIQUE (session_id,
 * response_id) key, so re-running it — or running it while the web/worker
 * paths are live — cannot duplicate or alter a bill. There is no destructive
 * mode and therefore no --yes gate; the admin token is required when one is
 * configured (same sources as AdminAuth) and skipped, with a note in the
 * output, on an install that has none.
 *
 *   php bin/usage-ledger-backfill.php --status        # pending report only
 *   php bin/usage-ledger-backfill.php                 # one sweep (500)
 *   php bin/usage-ledger-backfill.php --loop          # until converged
 *   php bin/usage-ledger-backfill.php --limit=2000 --loop
 *
 * Exit codes: 0 success · 1 generic failure · 2 web context ·
 *             3 unauthorized · 4 database unavailable · 5 ledger unmigrated
 *
 * Output: one JSON document on STDOUT; PHP notices go to STDERR.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'cli_only', 'message' => 'usage-ledger-backfill.php must be run from the CLI']);
    exit(2);
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    if (($errno & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
        return true;
    }
    fwrite(STDERR, sprintf("[php:%d] %s at %s:%d\n", $errno, $errstr, $errfile, $errline));
    return true;
});
set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, sprintf("[exception:%s] %s\n", get_class($e), $e->getMessage()));
    echo json_encode([
        'error'   => 'backfill_failed',
        'class'   => get_class($e),
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
    echo "\n";
    exit(1);
});

$root = dirname(__DIR__);

$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require $root . '/src/autoload.php';
}

use App\Core\AdminAuth;
use App\Core\Application;
use App\Core\Config;
use App\Usage\UsageLedger;

Config::load($root . '/config.php', $root);
AdminAuth::setRootDir($root);

$options = getopt('', ['status', 'loop', 'limit:', 'token:', 'help']);

if (isset($options['help'])) {
    echo <<<TXT
usage-ledger-backfill.php - derive revoap_usage_ledger rows for settled
sessions that have none. Append-only and idempotent; safe to re-run and
safe to run while the web/worker paths are live.

Usage:
  php bin/usage-ledger-backfill.php [--status] [--loop] [--limit=N] [--token=...]

Options:
  --status      report the pending count only; write nothing
  --loop        sweep repeatedly until the ledger is converged
  --limit=N     sessions per sweep (default 500, max 5000)
  --token=...   admin token; alternative: the configured env var
                (REVOAP_ADMIN_TOKEN). Required only when the install has
                an admin token configured.
  --help        show this help

Exit codes: 0 ok, 1 generic, 2 web-context, 3 unauthorized,
4 no database, 5 ledger table missing (apply migration 024 first).

TXT;
    exit(0);
}

// Authorize with the same token sources the web UI uses — but only when the
// install HAS a token. An append-only derivation on an unconfigured host is
// not a privileged operation; refusing there would block the first-run
// backfill on exactly the fresh installs that need it most.
$tokenMode = 'not_configured';
if (AdminAuth::isConfigured()) {
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
    $tokenMode = 'verified';
}

$pdo = (new Application($root))->pdo();
if ($pdo === null) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'database_unavailable',
        'message' => 'no database connection; nothing was written',
        'hint'    => 'inspect database.* in config.php, then retry',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(4);
}

$ledger = new UsageLedger($pdo);
if (!$ledger->tableAvailable()) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'ledger_unmigrated',
        'message' => 'revoap_usage_ledger does not exist; apply db/migrations/024_usage_ledger.sql (Settings → Migrations → Apply) first',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(5);
}

$limit = isset($options['limit']) ? max(1, min(5000, (int)$options['limit'])) : UsageLedger::SYNC_BATCH;
$statusOnly = array_key_exists('status', $options);
$loop = array_key_exists('loop', $options);

if ($statusOnly) {
    // Pending = settled sessions with no ledger rows. One indexed query;
    // the same LEFT JOIN syncMissing() uses, so the number shown is exactly
    // the work a sweep would do.
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM council_sessions cs
         LEFT JOIN ' . UsageLedger::TABLE . ' l ON l.session_id = cs.id
         WHERE cs.status IN (\'completed\', \'failed\') AND l.id IS NULL'
    );
    $pending = (int)($stmt !== false ? $stmt->fetchColumn() : 0);
    echo json_encode([
        'ok'       => true,
        'mode'     => 'status',
        'token'    => $tokenMode,
        'pending'  => $pending,
        'message'  => $pending === 0
            ? 'ledger is converged with the source rows'
            : "run without --status to derive {$pending} pending session(s)",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$sweeps = 0;
$totalSynced = 0;
$hardStop = $loop ? 10_000 : 1;   // a --loop that cannot terminate is a cron bomb
do {
    $synced = $ledger->syncMissing($limit);
    $totalSynced += $synced;
    $sweeps++;
} while ($loop && $synced >= $limit && $sweeps < $hardStop);

echo json_encode([
    'ok'        => true,
    'mode'      => $loop ? 'loop' : 'single',
    'token'     => $tokenMode,
    'sweeps'    => $sweeps,
    'limit'     => $limit,
    'synced'    => $totalSynced,
    'complete'  => $loop ? $sweeps < $hardStop : $totalSynced < $limit,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
