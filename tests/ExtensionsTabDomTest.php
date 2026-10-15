<?php
/**
 * ExtensionsTabDomTest: headless browser-side coverage for the Extensions
 * tab's new `.md.ini` install menu.
 *
 * The checks live in tests/harness/extensions-tab.mjs: they parse the real
 * assets/templates/layout.html into a stub DOM (tests/harness/dom-stub.mjs)
 * and load the real assets/js/extensions.js as an ES module through Node's
 * vm.SourceTextModule, then drive it with canned API answers - so what is
 * exercised is the shipped module, not a paraphrase of it.
 *
 * This file is the PHP-side entry point so the suite stays uniform
 * (`php tests/<Name>Test.php`, same `check()` output, same exit code). Node
 * is optional: a host without it gets a SKIP and a zero exit, never a
 * failure.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-26
 */
declare(strict_types=1);

$root    = dirname(__DIR__);
$harness = $root . '/tests/harness/extensions-tab.mjs';
$stub    = $root . '/tests/harness/dom-stub.mjs';

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
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

echo "== the harness ships ==\n";
check('tests/harness/extensions-tab.mjs exists', is_file($harness));
check('tests/harness/dom-stub.mjs exists', is_file($stub));

if (!is_file($harness) || !is_file($stub)) {
    echo "\n=========================\n";
    echo "Passes: {$passes}  Failures: {$failures}\n";
    exit(1);
}

$node = null;
foreach (['node', 'nodejs'] as $candidate) {
    $probe = @shell_exec(escapeshellarg($candidate) . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
        $node = $candidate;
        break;
    }
}

if ($node === null) {
    echo "\n== SKIP: node is not available on this host ==\n";
    echo "(the Extensions DOM harness needs node >= 18 with --experimental-vm-modules)\n";
    echo "----------------------------------------\n";
    echo "ALL OK: {$passes} passed, 0 failed (JS harness skipped)\n";
    exit(0);
}

echo "\n== the headless DOM harness ==\n";
$cmd = escapeshellarg($node) . ' --experimental-vm-modules '
    . escapeshellarg(str_replace('\\', '/', $harness)) . ' 2>&1';
$output = (string)shell_exec($cmd);
$lines  = preg_split('/\r\n|\n|\r/', $output) ?: [];

$passed  = 0;
$failed  = 0;
$skipped = [];
$summary = false;
foreach ($lines as $line) {
    if (str_contains($line, 'ExperimentalWarning')
        || str_contains($line, 'trace-warnings')
        || str_contains($line, '(node:')) {
        $skipped[] = $line;
        continue;
    }
    if (trim($line) === '') { continue; }
    echo $line . "\n";
    if (preg_match('/^\s*ok\s/', $line) === 1) { $passed++; }
    if (preg_match('/^\s*FAIL\s/', $line) === 1) { $failed++; }
    if (preg_match('/^Passes:\s*\d+\s+Failures:\s*\d+\s*$/', trim($line)) === 1) { $summary = true; }
}
if ($skipped !== []) {
    echo '  (filtered ' . count($skipped) . " line(s) of node warning noise)\n";
}

check('the harness reported at least one check', $passed + $failed > 0,
    'no check lines found - the harness crashed? exit output above');
check('the harness reached its own summary line (it did not crash mid-run)', $summary,
    'the "Passes: N  Failures: M" line is missing - the harness threw before the end');
check('every harness check passed', $failed === 0, "{$failed} check(s) failed");
check('the harness ran the full set', $passed >= 30, "{$passed} check(s) passed");

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
