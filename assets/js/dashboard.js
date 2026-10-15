/**
 * dashboard.js — the landing view.
 *
 * Purely wiring: every number here already has an endpoint that answers
 * (`/api/health`, `/api/health/system`, `/api/health/database`,
 * `/api/health/worker`, `/api/models/availability/summary`). What was missing was
 * a single place to look, so an operator opening the console saw a chat box and
 * had to visit four tabs to learn that the worker was down.
 *
 * Two deliberate constraints:
 *
 *  1. **It uses only PUBLIC endpoints.** A landing page that 403s before the
 *     operator has unlocked the admin area is worse than no landing page — it
 *     reads as broken. Everything here is already visible to any authenticated
 *     user of the console, so the dashboard works pre-unlock and the admin panels
 *     stay gated. `/api/admin/system` is deliberately NOT called.
 *  2. **It answers "is anything wrong?" before "how much did we spend?".** The
 *     verdict list is first, tiles second. A dashboard that leads with pretty
 *     numbers trains people to skim past it.
 *
 * No `innerHTML` with interpolated values anywhere.
 *
 * @project RevoAp   @since 2.0.0
 */
import { h, txt, clear } from './ui/dom.js';
import { icon } from './ui/icons.js';
import {
    Button, Card, Badge, Alert, Stat, EmptyState, Separator,
} from './ui/components.js';
import { t, tn, formatNumber, formatPercent, formatDuration, formatBytes, timeAgo } from './i18n.js';

const ROOT_ID = 'oc-dashboard-root';

/** Slow poll: this is a landing page, not a monitor. The worker chip in the
 *  header already refreshes faster for anyone who needs that. */
const REFRESH_MS = 30000;

let timer = 0;
function stopPolling() {
    if (timer) { clearInterval(timer); timer = 0; }
}

async function get(path) {
    try {
        const res = await fetch(path, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) return null;
        const body = await res.json();
        return (body && typeof body === 'object') ? body : null;
    } catch {
        // A single failing source must not blank the whole dashboard. Each card
        // reports its own absence, which is more useful than one error and also
        // means a half-broken install still shows the half that works.
        return null;
    }
}

export async function mount(root) {
    const host = root || document.getElementById(ROOT_ID);
    if (!host) return;
    stopPolling();

    const render = async () => {
        if (!host.isConnected) { stopPolling(); return; }
        // Parallel: five round-trips in sequence would make the landing page the
        // slowest thing in the console.
        const [health, system, database, worker, availability] = await Promise.all([
            get('/api/health'),
            get('/api/health/system'),
            get('/api/health/database'),
            get('/api/health/worker'),
            get('/api/models/availability/summary'),
        ]);

        clear(host);
        host.classList.add('stack');
        host.appendChild(header(health, system));
        host.appendChild(verdicts(system, database, worker, availability));
        host.appendChild(tiles(health, database, worker, availability));
        host.appendChild(subsystems(health, database, worker));
        if (availability && (availability.models || []).length) host.appendChild(modelPool(availability));
        host.appendChild(shortcuts());
    };

    await render();
    timer = setInterval(() => {
        if (document.hidden || !host.isConnected) { stopPolling(); return; }
        void render();
    }, REFRESH_MS);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopPolling(); });
}

/* ---------------------------------------------------------------- header */

function header(health, system) {
    const overall = (system && system.overall) || 'unknown';
    const tone = overall === 'ok' ? 'success' : overall === 'degraded' ? 'warning' : overall === 'critical' ? 'destructive' : 'muted';
    const version = (health && health.version) || '';

    return Card({
        eyebrow: t('nav.admin'),
        title: t('dashboard.title'),
        description: t('app.description'),
        actions: [
            version ? Badge(`v${version}`, { tone: 'outline' }) : null,
            Badge(t(`admin.health_${overall === 'ok' ? 'ok' : overall === 'critical' ? 'critical' : 'degraded'}`) || overall, { tone }),
            Button({ variant: 'outline', size: 'sm', iconName: 'messageSquare', label: t('dashboard.quick_run'),
                onClick: () => window.RevoApShell?.setTab('chat') }),
            Button({ variant: 'ghost', size: 'sm', iconName: 'refresh', label: t('common.refresh'), onClick: () => mount() }),
        ].filter(Boolean),
    });
}

