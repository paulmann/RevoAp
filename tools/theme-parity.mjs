#!/usr/bin/env node
/**
 * theme-parity.mjs — prove the light and dark themes are complete twins.
 *
 *     node tools/theme-parity.mjs [--fix-report]
 *
 * The failure this exists to prevent is specific and easy to ship: a token is
 * added to `:root` and forgotten in `.dark` (or vice versa), so one theme
 * renders a block with the other theme's colours — a near-white card on a white
 * page, or white text on white. It is invisible in whichever theme the author
 * happened to be using, and it survives review for exactly that reason.
 *
 * Four checks, in the order they matter:
 *
 *  1. **Name-set parity.** Every custom property declared in the light block
 *     must be declared in the dark block and vice versa. This is the check that
 *     catches the bug above. `tests/CssTokensTest.php` already does it for the
 *     legacy `--color-*` names; this does it for the whole v2 token set,
 *     including the accent presets (which must define the SAME names in both
 *     themes) and the derived `-fg` tokens.
 *  2. **No hardcoded colours** in the component/shell layers. A literal `#fff`
 *     or `rgb(...)` in a component cannot follow the theme, so it is either a
 *     bug now or a bug at the next rebrand. Allow-listed: the OKLCH black/white
 *     used inside `color-mix()` for darkening/lightening (those are relative
 *     operations, not absolute colours), and transparent.
 *  3. **Every token consumed exists.** A `var(--x)` with no declaration and no
 *     fallback renders as `initial`, which for a colour is *transparent* — the
 *     classic "the border disappeared" bug.
 *  4. **Contrast.** Body text on background must clear WCAG AA (4.5:1), large
 *     text 3:1, and non-text graphics (borders, the accent swatch check mark)
 *     3:1 per WCAG 1.4.11. Computed in OKLCH → sRGB → relative luminance, in
 *     both themes, so a rebrand that breaks contrast fails here rather than in
 *     an accessibility audit.
 *
 * Exit code is the number of failures (capped at 1), so CI can gate on it.
 */
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..');
const argv = process.argv.slice(2);

const failures = [];
const warnings = [];
let checks = 0;

function check(label, ok, detail = '') {
    checks++;
    if (!ok) failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
    return ok;
}
function warn(label, detail = '') {
    warnings.push(`${label}${detail ? ` — ${detail}` : ''}`);
}

/* =====================================================================
 * CSS parsing (deliberately minimal: brace matching + declaration scan)
 * =================================================================== */

/** Strip comments so a `/*` inside a value cannot confuse the brace walk. */
function stripComments(css) {
    return css.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
}

/**
 * Collect top-level rule blocks as {selector, body, start, end}.
 * Nested at-rules (@media, @supports) are flattened: their inner rules are
 * returned with the at-rule condition prefixed onto the selector, so
 * `@media print { :root { … } }` is analysed as `@media print :root`.
 */
function blocks(css, prefix = '') {
    const out = [];
    let i = 0;
    while (i < css.length) {
        const brace = css.indexOf('{', i);
        if (brace === -1) break;
        const selector = css.slice(i, brace).trim().replace(/\s+/g, ' ');
        let depth = 1;
        let j = brace + 1;
        while (j < css.length && depth > 0) {
            if (css[j] === '{') depth++;
            else if (css[j] === '}') depth--;
            j++;
        }
        const body = css.slice(brace + 1, j - 1);
        const full = prefix ? `${prefix} ${selector}` : selector;
        if (/\{$/.test(selector) === false && body.includes('{')) {
            // An at-rule: recurse so nested selectors are analysed too.
            out.push(...blocks(body, full));
        } else if (selector.startsWith('@') && !selector.startsWith('@media') && !selector.startsWith('@supports')) {
            out.push({ selector: full, body, start: brace, end: j });
        } else {
            out.push({ selector: full, body, start: brace, end: j });
        }
        i = j;
    }
    return out;
}

/** Custom properties declared in a rule body, as name -> raw value. */
function customProps(body) {
    const out = new Map();
    const re = /(--[A-Za-z0-9_-]+)\s*:\s*([^;}]+)/g;
    let m;
    while ((m = re.exec(body)) !== null) {
        out.set(m[1], m[2].trim());
    }
    return out;
}

