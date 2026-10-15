<?php
/**
 * CssTokensTest: offline guard for the CSS custom-property contract.
 *
 * Why this test exists: the six tab stylesheets (api / history / logs /
 * security / statistic / settings) referenced custom properties that no
 * theme ever defined — `--muted`, `--border`, `--bg-elev`, `--bg-code`,
 * `--accent-bg`, `--ok-bg`, … — and every call site carried a hard-coded
 * dark fallback (`var(--bg-elev, #1b2030)`) to hide it. Since a fallback
 * always wins when the property is undefined, the light theme rendered
 * dark tiles with near-invisible text, and nothing in the suite noticed:
 * a missing custom property is not a CSS error, so the browser simply
 * computes the fallback forever.
 *
 * The checks below are deliberately static (they parse the stylesheets
 * instead of rendering them) so the guard runs without a browser, and
 * they cover the three ways the bug can come back:
 *
 *   1. a var() whose name is defined nowhere in assets/css;
 *   2. a var() whose name is defined in a component file instead of both
 *      themes — it would then be invisible to the theme switcher;
 *   3. a colour hard-coded as a var() fallback, which is what made the
 *      undefined property harmless-looking in the first place.
 *
 * Runs without PHPUnit, database or network access:
 *   php tests/CssTokensTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
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
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failures++;
    }
}

$root    = dirname(__DIR__);
$cssRoot = $root . '/assets/css';

/* ---------------------------------------------------------------------
 * Exemptions. Kept as an explicit list so adding one is a visible act:
 * --oc-settings-nav-height is not a palette token at all — it is a
 * measurement published at runtime by the Settings section strip, and
 * `settings.css` only seeds it at 0px so `scroll-padding-top` is valid
 * before the script runs.
 * ------------------------------------------------------------------- */
$runtimeTokens = ['--oc-settings-nav-height'];

/* ---------------------------------------------------------------------
 * Collect every stylesheet, then every defined / used custom property.
 * Comments are stripped first: prose about a token (e.g. "the old
 * `var(--bg-elev, ...)` call sites") must not count as a use.
 * ------------------------------------------------------------------- */
$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($cssRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'css') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$rel = static function (string $path) use ($cssRoot): string {
    $norm = str_replace('\\', '/', $path);
    $base = str_replace('\\', '/', $cssRoot) . '/';
    return strpos($norm, $base) === 0 ? substr($norm, strlen($base)) : $norm;
};

$defined     = [];   // name => ['file:line', ...]
$used        = [];   // name => ['file:line', ...]
$colourFB    = [];   // 'file:line  rule' for var(--x, <colour>)
$braceIssues = [];   // 'file: +N/-M'

foreach ($files as $file) {
    $short = $rel($file);
    $raw   = (string)file_get_contents($file);
    $css   = (string)preg_replace('#/\*.*?\*/#s', '', $raw);

    if (substr_count($css, '{') !== substr_count($css, '}')) {
        $braceIssues[] = $short . ': ' . substr_count($css, '{') . ' open / '
            . substr_count($css, '}') . ' close';
    }

    // Line numbers must survive the comment strip, so scan the raw text
    // line by line and blank out commented spans instead of deleting them.
    $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
    $inComment = false;
    foreach ($lines as $i => $line) {
        $scan = $line;
        if ($inComment) {
            $close = strpos($scan, '*/');
            if ($close === false) { continue; }
            $scan = substr($scan, $close + 2);
            $inComment = false;
        }
        // Strip (and detect) any comment that opens on this line.
        while (($open = strpos($scan, '/*')) !== false) {
            $close = strpos($scan, '*/', $open + 2);
            if ($close === false) {
                $scan = substr($scan, 0, $open);
                $inComment = true;
                break;
            }
            $scan = substr($scan, 0, $open) . substr($scan, $close + 2);
        }

        $at = $short . ':' . ($i + 1);
        // A definition starts a declaration block on its own line (`  --x: 1;`)
        // or follows `{` / `;` on a shared one (`:root { --x: 0px; }`), which
        // is how settings.css seeds --oc-settings-nav-height. A var() call
        // site can never match: `var(` is not one of those delimiters.
        if (preg_match_all('/(?:^|[;{])\s*(--[A-Za-z0-9_-]+)\s*:/m', $scan, $m)) {
            foreach ($m[1] as $name) { $defined[$name][] = $at; }
        }
        if (preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $scan, $m)) {
            foreach ($m[1] as $name) { $used[$name][] = $at; }
        }
        // var(--token, <fallback>) where the fallback holds a colour literal.
        if (preg_match_all('/var\(\s*--[A-Za-z0-9_-]+\s*,\s*([^()]*?)\)/', $scan, $m)) {
            foreach ($m[1] as $fallback) {
                if (preg_match('/(#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\()/', $fallback)) {
                    $colourFB[] = $at . '  ' . trim($scan);
                }
            }
        }
    }
}

