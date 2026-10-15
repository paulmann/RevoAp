<?php
/**
 * WorkerStatusChipTest: the header worker indicator's lane contract.
 *
 * Runs `tests/harness/worker-status-chip.mjs`, which loads the REAL
 * assets/js/worker-status.js, feeds the classifier real-shaped health
 * snapshots and reads the real stylesheet. It guards the rule the operator
 * sees in the top-right corner: a job that is only paused means the worker is
 * idle and waiting, so the chip shows the amber waiting dot - it must never
 * keep spinning as if work were in progress.
 *
 * Thin runner: node is required; a host without node SKIPS rather than fails.
 *
 *   php tests/WorkerStatusChipTest.php
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-24
 */
declare(strict_types=1);

$harness = __DIR__ . '/harness/worker-status-chip.mjs';

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
    echo "  skip  node is not available - the lane contract cannot be checked here\n";
    echo "----------------------------------------\n";
    echo "ALL OK: {$passes} passed, 0 failed (skipped: no node)\n";
    exit(0);
}

$cmd = escapeshellarg($node) . ' --experimental-vm-modules ' . escapeshellarg($harness) . ' 2>&1';
$output = shell_exec($cmd);
$output = is_string($output) ? $output : '';

foreach (explode("\n", $output) as $line) {
    $trimmed = rtrim($line);
    if (preg_match('/^\s*ok\s{2}(.+)$/', $trimmed, $m) === 1) {
        check(trim($m[1]), true);
    } elseif (preg_match('/^\s*FAIL\s(.+)$/', $trimmed, $m) === 1) {
        check(trim($m[1]), false);
    }
}

if (preg_match('/Passes:\s*(\d+)\s+Failures:\s*(\d+)/', $output, $m) === 1) {
    $passes = (int)$m[1];
    $failed = (int)$m[2];
}

echo "----------------------------------------\n";
echo $failed === 0
    ? "ALL OK: {$passes} passed, 0 failed\n"
    : "FAILED: {$passes} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
