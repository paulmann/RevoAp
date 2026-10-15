// security.js — admin Security tab.
//
// 1. Probes /api/security/status (no auth) to decide whether to render
//    the nav tab. When SecurityTab=false in config.php, the tab stays
//    hidden and every protected endpoint returns 404 security_disabled.
// 2. When admin-authenticated, /api/security/status also auto-adds the
//    detected client IP to the whitelist (server-side) so a misclick on
//    "enable whitelist" cannot lock the operator out.
// 3. Renders editable whitelist / blacklist textareas, the trusted-proxy
//    list, an admin TTL field, and a runtime security checklist.
// 4. All log/IP content is rendered through textContent — never
//    innerHTML — so a paste with HTML-looking tokens cannot inject
//    markup. Buttons are wired through addEventListener.

const SECURITY_TAB = document.getElementById('oc-tab-security');
const ROOT         = document.getElementById('oc-security-root');
const TAB_BUTTONS  = document.querySelectorAll('[data-tab]');
const TAB_PANELS   = document.querySelectorAll('[data-tab-panel]');

let state = {
    status: { tab_enabled: false, admin_configured: false, admin_unlocked: false },
    security: null,
    clientIp: '',
    health: {},
    verdict: 'allow',
    loopback: ['127.0.0.0/8', '::1/128'],
    testIp: '',
    testResult: null,
    saving: false,
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
    if (name === 'security') void loadAndRender();
}

TAB_BUTTONS.forEach(t => {
    if (t.dataset.tab === 'security') {
        t.addEventListener('click', () => activateTab('security'));
    }
});

async function probeStatus() {
    const { body } = await api('/api/security/status');
    state.status = {
        tab_enabled:      body?.tab_enabled === true,
        admin_protection: body?.admin_protection !== false,
        admin_configured: body?.admin_configured === true,
        admin_unlocked:   body?.admin_unlocked === true,
    };
    if (body && Array.isArray(body.loopback_rules)) {
        state.loopback = body.loopback_rules;
    }
    if (SECURITY_TAB) {
        // Tab visibility is driven by SecurityTab=true alone. Admin auth
        // gates the tab body — clicking surfaces a clear notice when the
        // token is missing — but the operator must always see that the
        // feature is available.
        SECURITY_TAB.hidden = !state.status.tab_enabled;
    }
}

async function loadAndRender() {
    if (!ROOT) return;
    if (!state.status.tab_enabled) {
        renderBanner('warn', 'Security tab is disabled in config.php (SecurityTab=false). Enable it to manage IP rules.');
        return;
    }
    // v1.8.60 — a banner about a missing token only makes sense while a
    // token is actually required (see Protect_Settings_by_Admin_Key).
    if (state.status.admin_protection !== false && !state.status.admin_configured) {
        renderBanner('warn', 'Admin token not configured. Set REVOAP_ADMIN_TOKEN or .admin.key first.');
        return;
    }
    const { ok, body, status } = await api('/api/security/status');
    if (state.status.admin_protection !== false && (status === 401 || body?.error === 'unauthorized')) {
        renderBanner('warn', 'Admin token required. Open the Settings tab first to unlock.');
        return;
    }
    if (status === 404 && body?.error === 'security_disabled') {
        renderBanner('warn', 'Security tab is disabled in config.php (SecurityTab=false).');
        return;
    }
    if (!ok) {
        renderBanner('danger', body?.message || ('Failed to load security state (HTTP ' + status + ')'));
        return;
    }
    state.security = body.security || null;
    state.clientIp = body.client_ip || '';
    state.health   = body.health || {};
    state.verdict  = body.verdict || 'allow';
    if (Array.isArray(body.loopback_rules)) state.loopback = body.loopback_rules;
    render();
}

function renderBanner(kind, msg) {
    clear(ROOT);
    ROOT.append(el('div', { class: 'oc-security-banner oc-security-banner-' + kind, text: msg }));
}