$themeFiles = [
    'dark'  => 'themes/dark.css',
    'light' => 'themes/light.css',
];
$themeDefined = [];
foreach ($themeFiles as $theme => $relative) {
    $path = $cssRoot . '/' . $relative;
    $css  = (string)preg_replace('#/\*.*?\*/#s', '', (string)@file_get_contents($path));
    preg_match_all('/(?:^|[;{])\s*(--[A-Za-z0-9_-]+)\s*:/m', $css, $m);
    $themeDefined[$theme] = array_values(array_unique($m[1]));
}

echo "== the stylesheets are well formed ==\n";
check('assets/css ships stylesheets to scan', count($files) >= 12, count($files) . ' file(s)');
check('both theme files define tokens',
    count($themeDefined['dark']) > 0 && count($themeDefined['light']) > 0);
check('every stylesheet has balanced braces', $braceIssues === [],
    implode('; ', $braceIssues));

echo "\n== every var(--name) used in assets/css is defined ==\n";
$undefined = [];
foreach ($used as $name => $where) {
    if (!isset($defined[$name])) { $undefined[$name] = $where; }
}
$undefinedReal = array_diff_key($undefined, array_flip($runtimeTokens));
$undefinedDetail = [];
foreach ($undefinedReal as $name => $where) {
    $undefinedDetail[] = $name . ' at ' . implode(', ', array_slice($where, 0, 6))
        . (count($where) > 6 ? ' (+' . (count($where) - 6) . ' more)' : '');
}
check('no var() references an undefined custom property', $undefinedReal === [],
    implode(' | ', $undefinedDetail));
check('the only tolerated undefined name is the runtime token',
    array_diff(array_keys($undefined), $runtimeTokens) === [],
    'also tolerated: ' . implode(', ', array_diff(array_keys($undefined), $runtimeTokens)));

echo "\n== the runtime exemption is still honest ==\n";
// The whitelist entry must keep being justified: if nothing publishes the
// variable any more, it is dead and the exemption should go with it.
$publishers = [];
$jsFiles = glob($root . '/assets/js/*.js') ?: [];
foreach (array_merge($jsFiles, glob($root . '/assets/templates/*.html') ?: []) as $f) {
    if (strpos((string)file_get_contents($f), '--oc-settings-nav-height') !== false) {
        $publishers[] = basename($f);
    }
}
check('--oc-settings-nav-height is still defined in CSS', isset($defined['--oc-settings-nav-height']));
check('a script or template still publishes --oc-settings-nav-height',
    $publishers !== [], 'no JS/template sets it — drop the whitelist entry');
$seedSites = $defined['--oc-settings-nav-height'] ?? [];
$seedOnlyInSettings = $seedSites !== [];
foreach ($seedSites as $at) {
    if (strpos($at, 'components/settings.css:') !== 0) { $seedOnlyInSettings = false; }
}
check('the runtime token is only seeded where its strip lives',
    $seedOnlyInSettings, implode(', ', $seedSites));
check('the seed is a 0px placeholder the script overwrites',
    (bool)preg_match(
        '/--oc-settings-nav-height:\s*0px/',
        (string)file_get_contents($cssRoot . '/components/settings.css')
    ));

echo "\n== every token a component consumes is theme-able ==\n";
// A name defined only in a component file would never follow
// data-theme, which is exactly how the tab stylesheets drifted.
$notThemed = [];
foreach ($used as $name => $where) {
    if (in_array($name, $runtimeTokens, true)) { continue; }
    foreach (array_keys($themeFiles) as $theme) {
        if (!in_array($name, $themeDefined[$theme], true)) {
            $notThemed[] = $name . ' missing from themes/' . $theme . '.css'
                . ' (used at ' . implode(', ', array_slice($where, 0, 3)) . ')';
        }
    }
}
check('every used token is defined by BOTH themes', $notThemed === [],
    implode(' | ', $notThemed));