/* -------------------------------------------------------------- verdicts */

/**
 * "Is anything wrong?" — first thing on the page.
 *
 * Merges the four sources into one ordered list, because an operator should not
 * have to reconcile a green health card with a red worker card in three
 * different tabs.
 */
function verdicts(system, database, worker, availability) {
    const items = [];

    // The system-health endpoint already ranks its own checks; reuse them
    // rather than re-deriving a second opinion that could contradict it.
    for (const check of ((system && system.checks) || [])) {
        const status = String(check.status || check.state || '');
        if (status === 'ok' || status === 'pass' || status === '') continue;
        items.push({
            severity: status === 'critical' || status === 'fail' ? 'critical' : 'warning',
            title: String(check.name || check.label || check.id || t('admin.health')),
            description: String(check.message || check.detail || ''),
        });
    }

    if (database && database.configured && database.connected === false) {
        items.push({ severity: 'critical', title: t('settings.disconnected'), description: t('errors.database_error', { message: '' }) });
    }
    if (database && database.configured && database.schema_ready === false && (database.missing_tables || []).length) {
        items.push({
            severity: 'warning',
            title: tn('settings.migrations_pending', (database.missing_tables || []).length, { count: (database.missing_tables || []).length }),
            description: (database.missing_tables || []).slice(0, 6).join(', '),
        });
    }

    if (worker) {
        if ((worker.workers_alive ?? 0) === 0 && worker.execution_mode !== 'inline') {
            items.push({ severity: 'critical', title: t('queue.no_workers'), description: t('queue.no_workers_hint') });
        }
        if (worker.code_state === 'stale') {
            items.push({ severity: 'warning', title: t('worker.restart_required'), description: String(worker.code_stale_reason || '') });
        }
        const backlog = (worker.counts && (worker.counts.queued ?? worker.counts.pending)) || 0;
        if (backlog > 0 && (worker.workers_alive ?? 0) > 0 && (worker.oldest_queued_age_s ?? 0) > 300) {
            items.push({
                severity: 'warning',
                title: `${t('queue.backlog')}: ${formatNumber(backlog)}`,
                description: t('queue.backlog_help'),
            });
        }
        if ((worker.retry_exhausted ?? 0) > 0) {
            items.push({ severity: 'warning', title: `${t('queue.dead_letter')}: ${formatNumber(worker.retry_exhausted)}`, description: t('queue.dlq_help') });
        }
    }

    if (availability && (availability.unavailable ?? 0) > 0) {
        items.push({
            severity: 'warning',
            title: tn('fallback.report_unfilled', availability.unavailable, { count: availability.unavailable }),
            description: t('fallback.no_alternatives_hint'),
        });
    }

    if (items.length === 0) {
        return Alert({ tone: 'success', title: t('admin.health_ok'), description: t('security.no_recommendations') });
    }

    const order = { critical: 0, warning: 1, info: 2 };
    items.sort((a, b) => (order[a.severity] ?? 9) - (order[b.severity] ?? 9));

    const wrap = h('div', { class: 'stack-sm' });
    for (const item of items.slice(0, 6)) {
        wrap.appendChild(Alert({
            tone: item.severity === 'critical' ? 'destructive' : item.severity === 'warning' ? 'warning' : 'info',
            title: item.title,
            description: item.description || null,
            dismissible: item.severity === 'info',
        }));
    }
    if (items.length > 6) {
        wrap.appendChild(Button({ variant: 'ghost', size: 'sm', iconName: 'chevronDown', label: t('common.show_more'),
            onClick: () => window.RevoApShell?.setTab('system') }));
    }
    return wrap;
}

