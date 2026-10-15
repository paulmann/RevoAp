// history.js — admin History tab.
//
// 1. Probes /api/history/status (no auth) to decide whether to render
//    the nav tab. Backend gates everything on HistoryTab=true AND the
//    admin token; this client just hides the tab when disabled.
// 2. Lists past sessions via /api/history/list with pagination, search
//    (?q=) and status filter, then renders them grouped by year/month.
// 3. Clicking a session opens /api/history/session/{id} and renders
//    the full pipeline detail (query, final answer, every step, every
//    model response, every event). Every block that holds free text the
//    user may want to keep — the original query (the only one a failed
//    session has), the final answer, each stored model response — carries
//    a pair of copy buttons: "Copy" for the text exactly as shown, and
//    "Copy MD" for the same text under a Markdown heading.
// 4. All free-form text (queries, answers, model output, summaries)
//    is rendered with textContent ONLY — never innerHTML — so even
//    sanitized payloads cannot inject markup. The backend has already
//    scrubbed credentials, but defense-in-depth applies on the client.
// 5. A session that never finished (server died, provider hung, worker
//    timed out) gets a "Finish session" block: a dry-run of
//    POST /api/history/session/{id}/complete?dry_run=1 lists exactly
//    which pipeline stages will be rebuilt, and the real POST performs
//    it. Answers that are already stored are reused — the backend never
//    re-queries a model that already replied.

import { addCopyButtons } from './copy-buttons.js';
import { responseCacheSummary, runCacheSummary, stepCacheRows, stepCacheStats } from './prompt-cache.js';
import { enter, leave, mark, isTracing } from './debug-trace.js';

const HISTORY_TAB  = document.getElementById('oc-tab-history');
const ROOT         = document.getElementById('oc-history-root');
const TAB_BUTTONS  = document.querySelectorAll('[data-tab]');
const TAB_PANELS   = document.querySelectorAll('[data-tab-panel]');

const PAGE_SIZE = 25;
const SEARCH_DEBOUNCE_MS = 250;

const state = {
    status:  { tab_enabled: false, admin_configured: false, admin_unlocked: false, db_available: false, db_migrated: false },
    items:   [],
    groups:  [],
    total:   0,
    offset:  0,
    limit:   PAGE_SIZE,
    q:       '',
    statusFilter: '',
    selected: null,
    detail:  null,
    detailLoading: false,
    listError: null,
    detailError: null,
    searchTimer: null,
};

// "Finish session" state. Scoped to one session id — switching to another
// session wipes it so a stale plan can never be executed against the
// wrong row.
const REPAIR = {
    id:       null,
    checking: false,
    running:  false,
    armed:    false,
    error:    null,
    plan:     null,
    result:   null,
    notice:   null,
};

function resetRepair(id) {
    REPAIR.id = id;
    REPAIR.checking = false;
    REPAIR.running = false;
    REPAIR.armed = false;
    REPAIR.error = null;
    REPAIR.plan = null;
    REPAIR.result = null;
    REPAIR.notice = null;
}


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

function fmtTs(s) {
    if (!s) return '—';
    return String(s).replace('T', ' ').replace(/\..+$/, '');
}

function fmtMs(n) {
    n = Number(n) || 0;
    if (n < 1000) return n + ' ms';
    return (n / 1000).toFixed(1) + ' s';
}

function fmtScore(s) {
    if (s === null || s === undefined || Number.isNaN(Number(s))) return '—';
    return Number(s).toFixed(3);
}

function activateTab(name) {
    TAB_BUTTONS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    TAB_PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
    if (name === 'history') void loadAndRender();
}

TAB_BUTTONS.forEach(t => {
    if (t.dataset.tab === 'history') {
        t.addEventListener('click', () => activateTab('history'));
    }
});

async function probeStatus() {
    const { body } = await api('/api/history/status');
    state.status = body || state.status;
    if (!HISTORY_TAB) return;
    // Tab is shown whenever HistoryTab=true. Admin auth gates the tab
    // body, not the tab button — the operator must see the affordance
    // before unlocking.
    const tabEnabled = state.status.tab_enabled === true;
    HISTORY_TAB.hidden = !tabEnabled;
}

async function loadAndRender() {
    if (!ROOT) return;
    // History is publicly viewable (v1.8.6): the only gate is the
    // HistoryTab flag in config.php; admin unlock is no longer required.
    if (!state.status.tab_enabled) { renderDisabled('History tab is disabled in config.php (HistoryTab=false).'); return; }
    await loadList();
}

async function loadList() {
    state.listError = null;
    const params = new URLSearchParams({
        limit:  String(state.limit),
        offset: String(state.offset),
    });
    if (state.q) params.set('q', state.q);
    if (state.statusFilter) params.set('status', state.statusFilter);
    const { ok, body, status } = await api('/api/history/list?' + params.toString());
    if (status === 404 && body?.error === 'history_disabled') { renderDisabled('History tab is disabled in config.php (HistoryTab=false).'); return; }
    if (!ok || body?.ok === false) {
        const msg = body?.message || ('Failed to load history (HTTP ' + status + ')');
        if (body?.error === 'no_database' || body?.error === 'migration_required') {
            renderDisabled(msg);
            return;
        }
        state.listError = msg;
    }
    if (body?.ok) {
        state.items  = Array.isArray(body.items)  ? body.items  : [];
        state.groups = Array.isArray(body.groups) ? body.groups : [];
        state.total  = Number(body.total) || 0;
        state.limit  = Number(body.limit) || PAGE_SIZE;
        state.offset = Number(body.offset) || 0;
    }
    renderShell();
}

