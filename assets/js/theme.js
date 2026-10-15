/**
 * theme.js — appearance manager: colour scheme, accent preset and density.
 *
 * Three independent axes, each persisted separately, because users ask for
 * them separately:
 *
 *   mode     'light' | 'dark' | 'system'   the colour scheme
 *   accent   'indigo' | 'violet' | …       the brand preset (ui/tokens.css §3)
 *   density  'cozy' | 'comfortable' | 'compact'
 *
 * `mode: 'system'` is a first-class value, not "unset". That distinction is
 * the whole point of this module: a user who explicitly picks *System* and a
 * user who has never been asked must behave differently. The first gets the
 * OS preference and follows it live; the second gets the operator's
 * configured default (`ui.theme` in config.php) and also follows the OS —
 * but the first choice is written to storage the moment they toggle, so the
 * operator default can never overwrite it again.
 *
 * No flash of unstyled/wrong-theme content
 * ---------------------------------------
 * The class and the attributes are written by an inline script in the
 * `<head>` (see assets/js/theme-boot.js, inlined by the template) BEFORE any
 * stylesheet or module executes. This file then attaches to that state and
 * owns subsequent changes. Applying the theme here instead would paint the
 * default theme for one frame on every load — visible as a white flash in
 * dark mode and, worse, as a layout flip in RTL.
 *
 * Persistence is dual: localStorage (read by this module and by the SPA) and
 * a cookie (read by PHP so the server can render `<html class="dark" lang dir>`
 * correctly on the very first byte). The cookie is written from here rather
 * than set by PHP because only the client knows the resolved `system` value.
 *
 * @project RevoAp   @since 2.0.0
 */

/** Storage keys. Namespaced so two RevoAp installs on one origin do not collide. */
export const KEYS = Object.freeze({
    mode: 'revoap.theme.mode',
    accent: 'revoap.theme.accent',
    density: 'revoap.theme.density',
    /** Legacy v1 key, migrated once and then removed. */
    legacyMode: 'oc-theme',
    cookie: 'revoap_theme',
    cookieDensity: 'revoap_density',
});

export const MODES = Object.freeze(['light', 'dark', 'system']);
export const ACCENTS = Object.freeze(['indigo', 'violet', 'blue', 'cyan', 'emerald', 'amber', 'rose', 'slate']);
export const DENSITIES = Object.freeze(['cozy', 'comfortable', 'compact']);

const root = document.documentElement;
const mql = typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

/** @type {Set<function({mode:string,resolved:string,accent:string,density:string}):void>} */
const listeners = new Set();

function safeGet(key) {
    try { return localStorage.getItem(key); } catch { return null; }
}
function safeSet(key, value) {
    try { localStorage.setItem(key, value); return true; } catch { return false; }
}
function safeRemove(key) {
    try { localStorage.removeItem(key); } catch { /* quota/blocked — nothing to clean */ }
}

/**
 * Read a cookie without a full parse of `document.cookie` on every call.
 * Values are URL-decoded because the accent/density names are plain ASCII and
 * the theme is a three-letter word, but decoding keeps this correct if the
 * server ever writes something else.
 */
function readCookie(name) {
    const pairs = document.cookie ? document.cookie.split(';') : [];
    for (const pair of pairs) {
        const idx = pair.indexOf('=');
        if (idx < 0) continue;
        if (pair.slice(0, idx).trim() === name) {
            try { return decodeURIComponent(pair.slice(idx + 1).trim()); } catch { return null; }
        }
    }
    return null;
}

/**
 * Write a cookie that PHP can read on the next request.
 *
 * SameSite=Lax (not Strict): a Strict cookie is dropped on an inbound
 * navigation from an external link, which would reset the theme on every
 * click-through from a dashboard. HttpOnly must be false — this script is
 * the writer. Max-Age of one year matches LocaleNegotiator::COOKIE_TTL.
 */
