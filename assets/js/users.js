/**
 * users.js — the End users & usage panel (v2.1.0, T5).
 *
 * Replaces the server-rendered pending card for the Users tab with the live
 * multi-user surface: every pipeline identity, what its runs consumed (from
 * the append-only usage ledger), the budget that limits it, and the two
 * operator actions that belong here — editing a per-user budget and pulling
 * a data-subject export.
 *
 * Two things this panel is deliberately NOT:
 *
 *  1. NOT the operator RBAC console. `revoap_admin_users` management (logins,
 *     roles, 2FA) is the separate pending "Users & access" surface; the card
 *     at the bottom says so instead of leaving an empty space that reads as
 *     broken (docs/ADMIN.md §3).
 *  2. NOT a second implementation of the budget rule. Quota state shown here
 *     is the enforcer's own verdict (`/api/my/usage` shape) or the rollup the
 *     server computed — the panel never recomputes a limit client-side,
 *     because the two answers would eventually disagree.
 *
 * Money honesty: a null cost renders as "—" with the coverage hint, never as
 * $0.00 — an invented zero for an unpriced provider is worse than a gap.
 *
 * No `innerHTML` with interpolated values anywhere: user names, emails and
 * query previews are attacker-influenced text.
 *
 * @project RevoAp   @since 2.1.0
 */
import { h, clear } from './ui/dom.js';
import {
    Button, Card, Badge, Alert, Stat, Field, Input, Select, Switch,
    Dialog, Toast, EmptyState, Separator, Progress,
} from './ui/components.js';
import { t, formatNumber, formatPercent, timeAgo, formatDateTime } from './i18n.js';

const ROOT_ID = 'oc-users-root';

// The backend's StatisticsPeriod set — a period the server does not know
// silently normalizes to 'all', so the picker must only offer real ones.
const PERIODS = ['day', 'week', 'quarter', 'year', 'all'];

