<?php
/**
 * StickyShellCssTest: the header and the left column stay put.
 *
 * The operator asked for it in one line — "pin the header with the menu to the
 * top so it does not scroll away, and the left column too" — and the shell
 * makes that harder than it sounds, because three other rules already depend
 * on where the header is:
 *
 *   - the section strip in `settings.css` (`position: sticky; top: 0`) has to
 *     park BELOW the header now, or it slides under it;
 *   - `html { scroll-padding-top: var(--oc-settings-nav-height) }` is what
 *     every `scrollIntoView()` target lands against, so the header's height
 *     is part of the offset;
 *   - `.oc-final-answer-section`'s `scroll-margin-top` was written for a
 *     shell with no sticky chrome at all, in a comment that says so.
 *
 * Verified here (static checks on the shipped stylesheets — the browser half
 * is listed as unverified in the release report):
 *   - the header is sticky at the top with a z-index above the section strip
 *     and below the worker-status tooltip, and a height the other rules can
 *     name;
 *   - the sidebar is sticky below the header, is `align-self: start` (a
 *     stretched grid item cannot travel, so it would never stick), has its own
 *     scrollbar, and is bounded to the viewport;
 *   - the strip and the two scroll offsets are computed from the header's
 *     height;
 *   - the narrow layout is not broken: the sidebar's sticky treatment is
 *     undone there, and the one-column grid, the removed right border and the
 *     adaptive `.oc-content` (no width cap, `min-width: 0`) are all intact;
 *   - no new custom property was introduced — the CSS token test requires
 *     every used `--oc-*` token to be defined by both themes, and a layout
 *     constant is not a theme colour.
 *
 * Runs without PHPUnit, a browser, a database or a network:
 *   php tests/StickyShellCssTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

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

$cssRoot = __DIR__ . '/../assets/css';
$read = static fn(string $rel): string => (string)file_get_contents($cssRoot . '/' . $rel);
/**
 * Comments are stripped before any block is read: a declaration's own
 * comment can contain braces (`{block:'start'}`), which would end a
 * `[^}]*` capture early and report a rule as missing that is right there.
 * The prose checks below therefore use the RAW text.
 */
$strip = static fn(string $css): string => (string)preg_replace('#/\*.*?\*/#s', '', $css);

$appCss      = $read('app.css');
$settingsCss = $read('components/settings.css');
$pipelineCss = $read('components/pipeline.css');
$historyCss  = $read('components/history.css');
$workerCss   = $read('components/worker-status.css');
$appFlat      = $strip($appCss);
$settingsFlat = $strip($settingsCss);
$pipelineFlat = $strip($pipelineCss);
$historyFlat  = $strip($historyCss);
$workerFlat   = $strip($workerCss);

/** The body of a selector's block, or '' when it is absent. */
$block = static function (string $css, string $selector): string {
    if (preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/s', $css, $m) !== 1) {
        return '';
    }
    return $m[1];
};
/** Does a block declare `property: value` (whitespace-insensitive)? */
$declares = static function (string $block, string $property, ?string $value = null): bool {
    $pattern = '/' . preg_quote($property, '/') . '\s*:\s*' . ($value === null ? '[^;]+' : preg_quote($value, '/')) . '\s*[;}]/';
    return preg_match($pattern, $block . '}') === 1 || preg_match($pattern, $block) === 1;
};
/** A numeric declaration's value, or null. */
$valueOf = static function (string $block, string $property): ?string {
    if (preg_match('/' . preg_quote($property, '/') . '\s*:\s*([^;]+);/', $block, $m) !== 1) {
        return null;
    }
    return trim($m[1]);
};

// =====================================================================
echo "\n== the header is pinned ==\n";
// =====================================================================

$header = $block($appFlat, '.oc-header');
check('.oc-header is sticky at the top of the viewport',
    $declares($header, 'position', 'sticky') && $valueOf($header, 'top') === '0',
    $header);
$headerZ = (int)($valueOf($header, 'z-index') ?? -1);
check('it stacks above the section strip and below the worker-status tooltip',
    $headerZ > 5 && $headerZ < 60, 'z-index=' . $headerZ);
check('its height is a fact, not a guess, so the rules below can name it',
    $declares($header, 'min-height', '64px'),
    (string)$valueOf($header, 'min-height'));

// =====================================================================
echo "\n== the left column is pinned below it ==\n";
// =====================================================================

$sidebar = $block($appFlat, '.oc-sidebar');
check('.oc-sidebar is sticky', $declares($sidebar, 'position', 'sticky'), $sidebar);
check('it parks directly under the header',
    $valueOf($sidebar, 'top') === '64px', (string)$valueOf($sidebar, 'top'));
check('it does not stretch to the row (a stretched sticky box cannot travel)',
    $declares($sidebar, 'align-self', 'start'),
    (string)$valueOf($sidebar, 'align-self'));
check('it scrolls on its own when its content is taller than the viewport',
    $declares($sidebar, 'overflow-y', 'auto'),
    (string)$valueOf($sidebar, 'overflow-y'));
check('and it is bounded to one viewport minus the header',
    $valueOf($sidebar, 'height') === 'calc(100vh - 64px)',
    (string)$valueOf($sidebar, 'height'));
