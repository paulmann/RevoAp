/**
 * my-usage.js — the user-facing "My usage" card (v2.1.0, T5).
 *
 * Mounted inside the Statistic tab as a sibling of #oc-statistic-root, so
 * the operator's whole-installation numbers and the current identity's own
 * numbers sit next to each other without statistic.js knowing this file
 * exists.
 *
 * Whose usage is "my"? The endpoint decides, not this panel: an
 * authenticated end user gets its own rows; an operator gets the local
 * console identity (or ?user_id=); an anonymous visitor on a legacy-open
 * install gets the local identity too, because on such an install the
 * console's runs ARE the visitor's runs. On an enforcement-enabled install
 * an anonymous visitor gets identity_required, which this panel renders as
 * a sentence, not as an empty chart.
 *
 * The quota banner shows the SERVER's verdict — the panel never recomputes
 * a budget, because a second implementation of the limit rule is a second
 * answer.
 *
 * No `innerHTML` with interpolated values anywhere.
 *
 * @project RevoAp   @since 2.1.0
 */
import { h, clear } from './ui/dom.js';
import {
    Button, Card, Badge, Alert, Stat, Select, EmptyState, Separator, Progress,
} from './ui/components.js';
import { t, formatNumber, formatPercent, formatDuration } from './i18n.js';

const ROOT_ID = 'oc-myusage-root';

const PERIODS = ['day', 'week', 'quarter', 'year', 'all'];

