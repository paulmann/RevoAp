/**
 * i18n.js — client-side translation runtime.
 *
 * The catalogue is embedded in the first response as a
 * `<script type="application/json" id="revoap-i18n-catalog">` block, NOT
 * fetched. That choice is the difference between a console that is already
 * translated on the first paint and one that renders English for 200 ms and
 * then reflows into Russian (and, for Arabic, flips the entire layout
 * direction after the user has started reading). The payload is ~35 KB
 * gzipped and is cached by the browser with the document.
 *
 * `/api/i18n/{locale}` still exists and is used for two things: switching
 * language without a full reload, and the admin Localization panel's
 * coverage report.
 *
 * Parity with the server
 * ---------------------
 * `App\I18n\Translator` and this module implement the SAME contract — same
 * key format, same `{placeholder}` syntax, same CLDR plural categories, same
 * fallback chain. `tests/I18nParityTest.php` runs a table of (locale, count)
 * pairs through the PHP rules and asserts this file's `pluralCategory()`
 * agrees, so the two cannot drift.
 *
 * XSS
 * ---
 * `t()` returns a plain string. Every consumer assigns it through
 * `textContent`, an attribute setter, or `el.append()` — never through
 * `innerHTML`. Catalogues are operator-controlled, but a translation that
 * arrives via the admin Localization editor is user input, so the rule is
 * absolute and `tests/I18nXssTest.php` asserts no call site in this module
 * reaches `innerHTML` with a translated value.
 *
 * @project RevoAp   @since 2.0.0
 */

/** CLDR plural categories per locale, canonical order (mirrors PluralRules.php). */
const CATEGORIES = Object.freeze({
    en: ['one', 'other'],
    de: ['one', 'other'],
    ru: ['one', 'few', 'many', 'other'],
    zh: ['other'],
    ar: ['zero', 'one', 'two', 'few', 'many', 'other'],
});

/** Locales that are right-to-left. */
const RTL = Object.freeze(['ar']);

/** Where the choice is persisted. Mirrors LocaleNegotiator::COOKIE_NAME. */
const STORAGE_KEY = 'revoap.locale';
const COOKIE_NAME = 'revoap_locale';
const QUERY_PARAM = 'lang';

/** @type {Record<string,string>} flat dotted map */
let catalog = {};
/** @type {string} */
let active = 'en';
/** @type {Set<Function>} */
const listeners = new Set();
/** @type {Map<string,number>} keys asked for but absent — surfaced in dev */
const missing = new Map();

/* --------------------------------------------------------------------- *
 * Plural selection
 * --------------------------------------------------------------------- */

/**
 * CLDR cardinal category for a count in a locale.
 *
 * Mirrors App\I18n\PluralRules::category() exactly. `Math.floor(Math.abs(n))`
 * is the integer part; fractions go to 'other' except in Russian, where the
 * integer part still governs ("1.5 минуты" reads as few).
 *
 * @param {string} locale
 * @param {number} n
 * @returns {string}
 */
export function pluralCategory(locale, n) {
    const abs = Math.abs(Number(n) || 0);
    const i = Math.floor(abs);
    const isFractional = Math.abs(abs - i) > 1e-9;
    const lang = String(locale || 'en').slice(0, 2);

    if (lang === 'ru') return russian(i);
    if (lang === 'ar') return isFractional ? 'other' : arabic(i);
    if (lang === 'zh') return 'other';
    // en, de and anything unrecognised: the two-way rule.
    if (isFractional) return 'other';
    return i === 1 ? 'one' : 'other';
}

function russian(i) {
    const m10 = i % 10;
    const m100 = i % 100;
    if (m10 === 1 && m100 !== 11) return 'one';
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'few';
    if (m10 === 0 || (m10 >= 5 && m10 <= 9) || (m100 >= 11 && m100 <= 14)) return 'many';
    return 'other';
}

function arabic(i) {
    if (i === 0) return 'zero';
    if (i === 1) return 'one';
    if (i === 2) return 'two';
    const m100 = i % 100;
    if (m100 >= 3 && m100 <= 10) return 'few';
    if (m100 >= 11 && m100 <= 99) return 'many';
    return 'other';
}

/* --------------------------------------------------------------------- *
 * Interpolation
 * --------------------------------------------------------------------- */

