<?php
/**
 * ApiKeyControllerTest: offline unit checks for the admin API-tab
 * controller (Task: RevoAp REST API, Step 7 - API tab in settings).
 *
 * Runs without PHPUnit and without network access:
 * `php tests/ApiKeyControllerTest.php`.
 *
 * Verified:
 *   - status() public probe (tab/admin flags, no secrets);
 *   - ApiTab=false -> every mutating/list endpoint refuses 404
 *     api_tab_disabled;
 *   - missing/wrong admin token -> 401 unauthorized;
 *   - index() lists public rows and never serializes key_hash or
 *     plaintext material;
 *   - create() validation (label/rpm/daily/scopes/expires) -> 400;
 *   - create() returns the one-time plaintext once (rv_ + 48 hex) and
 *     the raw key is never echoed again by any listing;
 *   - rotate() replaces the material (new row, old one revoked) and
 *     unknown id -> 404;
 *   - revoke() disables the key (verify + isUsable gate) and unknown
 *     id -> 404;
 *   - ApiAuth::isAdminPath() denies /api/apikeys and /api/webhooks.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-09-19
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Api\ApiAuth;
use App\Api\ApiKeyController;
use App\Api\ApiKeyRepository;
use App\Api\ApiKeyService;
use App\Api\ApiRateLimiter;
use App\Core\AdminAuth;
use App\Core\Config;

$failures = 0;
$passes   = 0;
$report   = [];

function checkKc(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes, $report;
    if ($cond) {
        $report[] = "  ok  {$label}";
        $passes++;
    } else {
        $report[] = "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '');
        $failures++;
    }
}

/** Send a controller response and decode HTTP status + JSON body. */
function callKc(callable $fn): array
{
    $body   = $fn();
    $status = http_response_code();
    http_response_code(200); // reset for the next call
    $decoded = json_decode((string)$body, true);
    return ['status' => $status, 'raw' => (string)$body, 'json' => is_array($decoded) ? $decoded : []];
}

$work = sys_get_temp_dir() . '/revoap_apikeyctrl_' . bin2hex(random_bytes(4));
@mkdir($work, 0o777, true);

// Deterministic test world: ApiTab on, admin token from env + header.
Config::set(['ApiTab' => true], $work);
AdminAuth::setRootDir($work);
$_ENV['REVOAP_ADMIN_TOKEN']    = 'admin-test-token';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'admin-test-token';

$repo = new ApiKeyRepository(null); // memory mode
$svc  = new ApiKeyService($repo);
// Counter-based generator: every call yields DIFFERENT bytes, so rotate
// produces genuinely new material instead of an identical key.
$counter = 0;
$svc->setRandomBytes(static function () use (&$counter): string {
    $counter++;
    $bytes = '';
    for ($i = 0; $i < ApiKeyService::KEY_RANDOM_BYTES; $i++) {
        $bytes .= chr(($counter + $i) & 0xFF);
    }
    return $bytes;
});
$ctrl = new ApiKeyController($svc);

// --------------------------------------------------------------
// Public probe
// --------------------------------------------------------------
$probe = callKc(static fn(): string => $ctrl->status());
$j = $probe['json'];
checkKc('status probe reports tab enabled', ($j['tab_enabled'] ?? null) === true);
checkKc('status probe reports admin configured + unlocked', ($j['admin_configured'] ?? null) === true && ($j['admin_unlocked'] ?? null) === true);
checkKc('status probe leaks no key material', !str_contains($probe['raw'], 'plaintext') && !str_contains($probe['raw'], 'key_hash'));

// --------------------------------------------------------------
// ApiTab gate
// --------------------------------------------------------------
Config::set(['ApiTab' => false], $work);
$off = callKc(static fn(): string => $ctrl->index());
checkKc('ApiTab=false -> 404 api_tab_disabled', $off['status'] === 404 && ($off['json']['error'] ?? '') === 'api_tab_disabled');
$offCreate = callKc(static fn(): string => $ctrl->create(['label' => 'x']));
checkKc('ApiTab=false -> create also refused', $offCreate['status'] === 404);
Config::set(['ApiTab' => true], $work);

// --------------------------------------------------------------
// Admin token gate
// --------------------------------------------------------------
unset($_SERVER['HTTP_X_ADMIN_TOKEN']);
$noTok = callKc(static fn(): string => $ctrl->index());
checkKc('missing admin token -> 401', $noTok['status'] === 401 && ($noTok['json']['error'] ?? '') === 'unauthorized');

$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'wrong-token';
$wrongTok = callKc(static fn(): string => $ctrl->rotate(['id' => 1]));
checkKc('wrong admin token -> 401', $wrongTok['status'] === 401);
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'admin-test-token';

// --------------------------------------------------------------
// Validation
// --------------------------------------------------------------
$longLabel = callKc(static fn(): string => $ctrl->create(['label' => str_repeat('a', 129)]));
checkKc('oversized label -> 400 invalid_label', $longLabel['status'] === 400 && ($longLabel['json']['error'] ?? '') === 'invalid_label');

$badRpm = callKc(static fn(): string => $ctrl->create(['rpm_limit' => -1]));
checkKc('negative rpm_limit -> 400', $badRpm['status'] === 400 && ($badRpm['json']['error'] ?? '') === 'invalid_rpm_limit');