function renderDisabled(msg) {
    clear(ROOT);
    ROOT.append(el('div', { class: 'oc-alert oc-alert-warn', text: msg }));
}

function renderShell() {
    enter('history:renderShell', { steps: state.items.length, hasDetail: !!state.detail });
    clear(ROOT);
    const layout = el('div', { class: 'oc-history' });
    layout.append(renderListPane(), renderDetailPane());
    ROOT.append(layout);
    leave('history:renderShell');
}

function renderListPane() {
    const pane = el('div', { class: 'oc-history-list-pane' });

    const toolbar = el('div', { class: 'oc-history-toolbar' });
    const search = el('input', {
        id: 'oc-history-search',
        type: 'search', class: 'oc-input',
        placeholder: 'Search query / answer / skill…',
        value: state.q,
        oninput: (ev) => {
            const v = String(ev.target.value || '');
            if (state.searchTimer) clearTimeout(state.searchTimer);
            state.searchTimer = setTimeout(() => {
                state.q = v;
                state.offset = 0;
                void loadList();
            }, SEARCH_DEBOUNCE_MS);
        },
    });
    toolbar.append(search);

    const filter = el('select', {
        id: 'oc-history-status',
        class: 'oc-input',
        onchange: (ev) => {
            state.statusFilter = String(ev.target.value || '');
            state.offset = 0;
            void loadList();
        },
    });
    [
        ['',          'All statuses'],
        ['completed', 'Completed'],
        ['running',   'Running'],
        ['failed',    'Failed'],
    ].forEach(([v, label]) => {
        const opt = el('option', { value: v, text: label });
        if (state.statusFilter === v) opt.selected = true;
        filter.append(opt);
    });
    toolbar.append(filter);
    pane.append(toolbar);

    if (state.listError) {
        pane.append(el('div', { class: 'oc-alert oc-alert-error', text: state.listError }));
    }

    const list = el('div', { class: 'oc-history-list' });
    if (state.items.length === 0) {
        list.append(el('div', { class: 'oc-history-empty', text:
            state.q || state.statusFilter ? 'No sessions match this filter.' : 'No sessions yet.' }));
    } else {
        let lastBucket = '';
        for (const item of state.items) {
            const bucket = (item.bucket || '').trim();
            if (bucket && bucket !== lastBucket) {
                lastBucket = bucket;
                const groupMeta = state.groups.find(g => g.key === bucket);
                const label = groupMeta ? (groupMeta.label + ' (' + groupMeta.count + ')') : bucket;
                list.append(el('div', { class: 'oc-history-group', text: label }));
            }
            list.append(renderListItem(item));
        }
    }
    pane.append(list);

    pane.append(renderPagination());
    return pane;
}

function renderListItem(item) {
    const node = el('div', {
        class: 'oc-history-item' + (state.selected === item.id ? ' oc-history-item-active' : ''),
        onclick: () => {
            enter('history:click', { id: item.id, status: item.status });
            mark('history:click', { id: item.id, status: item.status });
            state.selected = item.id;
            // loadDetail paints the loading state itself, synchronously,
            // before its first await - a renderShell() here was a second,
            // identical full repaint of both panes on every single click.
            void loadDetail(item.id);
        },
    });

    const head = el('div', { class: 'oc-history-item-head' });
    head.append(el('span', { text: '#' + item.id + ' · ' + fmtTs(item.created_at) }));
    // A batch run waiting on the provider is neither finished nor failed, and
    // the session column cannot express that: `council_sessions.status` only
    // knows running/completed/failed, and a batch session is genuinely
    // `running` for as long as the batch is outstanding. The pill therefore
    // comes from the derived run mode (see HistoryService::withBatchState),
    // so "running" never has to mean "possibly stalled".
    const batch = item.batch || null;
    const awaitingBatch = !!(batch && batch.awaiting === true);
    const statusClass = awaitingBatch
        ? 'oc-history-status oc-history-status-batch'
        : 'oc-history-status oc-history-status-' + (item.status || 'unknown');
    head.append(el('span', {
        class: statusClass,
        text: awaitingBatch ? 'awaiting batch' : (item.status || '?'),
        title: awaitingBatch ? batchStatusTitle(batch) : '',
    }));
    node.append(head);

    node.append(el('div', { class: 'oc-history-item-query', text: item.query_preview || '(empty query)' }));

    const meta = el('div', { class: 'oc-history-item-meta' });
    if (item.skill_name || item.skill_slug) {
        meta.append(el('span', { text: 'skill: ' + (item.skill_name || item.skill_slug) }));
    }
    if (item.consensus_status) {
        meta.append(el('span', { text: 'consensus: ' + item.consensus_status + ' ' + fmtScore(item.consensus_score) }));
    }
    if (item.total_tokens) {
        meta.append(el('span', { text: 'tokens: ' + item.total_tokens }));
    }
    if (item.duration_ms) {
        meta.append(el('span', { text: 'duration: ' + fmtMs(item.duration_ms) }));
    }
    if (item.files) {
        meta.append(el('span', { text: 'files: ' + item.files }));
    }
    if (item.model_count) {
        meta.append(el('span', { text: 'models: ' + item.model_count }));
    }
    if (item.reasoning) {
        meta.append(el('span', { text: 'reasoning: ' + item.reasoning }));
    }
    if (batch) {
        meta.append(el('span', { text: 'batch: ' + batchMetaText(batch) }));
    }
    node.append(meta);

    return node;
}

/**
 * The batch readout for one History row. Reads like the Chat card on
 * purpose: phase, provider status, request count, elapsed time and the
 * 24-hour window, so the two screens cannot describe one run differently.
 */
