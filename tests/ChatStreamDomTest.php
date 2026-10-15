<?php
/**
 * ChatStreamDomTest: headless browser-side coverage for the chat stream.
 *
 * The checks themselves live in tests/harness/chat-stream.mjs: they parse
 * the real assets/templates/layout.html + assets/templates/chat.html into a
 * stub DOM (tests/harness/dom-stub.mjs) and load the real ES modules —
 * app.js, pipeline.js, copy-buttons.js — through Node's
 * vm.SourceTextModule, then drive the stream the way the browser does:
 * submit the form, feed SSE frames to upsertStepFromEvent, click the copy
 * and collapse controls, render the final answer.
 *
 * What it covers, part by part:
 *   A — the question becomes the first card of the stream, the composer is
 *       emptied, and a submission that never reached the pipeline gets its
 *       text back;
 *   B — long stage bodies are clamped by CSS and expanded with a per-card
 *       toggle whose presence is measured, a live SSE update does not undo
 *       the reader's expansion, and a collapsed card still puts its WHOLE
 *       text on the clipboard (asserted against the clipboard stub);
 *   C — the jump-to-final-answer control exists only when an answer does,
 *       is driven by an IntersectionObserver, and hides when the answer is
 *       on screen;
 *   D — the copy pair reaches the first stage card and the query card, and
 *       stays off empty, placeholder, running and non-text cards;
 *   E — nothing renders through innerHTML (checked statically and by a stub
 *       DOM that throws if a module reaches for it).
 *
 * This file is the PHP-side entry point so the whole suite stays uniform
 * (`php tests/<Name>Test.php`, same `check()` output, same exit code). It
 * shells out to node because the subject under test is JavaScript.
 *
 * Node is optional: a host without node gets a SKIP and a zero exit, never
 * a failure. `--experimental-vm-modules` is required for SourceTextModule
 * and is passed here; Node prints an ExperimentalWarning for it, which is
 * filtered out of the report.
 *
 * Runs without PHPUnit:
 *   php tests/ChatStreamDomTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

$root    = dirname(__DIR__);
$harness = $root . '/tests/harness/chat-stream.mjs';
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
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failures++;
    }
}

echo "== the harness ships ==\n";
check('tests/harness/chat-stream.mjs exists', is_file($harness));
check('tests/harness/dom-stub.mjs exists', is_file($stub));
check('the stub DOM refuses innerHTML (so a module cannot smuggle markup)',
    is_file($stub) && str_contains((string)file_get_contents($stub), 'innerHTML is not available in the harness'));
check('the stub DOM models layout metrics (the collapse decides by measuring)',
    is_file($stub) && str_contains((string)file_get_contents($stub), '__ocLayout'));

if (!is_file($harness) || !is_file($stub)) {
    echo "\n=========================\n";
    echo "Passes: {$passes}  Failures: {$failures}\n";
    exit(1);
}

// Locate node. A missing runtime is a skip, not a failure: the JS checks
// simply cannot run, and pretending otherwise would be worse.
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
    echo "(the chat-stream DOM harness needs node >= 18 with --experimental-vm-modules)\n";
    echo "----------------------------------------\n";
    echo "ALL OK: {$passes} passed, 0 failed (JS harness skipped)\n";
    exit(0);
}

echo "\n== the headless DOM harness ==\n";
$cmd = escapeshellarg($node) . ' --experimental-vm-modules '
    . escapeshellarg(str_replace('\\', '/', $harness)) . ' 2>&1';
$output = (string)shell_exec($cmd);
$lines = preg_split('/\r\n|\n|\r/', $output) ?: [];

$passed = 0;
$failed = 0;
$skipped = 0;
// A harness that dies half-way prints ok lines and stops; without this
// guard a TypeError inside it read as very check passed.
$summary = false;
foreach ($lines as $line) {
    // Drop node's own noise (the ExperimentalWarning block) but keep every
    // check line the harness printed. The app's own error channel is loud on
    // purpose — those lines are kept so a failure is never hidden.
    if (str_contains($line, 'ExperimentalWarning')
        || str_contains($line, 'trace-warnings')
        || str_contains($line, '(node:')) {
        $skipped++;
        continue;
    }
    if (trim($line) === '') { continue; }
    echo $line . "\n";
    if (preg_match('/^Passes:\s*\d+\s+Failures:\s*\d+\s*$/', trim($line)) === 1) { $summary = true; }
    if (preg_match('/^\s*ok\s/', $line) === 1) { $passed++; }
    if (preg_match('/^\s*FAIL\s/', $line) === 1) { $failed++; }
}
if ($skipped > 0) {
    echo "  (filtered {$skipped} line(s) of node warning noise)\n";
}

check('the harness reached its own summary line (it did not crash mid-run)', $summary,
    'the "Passes: N  Failures: M" line is missing - the harness threw before the end');
check('the harness reported at least one check', $passed + $failed > 0,
    'no check lines found — the harness crashed? exit output above');
check('every harness check passed', $failed === 0, "{$failed} check(s) failed");
check('the harness ran the full set', $passed >= 90, "{$passed} check(s) passed");

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
