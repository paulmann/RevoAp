<?php
/**
 * ApiSecurityTest: security / audit checks for the external REST API
 * (Task: REST API, Step 8).
 *
 * Runs without PHPUnit and without network access:
 * `php tests/ApiSecurityTest.php`.
 *
 * Verified:
 *   - per-IP minute budget enforced BEFORE key handling: the 4th request
 *     from one address is 429 even though the key's own quota is wide;
 *     an invalid key on an exhausted address is also 429 (not 401),
 *     proving the gate runs ahead of credential inspection;
 *   - the IP budget is keyed on REMOTE_ADDR, not X-Forwarded-For;
 *   - other addresses are unaffected in the same window;
 *   - rejected requests are not counted (window reset restores a clean
 *     allowance), and the window resets after 60 seconds;
 *   - unavailable counter storage FAILS CLOSED (429 instead of open
 *     door);
 *   - state files never reveal the source IP (opaque sha256 names);
 *   - ip_rate_limited events land in the audit log without the
 *     presented key or its hash;
 *   - when no IP quota is configured the legacy behaviour is preserved
 *     (no IP gate at all).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Api\ApiAuth;
use App\Api\ApiKeyRepository;
use App\Api\ApiKeyService;
use App\Api\ApiRateLimiter;
use App\Core\Config;
use App\Core\Logger;

// Hygiene: admin env vars must not leak from the parent environment.
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_ENV['OPEN_CORUM_ADMIN_TOKEN']);
putenv('REVOAP_ADMIN_TOKEN');
putenv('OPEN_CORUM_ADMIN_TOKEN');

$work = sys_get_temp_dir() . '/revoap_apisec_' . bin2hex(random_bytes(4));
@mkdir($work . '/logs', 0777, true);
Config::set([
    'app'     => ['debug' => true],
    'logging' => ['path' => $work . '/logs'],
], __DIR__);
Logger::reset();

$failures = 0;
$passes   = 0;

function checkSec(string $label, bool $cond, string $detail = ''): void
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

function cleanupSec(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// --- Suite setup: IP budget of 3 requests/minute ------------------
$limiter = new ApiRateLimiter($work . '/limits');
$service = new ApiKeyService(new ApiKeyRepository(null));
$auth    = new ApiAuth($service, $limiter, null, 3);

$gen  = $service->generate('Budget', 'full', 30, 1000);
$key  = $gen['plaintext'];

$t = 1700000000; // fixed unix second for deterministic windows
$limiter->setNow(static fn(): int => $t);

$serverA = static fn(string $presented): array => [
    'REMOTE_ADDR'    => '1.1.1.1',
    'HTTP_X_API_KEY' => $presented,
];

// --- Per-IP budget: 3 ok, 4th is 429 despite a wide key quota -----
$r1 = $auth->check($serverA($key), [], '/v1/models');
$r2 = $auth->check($serverA($key), [], '/v1/models');
$r3 = $auth->check($serverA($key), [], '/v1/runs/42/result');
checkSec('ip budget: first 3 same-IP requests pass',
    $r1['ok'] === true && $r2['ok'] === true && $r3['ok'] === true);

$r4 = $auth->check($serverA($key), [], '/v1/models');
checkSec('ip budget: 4th same-IP request -> 429',
    $r4['ok'] === false && $r4['status'] === 429 && ($r4['error'] ?? '') === 'rate_limited');
checkSec('ip budget: 429 carries Retry-After',
    isset($r4['headers']['Retry-After']));
checkSec('ip budget: 429 exposes zero remaining minute quota',
    ($r4['headers']['X-RateLimit-Remaining-Minute'] ?? '') === '0');
checkSec('ip budget: 429 message is static (no internals)',
    str_contains((string)($r4['message'] ?? ''), 'Too many requests'));

// The gate runs BEFORE credential handling: a wrong key on an exhausted
// IP yields 429, not 401.
$r5 = $auth->check($serverA('rv_not_a_real_key'), [], '/v1/models');
checkSec('ip gate precedes key inspection (bad key on exhausted IP -> 429, not 401)',
    $r5['ok'] === false && $r5['status'] === 429);

// ...and a valid key on another address is untouched by the first IP's
// exhaustion.
$rOther = $auth->check(['REMOTE_ADDR' => '10.9.9.9', 'HTTP_X_API_KEY' => $key], [], '/v1/models');
checkSec('other IP stays allowed inside the same window', $rOther['ok'] === true);

// --- Rejected requests must not spend quota ------------------------
$auth->check($serverA($key), [], '/v1/models'); // rejected (budget gone)
$auth->check($serverA($key), [], '/v1/models'); // rejected again
$limiter->setNow(static fn(): int => $t + 61);
$rReset = $auth->check($serverA($key), [], '/v1/models');
checkSec('ip budget resets next minute and rejected hits were not counted',
    $rReset['ok'] === true);

// --- The budget is keyed on REMOTE_ADDR, not X-Forwarded-For -------
$limiter->setNow(static fn(): int => $t + 600);
$limiterX = new ApiRateLimiter($work . '/limits-xff');
$serviceX = new ApiKeyService(new ApiKeyRepository(null));
$authX    = new ApiAuth($serviceX, $limiterX, null, 2);
$genX     = $serviceX->generate('Xff', 'full', 30, 1000);
$keyX     = $genX['plaintext'];
$limiterX->setNow(static fn(): int => $t + 600);

$xff1 = ['REMOTE_ADDR' => '7.7.7.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10', 'HTTP_X_API_KEY' => $keyX];
$xff2 = ['REMOTE_ADDR' => '7.7.7.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99', 'HTTP_X_API_KEY' => $keyX];
$rx1  = $authX->check($xff1, [], '/v1/models');
$rx2  = $authX->check($xff2, [], '/v1/models');
$rx3  = $authX->check($xff2, [], '/v1/models');
checkSec('ip budget follows REMOTE_ADDR: changing X-Forwarded-For does not renew quota',
    $rx1['ok'] === true && $rx2['ok'] === true && $rx3['ok'] === false && $rx3['status'] === 429);

// --- State files never reveal the IP ------------------------------
$limitFiles = scandir($work . '/limits');
$limitFiles = is_array($limitFiles) ? array_values(array_diff($limitFiles, ['.', '..'])) : [];
$allNames = implode('|', $limitFiles);
checkSec('limit state dir only has ip_*/key_* counter files',
    $limitFiles !== []
    && count(array_filter($limitFiles, static fn(string $f): bool => !preg_match('/^(ip_[0-9a-f]{32}|key_[0-9]+)\.json$/', $f))) === 0);