function batchMetaText(batch) {
    const phase = String(batch.phase_label || batch.phase || 'queued');
    const status = String(batch.status_label || '');
    const parts = [status !== '' ? phase + ' · ' + status : phase];
    const requests = Number(batch.request_count || 0);
    if (requests > 0) parts.push(requests + ' request' + (requests === 1 ? '' : 's'));
    const elapsed = batchElapsed(batch.submitted_at);
    if (elapsed !== '') parts.push('submitted ' + elapsed + ' ago');
    if (batch.awaiting === true) parts.push('up to ' + Number(batch.completion_window_h || 24) + ' h');
    return parts.join(' — ');
}

function batchStatusTitle(batch) {
    return 'This session is waiting on the provider Batch API. '
        + 'Results arrive asynchronously — usually minutes, up to 24 hours — and the consensus stages '
        + 'run as soon as they do. ' + batchMetaText(batch);
}

/** "just now" / "7 min" / "2 h 05 min" from an ISO-8601 UTC stamp. */
function batchElapsed(submittedAt) {
    if (!submittedAt) return '';
    const t = Date.parse(String(submittedAt));
    if (!Number.isFinite(t)) return '';
    const secs = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (secs < 60) return 'just now';
    const mins = Math.floor(secs / 60);
    if (mins < 60) return mins + ' min';
    return Math.floor(mins / 60) + ' h ' + String(mins % 60).padStart(2, '0') + ' min';
}

function renderPagination() {
    const wrap = el('div', { class: 'oc-history-pagination' });
    const prev = el('button', {
        type: 'button', class: 'oc-button',
        text: '← Prev',
        disabled: state.offset <= 0,
        onclick: () => {
            state.offset = Math.max(0, state.offset - state.limit);
            void loadList();
        },
    });
    const nextDisabled = state.offset + state.items.length >= state.total;
    const next = el('button', {
        type: 'button', class: 'oc-button',
        text: 'Next →',
        disabled: nextDisabled,
        onclick: () => {
            state.offset = state.offset + state.limit;
            void loadList();
        },
    });
    const info = el('span', {
        text: state.total === 0
            ? '0 sessions'
            : ((state.offset + 1) + '–' + Math.min(state.total, state.offset + state.items.length) + ' of ' + state.total),
    });
    wrap.append(prev, info, next);
    return wrap;
}

function renderDetailPane() {
    const pane = el('div', { class: 'oc-history-detail' });
    if (state.detailError) {
        pane.append(el('div', { class: 'oc-alert oc-alert-error', text: state.detailError }));
        return pane;
    }
    if (state.detailLoading) {
        pane.append(el('div', { class: 'oc-history-empty', text: 'Loading…' }));
        return pane;
    }
    if (!state.detail) {
        pane.append(el('div', { class: 'oc-history-empty',
            text: state.items.length > 0 ? 'Pick a session to view its full pipeline.' : 'No session selected.' }));
        return pane;
    }
    if (REPAIR.notice) {
        pane.append(el('div', { class: 'oc-alert oc-alert-ok', text: REPAIR.notice }));
    }
    enter('history:renderDetailInto', {
        id: state.detail?.session?.id,
        steps: Array.isArray(state.detail?.steps) ? state.detail.steps.length : -1,
        events: Array.isArray(state.detail?.events) ? state.detail.events.length : -1,
    });
    renderDetailInto(pane, state.detail);
    leave('history:renderDetailInto');
    return pane;
}

// A session counts as unfinished when the row was never flipped to
// `completed`, when no final answer was ever persisted, or when the journal
// says the synthesis itself failed.
//
// The row alone is not enough. Before v1.8.48 a run whose L4 fell back to
// the local apology body was still stamped `completed` with that body as its
// final answer, so the row looks perfectly finished while the session holds
// no synthesis at all — and the only route to a real answer is a re-run of
// L4. Those rows are exactly what the operator is looking at, so the panel
// has to key off the journal too, not off the status column.
//
// It mirrors the server-side planner on purpose: a failed L4 counts only
// when no completed L4 step exists, so a session that failed over from one
// synthesis model to another and then succeeded is not reported as broken.
function isUnfinishedSession(session, detail) {
    if (!session) return false;
    if ((session.status || '') !== 'completed' || !session.final_answer) return true;
    return hasFailedSynthesisStep(detail);
}

function hasFailedSynthesisStep(detail) {
    const steps = detail && Array.isArray(detail.steps) ? detail.steps : [];
    let failed = false;
    for (const step of steps) {
        const layer = (step && step.meta && step.meta.layer)
            ? String(step.meta.layer)
            : String((step && step.step_key) || '').split('.')[0];
        if (String(layer).toUpperCase() !== 'L4') continue;
        const status = String((step && step.status) || '');
        if (status === 'completed' && String((step && step.summary) || '').trim() !== '') return false;
        if (status === 'failed') failed = true;
    }
    return failed;
}


/**
 * R2 - why this run ended the way it did, and what to press next.
 *
 * Built from the diagnosis the detail payload already carries: no second
 * request, and nothing recomputed in the browser. The actions name controls
 * that already exist (the History re-run button, Settings -> Migrations,
 * Actualize) rather than inventing a second way to do the same thing - and a
 * step that needs confirmation says so in the text, so nobody is surprised by
 * a dialog.
 */
