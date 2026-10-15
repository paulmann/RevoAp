/**
 * queue.js — the job-queue panel.
 *
 * This panel is almost entirely wiring: the read side already existed in
 * `WorkerHealthService::snapshot()` (44 fields), `WorkerFeedController::jobs()`
 * and `JobsController`. What was missing was a place to look at it, so a dead
 * worker was discovered by a user's run hanging rather than by an operator.
 *
 * Three things it exists to make obvious, each of which is otherwise invisible:
 *
 *   1. **A worker running stale code.** After a deploy the web tier picks up the
 *      new code and the worker keeps running last week's pipeline for hours.
 *      `snapshot.code_state` already says this; the panel says it in words with
 *      the exact restart command.
 *   2. **A growing backlog with a live worker.** That means the worker is too
 *      slow, not that it is down — two different remedies, and the distinction
 *      is the whole diagnosis.
 *   3. **Jobs stuck in retry, and jobs that exhausted their attempts.** The
 *      dead-letter queue does not drain itself, and nothing else in the console
 *      shows it.
 *
 * No `innerHTML` with interpolated values: job payloads contain user prompts.
 *
 * @project RevoAp   @since 2.0.0
 */
import { h, txt, clear, debounce } from './ui/dom.js';
import { icon } from './ui/icons.js';
import {
    Button, Card, Badge, Alert, Stat, Field, Input, Select, Switch,
    Dialog, Confirm, Toast, EmptyState, Separator,
} from './ui/components.js';
import { t, tn, formatNumber, formatPercent, formatDuration, timeAgo, formatDateTime } from './i18n.js';

const ROOT_ID = 'oc-queue-root';

/** Auto-refresh interval. Long enough that a stalled worker is still caught
 *  quickly, short enough not to hammer three endpoints on an open tab. */
const REFRESH_MS = 15000;

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

let timer = 0;

/** Stop polling when the panel is hidden or the tab is backgrounded. A console
 *  left open overnight must not issue 17 000 requests against /api/health. */
function stopPolling() {
    if (timer) { clearInterval(timer); timer = 0; }
}

export async function mount(root) {
    const host = root || document.getElementById(ROOT_ID);
    if (!host) return;
    stopPolling();

    const state = { filter: '', status: '' };

    const render = async () => {
        // The panel may have been navigated away from mid-fetch.
        if (!host.isConnected) { stopPolling(); return; }
        let snap;
        let feed = null;
        try {
            snap = await api('/api/health/worker');
        } catch (err) {
            if (err.status === 403) { renderLocked(host); return; }
            renderError(host, err, render);
            return;
        }
        try { feed = await api('/api/health/worker/jobs?limit=100'); }
        catch { feed = null; }   // a feed failure must not blank the health tiles

        clear(host);
        host.classList.add('stack');
        host.appendChild(alerts(snap));
        host.appendChild(tiles(snap));
        host.appendChild(workersCard(snap));
        host.appendChild(jobsCard(snap, feed, state, render));
        host.appendChild(retryCard(snap));
    };

    await render();

    timer = setInterval(() => {
        if (document.hidden || !host.isConnected) { stopPolling(); return; }
        void render();
    }, REFRESH_MS);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopPolling(); });
}

/* ---------------------------------------------------------------- alerts */

/**
 * The conditions worth a banner rather than a tile. Each is a state where the
 * operator has to DO something, and each names the command.
 */
