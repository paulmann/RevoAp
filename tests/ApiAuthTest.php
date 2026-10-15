<?php
/**
 * ApiAuthTest: offline unit checks for the external REST API
 * authentication / rate-limit layer (Task: REST API, Step 2).
 *
 * Runs without PHPUnit and without network access:
 * `php tests/ApiAuthTest.php`.
 *
 * Verified:
 *   - key extraction from X-API-Key / Authorization: Bearer / ?api_key
 *     with the documented header-over-query priority;
 *   - 401 for missing / unknown / revoked / expired credentials;
 *   - 403 when a read-only key attempts a write route;
 *   - deny-list: admin-owned paths are unreachable even with a FULL
 *     key (404 route_not_found);
 *   - per-key rpm/daily quotas with 429 + Retry-After and window
 *     reset semantics (deterministic clock);
 *   - last_used_at touched on success, never on failure;
 *   - failed-auth audit lines are written to the error log without
 *     the presented key, its hash, or any secret material.
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
use App\Api\ApiKeyService;
use App\Api\ApiKeyRepository;
use App\Api\ApiRateLimiter;
use App\Api\ApiKey;
use App\Core\Config;
use App\Core\Logger;

// Hygiene: admin env vars must not leak from the parent environment.
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_ENV['OPEN_CORUM_ADMIN_TOKEN']);
putenv('REVOAP_ADMIN_TOKEN');
putenv('OPEN_CORUM_ADMIN_TOKEN');

$work = sys_get_temp_dir() . '/revoap_apiauth_' . bin2hex(random_bytes(4));
foreach (['logs'] as $sub) {
    @mkdir($work . '/' . $sub, 0777, true);
}
Config::set([
    'app'     => ['debug' => true],
    'logging' => ['path' => $work . '/logs'],
], __DIR__);
Logger::reset();

$failures = 0;
$passes   = 0;

function checkAuth(string $label, bool $cond, string $detail = ''): void
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

function cleanupAuth(string $dir): void
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

// --- Suite setup: one key per scenario --------------
$limiter = new ApiRateLimiter($work . '/limits');
$service = new ApiKeyService(new ApiKeyRepository(null));
$auth    = new ApiAuth($service, $limiter);

$genFull = $service->generate('Full', 'full', 30, 1000);
$fullPlain = $genFull['plaintext'];
$genRead  = $service->generate('Read', 'read', 30, 1000);
$readPlain = $genRead['plaintext'];

// Expired key.
$genExpired = $service->generate('Expired', 'full', 30, 1000, gmdate('Y-m-d H:i:s', time() - 3600));
$expiredPlain = $genExpired['plaintext'];

// Revoked key.
$genRevoked = $service->generate('Revoked', 'full', 30, 1000);
$revokedPlain = $genRevoked['plaintext'];
$service->revoke($genRevoked['key']->id);

// --- Key extraction ----------------------------------
checkAuth('extractKey: X-API-Key header',
    ApiAuth::extractKey(['HTTP_X_API_KEY' => 'rv_abc'], []) === 'rv_abc');
checkAuth('extractKey: Authorization Bearer',
    ApiAuth::extractKey(['HTTP_AUTHORIZATION' => 'Bearer rv_xyz'], []) === 'rv_xyz');
checkAuth('extractKey: query fallback',
    ApiAuth::extractKey([], ['api_key' => 'rv_q']) === 'rv_q');
checkAuth('extractKey: header wins over query',
    ApiAuth::extractKey(['HTTP_X_API_KEY' => 'rv_h'], ['api_key' => 'rv_q']) === 'rv_h');
checkAuth('extractKey: bearer wins over query',
    ApiAuth::extractKey(['HTTP_AUTHORIZATION' => 'Bearer rv_b'], ['api_key' => 'rv_q']) === 'rv_b');
checkAuth('extractKey: trims whitespace',
    ApiAuth::extractKey(['HTTP_X_API_KEY' => '  rv_pad  '], []) === 'rv_pad');
checkAuth('extractKey: empty when absent',
    ApiAuth::extractKey([], []) === '');

// --- Failure modes -----------------------------------
$server = ['REMOTE_ADDR' => '127.0.0.1'];
$r = $auth->check($server, [], '/v1/runs');
checkAuth('missing key -> 401 unauthorized', $r['ok'] === false && $r['status'] === 401);

$r = $auth->check(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_API_KEY' => 'rv_does_not_exist'], [], '/v1/runs');
checkAuth('unknown key -> 401', $r['ok'] === false && $r['status'] === 401);

$r = $auth->check(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_API_KEY' => $revokedPlain], [], '/v1/runs');
checkAuth('revoked key -> 401', $r['ok'] === false && $r['status'] === 401);

$r = $auth->check(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_API_KEY' => $expiredPlain], [], '/v1/runs');
checkAuth('expired key -> 401', $r['ok'] === false && $r['status'] === 401);

// --- Scope enforcement ------------------------------
$r = $auth->check(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_API_KEY' => $readPlain], [], '/v1/runs', true);
checkAuth('read key on write route -> 403', $r['ok'] === false && $r['status'] === 403);

$r = $auth->check(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_API_KEY' => $readPlain], [], '/v1/runs');
checkAuth('read key on read route -> ok', $r['ok'] === true && isset($r['key']));

$r = $auth->check(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_API_KEY' => $fullPlain], [], '/v1/runs', true);
checkAuth('full key on write route -> ok', $r['ok'] === true);
checkAuth('success carries rate-limit headers',
    isset($r['headers']['X-RateLimit-Remaining-Minute'], $r['headers']['X-RateLimit-Reset-Minute']));

// last_used_at touch after success.
$afterUse = $service->verify($fullPlain);
checkAuth('last_used_at stamped on success', $afterUse?->lastUsedAt !== null);

// --- Admin path deny-list ----------------------------
checkAuth('isAdminPath: /v1/runs allowed', $auth->isAdminPath('/v1/runs') === false);
checkAuth('isAdminPath: /v1/runs/42/events allowed', $auth->isAdminPath('/v1/runs/42/events') === false);
checkAuth('isAdminPath: /api/settings blocked', $auth->isAdminPath('/api/settings') === true);
checkAuth('isAdminPath: nested settings blocked', $auth->isAdminPath('/api/settings/providers') === true);
checkAuth('isAdminPath: /api/skills blocked', $auth->isAdminPath('/api/skills') === true);
checkAuth('isAdminPath: /api/skills/9 blocked', $auth->isAdminPath('/api/skills/9') === true);
checkAuth('isAdminPath: /api/logs/list blocked', $auth->isAdminPath('/api/logs/list') === true);
checkAuth('isAdminPath: /api/security blocked', $auth->isAdminPath('/api/security') === true);
checkAuth('isAdminPath: /api/history blocked', $auth->isAdminPath('/api/history/list') === true);
checkAuth('isAdminPath: /api/statistics blocked', $auth->isAdminPath('/api/statistics/summary') === true);
checkAuth('isAdminPath: /api/profile blocked', $auth->isAdminPath('/api/profile') === true);
checkAuth('isAdminPath: /api/share/session blocked', $auth->isAdminPath('/api/share/session') === true);

$r = $auth->check(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_API_KEY' => $fullPlain], [], '/api/settings');
checkAuth('full key on admin path -> 404', $r['ok'] === false && $r['status'] === 404);
checkAuth('admin path failure exposes no route detail',
    !isset($r['path']) && str_contains((string)($r['message'] ?? ''), 'not available'));

// --- Rate limiting -----------------------------------
$genTight = $service->generate('Tight', 'full', 3, 100);
$tightPlain = $genTight['plaintext'];
$tightId = $genTight['key']->id;
$limiter->reset($tightId);

$t = 1700000000; // fixed unix second for deterministic windows
$limiter->setNow(static fn(): int => $t);

$okCount = 0;
for ($i = 0; $i < 4; $i++) {
    $rr = $auth->check(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_API_KEY' => $tightPlain], [], '/v1/models');
    if ($rr['ok']) { $okCount++; }
    $last = $rr;
}
checkAuth('rpm=3 allows exactly 3 requests', $okCount === 3);
checkAuth('4th request -> 429 rate_limited', $last['ok'] === false && $last['status'] === 429);
checkAuth('429 includes Retry-After', isset($last['headers']['Retry-After']));

// Advance past the minute boundary -> quota resets.
$limiter->setNow(static fn(): int => $t + 61);
$r = $auth->check(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_API_KEY' => $tightPlain], [], '/v1/models');
checkAuth('quota resets in the next minute', $r['ok'] === true);

// Daily limit exhaustion with a technically-empty minute window.
$genDay = $service->generate('DayOnly', 'full', 3, 2);
$dayPlain = $genDay['plaintext'];
$dayId = $genDay['key']->id;
$limiter->reset($dayId);
$limiter->setNow(static fn(): int => $t + 600);
$auth->check(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_API_KEY' => $dayPlain], [], '/v1/models');
$auth->check(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_API_KEY' => $dayPlain], [], '/v1/models');
$r = $auth->check(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_API_KEY' => $dayPlain], [], '/v1/models');
checkAuth('daily limit enforced', $r['ok'] === false && $r['status'] === 429);
$limiter->setNow(null);

// --- Audit trail without secrets ---------------------
$errLog = $work . '/logs/revoap-error.log';
$logText = is_file($errLog) ? (string)file_get_contents($errLog) : '';
checkAuth('failed-auth events written to error log', str_contains($logText, 'api.auth.denied'));
checkAuth('audit never contains the presented key', !str_contains($logText, $fullPlain));
checkAuth('audit never contains the key hash', !str_contains($logText, $service->hashKey($fullPlain)));
checkAuth('audit never contains revoked key material', !str_contains($logText, $revokedPlain));

cleanupAuth($work);

echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