/**
 * R2 - why this run ended the way it did, and what to press next.
 *
 * Built from the diagnosis the detail payload already carries: no second
 * request, and nothing recomputed in the browser.
 *
 * Actions come in three kinds, and the difference is deliberate:
 *   - `endpoint` - a button calling an endpoint that already exists. Anything
 *     that changes state asks for a second click first, so a stray press cannot
 *     apply migrations or rebuild a run;
 *   - `navigate` - a button that opens the screen where the operator finds the
 *     control (the Settings tab), because pointing at a place beats describing
 *     it;
 *   - `command` - text, with the command shown. Restarting a service is the
 *     operator's own act: the application does not run `systemctl` for anyone.
 *
 * After an action the detail is reloaded, so what the operator sees next is the
 * new state rather than an optimistic message.
 */
function diagnosisBlock(diagnosis, sessionId) {
    if (!diagnosis || typeof diagnosis !== 'object' || typeof diagnosis.headline !== 'string') {
        return null;
    }
    const box = el('div', {
        class: 'oc-history-diagnosis oc-history-diagnosis-' + String(diagnosis.severity || 'ok'),
        dataset: { cause: String(diagnosis.cause || '') },
    }, [
        el('p', { class: 'oc-history-diagnosis-head', text: diagnosis.headline }),
    ]);

    const detail = typeof diagnosis.detail === 'string' ? diagnosis.detail : '';
    if (detail !== '') {
        box.append(el('p', { class: 'oc-muted oc-small', text: detail }));
    }

    const result = el('p', { class: 'oc-history-diagnosis-result oc-small', text: '' });
    const actions = Array.isArray(diagnosis.actions) ? diagnosis.actions : [];
    const list = el('div', { class: 'oc-history-diagnosis-actions' });

    for (const action of actions) {
        const label = String(action.label || '');
        const kind = String(action.kind || '');
        const target = typeof action.target === 'string' ? action.target : '';

        if (kind === 'endpoint' && target !== '' && typeof fetch === 'function') {
            let armed = action.needs_confirmation !== true;
            const button = el('button', {
                type: 'button',
                class: 'oc-button oc-small',
                text: armed ? label : label + ' - confirm',
            });
            button.addEventListener('click', () => {
                if (!armed) {
                    armed = true;
                    button.textContent = label + ' - confirm';
                    button.dataset.armed = '1';

                    return;
                }
                const path = target.split('{id}').join(encodeURIComponent(String(sessionId || '')));
                button.disabled = true;
                result.textContent = label + ': working…';
                void api(path, { method: String(action.method || 'POST') })
                    .then((res) => {
                        const ok = res.ok === true && (res.body.ok === undefined || res.body.ok === true);
                        result.textContent = ok
                            ? label + ': done. Reloading…'
                            : label + ': ' + String(res.body.error || res.body.message || ('HTTP ' + res.status));
                        void loadDetail(sessionId);
                    })
                    .catch(() => {
                        button.disabled = false;
                        result.textContent = label + ': the request did not go through.';
                    });
            });
            list.append(el('div', { class: 'oc-history-diagnosis-action' }, [button]));
            continue;
        }

        if (kind === 'navigate') {
            list.append(el('div', { class: 'oc-history-diagnosis-action' }, [
                el('button', {
                    type: 'button',
                    class: 'oc-button oc-small',
                    text: label,
                    onclick: () => {
                        const tab = document.getElementById('oc-tab-settings');
                        if (tab) {
                            tab.hidden = false;
                            tab.click();
                        }
                    },
                }),
            ]));
            continue;
        }

        list.append(el('div', { class: 'oc-history-diagnosis-action' }, [
            el('span', {
                class: 'oc-muted oc-small',
                text: label + (target !== '' ? ': ' + target : '')
                    + (action.needs_confirmation === true ? ' (asks for confirmation)' : ''),
            }),
        ]));
    }

    if (actions.length > 0) {
        box.append(list);
        box.append(result);
    }

    return box;
}

/**
 * R8 - "keep this run". The server writes the run out to share/ next to
 * index.php: the Markdown document, and the same content as a print-ready
 * page (that page is how a PDF is produced here - open it, print, save as
 * PDF). This only asks and then reports the files that actually landed, with
 * their real sizes.
 *
 * The endpoint states its own failures - an unwritable share/ directory, a
 * missing database, a run that is not there - so those arrive as text and
 * never as an empty panel that looks like success.
 */
function renderExportSection(session) {
    const box    = el('div', { class: 'oc-history-export' });
    const button = el('button', {
        type: 'button',
        class: 'oc-button oc-small',
        text: 'Export this run',
        title: 'Write the run to share/ on the server: a Markdown document, and a print-ready copy (print it to get a PDF)',
    });
    const out = el('div', { class: 'oc-history-export-result oc-small' });

    button.addEventListener('click', () => {
        button.disabled = true;
        out.textContent = 'Exporting...';
        void api('/api/history/session/' + encodeURIComponent(String(session.id)) + '/export', { method: 'POST' })
            .then((res) => {
                const body = res.body || {};
                if (res.ok !== true || body.ok !== true) {
                    button.disabled = false;
                    out.textContent = 'Export failed: '
                        + String(body.message || body.error || ('HTTP ' + res.status));
                    return;
                }
                const files = Array.isArray(body.files) ? body.files : [];
                clear(out);
                out.append(el('span', { text: 'Written to share/: ' }));
                files.forEach((file, index) => {
                    if (index > 0) out.append(el('span', { text: '·' }));
                    out.append(el('a', {
                        class: 'oc-history-export-link',
                        href: String(file.url || ''),
                        target: '_blank',
                        rel: 'noopener',
                        text: (file.kind === 'print' ? 'print copy (PDF)' : 'Markdown')
                            + ' - ' + fmtBytes(file.bytes),
                    }));
                });
                if (body.print_hint) {
                    out.append(el('div', { class: 'oc-muted', text: String(body.print_hint) }));
                }
                button.textContent = 'Export again';
                button.disabled = false;
            })
            .catch(() => {
                button.disabled = false;
                out.textContent = 'Export: the request did not go through.';
            });
    });

    box.append(button, out);

    return box;
}