function alerts(snap) {
    const wrap = h('div', { class: 'stack-sm' });

    if (snap.worker_status === 'down' || (snap.workers_alive ?? 0) === 0) {
        wrap.appendChild(Alert({
            tone: 'destructive',
            title: t('queue.no_workers'),
            description: t('queue.no_workers_hint'),
            actions: [
                Button({ size: 'sm', variant: 'outline', iconName: 'copy', label: t('worker.start_command'),
                    onClick: () => copyAndToast('php bin/revoap-worker.php') }),
            ],
        }));
    }

    if (snap.code_state === 'stale') {
        wrap.appendChild(Alert({
            tone: 'warning',
            title: t('worker.restart_required'),
            description: snap.code_stale_reason
                ? `${snap.code_stale_reason}${snap.code_newest_file ? ` — ${snap.code_newest_file}` : ''}`
                : null,
            actions: [
                Button({ size: 'sm', variant: 'outline', iconName: 'copy', label: t('worker.restart_command'),
                    onClick: () => copyAndToast('sudo systemctl restart revoap-worker') }),
            ],
        }));
    }

    if (!snap.migration_applied || !snap.jobs_table_available) {
        wrap.appendChild(Alert({
            tone: 'warning',
            title: t('migration_banner.pending', { count: 1 }).replace(/\{count\}/g, '1'),
            description: t('migration_banner.body'),
        }));
    }

    const backlog = (snap.counts && (snap.counts.queued ?? snap.counts.pending)) || 0;
    if (backlog > 0 && (snap.workers_alive ?? 0) > 0 && (snap.oldest_queued_age_s ?? 0) > 300) {
        // A backlog with a live worker is a CAPACITY problem, not an outage.
        // Saying "the worker is down" here would send the operator restarting a
        // process that is working as hard as it can.
        wrap.appendChild(Alert({
            tone: 'warning',
            title: t('queue.backlog') + ': ' + formatNumber(backlog),
            description: t('queue.oldest_pending', { age: formatDuration(snap.oldest_queued_age_s || 0) })
                + ' — ' + t('queue.backlog_help'),
        }));
    }

    if (snap.db_connected === false) {
        wrap.appendChild(Alert({
            tone: 'destructive',
            title: t('errors.database_error', { message: snap.last_db_error || '' }),
            description: snap.last_db_error_at ? t('models.last_checked', { ago: timeAgo(snap.last_db_error_at) }) : null,
        }));
    }

    return wrap;
}

/* ----------------------------------------------------------------- tiles */

function tiles(snap) {
    const c = snap.counts || {};
    const grid = h('div', { class: 'grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-6' });
    grid.appendChild(Stat({ label: t('queue.pending'), value: formatNumber(c.queued ?? c.pending ?? 0), icon: 'clock' }));
    grid.appendChild(Stat({ label: t('queue.running'), value: formatNumber(c.running ?? 0), icon: 'activity' }));
    grid.appendChild(Stat({ label: t('queue.completed'), value: formatNumber(c.completed ?? c.done ?? 0), icon: 'circleCheck' }));
    grid.appendChild(Stat({
        label: t('queue.failed'), value: formatNumber(c.failed ?? 0), icon: 'circleX',
        hint: (snap.retry_exhausted ?? 0) > 0 ? `${t('queue.dead_letter')}: ${formatNumber(snap.retry_exhausted)}` : null,
    }));
    grid.appendChild(Stat({
        label: t('queue.workers'), value: formatNumber(snap.workers_alive ?? snap.running_workers ?? 0), icon: 'cpu',
        hint: snap.latest_heartbeat_age_s != null ? t('queue.worker_last_beat') + ': ' + timeAgo(Date.now() / 1000 - snap.latest_heartbeat_age_s) : null,
    }));
    grid.appendChild(Stat({
        label: t('queue.throughput'), value: formatNumber(c.completed ?? c.done ?? 0), icon: 'trendingUp',
        hint: t('queue.throughput_help'),
    }));
    return grid;
}

/* --------------------------------------------------------------- workers */

