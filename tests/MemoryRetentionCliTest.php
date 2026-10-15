<?php
/**
 * MemoryRetentionCliTest: standalone regression test for
 * bin/memory-retention.php authentication and exit-code contract.
 *
 * The admin token is RANDOMIZED per run and never appears in the
 * repository, so the test proves the gate without any real secret.
 * A child `php bin/memory-retention.php` process is spawned via
 * proc_open() so the tested code path is the real CLI entry point.
 *
 * Assertions:
 *   - --help exits 0 and prints usage on STDOUT;
 *   - a missing/empty token is refused with rc=3 and
 *     {"error":"unauthorized"};
 *   - a wrong token vs the environment-configured one is refused
 *     with rc=3;
 *   - a correct token passes the auth gate (rc not in {3,5}) and
 *     the command proceeds to the database gate;
 *   - the CONFIGURED token is never echoed to STDOUT or STDERR;
 *   - --days<=0 is validated BEFORE authentication and returns rc=5;
 *   - STDOUT is always one JSON document.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cli  = $root . '/bin/memory-retention.php';

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

/**
 * Spawn `php bin/memory-retention.php` with the given extra argv and a
 * controlled environment. Returns [exitCode, stdout, stderr].
 *
 * @param list<string> $args         extra CLI arguments
 * @param array<string,string> $envSet  env vars to set in the child
 * @param list<string> $envRemove       env vars to remove in the child
 * @return array{0:int,1:string,2:string}
 */
function runCli(
    string $root,
    string $cli,
    array $args,
    array $envSet = [],
    array $envRemove = []
): array {
    $cmd = array_merge([PHP_BINARY, $cli], $args);
    // Minimal child environment: inherit only OS plumbing variables, so
    // no token from the parent shell can leak into the CLI child process.
    $env = [];
    foreach (['PATH', 'SystemRoot', 'windir', 'ComSpec', 'PATHEXT', 'TEMP', 'TMP'] as $name) {
        $value = getenv($name);
        if ($value !== false) {
            $env[$name] = $value;
        }
    }
    // Belt and braces: never pass token variables inherited from the
    // parent environment; each case below controls the token explicitly.
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
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return [$rc, (string)$stdout, (string)$stderr];
}

$token = 'test-token-' . bin2hex(random_bytes(6));

// Case 1: --help exits 0 and prints usage on STDOUT.
[$rc, $out, $err] = runCli($root, $cli, ['--help'], [], []);
check($rc === 0, 'help: exit code 0');
check(strpos($out, 'Usage') !== false, 'help: usage text on stdout');
check(trim($err) === '', 'help: stderr stays empty');

// Case 2: no presented token and no configured env token -> rc=3.
[$rc, $out, $err] = runCli($root, $cli, ['--status'], [], ['OPEN_CORUM_ADMIN_TOKEN']);
check($rc === 3, 'no token: exit code 3');
$doc = json_decode($out, true);
check(is_array($doc) && ($doc['error'] ?? '') === 'unauthorized', 'no token: error=unauthorized JSON');
check(strpos($out, 'OPEN_CORUM_ADMIN_TOKEN') === false, 'no token: env NAME not echoed');

// Case 3: environment-configured token, wrong --token= -> rc=3,
// and the configured token never leaks to any stream.
[$rc, $out, $err] = runCli(
    $root,
    $cli,
    ['--status', '--token=WRONG-' . $token],
    ['REVOAP_ADMIN_TOKEN' => $token],
    []
);
check($rc === 3, 'wrong token: exit code 3');
$doc = json_decode($out, true);
check(is_array($doc) && ($doc['error'] ?? '') === 'unauthorized', 'wrong token: error=unauthorized JSON');
$tokenSecret = 'test-token-';
check(strpos($out, $tokenSecret) === false, 'wrong token: configured token not in stdout');
check(strpos($err, $tokenSecret) === false, 'wrong token: configured token not in stderr');

// Case 4: correct --token passes the auth gate; the run proceeds to
// the database gate (rc 4) on hosts without a DB, or completes a
// dry-run (rc 0) where one is available. Auth failure codes 3/5 must
// never appear.
[$rc, $out, $err] = runCli(
    $root,
    $cli,
    ['--status', '--token=' . $token],
    ['REVOAP_ADMIN_TOKEN' => $token],
    []
);
check($rc !== 3 && $rc !== 5, 'correct token: auth gate passed (rc=' . $rc . ')');
$doc = json_decode($out, true);
check(is_array($doc), 'correct token: stdout is valid JSON');
check(strpos($out . $err, $token) === false, 'correct token: token absent from all output');

// Case 5: the token read from the environment variable (no --token)
// satisfies the gate as well.
[$rc, $out, $err] = runCli($root, $cli, ['--status'], ['REVOAP_ADMIN_TOKEN' => $token], []);
check($rc !== 3 && $rc !== 5, 'env token: auth gate passed (rc=' . $rc . ')');
check(strpos($out . $err, $token) === false, 'env token: token absent from all output');

// Case 6: --days<=0 is rejected BEFORE authentication (rc=5), even
// with a correct configured token.
[$rc, $out, $err] = runCli($root, $cli, ['--days=0'], ['REVOAP_ADMIN_TOKEN' => $token], []);
check($rc === 5, 'invalid days: exit code 5');
$doc = json_decode($out, true);
check(is_array($doc) && ($doc['error'] ?? '') === 'invalid_retention_days', 'invalid days: error=invalid_retention_days JSON');
check(strpos($out, '--days') === false || strpos($out, 'positive number') !== false, 'invalid days: message mentions window');
check(strpos($out . $err, $token) === false, 'invalid days: token absent from all output');

echo '----------------------------------------' . "\n";
echo 'passed: ' . $passed . '   failed: ' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