/**
 * Replace `{name}` and `{name, kind}` placeholders.
 *
 * `{{literal}}` emits a literal brace pair, so a message can show JSON. An
 * unknown placeholder is left verbatim — a visible `{model}` in the UI is a
 * bug report; an empty string is not.
 *
 * @param {string} message
 * @param {Record<string, unknown>} params
 */
function interpolate(message, params) {
    if (!message || message.indexOf('{') === -1) return message;
    const SENT = '\u0000B';
    let out = message.replace(/\{\{/g, SENT + 'L').replace(/\}\}/g, SENT + 'R');

    out = out.replace(/\{\s*([A-Za-z0-9_.\-]+)\s*(?:,\s*(number|percent|date|datetime|integer))?\s*\}/g,
        (whole, name, kind) => {
            if (!Object.prototype.hasOwnProperty.call(params, name)) return whole;
            const value = params[name];
            switch (kind) {
                case 'number':
                case 'integer':
                    return formatNumber(value, kind === 'integer' ? 0 : (Number.isInteger(value) ? 0 : 2));
                case 'percent':
                    return formatPercent(value);
                case 'date':
                    return formatDate(value);
                case 'datetime':
                    return formatDateTime(value);
                default:
                    if (value === null || value === undefined) return '';
                    if (typeof value === 'object') {
                        try { return JSON.stringify(value); } catch { return String(value); }
                    }
                    return String(value);
            }
        });

    return out.replace(new RegExp(SENT + 'L', 'g'), '{').replace(new RegExp(SENT + 'R', 'g'), '}');
}

/* --------------------------------------------------------------------- *
 * Formatting — Intl with a hand-rolled fallback.
 *
 * Intl is available in every browser that supports ES modules, but a
 * malformed locale tag makes the constructor throw, and this console runs on
 * operators' machines with exotic system locales. Each helper therefore
 * degrades to a deterministic ASCII rendering rather than propagating.
 * --------------------------------------------------------------------- */

function bcp47() {
    return ({ en: 'en-US', de: 'de-DE', ru: 'ru-RU', zh: 'zh-CN', ar: 'ar-EG' })[active] || 'en-US';
}