function workersCard(snap) {
    const presence = snap.worker_presence || [];
    const body = h('div', { class: 'stack-sm' });

    if (presence.length === 0) {
        body.appendChild(EmptyState({
            iconName: 'cpu', title: t('queue.no_workers'), description: t('queue.no_workers_hint'), compact: true,
            action: Button({ size: 'sm', variant: 'outline', iconName: 'copy', label: t('worker.start_command'),
                onClick: () => copyAndToast('php bin/revoap-worker.php') }),
        }));
        return Card({ title: t('queue.workers') }, body);
    }

    const rows = presence.map((w) => [
        String(w.worker_id || '—'),
        w.pid != null ? String(w.pid) : '—',
        String(w.host || '—'),
        w.app_version ? `v${w.app_version}` : '—',
        // The stale-code verdict is the single most useful column here.
        w.code_state === 'current' ? t('queue.worker_code_current')
            : w.code_state === 'stale' ? t('queue.worker_code_stale')
            : t('queue.worker_code_unknown'),
        w.last_seen_at ? timeAgo(w.last_seen_at) : (w.last_seen_age_s != null ? `${formatNumber(w.last_seen_age_s)} s` : '—'),
    ]);

    body.appendChild(table(
        [t('queue.worker_id'), t('queue.worker_pid'), t('queue.worker_host'), t('app.version'), t('queue.worker_code_state'), t('queue.worker_last_beat')],
        rows,
        (row) => (row[4] === t('queue.worker_code_current') ? null : row[4] === t('queue.worker_code_stale') ? 'warning' : 'muted'),
    ));

    if (snap.oldest_worker_uptime_s != null) {
        body.appendChild(h('p', { class: 'xs muted', text: `${t('system.uptime')}: ${formatDuration(snap.oldest_worker_uptime_s)}` }));
    }

    return Card({
        title: t('queue.workers'),
        description: `${formatNumber(snap.workers_alive ?? presence.length)} ${t('common.total').toLowerCase()}`,
        actions: [Button({ variant: 'ghost', size: 'sm', iconName: 'refresh', label: t('common.refresh'), onClick: () => mount() })],
    }, body);
}

/* ------------------------------------------------------------------ jobs */

function jobsCard(snap, feed, state, rerender) {
    const jobs = (feed && (feed.jobs || feed.items || feed.feed)) || [];
    const stale = snap.stale_jobs || [];

    const search = Input({ type: 'search', class: 'input-sm', placeholder: t('common.search_placeholder'), value: state.filter });
    const statusSel = Select([
        { value: '', label: t('common.all') },
        { value: 'queued', label: t('queue.pending') },
        { value: 'running', label: t('queue.running') },
        { value: 'paused', label: t('queue.paused') },
        { value: 'completed', label: t('queue.completed') },
        { value: 'failed', label: t('queue.failed') },
        { value: 'cancelled', label: t('queue.cancelled') },
    ], { value: state.status, class: 'select-sm w-auto' });

    const body = h('div', { class: 'stack-sm' });

    const paint = () => {
        clear(body);
        const q = state.filter.trim().toLowerCase();
        const rows = jobs
            .filter((j) => !state.status || String(j.status || '') === state.status)
            .filter((j) => !q || `${j.id} ${j.session_id ?? ''} ${j.status ?? ''} ${j.query ?? ''}`.toLowerCase().includes(q));

        if (rows.length === 0) {
            body.appendChild(EmptyState({
                iconName: jobs.length ? 'search' : 'listTree',
                title: jobs.length ? t('common.no_results') : t('queue.no_jobs'),
                description: jobs.length ? t('common.no_results_hint') : null,
                compact: true,
            }));
            return;
        }

        const tableRows = rows.map((j) => [
            String(j.id ?? '—'),
            String(j.status ?? '—'),
            String(j.attempts != null ? `${j.attempts}/${j.max_attempts ?? '?'}` : '—'),
            j.created_at ? timeAgo(j.created_at) : (j.created_at_iso ? String(j.created_at_iso) : '—'),
            j.available_at ? timeAgo(j.available_at) : '—',
            j.last_error ? String(j.last_error).slice(0, 90) : '',
        ]);

        const el = table(
            [t('queue.job_id'), t('common.status'), t('queue.job_attempts'), t('common.created'), t('queue.job_available_at'), t('queue.job_error')],
            tableRows,
            (row) => ({ queued: 'info', running: 'info', paused: 'warning', completed: 'success', failed: 'destructive', cancelled: 'muted' })[row[1]] || null,
            (row, index) => jobActions(rows[index], rerender),
        );
        body.appendChild(el);
    };

    search.addEventListener('input', debounce(() => { state.filter = search.value; paint(); }, 200));
    statusSel.addEventListener('change', () => { state.status = statusSel.value; paint(); });
    paint();

    const card = Card({
        title: t('queue.jobs'),
        description: jobs.length ? tn('audit.entries', jobs.length, { count: jobs.length }) : t('queue.no_jobs'),
        actions: [h('div', { class: 'row gap-2 wrap' }, search, statusSel)],
    }, body);

    if (stale.length) {
        card.appendChild(Alert({
            tone: 'warning',
            title: `${t('queue.stale') || 'Stale'}: ${formatNumber(stale.length)}`,
            description: t('queue.dlq_help'),
        }));
    }
    return card;
}