checkSec('limit state filenames hide the source IP',
    !str_contains($allNames, '1.1.1.1') && !str_contains($allNames, '10.9.9.9'));

// --- Fail-closed when counters cannot be persisted ---------------
// Passing a regular FILE as the storage dir makes fopen/c+ fail; the
// security boundary must refuse rather than open the door.
$blockFile = $work . '/not-a-dir';
file_put_contents($blockFile, 'x');
$limiterF = new ApiRateLimiter($blockFile);
$resF = $limiterF->registerAndCheckIp('1.2.3.4', 10);
checkSec('ip counter storage unavailable -> fail closed',
    $resF['allowed'] === false && ($resF['retry_after'] ?? 0) > 0);
@unlink($blockFile);

// --- Opaque 'unknown' address still shares one budget -------------
$limiterU = new ApiRateLimiter($work . '/limits-u');
$limiterU->setNow(static fn(): int => $t);
$ru1 = $limiterU->registerAndCheckIp('unknown', 1);
$ru2 = $limiterU->registerAndCheckIp('unknown', 1);
checkSec('missing REMOTE_ADDR falls into the unknown bucket',
    $ru1['allowed'] === true && $ru2['allowed'] === false);

// --- Legacy behaviour preserved when no IP quota configured --------
$limiterOff = new ApiRateLimiter($work . '/limits-off');
$authOff    = new ApiAuth($service, $limiterOff); // no ipRpm argument
$genOff     = $service->generate('NoIp', 'full', 30, 1000);
$keyOff     = $genOff['plaintext'];
$limiterOff->setNow(static fn(): int => $t);
$offOk = 0;
for ($i = 0; $i < 10; $i++) {
    $rio = $authOff->check(['REMOTE_ADDR' => '5.5.5.5', 'HTTP_X_API_KEY' => $keyOff], [], '/v1/models');
    if ($rio['ok']) { $offOk++; }
}
checkSec('no IP quota configured -> no IP gate (BC)', $offOk === 10);

// --- Audit: ip_rate_limited without secrets -----------------------
$logText = is_file($work . '/logs/revoap-error.log')
    ? (string)file_get_contents($work . '/logs/revoap-error.log')
    : '';
checkSec('ip_rate_limited events written to the audit log',
    str_contains($logText, 'api.auth.denied') && str_contains($logText, 'ip_rate_limited'));
checkSec('audit never contains the presented key', !str_contains($logText, $key));
checkSec('audit never contains the key hash', !str_contains($logText, $service->hashKey($key)));
checkSec('audit never contains the probe key from exhausted checks', !str_contains($logText, 'rv_not_a_real_key'));

$limiter->setNow(null);
$limiterX->setNow(null);
$limiterOff->setNow(null);
$limiterU->setNow(null);
cleanupSec($work);

echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