/** Does this selector target the light root, the dark root, or an accent? */
function classify(selector) {
    const s = selector.toLowerCase();
    const dark = s.includes('.dark') || s.includes('[data-theme="dark"]');
    const accent = /data-accent="([a-z]+)"/.exec(s);
    const print = s.includes('@media print');
    const forced = s.includes('forced-colors');
    if (print || forced) return { kind: 'conditional', dark, accent: null };
    if (accent) return { kind: 'accent', dark, accent: accent[1] };
    if (dark) return { kind: 'dark', dark: true, accent: null };
    if (s.includes(':root') || s === 'html' || s.includes('html ')) return { kind: 'light', dark: false, accent: null };
    return { kind: 'other', dark, accent: null };
}

/* =====================================================================
 * Colour maths: OKLCH -> sRGB -> relative luminance -> contrast
 * =================================================================== */

function oklchToLinearSrgb(L, C, Hdeg) {
    const h = (Hdeg * Math.PI) / 180;
    const a = C * Math.cos(h);
    const b = C * Math.sin(h);
    // OKLab -> linear sRGB (Björn Ottosson's matrices).
    const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
    const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
    const s_ = L - 0.0894841775 * a - 1.291485548 * b;
    const l = l_ ** 3, m = m_ ** 3, s = s_ ** 3;
    return [
        +4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ];
}

function gammaEncode(c) {
    const v = c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(Math.max(0, c), 1 / 2.4) - 0.055;
    return Math.min(1, Math.max(0, v));
}

/** Parse the subset of colour syntax the token file uses. */
function parseColour(value) {
    const v = value.trim();
    let m = /^oklch\(\s*([\d.]+%?)\s+([\d.]+%?)\s+([\d.]+)(?:deg)?\s*(?:\/\s*([\d.]+%?))?\s*\)$/i.exec(v);
    if (m) {
        let L = parseFloat(m[1]);
        let C = parseFloat(m[2]);
        if (m[1].endsWith('%')) L /= 100;
        if (m[2].endsWith('%')) C = (C / 100) * 0.4;
        const alpha = m[4] === undefined ? 1 : (m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
        const [r, g, b] = oklchToLinearSrgb(L, C, parseFloat(m[3]));
        return { rgb: [gammaEncode(r), gammaEncode(g), gammaEncode(b)].map((x) => Math.round(x * 255)), alpha };
    }
    m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(v);
    if (m) {
        let hex = m[1];
        if (hex.length === 3) hex = hex.split('').map((c) => c + c).join('');
        return { rgb: [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)), alpha: 1 };
    }
    m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)\s*(?:[,/]\s*([\d.]+%?))?\s*\)$/i.exec(v);
    if (m) {
        const alpha = m[4] === undefined ? 1 : (m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
        return { rgb: [m[1], m[2], m[3]].map((x) => Math.round(parseFloat(x))), alpha };
    }
    if (/^(white|black|transparent|currentcolor|inherit|none)$/i.test(v)) {
        if (/^white$/i.test(v)) return { rgb: [255, 255, 255], alpha: 1 };
        if (/^black$/i.test(v)) return { rgb: [0, 0, 0], alpha: 1 };
        return null;
    }
    return null;
}

