<?php
/**
 * ObservabilityTest: offline checks for the always-on error log (Task 4).
 *
 * Runs without PHPUnit and - crucially - without any real database or
 * network access, so it can be executed as `php tests/ObservabilityTest.php`
 * on any host with PHP 8.4+.
 *
 * Verified:
 *   - Logger::warn()/error() write JSON lines to {logs}/revoap-error.log
 *     even when app.debug=false;
 *   - Logger::info() stays silent when app.debug=false (legacy behavior);
 *   - a forced failure through Application::database() (unreachable DSN with
 *     a 1-second connect timeout) lands in revoap-error.log - the same
 *     production path a dead MySQL would take;
 *   - error log lines never contain request texts, model answers, API keys,
 *     DSN passwords or Bearer tokens (Logger::sanitize applied);
 *   - LogsService whitelist exposes the new revoap-error slug (the legacy
 *     opencorum-error alias still resolves) and tail() stays sanitized.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Application;
use App\Core\Config;
use App\Core\Logger;
use App\Logs\LogsService;

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

$tmpRoot = sys_get_temp_dir() . '/oc-observability-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/logs', 0o775, true);

// app.debug=false + isolated logging directory.
Config::set([
    'app'      => ['name' => 'RevoAp', 'version' => '1.0.0', 'debug' => false],
    'logging'  => ['path' => $tmpRoot . '/logs'],
    'database' => [
        'dsn'      => 'mysql:host=127.0.0.1;port=9;dbname=nope',
        'user'     => 'u',
        'password' => 'topsecretpw',
        'options'  => [PDO::ATTR_TIMEOUT => 1],
    ],
], $tmpRoot);
Logger::reset();

echo "== Task 4: Logger error levels are always on ==\n";
check('Logger::enabled() stays false when debug=false', Logger::enabled() === false);

$app = new Application($tmpRoot);
$db  = $app->database();
check('forced DB failure degrades to null', $db === null);

$errPath = $tmpRoot . '/logs/revoap-error.log';
$errBody = is_file($errPath) ? (string)file_get_contents($errPath) : '';
check('revoap-error.log exists after warn at debug=false', $errBody !== '');
check('error log contains the failure tag', str_contains($errBody, 'Database connection failed'));
check('error log line is valid JSON', (function (string $s): bool {
    $first = trim((string)strtok($s, "\n"));
    if ($first === '') {
        return false;
    }
    $decoded = json_decode($first, true);
    return is_array($decoded) && ($decoded['level'] ?? '') === 'warn';
})($errBody));

$dbgPath = $tmpRoot . '/logs/revoap-debug.log';
check('debug log is NOT created when debug=false', !is_file($dbgPath));

// info() must remain gated; warn()/error() must pass through.
Logger::info('should.not.land', ['x' => 1]);
Logger::warn('warn.always.lands', ['ok' => 1]);
Logger::error('error.always.lands', ['ok' => 1]);
$errBody = (string)file_get_contents($errPath);
check('info() did not reach the error log', !str_contains($errBody, 'should.not.land'));
check('warn() reached the error log', str_contains($errBody, 'warn.always.lands'));
check('error() reached the error log', str_contains($errBody, 'error.always.lands'));

// Secret leakage: request text, model answer, API keys, DSN, Bearer.
Logger::error('provider.failure.probe', [
    'query'         => 'user request body that must never leak',
    'answer'        => 'model response body that must never leak',
    'api_key'       => 'sk-live-probe-1234567890ABCDEF',
    'dsn'           => 'mysql:host=db.example;dbname=app;password=hunter2',
    'authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.probe',
]);
$leakProbe = (string)file_get_contents($errPath);
check('request text is never logged', !str_contains($leakProbe, 'user request body that must never leak'));
check('model answer is never logged', !str_contains($leakProbe, 'model response body that must never leak'));
check('sk- API key is redacted', !str_contains($leakProbe, 'sk-live-probe-1234567890ABCDEF') && str_contains($leakProbe, '"api_key":"***redacted***"'));
check('DSN password is redacted', !str_contains($leakProbe, 'hunter2') && str_contains($leakProbe, '"dsn":"***redacted***"'));
check('Bearer token is redacted', !str_contains($leakProbe, 'eyJhbGciOiJIUzI1NiJ9.probe') && str_contains($leakProbe, '"authorization":"***redacted***"'));

echo "\n== Task 4: LogsService exposes the error log ==\n";
$svc = new LogsService($tmpRoot);
$wl  = $svc->whitelist();
check('whitelist contains revoap-error', isset($wl['revoap-error']) && ($wl['revoap-error']['filename'] ?? '') === 'revoap-error.log');
check('legacy opencorum-error alias resolves', $svc->resolve('opencorum-error') !== null);
$tail = $svc->tail('revoap-error', 10);
check('tail returns the error log', is_array($tail) && ($tail['exists'] ?? false) === true && ($tail['lines'] ?? 0) > 0);
$content = is_array($tail) ? (string)($tail['content'] ?? '') : '';
check('tail output is sanitized', !str_contains($content, 'hunter2') && !str_contains($content, 'sk-live-probe'));

// Cleanup.
@unlink($errPath);
@unlink($dbgPath);
@rmdir($tmpRoot . '/logs');
@rmdir($tmpRoot);
Logger::reset();

echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