/** Per-job actions. Only rendered for statuses where the action is meaningful. */
function jobActions(job, rerender) {
    const id = job?.id;
    if (id == null) return null;
    const status = String(job.status || '');
    const actions = h('div', { class: 'row gap-1' });

    const act = async (verb, label, danger = false) => {
        if (danger) {
            const ok = await Confirm({ title: label, description: `${t('queue.job_id')} ${id}`, confirmLabel: label, danger: true });
            if (!ok) return;
        }
        try {
            await api(`/api/council/jobs/${encodeURIComponent(id)}/${verb}`, { method: 'POST' });
            Toast.success(label, String(id));
            rerender?.();
        } catch (err) {
            Toast.error(t('common.error'), String(err.message || err));
        }
    };

    if (status === 'running' || status === 'queued') {
        actions.appendChild(Button({ variant: 'ghost', size: 'icon', iconName: 'pause', ariaLabel: t('common.pause'), onClick: () => act('pause', t('common.pause')) }));
    }
    if (status === 'paused') {
        actions.appendChild(Button({ variant: 'ghost', size: 'icon', iconName: 'play', ariaLabel: t('common.resume'), onClick: () => act('resume', t('common.resume')) }));
    }
    if (status === 'failed' || status === 'paused') {
        actions.appendChild(Button({ variant: 'ghost', size: 'icon', iconName: 'rotateCcw', ariaLabel: t('common.retry'), onClick: () => act('resume', t('common.retry')) }));
    }
    if (status !== 'completed' && status !== 'cancelled') {
        actions.appendChild(Button({ variant: 'ghost', size: 'icon', iconName: 'square', ariaLabel: t('common.cancel'), onClick: () => act('cancel', t('common.cancel'), true) }));
    }
    actions.appendChild(Button({
        variant: 'ghost', size: 'icon', iconName: 'eye', ariaLabel: t('queue.timeline'),
        onClick: async () => {
            const dlg = Dialog({ title: `${t('queue.timeline')} · ${id}`, size: 'lg', content: [h('div', { class: 'xs muted', text: t('common.loading') })] });
            try {
                const tl = await api(`/api/council/jobs/${encodeURIComponent(id)}/timeline`);
                clear(dlg.body);
                dlg.body.appendChild(h('div', { class: 'code-block', attrs: { 'data-wrap': 'true' } },
                    txt(JSON.stringify(tl, null, 2))));
            } catch (err) {
                clear(dlg.body);
                dlg.body.appendChild(Alert({ tone: 'destructive', title: t('common.error'), description: String(err.message || err) }));
            }
        },
    }));
    return actions;
}

/* ----------------------------------------------------------- retry policy */

