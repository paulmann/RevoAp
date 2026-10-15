// logs.js — admin Logs tab.
//
// 1. Probes /api/logs/status (no auth) to decide whether to render the
//    nav tab. Backend gates everything on LogsTab=true AND the admin
//    token; this client just hides the tab when disabled.
// 2. Lists whitelisted log files via /api/logs/list, lets the operator
//    pick one, then streams a sanitized tail via /api/logs/tail.
// 3. Refresh and Clear actions hit the backend; Clear requires a
//    confirmation dialog because it is destructive.
// 4. Log content is rendered with textContent ONLY — never innerHTML —
//    so even sanitized log lines cannot inject markup.

const LOGS_TAB     = document.getElementById('oc-tab-logs');
const ROOT         = document.getElementById('oc-logs-root');
const TAB_BUTTONS  = document.querySelectorAll('[data-tab]');
const TAB_PANELS   = document.querySelectorAll('[data-tab-panel]');

const TAIL_DEFAULT = 500;
const TAIL_MAX     = 5000;

let state = {
    status: { tab_enabled: false, admin_configured: false, admin_unlocked: false },
    files:  [],
    selected: null,
    lines: TAIL_DEFAULT,
    autoRefresh: false,
    timer: null,
};

function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null) continue;
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = String(v);
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

function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }

