<?php
/**
 * bin/export-worker-config.php — emit the merged RevoAp runtime
 * configuration as a single JSON document for the future Worker
 * Service to consume on startup.
 *
 * The script is CLI-only: it is intentionally not registered as a web
 * route and refuses to run when SAPI is not `cli`. PHP warnings are
 * routed to STDERR via the error-handler installed at the top so that
 * the JSON document on STDOUT is never polluted — operators (and the
 * worker) can pipe directly into `jq` / a JSON parser.
 *
 * Modes:
 *   --safe     (default)  Strip API keys, tokens, passwords and any
 *                         secret-like leaves. Safe to log / commit.
 *   --secrets             Include connector API keys, admin token
 *                         file path, and database DSN with password.
 *                         CLI-only; refuses to run from the web SAPI.
 *
 * Exit codes:
 *   0  success
 *   1  configuration unavailable or unparseable
 *   2  invoked from a web context
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

// Refuse to run from the web SAPI. The exporter is intentionally not
// exposed via HTTP — its purpose is to hand a config envelope to a
// local worker process that lives on the same host.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'cli_only', 'message' => 'export-worker-config.php must be run from the CLI']);
    exit(2);
}

// Route every notice/warning to STDERR so STDOUT is pure JSON.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    fwrite(STDERR, sprintf("[php:%d] %s at %s:%d\n", $errno, $errstr, $errfile, $errline));
    return true;
});
set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, sprintf("[exception:%s] %s\n", get_class($e), $e->getMessage()));
    echo json_encode([
        'error'   => 'export_failed',
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

$includeSecrets = false;
foreach ($argv as $arg) {
    if ($arg === '--secrets' || $arg === '-s') { $includeSecrets = true; }
    if ($arg === '--safe' || $arg === '-S')    { $includeSecrets = false; }
}

\App\Core\Config::load($root . '/config.php', $root);

$config = [
    'app' => [
        'name'    => (string)\App\Core\Config::get('app.name', 'RevoAp'),
        'version' => (string)\App\Core\Config::get('app.version', '0.0.0'),
        'url'     => (string)\App\Core\Config::get('app.url', ''),
        'debug'   => (bool)\App\Core\Config::get('app.debug', false),
        'timezone'=> (string)\App\Core\Config::get('app.timezone', 'UTC'),
    ],
    'root' => $root,
    'paths' => [
        'config_php'        => $root . '/config.php',
        'overlay_php'       => $root . '/.config.php',
        'url_override'      => $root . '/.url',
        'sql_override'      => $root . '/.sql.php',
        'admin_token_file'  => $root . '/.admin.key',
        'system_prompt_dir' => $root . '/prompts/system',
        'logs_dir'          => $root . '/logs',
    ],
    'database' => [
        'dsn'      => (string)\App\Core\Config::get('database.dsn', ''),
        'user'     => (string)\App\Core\Config::get('database.user', ''),
        'password' => $includeSecrets ? (string)\App\Core\Config::get('database.password', '') : '',
    ],
    'cache' => [
        'model_responses' => [
            'enabled'     => (bool)\App\Core\Config::get('cache.model_responses.enabled', true),
            'ttl_seconds' => (int)\App\Core\Config::get('cache.model_responses.ttl_seconds', 0),
        ],
    ],
    'pipeline' => [
        'synthesis_provider'     => (string)\App\Core\Config::get('pipeline.synthesis_provider', ''),
        'synthesis_model'        => (string)\App\Core\Config::get('pipeline.synthesis_model', ''),
        'parallel_l1'            => (bool)\App\Core\Config::get('pipeline.parallel_l1', true),
        'max_consensus_rounds'   => (int)\App\Core\Config::get('pipeline.max_consensus_rounds', 2),
        'cycle_limit_min'        => (int)\App\Core\Config::get('pipeline.cycle_limit_min', 1),
        'cycle_limit_max'        => (int)\App\Core\Config::get('pipeline.cycle_limit_max', 5),
        'round2_score_threshold' => (float)\App\Core\Config::get('pipeline.round2_score_threshold', 0.85),
        'max_roles_per_skill'    => (int)\App\Core\Config::get('pipeline.max_roles_per_skill', 12),
        'default_selected_models'=> (array)\App\Core\Config::get('pipeline.default_selected_models', []),
        'conflict_arbiter'       => (array)\App\Core\Config::get('pipeline.conflict_arbiter', []),
        'consensus'              => (array)\App\Core\Config::get('pipeline.consensus', []),
    ],
    'reasoning' => (array)\App\Core\Config::get('reasoning', []),
    'session'   => (array)\App\Core\Config::get('session', []),
    'admin'     => [
        'token_env'  => (string)\App\Core\Config::get('admin.token_env', 'REVOAP_ADMIN_TOKEN'),
        'token_file' => (string)\App\Core\Config::get('admin.token_file', '.admin.key'),
    ],
    'models'    => (array)\App\Core\Config::get('models', []),
];

// Connectors — strip api_key when running in safe mode (the default).
$connectors = (array)\App\Core\Config::get('connectors', []);
$exportConnectors = [];
foreach ($connectors as $name => $row) {
    if (!is_array($row)) { continue; }
    $entry = [
        'active'        => (bool)($row['active'] ?? false),
        'base_url'      => (string)($row['base_url'] ?? ''),
        'timeout_chat'  => (int)($row['timeout_chat'] ?? 0),
        'timeout_embed' => (int)($row['timeout_embed'] ?? 0),
        'max_retries'   => (int)($row['max_retries'] ?? 0),
        'has_api_key'   => false,
    ];
    $resolvedKey = '';
    try {
        $resolvedKey = (string)\App\Core\Config::getConnectorKey((string)$name);
    } catch (\Throwable) {
        $resolvedKey = trim((string)($row['api_key'] ?? ''));
    }
    $entry['has_api_key'] = $resolvedKey !== '';
    if ($includeSecrets && $resolvedKey !== '') {
        $entry['api_key'] = $resolvedKey;
    }
    $exportConnectors[(string)$name] = $entry;
}
$config['connectors'] = $exportConnectors;

// Mode + provenance for the worker.
$config['_meta'] = [
    'exporter_version' => '1.0.0',
    'phase'            => 1,
    'secrets_mode'     => $includeSecrets,
    'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
];

echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo "\n";
exit(0);
