// admin-ui.js — shared shell for the admin tabs (Models, Settings).
//
// Both tabs render the same three things and mutate state through the
// same gate, so the pieces live here instead of being copied per tab:
//
//   * `el` / `clear` / `api` — the DOM and fetch helpers every admin
//     module used to re-declare. `el` builds nodes with createElement +
//     textContent only; it deliberately has no `html` escape hatch, so a
//     caller cannot smuggle markup (provider labels and model ids are
//     operator-controlled strings and must stay text).
//   * `createLockScreen()` — the admin-token prompt. The token is only
//     ever held in the HttpOnly `oc_admin` cookie set by
//     POST /api/settings/unlock, so unlocking in one tab unlocks every
//     tab on the origin and there is no client-side token to migrate.
//   * `createSectionNav()` — the sticky in-page section strip. Sticky is
//     confined to the containing block, so the strip container is
//     server-rendered next to the tab root rather than inside it, and the
//     strip publishes its height as `--oc-settings-nav-height` so
//     anchored cards land below it.
//
// Nothing here is Models-specific or Settings-specific: a third admin tab
// would reuse all three without touching this file.

/** Create an element. `class`, `text`, `dataset`, `on*` and booleans are
 *  understood; every other attribute is set verbatim. No innerHTML. */
export function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null) continue;
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = String(v);
        else if (k === 'dataset') Object.assign(node.dataset, v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2).toLowerCase(), v);
        else if (typeof v === 'boolean') { if (v) node.setAttribute(k, ''); }
        else node.setAttribute(k, String(v));
    }
    for (const child of children.flat()) {
        if (child == null) continue;
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
}

/** Detach every child of `node`. */
export function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }

/**
 * JSON fetch with the admin cookie attached. Always resolves to
 * `{status, ok, body}` — a non-JSON or empty answer becomes `{}` so
 * callers can read `body.error` unconditionally.
 */
export async function api(path, opts = {}) {
    const init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, ...opts };
    if (init.body && typeof init.body === 'object' && !(init.body instanceof FormData)) {
        init.headers = { ...init.headers, 'Content-Type': 'application/json' };
        init.body = JSON.stringify(init.body);
    }
    const res = await fetch(path, init);
    let body = null;
    try { body = await res.json(); } catch { body = null; }
    return { status: res.status, ok: res.ok, body: body ?? {} };
}

/**
 * Probe the public admin status endpoint and report everything the tab
 * needs to decide between "disabled", "locked", "not configured" and
 * "render". Never throws: a failed probe reports `admin_configured:
 * false` so the caller renders a locked/disabled state instead of an
 * empty page.
 *
 * @param {string} statusPath e.g. '/api/models/status'
 */
export async function probeAdminStatus(statusPath) {
    try {
        const { ok, body } = await api(statusPath);
        if (ok && body && typeof body === 'object') return body;
    } catch {
        // fall through to the neutral state below
    }
    // `admin_protection` defaults to TRUE: a probe that failed must not be
    // read as "the gate is open". See AdminAuth::gateState().
    return { tab_enabled: true, admin_protection: true, admin_configured: false, admin_unlocked: false };
}

/**
 * Is the operator surface gated by the admin token?
 *
 * v1.8.60 — the one question every admin module has to ask before it
 * renders a lock screen, a "token not configured" banner or an
 * "unauthorized" hint. Reported by every `/api/<tab>/status` probe as
 * `admin_protection` (the raw `Protect_Settings_by_Admin_Key` flag) and
 * baked into the first paint as `<meta name="oc-admin-protection">`.
 *
 * Defaults to TRUE when nothing says otherwise: a missing field, a failed
 * fetch and a stale cached page must all keep the gate, never open it.
 *
 * @param {{admin_protection?:boolean}|null} status a probe response
 * @returns {boolean}
 */
export function adminProtected(status) {
    if (status && typeof status.admin_protection === 'boolean') return status.admin_protection;
    const meta = document.querySelector('meta[name="oc-admin-protection"]');
    const value = meta ? (meta.getAttribute('content') || '') : '';
    if (value === 'false' || value === '0') return false;
    return true;
}

/**
 * Append the "Settings are not protected by an admin key" notice to a tab
 * root, but only when the gate is actually off.
 *
 * The strip is server-rendered too (see `layout.html`), so an operator sees
 * it before any script runs; this is the belt to that braces for the cases
 * where a tab re-renders its own body and the operator is looking at the
 * panel rather than the page top.
 *
 * @param {HTMLElement|null} root
 * @param {{admin_protection?:boolean}|null} status
 */
export function renderProtectionNotice(root, status) {
    if (!root || adminProtected(status)) return;
    if (root.querySelector('[data-oc-protection-notice]')) return;
    root.prepend(el('div', {
        class: 'oc-alert oc-alert-warn oc-protection-notice',
        'data-oc-protection-notice': true,
        role: 'status',
    },
        el('strong', { text: 'Settings are not protected by an admin key.' }),
        ' No token is requested. Set ',
        el('span', { class: 'oc-mono', text: 'Protect_Settings_by_Admin_Key' }),
        ' to ',
        el('span', { class: 'oc-mono', text: '1' }),
        ' in config.php or .config.php to require the key from ',
        el('span', { class: 'oc-mono', text: '.admin.key' }),
        ' again.'));
}

