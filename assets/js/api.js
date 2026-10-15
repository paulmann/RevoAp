// api.js - admin "API" tab: external REST API keys (rv_*).
//
// 1. Probes /api/apikeys/status (no auth) to decide whether to render
//    the nav tab. Backend gates listing/mutations on ApiTab=true AND
//    the admin token; this client hides the tab only when disabled.
// 2. Renders the key generator (label / scopes / rpm / daily limit /
//    optional expiry), the key list with Rotate / Revoke / Copy, and a
//    short how-to with curl examples.
// 3. The plaintext key is shown exactly once per create/rotate - inside
//    a warning box with a Copy button (navigator.clipboard when
//    available, prompt fallback). All dynamic text goes through
//    textContent, never innerHTML.

const API_TAB   = document.getElementById('oc-tab-api');
const ROOT      = document.getElementById('oc-api-root');
const TAB_BUTTONS  = document.querySelectorAll('[data-tab]');
const TAB_PANELS   = document.querySelectorAll('[data-tab-panel]');

let state = {
    status: { tab_enabled: false, admin_configured: false, admin_unlocked: false },
    keys: [],
    stats: { total: 0, active: 0, revoked: 0, expired: 0 },
    form: { label: '', scopes: 'full', rpm: '', daily: '', expires: '' },
    busy: false,
    reveal: null,
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

function activateTab(name) {
    TAB_BUTTONS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    TAB_PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
    if (name === 'api') {
        state.reveal = null;
        void loadAndRender();
    }
}

TAB_BUTTONS.forEach(t => {
    if (t.dataset.tab === 'api') {
        t.addEventListener('click', () => activateTab('api'));
    }
});

async function probeStatus() {
    const { body } = await api('/api/apikeys/status');
    state.status = {
        tab_enabled:      body?.tab_enabled === true,
        admin_protection: body?.admin_protection !== false,
        admin_configured: body?.admin_configured === true,
        admin_unlocked:   body?.admin_unlocked === true,
    };
    if (API_TAB) {
        // Tab visibility is driven by ApiTab=true alone. Admin auth
        // gates the tab body - but the operator always sees that the
        // feature is available.
        API_TAB.hidden = !state.status.tab_enabled;
    }
}

async function loadAndRender() {
    if (!ROOT) return;
    if (!state.status.tab_enabled) {
        renderBanner('warn', 'API tab is disabled in config.php (ApiTab=false).');
        return;
    }
    // v1.8.60 — a banner about a missing token only makes sense while a
    // token is actually required (see Protect_Settings_by_Admin_Key).
    if (state.status.admin_protection !== false && !state.status.admin_configured) {
        renderBanner('warn', 'Admin token not configured. Set REVOAP_ADMIN_TOKEN or .admin.key first.');
        return;
    }
    const { ok, body, status } = await api('/api/apikeys');
    if (state.status.admin_protection !== false && (status === 401 || body?.error === 'unauthorized')) {
        renderBanner('warn', 'Admin token required. Open the Settings tab first to unlock.');
        return;
    }
    if (status === 404 && body?.error === 'api_tab_disabled') {
        renderBanner('warn', 'API tab is disabled in config.php (ApiTab=false).');
        return;
    }
    if (!ok) {
        renderBanner('danger', body?.message || ('Failed to load API keys (HTTP ' + status + ')'));
        return;
    }
    state.keys  = Array.isArray(body.api_keys) ? body.api_keys : [];
    state.stats = body.stats || state.stats;
    render();
}

function renderBanner(kind, msg) {
    clear(ROOT);
    ROOT.append(el('div', { class: 'oc-api-banner oc-api-banner-' + kind, text: msg }));
}

function render() {
    clear(ROOT);

    if (state.reveal) {
        ROOT.append(renderReveal(state.reveal));
    }

    ROOT.append(renderStats());

    const grid = el('div', { class: 'oc-api-grid' });
    grid.append(renderCreateCard(), renderKeysCard());
    ROOT.append(grid);

    ROOT.append(renderHelpCard());
}

function renderReveal(data) {
    const wrap = el('div', { class: 'oc-api-reveal' });
    wrap.append(el('div', { class: 'oc-api-reveal-title', text: data.title }));
    wrap.append(el('code', { text: data.plaintext }));
    wrap.append(el('p', { class: 'oc-api-copy-note',
        text: 'Copy it now - this is the only time the full key is shown. It is stored as a SHA-256 digest and cannot be recovered later.' }));
    wrap.append(el('button', { type: 'button', class: 'oc-button',
        text: 'Copy key', onclick: () => void copyKey(data.plaintext) }));
    return wrap;
}

function renderStats() {
    const s = state.stats;
    const grid = el('div', { class: 'oc-api-stats' });
    const items = [
        ['Total', s.total], ['Active', s.active], ['Revoked', s.revoked], ['Expired', s.expired],
    ];
    for (const [label, value] of items) {
        grid.append(el('div', { class: 'oc-api-stat' },
            el('b', { text: value }),
            document.createTextNode(label),
        ));
    }
    return grid;
}

function renderCreateCard() {
    const card = el('div', { class: 'oc-api-card' });
    card.append(el('h3', { text: 'Generate a key' }));
    card.append(el('p', { class: 'oc-muted',
        text: 'Keys authenticate the external REST API at /v1/*. The plaintext appears once - keep it in a secure store.' }));

    const rows = [
        ['Label', 'Use case name, e.g. "CI builds"', 'text', 'label'],
        ['RPM limit', 'Requests per minute (blank = 30)', 'number', 'rpm'],
        ['Daily limit', 'Requests per UTC day (blank = 1000)', 'number', 'daily'],
        ['Expires at', 'Optional, e.g. 2026-12-31', 'text', 'expires'],
    ];
    for (const [label, ph, type, field] of rows) {
        const row = el('div', { class: 'oc-api-form-row' });
        const input = el('input', { id: 'oc-api-key-' + field, type, class: 'oc-input', placeholder: ph, title: ph });
        input.value = String(state.form[field] ?? '');
        input.addEventListener('input', () => { state.form[field] = input.value; });
        row.append(el('label', { for: 'oc-api-key-' + field, text: label }), input);
        card.append(row);
    }

    const scopeRow = el('div', { class: 'oc-api-form-row' });
    const select = el('select', { id: 'oc-api-key-scopes', class: 'oc-input', title: 'Scopes: full can start and cancel runs; read is read-only.' });
    const fullOpt = el('option', { value: 'full', text: 'full - create runs, read results, cancel' });
    const readOpt = el('option', { value: 'read', text: 'read - read-only (status/result/models/usage)' });
    select.append(fullOpt, readOpt);
    select.value = state.form.scopes === 'read' ? 'read' : 'full';
    select.addEventListener('change', () => { state.form.scopes = select.value; });
    scopeRow.append(el('label', { for: 'oc-api-key-scopes', text: 'Scopes' }), select);
    card.append(scopeRow);

    card.append(el('button', {
        type: 'button', class: 'oc-button oc-button-primary',
        text: state.busy ? 'Generating…' : 'Generate key',
        onclick: () => void createKey(),
    }));
    return card;
}

function renderKeysCard() {
    const card = el('div', { class: 'oc-api-card' });
    card.append(el('h3', { text: 'Keys' }));
    if (state.keys.length === 0) {
        card.append(el('p', { class: 'oc-muted', text: 'No keys yet. Generate the first one on the left.' }));
        return card;
    }
    for (const key of state.keys) {
        card.append(renderKeyRow(key));
    }
    return card;
}

function renderKeyRow(key) {
    const row = el('div', { class: 'oc-api-key' });
    const main = el('div', { class: 'oc-api-key-main' });

    const revoked = !!key.revoked_at;
    const expired = !revoked && !!key.expires_at && new Date(String(key.expires_at).replace(' ', 'T') + 'Z').getTime() <= Date.now();
    const status = revoked ? 'revoked' : (expired ? 'expired' : 'active');
    const name = el('span', { class: 'oc-api-key-name', text: (key.label || '(unnamed)') + '  ' });
    main.append(name);

    const meta = [
        key.prefix ?? '',
        (key.scopes || []).join('+') || 'read',
        String(key.rpm_limit ?? 0) + ' rpm',
        String(key.daily_limit ?? 0) + '/day',
        'status: ' + status,
        key.created_at ? 'created ' + key.created_at + ' UTC' : null,
        key.last_used_at && !revoked ? 'last used ' + key.last_used_at + ' UTC' : null,
        key.expires_at && !revoked ? 'expires ' + key.expires_at + ' UTC' : null,
    ].filter(Boolean);
    main.append(el('span', { class: 'oc-api-key-meta', text: meta.join(' | ') }));

    const actions = el('div', { class: 'oc-api-key-actions' });
    if (!revoked) {
        actions.append(
            el('button', { type: 'button', class: 'oc-button',
                title: 'Replace the key material with a fresh random key (the old one stops working).',
                text: 'Rotate', onclick: () => void rotateKey(key.id) }),
            el('button', { type: 'button', class: 'oc-button oc-button-danger',
                title: 'Revoke the key permanently. The audit row is kept.',
                text: 'Revoke', onclick: () => void revokeKey(key.id) }),
        );
    }
    row.append(main, actions);
    return row;
}

function renderHelpCard() {
    const card = el('div', { class: 'oc-api-card' });
    card.append(el('h3', { text: 'How to use the API' }));
    card.append(el('p', { class: 'oc-muted',
        text: 'Authenticate with X-API-Key (or Authorization: Bearer). Start an async run, then poll for the result:' }));
    card.append(el('pre', { class: 'oc-api-code-example', text: [
        'curl -X POST http://your-host/v1/runs \\',
        '  -H "Content-Type: application/json" \\',
        '  -H "X-API-Key: rv_..." \\',
        '  -d \'{"query":"What is the best way...","async":true}\'',
        '',
        'curl http://your-host/v1/runs/{id}/result \\',
        '  -H "X-API-Key: rv_..."',
    ].join('\n') }));
    card.append(el('p', { class: 'oc-muted',
        text: 'Formats: native, OpenAI-compatible (chat/completions) and Anthropic-compatible (messages) request/response envelopes. Full reference: docs/api-rest.md.' }));
    return card;
}

async function createKey() {
    if (state.busy) return;
    state.busy = true;
    render();
    const body = { label: state.form.label || '' };
    if (String(state.form.rpm || '').trim() !== '') body.rpm_limit = String(state.form.rpm).trim();
    if (String(state.form.daily || '').trim() !== '') body.daily_limit = String(state.form.daily).trim();
    body.scopes = state.form.scopes === 'read' ? 'read' : 'full';
    if (String(state.form.expires || '').trim() !== '') body.expires_at = String(state.form.expires).trim();

    const { ok, body: resp, status } = await api('/api/apikeys', { method: 'POST', body });
    state.busy = false;
    if (status === 401) { renderBanner('warn', 'Admin token required.'); return; }
    if (!ok) { renderBanner('danger', resp?.message || ('Create failed (HTTP ' + status + ')')); return; }
    state.reveal = { title: 'New API key - copy it now', plaintext: resp.plaintext || '' };
    state.form = { label: '', scopes: 'full', rpm: '', daily: '', expires: '' };
    await loadAndRender();
}

async function rotateKey(id) {
    if (!window.confirm('Rotate this key? The current key stops working immediately.')) return;
    const { ok, body, status } = await api('/api/apikeys/' + encodeURIComponent(id) + '/rotate', { method: 'POST', body: {} });
    if (status === 401) { renderBanner('warn', 'Admin token required.'); return; }
    if (!ok) { renderBanner('danger', body?.message || ('Rotate failed (HTTP ' + status + ')')); return; }
    state.reveal = { title: 'Rotated API key - copy it now', plaintext: body.plaintext || '' };
    await loadAndRender();
}

async function revokeKey(id) {
    if (!window.confirm('Revoke this key? It stops working immediately and cannot be restored.')) return;
    const { ok, body, status } = await api('/api/apikeys/' + encodeURIComponent(id) + '/revoke', { method: 'POST', body: {} });
    if (status === 401) { renderBanner('warn', 'Admin token required.'); return; }
    if (!ok) { renderBanner('danger', body?.message || ('Revoke failed (HTTP ' + status + ')')); return; }
    await loadAndRender();
}

async function copyKey(value) {
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        const textarea = el('textarea', { id: 'oc-clipboard-fallback', text: value });
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try { document.execCommand('copy'); } catch { /* ignore */ }
        textarea.remove();
    }
}

void probeStatus();
