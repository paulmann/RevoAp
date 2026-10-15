<?php
/**
 * PromptCacheUiTest: the DOM/JS contract of the prompt-cache surface.
 *
 * Runs the harness in `tests/harness/prompt-cache-ui.mjs`, which reads the
 * REAL modules rather than a paraphrase of them and checks the two halves
 * of the feature a PHP test cannot reach:
 *
 *   1. the Settings → Prompt caching card is shipped, registered, mounted
 *      and wired to `POST /api/settings/prompt-cache`, and it renders the
 *      capability table the server sends instead of carrying a second,
 *      drift-prone copy of the documentation;
 *   2. the statistics renderers distinguish the three states an operator
 *      must never see conflated — a cache hit, a reported miss (a real
 *      zero), and "no cache data was returned at all". The last one is the
 *      requirement behind `NO_CACHE_DATA`, and it is asserted in both
 *      directions: it appears when nothing was reported, and it does NOT
 *      appear when the provider reported a zero.
 *
 * The formatter itself (`assets/js/prompt-cache.js`) is deliberately
 * dependency-free so the harness can load the shipped file through
 * `vm.SourceTextModule` and call it — the assertions run against the real
 * code, not against a re-implementation.
 *
 * Like the other DOM tests this is a thin runner: node is required, and a
 * host without node SKIPS rather than fails (the suite must stay runnable
 * on a PHP-only box).
 *
 *   php tests/PromptCacheUiTest.php
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
$harness = __DIR__ . '/harness/prompt-cache-ui.mjs';

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

// The formatter must stay loadable without a DOM: the harness links it with
// a linker that throws, so a future import added to it fails loudly here
// instead of silently changing what is being tested.
$formatter = file_get_contents($root . '/assets/js/prompt-cache.js') ?: '';
check('the shared formatter is shipped', $formatter !== '');
check('the shared formatter stays DOM-free (no document/el() dependency)',
    !str_contains($formatter, 'document.') && !str_contains($formatter, "from './"),
    'prompt-cache.js must stay importable outside a browser');
check('the shared formatter never builds markup',
    !str_contains($formatter, 'innerHTML'));

$node = null;
foreach (['node', 'nodejs'] as $candidate) {
    $probe = shell_exec(escapeshellarg($candidate) . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
        $node = $candidate;
        break;
    }
}
if ($node === null) {
    echo "  skip  node is not available — the prompt-cache UI contract cannot be checked here\n";
    echo "----------------------------------------\n";
    echo "ALL OK: {$passes} passed, 0 failed (skipped: no node)\n";
    exit(0);
}

// --experimental-vm-modules is required: the harness loads the real ES
// module prompt-cache.js through vm.SourceTextModule, so the states below
// are produced by the shipped code rather than by a re-implementation.
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
check('the harness ran the full set', $harnessPassed >= 55, "{$harnessPassed} check(s) passed");

echo "----------------------------------------\n";
echo ($failed === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