/** Byte sizes as an operator reads them. */
function fmtBytes(n) {
    const v = Number(n);
    if (!isFinite(v) || v <= 0) { return '0 B'; }
    if (v < 1024) { return v + ' B'; }
    if (v < 1024 * 1024) { return (v / 1024).toFixed(1) + ' KB'; }

    return (v / (1024 * 1024)).toFixed(1) + ' MB';
}

function renderDetailInto(pane, detail) {
    // R2 - the diagnosis first: it is the answer to "what happened?", and the
    // steps below are the evidence for it.
    const diagnosis = diagnosisBlock(detail.diagnosis, detail.session ? detail.session.id : null);
    if (diagnosis) { pane.append(diagnosis); }
    const s = detail.session || {};

    pane.append(el('h2', { text: 'Session #' + s.id }));

    const meta = el('div', { class: 'oc-history-detail-meta' });
    meta.append(el('span', { text: 'started: ' + fmtTs(s.created_at) }));
    if (s.completed_at) meta.append(el('span', { text: 'completed: ' + fmtTs(s.completed_at) }));
    // Same derived rule as the list pill: a batch session waiting on the
    // provider reads "awaiting batch", not "running". `s.batch` is attached
    // by HistoryService::withBatchState when the session has a batch job.
    const detailAwaitingBatch = !!(s.batch && s.batch.awaiting === true);
    meta.append(el('span', {
        class: detailAwaitingBatch
            ? 'oc-history-status oc-history-status-batch'
            : 'oc-history-status oc-history-status-' + (s.status || 'unknown'),
        text: detailAwaitingBatch ? 'awaiting batch' : (s.status || '?'),
        title: detailAwaitingBatch ? batchStatusTitle(s.batch) : '',
    }));
    if (s.batch) {
        meta.append(el('span', { text: 'batch: ' + batchMetaText(s.batch) }));
    }
    if (s.consensus_status) {
        meta.append(el('span', { text: 'consensus: ' + s.consensus_status + ' ' + fmtScore(s.consensus_score) }));
    }
    meta.append(el('span', { text: 'tokens: ' + (s.total_tokens || 0) }));
    // Prompt-cache roll-up for the run: how many calls hit the cache, how
    // many tokens were read and written, and what was saved. `s.cache` is
    // derived by HistoryService::cacheRollup from the per-step meta, so it
    // is present for older runs too — they report "no data" rather than
    // zeroes, which is the truth for a run whose provider returned no
    // cache block.
    if (s.cache) {
        meta.append(el('span', { text: runCacheSummary(s.cache) }));
    }
    meta.append(el('span', { text: 'duration: ' + fmtMs(s.duration_ms) }));
    if (s.skill_name || s.skill_slug) {
        meta.append(el('span', { text: 'skill: ' + (s.skill_name || '') + ' (' + (s.skill_slug || '—') + ')' }));
    }
    pane.append(meta);
    // R8 - the run can be kept as a file (see renderExportSection).
    pane.append(renderExportSection(s));

    // A row with no stored answers at all - the placeholder a models_actualize
    // job leaves behind - has nothing to rebuild: the server answers 409
    // "nothing_to_repair" and the browser still paints the failed request as an
    // error, which is pure noise on every click. Predict it locally and do not
    // offer an affordance that cannot work.
    const storedSteps = Array.isArray(detail?.steps) ? detail.steps : [];
    if (isUnfinishedSession(s, detail) && storedSteps.length > 0) {
        pane.append(renderRepairSection(s));
    }

    // The query is the one block a session always has, so it gets the copy
    // pair too — in a failed session (see renderRepairSection above) it is
    // the only thing there is to copy. The heading and the buttons share a
    // flex row so the h3 keeps its existing size and margins.
    const queryText  = typeof s.query_text === 'string' ? s.query_text : '';
    const queryBlock = el('div', { class: 'oc-history-block', text: queryText || '(empty)' });
    const queryHead  = el('div', { class: 'oc-history-answer-head' }, [
        el('h3', { text: 'Original query' }),
    ]);
    addCopyButtons(queryHead, {
        text: queryText,
        source: queryBlock,
        heading: 'Original query',
        level: 2,
        label: 'Copy the original query to clipboard, exactly as shown',
        mdLabel: 'Copy the original query as a Markdown snippet (## heading added, text unchanged)',
    });
    pane.append(queryHead, queryBlock);

    // v1.9.x - the documents the run read. Names travel in the session
    // metadata (options_json.attachments); contents are never persisted.
    const fileNames = (s.options && Array.isArray(s.options.attachments)) ? s.options.attachments : [];
    if (fileNames.length > 0) {
        pane.append(el('h3', { text: 'Attached files' }));
        const filesBlock = el('div', { class: 'oc-history-block' });
        for (const name of fileNames) {
            filesBlock.append(el('div', { text: ' - ' + String(name) }));
        }
        pane.append(filesBlock);
    }

    if (s.options) {
        pane.append(el('h3', { text: 'Runtime options' }));
        const opts = s.options;
        const optsBlock = el('div', { class: 'oc-history-block' });
        const lines = [];
        if (opts.reasoning) lines.push('reasoning: ' + opts.reasoning);
        if (typeof opts.parallel_l1 === 'boolean') lines.push('parallel_l1: ' + opts.parallel_l1);
        if (Array.isArray(opts.selected_models)) {
            lines.push('selected_models:');
            for (const m of opts.selected_models) {
                lines.push('  - ' + (m.provider || '?') + ' / ' + (m.model || '?'));
            }
        }
        optsBlock.textContent = lines.join('\n') || '(empty)';
        pane.append(optsBlock);
    }

    // Final answer gets the copy pair. The heading and the buttons share a
    // flex row so the h3 keeps its existing size and margins; the copied
    // value is s.final_answer itself, never the heading or the meta strip.
    //
    // The extra class is what lifts the shared block's 360px scrolling
    // clamp (see .oc-history-final-answer in components/history.css): the
    // answer is the one block an operator reads end to end, so it is
    // rendered at its natural height on its own green plaque. Every other
    // `.oc-history-block` on this pane keeps the clamp.
    const finalAnswer   = s.final_answer || '';
    const finalAnswerEl = el('div', {
        class: 'oc-history-block oc-history-final-answer',
        text: finalAnswer || '(no final answer)',
    });
    const finalAnswerHead = el('div', { class: 'oc-history-answer-head' }, [
        el('h3', { text: 'Final answer' }),
    ]);
    addCopyButtons(finalAnswerHead, {
        text: finalAnswer,
        source: finalAnswerEl,
        heading: 'Final answer',
        level: 1,
        label: 'Copy the final answer to clipboard, exactly as shown',
        mdLabel: 'Copy the final answer as a Markdown snippet (# heading added, text unchanged)',
    });
    pane.append(finalAnswerHead, finalAnswerEl);

    pane.append(el('h3', { text: 'Pipeline steps' }));
    const steps = Array.isArray(detail.steps) ? detail.steps : [];
    if (steps.length === 0) {
        pane.append(el('div', { class: 'oc-history-empty', text: 'No steps recorded.' }));
    } else {
        for (const step of steps) {
            pane.append(renderStep(step));
        }
    }

    const events = Array.isArray(detail.events) ? detail.events : [];
    if (events.length > 0) {
        pane.append(el('h3', { text: 'Events (' + events.length + ')' }));
        const evWrap = el('div', { class: 'oc-history-events' });
        for (const e of events) {
            const row = el('div', { class: 'oc-history-event' });
            row.append(el('span', { class: 'oc-history-event-type', text: e.event_type || '' }));
            const payloadStr = e.payload ? JSON.stringify(e.payload) : '';
            row.append(el('span', { text: payloadStr.length > 240 ? payloadStr.slice(0, 240) + '…' : payloadStr }));
            evWrap.append(row);
        }
        pane.append(evWrap);
    }
}