async function api(path, opts = {}) {
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

function fmtBytes(n) {
    if (!Number.isFinite(n) || n <= 0) return '0 B';
    const units = ['B','KB','MB','GB'];
    let i = 0, v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return v.toFixed(v < 10 && i > 0 ? 1 : 0) + ' ' + units[i];
}

function fmtTs(unix) {
    if (!unix) return '—';
    try { return new Date(unix * 1000).toISOString().replace('T', ' ').replace(/\..+$/, ' UTC'); }
    catch { return '—'; }
}

function activateTab(name) {
    TAB_BUTTONS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    TAB_PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
    if (name === 'logs') void loadAndRender();
    else stopAutoRefresh();
}

TAB_BUTTONS.forEach(t => {
    if (t.dataset.tab === 'logs') {
        t.addEventListener('click', () => activateTab('logs'));
    }
});

async function probeStatus() {
    const { body } = await api('/api/logs/status');
    state.status = body || state.status;
    // Tab is shown whenever LogsTab=true. Admin auth gates the tab body
    // (lock screen / "admin not configured" notice) but never the tab
    // button itself — operators need a visible affordance to know that
    // the feature is enabled even before configuring the token.
    const tabEnabled = state.status.tab_enabled === true;
    if (LOGS_TAB) LOGS_TAB.hidden = !tabEnabled;
}

async function loadAndRender() {
    if (!ROOT) return;
    if (!state.status.tab_enabled) { renderDisabled('Logs tab is disabled in config.php (LogsTab=false).'); return; }
    // v1.8.60 — a banner about a missing token only makes sense while a
    // token is actually required (see Protect_Settings_by_Admin_Key).
    if (state.status.admin_protection !== false && !state.status.admin_configured) { renderDisabled('Admin token not configured. Set REVOAP_ADMIN_TOKEN or .admin.key first.'); return; }
    const { ok, body, status } = await api('/api/logs/list');
    if (state.status.admin_protection !== false && status === 401) { renderDisabled('Admin token required. Open Settings tab first to unlock.'); return; }
    if (status === 404 && body?.error === 'logs_disabled') { renderDisabled('Logs tab is disabled in config.php (LogsTab=false).'); return; }
    if (!ok) { renderError(body?.message || ('Failed to load logs (HTTP ' + status + ')')); return; }
    state.files = Array.isArray(body.files) ? body.files : [];
    if (state.selected === null && state.files.length > 0) {
        const first = state.files.find(f => f.exists) || state.files[0];
        state.selected = first.slug;
    }
    renderLogs();
    if (state.selected) await loadTail();
}

function renderDisabled(msg) {
    clear(ROOT);
    ROOT.append(el('div', { class: 'oc-alert oc-alert-warn', text: msg }));
}

function renderError(msg) {
    clear(ROOT);
    ROOT.append(el('div', { class: 'oc-alert oc-alert-error', text: msg }));
}

function renderLogs() {
    clear(ROOT);

    const sidebar = el('div', { class: 'oc-logs-sidebar' });
    sidebar.append(el('h3', { text: 'Log files' }));
    const list = el('ul', { class: 'oc-logs-list' });
    for (const f of state.files) {
        const li = el('li', {
            class: 'oc-logs-item' + (f.slug === state.selected ? ' oc-logs-item-active' : '') + (f.exists ? '' : ' oc-logs-item-missing'),
            onclick: async () => { state.selected = f.slug; renderLogs(); await loadTail(); },
        });
        li.append(el('div', { class: 'oc-logs-name', text: f.label }));
        li.append(el('div', { class: 'oc-logs-meta',
            text: (f.exists ? fmtBytes(f.size) + ' · ' + fmtTs(f.mtime) : '(missing)') }));
        list.append(li);
    }
    sidebar.append(list);

    const main = el('div', { class: 'oc-logs-main' });

    const toolbar = el('div', { class: 'oc-logs-toolbar' });
    const linesInput = el('input', { id: 'oc-logs-lines',
        type: 'number', min: '1', max: String(TAIL_MAX), value: String(state.lines),
        class: 'oc-input oc-logs-lines',
        oninput: (ev) => {
            const v = parseInt(ev.target.value, 10);
            if (Number.isFinite(v) && v > 0) state.lines = Math.min(TAIL_MAX, v);
        },
    });
    toolbar.append(el('label', { class: 'oc-logs-label', for: 'oc-logs-lines', text: 'Lines:' }), linesInput);
    toolbar.append(el('button', {
        type: 'button', class: 'oc-button', text: 'Refresh',
        onclick: () => { void loadTail(); },
    }));
    const autoBtn = el('button', {
        type: 'button',
        class: 'oc-button' + (state.autoRefresh ? ' oc-button-active' : ''),
        text: state.autoRefresh ? 'Auto: ON' : 'Auto: OFF',
        onclick: () => { state.autoRefresh ? stopAutoRefresh() : startAutoRefresh(); renderLogs(); },
    });
    toolbar.append(autoBtn);
    toolbar.append(el('button', {
        type: 'button', class: 'oc-button oc-button-danger', text: 'Clear log',
        onclick: () => { void clearSelected(); },
    }));
    main.append(toolbar);

    const meta = el('div', { class: 'oc-logs-meta-line', id: 'oc-logs-meta-line' });
    main.append(meta);

    const pre = el('pre', { class: 'oc-logs-content', id: 'oc-logs-content', text: '' });
    main.append(pre);

    ROOT.append(sidebar, main);
}

async function loadTail() {
    if (!state.selected) return;
    const url = '/api/logs/tail?slug=' + encodeURIComponent(state.selected) + '&lines=' + encodeURIComponent(String(state.lines));
    const { ok, body, status } = await api(url);
    const pre = document.getElementById('oc-logs-content');
    const meta = document.getElementById('oc-logs-meta-line');
    if (!pre || !meta) return;
    if (state.status.admin_protection !== false && status === 401) { renderDisabled('Admin token required. Open Settings tab first to unlock.'); return; }
    if (status === 404 && body?.error === 'logs_disabled') { renderDisabled('Logs tab is disabled in config.php (LogsTab=false).'); return; }
    if (!ok) {
        pre.textContent = '';
        meta.textContent = body?.message || ('Failed to load log tail (HTTP ' + status + ')');
        return;
    }
    // SAFE: textContent only — log lines may contain HTML-looking tokens.
    pre.textContent = body.content || (body.exists ? '(empty)' : '(file does not exist)');
    const parts = [];
    parts.push(body.label || body.slug);
    parts.push('size: ' + fmtBytes(body.size || 0));
    parts.push('mtime: ' + fmtTs(body.mtime));
    parts.push('shown: ' + (body.lines || 0) + ' lines');
    if (body.truncated) parts.push('(truncated)');
    meta.textContent = parts.join(' · ');
}

async function clearSelected() {
    if (!state.selected) return;
    const sel = state.files.find(f => f.slug === state.selected);
    const label = sel ? sel.label : state.selected;
    const ok = window.confirm('Truncate "' + label + '"? This permanently empties the file.');
    if (!ok) return;
    const { status, body } = await api('/api/logs/clear', { method: 'POST', body: { slug: state.selected } });
    if (status === 200) {
        await loadAndRender();
    } else {
        renderError(body?.message || ('Failed to clear log (HTTP ' + status + ')'));
    }
}

function startAutoRefresh() {
    stopAutoRefresh();
    state.autoRefresh = true;
    state.timer = setInterval(() => { void loadTail(); }, 5000);
}

function stopAutoRefresh() {
    state.autoRefresh = false;
    if (state.timer) { clearInterval(state.timer); state.timer = null; }
}

window.addEventListener('beforeunload', stopAutoRefresh);

void probeStatus();
