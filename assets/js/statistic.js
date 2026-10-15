// statistic.js — admin Statistic tab.
//
// 1. Probes /api/statistics/status (no auth) to decide whether to render
//    the nav tab. Backend gates everything on StatisticTab=true AND the
//    admin token; this client just hides the tab when disabled.
// 2. Renders period selector (All / Year / Quarter / Week / Day / Hour)
//    and pulls /api/statistics/summary?period=... on change.
// 3. Top cards show session totals, token totals, latency / duration,
//    cost (or "—" when unavailable). A detailed table breaks every
//    metric down per (provider, model).
// 4. Every value is rendered with textContent ONLY — never innerHTML —
//    so even unsanitized payloads cannot inject markup. Numeric stats
//    are returned by the backend, but defense-in-depth applies on the
//    client.

const STAT_TAB     = document.getElementById('oc-tab-statistic');
const ROOT         = document.getElementById('oc-statistic-root');
const TAB_BUTTONS  = document.querySelectorAll('[data-tab]');
const TAB_PANELS   = document.querySelectorAll('[data-tab-panel]');

const PERIOD_LABELS = {
    all:     'All',
    year:    'Year',
    quarter: 'Quarter',
    week:    'Week',
    day:     'Day',
    hour:    'Hour',
};

const state = {
    status:  { tab_enabled: false, admin_configured: false, admin_unlocked: false, db_available: false, periods: ['all'], default_period: 'all' },
    period:  'all',
    data:    null,
    loading: false,
    error:   null,
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
    const res = await fetch(path, init);
    let body = null;
    try { body = await res.json(); } catch { body = null; }
    return { status: res.status, ok: res.ok, body: body ?? {} };
}

function fmtInt(n) {
    n = Number(n);
    if (!Number.isFinite(n)) return '—';
    return n.toLocaleString('en-US');
}

function fmtMs(n) {
    n = Number(n);
    if (!Number.isFinite(n) || n <= 0) return '—';
    if (n < 1000) return n + ' ms';
    if (n < 60_000) return (n / 1000).toFixed(2) + ' s';
    return Math.floor(n / 60_000) + 'm ' + Math.floor((n % 60_000) / 1000) + 's';
}

function fmtCost(v) {
    if (v === null || v === undefined) return '—';
    const n = Number(v);
    if (!Number.isFinite(n)) return '—';
    if (n === 0) return '$0.00';
    if (n < 0.01) return '$' + n.toFixed(6);
    return '$' + n.toFixed(4);
}

function activateTab(name) {
    TAB_BUTTONS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    TAB_PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
    if (name === 'statistic') void loadAndRender();
}

TAB_BUTTONS.forEach(t => {
    if (t.dataset.tab === 'statistic') {
        t.addEventListener('click', () => activateTab('statistic'));
    }
});

async function probeStatus() {
    const { body } = await api('/api/statistics/status');
    state.status = body || state.status;
    if (Array.isArray(body?.periods) && body.periods.length > 0) {
        if (!body.periods.includes(state.period)) {
            state.period = body.default_period || body.periods[0] || 'all';
        }
    }
    if (!STAT_TAB) return;
    // Tab visibility tracks the StatisticTab config flag alone. Admin
    // auth gates the tab body (lock screen / "admin not configured")
    // but not the navigation button.
    const tabEnabled = state.status.tab_enabled === true;
    STAT_TAB.hidden = !tabEnabled;
}

async function loadAndRender() {
    if (!ROOT) return;
    // Statistic tab is publicly viewable (v1.8.6): the only gate is the
    // StatisticTab flag in config.php; admin unlock is no longer required.
    if (!state.status.tab_enabled) { renderDisabled('Statistic tab is disabled in config.php (StatisticTab=false).'); return; }
    await loadSummary();
}

async function loadSummary() {
    state.loading = true;
    state.error = null;
    renderShell();
    const params = new URLSearchParams({ period: state.period });
    const { ok, body, status } = await api('/api/statistics/summary?' + params.toString());
    state.loading = false;
    if (status === 404 && body?.error === 'statistics_disabled') {
        renderDisabled('Statistic tab is disabled in config.php (StatisticTab=false).'); return;
    }
    if (!ok || body?.ok === false) {
        const msg = body?.message || ('Failed to load statistics (HTTP ' + status + ')');
        if (body?.error === 'no_database') { renderDisabled(msg); return; }
        state.error = msg;
        renderShell();
        return;
    }
    state.data = body;
    renderShell();
}

function renderDisabled(msg) {
    clear(ROOT);
    ROOT.append(el('div', { class: 'oc-alert oc-alert-warn', text: msg }));
}

function renderShell() {
    clear(ROOT);
    ROOT.append(renderToolbar());
    if (state.error) {
        ROOT.append(el('div', { class: 'oc-alert oc-alert-error', text: state.error }));
    }
    if (state.loading) {
        ROOT.append(el('div', { class: 'oc-statistic-empty', text: 'Loading…' }));
        return;
    }
    if (!state.data) {
        ROOT.append(el('div', { class: 'oc-statistic-empty', text: 'No data yet — pick a period to load statistics.' }));
        return;
    }
    ROOT.append(renderRange(state.data));
    ROOT.append(renderCards(state.data));
    ROOT.append(renderPerModel(state.data));
}

