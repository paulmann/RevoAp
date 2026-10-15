/**
 * theme-boot.js — the inline `<head>` appearance bootstrap.
 *
 * This file is INLINED into the HTML `<head>` by the template renderer (it is
 * 60 lines and must run before the first paint), not linked as a module. A
 * module script is deferred to after the document has parsed and painted, so
 * using one here would guarantee a visible flash: white page → dark page, and
 * in Arabic a whole layout flip left-to-right → right-to-left.
 *
 * The CSP forbids `script-src 'unsafe-inline'`, so the renderer injects this
 * source into a script tag carrying the per-request CSP nonce — the same value
 * the Content-Security-Policy header allows. (This comment deliberately does
 * not spell the template placeholder out: this file is INLINED into the
 * document, so a literal double-brace token in it would be interpolated by
 * App\Helpers\TemplateHelper on the way out, and would look like an
 * unrendered placeholder to anyone grepping the served HTML.)
 *
 * The PHP renderer reads this file back at request time, so there is exactly
 * one copy of the logic and it cannot drift from the module version in
 * assets/js/theme.js.
 *
 * What it does, in order, all synchronously:
 *   1. read the persisted mode / accent / density (localStorage, then cookie);
 *   2. resolve 'system' against prefers-color-scheme;
 *   3. resolve the locale and set lang + dir on <html>;
 *   4. write class="dark" + data-* attributes.
 *
 * It deliberately does NOT write anything back to storage and does NOT
 * register listeners — assets/js/theme.js owns mutation. This script only
 * reads, so a browser with storage disabled still renders the right theme.
 *
 * Failure mode: if anything here throws, the browser keeps the
 * server-rendered attributes, which the PHP side already set from the cookie.
 * That is why every read is wrapped: a boot script that throws would leave
 * the page unstyled-by-theme, which is worse than a wrong guess.
 */
(function () {
    'use strict';
    try {
        var root = document.documentElement;
        var MODES = ['light', 'dark', 'system'];
        var ACCENTS = ['indigo', 'violet', 'blue', 'cyan', 'emerald', 'amber', 'rose', 'slate'];
        var DENSITIES = ['cozy', 'comfortable', 'compact'];
        var LOCALES = ['en', 'de', 'ru', 'zh', 'ar'];

        function get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
        function cookie(name) {
            var parts = document.cookie ? document.cookie.split(';') : [];
            for (var i = 0; i < parts.length; i++) {
                var eq = parts[i].indexOf('=');
                if (eq < 0) continue;
                if (parts[i].slice(0, eq).replace(/^\s+|\s+$/g, '') === name) {
                    try { return decodeURIComponent(parts[i].slice(eq + 1).replace(/^\s+|\s+$/g, '')); }
                    catch (e) { return null; }
                }
            }
            return null;
        }
        function pick(value, allowed, fallback) {
            return allowed.indexOf(value) >= 0 ? value : fallback;
        }

        /* --- locale: ?lang= > cookie > server-rendered > navigator ------ */
        var tag = null;
        try {
            var q = new URLSearchParams(location.search).get('lang');
            if (q) tag = q;
        } catch (e) { /* URLSearchParams is baseline; guard anyway */ }
        if (!tag) tag = cookie('revoap_locale');
        if (!tag) tag = root.getAttribute('lang');
        tag = String(tag || 'en').toLowerCase().replace(/_/g, '-');
        var primary = tag.split('-')[0];
        var locale = pick(primary, LOCALES, 'en');
        // zh-TW / zh-Hant render in the shipped Simplified catalog rather than
        // falling back to English — see App\I18n\Locale::fromTag().
        if (primary === 'zh') locale = 'zh';

        var dir = locale === 'ar' ? 'rtl' : 'ltr';
        root.setAttribute('lang', locale);
        root.setAttribute('dir', dir);
        root.setAttribute('data-locale', locale);

        /* --- colour mode ------------------------------------------------ */
        var configured = (root.getAttribute('data-ui-theme-default') || 'system').toLowerCase();
        configured = pick(configured, MODES, 'system');
        var mode = pick(get('revoap.theme.mode'), MODES, configured);

        var dark;
        if (mode === 'dark') dark = true;
        else if (mode === 'light') dark = false;
        else dark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);

        if (dark) root.classList.add('dark'); else root.classList.remove('dark');
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        root.setAttribute('data-color-mode', mode);

        /* --- accent + density ------------------------------------------- */
        var accentCfg = (root.getAttribute('data-ui-accent-default') || 'indigo').toLowerCase();
        var accent = pick(get('revoap.theme.accent'), ACCENTS,
            pick(cookie('revoap_accent'), ACCENTS, pick(accentCfg, ACCENTS, 'indigo')));
        root.setAttribute('data-accent', accent);

        var densityCfg = (root.getAttribute('data-ui-density-default') || 'comfortable').toLowerCase();
        var density = pick(get('revoap.theme.density'), DENSITIES,
            pick(cookie('revoap_density'), DENSITIES, pick(densityCfg, DENSITIES, 'comfortable')));
        root.setAttribute('data-density', density);

        /* --- mobile viewport height ------------------------------------- *
         * `100vh` on mobile browsers includes the URL bar, so a full-height
         * shell overflows and the bottom navigation gets pushed off-screen.
         * --vvh is recomputed on resize/orientationchange; dvh is used where
         * supported, and this variable is the fallback for the rest.
         * --------------------------------------------------------------- */
        function setVh() {
            var h = window.innerHeight || root.clientHeight;
            root.style.setProperty('--vvh', h + 'px');
        }
        setVh();
        var vhTimer = 0;
        window.addEventListener('resize', function () {
            clearTimeout(vhTimer);
            vhTimer = setTimeout(setVh, 120);
        }, { passive: true });
        window.addEventListener('orientationchange', function () { setTimeout(setVh, 250); }, { passive: true });

        /* --- colour-scheme for form controls ---------------------------- *
         * Native <select>, <input type="date"> and scrollbars follow
         * `color-scheme`. It is set in tokens.css per theme, but setting it
         * here too means the very first paint of a native control is already
         * in the right palette rather than white-on-dark for one frame.
         * --------------------------------------------------------------- */
        root.style.colorScheme = dark ? 'dark' : 'light';
    } catch (e) {
        /* Never let a boot error blank the page. The server-rendered
           attributes already describe a usable theme. */
        if (window.console && console.warn) console.warn('[revoap] theme boot failed', e);
    }
})();
