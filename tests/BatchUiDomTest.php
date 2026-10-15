<?php
/**
 * BatchUiDomTest: the DOM/JS contract of the "Run via Batch API" mode.
 *
 * Runs the harness in `tests/harness/batch-ui.mjs`, which reads the REAL
 * template, the real modules and the real stylesheets rather than a
 * paraphrase of them, and checks three things the batch feature would
 * otherwise only be able to get wrong silently:
 *
 *   1. the composer offers the mode, says in plain words that the result is
 *      asynchronous and names the 24-hour window, and keeps a reason with the
 *      toggle when the deployment cannot submit a batch;
 *   2. the lifecycle readout is driven by the job row (phase, provider
 *      status, request counts, elapsed time, window), reports per-row
 *      failures as per-row failures rather than as a failed run, and only
 *      offers Cancel while the provider actually owns the batch;
 *   3. the styling uses only tokens the two themes publish — the same rule
 *      {@see CssTokensTest} enforces across the tree, applied here next to
 *      the classes it guards.
 *
 * Like the other DOM tests this is a thin runner: node is required, and a
 * host without node SKIPS rather than fails (the suite must stay runnable on
 * a PHP-only box).
 *
 *   php tests/BatchUiDomTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$harness = __DIR__ . '/harness/batch-ui.mjs';

$passes  = 0;
$failed  = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passes, $failed;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
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
    echo "  skip  node is not available — the DOM contract cannot be checked here\n";
    echo "----------------------------------------\n";
    echo "ALL OK: {$passes} passed, 0 failed (skipped: no node)\n";
    exit(0);
}

// --experimental-vm-modules is required: the harness loads the real ES
// module model-selector.js through vm.SourceTextModule, so the pool rules are
// exercised as shipped rather than re-implemented here.
$cmd = escapeshellarg($node) . ' --experimental-vm-modules '
    . escapeshellarg(str_replace('\\', '/', $harness)) . ' 2>&1';
$output = (string)shell_exec($cmd);

$harnessPassed = 0;
$harnessFailed = 0;
$summary = false;
foreach (explode("\n", $output) as $line) {
    if (str_contains($line, 'ExperimentalWarning') || str_contains($line, 'trace-warnings') || str_contains($line, '(node:')) {
        continue;
    }
    if (preg_match('/^\s*ok\s/', $line) === 1) { $harnessPassed++; }
    if (preg_match('/^\s*FAIL\s/', $line) === 1) { $harnessFailed++; }
    if (preg_match('/^Passes:\s*\d+\s+Failures:\s*\d+/', trim($line)) === 1) { $summary = true; }
}

check('the harness reported at least one check', $harnessPassed + $harnessFailed > 0, $output);
check('the harness reached its own summary line (it did not crash mid-run)',
    $summary, substr($output, 0, 1500));
check('every harness check passed', $harnessFailed === 0, "{$harnessFailed} check(s) failed");
check('the harness ran the full set', $harnessPassed >= 45, "{$harnessPassed} check(s) passed");

echo "----------------------------------------\n";
echo ($failed === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