function renderToolbar() {
    const wrap = el('div', { class: 'oc-statistic-toolbar' });
    wrap.append(el('span', { class: 'oc-statistic-toolbar-label', text: 'Period:' }));
    const periods = Array.isArray(state.status.periods) && state.status.periods.length > 0
        ? state.status.periods
        : Object.keys(PERIOD_LABELS);
    for (const p of periods) {
        const label = PERIOD_LABELS[p] || p;
        const btn = el('button', {
            type: 'button',
            class: 'oc-statistic-period' + (state.period === p ? ' oc-statistic-period-active' : ''),
            text: label,
            onclick: () => { state.period = p; void loadSummary(); },
        });
        wrap.append(btn);
    }
    const refresh = el('button', {
        type: 'button',
        class: 'oc-button',
        text: 'Refresh',
        onclick: () => void loadSummary(),
    });
    wrap.append(refresh);
    return wrap;
}

function renderRange(d) {
    const range = el('div', { class: 'oc-statistic-range' });
    if (d.from && d.to) {
        range.append(el('span', { text: 'Window: ' + d.from + ' → ' + d.to + ' (UTC)' }));
    }
    return range;
}

function renderCards(d) {
    const grid = el('div', { class: 'oc-statistic-cards' });
    const sessions = d.sessions || {};
    const tokens   = d.tokens   || {};
    const calls    = d.calls    || {};
    const latency  = d.latency  || {};
    const cost     = d.cost     || {};

    grid.append(card('Sessions', [
        ['Total',     fmtInt(sessions.total)],
        ['Completed', fmtInt(sessions.completed)],
        ['Failed',    fmtInt(sessions.failed)],
        ['Running',   fmtInt(sessions.running)],
        ['Total duration', fmtMs(sessions.duration_total_ms)],
        ['Avg duration',   fmtMs(sessions.duration_avg_ms)],
    ]));

    grid.append(card('LLM calls', [
        ['Total',      fmtInt(calls.total)],
        ['Success',    fmtInt(calls.success)],
        ['Failed',     fmtInt(calls.failed)],
        ['Cache hits', fmtInt(calls.cache_hits)],
    ]));

    grid.append(card('Tokens', [
        ['Prompt',     fmtInt(tokens.prompt)],
        ['Completion', fmtInt(tokens.completion)],
        ['Total',      fmtInt(tokens.total)],
        ['Cached',     fmtInt(tokens.cached)],
        ['Reasoning',  fmtInt(tokens.reasoning)],
        ['Estimated rows', fmtInt(tokens.estimated_rows)],
    ]));

    grid.append(card('Latency', [
        ['Total', fmtMs(latency.total_ms)],
        ['Avg',   fmtMs(latency.avg_ms)],
    ]));

    grid.append(card('Cost', [
        ['Total', fmtCost(cost.total)],
        ['Avg',   fmtCost(cost.avg)],
        ['Reported by provider', cost.available ? 'yes' : 'no'],
    ]));

    return grid;
}

function card(title, rows) {
    const wrap = el('div', { class: 'oc-statistic-card' });
    wrap.append(el('h3', { class: 'oc-statistic-card-title', text: title }));
    const list = el('dl', { class: 'oc-statistic-card-list' });
    for (const [k, v] of rows) {
        list.append(el('dt', { text: k }));
        list.append(el('dd', { text: v }));
    }
    wrap.append(list);
    return wrap;
}

function renderPerModel(d) {
    const wrap = el('div', { class: 'oc-statistic-permodel' });
    wrap.append(el('h3', { text: 'Per-LLM breakdown' }));
    const rows = Array.isArray(d.per_model) ? d.per_model : [];
    if (rows.length === 0) {
        wrap.append(el('div', { class: 'oc-statistic-empty', text: 'No LLM calls recorded for this period.' }));
        return wrap;
    }
    const table = el('table', { class: 'oc-statistic-table' });
    const head  = el('thead');
    const headRow = el('tr');
    [
        'Provider', 'Model', 'Calls', 'Success', 'Failed',
        'Prompt tok', 'Completion tok', 'Total tok',
        'Cached tok', 'Reasoning tok', 'Cache hits',
        'Estimated', 'Total latency', 'Avg latency',
        'Cost', 'Avg cost',
    ].forEach((h) => headRow.append(el('th', { text: h })));
    head.append(headRow);
    table.append(head);
    const body = el('tbody');
    for (const r of rows) {
        const tr = el('tr');
        tr.append(el('td', { text: r.provider || '—' }));
        tr.append(el('td', { text: r.model    || '—' }));
        tr.append(el('td', { text: fmtInt(r.calls) }));
        tr.append(el('td', { text: fmtInt(r.success) }));
        tr.append(el('td', { text: fmtInt(r.failed) }));
        tr.append(el('td', { text: fmtInt(r.prompt_tokens) }));
        tr.append(el('td', { text: fmtInt(r.completion_tokens) }));
        tr.append(el('td', { text: fmtInt(r.total_tokens) }));
        tr.append(el('td', { text: fmtInt(r.cached_tokens) }));
        tr.append(el('td', { text: fmtInt(r.reasoning_tokens) }));
        tr.append(el('td', { text: fmtInt(r.cache_hits) }));
        tr.append(el('td', { text: fmtInt(r.estimated_rows) }));
        tr.append(el('td', { text: fmtMs(r.total_latency_ms) }));
        tr.append(el('td', { text: fmtMs(r.avg_latency_ms) }));
        tr.append(el('td', { text: fmtCost(r.cost) }));
        tr.append(el('td', { text: fmtCost(r.avg_cost) }));
        body.append(tr);
    }
    table.append(body);
    // v2.1 — sixteen columns never fit a phone, and without a scroll
    // container the table squeezed every header into a letter column.
    wrap.append(el('div', { class: 'table-wrap' }, table));
    return wrap;
}

void probeStatus();