function retryCard(snap) {
    const policy = snap.retry_policy || {};
    const rows = [
        [t('queue.retry_policy'), JSON.stringify(policy)],
        [t('queue.retry_all_failed'), formatNumber(snap.retry_scheduled ?? 0)],
        [t('queue.dead_letter'), formatNumber(snap.retry_exhausted ?? 0)],
        [t('queue.job_available_at'), snap.next_retry_at ? formatDateTime(snap.next_retry_at) : t('common.never')],
        [t('queue.backlog'), snap.oldest_retry_age_s != null ? formatDuration(snap.oldest_retry_age_s) : '—'],
        [t('system.build_id'), snap.app_version ? `v${snap.app_version}` : '—'],
    ];
    return Card({
        title: t('queue.retry_policy'),
        description: t('queue.dlq_help'),
    }, definitionGrid(rows));
}

/* ----------------------------------------------------------------- shared */

function definitionGrid(pairs) {
    const grid = h('div', { class: 'grid gap-x-6 gap-y-2 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' });
    for (const [label, value] of pairs) {
        if (value === null || value === undefined) continue;
        grid.appendChild(h('div', { class: 'row-between gap-3 py-1 border-b border-border' },
            h('span', { class: 'xs muted shrink-0', text: label }),
            h('span', { class: 'text-sm mono truncate text-end', text: String(value), title: String(value) })));
    }
    return grid;
}

/**
 * @param {Array<Array<string>>} rows
 * @param {Function|null} toneFor  row -> badge tone for column 1
 * @param {Function|null} actionsFor row, index -> Node for the trailing cell
 */
function table(headers, rows, toneFor = null, actionsFor = null) {
    const el = h('table', { class: 'table table-compact' });
    el.appendChild(h('thead', {}, h('tr', {}, headers.map((hdr) => h('th', { text: hdr })))));
    const tbody = h('tbody');
    rows.forEach((row, index) => {
        const tr = h('tr');
        row.forEach((cell, i) => {
            const td = h('td', { class: i === 0 ? 'mono xs' : 'xs' });
            const tone = (i === 1 && toneFor) ? toneFor(row) : null;
            if (tone) td.appendChild(Badge(String(cell), { tone }));
            else td.textContent = String(cell ?? '');
            tr.appendChild(td);
        });
        if (actionsFor) {
            const node = actionsFor(row, index);
            tr.appendChild(h('td', { class: 'text-end' }, node || ''));
        }
        tbody.appendChild(tr);
    });
    el.appendChild(tbody);
    return h('div', { class: 'table-wrap max-h-[30rem] overflow-y-auto' }, el);
}

function renderLocked(host) {
    stopPolling();
    clear(host);
    host.appendChild(Alert({
        tone: 'warning', title: t('common.unauthorized'), description: t('admin.unlock_help'),
        actions: [Button({ size: 'sm', iconName: 'unlock', label: t('admin.unlock'),
            onClick: () => window.RevoApShell?.showUnlockDialog() })],
    }));
}

function renderError(host, err, retry) {
    stopPolling();
    clear(host);
    host.appendChild(Alert({
        tone: 'destructive', title: t('common.error'), description: String(err.message || err),
        actions: [Button({ size: 'sm', variant: 'outline', iconName: 'refresh', label: t('common.retry'), onClick: retry })],
    }));
}

async function copyAndToast(text) {
    const { copyText } = await import('./ui/dom.js');
    const ok = await copyText(text);
    if (ok) Toast.success(t('scheduler.copied'), text);
    else Toast.error(t('common.copy_failed'));
}

function init() {
    const root = document.getElementById(ROOT_ID);
    if (!root) return;
    // Mount on first reveal, not on load: this panel polls three endpoints every
    // 15 s, which is a lot of traffic for a tab most operators open once a week.
    const mo = new MutationObserver(() => {
        const p = root.closest('[data-tab-panel]');
        if (p && !p.hidden && !root.dataset.revoapMounted) {
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
