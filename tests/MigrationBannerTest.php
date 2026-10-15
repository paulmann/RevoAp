<?php
/**
 * MigrationBannerTest: the banner must not outlive the problem it describes.
 *
 * The banner is painted by assets/js/migration-banner.js when
 * GET /api/settings/version-status reports drift, and settings.js applies
 * migrations. Before v1.9.1 nothing connected the two: the banner was painted
 * once at load, so a successful "Apply Migrations" left a warning on screen
 * about drift that no longer existed until the operator reloaded.
 *
 * The contract that fixes it spans two files - an event name and a re-probe -
 * and the cheapest way to keep a contract honest is to assert it directly.
 * These are SOURCE-LEVEL assertions: the module has no DOM harness of its own
 * yet, so this suite pins the contract and the wording, not the rendering.
 *
 * Run: php tests/MigrationBannerTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-23
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

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

$root = dirname(__DIR__);
$banner = (string)file_get_contents($root . '/assets/js/migration-banner.js');
$settings = (string)file_get_contents($root . '/assets/js/settings.js');
$layout = (string)file_get_contents($root . '/assets/templates/layout.html');

// =====================================================================
echo "\n== (a) the event that connects the apply button to the banner ==\n";
// =====================================================================
check('the banner declares the applied-event name', str_contains($banner, "export const MIGRATIONS_APPLIED = 'oc:migrations-applied';"));
check('settings.js dispatches that same event', str_contains($settings, "new CustomEvent('oc:migrations-applied')"));
check('the event name is not spelled differently in the two files',
    substr_count($banner, 'oc:migrations-applied') === 1 && substr_count($settings, 'oc:migrations-applied') === 1);
// The count is the number of call sites, not a magic number: every apply path
// announces the same event through this one helper and none dispatches the
// event by hand. R-7 added a fourth call site - the System health card's
// "Apply migrations" action, which must refresh the banner on the same event
// (the check it just repaired is the same drift the banner warns about).
check('settings.js announces it through one helper, not by hand at each call site',
    str_contains($settings, 'function announceMigrationsApplied()')
    && substr_count($settings, 'announceMigrationsApplied();') === 4,
    (string)substr_count($settings, 'announceMigrationsApplied();'));

// =====================================================================
echo "\n== (b) the banner re-probes, and removes itself when the drift is gone ==\n";
// =====================================================================
check('the banner listens for the event', str_contains($banner, 'document.addEventListener(MIGRATIONS_APPLIED'));
check('the listener re-probes without honouring an old dismissal',
    str_contains($banner, 'void refresh({ show: false });'));
check('a clean probe removes the banner', str_contains($banner, 'existing.remove();'));
check('a clean probe clears the session dismissal, so the next drift is not hidden',
    str_contains($banner, "sessionStorage.removeItem(DISMISSED_KEY)"));
check('a failed probe leaves the banner alone instead of guessing',
    str_contains($banner, 'if (!info) {') && str_contains($banner, 'return existing !== null;'));
check('a banner that is still needed is refreshed with the current versions',
    str_contains($banner, 'refreshText(existing, info);'));
check('the numbers are re-read from the live probe, not remembered',
    str_contains($banner, 'info?.live_schema_version') && str_contains($banner, 'info?.expected_schema_version'));

// =====================================================================
echo "\n== (c) the banner says what a click does ==\n";
// =====================================================================
check('the text no longer promises that clicking applies migrations',
    !str_contains($banner, 'Click to apply migrations'));
check('the text says the click opens Settings, which is what happens',
    str_contains($banner, 'Click to open Settings'));
check('the action button describes the same behaviour',
    str_contains($banner, 'Open Settings → Migrations'));
check('the banner keeps the module signature layout.html expects',
    str_contains($layout, 'assets/js/migration-banner.js') && str_contains($banner, 'export async function refresh('));
check('the probe endpoint is unchanged, so no new surface is exposed',
    str_contains($banner, '/api/settings/version-status'));
// The wording may mention the admin token (it explains where the unlock input
// is), but no value a secret could be assigned to may appear in the banner's
// own text output.
check('the banner still never prints secret material',
    !str_contains($banner, 'admin_key')
    && !str_contains($banner, '.bothub.key')
    && preg_match('/textContent\s*=[^;]*(key|token)/i', $banner) !== 1);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
