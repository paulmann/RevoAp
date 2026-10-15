<?php
/**
 * CopyButtonsShareDomTest: the DOM/JS contract of the Share button.
 *
 * Runs the harness in `tests/harness/copy-buttons-share.mjs`, which imports the
 * REAL assets/js/copy-buttons.js into the stub DOM and clicks the real button.
 * It exists because the interesting parts of this feature are invisible from
 * PHP: that every copy pair gains exactly one Share control and a re-render
 * cannot stack a second one, that the payload posted is the same Markdown the
 * "Copy MD" button copies, that a deployment with MD.Viewer opens the viewer
 * and one without it opens nothing while still copying the file link, and that
 * a refusal from the endpoint is reported with its reason instead of being
 * swallowed.
 *
 * Like the other DOM tests this is a thin runner: node is required, and a host
 * without node SKIPS rather than fails (the suite must stay runnable on a
 * PHP-only box).
 *
 *   php tests/CopyButtonsShareDomTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-27
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$harness = __DIR__ . '/harness/copy-buttons-share.mjs';

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
check('the module under test exists', is_file($root . '/assets/js/copy-buttons.js'));

$node = null;
foreach (['node', 'nodejs'] as $candidate) {
    $probe = shell_exec(escapeshellarg($candidate) . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
        $node = $candidate;
        break;
    }
}
if ($node === null) {
    echo "  skip  node is not available - the DOM contract cannot be checked here\n";
    echo "----------------------------------------\n";
    echo "ALL OK: {$passes} passed, 0 failed (skipped: no node)\n";
    exit(0);
}

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
check('the harness ran the full set', $harnessPassed >= 40, "{$harnessPassed} check(s) passed");

echo "----------------------------------------\n";
echo ($failed === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