/** @param {number} value @param {number} [decimals] */
export function formatNumber(value, decimals = 0) {
    const n = Number(value);
    if (!Number.isFinite(n)) return String(value ?? '');
    try {
        return new Intl.NumberFormat(bcp47(), {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(n);
    } catch {
        return n.toFixed(decimals);
    }
}

/** Compact form for stat tiles: 12 400 → "12.4K" / "12,4 тыс." */
export function formatCompact(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return String(value ?? '');
    try {
        return new Intl.NumberFormat(bcp47(), { notation: 'compact', maximumFractionDigits: 1 }).format(n);
    } catch {
        return String(Math.round(n));
    }
}

/** @param {number} fraction 0..1 */
export function formatPercent(fraction, decimals = 1) {
    const n = Number(fraction);
    if (!Number.isFinite(n)) return String(fraction ?? '');
    try {
        return new Intl.NumberFormat(bcp47(), {
            style: 'percent',
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(n);
    } catch {
        return (n * 100).toFixed(decimals) + '%';
    }
}

function toDate(value) {
    if (value instanceof Date) return value;
    if (typeof value === 'number') {
        // A 10-digit value is seconds (PHP `time()`); a 13-digit one is
        // milliseconds. Guessing wrong here puts events in 1970 or in the
        // year 56 000, and both look like a broken UI rather than a bug.
        return new Date(value < 1e12 ? value * 1000 : value);
    }
    const d = new Date(String(value));
    return Number.isNaN(d.getTime()) ? null : d;
}

export function formatDate(value) {
    const d = toDate(value);
    if (!d) return String(value ?? '');
    try {
        return new Intl.DateTimeFormat(bcp47(), { year: 'numeric', month: 'short', day: 'numeric' }).format(d);
    } catch {
        return d.toISOString().slice(0, 10);
    }
}

export function formatDateTime(value) {
    const d = toDate(value);
    if (!d) return String(value ?? '');
    try {
        return new Intl.DateTimeFormat(bcp47(), {
            year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
        }).format(d);
    } catch {
        return d.toISOString().slice(0, 16).replace('T', ' ');
    }
}

export function formatTime(value) {
    const d = toDate(value);
    if (!d) return String(value ?? '');
    try {
        return new Intl.DateTimeFormat(bcp47(), { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(d);
    } catch {
        return d.toISOString().slice(11, 19);
    }
}

/**
 * Duration in words: 93 → "1m 33s" / "1 мин 33 с".
 *
 * Not `Intl.DurationFormat` — it is not yet baseline across the browsers this
 * console supports, and a fallback would be needed anyway.
 */
export function formatDuration(seconds) {
    const s = Math.max(0, Math.round(Number(seconds) || 0));
    if (s < 60) return t('duration.seconds', { n: s });
    if (s < 3600) {
        const m = Math.floor(s / 60);
        const rest = s % 60;
        return rest === 0 ? t('duration.minutes', { n: m }) : `${t('duration.minutes_short', { n: m })} ${t('duration.seconds_short', { n: rest })}`;
    }
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return m === 0 ? t('duration.hours', { n: h }) : `${t('duration.hours_short', { n: h })} ${t('duration.minutes_short', { n: m })}`;
}

/** Byte size, always in the binary (1024) ladder because these are payloads. */
export function formatBytes(bytes) {
    const n = Number(bytes);
    if (!Number.isFinite(n) || n < 0) return String(bytes ?? '');
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i += 1; }
    const text = i === 0 ? String(Math.round(v)) : formatNumber(v, v < 10 ? 1 : 0);
    return `${text}\u00A0${units[i]}`;
}

/** Relative time. Mirrors Translator::timeAgo(). */
export function timeAgo(value) {
    const d = toDate(value);
    if (!d) return String(value ?? '');
    const diff = Math.round((Date.now() - d.getTime()) / 1000);
    const future = diff < 0;
    const abs = Math.abs(diff);
    const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, size] of units) {
        if (abs >= size) {
            const n = Math.floor(abs / size);
            return tn(`time.${unit}.${future ? 'from_now' : 'ago'}`, n, { n });
        }
    }
    return t('time.just_now');
}

/* --------------------------------------------------------------------- *
 * Core lookup
 * --------------------------------------------------------------------- */

/**
 * Translate a key.
 *
 * @param {string} key   dotted catalogue key
 * @param {Record<string, unknown>} [params]
 * @returns {string} never throws; returns the key itself when untranslated,
 *                   which is the behaviour that makes a missing string
 *                   findable in a screenshot.
 */
export function t(key, params = {}) {
    const value = lookup(key);
    if (value === null) {
        missing.set(key, (missing.get(key) || 0) + 1);
        return interpolate(String(key), params);
    }
    return interpolate(value, params);
}

/**
 * Translate a pluralised key.
 *
 * @param {string} key    base key; the catalogue holds `key.one`, `key.few`, …
 * @param {number} count
 * @param {Record<string, unknown>} [params]
 */
export function tn(key, count, params = {}) {
    const n = Number(count) || 0;
    const merged = { count: n, n, ...params };
    const cat = pluralCategory(active, n);

    let value = lookup(`${key}.${cat}`);
    if (value === null) {
        // Degrade down the locale's canonical categories, ending at `other`.
        for (const candidate of (CATEGORIES[active.slice(0, 2)] || ['other'])) {
            value = lookup(`${key}.${candidate}`);
            if (value !== null) break;
        }
    }
    if (value === null) value = lookup(key);
    if (value === null) {
        missing.set(key, (missing.get(key) || 0) + 1);
        return interpolate(String(key), merged);
    }
    return interpolate(value, merged);
}

/** Raw lookup with no interpolation and no missing-key bookkeeping. */
function lookup(key) {
    if (Object.prototype.hasOwnProperty.call(catalog, key)) return catalog[key];
    return null;
}

/** True when the key exists in the active catalogue. */
export function has(key) {
    return Object.prototype.hasOwnProperty.call(catalog, key);
}

/** Every key asked for but not found, with hit counts. For the dev overlay. */
export function missingKeys() {
    return Array.from(missing.entries()).map(([key, count]) => ({ key, count })).sort((a, b) => b.count - a.count);
}

/** True when the active locale renders right-to-left. */
export function isRtl() {
    return RTL.includes(String(active).slice(0, 2));
}

export function locale() { return active; }
export function direction() { return isRtl() ? 'rtl' : 'ltr'; }

/* --------------------------------------------------------------------- *
 * DOM binding
 * --------------------------------------------------------------------- */

/**
 * Translate the static markup under `scope`.
 *
 * Three attributes are honoured:
 *
 *   data-i18n="chat.send"                    → textContent
 *   data-i18n-attr="aria-label:nav.chat;title:nav.chat"
 *   the data-i18n-html attribute               → innerHTML, ONLY for keys that
 *                                              the operator controls and that
 *                                              are known to contain markup.
 *
 * `data-i18n-html` is the one dangerous path and is therefore explicit,
 * opt-in per element, and passed through `sanitizeHtml()` rather than
 * assigned raw. Static markup in the templates uses `data-i18n`; the
 * `-html` variant exists for the Help panel, whose source is Markdown
 * rendered server-side.
 *
 * @param {ParentNode} [scope]
 */
export function applyTranslations(scope = document) {
    const root = scope instanceof Element ? scope : document;

    for (const el of root.querySelectorAll('[data-i18n]')) {
        const key = el.getAttribute('data-i18n');
        if (!key) continue;
        const text = t(key, paramsFrom(el));
        // `textContent` is the whole point: a translation containing
        // `<script>` renders as text, not as a node.
        if (el.dataset.i18nKeep === 'value') el.value = text;
        else if (el.dataset.i18nKeep === 'placeholder') el.placeholder = text;
        else el.textContent = text;
    }

    for (const el of root.querySelectorAll('[data-i18n-attr]')) {
        for (const pair of String(el.getAttribute('data-i18n-attr')).split(';')) {
            const idx = pair.indexOf(':');
            if (idx <= 0) continue;
            const attr = pair.slice(0, idx).trim();
            const key = pair.slice(idx + 1).trim();
            if (!attr || !key) continue;
            // Only attributes on this allow-list can be written. Without it a
            // translation key could set `href` or `src` and turn a stale
            // catalogue entry into a navigation hijack.
            if (!SAFE_ATTRS.has(attr)) continue;
            el.setAttribute(attr, t(key, paramsFrom(el)));
        }
    }

    for (const el of root.querySelectorAll('[data-i18n-html]')) {
        const key = el.getAttribute('data-i18n-html');
        if (!key) continue;
        el.innerHTML = sanitizeHtml(t(key, paramsFrom(el)));
    }

    // `<html lang dir>` must follow the active locale for the whole document,
    // including nodes added later.
    document.documentElement.setAttribute('lang', active);
    document.documentElement.setAttribute('dir', direction());
}

/** Attributes a translation may set. Anything outside this list is ignored. */
const SAFE_ATTRS = new Set([
    'aria-label', 'aria-description', 'aria-placeholder', 'aria-valuetext',
    'title', 'alt', 'placeholder', 'label', 'data-tooltip', 'download',
]);

/**
 * Read interpolation parameters off an element.
 *
 * `data-i18n-params='{"count":3}'` for the rare static case; individual
 * `data-p-count="3"` attributes for the common one, because writing JSON in
 * an HTML attribute means escaping quotes inside a quoted attribute.
 */
function paramsFrom(el) {
    const params = {};
    const raw = el.getAttribute('data-i18n-params');
    if (raw) {
        try {
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') Object.assign(params, parsed);
        } catch { /* a malformed param bag must not break the label */ }
    }
    for (const attr of el.attributes) {
        if (attr.name.startsWith('data-p-')) {
            params[attr.name.slice(7)] = attr.value;
        }
    }
    return params;
}

/**
 * Strip everything that can execute or navigate from an HTML fragment.
 *
 * Deliberately not a full sanitiser — the one place this is used renders
 * operator-authored Help content, and the server has already redacted
 * secrets from it. What it must guarantee is that a translated string cannot
 * become a script, an event handler or a `javascript:` URL.
 *
 * @param {string} html
 * @returns {string}
 */
export function sanitizeHtml(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = String(html);
    const doc = tpl.content;

    for (const el of Array.from(doc.querySelectorAll('script,iframe,object,embed,link,meta,base,form,input,style'))) {
        el.remove();
    }
    for (const el of Array.from(doc.querySelectorAll('*'))) {
        for (const attr of Array.from(el.attributes)) {
            const name = attr.name.toLowerCase();
            if (name.startsWith('on')) { el.removeAttribute(attr.name); continue; }
            if (name === 'href' || name === 'src' || name === 'xlink:href' || name === 'action' || name === 'formaction') {
                const value = attr.value.replace(/[\u0000-\u0020]/g, '').toLowerCase();
                if (value.startsWith('javascript:') || value.startsWith('data:text/html') || value.startsWith('vbscript:')) {
                    el.removeAttribute(attr.name);
                    continue;
                }
                // External links from translated content open in a new tab and
                // must not be able to reach `window.opener`.
                if (name === 'href' && /^https?:/.test(value)) {
                    el.setAttribute('target', '_blank');
                    el.setAttribute('rel', 'noopener noreferrer nofollow');
                }
            }
            if (name === 'srcdoc' || name === 'style' && /expression\s*\(|url\s*\(\s*javascript/i.test(attr.value)) {
                el.removeAttribute(attr.name);
            }
        }
    }
    return tpl.innerHTML;
}

/* --------------------------------------------------------------------- *
 * Locale management
 * --------------------------------------------------------------------- */

/** Every locale the console ships, with the metadata the picker needs. */
export const LOCALES = Object.freeze([
    { code: 'en', native: 'English', english: 'English', dir: 'ltr' },
    { code: 'de', native: 'Deutsch', english: 'German', dir: 'ltr' },
    { code: 'ru', native: 'Русский', english: 'Russian', dir: 'ltr' },
    { code: 'zh', native: '简体中文', english: 'Chinese (Simplified)', dir: 'ltr' },
    { code: 'ar', native: 'العربية', english: 'Arabic', dir: 'rtl' },
]);

function readCookie(name) {
    for (const part of (document.cookie || '').split(';')) {
        const idx = part.indexOf('=');
        if (idx < 0) continue;
        if (part.slice(0, idx).trim() === name) {
            try { return decodeURIComponent(part.slice(idx + 1).trim()); } catch { return null; }
        }
    }
    return null;
}

function writeCookie(name, value, days = 365) {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${days * 86400}; SameSite=Lax${secure}`;
}

/**
 * Resolve the locale the same way the server did.
 *
 * Same precedence as App\I18n\LocaleNegotiator: ?lang= > cookie >
 * <html lang> (server-rendered) > navigator.languages > 'en'.
 *
 * @returns {{code:string, source:string}}
 */
export function detectLocale() {
    try {
        const q = new URLSearchParams(location.search).get(QUERY_PARAM);
        if (q) {
            const hit = normalize(q);
            if (hit) return { code: hit, source: 'query' };
        }
    } catch { /* no URLSearchParams — fall through */ }

    const cookie = normalize(readCookie(COOKIE_NAME));
    if (cookie) return { code: cookie, source: 'cookie' };

    const rendered = normalize(document.documentElement.getAttribute('lang'));
    if (rendered) return { code: rendered, source: 'server' };

    for (const pref of (navigator.languages || [navigator.language || 'en'])) {
        const hit = normalize(pref);
        if (hit) return { code: hit, source: 'navigator' };
    }
    return { code: 'en', source: 'default' };
}

/** `zh-Hant-TW` → `zh`, `de_DE.UTF-8` → `de`, `klingon` → null. */
function normalize(tag) {
    if (!tag) return null;
    const primary = String(tag).toLowerCase().replace(/_/g, '-').split(/[-.@]/)[0];
    return LOCALES.some((l) => l.code === primary) ? primary : null;
}

/** Replace the active catalogue. Returns false when the fetch failed. */
export async function loadCatalog(code, { apply = true } = {}) {
    const normalized = normalize(code) || 'en';
    try {
        const res = await fetch(`/api/i18n/${encodeURIComponent(normalized)}`, {
            headers: { Accept: 'application/json', 'X-Locale': normalized },
            credentials: 'same-origin',
            cache: 'no-cache',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const body = await res.json();
        const messages = body && body.messages ? body.messages : null;
        if (!messages || typeof messages !== 'object') throw new Error('malformed catalogue payload');
        catalog = messages;
        if (apply) setActive(normalized, { persist: false });
        return true;
    } catch (err) {
        console.warn(`[i18n] could not load catalogue "${normalized}"`, err);
        return false;
    }
}

/** Set the active locale and re-translate everything already on the page. */
export function setActive(code, { persist = true, retranslate = true } = {}) {
    const normalized = normalize(code) || 'en';
    active = normalized;
    document.documentElement.setAttribute('lang', normalized);
    document.documentElement.setAttribute('dir', RTL.includes(normalized) ? 'rtl' : 'ltr');
    document.documentElement.setAttribute('data-locale', normalized);

    if (persist) {
        try { localStorage.setItem(STORAGE_KEY, normalized); } catch { /* blocked */ }
        writeCookie(COOKIE_NAME, normalized);
    }
    if (retranslate) applyTranslations(document);

    const payload = { locale: normalized, dir: RTL.includes(normalized) ? 'rtl' : 'ltr', rtl: RTL.includes(normalized) };
    for (const fn of listeners) {
        try { fn(payload); } catch (err) { console.error('[i18n] listener failed', err); }
    }
    return payload;
}

/**
 * Switch language and remember it.
 *
 * @param {string} code
 * @param {{reload?:boolean}} [opts] `reload: true` does a full navigation
 *        with `?lang=`. That is the safe path when the page has already
 *        rendered server-side content (the Help panel, a shared run) whose
 *        text cannot be re-translated in place.
 */
export async function switchLocale(code, { reload = false } = {}) {
    const normalized = normalize(code);
    if (!normalized) return false;
    if (reload) {
        const url = new URL(location.href);
        url.searchParams.set(QUERY_PARAM, normalized);
        location.assign(url.toString());
        return true;
    }
    const ok = normalized === active ? true : await loadCatalog(normalized, { apply: false });
    setActive(normalized);
    return ok;
}

/** Subscribe to locale changes. Returns an unsubscribe function. */
export function onLocaleChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/* --------------------------------------------------------------------- *
 * Boot
 * --------------------------------------------------------------------- */

/**
 * A locale change ends with a navigation.
 *
 * Every panel in this console is rendered by PHP — the Help page, the chat
 * composer, the admin cards — and `t()` cannot re-render text that is already
 * in the document. `setActive()` writes the `revoap_locale` cookie, which is
 * exactly what App\I18n\LocaleNegotiator reads on the next request, so one
 * reload is what turns "the switch was stored" into "the interface is in the
 * new language". Doing it here rather than at each call site means every
 * control is covered: the header <select>, the appearance dialog's language
 * grid, the drawer, the command palette and any future one.
 *
 * @param {string} renderedLocale Locale the document was served in.
 */
function ensureRerenderOnLocaleChange(renderedLocale) {
    const GUARD = 'revoap.locale.reload-guard';
    onLocaleChange(({ locale }) => {
        if (!locale || locale === renderedLocale) return;
        try {
            // Insurance only: if a reload came back in another language than
            // requested (a proxy rewriting ?lang=, say), the guard stops the
            // two of them from reloading each other forever.
            if (sessionStorage.getItem(GUARD) === locale) return;
            sessionStorage.setItem(GUARD, locale);
        } catch { /* storage blocked — reload once anyway */ }
        location.reload();
    });
}

/** Read the server-embedded catalogue. Returns the number of keys loaded. */
export function hydrate() {
    const node = document.getElementById('revoap-i18n-catalog');
    const declared = document.documentElement.getAttribute('data-locale')
        || document.documentElement.getAttribute('lang')
        || 'en';

    if (node && node.textContent) {
        try {
            const parsed = JSON.parse(node.textContent);
            if (parsed && typeof parsed === 'object') {
                // The payload is either a bare map or `{locale, messages}`.
                if (parsed.messages && typeof parsed.messages === 'object') {
                    catalog = parsed.messages;
                    active = normalize(parsed.locale) || declared;
                } else {
                    catalog = parsed;
                    active = normalize(declared) || 'en';
                }
            }
        } catch (err) {
            console.warn('[i18n] embedded catalogue is not valid JSON', err);
        }
    } else {
        active = normalize(declared) || 'en';
    }

    document.documentElement.setAttribute('lang', active);
    document.documentElement.setAttribute('dir', RTL.includes(active) ? 'rtl' : 'ltr');
    return Object.keys(catalog).length;
}

/**
 * Wire every locale control on the page.
 *
 *   [data-locale-select]           a <select> listing LOCALES
 *   [data-locale="ru"]             a button/menu item that switches
 *   [data-i18n], [data-i18n-attr]  translated in place, also on later inserts
 *
 * A MutationObserver keeps newly rendered panels translated, because the
 * admin tabs rebuild their DOM constantly and calling applyTranslations()
 * after every render is exactly the kind of thing that gets forgotten.
 */
export function bindControls(scope = document) {
    if (scope.__revoapI18nBound) return;
    scope.__revoapI18nBound = true;

    // Populate any <select data-locale-select> with the shipped locales.
    for (const sel of scope.querySelectorAll('select[data-locale-select]')) {
        if (sel.options.length === 0) {
            for (const l of LOCALES) {
                const opt = document.createElement('option');
                opt.value = l.code;
                // Native name first, English gloss second: a user who cannot
                // read the current UI still finds their own language.
                opt.textContent = l.native === l.english ? l.native : `${l.native} — ${l.english}`;
                opt.dir = l.dir;
                opt.lang = l.code;
                sel.appendChild(opt);
            }
        }
        sel.value = active;
        sel.addEventListener('change', () => { switchLocale(sel.value, { reload: sel.dataset.reload === 'true' }); });
    }

    scope.addEventListener('click', (ev) => {
        const el = ev.target instanceof Element ? ev.target.closest('[data-locale]') : null;
        if (!el) return;
        ev.preventDefault();
        const code = el.getAttribute('data-locale');
        switchLocale(code, { reload: el.dataset.reload === 'true' });
    });

    // Keep dynamically inserted markup translated.
    if (typeof MutationObserver === 'function' && scope === document) {
        let queued = false;
        const obs = new MutationObserver((records) => {
            let touched = false;
            for (const r of records) {
                for (const n of r.addedNodes) {
                    if (n.nodeType === 1 && (n.hasAttribute?.('data-i18n') || n.querySelector?.('[data-i18n],[data-i18n-attr]'))) {
                        touched = true;
                        break;
                    }
                }
                if (touched) break;
            }
            if (!touched || queued) return;
            queued = true;
            // Coalesce: a panel render inserts hundreds of nodes in one task.
            requestAnimationFrame(() => { queued = false; applyTranslations(document.body); });
        });
        obs.observe(document.body, { childList: true, subtree: true });
    }
}

function init() {
    hydrate();
    // The server has already resolved the locale; only override when the URL
    // explicitly asks for a different one (a shared "view in Russian" link).
    const detected = detectLocale();
    if (detected.source === 'query' && detected.code !== active) {
        setActive(detected.code, { persist: true, retranslate: false });
        // The embedded catalogue is for the server's locale; fetch the other.
        void loadCatalog(detected.code, { apply: false });
    }
    bindControls(document);
    applyTranslations(document);
    // Registered AFTER the boot override above: the listener must not see the
    // locale the document was served with, or loading a page would reload it.
    ensureRerenderOnLocaleChange(active);

    window.RevoApI18n = Object.freeze({
        t, tn, has, locale, direction, isRtl,
        switchLocale, setActive, detectLocale, loadCatalog, onLocaleChange,
        applyTranslations, sanitizeHtml, missingKeys, LOCALES,
        formatNumber, formatCompact, formatPercent, formatDate, formatDateTime,
        formatTime, formatDuration, formatBytes, timeAgo, pluralCategory,
    });

    // Surface missing translations during development only. The flag is set
    // by the server from `app.debug`, so a production console stays silent.
    const dbg = document.querySelector('meta[name="oc-app-debug"]');
    if (dbg && dbg.getAttribute('content') === '1') {
        setTimeout(() => {
            const list = missingKeys();
            if (list.length) console.info(`[i18n] ${list.length} missing key(s):`, list.slice(0, 25));
        }, 2000);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export default {
    t, tn, has, locale, direction, isRtl, switchLocale, setActive, detectLocale,
    loadCatalog, onLocaleChange, applyTranslations, sanitizeHtml, missingKeys, LOCALES,
};