async function api(path, opts = {}) {
    const res = await fetch(path, {
        method: opts.method || 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(opts.body ? { 'Content-Type': 'application/json' } : {}) },
        body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const text = await res.text();
    let body = null;
    try { body = text ? JSON.parse(text) : null; } catch { body = null; }
    if (!res.ok) {
        const err = new Error((body && (body.message || body.error)) || `HTTP ${res.status}`);
        err.status = res.status;
        err.body = body;
        throw err;
    }
    return body || {};
}

/** Money rendering: null is a gap, not a zero. */
function money(cost, currency = 'USD') {
    if (cost === null || cost === undefined) return '—';
    const v = Number(cost);
    const decimals = Math.abs(v) < 1 ? 4 : 2;
    return `${currency} ${formatNumber(v, decimals)}`;
}

/** Coverage badge: honest about unpriced spend without nagging when full. */
function coverageBadge(totals) {
    if (!totals || !totals.calls) return null;
    const cov = totals.calls > 0 ? totals.cost_rows / totals.calls : 1;
    if (cov >= 1) return null;
    return Badge(`${t('usage.coverage')}: ${formatPercent(cov, 0)}`, {
        tone: 'warning', title: t('usage.coverage_hint'),
    });
}

export async function mount(root) {
    const host = root || document.getElementById(ROOT_ID);
    if (!host) return;

    const state = { period: 'week', detailId: null };

    const renderList = async () => {
        state.detailId = null;
        let data;
        try {
            data = await api(`/api/admin/users?period=${encodeURIComponent(state.period)}`);
        } catch (err) {
            if (err.status === 403) { renderLocked(host); return; }
            renderError(host, err, renderList);
            return;
        }
        if (!host.isConnected) return;
        clear(host);
        host.classList.add('stack');
        host.appendChild(header(state, renderList));
        if (!data.ledger_migrated) {
            host.appendChild(Alert({ tone: 'warning', title: t('usage.ledger_unmigrated'),
                description: 'db/migrations/024_usage_ledger.sql' }));
        }
        host.appendChild(tiles(data));
        host.appendChild(usersCard(data, state, renderList, () => openDetail(state, renderList)));
        host.appendChild(rbacPendingCard());
    };

    const openDetail = async (st, back) => {
        let data;
        try {
            data = await api(`/api/admin/users/${st.detailId}?period=${encodeURIComponent(st.period)}&days=30`);
        } catch (err) {
            if (err.status === 403) { renderLocked(host); return; }
            renderError(host, err, back);
            return;
        }
        if (!host.isConnected) return;
        clear(host);
        host.classList.add('stack');
        host.appendChild(detailView(data, st, back, renderList));
    };

    await renderList();
}

/* ---------------------------------------------------------------- header */

function header(state, refresh) {
    const periodSelect = Select(
        PERIODS.map((p) => ({ value: p, label: t(`statistic.period_${p}`) })),
        { value: state.period, id: 'oc-users-period', class: 'select-sm w-auto' },
    );
    periodSelect.addEventListener('change', () => { state.period = periodSelect.value; void refresh(); });
    const bar = h('div', { class: 'row-between gap-3 flex-wrap' },
        h('div', { class: 'stack-sm min-w-0' },
            h('h2', { class: 'card-title', text: t('usage.title') }),
            h('p', { class: 'card-description', text: t('usage.subtitle') }),
        ),
        h('div', { class: 'row gap-2' },
            Field({ label: t('usage.period'), control: periodSelect, id: 'oc-users-period-field' }),
            Button({ variant: 'outline', size: 'sm', iconName: 'refresh', label: t('usage.resync'),
                title: t('usage.resync_hint'), onClick: () => resync(refresh) }),
        ),
    );
    return bar;
}

async function resync(refresh) {
    try {
        const r = await api('/api/admin/users/sync', { method: 'POST' });
        Toast.success(r.synced > 0
            ? t('usage.resync_done', { count: r.synced })
            : t('usage.resync_converged'));
        void refresh();
    } catch (err) {
        Toast.error(err.message || String(err));
    }
}

/* ----------------------------------------------------------------- tiles */

function tiles(data) {
    const un = data.unattributed || {};
    let tokens = (un.tokens || 0);
    let runs = (un.runs || 0);
    let cost = null;
    let pricedAll = true;
    for (const u of data.users || []) {
        tokens += u.tokens || 0;
        runs += u.runs || 0;
        if (u.cost === null) { pricedAll = false; } else { cost = (cost || 0) + u.cost; }
    }
    if (un.cost === null) pricedAll = false; else if (un.cost) cost = (cost || 0) + un.cost;
    return h('div', { class: 'grid gap-3 grid-cols-2 lg:grid-cols-3' },
        Stat({ label: t('usage.runs'), value: formatNumber(runs), icon: 'play' }),
        Stat({ label: t('usage.tokens'), value: formatNumber(tokens), icon: 'hash' }),
        Stat({ label: t('usage.cost'), value: pricedAll ? money(cost) : `${money(cost)}*`,
            hint: pricedAll ? null : t('usage.coverage_hint'), icon: 'wallet' }),
        Stat({ label: t('users.list'), value: formatNumber((data.users || []).length), icon: 'users' }),
    );
}

/* ------------------------------------------------------------ users card */

function usersCard(data, state, refresh, openDetail) {
    const rows = data.users || [];
    const body = h('div', { class: 'stack' });

    if (!rows.length) {
        body.appendChild(EmptyState({ iconName: 'users', title: t('usage.no_users'), description: t('usage.no_users_hint') }));
    } else {
        body.appendChild(userTable(rows, state, refresh, openDetail));
    }

    const un = data.unattributed || null;
    if (un && (un.runs > 0 || un.calls > 0)) {
        body.appendChild(Separator());
        body.appendChild(unattributedRow(un));
    }
    return Card({ title: t('users.list'), description: t('usage.period') + ': ' + t(`statistic.period_${state.period}`) }, body);
}

function userTable(rows, state, refresh, openDetail) {
    const el = h('table', { class: 'table table-compact' });
    el.appendChild(h('thead', {}, h('tr', {},
        [t('usage.user'), t('usage.runs'), t('usage.tokens'), t('usage.cost'),
         t('usage.budgets'), t('usage.last_run')].map((hdr) => h('th', { text: hdr })),
        h('th', { class: 'text-end', text: '' }),
    )));
    const tbody = h('tbody');
    for (const u of rows) {
        const idCell = h('td', { class: 'xs' },
            h('div', { class: 'stack-sm' },
                h('div', { class: 'row gap-2' },
                    h('span', { class: 'font-medium', text: u.name || u.email || (u.is_local ? t('usage.local_user') : u.external_id || `#${u.id}`) }),
                    u.is_local ? Badge(t('usage.local_user'), { tone: 'muted' }) : null,
                ),
                h('span', { class: 'xs muted mono', text: `#${u.id} · ${u.external_id || ''}` }),
            ),
        );
        const budgetCell = h('td', { class: 'xs' });
        if (!(u.budgets || []).length) {
            budgetCell.appendChild(h('span', { class: 'xs muted', text: '—' }));
        } else {
            for (const b of u.budgets) {
                const pct = b.amount > 0 ? Math.min(1.5, b.spent_amount / b.amount) : 0;
                const tone = !b.enabled ? 'muted' : (pct >= 1 ? (b.action_at_limit === 'block' ? 'destructive' : 'warning') : 'success');
                budgetCell.appendChild(Badge(`${money(b.spent_amount, b.currency)} / ${money(b.amount, b.currency)} · ${t('budgets.period_' + b.period)}`, { tone, dot: true }));
                budgetCell.appendChild(h('span', { text: ' ' }));
            }
        }
        const tr = h('tr', {},
            idCell,
            h('td', { class: 'xs mono', text: formatNumber(u.runs || 0) }),
            h('td', { class: 'xs mono', text: formatNumber(u.tokens || 0) }),
            h('td', { class: 'xs mono', text: money(u.cost) }),
            budgetCell,
            h('td', { class: 'xs muted', text: u.last_run ? timeAgo(u.last_run) : t('usage.never_ran') }),
            h('td', { class: 'text-end' },
                h('div', { class: 'row gap-1 justify-end' },
                    Button({ variant: 'ghost', size: 'sm', iconName: 'barChart', label: t('usage.detail'),
                        onClick: () => { state.detailId = u.id; void openDetail(); } }),
                    Button({ variant: 'ghost', size: 'sm', iconName: 'wallet', label: t('usage.budget_add'),
                        onClick: () => openBudgetDialog(u, null, refresh) }),
                    Button({ variant: 'ghost', size: 'sm', iconName: 'download', label: t('usage.export'),
                        title: t('usage.export_hint'), onClick: () => exportUser(u) }),
                ),
            ),
        );
        tbody.appendChild(tr);
    }
    el.appendChild(tbody);
    return h('div', { class: 'table-wrap max-h-[30rem] overflow-y-auto' }, el);
}

function unattributedRow(un) {
    return h('div', { class: 'row-between gap-3 flex-wrap' },
        h('div', { class: 'stack-sm min-w-0' },
            h('div', { class: 'row gap-2' },
                Badge(t('usage.unattributed'), { tone: 'warning', dot: true }),
                h('span', { class: 'xs muted', text: t('usage.unattributed_hint') }),
            ),
        ),
        h('div', { class: 'row gap-3 xs mono' },
            h('span', { text: `${t('usage.runs')}: ${formatNumber(un.runs || 0)}` }),
            h('span', { text: `${t('usage.tokens')}: ${formatNumber(un.tokens || 0)}` }),
            h('span', { text: `${t('usage.cost')}: ${money(un.cost)}` }),
        ),
    );
}

/* ---------------------------------------------------------- detail view */

function detailView(data, state, back, refresh) {
    const u = data.user || {};
    const totals = data.totals || {};
    const wrap = h('div', { class: 'stack' });

    wrap.appendChild(h('div', { class: 'row-between gap-3 flex-wrap' },
        h('div', { class: 'stack-sm' },
            h('div', { class: 'row gap-2' },
                Button({ variant: 'ghost', size: 'sm', iconName: 'arrowLeft', label: t('users.list'), onClick: () => back() }),
            ),
            h('h2', { class: 'card-title', text: u.name || u.email || u.external_id || `#${u.id}` }),
            h('p', { class: 'card-description mono', text: `#${u.id} · ${u.external_id || ''}${u.email ? ' · ' + u.email : ''}` }),
        ),
        h('div', { class: 'row gap-2' },
            Button({ variant: 'outline', size: 'sm', iconName: 'download', label: t('usage.export'), onClick: () => exportUser(u) }),
            Button({ variant: 'outline', size: 'sm', iconName: 'wallet', label: t('usage.budget_add'),
                onClick: () => openBudgetDialog(u, null, () => { state.detailId = u.id; void back(); }) }),
        ),
    ));

    wrap.appendChild(h('div', { class: 'grid gap-3 grid-cols-2 lg:grid-cols-3' },
        Stat({ label: t('usage.runs'), value: formatNumber(totals.runs || 0), icon: 'play',
            hint: (totals.failures || 0) > 0 ? `${t('usage.failed_runs')}: ${formatNumber(totals.failures)}` : null }),
        Stat({ label: t('usage.tokens'), value: formatNumber(totals.total_tokens || 0), icon: 'hash',
            hint: `${t('usage.calls')}: ${formatNumber(totals.calls || 0)}` }),
        Stat({ label: t('usage.cost'), value: money(totals.cost), icon: 'wallet',
            hint: totals.calls > 0 && totals.cost_rows < totals.calls
                ? `${t('usage.coverage')}: ${formatPercent(totals.cost_rows / totals.calls, 0)}` : null }),
        Stat({ label: t('usage.cached'), value: formatNumber(totals.cached_tokens || 0), icon: 'zap',
            hint: t('usage.cached_hint') }),
    ));

    if (totals.calls > 0 && totals.cost_rows < totals.calls) {
        wrap.appendChild(Alert({ tone: 'default', title: t('usage.coverage'), description: t('usage.coverage_hint') }));
    }

    wrap.appendChild(seriesCard(data.series || []));
    wrap.appendChild(modelsCard(data.by_model || []));
    wrap.appendChild(budgetsCard(data, state, back));
    wrap.appendChild(runsCard(data.recent_runs || []));
    return wrap;
}

function seriesCard(series) {
    const body = h('div');
    if (!series.length) {
        body.appendChild(EmptyState({ iconName: 'barChart', title: t('usage.no_activity'), compact: true }));
        return Card({ title: t('usage.daily_series') }, body);
    }
    const max = Math.max(1, ...series.map((s) => s.tokens || 0));
    // One Progress row per day instead of a hand-rolled bar chart: the
    // component's CSS is in the committed build, the role=progressbar
    // semantics come free, and no dynamic-width Tailwind class is needed
    // (Tailwind cannot generate a class it never sees literally —
    // REWORK-STATE gotcha #6).
    const list = h('div', { class: 'stack-sm max-h-[24rem] overflow-y-auto' });
    for (const s of series) {
        const pct = Math.max(0, Math.min(100, Math.round(((s.tokens || 0) / max) * 100)));
        const costTxt = s.cost !== null ? ` · ${money(s.cost)}` : ` · ${t('usage.cost')}: —`;
        list.appendChild(h('div', { class: 'row gap-2', title: `${s.day}: ${formatNumber(s.tokens || 0)} ${t('usage.tokens').toLowerCase()} · ${formatNumber(s.runs || 0)} ${t('usage.runs').toLowerCase()}${costTxt}` },
            h('span', { class: 'xs muted mono', text: (s.day || '').slice(5) }),
            h('div', { class: 'flex-1 min-w-0' }, Progress(pct, { tone: pct >= 100 ? 'warning' : 'default' })),
            h('span', { class: 'xs mono', text: formatNumber(s.tokens || 0) }),
        ));
    }
    body.appendChild(list);
    return Card({ title: t('usage.daily_series'), description: t('usage.tokens') }, body);
}

function modelsCard(models) {
    if (!models.length) {
        return Card({ title: t('usage.by_model') },
            EmptyState({ iconName: 'cpu', title: t('usage.no_activity'), compact: true }));
    }
    const el = h('table', { class: 'table table-compact' });
    el.appendChild(h('thead', {}, h('tr', {},
        [t('usage.model'), t('usage.calls'), t('usage.tokens'), t('usage.cached'), t('usage.cost')]
            .map((hdr) => h('th', { text: hdr })),
    )));
    const tbody = h('tbody');
    for (const m of models) {
        tbody.appendChild(h('tr', {},
            h('td', { class: 'xs mono' },
                h('span', { text: m.model || '?' }),
                h('span', { class: 'muted', text: m.provider ? ` · ${m.provider}` : '' }),
            ),
            h('td', { class: 'xs mono', text: formatNumber(m.calls || 0) }),
            h('td', { class: 'xs mono', text: formatNumber(m.tokens || 0) }),
            h('td', { class: 'xs mono', text: formatNumber(m.cached_tokens || 0) }),
            h('td', { class: 'xs mono', text: money(m.cost) }),
        ));
    }
    el.appendChild(tbody);
    return Card({ title: t('usage.by_model') }, h('div', { class: 'table-wrap max-h-[24rem] overflow-y-auto' }, el));
}

function budgetsCard(data, state, back) {
    const budgets = data.budgets || [];
    const body = h('div', { class: 'stack' });
    if (!budgets.length) {
        body.appendChild(h('p', { class: 'card-description', text: t('usage.budget_none') }));
    }
    for (const b of budgets) {
        const pct = b.amount > 0 ? Math.min(1, (b.spent_amount || 0) / b.amount) : 0;
        const over = b.amount > 0 && b.spent_amount >= b.amount;
        const row = h('div', { class: 'stack-sm border border-border rounded-md p-3' },
            h('div', { class: 'row-between gap-3 flex-wrap' },
                h('div', { class: 'stack-sm' },
                    h('div', { class: 'row gap-2' },
                        h('span', { class: 'font-medium', text: b.name }),
                        Badge(t('budgets.period_' + b.period), { tone: 'muted' }),
                        Badge(t('budgets.action_' + b.action_at_limit), { tone: b.action_at_limit === 'block' ? 'destructive' : 'secondary' }),
                        b.enabled ? null : Badge(t('users.status_disabled'), { tone: 'muted' }),
                        over ? Badge(t('usage.quota_block'), { tone: 'destructive', dot: true }) : null,
                    ),
                    h('span', { class: 'xs muted', text: t('usage.spent_of', { spent: money(b.spent_amount, b.currency), amount: money(b.amount, b.currency) }) }),
                ),
                Button({ variant: 'outline', size: 'sm', iconName: 'pencil', label: t('usage.budget_edit'),
                    onClick: () => openBudgetDialog(data.user, b, () => { void back(); }) }),
            ),
            Progress(Math.round(pct * 100), { tone: over ? 'destructive' : pct > 0.8 ? 'warning' : 'default' }),
        );
        body.appendChild(row);
    }
    const alerts = data.alerts || [];
    if (alerts.length) {
        body.appendChild(Separator());
        body.appendChild(h('div', { class: 'stack-sm' },
            h('span', { class: 'xs muted', text: t('budgets.alerts') }),
            ...alerts.slice(0, 8).map((a) => h('div', { class: 'xs row gap-2' },
                Badge(a.kind === 'limit' ? '100%' : `${a.threshold}%`, { tone: a.kind === 'limit' ? 'destructive' : 'warning' }),
                h('span', { class: 'muted', text: `${a.period_key} · ${timeAgo(new Date(Number(a.fired_at) * 1000).toISOString())}` }),
                h('span', { class: 'mono', text: `${money(Number(a.spent))} / ${money(Number(a.amount))}` }),
            )),
        ));
    }
    return Card({ title: t('usage.budgets'), description: t('budgets.subtitle') }, body);
}

function runsCard(runs) {
    if (!runs.length) {
        return Card({ title: t('usage.recent_runs') },
            EmptyState({ iconName: 'history', title: t('usage.no_activity'), compact: true }));
    }
    const el = h('table', { class: 'table table-compact' });
    el.appendChild(h('thead', {}, h('tr', {},
        [t('usage.run'), t('usage.status'), t('usage.tokens'), t('usage.cost'), t('usage.when'), '']
            .map((hdr) => h('th', { text: hdr })),
    )));
    const tbody = h('tbody');
    for (const r of runs) {
        const tone = r.status === 'completed' ? 'success' : r.status === 'failed' ? 'destructive' : 'warning';
        tbody.appendChild(h('tr', {},
            h('td', { class: 'xs' },
                h('div', { class: 'stack-sm' },
                    h('span', { class: 'mono xs muted', text: `#${r.session_id}` }),
                    h('span', { class: 'xs truncate', text: r.query_preview || '', title: r.query_preview || '' }),
                ),
            ),
            h('td', { class: 'xs' }, Badge(r.status, { tone })),
            h('td', { class: 'xs mono', text: formatNumber(r.tokens || 0) }),
            h('td', { class: 'xs mono', text: money(r.cost) }),
            h('td', { class: 'xs muted', text: r.run_created_at ? formatDateTime(r.run_created_at.replace(' ', 'T') + 'Z') : '' }),
            h('td', { class: 'text-end' },
                Button({ variant: 'ghost', size: 'sm', iconName: 'externalLink', label: t('usage.view_run'),
                    onClick: () => { window.RevoApShell?.setTab('history'); } }),
            ),
        ));
    }
    el.appendChild(tbody);
    return Card({ title: t('usage.recent_runs') }, h('div', { class: 'table-wrap max-h-[24rem] overflow-y-auto' }, el));
}

/* --------------------------------------------------------- budget dialog */

function openBudgetDialog(user, budget, onSaved) {
    const editing = budget && budget.id;
    const amount = Input({ type: 'number', step: '0.01', min: '0', id: 'oc-budget-amount',
        value: editing ? String(budget.amount) : '' });
    const currency = Input({ id: 'oc-budget-currency', maxlength: 3,
        value: editing ? budget.currency : 'USD' });
    const period = Select(['daily', 'weekly', 'monthly', 'yearly'].map((p) => ({ value: p, label: t('budgets.period_' + p) })),
        { value: editing ? budget.period : 'monthly', id: 'oc-budget-period' });
    const action = Select([
        { value: 'warn', label: t('budgets.action_warn') },
        { value: 'throttle', label: t('budgets.action_throttle') },
        { value: 'block', label: t('budgets.action_block') },
    ], { value: editing ? budget.action_at_limit : 'warn', id: 'oc-budget-action' });
    const thresholds = Input({ id: 'oc-budget-thresholds', value: editing ? budget.thresholds : '50,80,95' });
    // Switch() with a label returns the ROW, not the input, so the checked
    // state travels in a closure variable rather than a .checked read.
    let enabledState = editing ? !!budget.enabled : true;
    const enabled = Switch({ checked: enabledState, label: t('users.status_active'), id: 'oc-budget-enabled',
        onChange: (v) => { enabledState = v; } });
    const name = Input({ id: 'oc-budget-name', value: editing ? budget.name : '' ,
        placeholder: `${t('usage.budget')} · ${user.name || user.external_id || '#' + user.id}` });

    const save = async () => {
        const payload = {
            id: editing ? budget.id : undefined,
            name: name.value, amount: Number(amount.value), currency: currency.value,
            period: period.value, action_at_limit: action.value,
            thresholds: thresholds.value, enabled: enabledState,
        };
        try {
            const r = await api(`/api/admin/users/${user.id}/budget`, { method: 'POST', body: payload });
            if (!r.ok) throw new Error(r.error || 'save_failed');
            Toast.success(t('usage.budget_saved'));
            dlg.close();
            onSaved && onSaved();
        } catch (err) {
            Toast.error(`${t('usage.budget_save_failed')} ${err.message || ''}`);
        }
    };

    // Dialog() opens on construction (it pushes its own layer) — there is
    // no .open(); `dlg.close()` is the handle the save button needs.
    const dlg = Dialog({
        title: editing ? t('usage.budget_edit') : t('usage.budget_add'),
        description: t('budgets.threshold_help'),
        content: [
            Field({ label: t('budgets.name'), control: name }),
            Field({ label: t('budgets.limit'), control: amount, required: true }),
            Field({ label: t('budgets.currency'), control: currency }),
            Field({ label: t('budgets.period'), control: period }),
            Field({ label: t('budgets.action_at_limit'), control: action, description: t('budgets.action_throttle') }),
            Field({ label: t('budgets.thresholds'), control: thresholds }),
            enabled,
        ],
        footer: [
            Button({ variant: 'ghost', label: t('common.cancel'), onClick: () => dlg.close() }),
            Button({ label: t('common.save'), iconName: 'check', onClick: () => void save() }),
        ],
    });
}

/* ----------------------------------------------------------------- misc */

function exportUser(u) {
    Toast.success(t('usage.export_started'));
    // A plain navigation download: the endpoint sets Content-Disposition,
    // and the admin cookie rides along on a same-origin navigation.
    window.location.href = `/api/admin/users/${u.id}/export`;
}

function rbacPendingCard() {
    return Card({ title: t('usage.rbac_pending') },
        h('div', { class: 'stack-sm' },
            h('p', { class: 'card-description', text: t('usage.rbac_pending_hint') }),
            h('div', { class: 'code-block', text: 'revoap_admin_users · revoap_admin_sessions' }),
        ));
}

function renderLocked(host) {
    clear(host);
    host.appendChild(Alert({
        tone: 'warning', title: t('common.unauthorized'), description: t('admin.unlock_help'),
        actions: [Button({ size: 'sm', iconName: 'unlock', label: t('admin.unlock'),
            onClick: () => window.RevoApShell?.showUnlockDialog() })],
    }));
}

function renderError(host, err, retry) {
    clear(host);
    host.appendChild(Alert({
        tone: 'destructive', title: t('common.error'),
        description: err && err.message ? err.message : String(err),
        actions: [Button({ size: 'sm', iconName: 'refresh', label: t('common.retry'), onClick: () => void retry() })],
    }));
}

function init() {
    const root = document.getElementById(ROOT_ID);
    if (!root) return;
    // Mount on first reveal: the list endpoint is admin-gated and the tab is
    // opened rarely — same policy as the queue panel.
    const mo = new MutationObserver(() => {
        const panel = root.closest('[data-tab-panel]');
        if (panel && !panel.hidden && !root.dataset.revoapMounted) {
            root.dataset.revoapMounted = '1';
            mo.disconnect();
            void mount(root);
        }
    });
    mo.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['hidden'] });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();

export default { mount };
