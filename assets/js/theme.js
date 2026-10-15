// theme.js — light/dark theme toggle with system preference detection.
//
// v1.8.33: honours the operator-configured default theme (server-rendered
// into <html data-ui-theme-default="..."> / <meta name="oc-ui-theme">).
//   - "system": legacy behaviour — prefers-color-scheme drives the
//     initial theme unless the user explicitly toggled.
//   - "light" / "dark": forced default for first-time visitors; the
//     user can still toggle and the choice is remembered in
//     localStorage. The configured default never overrides an
//     existing user choice.
const STORAGE_KEY = 'oc-theme';
const html = document.documentElement;

function preferred() {
    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
}

function configuredDefault() {
    const attr = (html.dataset.uiThemeDefault || '').toLowerCase();
    if (attr === 'light' || attr === 'dark') return attr;
    const meta = document.querySelector('meta[name="oc-ui-theme"]');
    const v = (meta && meta.getAttribute('content') || '').toLowerCase();
    if (v === 'light' || v === 'dark') return v;
    return null;
}

function apply(theme) {
    html.dataset.theme = theme;
    try { localStorage.setItem(STORAGE_KEY, theme); } catch { /* storage may be blocked */ }
}

function init() {
    const stored = (() => { try { return localStorage.getItem(STORAGE_KEY); } catch { return null; } })();
    if (stored === 'light' || stored === 'dark') {
        apply(stored);
    } else {
        // No user choice yet — pick configured default, otherwise
        // prefers-color-scheme.
        const def = configuredDefault();
        const initial = def || preferred();
        html.dataset.theme = initial; // do not persist — keep "follow system" semantics when "system" was selected
    }
    const btn = document.getElementById('theme-toggle');
    if (btn) {
        btn.addEventListener('click', () => {
            apply(html.dataset.theme === 'dark' ? 'light' : 'dark');
        });
    }
    window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', (e) => {
        const userPick = (() => { try { return localStorage.getItem(STORAGE_KEY); } catch { return null; } })();
        const def = configuredDefault();
        if (!userPick && !def) {
            html.dataset.theme = e.matches ? 'light' : 'dark';
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