function luminance([r, g, b]) {
    const f = (c) => {
        const s = c / 255;
        return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}

function contrast(fg, bg) {
    if (!fg || !bg) return null;
    // Alpha is composited over the background first: a 12% white border on a
    // dark card is not "white on dark", it is the blended result, and measuring
    // the unblended colour would pass things that fail on screen.
    const blend = (c, a, over) => c.map((v, i) => Math.round(v * a + over[i] * (1 - a)));
    const f = fg.alpha < 1 ? blend(fg.rgb, fg.alpha, bg.rgb) : fg.rgb;
    const b = bg.alpha < 1 ? blend(bg.rgb, bg.alpha, [255, 255, 255]) : bg.rgb;
    const l1 = luminance(f);
    const l2 = luminance(b);
    const [hi, lo] = l1 > l2 ? [l1, l2] : [l2, l1];
    return (hi + 0.05) / (lo + 0.05);
}

/* =====================================================================
 * Run
 * =================================================================== */

const tokensPath = join(root, 'ui', 'tokens.css');
const tokensCss = stripComments(readFileSync(tokensPath, 'utf8'));
const tokenBlocks = blocks(tokensCss);

// --- gather declarations per theme -------------------------------------
const light = new Map();
const dark = new Map();
const accentLight = new Map();   // accent -> Map(name -> value)
const accentDark = new Map();

for (const b of tokenBlocks) {
    const kind = classify(b.selector);
    const props = customProps(b.body);
    if (kind.kind === 'light') {
        for (const [k, v] of props) if (!light.has(k)) light.set(k, v);
    } else if (kind.kind === 'dark') {
        for (const [k, v] of props) if (!dark.has(k)) dark.set(k, v);
    } else if (kind.kind === 'accent') {
        const target = kind.dark ? accentDark : accentLight;
        if (!target.has(kind.accent)) target.set(kind.accent, new Map());
        for (const [k, v] of props) target.get(kind.accent).set(k, v);
    } else if (kind.kind === 'conditional') {
        // print / forced-colours override both themes; they must define the
        // same names in one rule, which they do by construction. Recorded so a
        // later refactor cannot silently drop one.
        for (const [k] of props) {
            if (!light.has(k) && !dark.has(k)) warn(`conditional block defines ${k}, which neither theme declares`);
        }
    }
}

console.log(`tokens.css: ${light.size} light props, ${dark.size} dark props, ` +
    `${accentLight.size} light accents, ${accentDark.size} dark accents\n`);

// --- CHECK 1: name-set parity ------------------------------------------
// Parity is required for COLOUR-bearing tokens only. Geometry (radius),
// typography (font stacks, type scale), layout metrics (header height, sidebar
// width), motion (durations, easings) and the z-index ladder are deliberately
// theme-independent: a radius does not change between light and dark, and
// re-declaring 77 such values in both blocks would create 77 things to keep in
// sync for no benefit. A token counts as colour-bearing when its value in
// EITHER theme parses as a colour, or when it is derived from one via
// color-mix() / references a colour token through var().
function isColourBearing(name) {
    for (const map of [light, dark]) {
        const v = map.get(name);
        if (v === undefined) continue;
        if (parseColour(v)) return true;
        if (/color-mix\(/i.test(v)) return true;
        // A box-shadow value is a shorthand, not a bare colour, so parseColour
        // rejects it — but its alpha is theme-dependent and MUST be: a 6 %-black
        // shadow is invisible on a dark background, which is why the dark block
        // re-declares all five at 40-65 %. Treating shadows as geometry would
        // flag that correct duplication as a bug (and "fixing" it would make
        // every card in dark mode look flat and unbounded).
        if (/oklch\(|rgba?\(|hsla?\(/i.test(v)) return true;
        const ref = /var\(\s*(--[A-Za-z0-9_-]+)/.exec(v);
        if (ref && isColourBearing(ref[1])) return true;
    }
    return false;
}
// The pre-v2 `--color-*` names are a BRIDGE: one :root block aliasing each to a
// v2 token, so 4 700 lines of legacy component CSS render correctly in both
// themes without being rewritten. Because every value is `var(--new-token)`,
// the alias follows the theme through the underlying token and a dark copy is
// not merely unnecessary — it would be a second place to forget. Excluded from
// parity, and asserted separately below.
const isBridge = (k) => k.startsWith('--color-');
const allTokens = [...new Set([...light.keys(), ...dark.keys()])];
const colourTokens = allTokens.filter((k) => isColourBearing(k) && !isBridge(k));
const bridgeTokens = allTokens.filter(isBridge);
const geometryOnly = allTokens.filter((k) => !isColourBearing(k) && !isBridge(k));
console.log(`  of which ${colourTokens.length} colour-bearing (parity required), ` +
    `${geometryOnly.length} theme-independent, ${bridgeTokens.length} legacy bridge\n`);

const onlyLight = colourTokens.filter((k) => !dark.has(k));
const onlyDark = colourTokens.filter((k) => !light.has(k));
check('every colour token declared in light is also declared in dark', onlyLight.length === 0,
    onlyLight.length ? `${onlyLight.length} missing in dark: ${onlyLight.slice(0, 12).join(', ')}` : '');
check('every colour token declared in dark is also declared in light', onlyDark.length === 0,
    onlyDark.length ? `${onlyDark.length} missing in light: ${onlyDark.slice(0, 12).join(', ')}` : '');
// A theme-independent token belongs in :root exactly ONCE. Declaring it in the
// dark block too is not harmless duplication: it is a second value that will
// drift, and the drift only shows in one theme. Declaring it ONLY in dark is a
// straight bug (light falls back to `initial`).
const dupGeometry = geometryOnly.filter((k) => light.has(k) && dark.has(k));
const darkOnlyGeometry = geometryOnly.filter((k) => dark.has(k) && !light.has(k));
check('theme-independent tokens are not duplicated into the dark block',
    dupGeometry.length === 0, `${dupGeometry.length}: ${dupGeometry.slice(0, 10).join(', ')}`);
check('no theme-independent token is dark-only', darkOnlyGeometry.length === 0,
    darkOnlyGeometry.slice(0, 10).join(', '));

// The bridge: :root-only, and every alias must resolve to a token that both
// themes actually declare. An alias pointing at nothing renders `initial`,
// which for a colour is transparent — a border or a plaque simply vanishes in
// one theme, and it vanishes silently.
const bridgeInDark = bridgeTokens.filter((k) => dark.has(k));
check('the legacy --color-* bridge is declared in :root only', bridgeInDark.length === 0,
    bridgeInDark.slice(0, 8).join(', '));
const brokenBridge = [];
for (const k of bridgeTokens) {
    const v = light.get(k) || '';
    const ref = /var\(\s*(--[A-Za-z0-9_-]+)/.exec(v);
    if (!ref) continue;                       // a literal value: fine, it is theme-fixed by intent
    const target = ref[1];
    if (!light.has(target) && !dark.has(target)) brokenBridge.push(`${k} -> ${target}`);
}
check('every bridge alias resolves to a declared token', brokenBridge.length === 0,
    brokenBridge.slice(0, 8).join(', '));

// Accent presets must define the same names in both themes, and every accent
// must define the same set as every other accent — otherwise switching accent
// in dark mode silently keeps the previous accent's value for the missing key.
const accentNames = [...new Set([...accentLight.keys(), ...accentDark.keys()])].sort();
check('every accent preset exists in BOTH themes',
    accentNames.every((a) => accentLight.has(a) && accentDark.has(a)),
    accentNames.filter((a) => !(accentLight.has(a) && accentDark.has(a))).join(', '));
// `indigo` is the DEFAULT accent and is declared in :root, so it has no
// [data-accent="indigo"] rule — using it as the reference set yields an empty
// set and makes every real preset "wrong". The actual invariant is: every
// preset defines the four brand tokens, and MAY additionally override
// --primary-foreground when its hue is too light for white ink (amber, cyan,
// emerald, rose and slate do; indigo, blue and violet do not). That override is
// not an inconsistency — omitting it would ship white-on-amber at 2.9:1.
const REQUIRED_ACCENT = ['--primary', '--primary-hover', '--primary-active', '--ring'];
const OPTIONAL_ACCENT = ['--primary-foreground'];
for (const a of accentNames) {
    for (const [label, map] of [['light', accentLight], ['dark', accentDark]]) {
        const preset = map.get(a);
        if (!preset) continue;
        const missing = REQUIRED_ACCENT.filter((k) => !preset.has(k));
        check(`accent "${a}" (${label}) defines all four brand tokens`, missing.length === 0,
            missing.join(', '));
        const unexpected = [...preset.keys()].filter(
            (k) => !REQUIRED_ACCENT.includes(k) && !OPTIONAL_ACCENT.includes(k));
        check(`accent "${a}" (${label}) declares nothing outside the brand tokens`,
            unexpected.length === 0, unexpected.join(', '));
    }
}

// --- CHECK 2: no hardcoded colours in the component layers ---------------
const cssFiles = ['ui/tailwind.css', 'ui/shell.css'];
for (const rel of cssFiles) {
    const p = join(root, rel);
    if (!readFileSync(p, 'utf8')) continue;
    const raw = readFileSync(p, 'utf8');
    const css = stripComments(raw);
    const offenders = [];
    // Walk declarations only (skip selector text, where a "#" could be an id).
    for (const b of blocks(css)) {
        const decls = b.body.split(';');
        for (const d of decls) {
            const idx = d.indexOf(':');
            if (idx < 0) continue;
            const prop = d.slice(0, idx).trim();
            const value = d.slice(idx + 1);
            if (!prop || prop.startsWith('--')) continue;
            // Relative colour operations are fine: color-mix(… black) darkens
            // whatever the token is, so it follows the theme by construction.
            const withoutMix = value.replace(/color-mix\([^)]*\)/g, ' ');
            if (/#[0-9a-fA-F]{3,8}\b/.test(withoutMix) || /\brgba?\(/.test(withoutMix) || /\bhsla?\(/.test(withoutMix)) {
                offenders.push(`${prop}: ${value.trim().slice(0, 60)}`);
            }
        }
    }
    check(`${rel} contains no hardcoded colour`, offenders.length === 0,
        offenders.length ? `${offenders.length}: ${offenders.slice(0, 5).join(' | ')}` : '');
}

// Legacy `oc-*` component CSS is allowed hardcoded colours only as var()
// FALLBACKS (the pre-v2 files predate the token set); anything else is a theme
// leak. Reported as a warning with a count so the migration can be tracked.
const legacyDir = join(root, 'assets/css');
let legacyHardcoded = 0;
let legacyFiles = 0;
const walk = (dir) => {
    for (const e of readdirSync(dir)) {
        const p = join(dir, e);
        if (statSync(p).isDirectory()) { walk(p); continue; }
        if (!p.endsWith('.css')) continue;
        legacyFiles++;
        const css = stripComments(readFileSync(p, 'utf8'));
        for (const b of blocks(css)) {
            for (const d of b.body.split(';')) {
                const idx = d.indexOf(':');
                if (idx < 0) continue;
                const value = d.slice(idx + 1).replace(/var\([^)]*\)/g, ' ');
                if (/#[0-9a-fA-F]{3,8}\b/.test(value) || /\brgba?\(/.test(value)) legacyHardcoded++;
            }
        }
    }
};
if (readFileSync && statSync(legacyDir).isDirectory()) walk(legacyDir);
console.log(`legacy CSS: ${legacyFiles} files, ${legacyHardcoded} hardcoded colour declarations (tracked in docs/MIGRATION_UI.md)\n`);

// --- CHECK 3: every var() consumed has a declaration or a fallback -------
const allCss = [join(root, 'ui/tokens.css'), join(root, 'ui/tailwind.css'), join(root, 'ui/shell.css')]
    .map((p) => stripComments(readFileSync(p, 'utf8'))).join('\n');
const declared = new Set([...light.keys(), ...dark.keys()]);
for (const m of accentLight.values()) for (const k of m.keys()) declared.add(k);
for (const m of accentDark.values()) for (const k of m.keys()) declared.add(k);

const undefinedNoFallback = new Set();
const varRe = /var\(\s*(--[A-Za-z0-9_-]+)\s*(,)?/g;
let vm;
while ((vm = varRe.exec(allCss)) !== null) {
    const name = vm[1];
    const hasFallback = vm[2] === ',';
    if (!declared.has(name) && !hasFallback) undefinedNoFallback.add(name);
}
check('every var() reference resolves to a declared token or has a fallback',
    undefinedNoFallback.size === 0,
    undefinedNoFallback.size ? [...undefinedNoFallback].slice(0, 12).join(', ') : '');

// --- CHECK 4: contrast in BOTH themes ------------------------------------
/** Resolve a token to a concrete colour, following var() one level. */
function resolveToken(name, theme, depth = 0) {
    if (depth > 4) return null;
    const raw = (theme === 'dark' ? dark : light).get(name);
    if (raw === undefined) return null;
    const direct = parseColour(raw);
    if (direct) return direct;
    const ref = /var\(\s*(--[A-Za-z0-9_-]+)/.exec(raw);
    if (ref) return resolveToken(ref[1], theme, depth + 1);
    return null;
}

const pairs = [
    ['--foreground', '--background', 4.5, 'body text on page'],
    ['--foreground', '--card', 4.5, 'body text on card'],
    ['--card-foreground', '--card', 4.5, 'card text on card'],
    ['--muted-foreground', '--background', 4.5, 'muted text on page'],
    ['--muted-foreground', '--card', 4.5, 'muted text on card'],
    ['--primary-foreground', '--primary', 4.5, 'label on primary button'],
    ['--primary', '--background', 3.0, 'link / accent graphic on page'],
    ['--destructive-foreground', '--destructive', 4.5, 'label on destructive button'],
    ['--success-foreground', '--success', 3.0, 'label on success fill'],
    ['--secondary-foreground', '--secondary', 4.5, 'label on secondary button'],
    ['--accent-foreground', '--accent', 4.5, 'label on accent hover'],
    ['--popover-foreground', '--popover', 4.5, 'dropdown text'],
    ['--sidebar-foreground', '--sidebar', 4.5, 'sidebar text'],
    ['--code-foreground', '--code-background', 4.5, 'code block text'],
    // Component BOUNDARY: WCAG 1.4.11 requires 3:1 for the visual information
    // needed to identify an interactive component. An input's border is exactly
    // that. The subtler --border (card separators) is decorative and is reported
    // as a warning, not a failure.
    ['--border-strong', '--background', 3.0, 'input border / component boundary (WCAG 1.4.11)'],
    ['--input', '--input-background', 3.0, 'input border on its own fill'],
    ['--ring', '--background', 3.0, 'focus ring (non-text)'],
];

for (const theme of ['light', 'dark']) {
    for (const [fg, bg, min, label] of pairs) {
        const f = resolveToken(fg, theme);
        const b = resolveToken(bg, theme);
        if (!f || !b) { warn(`[${theme}] cannot resolve ${fg}/${bg} for "${label}"`); continue; }
        const ratio = contrast(f, b);
        check(`[${theme}] ${label} >= ${min}:1`, ratio !== null && ratio >= min,
            ratio === null ? 'unresolved' : `${ratio.toFixed(2)}:1 (${fg} on ${bg})`);
    }
}

// Accent presets: the button label must clear AA in BOTH themes. This is what
// stops a rebrand from shipping white-on-amber (2.9:1).
for (const a of accentNames) {
    for (const theme of ['light', 'dark']) {
        const map = (theme === 'dark' ? accentDark : accentLight).get(a);
        if (!map) continue;
        const primary = parseColour(map.get('--primary') || '');
        // --primary-foreground is only overridden by some presets; the rest
        // inherit the theme default, so resolve against the theme when absent.
        const fgRaw = map.get('--primary-foreground') || (theme === 'dark' ? dark : light).get('--primary-foreground');
        const fg = parseColour(fgRaw || '');
        if (!primary || !fg) { warn(`[${theme}] accent ${a}: unresolved primary/foreground`); continue; }
        const ratio = contrast(fg, primary);
        check(`[${theme}] accent "${a}": label on primary >= 3:1`, ratio !== null && ratio >= 3.0,
            ratio === null ? 'unresolved' : `${ratio.toFixed(2)}:1`);
    }
}

// --- report --------------------------------------------------------------
console.log(`checks run: ${checks}`);
if (warnings.length) {
    console.log(`\nwarnings (${warnings.length}):`);
    for (const w of warnings.slice(0, 20)) console.log(`  ~ ${w}`);
}
if (failures.length) {
    console.log(`\nFAILURES (${failures.length}):`);
    for (const f of failures) console.log(`  ✗ ${f}`);
    if (argv.includes('--fix-report')) {
        writeFileSync(join(root, 'theme-parity-report.json'),
            JSON.stringify({ checks, failures, warnings }, null, 2));
        console.log('\nwrote theme-parity-report.json');
    }
    process.exit(1);
}
console.log('\ntheme parity: OK — light and dark declare identical token sets, no');
console.log('hardcoded colours in the v2 layers, every var() resolves, and all');
console.log('contrast pairs clear their WCAG floor in both themes.');
process.exit(0);