check('the two themes define the same token names',
    array_diff($themeDefined['dark'], $themeDefined['light']) === []
    && array_diff($themeDefined['light'], $themeDefined['dark']) === [],
    'dark-only: ' . implode(', ', array_diff($themeDefined['dark'], $themeDefined['light']))
    . ' / light-only: ' . implode(', ', array_diff($themeDefined['light'], $themeDefined['dark'])));

echo "\n== no colour is hidden in a var() fallback ==\n";
// The fallback is what rendered while the property was undefined, so a
// dark literal there is a light-theme bug waiting to be computed.
check('no var(--token, <colour literal>) exists in assets/css', $colourFB === [],
    implode(' | ', array_slice($colourFB, 0, 8)));
check('palette tokens define no unused leftovers', (static function () use ($used, $defined, $runtimeTokens): array {
    // A token nobody consumes is dead weight in both themes.
    $unused = [];
    foreach ($defined as $name => $where) {
        if (!isset($used[$name]) && !in_array($name, $runtimeTokens, true)) {
            $unused[] = $name . ' (' . implode(', ', $where) . ')';
        }
    }
    return $unused;
})() === [], '');

echo "\n== the repaired token family stays in place ==\n";
// Pins the names the dead-variable repair introduced, so a later rename
// cannot silently orphan a component reference again. Values are read
// straight out of the theme files, skipping comments.
$themeCss = [];
foreach ($themeFiles as $theme => $relative) {
    $themeCss[$theme] = (string)preg_replace(
        '#/\*.*?\*/#s',
        '',
        (string)@file_get_contents($cssRoot . '/' . $relative)
    );
}
$repaired = [
    '--color-bg-elevated',
    '--color-bg-hover',
    '--color-bg-active',
    '--color-bg-code',
    '--color-text-code',
    '--color-accent-bg',
    '--color-success-bg',
    '--color-warning-bg',
    '--color-error-bg',
];
foreach ($repaired as $name) {
    $missing = [];
    foreach (array_keys($themeFiles) as $theme) {
        if (!preg_match('/^\s*' . preg_quote($name, '/') . '\s*:\s*(\S+)/m', $themeCss[$theme], $m)) {
            $missing[] = $theme;
        }
    }
    check($name . ' is defined by both themes', $missing === [],
        'missing from: ' . implode(', ', $missing));
}

echo "\n== the original palette contract is untouched ==\n";
$original = [
    '--color-bg-primary', '--color-bg-secondary', '--color-bg-surface',
    '--color-border', '--color-text-primary', '--color-text-secondary',
    '--color-accent', '--color-accent-hover', '--color-success',
    '--color-warning', '--color-error', '--shadow-sm',
];
$missingOriginal = [];
foreach ($original as $name) {
    foreach (array_keys($themeFiles) as $theme) {
        if (!in_array($name, $themeDefined[$theme], true)) {
            $missingOriginal[] = $name . '/' . $theme;
        }
    }
}
check('all 12 original tokens still defined by both themes', $missingOriginal === [],
    implode(', ', $missingOriginal));

// The dead names must never come back: each one was a documented bug.
echo "\n== the dead variable names do not return ==\n";
$dead = [
    '--border', '--muted', '--accent', '--danger', '--danger-fg', '--bg-elev',
    '--bg-elev-2', '--bg-card', '--bg-hover', '--bg-active', '--bg-code',
    '--text-code', '--accent-bg', '--ok', '--ok-bg', '--warn', '--warn-bg',
    '--danger-bg', '--success', '--fg', '--color-text', '--color-bg-subtle',
    '--color-accent-muted',
];
$resurrected = [];
foreach ($dead as $name) {
    if (isset($defined[$name]) || isset($used[$name])) {
        $resurrected[] = $name
            . (isset($used[$name]) ? ' used at ' . implode(', ', $used[$name]) : ' (defined)');
    }
}
check('no legacy dead name is defined or used anywhere in assets/css',
    $resurrected === [], implode(' | ', $resurrected));

echo "----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