function writeCookie(name, value, { days = 365 } = {}) {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${days * 86400}; SameSite=Lax${secure}`;
}

/** The operator's configured default, server-rendered. 'system' when unset. */
function configuredMode() {
    const attr = (root.dataset.uiThemeDefault || '').toLowerCase();
    if (MODES.includes(attr)) return attr;
    const meta = document.querySelector('meta[name="oc-ui-theme"]');
    const v = ((meta && meta.getAttribute('content')) || '').toLowerCase();
    if (v === 'light' || v === 'dark') return v;
    return 'system';
}

function configuredAccent() {
    const a = (root.dataset.uiAccentDefault || '').toLowerCase();
    return ACCENTS.includes(a) ? a : 'indigo';
}

function configuredDensity() {
    const d = (root.dataset.uiDensityDefault || '').toLowerCase();
    return DENSITIES.includes(d) ? d : 'comfortable';
}

/**
 * One-time migration from the v1 single `oc-theme` key.
 *
 * v1 stored only 'light' | 'dark' and treated "absent" as "follow system",
 * which conflated an explicit choice with never having been asked. Reading
 * the old value preserves the operator's existing preference across the
 * upgrade; deleting it afterwards stops the two keys from ever disagreeing.
 */
function migrateLegacy() {
    const legacy = safeGet(KEYS.legacyMode);
    if (legacy !== 'light' && legacy !== 'dark') { safeRemove(KEYS.legacyMode); return null; }
    safeRemove(KEYS.legacyMode);
    if (safeGet(KEYS.mode) === null) {
        safeSet(KEYS.mode, legacy);
        writeCookie(KEYS.cookie, legacy);
    }
    return legacy;
}

/** Resolve 'system' to a concrete scheme using the OS preference. */
function resolveMode(mode) {
    if (mode === 'light' || mode === 'dark') return mode;
    return mql && mql.matches ? 'dark' : 'light';
}

function emit(state) {
    for (const fn of listeners) {
        try { fn(state); } catch (err) { console.error('[theme] listener failed', err); }
    }
}

/** Apply the full appearance state to <html> and persist it. */
function apply({ mode, accent, density }, { persist = true } = {}) {
    const resolved = resolveMode(mode);

    // The class is what Tailwind's `darkMode: ['class', '.dark']` matches.
    root.classList.toggle('dark', resolved === 'dark');
    // The attribute is what the pre-v2 `oc-*` stylesheets match. Both are
    // written so the legacy component CSS and the new layer cannot disagree.
    root.dataset.theme = resolved;
    root.dataset.colorMode = mode;      // keeps 'system' visible to the picker
    root.dataset.accent = accent;
    root.dataset.density = density;

    if (persist) {
        safeSet(KEYS.mode, mode);
        safeSet(KEYS.accent, accent);
        safeSet(KEYS.density, density);
        writeCookie(KEYS.cookie, resolved);
        writeCookie(KEYS.cookieDensity, density);
    }

    // Tell a screen reader that the appearance changed. `aria-live` on the
    // document would be far too chatty, so the announcement is scoped to the
    // theme control's own status node.
    announce(resolved, density);

    emit({ mode, resolved, accent, density });
}

let announceTimer = 0;
function announce(resolved, density) {
    const live = document.getElementById('revoap-theme-status');
    if (!live) return;
    // Debounce: a slider drag fires many changes but only the final one is
    // worth announcing.
    clearTimeout(announceTimer);
    announceTimer = setTimeout(() => {
        live.textContent = document.documentElement.lang === 'ru'
            ? `Тема: ${resolved === 'dark' ? 'тёмная' : 'светлая'}, плотность: ${density}`
            : `Theme: ${resolved}, density: ${density}`;
    }, 400);
}

/* --------------------------------------------------------------------- *
 * Public API
 * --------------------------------------------------------------------- */

/** The current state, resolved. */
export function getState() {
    const mode = root.dataset.colorMode || safeGet(KEYS.mode) || configuredMode();
    const resolved = resolveMode(mode);
    return {
        mode: MODES.includes(mode) ? mode : 'system',
        resolved,
        isDark: resolved === 'dark',
        accent: ACCENTS.includes(root.dataset.accent) ? root.dataset.accent : configuredAccent(),
        density: DENSITIES.includes(root.dataset.density) ? root.dataset.density : configuredDensity(),
    };
}

export function setMode(mode) {
    if (!MODES.includes(mode)) return;
    apply({ ...getState(), mode });
}

export function setAccent(accent) {
    if (!ACCENTS.includes(accent)) return;
    apply({ ...getState(), accent });
}

export function setDensity(density) {
    if (!DENSITIES.includes(density)) return;
    apply({ ...getState(), density });
}

/**
 * Cycle light → dark → system.
 *
 * Three states, not two: "System" is where most people actually want to be,
 * and a two-way toggle makes it unreachable once you have left it.
 */
export function cycleMode() {
    const order = ['light', 'dark', 'system'];
    const cur = getState().mode;
    setMode(order[(order.indexOf(cur) + 1) % order.length]);
}

/** Toggle between the two concrete schemes, leaving 'system' behind. */
export function toggleMode() {
    setMode(getState().isDark ? 'light' : 'dark');
}

/** Subscribe to appearance changes. Returns an unsubscribe function. */
export function onChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/**
 * Wire every control on the page that declares an intent through data
 * attributes. Delegated once at the document level so controls injected
 * later (the settings panel rebuilds itself constantly) work without a
 * re-registration.
 *
 *   [data-theme-toggle]            cycles the mode
 *   [data-theme-mode="dark"]       sets that mode
 *   [data-theme-accent="violet"]   sets that accent
 *   [data-theme-density="compact"] sets that density
 */
export function bindControls(scope = document) {
    if (scope.__revoapThemeBound) return;
    scope.__revoapThemeBound = true;

    scope.addEventListener('click', (ev) => {
        const el = ev.target instanceof Element
            ? ev.target.closest('[data-theme-toggle],[data-theme-mode],[data-theme-accent],[data-theme-density]')
            : null;
        if (!el) return;

        if (el.hasAttribute('data-theme-toggle')) {
            ev.preventDefault();
            cycleMode();
            syncControls();
            return;
        }
        const mode = el.dataset.themeMode;
        if (mode) { ev.preventDefault(); setMode(mode); syncControls(); return; }
        const accent = el.dataset.themeAccent;
        if (accent) { ev.preventDefault(); setAccent(accent); syncControls(); return; }
        const density = el.dataset.themeDensity;
        if (density) { ev.preventDefault(); setDensity(density); syncControls(); return; }
    });

    // Segmented controls and radio groups report through `change`.
    scope.addEventListener('change', (ev) => {
        const el = ev.target;
        if (!(el instanceof Element)) return;
        const group = el.closest('[data-theme-group]');
        if (!group || !(el instanceof HTMLInputElement)) return;
        if (!el.checked) return;
        const kind = group.dataset.themeGroup;
        const value = el.value;
        if (kind === 'mode') setMode(value);
        else if (kind === 'accent') setAccent(value);
        else if (kind === 'density') setDensity(value);
        syncControls();
    });

    syncControls();
}

/**
 * Reflect the current state into every bound control: `aria-pressed` /
 * `aria-checked` for buttons, `.checked` for radios, and the icon swap on
 * the cycle toggle. Kept separate from `apply()` so it can be called after
 * a panel re-renders.
 */
export function syncControls() {
    const s = getState();

    for (const btn of document.querySelectorAll('[data-theme-mode],[data-theme-accent],[data-theme-density]')) {
        const want = btn.dataset.themeMode || btn.dataset.themeAccent || btn.dataset.themeDensity;
        const have = btn.dataset.themeMode ? s.mode
            : btn.dataset.themeAccent ? s.accent : s.density;
        const on = want === have;
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.dataset.state = on ? 'active' : 'inactive';
    }

    for (const input of document.querySelectorAll('input[data-theme-radio]')) {
        const group = input.closest('[data-theme-group]')?.dataset.themeGroup;
        input.checked = (group === 'mode' && input.value === s.mode)
            || (group === 'accent' && input.value === s.accent)
            || (group === 'density' && input.value === s.density);
    }

    for (const t of document.querySelectorAll('[data-theme-toggle]')) {
        t.setAttribute('aria-label', t.dataset.labelAll || 'Toggle theme');
        t.dataset.resolved = s.resolved;
        t.dataset.mode = s.mode;
        // The icon is three separate SVGs in the markup; show the matching
        // one so the control always says what the NEXT click will do.
        for (const svg of t.querySelectorAll('svg[data-for]')) {
            svg.hidden = svg.dataset.for !== s.mode;
        }
    }
}

/* --------------------------------------------------------------------- *
 * Boot
 * --------------------------------------------------------------------- */

function init() {
    migrateLegacy();

    // Reconcile: the inline bootstrap script may have written a `system`
    // resolution that has since changed (the user flipped their OS theme
    // while the tab was in the background and the page was restored from
    // the back/forward cache).
    const stored = safeGet(KEYS.mode);
    const mode = MODES.includes(stored) ? stored : configuredMode();
    const accentRaw = safeGet(KEYS.accent);
    const densityRaw = safeGet(KEYS.density);

    apply({
        mode,
        accent: ACCENTS.includes(accentRaw) ? accentRaw : (ACCENTS.includes(root.dataset.accent) ? root.dataset.accent : configuredAccent()),
        density: DENSITIES.includes(densityRaw) ? densityRaw : (DENSITIES.includes(root.dataset.density) ? root.dataset.density : configuredDensity()),
    }, { persist: false });

    // Follow the OS while (and only while) mode === 'system'.
    if (mql) {
        const handler = (e) => {
            if (getState().mode !== 'system') return;
            apply({ ...getState() }, { persist: false });
            void e;
        };
        // addEventListener is baseline since 2020; addListener is gone in 8.5-era browsers.
        mql.addEventListener('change', handler);
    }

    // Re-apply on bfcache restore: Safari hands back a page whose <html>
    // still carries the class from when it was frozen.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) { apply({ ...getState() }, { persist: false }); syncControls(); }
    });

    bindControls(document);

    // Expose a minimal imperative API for the legacy modules that still
    // reach for a global. Frozen, so nothing can monkey-patch it.
    window.RevoApTheme = Object.freeze({
        get: getState,
        setMode, setAccent, setDensity,
        cycleMode, toggleMode, onChange, syncControls,
        MODES, ACCENTS, DENSITIES, KEYS,
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export default { getState, setMode, setAccent, setDensity, cycleMode, toggleMode, onChange, bindControls, syncControls };