function renderStep(step) {
    const node = el('div', { class: 'oc-history-step' });

    const head = el('div', { class: 'oc-history-step-head' });
    head.append(el('span', { class: 'oc-history-step-key',
        text: (step.step_key || '') + ' · ' + (step.label || '') }));
    head.append(el('span', { class: 'oc-history-step-meta',
        text: (step.status || '') + ' · ' + fmtTs(step.started_at) + (step.ended_at ? ' → ' + fmtTs(step.ended_at) : '') }));
    node.append(head);

    if (step.summary) {
        node.append(el('div', { class: 'oc-history-block', text: step.summary }));
    }

    // Per-stage cache figures, straight from the step's meta — the same
    // numbers the Chat card shows for that stage. Rendered only when the
    // step's provider actually reported cache statistics: a stage is not a
    // cache miss just because nobody told us about the cache, and a stage
    // that made no provider call (L2/L3) has nothing to say at all. Reuses
    // the Chat per-step list styling, so no new CSS is involved.
    if (stepCacheStats(step.meta).reported) {
        const cacheDl = el('dl', { class: 'oc-step-stats' });
        for (const [k, v] of stepCacheRows(step.meta)) {
            cacheDl.append(el('div', {}, [el('dt', {}, [k]), el('dd', {}, [String(v)])]));
        }
        node.append(cacheDl);
    }

    const responses = Array.isArray(step.responses) ? step.responses : [];
    if (responses.length > 0) {
        node.append(el('h4', { text: 'Model responses (' + responses.length + ')' }));
        // Heading for the Markdown snippet of each stored answer: the step
        // the response belongs to, so a pasted answer still says which
        // stage of the pipeline produced it.
        const stepHeading = [step.step_key, step.label].filter(Boolean).join(' · ') || 'Pipeline step';
        for (const r of responses) {
            const wrap = el('div', { class: 'oc-history-response' });
            const rh = el('div', { class: 'oc-history-response-head' });
            const identity = (r.provider || '?') + ' / ' + (r.model || '?') + (r.role_name ? ' · ' + r.role_name : '');
            const tokens = (r.prompt_tokens || 0) + ' + ' + (r.completion_tokens || 0) + ' = ' + (r.total_tokens || 0);
            // Cache figures for this stored call, from the typed columns on
            // council_model_responses. Empty string when the provider
            // reported nothing, so a response without cache data renders
            // exactly the row it always rendered.
            const cacheText = responseCacheSummary(r);
            rh.append(el('span', { text: identity }));
            rh.append(el('span', {
                text: tokens + ' tok · ' + fmtMs(r.latency_ms) + (r.tokens_estimated ? ' (est.)' : '')
                    + (cacheText ? ' · ' + cacheText : ''),
            }));
            // One button pair per stored model answer. The copied value is
            // raw_content — the model's own text — not the provider/model
            // attribution or the token counts that sit in the same row;
            // those are only ever added, as a metadata line, to the
            // Markdown snippet.
            const responseBlock = el('div', { class: 'oc-history-block', text: r.raw_content || '(empty)' });
            addCopyButtons(rh, {
                text: r.raw_content,
                source: responseBlock,
                heading: stepHeading,
                level: 3,
                meta: identity + ' · ' + tokens + ' tok · ' + fmtMs(r.latency_ms) + (r.tokens_estimated ? ' (est.)' : ''),
                label: 'Copy this model answer to clipboard, exactly as shown',
                mdLabel: 'Copy this model answer as a Markdown snippet (step heading added, text unchanged)',
            });
            wrap.append(rh);
            wrap.append(responseBlock);
            node.append(wrap);
        }
    }

    if (step.meta && Object.keys(step.meta).length > 0) {
        const metaWrap = el('details');
        metaWrap.append(el('summary', { text: 'Step meta' }));
        const metaBlock = el('div', { class: 'oc-history-block' });
        try { metaBlock.textContent = JSON.stringify(step.meta, null, 2); }
        catch { metaBlock.textContent = String(step.meta); }
        metaWrap.append(metaBlock);
        node.append(metaWrap);
    }

    return node;
}