function render() {
    if (!state.security) return;
    clear(ROOT);

    const ip = state.clientIp || '(not detected)';
    ROOT.append(el('div',
        { class: 'oc-security-banner oc-security-banner-info' },
        'Detected client IP: ', el('strong', { text: ip }),
        '. Verdict against current rules: ',
        el('strong', { text: state.verdict }),
        '. Loopback (',
        state.loopback.join(', '),
        ') is always allowed.'
    ));

    if (state.security.ip_whitelist_enabled
        && state.clientIp
        && !ipMatchesAny(state.clientIp, state.security.ip_whitelist)) {
        ROOT.append(el('div',
            { class: 'oc-security-banner oc-security-banner-danger' },
            'Warning: whitelist enforcement is ON and your IP (', state.clientIp,
            ') does not match any rule. Click "Add my IP" before saving or you will be locked out.'
        ));
    }

    const grid = el('div', { class: 'oc-security-grid' });
    grid.append(renderWhitelistCard(), renderBlacklistCard(), renderProxyCard(), renderHealthCard());
    ROOT.append(grid);

    ROOT.append(renderTestCard());

    const toolbar = el('div', { class: 'oc-security-toolbar' });
    toolbar.append(
        el('button', { type: 'button', class: 'oc-button oc-button-primary',
            onclick: () => void saveAll(),
            text: state.saving ? 'Saving…' : 'Save all changes' }),
        el('button', { type: 'button', class: 'oc-button',
            onclick: () => void addCurrentIp(),
            text: 'Add my IP to whitelist' }),
        el('button', { type: 'button', class: 'oc-button oc-button-danger',
            onclick: () => void resetToDefaults(),
            text: 'Reset to safe defaults' }),
    );
    ROOT.append(toolbar);
}

function renderWhitelistCard() {
    const card = el('div', { class: 'oc-security-card' });
    card.append(el('h3', { text: 'IP whitelist' }));
    card.append(el('p', { class: 'oc-muted',
        text: 'When enabled, only IPs matching one of these rules can reach the application. Loopback is always allowed; the current admin IP is auto-added on every visit to this tab.' }));
    const toggle = el('label', { class: 'oc-security-toggle' });
    const cb = el('input', { id: 'oc-security-whitelist-toggle', type: 'checkbox' });
    cb.checked = state.security.ip_whitelist_enabled === true;
    cb.addEventListener('change', () => { state.security.ip_whitelist_enabled = cb.checked; render(); });
    toggle.append(cb, document.createTextNode('Enable whitelist enforcement'));
    card.append(toggle);
    const ta = el('textarea', { id: 'oc-security-whitelist-list', class: 'oc-security-list',
        placeholder: 'One IP or CIDR per line, e.g.\n203.0.113.42\n10.0.0.0/8\n2001:db8::/32' });
    ta.value = (state.security.ip_whitelist || []).join('\n');
    ta.addEventListener('input', () => { state.security.ip_whitelist = splitLines(ta.value); });
    card.append(ta);
    return card;
}

function renderBlacklistCard() {
    const card = el('div', { class: 'oc-security-card' });
    card.append(el('h3', { text: 'IP blacklist' }));
    card.append(el('p', { class: 'oc-muted',
        text: 'Matched IPs are denied with HTTP 403, before reaching any controller. Blacklist takes precedence over whitelist; loopback rules are silently stripped from this list.' }));
    const toggle = el('label', { class: 'oc-security-toggle' });
    const cb = el('input', { id: 'oc-security-blacklist-toggle', type: 'checkbox' });
    cb.checked = state.security.ip_blacklist_enabled === true;
    cb.addEventListener('change', () => { state.security.ip_blacklist_enabled = cb.checked; render(); });
    toggle.append(cb, document.createTextNode('Enable blacklist enforcement'));
    card.append(toggle);
    const ta = el('textarea', { id: 'oc-security-blacklist-list', class: 'oc-security-list',
        placeholder: 'One IP or CIDR per line' });
    ta.value = (state.security.ip_blacklist || []).join('\n');
    ta.addEventListener('input', () => { state.security.ip_blacklist = splitLines(ta.value); });
    card.append(ta);
    return card;
}