/**
 * Disabled notice — the tab flag is off in config.php. Rendered instead
 * of the section list so an operator who bookmarked the tab still learns
 * which flag to flip. The two credentials and the flag are the same
 * remedies Settings has always listed.
 *
 * @param {HTMLElement|null} root
 * @param {{flag:string, feature:string}} spec
 */
export function renderDisabledNotice(root, spec) {
    if (!root) return;
    clear(root);
    root.append(el('div', { class: 'oc-alert oc-alert-warn' },
        `${spec.feature} are disabled. Configure `,
        el('span', { class: 'oc-mono', text: 'REVOAP_ADMIN_TOKEN' }),
        ' or create ',
        el('span', { class: 'oc-mono', text: '.admin.key' }),
        ' at the project root, or set ',
        el('span', { class: 'oc-mono', text: String(spec.flag) }),
        ' to true in config.php, then reload.'));
}

/**
 * Error notice for a failed load.
 *
 * @param {HTMLElement|null} root
 * @param {string} message
 */
export function renderErrorNotice(root, message) {
    if (!root) return;
    clear(root);
    root.append(el('div', { class: 'oc-alert oc-alert-error', text: message }));
}

/**
 * Admin-token lock screen, identical to the one Settings has always
 * rendered. On a successful unlock the HttpOnly cookie is in place, so
 * `onUnlocked()` can immediately re-run the tab's load path — and so can
 * every other admin tab, which is how Models and Settings share one
 * unlock without sharing any state.
 *
 * @param {HTMLElement|null} root
 * @param {{title:string, description:string, onUnlocked:() => (void|Promise<void>),
 *          onState?:(unlocked:boolean) => void}} spec
 */
export function createLockScreen(root, spec) {
    if (!root) return;
    clear(root);
    const input = el('input', { type: 'password', placeholder: 'Admin token', autocomplete: 'off', id: 'oc-admin-token' });
    const status = el('div', { class: 'oc-status' });
    const submit = el('button', {
        class: 'oc-button-primary',
        type: 'button',
        onClick: async () => {
            const token = input.value.trim();
            if (!token) { status.textContent = 'Enter the admin token.'; return; }
            status.textContent = 'Verifying…';
            const res = await api('/api/settings/unlock', { method: 'POST', body: { token } });
            if (res.ok) {
                input.value = '';
                if (typeof spec.onState === 'function') spec.onState(true);
                await spec.onUnlocked();
            } else {
                status.textContent = res.body?.error === 'invalid_token' ? 'Invalid token.' : 'Unlock failed.';
            }
        },
    }, 'Unlock');
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit.click(); });
    root.append(
        el('div', { class: 'oc-settings-locked' },
            el('h2', { text: spec.title }),
            el('p', { class: 'oc-muted', text: spec.description }),
            input,
            el('div', {}, submit, ' ', status),
        ),
    );
}

/**
 * Sticky in-page section strip.
 *
 * Active-section tracking uses an IntersectionObserver rather than a
 * scroll handler: the callback only fires when a card crosses the
 * "reading band" (the viewport area just below the strip), so there is
 * no per-frame geometry work and no layout thrash while scrolling.
 *
 * The strip is built from the section cards that are actually present in
 * the DOM, so a section switched off by config is never advertised.
 * Sections are read by id at refresh time (not captured at construction)
 * because the tab body is re-rendered on every load.
 *
 * @param {{nav:HTMLElement|null, band:number, sections:Array<{id:string,title:string}>}} spec
 * @returns {{
 *   refresh:() => void,
 *   stop:() => void,
 *   setActive:(id:string) => void,
 *   enabled:() => boolean,
 * }}
 */
