<?php
/**
 * ParseClientTest: every client module must parse.
 *
 * Written after a bad patch shipped: a duplicated `const` declaration is
 * invisible to the PHP suites and to the offline harnesses (none of them load
 * the module) but kills the module in the browser, so the tab it powers stops
 * opening. Parsing each file the way the browser does takes milliseconds and
 * makes that whole class of mistake impossible to deploy.
 *
 * Thin runner: node is required; a host without node SKIPS rather than fails.
 *
 *   php tests/ParseClientTest.php
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-25
 */
declare(strict_types=1);

$harness = __DIR__ . '/harness/parse-client.mjs';

$passes = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passes, $failed;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failed++;
    }
}

check('the harness script exists', is_file($harness), $harness);

$node = null;
foreach (['node', 'nodejs'] as $candidate) {
    $probe = shell_exec(escapeshellarg($candidate) . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
        $node = $candidate;
        break;
    }
}
if ($node === null) {
    echo "  skip  node is not available - the modules cannot be parsed here\n";
    echo "----------------------------------------\n";
    echo "ALL OK: {$passes} passed, 0 failed (skipped: no node)\n";
    exit(0);
}

$cmd = escapeshellarg($node) . ' --experimental-vm-modules ' . escapeshellarg($harness) . ' 2>&1';
$output = shell_exec($cmd);
$output = is_string($output) ? $output : '';

foreach (explode("\n", $output) as $line) {
    if (preg_match('/^\s*FAIL\s+(\S+)\s+-\s+(.+)$/', trim($line), $m) === 1) {
        check('parses: ' . $m[1], false, $m[2]);
    }
}

if (preg_match('/Passes:\s*(\d+)\s+Failures:\s*(\d+)/', $output, $m) === 1) {
    $passes = (int)$m[1];
    $failed = (int)$m[2];
} else {
    check('the harness produced a result', false, trim(substr($output, 0, 200)));
}

echo "----------------------------------------\n";
echo $failed === 0
    ? "ALL OK: {$passes} passed, 0 failed\n"
    : "FAILED: {$passes} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