async function get(path) {
    try {
        const res = await fetch(path, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const body = await res.json();
        if (!res.ok) {
            const err = new Error((body && (body.message || body.error)) || `HTTP ${res.status}`);
            err.status = res.status;
            err.body = body;
            throw err;
        }
        return body;
    } catch (err) {
        err.status = err.status || 0;
        throw err;
    }
}

function money(cost, currency = 'USD') {
    if (cost === null || cost === undefined) return '—';
    const v = Number(cost);
    return `${currency} ${formatNumber(v, Math.abs(v) < 1 ? 4 : 2)}`;
}

export async function mount(root) {
    const host = root || document.getElementById(ROOT_ID);
    if (!host) return;

    const state = { period: 'week' };

    const render = async () => {
        let data;
        try {
            data = await get(`/api/my/usage?period=${encodeURIComponent(state.period)}&days=30`);
        } catch (err) {
            if (!host.isConnected) return;
            clear(host);
            host.appendChild(errorCard(err, render));
            return;
        }
        if (!host.isConnected) return;
        clear(host);
        host.appendChild(card(data, state, render));
    };

    await render();
}

function card(data, state, refresh) {
    const periodSelect = Select(
        PERIODS.map((p) => ({ value: p, label: t(`statistic.period_${p}`) })),
        { value: state.period, class: 'select-sm w-auto', id: 'oc-myusage-period' },
    );
    periodSelect.addEventListener('change', () => { state.period = periodSelect.value; void refresh(); });

    const who = data.user
        ? (data.user.name || data.user.email || data.user.external_id || `#${data.user.id}`)
        : null;

    // Card's own header carries title/description/actions — building a
    // custom head and re-parenting it into the card would fight the
    // component's structure for no gain.
    const shell = Card({
        title: t('usage.my_title'),
        description: t('usage.my_subtitle'),
        actions: [who ? Badge(who, { tone: 'muted' }) : null, periodSelect].filter(Boolean),
    });
    const body = shell.querySelector('[data-slot="card-content"]');
    body.classList.add('stack');

    if (!data.persistent || !data.ledger_migrated) {
        body.appendChild(Alert({ tone: 'warning', title: t('usage.ledger_unmigrated'),
            description: 'db/migrations/024_usage_ledger.sql' }));
        return shell;
    }

    const totals = data.totals;
    if (!totals || (totals.runs === 0 && totals.calls === 0)) {
        body.appendChild(EmptyState({ iconName: 'barChart', title: t('usage.my_no_data'), compact: true }));
        return shell;
    }

    body.appendChild(quotaBanner(data.quota || null));

    body.appendChild(h('div', { class: 'grid gap-3 grid-cols-2 lg:grid-cols-3' },
        Stat({ label: t('usage.runs'), value: formatNumber(totals.runs || 0), icon: 'play',
            hint: (totals.failures || 0) > 0 ? `${t('usage.failed_runs')}: ${formatNumber(totals.failures)}` : null }),
        Stat({ label: t('usage.tokens'), value: formatNumber(totals.total_tokens || 0), icon: 'hash',
            hint: `${t('usage.calls')}: ${formatNumber(totals.calls || 0)}` }),
        Stat({ label: t('usage.cost'), value: money(totals.cost), icon: 'wallet',
            hint: totals.calls > 0 && totals.cost_rows < totals.calls
                ? `${t('usage.coverage')}: ${formatPercent(totals.cost_rows / totals.calls, 0)}` : null }),
        Stat({ label: t('usage.cached'), value: formatNumber(totals.cached_tokens || 0), icon: 'zap' }),
    ));

    const series = data.series || [];
    if (series.length) {
        body.appendChild(Separator());
        body.appendChild(seriesBlock(series));
    }

    const models = data.by_model || [];
    if (models.length) {
        body.appendChild(Separator());
        body.appendChild(modelsBlock(models));
    }

    return shell;
}

function quotaBanner(quota) {
    if (!quota || !quota.checked) {
        return Alert({ tone: 'default', title: t('usage.quota_not_checked') });
    }
    if (quota.action === 'block') {
        const b = quota.blocking || {};
        const resets = Number(b.resets_at || 0) > 0
            ? t('usage.resets_in', { duration: formatDuration(Math.max(0, Number(b.resets_at) - Math.floor(Date.now() / 1000))) })
            : null;
        return Alert({
            tone: 'destructive',
            title: t('usage.quota_block'),
            description: [
                b.name ? `${b.name}: ${t('usage.spent_of', { spent: money(b.spent, b.currency), amount: money(b.amount, b.currency) })}` : null,
                resets,
            ].filter(Boolean).join(' · '),
        });
    }
    if (quota.action === 'warn') {
        const notes = (quota.warnings || []).map((w) => (w === 'throttle_not_enforced'
            ? t('budgets.action_throttle')
            : t('usage.coverage_hint')));
        return Alert({ tone: 'warning', title: t('usage.quota_warn'),
            description: notes.length ? notes[0] : null });
    }
    const budget = (quota.budgets && quota.budgets[0]) || null;
    return Alert({ tone: 'success', title: t('usage.quota_ok'),
        description: budget && budget.amount > 0
            ? t('usage.spent_of', { spent: money(budget.spent, budget.currency), amount: money(budget.amount, budget.currency) })
            : null });
}

function seriesBlock(series) {
    const max = Math.max(1, ...series.map((s) => s.tokens || 0));
    // Progress rows, not a hand-rolled bar chart: the component CSS is in
    // the committed build and no dynamic Tailwind class is needed
    // (REWORK-STATE gotcha #6).
    const list = h('div', { class: 'stack-sm max-h-[18.75rem] overflow-y-auto' });
    for (const s of series) {
        const pct = Math.max(0, Math.min(100, Math.round(((s.tokens || 0) / max) * 100)));
        list.appendChild(h('div', { class: 'row gap-2',
            title: `${s.day}: ${formatNumber(s.tokens || 0)} ${t('usage.tokens').toLowerCase()}${s.cost !== null ? ' · ' + money(s.cost) : ''}` },
            h('span', { class: 'xs muted mono', text: (s.day || '').slice(5) }),
            h('div', { class: 'flex-1 min-w-0' }, Progress(pct, { tone: pct >= 100 ? 'warning' : 'default' })),
            h('span', { class: 'xs mono', text: formatNumber(s.tokens || 0) }),
        ));
    }
    return h('div', { class: 'stack-sm' },
        h('span', { class: 'xs muted', text: t('usage.daily_series') }),
        list,
    );
}

function modelsBlock(models) {
    const el = h('table', { class: 'table table-compact' });
    el.appendChild(h('thead', {}, h('tr', {},
        [t('usage.model'), t('usage.calls'), t('usage.tokens'), t('usage.cost')]
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
            h('td', { class: 'xs mono', text: money(m.cost) }),
        ));
    }
    el.appendChild(tbody);
    return h('div', { class: 'stack-sm' },
        h('span', { class: 'xs muted', text: t('usage.by_model') }),
        h('div', { class: 'table-wrap max-h-[18.75rem] overflow-y-auto' }, el),
    );
}

function errorCard(err, retry) {
    if (err.status === 403 && err.body && err.body.error === 'identity_required') {
        return Card({ title: t('usage.my_title') },
            Alert({ tone: 'warning', title: t('usage.my_identity_required') }));
    }
    return Card({ title: t('usage.my_title') },
        Alert({
            tone: 'destructive', title: t('common.error'),
            description: err.message || String(err),
            actions: [Button({ size: 'sm', iconName: 'refresh', label: t('common.retry'), onClick: () => void retry() })],
        }));
}

function init() {
    const root = document.getElementById(ROOT_ID);
    if (!root) return;
    // Mount on first reveal of the Statistic tab — same lazy policy as the
    // queue and dashboard panels: an endpoint nobody looks at must not be
    // polled by everybody.
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