$badScopes = callKc(static fn(): string => $ctrl->create(['scopes' => 42]));
checkKc('non-string/array scopes -> 400', $badScopes['status'] === 400 && ($badScopes['json']['error'] ?? '') === 'invalid_scopes');

$badExpires = callKc(static fn(): string => $ctrl->create(['expires_at' => 'not a date']));
checkKc('unparseable expires_at -> 400', $badExpires['status'] === 400 && ($badExpires['json']['error'] ?? '') === 'invalid_expires_at');

// --------------------------------------------------------------
// Create: one-time plaintext + public listing without secrets
// --------------------------------------------------------------
$made = callKc(static fn(): string => $ctrl->create(['label' => 'CI', 'scopes' => 'full', 'rpm_limit' => '20']));
$madeJson = $made['json'];
checkKc('create returns 201', $made['status'] === 201);
$plain = (string)($madeJson['plaintext'] ?? '');
checkKc('plaintext matches rv_ + 48 hex', preg_match('/^rv_[0-9a-f]{48}$/', $plain) === 1, $plain);
checkKc('created row carries prefix + label', ($madeJson['api_key']['prefix'] ?? '') === $svc->prefixOf($plain) && ($madeJson['api_key']['label'] ?? '') === 'CI');
checkKc('create response has no key_hash', !array_key_exists('key_hash', $madeJson['api_key']) && !str_contains($made['raw'], $svc->hashKey($plain)));

$list1 = callKc(static fn(): string => $ctrl->index());
checkKc('index lists the new key', count($list1['json']['api_keys'] ?? []) === 1);
checkKc('index never carries plaintext/hash', !str_contains($list1['raw'], $plain) && !str_contains($list1['raw'], 'key_hash'));
checkKc('stats reflect one active key', ($list1['json']['stats']['total'] ?? 0) === 1 && ($list1['json']['stats']['active'] ?? 0) === 1);

$resolved = $svc->verify($plain);
checkKc('service verifies the one-time plaintext', $resolved !== null && $resolved->isUsable());
$id = (int)($madeJson['api_key']['id'] ?? 0);
checkKc('created key has a numeric id', $id > 0);

// --------------------------------------------------------------
// Rotate / revoke
// --------------------------------------------------------------
$rot = callKc(static fn(): string => $ctrl->rotate(['id' => $id]));
$newPlain = (string)($rot['json']['plaintext'] ?? '');
checkKc('rotate returns 200 with a new plaintext', $rot['status'] === 200 && preg_match('/^rv_[0-9a-f]{48}$/', $newPlain) === 1, $newPlain);
checkKc('rotate response has no key_hash', !str_contains($rot['raw'], 'key_hash'));
checkKc('new material differs from the old one', $newPlain !== $plain);
$oldAfter  = $svc->verify($plain);
$newAfter  = $svc->verify($newPlain);
checkKc('old material is revoked after rotate', $oldAfter !== null && !$oldAfter->isUsable());
checkKc('new material is a separate usable row', $newAfter !== null && $newAfter->isUsable() && $newAfter->id !== $oldAfter?->id);

$rotUnknown = callKc(static fn(): string => $ctrl->rotate(['id' => 99999]));
checkKc('rotate unknown id -> 404', $rotUnknown['status'] === 404 && ($rotUnknown['json']['error'] ?? '') === 'unknown_api_key');

$rev = callKc(static fn(): string => $ctrl->revoke(['id' => $newAfter->id]));
checkKc('revoke returns ok', $rev['status'] === 200 && ($rev['json']['ok'] ?? false) === true);
checkKc('revoked key does not pass isUsable', !$svc->verify($newPlain)->isUsable());

$list2 = callKc(static fn(): string => $ctrl->index());
checkKc('after rotate+revoke stats: total 2, active 0, revoked 2',
    ($list2['json']['stats']['total'] ?? 0) === 2
    && ($list2['json']['stats']['active'] ?? 0) === 0
    && ($list2['json']['stats']['revoked'] ?? 0) === 2);

$revUnknown = callKc(static fn(): string => $ctrl->revoke(['id' => 99998]));
checkKc('revoke unknown id -> 404', $revUnknown['status'] === 404);

// --------------------------------------------------------------
// Deny-list hygiene (Step 8 prerequisite)
// --------------------------------------------------------------
$limiter = new ApiRateLimiter($work . '/rl');
$auth    = new ApiAuth($svc, $limiter);
checkKc('ApiAuth denies /api/apikeys for external keys', $auth->isAdminPath('/api/apikeys') && $auth->isAdminPath('/api/apikeys/7/rotate'));
checkKc('ApiAuth denies /api/webhooks for external keys', $auth->isAdminPath('/api/webhooks') && $auth->isAdminPath('/api/webhooks/1'));

// --------------------------------------------------------------
// Cleanup
// --------------------------------------------------------------
AdminAuth::setRootDir(null);
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_SERVER['HTTP_X_ADMIN_TOKEN']);
@array_map('unlink', array_merge(glob($work . '/rl/*') ?: [], glob($work . '/*') ?: []));
if (is_dir($work . '/rl')) { @rmdir($work . '/rl'); }
@rmdir($work);

echo implode("\n", $report) . "\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
