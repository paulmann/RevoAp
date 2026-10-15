<?php
declare(strict_types=1);
/**
 * OriginGuardTest: a state-changing request from another site is refused.
 *
 * The endpoints under /api/* write files, export runs and change settings.
 * Without this check any page on the internet could POST to them through a
 * visitor's browser: the response is unreadable to the attacker, the side
 * effect is not. The rule has to be strict about a foreign host and lenient
 * about the absence of any header, because curl and the deploy probes send
 * none and a browser always sends Origin on a cross-site POST.
 *
 *   php tests/OriginGuardTest.php
 */
use App\Http\OriginGuard;

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $c) {
    if (is_file($root . $c)) { require $root . $c; }
}

$passes = 0; $failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passes, $failed;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failed++; }
}

check('the guard is loadable', class_exists(OriginGuard::class));

$host = ['HTTP_HOST' => 'revoap.com', 'SERVER_NAME' => 'revoap.com'];
$post = 'POST';
$path = '/api/share/document';

$cases = [
    'a foreign Origin is refused' => [
        ['HTTP_ORIGIN' => 'https://evil.example'] + $host, true,
    ],
    'a foreign Origin over http is refused too' => [
        ['HTTP_ORIGIN' => 'http://evil.example'] + $host, true,
    ],
    'the same host is allowed' => [
        ['HTTP_ORIGIN' => 'https://revoap.com'] + $host, false,
    ],
    'the same host with a port is allowed' => [
        ['HTTP_ORIGIN' => 'https://revoap.com:8443'] + $host, false,
    ],
    'a foreign Referer alone is refused' => [
        ['HTTP_REFERER' => 'https://evil.example/page'] + $host, true,
    ],
    'the same Referer alone is allowed' => [
        ['HTTP_REFERER' => 'https://revoap.com/history'] + $host, false,
    ],
    'no header at all is allowed (curl, deploy probes)' => [
        $host, false,
    ],
    'a stripped Origin with a good Referer is allowed' => [
        ['HTTP_REFERER' => 'https://revoap.com/'] + $host, false,
    ],
    'a stripped Referer with a foreign Origin is still refused' => [
        ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://revoap.com/'] + $host, true,
    ],
    'a host with a trailing dot still matches' => [
        ['HTTP_ORIGIN' => 'https://revoap.com.'] + $host, false,
    ],
];

foreach ($cases as $label => [$server, $expectRefused]) {
    $why = OriginGuard::violation($post, $path, $server);
    check($label, ($why !== null) === $expectRefused, (string)$why);
}

foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
    check($method . ' is never refused', OriginGuard::violation($method, $path, ['HTTP_ORIGIN' => 'https://evil.example'] + $host) === null);
}

check('the check is wired before routing',
    str_contains((string)file_get_contents($root . '/index.php'), 'OriginGuard::violation('));
check('a refusal is logged and answered as 403',
    str_contains((string)file_get_contents($root . '/index.php'), "'error'   => 'forbidden_origin',"));
// The v1 bug this guards: the refusal set http_response_code(403) and then
// called Response::json(), which set the status itself — so the browser got a
// 200 carrying an error body and the fetch layer treated a cross-site refusal
// as success. The fix is to pass the status to Response::json() and nowhere
// else.
//
// Scoped to the refusal BLOCK rather than the whole file, because
// `http_response_code(403)` is legitimate elsewhere: since v2.0.0 serveAsset()
// returns 403 for an extension outside its allow-list. A whole-file grep would
// fail on that correct code and would tell us nothing about the OriginGuard
// path, which is the only thing this check is about.
$indexSrcOG = (string)file_get_contents($root . '/index.php');
$refusalBlock = '';
if (preg_match("/'error'\s*=>\s*'forbidden_origin'.*?\n\}/s", $indexSrcOG, $mOG) === 1) {
    $refusalBlock = $mOG[0];
}
check('the OriginGuard refusal block was located', $refusalBlock !== '');
check('the refusal passes its status to Response::json()',
    str_contains($refusalBlock, '], 403);') || str_contains($refusalBlock, ", 403);\n"));
check('and does not set the status separately (which Response::json would overwrite)',
    !str_contains($refusalBlock, 'http_response_code('));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