async function loadDetail(id) {
    enter('history:loadDetail', { id });
    if (REPAIR.id !== id) resetRepair(null);
    state.detail = null;
    state.detailError = null;
    state.detailLoading = true;
    renderShell();
    const { ok, body, status } = await api('/api/history/session/' + encodeURIComponent(String(id)));
    state.detailLoading = false;
    if (status === 404) { state.detailError = 'Session not found (it may have been deleted).'; renderShell(); return; }
    if (!ok || body?.ok === false) {
        state.detailError = body?.message || ('Failed to load session (HTTP ' + status + ')');
        renderShell();
        return;
    }
    state.detail = body;
    mark('history:detailFetched', {
        id,
        bytes: isTracing() ? JSON.stringify(body || {}).length : 0,
        steps: Array.isArray(body?.steps) ? body.steps.length : -1,
        events: Array.isArray(body?.events) ? body.events.length : -1,
        unfinished: isUnfinishedSession(body?.session, body),
    });
    state.detail = body;
    renderShell();
    leave('history:loadDetail');
}

// ---------------------------------------------------------------------
// "Finish session" — plan preview + execution
// ---------------------------------------------------------------------

const REPAIR_ENDPOINT = (id) => '/api/history/session/' + encodeURIComponent(String(id)) + '/complete';

// Kick off the dry-run the first time an unfinished session is rendered.
// The fetch is deferred to the next tick so it never runs inside a render.
function ensureRepairPlan(id) {
    mark('history:ensureRepairPlan', { id, same: REPAIR.id === id });
    if (REPAIR.id === id) return;
    resetRepair(id);
    REPAIR.checking = true;
    REPAIR.notice = null;
    setTimeout(() => { void loadRepairPlan(id); }, 0);
}

async function loadRepairPlan(id) {
    enter('history:loadRepairPlan', { id });
    REPAIR.checking = true;
    REPAIR.error = null;
    renderShell();
    const { ok, body, status } = await api(REPAIR_ENDPOINT(id) + '?dry_run=1', { method: 'POST' });
    if (REPAIR.id !== id) return; // user moved on while the request was in flight
    REPAIR.checking = false;
    if (!ok || body?.ok === false) {
        REPAIR.plan = null;
        if (body?.error === 'already_completed') {
            REPAIR.notice = 'This session is already finished — nothing to rebuild.';
            await loadList();
            await loadDetail(id);
            return;
        }
        REPAIR.error = body?.message || ('Could not plan the rebuild (HTTP ' + status + ')');
        renderShell();
        return;
    }
    REPAIR.plan = body;
    renderShell();
    leave('history:loadRepairPlan');
}

async function runRepair(id) {
    REPAIR.running = true;
    REPAIR.armed = false;
    REPAIR.error = null;
    renderShell();
    const { ok, body, status } = await api(REPAIR_ENDPOINT(id), { method: 'POST' });
    if (REPAIR.id !== id) return;
    REPAIR.running = false;
    if (!ok || body?.ok === false) {
        if (body?.error === 'already_completed') {
            REPAIR.notice = 'This session was already finished before the rebuild started.';
            await loadList();
            await loadDetail(id);
            return;
        }
        REPAIR.error = body?.message || ('Rebuild failed (HTTP ' + status + ')');
        if (body?.plan) REPAIR.plan = body;
        renderShell();
        // A failed repair must leave the affordance in place: the session is
        // still unfinished, and the most likely fix (the model came back, or
        // the catalogue was refreshed) is exactly a second click. Reloading
        // the plan keeps the button label and the item list honest about
        // what the next attempt would do.
        void loadRepairPlan(id);
        return;
    }
    REPAIR.result = body;
    REPAIR.plan = body;
    const s = body.session || {};
    const done = Array.isArray(body.executed) ? body.executed.length : 0;
    REPAIR.notice = 'Session finished: ' + done + ' stage(s) rebuilt, '
        + (s.consensus_status || '?') + ' ' + fmtScore(s.consensus_score)
        + ', ' + (s.total_tokens || 0) + ' tokens, ' + fmtMs(s.duration_ms) + '.';
    await loadList();
    await loadDetail(id);
    renderShell();
}