function renderProxyCard() {
    const card = el('div', { class: 'oc-security-card' });
    card.append(el('h3', { text: 'Trusted proxies' }));
    card.append(el('p', { class: 'oc-muted',
        text: 'When the request arrives from one of these proxies, X-Forwarded-For / X-Real-IP is honoured. If empty (or the toggle is off), only REMOTE_ADDR is trusted — XFF is never blindly trusted.' }));
    const toggle = el('label', { class: 'oc-security-toggle' });
    const cb = el('input', { id: 'oc-security-proxy-toggle', type: 'checkbox' });
    cb.checked = state.security.trust_proxy_headers === true;
    cb.addEventListener('change', () => { state.security.trust_proxy_headers = cb.checked; render(); });
    toggle.append(cb, document.createTextNode('Trust X-Forwarded-For from listed proxies'));
    card.append(toggle);
    const ta = el('textarea', { id: 'oc-security-proxy-list', class: 'oc-security-list',
        placeholder: 'Trusted proxy IPs / CIDRs (e.g. 10.0.0.0/8)' });
    ta.value = (state.security.trusted_proxies || []).join('\n');
    ta.addEventListener('input', () => { state.security.trusted_proxies = splitLines(ta.value); });
    card.append(ta);

    const row = el('div', { class: 'oc-security-row' });
    const label = el('label', { for: 'oc-security-admin-ttl', text: 'Admin session TTL (min):' });
    const input = el('input', { id: 'oc-security-admin-ttl', type: 'number', min: '5', max: '43200', class: 'oc-input' });
    input.value = String(state.security.admin_session_ttl_minutes || 120);
    input.addEventListener('input', () => {
        const v = parseInt(input.value, 10);
        if (Number.isFinite(v) && v >= 5) state.security.admin_session_ttl_minutes = v;
    });
    row.append(label, input);
    card.append(row);
    return card;
}

function renderHealthCard() {
    const card = el('div', { class: 'oc-security-card' });
    card.append(el('h3', { text: 'Runtime checklist' }));
    card.append(el('p', { class: 'oc-muted',
        text: 'Read-only. These are surfaced for awareness — none expose secrets.' }));
    const grid = el('div', { class: 'oc-security-status' });
    const items = [
        ['HTTPS detected',         state.health.https_detected,    'goodTrue'],
        ['Admin token configured', state.health.admin_token_configured, 'goodTrue'],
        ['Debug mode',             state.health.app_debug,         'goodFalse'],
        ['Settings tab',           state.health.settings_tab_enabled, 'neutral'],
        ['Logs tab',               state.health.logs_tab_enabled,  'neutral'],
        ['Security tab',           state.health.security_tab_enabled, 'goodTrue'],
        ['Logs dir writable',      state.health.logs_dir_writable, 'goodTrue'],
        ['var/ writable',          state.health.var_dir_writable,  'goodTrue'],
        ['Security headers',       state.health.security_headers,  'goodTrue'],
    ];
    for (const [label, value, kind] of items) {
        const row = el('div');
        row.append(el('span', { text: label }));
        row.append(el('span', {
            class: classifyFlag(value, kind),
            text: value === true ? 'on' : (value === false ? 'off' : '—'),
        }));
        grid.append(row);
    }
    card.append(grid);
    return card;
}

function classifyFlag(value, kind) {
    if (value !== true && value !== false) return 'oc-security-flag-off';
    if (kind === 'goodTrue')  return value ? 'oc-security-flag-on' : 'oc-security-flag-warn';
    if (kind === 'goodFalse') return value ? 'oc-security-flag-bad' : 'oc-security-flag-on';
    return value ? 'oc-security-flag-on' : 'oc-security-flag-off';
}