check('the column still carries its tint and its separator',
    $declares($sidebar, 'background', 'var(--color-bg-secondary)')
    && $declares($sidebar, 'border-right', '1px solid var(--color-border)'),
    $sidebar);

$main = $block($appFlat, '.oc-main');
check('the two-column shell and its height are unchanged',
    $declares($main, 'grid-template-columns', '280px 1fr')
    && str_contains($main, 'min-height: calc(100vh - 64px)'),
    $main);

// =====================================================================
echo "\n== the rules that depend on the header followed it ==\n";
// =====================================================================

// (not `$strip` — that name is the comment-stripping closure above)
$settingsNav = $block($settingsFlat, '.oc-settings-nav');
$stripZ = (int)($valueOf($settingsNav, 'z-index') ?? -1);
check('the section strip parks below the header instead of under it',
    $declares($settingsNav, 'position', 'sticky') && $valueOf($settingsNav, 'top') === '64px',
    (string)$valueOf($settingsNav, 'top'));
check('and stays below it in the stacking order',
    $stripZ > 0 && $stripZ < $headerZ, 'strip z-index=' . $stripZ);
check('the document scroll offset clears header + strip',
    preg_match('/html\s*\{[^}]*scroll-padding-top\s*:\s*calc\(\s*64px\s*\+\s*var\(--oc-settings-nav-height\)\s*\)/s', $settingsCss) === 1,
    'scroll-padding-top does not include the header');

$finalAnswer = $block($pipelineFlat, '.oc-final-answer-section');
check('the "jump to final answer" target clears the header too',
    $valueOf($finalAnswer, 'scroll-margin-top') === 'calc(64px + 1.5rem)',
    (string)$valueOf($finalAnswer, 'scroll-margin-top'));
check('and the stylesheet no longer claims the header scrolls away',
    !str_contains($pipelineCss, 'scrolls away with the page')
    && !str_contains($pipelineCss, 'shell has none today'),
    'pipeline.css still documents the old behaviour');

$historyGroup = $block($historyFlat, '.oc-history-group');
check('the history group header keeps its own scrollport-local sticky',
    $declares($historyGroup, 'position', 'sticky')
    && $valueOf($historyGroup, 'top') === '0'
    && $block($historyFlat, '.oc-history-list') !== ''
    && $declares($block($historyFlat, '.oc-history-list'), 'overflow-y', 'auto'),
    $historyGroup);
check('the worker-status tooltip still paints over the header',
    (int)($valueOf($block($workerFlat, '.oc-worker-status-tip'), 'z-index') ?? 0) > $headerZ,
    (string)$valueOf($block($workerFlat, '.oc-worker-status-tip'), 'z-index'));

// =====================================================================
echo "\n== the narrow layout is not broken ==\n";
// =====================================================================

$mediaStart = strpos($appCss, '@media (max-width: 880px)');
check('the 880px breakpoint is still there', $mediaStart !== false);
$media = $mediaStart === false ? '' : substr($appCss, $mediaStart);
check('the shell still collapses to one column',
    str_contains($media, '.oc-main { grid-template-columns: 1fr; }'), $media);
$mediaSidebar = $block($strip($media), '.oc-sidebar');
check('the sidebar becomes a band again, not a pinned column',
    $declares($mediaSidebar, 'position', 'static')
    && $declares($mediaSidebar, 'height', 'auto')
    && $declares($mediaSidebar, 'overflow-y', 'visible'),
    $mediaSidebar);
check('and it keeps the bottom border the narrow layout has always had',
    $declares($mediaSidebar, 'border-bottom', '1px solid var(--color-border)'),
    $mediaSidebar);

$content = $block($appFlat, '.oc-content');
check('the content column stays adaptive (no width cap, min-width: 0, clamp padding)',
    !str_contains($appCss, '[data-ui-width')
    && !str_contains($content, 'max-width')
    && $declares($content, 'min-width', '0')
    && str_contains($content, 'padding: clamp('),
    $content);

// =====================================================================
echo "\n== no new CSS tokens, and the notice is laid out ==\n";
// =====================================================================

check('app.css introduces no custom property of its own',
    preg_match('/^\s*--[A-Za-z0-9_-]+\s*:/m', $appCss) === 0,
    'a new token would have to be defined by both themes for CssTokensTest to pass');
$notice = $block($appFlat, '.oc-protection-notice');
check('the unprotected-settings strip has its own layout rule',
    $notice !== '' && str_contains($notice, 'margin:'),
    $notice);
check('and reuses the existing alert styling rather than new colours',
    str_contains($appCss, '.oc-protection-notice .oc-mono')
    && !preg_match('/#([0-9a-fA-F]{3,8})\b/', $notice),
    $notice);

$layout = (string)file_get_contents(__DIR__ . '/../assets/templates/layout.html');
$headerEnd = strpos($layout, '</header>');
$noticeAt  = strpos($layout, 'data-oc-protection-notice');
$mainAt    = strpos($layout, '<main class="oc-main">');
check('the protection notice sits between the header and the shell',
    $headerEnd !== false && $noticeAt !== false && $mainAt !== false
    && $headerEnd < $noticeAt && $noticeAt < $mainAt,
    json_encode(['header' => $headerEnd, 'notice' => $noticeAt, 'main' => $mainAt]));

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