export function createSectionNav(spec) {
    const nav = spec.nav;
    const band = typeof spec.band === 'number' ? spec.band : 0.45;
    const registry = Array.isArray(spec.sections) ? spec.sections : [];
    /** Height of the strip, used for the scroll offset of anchored cards. */
    const stripHeight = () => (nav ? (nav.offsetHeight || 0) : 0);
    /**
     * v1.8.60 — the pinned header's height (assets/css/app.css). The strip
     * parks below it, so the "reading band" that decides which section is
     * active starts below the header AND the strip: without this the band
     * would begin inside the header and highlight the section above the one
     * being read. Measured from the header itself so a viewport where the
     * nav wraps stays correct.
     */
    const headerHeight = () => {
        const header = document.querySelector('.oc-header');
        return header ? (header.offsetHeight || 0) : 0;
    };
    const ctl = { observer: null, links: new Map(), active: '', sections: [] };

    /** Feature-gated on layout: no strip container, nothing to do. */
    function enabled() { return nav !== null; }

    /** Everything the strip needs to know about the page. */
    function ready() { return ctl.sections.length > 0 && typeof IntersectionObserver === 'function'; }

    /**
     * Publish the strip height as a CSS variable on the document root.
     * Both the cards (`scroll-margin-top`) and the document scroller
     * (`scroll-padding-top` in settings.css) read it, so a section
     * scrolled to always lands *below* the strip instead of under it —
     * for the strip itself and for the worker-status indicator, which
     * scrolls to #oc-worker-card from the header.
     *
     * The observer's reading band depends on the same height, so it is
     * rebuilt here as well: on a narrow screen the strip is a different
     * height, and a band computed for the wide layout would highlight the
     * wrong section. Re-runs on window resize.
     */
    function syncHeight() {
        document.documentElement.style.setProperty('--oc-settings-nav-height', stripHeight() + 'px');
        observe();
    }

    /** (Re)create the active-section observer for the current strip height. */
    function observe() {
        if (ctl.observer) {
            ctl.observer.disconnect();
            ctl.observer = null;
        }
        if (!ready()) return;
        // Several cards can intersect the band at once (short cards, tall
        // viewports), so the first one in document order wins — that is the
        // one the reader is looking at.
        const visible = new Set();
        const order = ctl.sections.map((s) => s.id);
        ctl.observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                const id = entry.target.id;
                if (entry.isIntersecting) visible.add(id); else visible.delete(id);
            }
            const active = order.find((id) => visible.has(id));
            if (active) setActive(active);
        }, {
            // Top band: pinned header + strip height + a small gap. Bottom:
            // ignore the lower half of the viewport so a section entering
            // from below does not steal the highlight from the one being
            // read.
            rootMargin: `-${headerHeight() + stripHeight() + 8}px 0px -${Math.round((1 - band) * 100)}% 0px`,
            threshold: 0,
        });
        for (const section of ctl.sections) ctl.observer.observe(section.node);
    }

    function setActive(id) {
        if (ctl.active === id) return;
        ctl.active = id;
        for (const [sid, link] of ctl.links) {
            const on = sid === id;
            link.classList.toggle('oc-settings-nav-link-active', on);
            link.setAttribute('aria-current', on ? 'true' : 'false');
        }
        // Keep the highlighted pill inside the horizontally scrollable strip
        // (narrow screens): centre it without disturbing the page scroll.
        const link = ctl.links.get(id);
        const strip = nav ? nav.firstElementChild : null;
        if (link && strip && typeof link.offsetLeft === 'number' && typeof strip.offsetWidth === 'number') {
            const centred = link.offsetLeft - (strip.offsetWidth / 2) + ((link.offsetWidth || 0) / 2);
            strip.scrollLeft = Math.max(0, centred);
        }
    }

    /** Smooth-scroll to a section card, honouring the strip height. */
    function goTo(node, id) {
        setActive(id);
        if (node && typeof node.scrollIntoView === 'function') {
            try {
                node.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch {
                node.scrollIntoView();
            }
        }
    }

    /** Tear the strip down (locked page, tab switch, re-render). */
    function stop() {
        if (ctl.observer) {
            ctl.observer.disconnect();
            ctl.observer = null;
        }
        ctl.links = new Map();
        ctl.active = '';
        ctl.sections = [];
        window.removeEventListener('resize', syncHeight);
        if (nav) {
            clear(nav);
            nav.hidden = true;
        }
        document.documentElement.style.setProperty('--oc-settings-nav-height', '0px');
    }

    /**
     * (Re)build the strip from the sections currently present in the DOM.
     * Called after every render and whenever a section is shown or hidden
     * at runtime. A no-op — strip hidden — when no section is available
     * (locked page, all sections switched off by config).
     */
    function refresh() {
        if (ctl.observer) {
            ctl.observer.disconnect();
            ctl.observer = null;
        }
        ctl.links = new Map();
        ctl.active = '';
        ctl.sections = [];
        if (!enabled()) return;
        clear(nav);

        const sections = registry
            .map((s) => ({ ...s, node: document.getElementById(s.id) }))
            .filter((s) => s.node && !s.node.hasAttribute('hidden'));
        if (sections.length === 0) {
            nav.hidden = true;
            document.documentElement.style.setProperty('--oc-settings-nav-height', '0px');
            return;
        }

        const strip = el('div', { class: 'oc-settings-nav-strip' });
        for (const section of sections) {
            const link = el('button', {
                type: 'button',
                class: 'oc-settings-nav-link',
                'data-section': section.id,
                text: section.title,
                onClick: () => goTo(section.node, section.id),
            });
            ctl.links.set(section.id, link);
            strip.append(link);
        }
        nav.append(strip);
        nav.hidden = false;
        ctl.sections = sections;
        syncHeight();
        window.addEventListener('resize', syncHeight);
        setActive(sections[0].id);
    }

    return { refresh, stop, setActive, enabled };
}