/* ----------------------------------------------------------------- tiles */

function tiles(health, database, worker, availability) {
    const grid = h('div', { class: 'grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-6' });
    const counts = (worker && worker.counts) || {};

    grid.appendChild(Stat({
        label: t('queue.pending'), value: formatNumber(counts.queued ?? counts.pending ?? 0), icon: 'clock',
        hint: (worker && worker.oldest_queued_age_s != null) ? t('queue.oldest_pending', { age: formatDuration(worker.oldest_queued_age_s) }) : null,
    }));
    grid.appendChild(Stat({ label: t('queue.running'), value: formatNumber(counts.running ?? 0), icon: 'activity' }));
    grid.appendChild(Stat({
        label: t('queue.completed'), value: formatNumber(counts.completed ?? counts.done ?? 0), icon: 'circleCheck',
        hint: (counts.failed ?? 0) > 0 ? `${t('queue.failed')}: ${formatNumber(counts.failed)}` : null,
    }));
    grid.appendChild(Stat({
        label: t('queue.workers'), value: formatNumber((worker && (worker.workers_alive ?? worker.running_workers)) ?? 0), icon: 'cpu',
        hint: worker && worker.latest_heartbeat_age_s != null ? `${t('queue.worker_last_beat')}: ${formatNumber(worker.latest_heartbeat_age_s)} s` : null,
    }));
    grid.appendChild(Stat({
        label: t('models.title'),
        value: availability ? formatNumber((availability.models || []).length) : '—',
        icon: 'cpu',
        hint: availability && (availability.unavailable ?? 0) > 0 ? `${t('models.unavailable')}: ${formatNumber(availability.unavailable)}` : null,
    }));
    grid.appendChild(Stat({
        label: t('admin.subsystem_database'),
        value: database ? (database.connected ? t('settings.connected') : database.configured ? t('settings.disconnected') : t('common.none')) : '—',
        icon: 'database',
        hint: database && database.schema_ready === false ? t('migration_banner.body') : null,
    }));
    return grid;
}

/* ------------------------------------------------------------ subsystems */

function subsystems(health, database, worker) {
    const rows = [];
    const push = (label, ok, detail) => rows.push([label, ok === null ? t('common.unknown') : ok ? t('common.ok') : t('common.failed'), detail || '']);

    push(t('admin.subsystem_database'), database ? database.connected === true : null,
        database && database.configured ? `${(database.present_tables || []).length} ${t('statistic.sessions').toLowerCase()}` : t('settings.database_help'));
    push(t('admin.subsystem_queue'), worker ? (worker.jobs_table_available === true) : null,
        worker ? `${t('queue.total')}: ${formatNumber(worker.total_non_terminal ?? 0)}` : '');
    push(t('admin.subsystem_worker'), worker ? (worker.workers_alive ?? 0) > 0 : null,
        worker && worker.code_state ? t(`queue.worker_code_${worker.code_state}`) : '');
    push(t('admin.subsystem_storage'), database ? database.schema_ready === true : null,
        database && (database.missing_tables || []).length ? (database.missing_tables || []).slice(0, 3).join(', ') : '');

    // The health endpoint's own worker/config blocks, if present.
    if (health && health.config_sources) {
        push(t('system.config_source'), true, Array.isArray(health.config_sources) ? health.config_sources.join(' + ') : String(health.config_sources));
    }

    return Card({ title: t('admin.health'), description: t('admin.overview') },
        rows.length ? definitionGrid(rows) : EmptyState({ iconName: 'activity', title: t('common.empty'), compact: true }));
}

/* ------------------------------------------------------------ model pool */

