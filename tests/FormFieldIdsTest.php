<?php
/**
 * FormFieldIdsTest: form fields must be named.
 *
 * Runs `tests/harness/form-field-ids.mjs`. The browser reports every
 * input/select/textarea without an id or name - one issue per field, which on
 * a generated table means one per row (20 model rows produced 20 issues on a
 * live install). The harness fails when a new unnamed field appears, and also
 * when the known-offender list stops matching reality.
 *
 * Thin runner: node is required; a host without node SKIPS rather than fails.
 *
 *   php tests/FormFieldIdsTest.php
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-25
 */
declare(strict_types=1);

$harness = __DIR__ . '/harness/form-field-ids.mjs';

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
    echo "  skip  node is not available - the field scan cannot run here\n";
    echo "----------------------------------------\n";
    echo "ALL OK: {$passes} passed, 0 failed (skipped: no node)\n";
    exit(0);
}

$cmd = escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1';
$output = shell_exec($cmd);
$output = is_string($output) ? $output : '';

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