// The plan's own words for what the next click does. A failed synthesis is
// the one case where "Finish session" is misleading — the session row may
// still be flipped, but what the operator is really asking for is a re-run
// of L4 — so the button says so, and the confirmation prompt repeats it.
function repairAction(plan) {
    const l4 = plan && typeof plan.l4 === 'object' && plan.l4 !== null ? plan.l4 : {};
    if (l4.retry === true) {
        return { label: 'Re-run synthesis (L4)', armed: 'Confirm — re-run synthesis', retry: true };
    }
    if (l4.required === true) {
        return { label: 'Finish session — run synthesis', armed: 'Confirm — run synthesis', retry: false };
    }
    return { label: 'Finish session', armed: 'Confirm — rebuild now', retry: false };
}

function renderRepairSection(session) {
    const id = session.id;
    ensureRepairPlan(id);

    const planNow = REPAIR.plan && REPAIR.plan.plan ? REPAIR.plan.plan : null;
    const action  = repairAction(planNow);

    const box = el('div', { class: 'oc-history-repair' });
    box.append(el('h3', { text: action.retry ? 'Synthesis failed — re-run it' : 'Session not finished' }));
    box.append(el('div', { class: 'oc-history-repair-intro', text: action.retry
        ? 'The models that answered are stored, but the final synthesis (L4) failed, so what this '
          + 'session holds is a fallback body, not a final answer. The session is recorded as failed '
          + 'rather than completed. "Re-run synthesis" re-executes only L4 from the stored answers — '
          + 'no model is queried twice.'
        : 'The pipeline stopped before this session produced a final answer. '
          + '"Finish session" rebuilds only the stages that are missing and reuses every answer '
          + 'already stored in the journal — the models that replied are not queried again.' }));

    const actions = el('div', { class: 'oc-history-repair-actions' });
    const checkBtn = el('button', {
        type: 'button', class: 'oc-button',
        text: REPAIR.checking ? 'Checking…' : 'Refresh plan',
        disabled: REPAIR.checking || REPAIR.running,
        onclick: () => { if (!REPAIR.checking && !REPAIR.running) void loadRepairPlan(id); },
    });
    const busy = REPAIR.running;
    const armed = REPAIR.armed;
    const finishBtn = el('button', {
        type: 'button',
        class: 'oc-button oc-button-primary' + (armed ? ' oc-history-repair-armed' : ''),
        text: busy ? (action.retry ? 'Re-running synthesis…' : 'Rebuilding…')
            : (armed ? action.armed : action.label),
        title: action.retry
            ? 'Re-executes the L4 synthesis step for this session; stored model answers are reused.'
            : 'Rebuilds the missing pipeline stages for this session.',
        disabled: busy || REPAIR.checking,
        onclick: () => {
            if (busy || REPAIR.checking) return;
            if (!REPAIR.armed) { REPAIR.armed = true; renderShell(); return; }
            void runRepair(id);
        },
    });
    actions.append(finishBtn, checkBtn);
    if (REPAIR.armed && !busy) {
        const cancel = el('button', {
            type: 'button', class: 'oc-button', text: 'Cancel',
            onclick: () => { REPAIR.armed = false; renderShell(); },
        });
        actions.append(cancel);
    }
    box.append(actions);

    if (busy) {
        box.append(el('div', { class: 'oc-history-repair-status', text:
            'Rebuilding… missing stages are being executed now. This can take a while when a '
            + 'model call has to be repeated — keep the tab open.' }));
    }
    if (REPAIR.error) {
        box.append(el('div', { class: 'oc-alert oc-alert-error', text: REPAIR.error }));
    }

    const plan = REPAIR.plan && REPAIR.plan.plan ? REPAIR.plan.plan : null;
    if (REPAIR.checking && !plan) {
        box.append(el('div', { class: 'oc-history-empty', text: 'Checking what needs to be rebuilt…' }));
        return box;
    }
    if (!plan) return box;

    const items = Array.isArray(plan.items) ? plan.items : [];
    box.append(el('h4', { text: 'Will be rebuilt (' + items.length + ')' }));
    if (items.length === 0) {
        box.append(el('div', { class: 'oc-history-empty', text:
            'No pipeline stage is missing — only the session row still has to be marked completed.' }));
    } else {
        const list = el('div', { class: 'oc-history-repair-list' });
        for (const item of items) {
            const row = el('div', { class: 'oc-history-repair-item' });
            row.append(el('span', { class: 'oc-history-repair-layer', text: String(item.layer || '?') }));
            row.append(el('span', { class: 'oc-history-repair-detail', text: String(item.detail || '') }));
            list.append(row);
        }
        box.append(list);
    }

    const already = Array.isArray(REPAIR.plan.already_completed) ? REPAIR.plan.already_completed : [];
    if (already.length > 0) {
        const details = el('details', { class: 'oc-history-repair-reuse' });
        details.append(el('summary', { text: 'Reused results (' + already.length + ')' }));
        const list = el('div', { class: 'oc-history-repair-list' });
        for (const item of already) {
            const row = el('div', { class: 'oc-history-repair-item' });
            row.append(el('span', { class: 'oc-history-repair-layer', text: String(item.layer || '?') }));
            row.append(el('span', { class: 'oc-history-repair-detail', text: String(item.detail || '') }));
            list.append(row);
        }
        details.append(list);
        box.append(details);
    }

    const warnings = Array.isArray(REPAIR.plan.warnings) ? REPAIR.plan.warnings : [];
    if (warnings.length > 0) {
        const details = el('details', { class: 'oc-history-repair-reuse' });
        details.append(el('summary', { text: 'Warnings (' + warnings.length + ')' }));
        for (const w of warnings) {
            details.append(el('div', { class: 'oc-history-repair-detail', text: String(w) }));
        }
        box.append(details);
    }

    return box;
}

void probeStatus();