function modelPool(availability) {
    const models = availability.models || [];
    const body = h('div', { class: 'flex flex-wrap gap-1.5' });
    for (const m of models.slice(0, 24)) {
        body.appendChild(Badge(m.id, {
            tone: ({ healthy: 'success', unknown: 'muted', degraded: 'warning', unavailable: 'destructive', retired: 'secondary' })[m.status] || 'muted',
            dot: true,
            pulse: m.status === 'degraded',
            title: m.reason_label_key ? t(m.reason_label_key) : t(m.label_key || 'models.unknown_status'),
        }));
    }
    if (models.length > 24) {
        body.appendChild(Button({ variant: 'ghost', size: 'sm', label: `+${models.length - 24}`,
            onClick: () => window.RevoApShell?.setTab('models') }));
    }
    return Card({
        title: t('chat.model_pool'),
        description: tn('chat.models_selected', models.length, { count: models.length })
            + (availability.mode ? ` · ${t(availability.mode_label_key || 'fallback.mode')}` : ''),
        actions: [Button({ variant: 'ghost', size: 'sm', iconName: 'shuffle', label: t('fallback.title'),
            onClick: () => window.RevoApShell?.setTab('fallback') })],
    }, body);
}

/* ------------------------------------------------------------- shortcuts */

function shortcuts() {
    const targets = [
        ['chat', 'messageSquare'], ['models', 'cpu'], ['fallback', 'shuffle'],
        ['history', 'history'], ['statistic', 'barChart'], ['queue', 'listTree'],
        ['audit', 'shield'], ['settings', 'settings'], ['help', 'lifeBuoy'],
    ];
    const body = h('div', { class: 'flex flex-wrap gap-1.5' });
    for (const [id, iconName] of targets) {
        const label = t(`nav.${id}`) === `nav.${id}` ? (id === 'fallback' ? t('fallback.title') : id) : t(`nav.${id}`);
        body.appendChild(Button({ variant: 'outline', size: 'sm', iconName, label, onClick: () => window.RevoApShell?.setTab(id) }));
    }
    return Card({
        title: t('dashboard.shortcuts'),
        description: t('command.hint', { key: 'Ctrl K' }),
        actions: [Button({ variant: 'ghost', size: 'sm', iconName: 'command', label: t('nav.command_palette'),
            onClick: () => window.RevoApShell?.openCommandPalette() })],
    }, body);
}

function definitionGrid(pairs) {
    const grid = h('div', { class: 'grid gap-x-6 gap-y-2 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' });
    for (const [label, value, detail] of pairs) {
        if (value === null || value === undefined) continue;
        const cell = h('div', { class: 'row-between gap-3 py-1 border-b border-border' });
        cell.appendChild(h('span', { class: 'xs muted shrink-0', text: label }));
        const right = h('div', { class: 'row gap-2 min-w-0 justify-end' });
        if (detail) right.appendChild(h('span', { class: 'xs muted truncate max-w-[12rem]', text: detail, title: detail }));
        const ok = value === t('common.ok');
        const bad = value === t('common.failed');
        right.appendChild(Badge(value, { tone: ok ? 'success' : bad ? 'destructive' : 'muted' }));
        cell.appendChild(right);
        grid.appendChild(cell);
    }
    return grid;
}

function init() {
    const root = document.getElementById(ROOT_ID);
    if (!root) return;
    const mo = new MutationObserver(() => {
        const panel = root.closest('[data-tab-panel]');
        if (panel && !panel.hidden && !root.dataset.revoapMounted) {
            root.dataset.revoapMounted = '1';
            mo.disconnect();
            void mount(root);
        }
    });
    mo.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['hidden'] });
    // The dashboard is the natural landing tab, so also mount if it is already
    // visible when this module evaluates (a #dashboard deep link).
    const panel = root.closest('[data-tab-panel]');
    if (panel && !panel.hidden) { root.dataset.revoapMounted = '1'; mo.disconnect(); void mount(root); }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();

export default { mount };