function renderTestCard() {
    const card = el('div', { class: 'oc-security-card' });
    card.append(el('h3', { text: 'Test an IP against current rules' }));
    card.append(el('p', { class: 'oc-muted',
        text: 'Dry-run: never modifies any list. Useful before flipping the whitelist toggle on a remote host.' }));
    const wrap = el('div', { class: 'oc-security-test' });
    const input = el('input', { id: 'oc-security-test-ip', 'aria-label': 'IP or CIDR to test', type: 'text', class: 'oc-input',
        placeholder: 'IP or CIDR (e.g. 198.51.100.7)', value: state.testIp });
    input.addEventListener('input', () => { state.testIp = input.value; });
    const btn = el('button', { type: 'button', class: 'oc-button',
        text: 'Test', onclick: () => void runTest() });
    wrap.append(input, btn);
    if (state.testResult) {
        const cls = state.testResult.allowed
            ? 'oc-security-test-result oc-security-test-allow'
            : 'oc-security-test-result oc-security-test-deny';
        wrap.append(el('span', { class: cls,
            text: state.testResult.allowed
                ? ('ALLOW — ' + state.testResult.ip + ' (' + state.testResult.verdict + ')')
                : ('DENY — '  + state.testResult.ip + ' (' + state.testResult.verdict + ')')
        }));
    }
    card.append(wrap);
    return card;
}

function splitLines(text) {
    return String(text || '').split(/[\r\n,]+/).map(s => s.trim()).filter(Boolean);
}

function ipMatchesAny(ip, rules) {
    // Best-effort client-side check; the server is authoritative. Used
    // only to surface the lockout warning before the user clicks Save.
    if (!ip || !Array.isArray(rules)) return false;
    for (const rule of rules) {
        if (typeof rule !== 'string') continue;
        if (rule === ip) return true;
        if (rule.indexOf('/') === -1) continue;
        // Don't try to subnet-match on the client — server will validate.
        // Treat exact loopback rules as always-match for the warning.
        if (rule === '127.0.0.0/8' && /^127\./.test(ip)) return true;
        if (rule === '::1/128' && ip === '::1') return true;
    }
    return false;
}

async function saveAll() {
    if (!state.security || state.saving) return;
    state.saving = true;
    render();
    const { ok, body, status } = await api('/api/security/save', {
        method: 'POST',
        body: state.security,
    });
    state.saving = false;
    if (status === 401) { renderBanner('warn', 'Admin token required.'); return; }
    if (!ok) { renderBanner('danger', body?.message || ('Save failed (HTTP ' + status + ')')); return; }
    state.security = body.security || state.security;
    state.clientIp = body.client_ip || state.clientIp;
    state.verdict  = body.verdict || state.verdict;
    render();
}

async function addCurrentIp() {
    if (!state.security || !state.clientIp) return;
    const ip = state.clientIp;
    if (!state.security.ip_whitelist.includes(ip) && !ipMatchesAny(ip, state.security.ip_whitelist)) {
        state.security.ip_whitelist = [...state.security.ip_whitelist, ip];
    }
    render();
    await saveAll();
}

async function resetToDefaults() {
    if (!window.confirm('Reset security settings to safe defaults? Enforcement will be turned off and only loopback will be in the whitelist.')) {
        return;
    }
    const { ok, body, status } = await api('/api/security/reset', { method: 'POST', body: {} });
    if (status === 401) { renderBanner('warn', 'Admin token required.'); return; }
    if (!ok) { renderBanner('danger', body?.message || ('Reset failed (HTTP ' + status + ')')); return; }
    state.security = body.security || state.security;
    render();
}

async function runTest() {
    if (!state.testIp) return;
    const { ok, body, status } = await api('/api/security/test-ip', {
        method: 'POST',
        body: { ip: state.testIp },
    });
    if (status === 401) { renderBanner('warn', 'Admin token required.'); return; }
    if (!ok) {
        state.testResult = { allowed: false, ip: state.testIp, verdict: body?.error || 'invalid' };
    } else {
        state.testResult = body;
    }
    render();
}

void probeStatus();
