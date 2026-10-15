// app.js — boot, fetch active connectors + model catalog, submit query
// over Server-Sent Events for live pipeline updates.
//
// Design (v1.2):
//   1. /api/connectors returns active providers + the catalog of usable
//      models + the default consensus pool.
//   2. ModelSelector widget renders the consensus pool and persists
//      changes to localStorage.
//   3. v1.8.37 — DEFAULT mode is "job":
//        a) POST /api/council/jobs with the same options the legacy URL
//           used to encode (selected_models, reasoning, consensus_skill,
//           cycle_limit, skill, client_run_id, …).
//        b) Open an EventSource on the returned events_url (replay SSE)
//           with `?live=1`. The worker writes pipeline_events; the
//           replay endpoint streams them as they land.
//        c) On EventSource drop or terminal PIPELINE_REPLAY_DONE that
//           reports a non-terminal job_status, reconnect with
//           Last-Event-ID so we never miss events from the worker.
//      Legacy mode (`pipeline.execution_mode = 'legacy'`) still opens
//      EventSource('/api/council/stream?…') directly, running the
//      pipeline inside the SSE web request. Both surfaces stay wired.
//   4. Strict single-stream guarantee: the active EventSource is closed
//      before another query is submitted, and PIPELINE_DONE/PIPELINE_FATAL
//      always close the stream so EventSource never auto-reconnects.
//   5. POST /api/council/run is used as a legacy-mode fallback ONLY when
//      the SSE handshake fails before any pipeline event is seen. In
//      job mode we never re-run the pipeline as a fallback — the job
//      row is the source of truth and the worker keeps making progress.

import {
    renderStep,
    renderQueryCard,
    clearPipeline,
    upsertStepFromEvent,
    renderFinalAnswer,
    renderFinalAnswerDelta,
    startActivityTimer,
    stopActivityTimer,
    setActivity,
    markStaleRunningStepsFailed,
    renderRunControls,
} from './pipeline.js';
import { renderSessionStats } from './stats.js';
import { ModelSelector, setActiveModelSelector, setBatchCapabilityIds, applyPresetToSelection } from './model-selector.js';
import * as logger from './logger.js';
import './debug-trace.js'; // ?debug=1 trace: wraps timers/rAF page-wide

const SELECTOR_ROOT_ID = 'model-selector';
let modelSelector = null;
let activeRun = null;       // { es, runId, terminated, sessionId, paused, l1Count, finalizing, anyEventSeen, mode, jobId, lastEventId }
let runCounter = 0;

// v1.8.37 — Pipeline execution mode for the main Run button.
//   'job'    : POST /api/council/jobs + EventSource(replay endpoint, live=1)
//   'legacy' : EventSource('/api/council/stream?…')
// Resolved from <meta name="oc-execution-mode"> baked into the SPA shell,
// and refined once at boot from /api/boot-config so a runtime.json or
// .config.php override takes effect without a hard reload.
function readMetaExecutionMode() {
    const m = document.querySelector('meta[name="oc-execution-mode"]');
    const v = m ? String(m.getAttribute('content') || '').toLowerCase().trim() : '';
    return v === 'legacy' ? 'legacy' : 'job';
}
function readMetaWorkerWarningSeconds() {
    const m = document.querySelector('meta[name="oc-job-worker-warning-seconds"]');
    if (!m) return 15;
    const n = Number(String(m.getAttribute('content') || '').trim());
    return Number.isFinite(n) && n >= 3 && n <= 120 ? Math.round(n) : 15;
}
let pipelineExecutionMode      = readMetaExecutionMode();
let pipelineWorkerWarningSecs  = readMetaWorkerWarningSeconds();

// ---------------------------------------------------------------------
// Batch API run mode.
//
// `batchCapability` is the server's answer to "can this deployment submit a
// batch at all" (/api/boot-config -> batch): a resolved bothub key AND a
// usable transport. It is a MACHINERY report, never a per-model verdict -
// whether one model can be batched is the provider's answer to
// `GET /api/council/batch/models`, and that answer is three-valued
// (supported / unknown), so nothing here ever claims "not supported".
//
// `ready: null` means the question has not been answered yet. The toggle
// stays usable in that state: failing closed on a slow boot-config request
// would hide a working feature, and the enqueue path reports the real reason
// if the server cannot submit.
// ---------------------------------------------------------------------
let batchCapability = { ready: null, reason: '' };
let defaultArbiter = null;
let batchPollTimer  = null;

async function refreshPipelineMode() {
    try {
        const { data } = await logger_fetchJsonOrSurface('/api/boot-config');
        const mode = String(data?.pipeline?.execution_mode || '').toLowerCase();
        if (mode === 'job' || mode === 'legacy') {
            pipelineExecutionMode = mode;
        }
        const warn = Number(data?.pipeline?.job_worker_warning_seconds);
        if (Number.isFinite(warn) && warn >= 3 && warn <= 120) {
            pipelineWorkerWarningSecs = Math.round(warn);
        }
        if (data?.batch && typeof data.batch === 'object') {
            batchCapability = {
                ready: data.batch.ready === true,
                reason: typeof data.batch.reason === 'string' ? data.batch.reason : '',
            };
        }

        // v1.9.1 - the arbiter this run will use unless its council preset
        // appoints another one. Named on the card under the question before
        // L0_OPTIONS arrives, and replaced by the run's own arbiter then.
        const configuredArbiter = data?.pipeline?.conflict_arbiter;
        defaultArbiter = configuredArbiter && typeof configuredArbiter === 'object'
            && typeof configuredArbiter.model === 'string' && configuredArbiter.model !== ''
            ? {
                provider: typeof configuredArbiter.provider === 'string' ? configuredArbiter.provider : '',
                model: configuredArbiter.model,
                role: typeof configuredArbiter.role === 'string' ? configuredArbiter.role : 'conflict',
            }
            : null;
    } catch {
        // Best-effort - fall back to the meta-tag value baked at boot.
    }
    applyBatchCapabilityToToggle();
}

/**
 * Reflect the capability report on the composer toggle. A disabled control
 * always carries its reason in the title, so "why can't I use this" is
 * answerable without opening the logs.
 */
function applyBatchCapabilityToToggle() {
    const box = document.getElementById('run-mode-batch');
    if (!box) return;
    const label = box.closest('label');
    if (batchCapability.ready !== false) {
        box.disabled = false;
        if (label) {
            label.title = batchCapability.ready === true && batchCapability.reason !== ''
                ? batchCapability.reason
                : 'Submit the L1 fan-out to the provider\'s Batch API instead of calling the models live. '
                    + 'Results arrive asynchronously - usually minutes, up to 24 hours - and the consensus '
                    + 'stages run as soon as they do.';
        }
        return;
    }
    box.disabled = true;
    box.checked = false;
    if (!label) return;
    label.title = 'Batch API unavailable: ' + (batchCapability.reason || 'this deployment is not ready for it');
    if (!label.querySelector('.oc-batch-hint')) {
        const hint = document.createElement('span');
        hint.className = 'oc-batch-hint oc-muted oc-small';
        hint.textContent = ' (unavailable)';
        label.append(hint);
    }
}

/** Is the composer asking for a batch run right now? */
function getRunMode() {
    const box = document.getElementById('run-mode-batch');
    return box && box.checked ? 'batch' : 'sync';
}

/**
 * Is the composer asking for a single-model run (one model, no consensus)?
 *
 * Read straight off the checkbox: the mode is a routing decision made at submit
 * time, and the server is the one that decides whether it can be honoured (it
 * reports `run_mode` back, and falls back to the consensus pipeline with a
 * reason when the database cannot store the 'single' verdict yet).
 */
function getSingleModelMode() {
    const box = document.getElementById('run-mode-single');
    return !!(box && box.checked);
}

/**
 * Flip the single-model toggle from code, with the same side effects a click
 * has (the two run modes stay mutually exclusive, so one switch turns the other
 * off). Dispatches a real `change` event so there is exactly one place that
 * knows what the toggles imply.
 */
function setSingleModelMode(on) {
    const box = document.getElementById('run-mode-single');
    if (!box || box.checked === !!on) return;
    box.checked = !!on;
    box.dispatchEvent(new Event('change'));
}

/**
 * Pure wording for a batch lifecycle snapshot. `batch` is the `result.batch`
 * block of GET /api/council/jobs/{id}.
 *
 * Kept pure, like the other caption builders in this file, so the DOM
 * harness can drive it from fixtures without a server.
 */
function batchStateText(batch) {
    if (!batch || typeof batch !== 'object') return null;
    const phase  = String(batch.phase_label || batch.phase || 'Queued');
    const status = String(batch.status_label || '');
    const requests = Number(batch.request_count || 0);
    const windowH  = Number(batch.completion_window_h || 24);
    const elapsed  = batchElapsedText(batch.submitted_at);
    const parts = [];
    parts.push(status !== '' ? phase + ' - ' + status : phase);
    const counts = [];
    if (requests > 0) {
        counts.push(requests + ' request' + (requests === 1 ? '' : 's'));
    }
    const polls = Number(batch.polls || 0);
    if (polls > 0) counts.push(polls + ' poll' + (polls === 1 ? '' : 's'));
    if (counts.length) parts.push(counts.join(', '));
    if (elapsed !== '') parts.push('submitted ' + elapsed + ' ago');
    parts.push('provider window: up to ' + windowH + ' h');
    const failedRows  = Number(batch?.result?.failed?.length || 0);
    const missingRows = Number(batch?.result?.missing_ids?.length || 0);
    const invalidRows = Number(batch?.result?.invalid_lines?.length || 0);
    const error = String(batch.error || '');
    const done  = String(batch.phase || '') === 'done';
    // The state attribute drives the card's colour. While the provider owns
    // the batch it is `awaiting` (warning): neither finished nor broken.
    const state = !done
        ? 'awaiting'
        : (batch.cancel_requested === true ? 'cancelled' : (error !== '' ? 'failed' : 'completed'));
    return {
        state,
        detail: parts.join(' \u00b7 '),
        partial: failedRows + missingRows + invalidRows > 0,
        failedRows,
        missingRows,
        invalidRows,
        error,
    };
}

/** "just now" / "7 min" / "2 h 05 min" from an ISO-8601 UTC stamp. */
function batchElapsedText(submittedAt) {
    if (!submittedAt) return '';
    const t = Date.parse(String(submittedAt));
    if (!Number.isFinite(t)) return '';
    const secs = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (secs < 60) return 'just now';
    const mins = Math.floor(secs / 60);
    if (mins < 60) return mins + ' min';
    const hours = Math.floor(mins / 60);
    return hours + ' h ' + String(mins % 60).padStart(2, '0') + ' min';
}

/**
 * Paint the batch lifecycle into the composer card and decide what the
 * operator can do about it: a Cancel button while the provider owns the
 * batch, a per-row "partial" note when some rows produced no answer, and
 * nothing once the run is terminal (the result is in History by then).
 */
function renderBatchState(batch) {
    const box = document.getElementById('chat-batch-state');
    if (!box) return null;
    const info = batchStateText(batch);
    if (info === null) {
        box.hidden = true;
        box.replaceChildren();
        const cancel = document.getElementById('chat-batch-cancel');
        if (cancel) cancel.hidden = true;
        return null;
    }
    box.hidden = false;
    box.dataset.batchState = info.state;
    const line = document.createElement('span');
    line.textContent = info.detail;
    box.replaceChildren(line);
    if (info.partial) {
        const note = document.createElement('span');
        note.className = 'oc-batch-state-detail';
        note.textContent = 'Some requests produced no usable answer. Each one is reported on its own '
            + 'pipeline step (failed rows: ' + info.failedRows
            + ', missing answers: ' + info.missingRows
            + ', unreadable lines: ' + info.invalidRows + '). The rest of the run continued.';
        box.append(note);
    }
    if (info.error !== '') {
        const err = document.createElement('span');
        err.className = 'oc-batch-state-detail';
        err.textContent = info.error;
        box.append(err);
    }
    const cancel = document.getElementById('chat-batch-cancel');
    if (cancel) cancel.hidden = info.state !== 'awaiting';
    return info;
}

/** One poll of GET /api/council/jobs/{id}; also repaints the composer card. */
async function pollBatchJob(jobId) {
    try {
        const { data } = await logger_fetchJsonOrSurface(
            '/api/council/jobs/' + encodeURIComponent(String(jobId)),
            { headers: { Accept: 'application/json' } },
        );
        if (!data?.ok || !data?.job) return null;
        renderBatchState(data.job?.result?.batch ?? null);
        return data.job;
    } catch {
        return null;   // a missed poll is noise; the next one asks again
    }
}

function startBatchPolling(jobId) {
    stopBatchPolling();
    void pollBatchJob(jobId);
    // 5 s is deliberately faster than the server's own provider-poll cadence
    // (30 s / 150 s): this costs one indexed row read and exists only to keep
    // the operator's screen honest between provider polls.
    batchPollTimer = setInterval(() => { void pollBatchJob(jobId); }, 5000);
}

function stopBatchPolling() {
    if (batchPollTimer !== null) {
        clearInterval(batchPollTimer);
        batchPollTimer = null;
    }
}

/**
 * Cancel a batch: POST the intent, and let the worker deliver it.
 *
 * The browser cannot cancel the batch itself - the provider call happens in
 * the worker, which may be asleep between polls - so this button reports
 * "requested" rather than "cancelled", and the card keeps showing the run
 * until the job actually reaches `cancelled`.
 */
async function onBatchCancelClick() {
    const jobId = activeRun?.jobId ?? null;
    if (jobId == null) return;
    const cancel = document.getElementById('chat-batch-cancel');
    if (cancel) cancel.disabled = true;
    setStatus('Cancel requested - the worker will tell the provider to stop...');
    try {
        const { data } = await logger_fetchJsonOrSurface(
            '/api/council/jobs/' + encodeURIComponent(String(jobId)) + '/cancel',
            { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' },
        );
        setStatus(data?.ok
            ? String(data.message || 'Cancel requested.')
            : 'Cancel was not accepted: ' + String(data?.message || data?.error || 'unknown error'));
    } catch (err) {
        setStatus('Cancel failed: ' + (err?.message || 'unknown error'));
    } finally {
        if (cancel) cancel.disabled = false;
        void pollBatchJob(jobId);
    }
}
// v1.8.22 — remember the last anyEventSeen flag of a terminated run so
// the catch handler in submitQuery() can decide between "stream closed"
// (do not re-run) and "no events seen" (safe to fall back to /api/council/run).
// Previously this check went through `activeRun?.anyEventSeen` which is
// `undefined` once `closeActiveRun()` / `finish()` clears the global —
// causing the fallback to fire even when the SSE stream had already
// returned partial results, which is exactly how operators hit the
// "Unexpected token '<', '<!DOCTYPE'" failure post-Gemini-L1_RESPONSE.
let lastRunAnyEventSeen = false;
// v1.8.23 — remember the session id of the last run even after closeActiveRun()
// clears `activeRun`, so the catch handler in submitQuery() can recover the
// final answer from /api/council/session/{id} when SSE drops mid-pipeline.
let lastRunSessionId = null;
// v1.8.23 — set true the moment we have observed CONSENSUS_CYCLE_LIMIT_REACHED
// or any L4_* event for the active run. The catch handler uses this to
// decide whether to attempt session-state recovery (the user wants L4
// content even after the stream drops at the synthesis step).
let lastRunL4OrTerminalSeen = false;

// v1.8.22 — debug controls are now resolved by logger.js based on:
//   1. URL ?debug=0/1/true/false (highest precedence)
//   2. window.OC_DEBUG = true/false
//   3. <body data-debug="0|1">
//   4. <meta name="oc-log-to-console"> (from config.php's
//      `app.log_to_console`, default true)
// `debugLog` is preserved as a backwards-compatible shim so any caller
// still referencing it keeps working. Prompt/answer bodies are dropped
// from the metadata view unless `window.OC_DEBUG_VERBOSE = true`.
const DEBUG = logger.isEnabled();
function debugLog(tag, payload) { logger.log(tag, payload || {}); }

// v1.8.22 — fetch + JSON parse with a human-readable error path. When
// the server returns HTML (the classic "Unexpected token '<'" cause), we
// rewrite the error message so the chat status shows something like
// `Non-JSON response from /api/council/run (HTTP 500, text/html)`
// instead of a bare parser error. The body preview is sent to
// logger.nonJsonPreview() so the operator can see it in the console.
async function logger_fetchJsonOrSurface(input, init = {}) {
    try {
        return await logger.fetchJson(input, init);
    } catch (err) {
        if (err && err.name === 'NonJsonResponseError') {
            const niceMsg = 'Non-JSON response from ' + err.url
                + ' (HTTP ' + err.status + ', ' + (err.contentType || 'unset') + ').'
                + (err.preview ? ' First bytes: ' + err.preview.slice(0, 200) : '');
            const e = new Error(niceMsg);
            e.name = 'NonJsonResponseError';
            e.url = err.url;
            e.status = err.status;
            e.contentType = err.contentType;
            e.preview = err.preview;
            throw e;
        }
        throw err;
    }
}

// References to the pause / reject / finalize buttons resolved on init.
let pauseBtn    = null;
let rejectBtn   = null;
let finalizeBtn = null;
let shareBtn    = null;

// session id of the most-recently-completed run; share button targets it.
let lastCompletedSessionId = null;

// Synchronize pause/reject/finalize buttons with the current run state.
function syncControlButtons() {
    if (!pauseBtn || !rejectBtn) return;
    const hasRun = activeRun && !activeRun.terminated;
    if (!hasRun) {
        pauseBtn.disabled  = true;
        rejectBtn.disabled = true;
        if (finalizeBtn) {
            finalizeBtn.disabled = true;
            finalizeBtn.textContent = 'Stop and Run consensus';
        }
        pauseBtn.textContent = 'Pause';
        // No run means no controls under the question either.
        renderRunControls({ visible: false });
        return;
    }
    // Reject is allowed any time during an active run, including while paused.
    rejectBtn.disabled = false;
    // v1.8.37 — In job mode the Pause button targets the jobId returned
    // by POST /api/council/jobs and is therefore safe as soon as we
    // have an active run. In legacy mode we still wait until the
    // sessionId surfaces on the SSE stream because /api/council/control
    // is keyed by session.
    const haveTarget = activeRun.mode === 'job'
        ? (activeRun.jobId != null)
        : (activeRun.sessionId != null);
    pauseBtn.disabled = !haveTarget;
    pauseBtn.textContent = activeRun.paused ? 'Continue' : 'Pause';
    // v1.8.20: "Stop and Run consensus" is safe once we know the
    // session id AND at least one L1 result has been collected — that
    // is the minimum L4 needs to produce a meaningful synthesis. While
    // a finalize is already in-flight (state=finalizing) the button is
    // disabled so the user cannot double-click it.
    if (finalizeBtn) {
        // v1.8.37 — Job-mode runs can target the jobId immediately; legacy
        // runs still wait for the session id to surface on the SSE stream.
        const haveFinalizeTarget = activeRun.mode === 'job'
            ? (activeRun.jobId != null)
            : (activeRun.sessionId != null);
        const canFinalize = haveFinalizeTarget
            && (activeRun.l1Count || 0) > 0
            && !activeRun.finalizing;
        finalizeBtn.disabled = !canFinalize;
        finalizeBtn.textContent = activeRun.finalizing
            ? 'Finalising…'
            : 'Stop and Run consensus';
    }

    // The same three controls, under the newest question. The values are read
    // back from the composer buttons so the two places cannot disagree.
    renderRunControls({
        visible: true,
        pauseLabel: pauseBtn.textContent,
        pauseEnabled: !pauseBtn.disabled,
        rejectEnabled: !rejectBtn.disabled,
        finalizeLabel: finalizeBtn ? finalizeBtn.textContent : 'Stop and Run consensus',
        finalizeEnabled: finalizeBtn ? !finalizeBtn.disabled : false,
        onPause: onPauseClick,
        onReject: onRejectClick,
        onFinalize: onFinalizeClick,
    });
}

async function postControl(action) {
    if (!activeRun) return null;
    // v1.8.37 — Route controls to the job endpoints when the active run
    // came from POST /api/council/jobs. Legacy runs continue to use the
    // session-keyed /api/council/control surface.
    if (activeRun.mode === 'job' && activeRun.jobId != null) {
        const path = jobControlPath(action, activeRun.jobId);
        if (!path) {
            logger.warn('control.unknown_action', { action, mode: 'job' });
            return null;
        }
        logger.control(action, { mode: 'job', jobId: activeRun.jobId, sessionId: activeRun.sessionId, runId: activeRun.runId });
        try {
            const { data: json } = await logger_fetchJsonOrSurface(path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({}),
            });
            logger.control(action + '.result', { ok: json?.ok, status: json?.status, mode: 'job' });
            return json;
        } catch (err) {
            logger.error('control.failed', { action, mode: 'job', error: err?.message ?? String(err) }, err);
            return null;
        }
    }
    if (activeRun.sessionId == null) return null;
    logger.control(action, { mode: 'legacy', sessionId: activeRun.sessionId, runId: activeRun.runId });
    try {
        const { data: json } = await logger_fetchJsonOrSurface('/api/council/control', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: activeRun.sessionId, action }),
        });
        logger.control(action + '.result', { ok: json?.ok, state: json?.state, mode: 'legacy' });
        return json;
    } catch (err) {
        logger.error('control.failed', { action, mode: 'legacy', error: err?.message ?? String(err) }, err);
        return null;
    }
}

// v1.8.37 — map the legacy control action names (pause/continue/reject/
// finalize) to the corresponding job endpoint paths. Returns null for
// unsupported actions so the caller can avoid posting an undefined URL.
function jobControlPath(action, jobId) {
    const id = encodeURIComponent(String(jobId));
    switch (action) {
        case 'pause':    return '/api/council/jobs/' + id + '/pause';
        case 'continue': return '/api/council/jobs/' + id + '/resume';
        case 'reject':   return '/api/council/jobs/' + id + '/cancel';
        case 'finalize': return '/api/council/jobs/' + id + '/run-consensus';
        default:         return null;
    }
}

async function onPauseClick() {
    if (!activeRun || activeRun.terminated) return;
    const next = activeRun.paused ? 'continue' : 'pause';
    // Optimistic UI flip — server confirms via PIPELINE_PAUSED / PIPELINE_CONTINUED
    activeRun.paused = !activeRun.paused;
    syncControlButtons();
    setStatus(activeRun.paused ? 'Pause requested — pipeline will halt at the next safe boundary…' : 'Continue requested — resuming pipeline…');
    await postControl(next);
}

// v1.8.20: "Stop and Run consensus". Cooperatively stop launching new
// consensus cycles and let the backend synthesise a final answer from
// the L1/L2/L3 results already collected. Different from Reject (which
// aborts and yields no answer) and Pause (which is resumable).
async function onFinalizeClick() {
    if (!activeRun || activeRun.terminated) return;
    if (activeRun.sessionId == null) return;
    if ((activeRun.l1Count || 0) <= 0) return;
    if (activeRun.finalizing) return;
    activeRun.finalizing = true;
    syncControlButtons();
    setStatus('Stop and Run consensus requested — synthesising final answer from collected results…');
    setActivity({ state: 'running', label: 'Finalising — synthesising from collected results', stage: '—' });
    await postControl('finalize');
}

async function onRejectClick() {
    if (!activeRun || activeRun.terminated) return;
    setStatus('Reject requested — aborting pipeline…');
    if (activeRun.sessionId != null) {
        await postControl('reject');
    }
    // Close the SSE stream eagerly so the UI does not wait for the
    // server to flush its terminal frame. The server-side pipeline will
    // still mark the session rejected on its next checkpoint. closeActiveRun
    // settles the awaiting startSse() promise, which lets submitQuery
    // reach its finally block and re-enable the Run button.
    closeActiveRun('rejected');
    syncControlButtons();
    setActivity({ state: 'failed', label: 'Pipeline rejected by user' });
    stopActivityTimer();
    setStatus('Pipeline rejected. Click Run Pipeline to start a new request.');
    const submitBtn = document.getElementById('chat-submit');
    if (submitBtn) submitBtn.disabled = false;
}

function nowIso() {
    return new Date().toISOString();
}

function fmtMs(ms) {
    if (ms == null || ms === '—') return '—';
    const n = Number(ms);
    if (!Number.isFinite(n)) return '—';
    if (n < 1000) return Math.round(n) + 'ms';
    return (n / 1000).toFixed(1) + 's';
}

function dash(v) {
    return (v === null || v === undefined || v === '') ? '—' : v;
}

function statsFromMeta(meta, status) {
    return {
        model:             dash(meta?.model),
        provider:          dash(meta?.provider),
        prompt_tokens:     dash(meta?.prompt_tokens),
        completion_tokens: dash(meta?.completion_tokens),
        total_tokens:      dash(meta?.total_tokens),
        latency:           meta?.latency_ms != null ? fmtMs(meta.latency_ms) : '—',
        status:            dash(status),
        timestamp:         dash(meta?.timestamp || nowIso()),
        cost:              meta?.cost ?? null,
        cached_tokens:     meta?.cached_tokens ?? null,
        reasoning_tokens:  meta?.reasoning_tokens ?? null,
        generation_id:     meta?.generation_id ?? null,
        tokens_estimated:  meta?.tokens_estimated ?? false,
        reasoning_text:    meta?.reasoning_text ?? null,
        cache_hit:         meta?.cache_hit ?? false,
        // Prompt cache (Bothub). Kept as null when unreported, so the
        // renderer can tell a reported zero from a provider that returned
        // no cache block; `cache_stats` is the payload's own flag for the
        // same thing. `cache_hit` above is the *response*-cache hit flag
        // (ModelResponseCache) and is a different mechanism.
        cache_write_tokens: meta?.cache_write_tokens ?? null,
        cache_discount:     meta?.cache_discount ?? null,
        cache_stats:        meta?.cache_stats ?? false,
    };
}

async function loadConnectorsAndCatalog() {
    const list = document.getElementById('connector-list');
    const selectorRoot = document.getElementById(SELECTOR_ROOT_ID);
    let data = null;
    try {
        ({ data } = await logger_fetchJsonOrSurface('/api/connectors'));
    } catch (err) {
        logger.warn('connectors.load_failed', { error: err?.message ?? String(err) });
        data = { connectors: [], available_models: [], default_selected: [] };
    }
    if (list) {
        list.replaceChildren();
        for (const c of data.connectors ?? []) {
            const li = document.createElement('li');
            li.textContent = `${c.provider}: ${c.models.length} models`;
            li.title = `Provider "${c.provider}" exposes ${c.models.length} active model${c.models.length === 1 ? '' : 's'}.`;
            list.append(li);
        }
        if ((data.connectors ?? []).length === 0) {
            const li = document.createElement('li');
            li.textContent = 'No connectors available';
            list.append(li);
        }
    }
    if (selectorRoot) {
        modelSelector = new ModelSelector(selectorRoot);
        // v1.8.49 — publish the instance so the Models tab can push a
        // consensus preset into this same pool without a reload. The
        // selection is read from the selector at submit time, so an
        // in-place update needs no extra listener.
        setActiveModelSelector(modelSelector);
        // The Save button the pool block renders persists the pool for the
        // skill currently chosen in the composer; this page owns that policy.
        modelSelector.setPoolSaver(savePoolForSkill);
        modelSelector.setCatalog(data.available_models ?? [], data.default_selected ?? []);
        // The composer's toggle may already be on (it is enabled before the
        // catalogue finishes loading), so the freshly built selector has to
        // adopt the current mode rather than defaulting to synchronous rules.
        modelSelector.setBatchMode(getRunMode() === 'batch');
        // The provider's own batch-capable list. Best-effort and after the
        // pool is painted, so a slow or failing call cannot delay the Chat
        // page: the badges simply keep saying "unknown" until it answers.
        void loadBatchCapabilityList();
        // The skill dropdown may have resolved before this catalogue did, in
        // which case applyConsensusSkillBinding() had no panel to pour a
        // preset into. Now there is one, so the binding is applied for real.
        applyConsensusSkillBinding();
    }
}

/**
 * Ask the server which ids the provider lists as batch-capable and hand the
 * answer to the pool.
 *
 * Only a SUCCESSFUL answer is adopted. A failure leaves the client in the
 * unknown state on purpose: marking every model "not batch-capable" because
 * one request timed out is the kind of wrong answer an operator then spends
 * an afternoon trying to fix.
 */
async function loadBatchCapabilityList() {
    try {
        const { response, data } = await logger_fetchJsonOrSurface('/api/council/batch/models', {
            headers: { Accept: 'application/json' },
        });
        if (response && response.ok === true && data?.ok === true && Array.isArray(data.models)) {
            setBatchCapabilityIds(data.models);
        } else {
            setBatchCapabilityIds(null);
        }
    } catch {
        setBatchCapabilityIds(null);
    }
    if (modelSelector) { modelSelector.render(); }
}

function setStatus(text) {
    const status = document.getElementById('chat-status');
    if (status) status.textContent = text;
}

function getReasoning() {
    const sel = document.getElementById('reasoning-select');
    return sel ? sel.value : 'off';
}

/**
 * What the card under the question says before the run answers: the pool that
 * was sent, and the arbiter this council appoints - the skill's own binding
 * when it has one, the configured conflict arbiter otherwise. The engine's
 * L0_OPTIONS event replaces both with what the run actually got.
 */
function queryParticipantsInfo() {
    const models = modelSelector ? modelSelector.selected() : [];
    const skill = getConsensusSkill();
    const item = skill === null ? null : (consensusSkillItems.get(skill) || null);
    const arbiter = item && item.arbiter ? item.arbiter : defaultArbiter;
    return { models, arbiter, state: 'requested' };
}

function getConsensusSkill() {
    const sel = document.getElementById('consensus-skill-select');
    const v = sel ? String(sel.value || '').trim() : '';
    return v === '' ? null : v;
}

function getCycleLimit() {
    const inp = document.getElementById('cycle-limit-input');
    if (!inp) return null;
    const raw = String(inp.value ?? '').trim();
    if (raw === '') return null;
    const n = Number(raw);
    if (!Number.isFinite(n) || n < 1) return null;
    // Mirror the server-side cap so the picker visually agrees with the
    // engine. The server still clamps regardless.
    const max = Number(inp.max || 5);
    return Math.max(1, Math.min(Math.floor(n), Number.isFinite(max) ? max : 5));
}

/**
 * The consensus-skill entries GET /api/consensus/skills last returned, keyed
 * by the value the dropdown carries — the skill's file stem, which is also
 * what `consensus_skill` sends to the server.
 *
 * @type {Map<string, object>}
 */
let consensusSkillItems = new Map();

/**
 * The pool the server publishes for a skill with no preset of its own
 * (`default_pool` in GET /api/consensus/skills). The catalog deliberately
 * refuses to invent a binding, so this is what the left-hand panel falls back
 * to for such a skill: the Default preset's models, applied only when the
 * skill really reports `uses_user_pool: true`. Empty when the server sent
 * none, which keeps the honest "left as you assembled it" note.
 *
 * @type {Array<{provider:string,model:string}>}
 */
let consensusDefaultPool = [];

/**
 * The skill the server falls back to when the composer carries no explicit
 * choice (`default` in GET /api/consensus/skills). The placeholder option is
 * not "no skill": it is this one, and the panel has to show its pool or the
 * operator's default choice leaves the Consensus models list empty.
 */
let consensusDefaultSkill = '';

async function loadConsensusSkills() {
    const sel = document.getElementById('consensus-skill-select');
    if (!sel) return;
    try {
        const { data } = await logger_fetchJsonOrSurface('/api/consensus/skills');
        const items = Array.isArray(data?.consensus_skills) ? data.consensus_skills : [];
        if (items.length === 0) return;
        consensusSkillItems = new Map();
        consensusDefaultPool = Array.isArray(data?.default_pool) ? data.default_pool : [];
        sel.replaceChildren();
        const def = String(data?.default || '');
        consensusDefaultSkill = def;
        const fallback = document.createElement('option');
        fallback.value = '';
        fallback.textContent = '(default — server-configured)';
        sel.appendChild(fallback);
        for (const it of items) {
            const opt = document.createElement('option');
            opt.value = String(it.file || it.slug || '');
            const tag = it.consensus_scoring === 'lexical_jaccard' ? ' [lexical]' : ' [semantic]';
            opt.textContent = (it.name || it.slug || it.file || 'consensus') + tag;
            // The tooltip says what the skill does AND what it runs on, so the
            // choice can be made before selecting it rather than after.
            const binding = it.uses_user_pool === true
                ? ' No linked preset: this council runs on the consensus pool in the left panel — filled from '
                    + 'the Default preset when you pick it.'
                : ` Linked to the "${it.preset}" preset — picking it switches the left panel to that council's models.`;
            opt.title = String(it.description || '') + binding;
            if (opt.value === def) opt.selected = true;
            consensusSkillItems.set(opt.value, it);
            sel.appendChild(opt);
        }
        // The dropdown may already carry a value (the server default), so the
        // panel is brought in line before the operator touches anything.
        applyConsensusSkillBinding();
    } catch {
        // Best-effort. Leave the placeholder dropdown alone on failure.
    }
}

/** Write the skill's own status line under its control ('' clears it). */
function setConsensusSkillNote(text) {
    const note = document.getElementById('consensus-skill-status');
    if (note) { note.textContent = text; }
}

/**
 * Make the left-hand consensus pool describe the skill that is in effect.
 *
 * The reported defect: choosing a skill in the composer did nothing to the
 * model panel beside it, although a skill's council runs on the models its
 * domain preset defines — the panel kept showing whatever pool the operator
 * had assembled, so the run used models the operator had not chosen.
 *
 * The binding travels with the skill (`preset`, `models`, `arbiter`,
 * `uses_user_pool`); when it exists the pool is replaced through the same
 * applyPresetToSelection() the Models tab's "Use as my consensus pool" button
 * uses, so both surfaces agree about what a preset means.
 *
 * What this deliberately does NOT do:
 *   - invent a binding. A skill the server reported as `uses_user_pool` gets
 *     no binding of its own from here; its pool comes from the separate
 *     `default_pool` the server publishes (the Default preset), which is the
 *     pool a skill with no preset of its own is documented to run on — never
 *     some other skill's models dressed up as its own;
 *   - claim an outcome it cannot support. applyPreset() reports
 *     `{applied, skipped, empty, reason}` and all of it is passed on: how
 *     many models were bound, which of them no configured connector can
 *     serve, and — when `applied` is 0 — that nothing was applied.
 */
/**
 * The skill the composer currently stands for.
 *
 * A named option is that skill. The placeholder - "(default - server
 * configured)" - is not "no skill": the server names the skill it uses by
 * default in `default`, and an operator who leaves the composer untouched is
 * running THAT council. Resolving it here is the single place both callers
 * agree on what the composer means, which is why the Consensus models panel
 * used to stay empty on a freshly loaded page: nothing filled it until a
 * named skill had been picked explicitly.
 *
 * A value the server never listed still resolves to null, so an unknown
 * option keeps behaving as it did - nothing applied, nothing claimed.
 *
 * @returns {object|null}
 */
function consensusSkillInEffect() {
    const value = getConsensusSkill();
    if (value !== null) {
        return consensusSkillItems.get(value) || null;
    }
    return consensusSkillItems.get(String(consensusDefaultSkill || '')) || null;
}

function applyConsensusSkillBinding() {
    const sel = document.getElementById('consensus-skill-select');
    if (!sel) { return; }
    const item = consensusSkillInEffect();
    if (item === null) {
        // A value the server never listed: nothing is known about a binding,
        // so nothing is applied and nothing claimed. The empty placeholder is
        // no longer this case - it resolves to the server's default skill.
        setConsensusSkillNote('');
        return;
    }
    const name = String(item.name || item.slug || item.file || 'consensus');
    if (item.uses_user_pool === true) {
        // No preset of its own — the server said so and the catalog refused to
        // invent one. The panel still has to describe a predictable pool, so
        // the skill falls back to the Default preset the server publishes
        // beside the list. That fallback is applied only here, so a skill that
        // was never reported at all still leaves the pool alone below.
        const fallback = Array.isArray(consensusDefaultPool) ? consensusDefaultPool : [];
        if (modelSelector === null || fallback.length === 0) {
            setConsensusSkillNote(`"${name}" is not linked to a preset: this council runs on the consensus `
                + 'pool below, which was left exactly as you assembled it.');
            return;
        }
        const fallbackResult = applyPresetToSelection(fallback);
        if (fallbackResult === null) {
            setConsensusSkillNote(`"${name}" has no preset of its own, and the Default preset could not be `
                + 'applied: the Chat page\'s model panel is not available here, so the pool was not changed.');
            return;
        }
        if (fallbackResult.applied === 0) {
            setConsensusSkillNote(`"${name}" has no preset of its own, and the Default preset could not be `
                + `applied: ${fallbackResult.reason} The pool is unchanged.`);
            return;
        }
        const fallbackSkipped = Array.isArray(fallbackResult.skipped) ? fallbackResult.skipped : [];
        setConsensusSkillNote(`"${name}" has no preset of its own, so it runs on the Default preset: `
            + `${fallbackResult.applied} model${fallbackResult.applied === 1 ? '' : 's'} applied to the consensus pool`
            + (fallbackSkipped.length > 0
                ? `; no configured connector serves ${fallbackSkipped.map((k) => String(k).split('|').join(' / ')).join(', ')}, so `
                    + `${fallbackSkipped.length === 1 ? 'it was' : 'they were'} left out.`
                : '.'));
        return;
    }
    if (modelSelector === null) {
        // The pool is still being built — its catalogue request has not
        // landed yet. Say nothing rather than describe a panel that does not
        // exist: loadConnectorsAndCatalog() calls this again the moment the
        // selector is registered.
        return;
    }
    const models = Array.isArray(item.models) ? item.models : [];
    const result = applyPresetToSelection(models);
    if (result === null) {
        setConsensusSkillNote(`"${name}" is linked to the preset "${item.preset}", but the Chat page's `
            + 'model panel is not available here, so the pool was not changed.');
        return;
    }
    if (result.applied === 0) {
        setConsensusSkillNote(`"${name}" is linked to the preset "${item.preset}", but no model was `
            + `applied: ${result.reason} The pool is unchanged.`);
        return;
    }
    const skipped = Array.isArray(result.skipped) ? result.skipped : [];
    setConsensusSkillNote(`"${name}" is linked to the preset "${item.preset}": ${result.applied} `
        + `model${result.applied === 1 ? '' : 's'} applied to the consensus pool`
        + (skipped.length > 0
            ? `; no configured connector serves ${skipped.map((k) => String(k).split('|').join(' / ')).join(', ')}, so `
                + `${skipped.length === 1 ? 'it was' : 'they were'} left out.`
            : '.'));
}

/**
 * Save the current consensus pool FOR the skill selected in the composer, so
 * the choice survives a page reload instead of applying only to the next run.
 *
 * A skill's pool lives on its domain preset: the skill's own preset when it
 * has one, the Default preset otherwise (the same one an unbound skill falls
 * back to in applyConsensusSkillBinding). POST /api/domain-presets/{slug}/select
 * replaces that preset's selection in place — it keeps the preset's name and
 * its skill link — which is exactly "save the pool for this skill". The
 * documented upsert (POST /api/domain-presets) is used only when the preset
 * row does not exist yet, so a store that was never seeded still works.
 *
 * Every outcome is said out loud: saved, nothing to save, or the server's own
 * reason. A silent "clicked and nothing happened" is the one thing this must
 * never do.
 */
async function savePoolForSkill() {
    if (modelSelector === null) {
        return;
    }
    const pool = modelSelector.selected();
    if (pool.length === 0) {
        modelSelector.setSaveStatus('Nothing to save: the consensus pool is empty.');
        return;
    }
    const item = consensusSkillInEffect();
    if (item === null) {
        modelSelector.setSaveStatus('Nothing saved yet: choose a Consensus skill first - the pool is saved for the skill that is selected.');
        return;
    }
    const skillName = String(item.name || item.slug || item.file || 'consensus');
    const ownPreset = typeof item.preset === 'string' && item.preset !== '' ? item.preset : null;
    const slug = ownPreset === null ? 'default' : ownPreset;
    const plural = pool.length === 1 ? '' : 's';
    modelSelector.setSaveStatus(`Saving ${pool.length} model${plural} for "${skillName}" ...`);
    try {
        let { response, data } = await logger_fetchJsonOrSurface(
            `/api/domain-presets/${encodeURIComponent(slug)}/select`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ models: pool }),
            },
        );
        if (response.status === 404) {
            // The preset row is missing (a store that was never seeded). Create
            // it through the documented upsert. An unbound skill's fallback goes
            // in as `default` and is left unlinked on purpose, so it keeps
            // serving every skill that has no preset of its own.
            ({ response, data } = await logger_fetchJsonOrSurface('/api/domain-presets', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(ownPreset === null
                    ? { slug, name: 'Default', models: pool, is_system: true }
                    : { slug, name: skillName, models: pool, skill_slug: item.slug }),
            }));
        }
        if (!response.ok) {
            const reason = String(data?.message || data?.error || ('HTTP ' + response.status));
            modelSelector.setSaveStatus(`Save failed for "${skillName}": ${reason}`);
            return;
        }
        if (data && data.persisted === false) {
            modelSelector.setSaveStatus(`Saved for "${skillName}" in memory only: `
                + String(data.warning || 'the server could not persist it, so it disappears on reload.'));
            return;
        }
        modelSelector.setSaveStatus(`Saved ${pool.length} model${plural} for "${skillName}". `
            + 'They are restored the next time you pick this skill.');
    } catch (err) {
        modelSelector.setSaveStatus(`Save failed for "${skillName}": ${err?.message ?? String(err)}`);
    }
}

// Close any active SSE stream and mark the run terminated. Safe to call
// at any time (idempotent). When `reason` indicates a deliberate UI
// action (rejected/superseded/done/unload/pagehide), we settle the
// awaiting `startSse` promise instead of leaving it dangling — otherwise
// `submitQuery` never reaches its `finally` block and the Run button
// stays disabled across runs (regression introduced by the v1.8.7
// pause/reject controls).
function closeActiveRun(reason) {
    // A batch run keeps polling the job row; nothing else may keep doing so
    // after the run it belongs to is gone.
    stopBatchPolling();
    if (!activeRun) return;
    const run = activeRun;
    run.terminated = true;
    // v1.8.46 — remember WHY the run was torn down. A deliberate close
    // (reject / superseded / navigation) must not be mistaken for "the
    // job ended on its own", which is what the terminal reconciliation in
    // submitQueryJobMode() keys off.
    run.closeReason = reason || 'closed';
    // v1.8.22 — remember whether the now-terminated run had emitted any
    // event so the catch handler in submitQuery() can avoid the doomed
    // /api/council/run fallback (which produced the "Unexpected token '<'"
    // error when the backend was still mid-pipeline). closeActiveRun()
    // clears `activeRun` to null, so any later `activeRun?.anyEventSeen`
    // check would silently see `undefined`.
    lastRunAnyEventSeen = lastRunAnyEventSeen || !!run.anyEventSeen;
    // v1.8.37 / v1.8.45 — clear the job-mode worker-probe interval.
    stopWorkerWarnTimer(run);
    try { run.es?.close(); } catch { /* noop */ }
    activeRun = null;
    syncControlButtons();
    logger.sse('close', { runId: run.runId, reason: reason || 'closed', anyEventSeen: run.anyEventSeen });
    // Resolve the pending startSse() promise so the awaiting submitQuery
    // can run its cleanup (`finally`). We mark `anyEventSeen` so the
    // caller does NOT fall back to the synchronous /api/council/run —
    // a rejected run must terminate cleanly, not re-execute.
    if (typeof run.settle === 'function') {
        if (run.mode === 'job') {
            // Job-mode settle takes a reason string; 'job_terminal' here
            // means "stop reconnecting" because the user closed it.
            run.settle('job_terminal');
        } else {
            run.settle({ anyEventSeen: true, reason: reason || 'closed' });
        }
    }
}

function startSse(query) {
    return new Promise((resolve, reject) => {
        // Close any prior run before opening a new one so the browser
        // never has two parallel EventSources for the same controller.
        closeActiveRun('superseded');

        const params = new URLSearchParams();
        params.set('query', query);
        const selected = modelSelector ? modelSelector.selected() : [];
        if (selected.length > 0) params.set('selected_models', JSON.stringify(selected));
        params.set('reasoning', getReasoning());
        const consensusSkill = getConsensusSkill();
        if (consensusSkill) params.set('consensus_skill', consensusSkill);
        const cycleLimit = getCycleLimit();
        if (cycleLimit != null) params.set('cycle_limit', String(cycleLimit));
        // One model, no consensus: the flag travels with the run so the server
        // never has to guess from the size of the pool.
        if (getSingleModelMode()) params.set('single_model', '1');
        // v1.8.21: stable per-submission id. EventSource auto-reconnects on
        // transient close; the backend uses this id as a mutex key so a
        // second connection cannot re-launch the same pipeline.
        const clientRunId = 'crun_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
        params.set('client_run_id', clientRunId);

        const url = '/api/council/stream?' + params.toString();
        const runId = 'run-' + (++runCounter);
        debugLog('startSse', { runId, clientRunId, url, selected, reasoning: getReasoning(), consensusSkill, cycleLimit });
        let es;
        try {
            es = new EventSource(url);
        } catch (err) {
            reject(err);
            return;
        }
        // `settle` is exposed on the run object so closeActiveRun() can
        // resolve the awaiting submitQuery promise from the outside (eg.
        // when the user clicks Reject and we close the stream eagerly).
        let settled = false;
        const settle = (value) => {
            if (settled) return;
            settled = true;
            resolve(value);
        };
        const fail = (err) => {
            if (settled) return;
            settled = true;
            reject(err);
        };
        const run = {
            es, runId, terminated: false, anyEventSeen: false,
            sessionId: null, paused: false, settle,
            // v1.8.20: number of L1 responses observed so far (gates
            // the "Stop and Run consensus" button), and a flag set
            // once the backend confirms a manual finalize is in flight.
            l1Count: 0, finalizing: false,
        };
        activeRun = run;
        syncControlButtons();
        const finish = (err) => {
            // v1.8.22 — always copy `anyEventSeen` to the module-scope
            // flag so the catch handler in submitQuery() can avoid the
            // fallback re-run even after activeRun is cleared.
            lastRunAnyEventSeen = lastRunAnyEventSeen || !!run.anyEventSeen;
            if (run.terminated) {
                if (!settled) settle({ anyEventSeen: run.anyEventSeen });
                return;
            }
            run.terminated = true;
            try { es.close(); } catch { /* noop */ }
            if (activeRun === run) activeRun = null;
            err ? fail(err) : settle({ anyEventSeen: run.anyEventSeen });
        };

        es.addEventListener('open', () => {
            logger.sse('open', { runId, clientRunId, readyState: es.readyState, url: '/api/council/stream' });
        });
        es.addEventListener('pipeline', (e) => {
            // Drop events from runs that were superseded by a newer
            // submission so the UI never mixes two pipelines.
            if (run.terminated || activeRun !== run) {
                logger.sse('event_dropped', { runId, reason: 'run terminated/superseded' });
                return;
            }
            let evt;
            try {
                evt = JSON.parse(e.data);
            } catch (err) {
                // v1.8.22 — the SSE wire frame failed to parse. EventSource
                // already buffered the full event, so this is genuinely a
                // malformed payload from the server — not a network blip.
                logger.error('sse.parse_failed', { runId, raw_len: typeof e.data === 'string' ? e.data.length : 0 }, err);
                return;
            }
            try {
                run.anyEventSeen = true;
                lastRunAnyEventSeen = true;
                logger.pipelineEvent(evt);
                // v1.8.21: backend rejected this duplicate connection.
                // Tear the run down without falling back to /api/council/run.
                if (evt.type === 'PIPELINE_DUPLICATE_RUN_REJECTED') {
                    setStatus('A duplicate stream was rejected; the original run continues. '
                        + 'Original session: ' + (evt?.payload?.existing?.session_id ?? '?'));
                    debugLog('PIPELINE_DUPLICATE_RUN_REJECTED', evt.payload);
                    run.duplicate = true;
                }
                // Capture the session id from the first event that
                // carries it — needed to target /api/council/control.
                const sid = evt?.payload?.session_id;
                if (run.sessionId == null && Number.isFinite(Number(sid))) {
                    run.sessionId = Number(sid);
                    syncControlButtons();
                }
                // v1.8.23 — remember the session id on the module scope
                // so recovery after an SSE drop can target the right row.
                if (run.sessionId != null && Number.isFinite(Number(run.sessionId))) {
                    lastRunSessionId = Number(run.sessionId);
                }
                // v1.8.23 — track once we've crossed into L4 territory
                // (cycle limit reached / L4_* / consensus stop reasons)
                // so the catch handler knows recovery is worth trying.
                if (typeof evt.type === 'string'
                    && (evt.type.startsWith('L4_')
                        || evt.type === 'CONSENSUS_CYCLE_LIMIT_REACHED'
                        || evt.type === 'CONSENSUS_STAGNATION_DETECTED'
                        || evt.type === 'CONSENSUS_MANUAL_FINALIZE_REQUESTED'
                        || evt.type === 'CONSENSUS_MANUAL_FINALIZE_STARTED'
                        || evt.type === 'PIPELINE_FINALIZING')
                ) {
                    lastRunL4OrTerminalSeen = true;
                }
                // Track pause/continue state based on backend events.
                if (evt.type === 'PIPELINE_PAUSED') {
                    run.paused = true;
                    syncControlButtons();
                    setStatus('Pipeline paused — click Continue to resume.');
                    setActivity({ state: 'paused', label: 'Paused — awaiting continue', stage: evt.payload?.stage ?? '—' });
                }
                if (evt.type === 'PIPELINE_CONTINUED') {
                    run.paused = false;
                    syncControlButtons();
                    setStatus('Pipeline resumed.');
                    setActivity({ state: 'running', label: 'Pipeline resumed', stage: evt.payload?.stage ?? '—' });
                }
                // v1.8.20: count successful L1 responses so we can enable
                // "Stop and Run consensus" as soon as L4 has at least one
                // result to synthesise from. The backend emits L1_RESPONSE
                // for each model that returned content this round.
                if (evt.type === 'L1_RESPONSE') {
                    run.l1Count = (run.l1Count || 0) + 1;
                    syncControlButtons();
                }
                // v1.8.20: backend acknowledged Stop and Run consensus.
                if (evt.type === 'CONSENSUS_MANUAL_FINALIZE_REQUESTED'
                    || evt.type === 'CONSENSUS_MANUAL_FINALIZE_STARTED'
                    || evt.type === 'PIPELINE_FINALIZING') {
                    run.finalizing = true;
                    syncControlButtons();
                    setStatus('Stop and Run consensus accepted — synthesising final answer from collected results…');
                    setActivity({ state: 'running', label: 'Finalising — synthesising from collected results', stage: '—' });
                }
                if (evt.type === 'AUTO_ROUTING_NOTICE') {
                    try { renderAutoRoutingNotice(evt.payload || {}); } catch (_) { /* non-fatal */ }
                }
                handleStreamedEvent(evt);
                if (evt.type === 'PIPELINE_DONE' || evt.type === 'PIPELINE_FATAL' || evt.type === 'PIPELINE_REJECTED') {
                    debugLog('terminal event', {
                        type: evt.type,
                        reason: evt?.payload?.reason ?? evt?.payload?.loop_stop_reason,
                        session_id: evt?.payload?.session_id ?? run.sessionId,
                        runId: run.runId,
                        clientRunId,
                    });
                    if (evt.type === 'PIPELINE_DONE' && evt.payload?.stats) {
                        renderSessionStats(evt.payload.stats);
                    }
                    if (evt.type === 'PIPELINE_FATAL') {
                        setStatus('Error: ' + (evt.payload?.message ?? 'pipeline failed'));
                    }
                    if (evt.type === 'PIPELINE_REJECTED') {
                        setActivity({ state: 'failed', label: 'Pipeline rejected by user' });
                        setStatus('Pipeline rejected.');
                    }
                    // Terminal event — close the stream so the browser
                    // does not auto-reconnect and re-trigger the run.
                    finish(null);
                }
            } catch (err) {
                console.error('bad SSE event', err);
            }
        });
        es.addEventListener('error', () => {
            if (run.terminated) return;
            logger.sse('error', { runId, readyState: es.readyState, anyEventSeen: run.anyEventSeen });
            // Browsers fire `error` both on transient network blips
            // (readyState=CONNECTING) and on terminal closure. Only
            // resolve when the connection is conclusively closed AND
            // we have NOT already received a terminal pipeline event.
            if (es.readyState === EventSource.CLOSED) {
                finish(new Error('SSE connection closed'));
            } else if (es.readyState === EventSource.CONNECTING && run.anyEventSeen) {
                logger.sse('reconnect_suppressed', { runId, clientRunId, anyEventSeen: run.anyEventSeen });
                try { es.close(); } catch { /* noop */ }
                finish(new Error('SSE connection dropped mid-run'));
            }
        });
    });
}

async function fallbackSync(query) {
    setStatus('Live updates unavailable — running in fallback mode…');
    const selected = modelSelector ? modelSelector.selected() : [];
    const body = {
        query,
        selected_models: selected,
        reasoning: getReasoning(),
    };
    const consensusSkill = getConsensusSkill();
    if (consensusSkill) body.consensus_skill = consensusSkill;
    const cycleLimit = getCycleLimit();
    if (cycleLimit != null) body.cycle_limit = cycleLimit;
    if (getSingleModelMode()) body.single_model = true;
    // v1.8.22 — fetchJson() validates content-type before JSON.parse and
    // throws NonJsonResponseError carrying a body preview when the server
    // returns HTML (the exact "Unexpected token '<', '<!DOCTYPE...'" path).
    const { response: res, data } = await logger_fetchJsonOrSurface('/api/council/run', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error(data.error || data.message || ('HTTP ' + res.status));

    const stepsList = Array.isArray(data.steps) ? data.steps : [];
    const eventsList = Array.isArray(data.events) ? data.events : [];
    const eventsByStep = {};
    for (const ev of eventsList) {
        const sid = ev.step_id;
        if (sid == null) continue;
        (eventsByStep[sid] = eventsByStep[sid] || []).push(ev);
    }

    for (const step of stepsList) {
        const meta = (typeof step.meta === 'string') ? (JSON.parse(step.meta) || {}) : (step.meta || {});
        renderStep({
            id: step.id,
            layer: meta.layer || (step.step_key || '').split('.')[0] || 'L?',
            label: step.label || step.step_key || '',
            status: step.status || 'completed',
            response_text: step.summary || '(no response)',
            stats: statsFromMeta(meta, step.status),
            raw: { step, events: eventsByStep[step.id] || [] },
        });
    }
    for (const ev of eventsList) {
        if (ev.step_id != null && eventsByStep[ev.step_id]) continue;
        if (ev.event_type === 'PIPELINE_DONE') continue;
        renderStep({
            id: 'ev-' + ev.id,
            layer: typeof ev.event_type === 'string' ? ev.event_type.split('_')[0] : '—',
            label: ev.event_type || 'event',
            status: 'event',
            response_text: typeof ev.payload === 'object' && ev.payload !== null
                ? JSON.stringify(ev.payload, null, 2)
                : String(ev.payload ?? ''),
            stats: statsFromMeta({}, 'event'),
            raw: ev,
        });
    }

    if (data.final_answer) {
        const syn = data.synthesis_stats || {};
        renderFinalAnswer({
            text: data.final_answer,
            stats: statsFromMeta({
                ...syn,
                latency_ms: syn.latency_ms ?? data.stats?.total_latency_ms ?? 0,
            }, 'completed'),
            raw: { consensus: data.consensus, synthesis_stats: data.synthesis_stats },
        });
    }
    if (data.stats) renderSessionStats(data.stats);
    if (data.session_id != null && Number.isFinite(Number(data.session_id))) {
        lastCompletedSessionId = Number(data.session_id);
        if (shareBtn) shareBtn.disabled = false;
    }
}

function handleStreamedEvent(evt) {
    upsertStepFromEvent(evt, statsFromMeta);
    if (evt.type === 'PIPELINE_DONE') {
        const payload = evt.payload || {};
        const sid = payload.session_id ?? activeRun?.sessionId ?? null;
        if (sid != null && Number.isFinite(Number(sid))) {
            lastCompletedSessionId = Number(sid);
            if (shareBtn) shareBtn.disabled = false;
        }
        if (payload.final_answer) {
            const syn = payload.synthesis_stats || {};
            renderFinalAnswer({
                text: payload.final_answer,
                stats: statsFromMeta({
                    ...syn,
                    latency_ms: syn.latency_ms ?? payload.duration_ms ?? 0,
                }, 'completed'),
                raw: { consensus: payload.consensus, synthesis_stats: payload.synthesis_stats },
            });
        }
        setActivity({ state: 'completed', label: 'Pipeline completed', stage: 'Σ' });
        stopActivityTimer();
        setStatus('Pipeline completed.');
    }
}

async function onShareClick() {
    if (!shareBtn || shareBtn.disabled) return;
    const sid = lastCompletedSessionId;
    const resultBox = document.getElementById('chat-share-result');
    if (sid == null) {
        if (resultBox) {
            resultBox.hidden = false;
            resultBox.textContent = 'No completed session to share yet.';
        }
        return;
    }
    shareBtn.disabled = true;
    shareBtn.textContent = 'Generating link…';
    try {
        const { response: res, data } = await logger_fetchJsonOrSurface('/api/share/session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sid }),
        });
        if (!res.ok || !data?.ok) {
            // v1.8.24: migration_required is a precise, actionable signal —
            // the shared_sessions table is missing from the live DB. Render
            // an operator-friendly message that names the migration file
            // and the Settings → Migrations path.
            if (data?.error === 'migration_required') {
                const missing = data?.missing_table || 'shared_sessions';
                const migration = data?.migration || 'db/migrations/006_shared_sessions.sql';
                if (resultBox) {
                    resultBox.hidden = false;
                    resultBox.textContent =
                        'Sharing is unavailable: table "' + missing + '" is missing. '
                        + 'Apply migration ' + migration + ' (Settings → Migrations → Apply) and try again.';
                }
                return;
            }
            const baseMsg = data?.message || data?.error || ('HTTP ' + res.status);
            const reason = data?.reason;
            let detail = '';
            if (reason && typeof reason === 'object') {
                const parts = [];
                if (reason.class)    parts.push(String(reason.class));
                if (reason.sqlstate) parts.push('SQLSTATE ' + String(reason.sqlstate));
                if (reason.message)  parts.push(String(reason.message));
                if (parts.length)    detail = ' (' + parts.join(': ') + ')';
            }
            if (resultBox) {
                resultBox.hidden = false;
                resultBox.textContent = 'Could not create share link: ' + baseMsg + detail;
            }
            return;
        }
        const url = String(data.url || '');
        if (resultBox) {
            resultBox.hidden = false;
            resultBox.replaceChildren();
            const label = document.createElement('div');
            label.textContent = 'Public read-only share link (no model controls, no admin access):';
            resultBox.appendChild(label);
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = url;
            resultBox.appendChild(link);
            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'oc-button-ghost';
            copyBtn.style.marginLeft = '0.5rem';
            copyBtn.textContent = 'Copy';
            copyBtn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(url);
                    copyBtn.textContent = 'Copied ✓';
                    setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1500);
                } catch {
                    copyBtn.textContent = 'Copy failed';
                }
            });
            resultBox.appendChild(copyBtn);
            if (data.persistent === false) {
                const warn = document.createElement('div');
                warn.style.marginTop = '0.4rem';
                warn.style.opacity = '0.7';
                warn.style.fontSize = '0.8rem';
                warn.textContent = 'Note: this host has no database configured — link will not survive process restart.';
                resultBox.appendChild(warn);
            }
        }
    } catch (err) {
        if (resultBox) {
            resultBox.hidden = false;
            resultBox.textContent = 'Share request failed: ' + (err?.message || 'unknown error');
        }
    } finally {
        shareBtn.disabled = false;
        shareBtn.textContent = 'Share';
    }
}

// v1.8.37 — Job-mode submit flow. Posts the query payload to
// /api/council/jobs (so the long-running LLM pipeline lives in the
// worker, not the web request), then attaches an EventSource to the
// replay endpoint at the returned events_url. The replay endpoint
// streams persisted pipeline_events as the worker writes them and
// terminates with PIPELINE_REPLAY_DONE — at which point we either stop
// (if job_status is terminal) or reconnect with Last-Event-ID.
// v1.9.3 - composer attachments. What the operator attaches rides with the
// query into the durable job payload (POST /api/council/jobs) and reaches
// every model call through the pipeline; the limits mirror
// App\Pipeline\QueryAttachments so the browser refuses what the server
// would refuse anyway.
const ATTACHMENT_MAX_FILES = 6;
const ATTACHMENT_MAX_FILE_CHARS = 128000;
const ATTACHMENT_MAX_TOTAL_CHARS = 300000;
const ATTACHMENT_EXTENSIONS = new Set([
    'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'jsonl', 'log',
    'yaml', 'yml', 'xml', 'html', 'htm', 'css', 'js', 'mjs', 'ts',
    'py', 'php', 'rb', 'go', 'rs', 'java', 'c', 'h', 'cpp', 'hpp',
    'cs', 'sh', 'ps1', 'sql', 'ini', 'toml', 'conf', 'tex', 'rst',
]);
const composerAttachments = { items: [], note: '' };

function initAttachments() {
    const input = document.getElementById('chat-attach-input');
    const button = document.getElementById('chat-attach-button');
    if (!input || !button) return;
    button.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
        void onAttachmentsPicked(input.files);
        input.value = '';
    });
}

function attachmentExtension(name) {
    const dot = String(name).lastIndexOf('.');
    return dot >= 0 ? String(name).slice(dot + 1).toLowerCase() : '';
}

async function onAttachmentsPicked(fileList) {
    const files = Array.from(fileList || []);
    for (const file of files) {
        const name = String(file.name || 'file');
        if (!ATTACHMENT_EXTENSIONS.has(attachmentExtension(name))) {
            composerAttachments.note = name + ': not a supported text format.';
            continue;
        }
        if (composerAttachments.items.length >= ATTACHMENT_MAX_FILES) {
            composerAttachments.note = 'No more than ' + ATTACHMENT_MAX_FILES + ' files per run.';
            break;
        }
        let content = '';
        try {
            content = await file.text();
        } catch {
            composerAttachments.note = name + ': the file could not be read.';
            continue;
        }
        content = content.replace(/\r\n|\r/g, '\n').split('\u0000').join('');
        if (content.length > ATTACHMENT_MAX_FILE_CHARS) {
            composerAttachments.note = name + ': larger than ' + ATTACHMENT_MAX_FILE_CHARS + ' characters.';
            continue;
        }
        const total = composerAttachments.items.reduce((sum, item) => sum + item.content.length, 0) + content.length;
        if (total > ATTACHMENT_MAX_TOTAL_CHARS) {
            composerAttachments.note = 'The files together are larger than ' + ATTACHMENT_MAX_TOTAL_CHARS + ' characters.';
            break;
        }
        composerAttachments.items.push({ name: name, content: content, chars: content.length });
        composerAttachments.note = '';
    }
    renderComposerAttachments();
}

function renderComposerAttachments() {
    const host = document.getElementById('chat-attachments');
    if (!host) return;
    host.replaceChildren();
    host.hidden = composerAttachments.items.length === 0 && composerAttachments.note === '';
    composerAttachments.items.forEach((item, index) => {
        const chip = document.createElement('span');
        chip.className = 'oc-attachment-chip';
        const label = document.createElement('span');
        label.textContent = item.name + ' (' + item.chars + ' chars)';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'oc-attachment-remove';
        remove.title = 'Remove this attachment';
        remove.setAttribute('aria-label', 'Remove attachment ' + item.name);
        remove.textContent = '\u00d7';
        remove.addEventListener('click', () => {
            composerAttachments.items.splice(index, 1);
            renderComposerAttachments();
        });
        chip.append(label, remove);
        host.append(chip);
    });
    if (composerAttachments.note !== '') {
        const note = document.createElement('span');
        note.className = 'oc-attachment-note';
        note.textContent = composerAttachments.note;
        host.append(note);
    }
}

function attachmentsForSubmit() {
    return composerAttachments.items.map((item) => ({ name: item.name, content: item.content }));
}

/** The names of the files on the composer, for the query card. */
function attachmentNamesForCard() {
    return composerAttachments.items.map((item) => item.name);
}

function clearComposerAttachments() {
    composerAttachments.items = [];
    composerAttachments.note = '';
    renderComposerAttachments();
}

async function submitQueryJobMode(query) {
    // Tear down any prior run before enqueuing a new one — guarantees
    // single-stream invariant just like the legacy flow.
    closeActiveRun('superseded');

    const selected = modelSelector ? modelSelector.selected() : [];
    const body = { query };
    if (selected.length > 0) body.selected_models = selected;
    body.reasoning = getReasoning();
    const consensusSkill = getConsensusSkill();
    if (consensusSkill) body.consensus_skill = consensusSkill;
    const cycleLimit = getCycleLimit();
    if (cycleLimit != null) body.cycle_limit = cycleLimit;
    // Single-model mode is refused together with the Batch API (the batch
    // executor parks the L1 fan-out at the provider; a one-call run has nothing
    // to park), so the composer keeps them mutually exclusive.
    if (getSingleModelMode()) body.single_model = true;
    // v1.9.4 - attachments ride in the job payload; the worker hands them
    // to every model call. The Batch API carries them too: its L1 planner
    // builds items through the same message builder, and the continuation
    // stages read the same options back.
    const attachments = attachmentsForSubmit();
    if (attachments.length > 0) {
        body.attachments = attachments;
    }
    const clientRunId = 'crun_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
    body.client_run_id = clientRunId;
    // The Batch API is a routing decision made at enqueue time: the server
    // stores it in the job payload (`kind = batch_pipeline`), which decides
    // both which executor runs the job and how it reports progress. The
    // synchronous request shape is untouched for every other run.
    const runMode = getRunMode();
    if (runMode === 'batch') body.run_mode = 'batch';

    logger.log('jobs.create.request', { client_run_id: clientRunId, selected_count: selected.length, reasoning: body.reasoning, cycle_limit: cycleLimit, consensus_skill: consensusSkill });

    let created = null;
    let postResponse = null;
    try {
        const { response, data } = await logger_fetchJsonOrSurface('/api/council/jobs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        postResponse = response;
        created = data;
    } catch (err) {
        logger.error('jobs.create.failed', { message: err?.message }, err);
        throw new Error('Could not enqueue job: ' + (err?.message || 'unknown error')
            + ' — import db/schema.sql and ensure the worker (revoap-worker.service) is running.');
    }
    if (!postResponse?.ok || !created?.ok || !created?.job_id) {
        const detail = created?.message || created?.error || ('HTTP ' + (postResponse?.status ?? '?'));
        logger.error('jobs.create.rejected', { detail, body: created });
        throw new Error('Job enqueue rejected: ' + detail);
    }
    // The queue holds the files now; the composer gives them up.
    clearComposerAttachments();
    const jobId = Number(created.job_id);
    const sessionId = Number(created.session_id);
    const eventsUrl = String(created.events_url || ('/api/council/session/' + sessionId + '/events/stream'));
    lastRunSessionId = sessionId;
    logger.log('jobs.create.ok', { job_id: jobId, session_id: sessionId, events_url: eventsUrl, status: created.status });

    // Render an immediate "Job queued" activity card so the user sees
    // the run starting even before the worker writes its first event.
    setStatus('Job queued (job #' + jobId + ', session #' + sessionId + ') — waiting for worker…');
    setActivity({ state: 'running', label: 'Job queued — waiting for worker…', stage: '—' });

    // A batch run keeps its own readout: the composer card shows the batch
    // phase, the provider status, the request counts, the elapsed time and
    // the 24-hour window, and offers the cancel button. It is fed by
    // GET /api/council/jobs/{id} rather than by the event stream, because
    // the interesting state (the provider's status) lives on the job row.
    if (runMode === 'batch') {
        // Seed the card from the one thing known locally — the run is queued —
        // so the operator sees the batch readout and its cancel control from
        // the first moment rather than an empty composer with no explanation
        // of why nothing is happening yet. The first job-row poll replaces it.
        renderBatchState({
            phase: 'new', phase_label: 'Queued', status: '', status_label: '',
            request_count: 0, polls: 0, completion_window_h: 24, awaiting: true,
        });
        startBatchPolling(jobId);
        setStatus('Batch submitted (job #' + jobId + ', session #' + sessionId
            + ') — results arrive asynchronously, in minutes to 24 hours.');
    }

    const runId = 'run-' + (++runCounter);
    const run = {
        es: null, runId, terminated: false, anyEventSeen: false,
        sessionId, paused: false, settle: null,
        l1Count: 0, finalizing: false,
        mode: 'job', jobId, clientRunId,
        lastEventId: 0,
        eventsUrl,
        workerWarnTimer: null,
        workerWarned: false,
        // v1.8.45 — state-probe bookkeeping for the worker hint below.
        workerProbeInFlight: false,       // ≤1 state request per interval
        workerProbeUseJobEndpoint: false, // health could not classify this job
        workerProbeState: null,           // branch rendered on the last tick
        workerCaption: null,              // branch currently on the activity card
        workerCaptionText: null,          // exact status text the probe owns (v1.8.46)
        // v1.8.46 — terminal reconciliation bookkeeping.
        terminalSettled: false,           // final state painted; stop probing
        jobTerminalStatus: null,          // job_status reported by PIPELINE_REPLAY_DONE
        closeReason: null,                // set by closeActiveRun() on a deliberate teardown
    };
    activeRun = run;
    syncControlButtons();

    // v1.8.45 — no event within the configured window is no longer read
    // as "the worker is not running". Re-evaluate the real state once per
    // interval and pick the caption from it (see workerWarnTick below);
    // the probe stops the moment the first event of this run lands.
    const warnSecs = pipelineWorkerWarningSecs;
    run.workerWarnTimer = setInterval(() => { void workerWarnTick(run, warnSecs); }, warnSecs * 1000);

    // Reconnect loop: replay endpoint sends a snapshot of events past
    // `lastEventId`, then streams new events as the worker writes them,
    // then closes with PIPELINE_REPLAY_DONE. We re-open if job_status is
    // non-terminal; we stop once it's terminal or the user terminates.
    let attempts = 0;
    while (!run.terminated) {
        attempts++;
        let stopReason = await openOneReplayConnection(run, attempts);
        if (run.terminated) break;
        // v1.8.46 — the state probe can reach a terminal verdict while the
        // replay endpoint still reports nothing (for instance when the
        // endpoint cannot resolve the job row and keeps answering
        // `job_terminal: false`). Without this the browser would reconnect
        // forever behind a card that already shows the final message.
        if (run.terminalSettled) break;
        if (stopReason === 'job_terminal') break;
        // Brief backoff before reconnecting so a flapping worker doesn't
        // spin-loop the browser. Cap at ~5s.
        const backoffMs = Math.min(5000, 250 + attempts * 250);
        await new Promise((r) => setTimeout(r, backoffMs));
    }

    if (run.workerWarnTimer) stopWorkerWarnTimer(run);

    // v1.8.46 — terminal reconciliation. The loop is over: either the job
    // reached a terminal status, or the pipeline emitted its own terminal
    // frame. If the browser never rendered the ending itself — no frames
    // at all, or frames but no terminal PIPELINE_DONE — the activity card
    // is still carrying the v1.8.45 hint ("Job #N running — no events
    // yet") next to a `running` badge, forever, because stopping the probe
    // timer was the only thing this function used to do.
    //
    // Skipped when the run was closed deliberately (Reject, a newer
    // submission, page unload): those are the user's decisions, not a job
    // that ended on its own. Also skipped when `run.terminated` is set —
    // that flag is raised by the pipeline's OWN terminal frame
    // (PIPELINE_DONE / PIPELINE_FATAL / PIPELINE_REJECTED), which already
    // rendered the ending, so repainting here would overwrite a correct
    // final answer with a "the final frame never arrived" message.
    if (!run.terminated && !run.terminalSettled && run.closeReason == null) {
        const cap = settleTerminalRun(run, run.jobTerminalStatus);
        if (cap !== null) {
            logger.log('jobs.terminal_settled.after_loop', {
                job_id: run.jobId,
                session_id: run.sessionId,
                attempts,
                branch: cap.branch,
            });
        } else if (run.jobTerminalStatus == null) {
            // The endpoint never reported a terminal status — either the
            // run was torn down, or the job row could not be resolved.
            // Leave the last honest caption alone rather than inventing a
            // verdict; the probe (if still armed) keeps the state fresh.
            logger.log('jobs.terminal_unknown', {
                job_id: run.jobId,
                session_id: run.sessionId,
                attempts,
                any_event_seen: run.anyEventSeen === true,
            });
        }
    }
}

// ---------- v1.8.45 — state-aware worker hint ----------
//
// Pre-v1.8.45 the hint shown while a job waited for the worker was a pure
// timer: 15s without an event and the UI asserted "No worker activity —
// start the worker process", without ever asking whether a worker
// existed. Any query that had to queue behind an already-running job
// therefore got an instruction that was simply false — while Settings →
// Worker, reading the same backend, correctly reported "healthy, 1
// alive, running job #8". The "start the worker" branch is only truthful
// when no worker process is alive at all (branch c); every other branch
// now says what is actually happening.

const WORKER_PROBE_HEALTH_URL = '/api/health/worker';

// The literal instruction kept byte-identical to the pre-v1.8.45 text so
// the one truthful case still reads the way operators know it.
function workerStartHint(warnSecs) {
    return 'No worker activity for ' + warnSecs + 's — start the worker process: '
        + '`php bin/revoap-worker.php --once` for a single drain or '
        + '`sudo systemctl start revoap-worker.service` for the persistent runner.';
}

function stopWorkerWarnTimer(run) {
    if (!run) return;
    if (run.workerWarnTimer != null) {
        clearInterval(run.workerWarnTimer);
        run.workerWarnTimer = null;
    }
}

// ---------- v1.8.46 — terminal reconciliation ----------
//
// A job-mode run could end while the live event stream had delivered
// NOTHING: the job row reaches `completed` (Settings → Worker flips from
// "running" to a green "idle — free"), History shows the finished session
// with its final answer, and the chat is still wearing the caption the
// v1.8.45 probe painted last — "Job #N running — no events yet" next to a
// "running" badge. Forever, because the reconnect loop simply stopped and
// nothing repainted the card.
//
// Two separate defects produced that: the probe had no terminal branch,
// and the end of the reconnect loop had no reconciliation step. The code
// below closes both. A missing event stream is NOT a pipeline failure —
// the worker did the work — so the wording never claims the task failed,
// and the result is routed to History where it actually lives.

/** Terminal `JobStatus` values (see App\Jobs\JobStatus::isTerminal). */
const TERMINAL_JOB_STATUSES = ['completed', 'failed', 'cancelled', 'canceled'];

function isTerminalJobStatus(status) {
    return TERMINAL_JOB_STATUSES.includes(String(status || '').toLowerCase());
}

// Pure caption for a run that reached a terminal job status. Returns null
// while the job is still non-terminal so the caller keeps the normal
// running/queued captions.
//
// `sawEvents` distinguishes the two ways a job-mode run can end without
// the UI having rendered a final answer:
//   * false — the live stream delivered nothing at all. This is the case
//     the v1.8.46 fix targets: the worker finished the job while the
//     browser followed a session id that carried no events.
//   * true  — frames arrived but the terminal PIPELINE_DONE never did.
//
// `kind` drives the activity badge: `completed` when the job succeeded,
// `failed` when it did not. Crucially, a successful job whose stream
// delivered nothing keeps the `completed` badge — a degraded live channel
// is not a failed task, and the wording says so.
function workerTerminalCaption(run, jobStatus, sawEvents = false) {
    const status = String(jobStatus || '').toLowerCase();
    if (!isTerminalJobStatus(status)) return null;
    const jobId = run.jobId;
    const sessionId = run.sessionId;
    const sessionRef = sessionId != null ? ' (session #' + sessionId + ')' : '';
    const logHint = 'then check the worker log: `sudo journalctl -u revoap-worker.service -f -o cat`.';
    if (status === 'completed') {
        return {
            branch: sawEvents ? 'terminal-completed-partial' : 'terminal-completed',
            terminal: true,
            jobStatus: 'completed',
            kind: 'completed',
            degraded: !sawEvents,
            status: sawEvents
                ? 'Job #' + jobId + ' completed, but the stream closed before the final frame arrived. '
                    + 'The finished answer is in History' + sessionRef + '.'
                : 'Job #' + jobId + ' finished successfully, but the live event stream delivered no '
                    + 'frames — a delivery problem, not a task failure. The result is stored under '
                    + 'History' + sessionRef + '.',
            label: 'Completed — result is in History',
        };
    }
    if (status === 'failed') {
        return {
            branch: sawEvents ? 'terminal-failed-partial' : 'terminal-failed',
            terminal: true,
            jobStatus: 'failed',
            kind: 'failed',
            degraded: !sawEvents,
            status: sawEvents
                ? 'Job #' + jobId + ' failed after streaming its events. Open History' + sessionRef
                    + ' for the persisted record, then check the worker log.'
                : 'Job #' + jobId + ' failed and no event reached the live stream. Open History'
                    + sessionRef + ' for the persisted record, then ' + logHint,
            label: 'Failed — record is in History',
        };
    }
    return {
        branch: 'terminal-' + status,
        terminal: true,
        jobStatus: status,
        kind: 'failed',
        degraded: !sawEvents,
        status: 'Job #' + jobId + ' ended as "' + status + '" without a final frame reaching the live '
            + 'stream. History' + sessionRef + ' holds the persisted record.',
        label: 'Ended (' + status + ') — see History',
    };
}

// History is a real tab only when the feature is enabled server-side;
// the shell ships the button with the `hidden` attribute otherwise. We
// must not offer a control that cannot work, so the caller falls back to
// plain text when the tab is unavailable.
function historyTabButton() {
    const btn = document.getElementById('oc-tab-history');
    if (!btn || btn.hidden) return null;
    return btn;
}

// Remove the terminal-delivery banner. Called when a new run starts so the
// notice always describes the run the user is currently looking at.
function clearDeliveryNotice() {
    const old = document.getElementById('oc-delivery-notice');
    if (old) old.remove();
}

// Render the one-off banner that explains a terminal run whose final frame
// never reached the browser, with a working way into History when the tab
// exists. Idempotent: repeated calls replace the previous banner.
function renderDeliveryNotice(cap) {
    const section = document.getElementById('pipeline-section');
    const host = section?.parentNode;
    if (!host) return;
    clearDeliveryNotice();

    const card = document.createElement('div');
    card.id = 'oc-delivery-notice';
    card.className = 'oc-delivery-notice';
    card.dataset.state = cap.kind;

    const title = document.createElement('h3');
    title.textContent = cap.kind === 'completed'
        ? 'Result available in History'
        : 'Run ended — record in History';
    card.append(title);

    const text = document.createElement('p');
    text.textContent = cap.status;
    card.append(text);

    const tab = historyTabButton();
    if (tab) {
        const go = document.createElement('button');
        go.type = 'button';
        go.id = 'oc-delivery-notice-history';
        go.className = 'oc-button-ghost oc-delivery-notice-action';
        go.textContent = 'Open History';
        go.addEventListener('click', () => {
            try { tab.click(); } catch { /* tab wiring is best-effort */ }
        });
        card.append(go);
    } else {
        const hint = document.createElement('p');
        hint.className = 'oc-muted oc-small';
        hint.textContent = 'The History tab is disabled on this deployment — read the session through '
            + 'the API instead: GET /api/council/session/<id>.';
        card.append(hint);
    }

    host.insertBefore(card, section);
}

// The status line as it currently reads, or null when there is no line.
function currentStatusText() {
    const status = document.getElementById('chat-status');
    return status ? status.textContent : null;
}

// v1.8.46 — retire the probe's caption the moment the run proves it is
// alive. The probe only ever wrote a "waiting / no events" line, so once a
// real frame arrives that line is stale by definition. Guarded by an exact
// text comparison: if anything else has claimed the status line in the
// meantime (a pause notice, an error, a terminal verdict) it is left alone.
function clearStaleWorkerCaption(run) {
    if (!run || run.workerCaptionText == null) return;
    if (currentStatusText() !== run.workerCaptionText) {
        run.workerCaptionText = null;
        return;
    }
    run.workerCaptionText = null;
    run.workerCaption = null;
    run.workerProbeState = null;
    setStatus('Job #' + run.jobId + ' is running — live events are streaming in.');
}

// Apply the terminal state to BOTH surfaces the user is looking at — the
// status line and the activity card — so the pair can never disagree
// again. `run` is mutated so the probe timer and any later frame stop
// touching the card.
//
// Returns null when there is nothing to finalise: the job is not terminal
// yet, or the caller deliberately tore the run down.
function settleTerminalRun(run, jobStatus) {
    if (run.terminalSettled || run.closeReason != null) return null;
    // `anyEventSeen` is read once, at settle time: it decides between the
    // "delivery degraded" and "stream closed early" wordings.
    const cap = workerTerminalCaption(run, jobStatus, run.anyEventSeen === true);
    if (cap === null) return null;
    stopWorkerWarnTimer(run);
    // A terminal verdict ends the diagnosis. The probe checks this flag
    // instead of `anyEventSeen` so the "did any frame arrive?" answer the
    // rest of the file relies on stays truthful.
    run.terminalSettled = true;
    run.workerProbeState = cap.branch;
    run.workerCaption = cap.branch;
    run.workerCaptionText = null;
    setStatus(cap.status);
    setActivity({ state: cap.kind, label: cap.label, stage: 'Σ' });
    renderDeliveryNotice(cap);
    logger.warn('jobs.terminal_settled', {
        job_id: run.jobId,
        session_id: run.sessionId,
        job_status: cap.jobStatus,
        branch: cap.branch,
        any_event_seen: run.anyEventSeen === true,
        delivery_degraded: cap.degraded,
    });
    return cap;
}

// Issues exactly ONE state request. `/api/health/worker` is the primary
// source: besides the pool view it names the holder of this job via
// `running_workers[].current_job_id`, which is what separates branch (a)
// from branch (b). When the snapshot cannot classify this job the next
// interval asks `GET /api/council/jobs/{id}` instead — never both.
//
// Returns { ok:false } when the request failed, so the caller can fall
// back to a neutral caption instead of inventing an instruction.
async function probeWorkerState(run, warnSecs) {
    const useJobEndpoint = run.workerProbeUseJobEndpoint === true;
    const url = useJobEndpoint
        ? '/api/council/jobs/' + encodeURIComponent(String(run.jobId))
        : WORKER_PROBE_HEALTH_URL;
    // A hung endpoint must not freeze the diagnostics forever: while the
    // request is in flight the interval guard skips further probes, so an
    // answer that never arrives would leave the last caption on screen.
    // The budget is deliberately loose (two windows) — a merely slow
    // endpoint is worth waiting for, a dead one is not.
    const budgetMs = Math.max(5000, warnSecs * 2000);
    const ctl = typeof AbortController === 'function' ? new AbortController() : null;
    const abortTimer = ctl ? setTimeout(() => { try { ctl.abort(); } catch { /* noop */ } }, budgetMs) : null;
    let response, data;
    try {
        ({ response, data } = await logger_fetchJsonOrSurface(url, {
            headers: { Accept: 'application/json' },
            ...(ctl ? { signal: ctl.signal } : {}),
        }));
    } catch (err) {
        logger.warn('jobs.worker_probe_failed', {
            job_id: run.jobId, session_id: run.sessionId, url,
            message: err?.message, after_ms: budgetMs,
        });
        return { ok: false, source: useJobEndpoint ? 'job' : 'health' };
    } finally {
        if (abortTimer) clearTimeout(abortTimer);
    }
    // A JSON error body (5xx/404) parses fine but carries no state — treat
    // it exactly like a transport failure rather than reading zeroes from it.
    if (!response || response.ok !== true) {
        logger.warn('jobs.worker_probe_failed', {
            job_id: run.jobId, session_id: run.sessionId, url,
            message: 'HTTP ' + (response ? response.status : '?'),
        });
        return { ok: false, source: useJobEndpoint ? 'job' : 'health' };
    }
    if (useJobEndpoint) {
        const status = String(data?.job?.status || '').toLowerCase();
        // Stay on the job endpoint only while it still has no answer.
        run.workerProbeUseJobEndpoint = status === '';
        return {
            ok: true,
            source: 'job',
            jobStatus: status === '' ? null : status,
            workersAlive: null,  // unknown from this source
        };
    }
    const counts  = data?.counts || {};
    const queued  = Number(counts.queued  || 0);
    const workers = Array.isArray(data?.running_workers) ? data.running_workers : [];
    const alive   = workers.filter((w) => w && w.alive === true);
    const reported = Number(data?.workers_alive);
    const workersAlive = Number.isFinite(reported) ? reported : alive.length;
    const holder = workers.find((w) => Number(w?.current_job_id) === Number(run.jobId)) || null;
    // The worker that has to free up before this job can start.
    const busy = alive.find((w) => Number(w?.current_job_id) > 0) || null;

    let jobStatus = null;
    if (holder) jobStatus = 'running';
    else if (queued > 0) jobStatus = 'queued';
    // Otherwise the snapshot cannot say what this job is doing (a busy
    // worker that does not name it, or an empty queue) — ask the job row
    // on the next interval rather than showing a guess.
    run.workerProbeUseJobEndpoint = jobStatus === null;

    return {
        ok: true,
        source: 'health',
        jobStatus,
        workersAlive,
        queued,
        busyWorkerId: busy && busy.worker_id != null ? String(busy.worker_id) : null,
        busyJobId: busy && Number(busy.current_job_id) > 0 ? Number(busy.current_job_id) : null,
        busySessionId: busy && Number(busy.session_id) > 0 ? Number(busy.session_id) : null,
        healthStatus: data?.worker_status ?? null,
    };
}

// Pure caption selection — no DOM, no network. `run` is read only for
// jobId and for the branch of the previous tick, which decides the "this
// has been going on for a while" suffix in branch (a).
function workerWarnCaption(run, state, warnSecs) {
    const jobId = run.jobId;

    // (t) v1.8.46 — the run is already over. Pre-v1.8.46 this function
    // knew only running / queued / "unknown", so a terminal status fell
    // through to branch (d) and rendered "Job #N is queued — waiting for a
    // free worker slot" for a job that had already finished — a false
    // statement that also never stopped, because the only exits from the
    // timer were "an event arrived" or "the user closed the run".
    if (state.ok) {
        const terminalCap = workerTerminalCaption(run, state.jobStatus, run.anyEventSeen === true);
        if (terminalCap !== null) return terminalCap;
    }

    // (a) This job is the one being processed. The worker is not the
    // problem, so never suggest starting one. If the previous tick
    // reported the same thing, the state has persisted for a full extra
    // window — point at the worker log rather than repeating ourselves.
    if (state.ok && state.jobStatus === 'running') {
        let text = 'Job #' + jobId + ' is running — the worker picked it up. (No events yet.)';
        if (run.workerProbeState === 'running') {
            text += ' Still nothing in the event stream after a full window — check the worker log: '
                + '`sudo journalctl -u revoap-worker.service -f -o cat`.';
        }
        return { branch: 'running', status: text, label: 'Job #' + jobId + ' running — no events yet' };
    }

    // (b) Queued behind a live worker: it starts by itself. One process
    // handles one job at a time, so this is normal, not a fault.
    if (state.ok && state.jobStatus === 'queued' && state.workersAlive != null && state.workersAlive > 0) {
        const busy = state.busyWorkerId
            ? 'worker ' + state.busyWorkerId + ' is busy with '
                + (state.busyJobId
                    ? 'job #' + state.busyJobId
                        + (state.busySessionId ? ' (session #' + state.busySessionId + ')' : '')
                    : 'an earlier job')
            : 'a live worker is busy with an earlier job';
        return {
            branch: 'queued-busy',
            status: 'Job #' + jobId + ' is queued: ' + busy + '. It starts automatically when that worker '
                + 'frees up — one worker process handles one job at a time (add capacity with the '
                + 'revoap-worker@.service pool template).',
            label: state.busyWorkerId && state.busyJobId
                ? 'Queued behind job #' + state.busyJobId + ' on ' + state.busyWorkerId
                : 'Queued — waiting for a free worker slot',
        };
    }

    // (c) Queued with no live worker at all — the only case in which the
    // "start the worker" instruction is the truth. Wording unchanged.
    if (state.ok && state.jobStatus === 'queued' && state.workersAlive === 0) {
        return {
            branch: 'queued-idle',
            status: 'Job queued — waiting for worker… ' + workerStartHint(warnSecs),
            label: 'Waiting for worker — start revoap-worker.service',
        };
    }

    // (d) State unknown: probe failed, or the snapshot could not classify
    // this job. Report the wait and instruct nothing.
    return {
        branch: 'unknown',
        status: 'Job #' + jobId + ' is queued — waiting for a free worker slot.',
        label: 'Waiting for a free worker slot',
    };
}

// One interval of the hint: probe, then repaint from the real state.
async function workerWarnTick(run, warnSecs) {
    // v1.8.46 — `terminalSettled` is the new exit: once the terminal
    // reconciliation has run, probing is pointless and the interval must
    // stop instead of asking /api/health/worker forever.
    if (!run || run.terminated || run.terminalSettled || run.anyEventSeen) { stopWorkerWarnTimer(run); return; }
    if (run.workerProbeInFlight) return;  // at most one request per interval
    run.workerProbeInFlight = true;
    let state;
    try {
        state = await probeWorkerState(run, warnSecs);
    } finally {
        run.workerProbeInFlight = false;
    }
    // The probe can resolve after the run closed, after the worker finally
    // emitted, or after a newer run superseded this one — never repaint
    // the interface over any of those.
    if (run.terminated || run.terminalSettled || run.anyEventSeen || activeRun !== run) return;

    const cap = workerWarnCaption(run, state, warnSecs);
    // v1.8.46 — the probe reached a terminal verdict while the live
    // stream stayed silent. Settle the whole run (status + activity card
    // + History route) and stop; `settleTerminalRun` owns the
    // logging so the branch is recorded exactly once.
    if (cap.terminal) {
        settleTerminalRun(run, cap.jobStatus);
        return;
    }
    const branchChanged = run.workerProbeState !== cap.branch;
    run.workerProbeState = cap.branch;
    run.workerWarned = true;
    if (branchChanged) {
        // Kept as `jobs.worker_idle` for continuity, but the payload now
        // carries the measured state — otherwise the console log keeps
        // claiming "idle" while a worker is demonstrably busy.
        logger.warn('jobs.worker_idle', {
            job_id: run.jobId,
            session_id: run.sessionId,
            seconds: warnSecs,
            branch: cap.branch,
            probe_ok: state.ok === true,
            probe_source: state.source ?? null,
            workers_alive: state.workersAlive ?? null,
            job_status: state.jobStatus ?? null,
            worker_status: state.healthStatus ?? null,
            busy_with_job_id: state.busyJobId ?? null,
            busy_worker_id: state.busyWorkerId ?? null,
        });
    }
    setStatus(cap.status);
    // Remember the exact text this probe owns, so the first real event can
    // retire it precisely (see clearStaleWorkerCaption) without touching a
    // status line that someone else has since claimed.
    run.workerCaptionText = cap.status;
    // Repaint the activity card only when the state actually changed, so
    // queued → running and busy → free are reflected without churning the
    // card on every tick of an unchanged state.
    if (run.workerCaption !== cap.branch) {
        setActivity({ state: 'running', label: cap.label, stage: '—' });
        run.workerCaption = cap.branch;
    }
}

// Opens a single EventSource window to the replay endpoint. Resolves
// when the connection terminates with one of:
//   'job_terminal' — PIPELINE_REPLAY_DONE reported a terminal status
//   'reconnect'    — non-terminal status or transient drop; reopen
//   'closed'       — caller-driven close (reject/superseded/unload)
function openOneReplayConnection(run, attempt) {
    return new Promise((resolve) => {
        let settled = false;
        const settle = (reason) => { if (!settled) { settled = true; resolve(reason); } };
        run.settle = settle;

        const url = run.eventsUrl
            + (run.eventsUrl.includes('?') ? '&' : '?')
            + 'live=1'
            + (run.lastEventId > 0 ? '&after_event_id=' + encodeURIComponent(String(run.lastEventId)) : '');
        let es;
        try {
            es = new EventSource(url);
        } catch (err) {
            logger.error('jobs.sse.open_failed', { url, message: err?.message }, err);
            settle('reconnect');
            return;
        }
        run.es = es;
        logger.sse('open', { runId: run.runId, mode: 'job', attempt, url, lastEventId: run.lastEventId });

        const handleEventRow = (e, fallbackType) => {
        // v1.9.x - the final answer arrives in pieces while it is generated.
        // Handled before the type switch: a delta is a partial of an event the
        // switch already knows how to finish, and it must not disturb it.
        if (fallbackType === 'L4_SYNTHESIS_DELTA') {
            try {
                const deltaRow = JSON.parse(e.data);
                renderFinalAnswerDelta(deltaRow && deltaRow.text);
            } catch { /* a broken delta must not stop the stream */ }
            return;
        }
            if (run.terminated) return;
            let row;
            try { row = JSON.parse(e.data); }
            catch (err) {
                logger.error('jobs.sse.parse_failed', { runId: run.runId, raw_len: typeof e.data === 'string' ? e.data.length : 0 }, err);
                return;
            }
            const eventId = Number(row?.event_id || e?.lastEventId || 0);
            if (Number.isFinite(eventId) && eventId > run.lastEventId) {
                run.lastEventId = eventId;
            }
            const type = String(e.type || fallbackType || (row?.event_type) || 'UNKNOWN');
            // Replay frames wrap the original payload under .payload.
            const evt = {
                type,
                step_id: row?.step_id ?? null,
                payload: (row && typeof row.payload === 'object' && row.payload !== null) ? row.payload : {},
            };
            const firstEventOfRun = run.anyEventSeen !== true;
            run.anyEventSeen = true;
            lastRunAnyEventSeen = true;
            // v1.8.45 — the run is alive and producing output; the state
            // probe has nothing left to diagnose.
            stopWorkerWarnTimer(run);
            // v1.8.46 — and if the probe had already painted a wait
            // caption, that caption is now FALSE and must go immediately:
            // L1 model calls routinely take longer than the warning window,
            // so "no events yet" would otherwise sit above a pipeline that
            // is visibly streaming until the terminal frame lands.
            if (firstEventOfRun) { clearStaleWorkerCaption(run); }
            logger.pipelineEvent(evt);
            try { processJobReplayEvent(run, evt); }
            catch (err) { logger.error('jobs.sse.handler_failed', { type }, err); }
        };

        // Bind handlers for every event type the worker writes. EventSource
        // only fires named handlers for `event: <NAME>` frames, so we
        // attach a generic catch-all via `message` as a defensive
        // fallback for clients/proxies that strip the event field.
        //
        // v1.8.46 — this list has to MIRROR the engine's emitters. The
        // replay endpoint writes an explicit `event: <TYPE>` line for every
        // row, and per the EventSource spec `onmessage` fires only for
        // frames WITHOUT an event field — so a type missing here is dropped
        // silently, with no error and no `anyEventSeen`. Eleven types the
        // engine emits (the arbiter / main-model / repair / round-progress
        // surface, plus PIPELINE_STARTED and REQUEST_ANALYZER) were in that
        // gap; they are collected in the second block below.
        // Verified against PipelineEngine::emitEvent() call sites.
        const knownTypes = [
            'L0_OPTIONS','L0_SKILL','L0_SYSTEM_PROMPT',
            'L1_DISPATCH','L1_STEP_STARTED','L1_RESPONSE','L1_FAILED','L1_NO_AVAILABLE_MODELS','L1_NO_RESULTS',
            // Batch API L1: the submission of each participant, and the two ways the
            // batch stage itself can fail to produce anything.
            'BATCH_L1_STEP_SUBMITTED','BATCH_L1_NO_RESULTS','BATCH_L1_NO_AVAILABLE_MODELS',
            'L2_NORMALIZED','L3_CONSENSUS','L4_SYNTHESIS','L4_FAILED',
            'CONSENSUS_ROUND_STARTED','CONSENSUS_CYCLE_LIMIT_REACHED','CONSENSUS_CYCLE_LIMIT_CLAMPED',
            'CONSENSUS_STAGNATION_DETECTED','CONSENSUS_MANUAL_FINALIZE_REQUESTED','CONSENSUS_MANUAL_FINALIZE_STARTED',
            'CONSENSUS_LOOP_GUARD_TRIGGERED',
            'PIPELINE_PAUSED','PIPELINE_CONTINUED','PIPELINE_FINALIZING','PIPELINE_REJECTED',
            'PIPELINE_DONE','PIPELINE_FATAL','PIPELINE_DUPLICATE_RUN_REJECTED',
            'AUTO_ROUTING_NOTICE','LOOP_DETECTED',
            // v1.9.x - the Automatic Skill Selector's decision. The engine has
            // emitted it since v1.9.5, but the type was never bound here: the
            // replay endpoint writes an explicit `event:` line per frame, and
            // EventSource drops a frame whose type has no named listener, so the
            // decision reached the browser and vanished in silence.
            'SKILL_AUTO_SELECTED',
            // v1.8.46 — previously unbound engine types (frames were dropped).
            'PIPELINE_STARTED','REQUEST_ANALYZER',
            'CONSENSUS_ROUND_COMPLETED','CONSENSUS_ROUND_REPEAT',
            'L1_REPAIR_DISPATCH',
            'L4_ARBITER_STARTED','L4_ARBITER_FAILED',
            'L4_MAIN_MODEL_SYNTHESIS_STARTED','L4_MAIN_MODEL_SYNTHESIS_FAILED','L4_MAIN_MODEL_ATTEMPT_FAILED',
            'L4_PROVIDER_FALLBACK',
            // v1.8.60 — the preflight gate and the L4 short-circuit. A
            // preflight refusal is a terminal outcome the operator has to be
            // able to read in the stream, and L4_SKIPPED is what replaces the
            // old "404 at the end of a doomed run".
            'PIPELINE_PREFLIGHT','PIPELINE_PREFLIGHT_FAILED','L4_SKIPPED',
            // v1.9.x - pieces of the final answer, while it is generated.
            'L4_SYNTHESIS_DELTA',
            // v1.9.x - a model dropped by the breaker and its stand-in.
            'MODEL_SUBSTITUTED',
            // v1.9.x - single-model mode: the mode announcement, the notice that
            // the database cannot carry its verdict (so the consensus pipeline
            // ran instead), and the answer to a finalize request that arrived
            // after the one call had already produced the final answer.
            'SINGLE_MODEL_MODE','SINGLE_MODEL_MODE_UNAVAILABLE','SINGLE_MODEL_FINALIZE_IGNORED',
        ];
        for (const t of knownTypes) {
            es.addEventListener(t, (e) => handleEventRow(e, t));
        }
        es.addEventListener('message', (e) => handleEventRow(e, 'MESSAGE'));

        es.addEventListener('PIPELINE_REPLAY_DONE', (e) => {
            let row = null;
            try { row = JSON.parse(e.data); } catch { /* noop */ }
            const terminal = !!(row && row.job_terminal);
            const status = row?.job_status || null;
            // v1.8.46 — remember the terminal status the endpoint reported
            // so the reconnect loop can finalise a run that never received
            // a single pipeline frame (see settleTerminalRun).
            if (terminal && status) { run.jobTerminalStatus = String(status); }
            logger.sse('replay_done', { runId: run.runId, mode: 'job', terminal, status, last_event_id: run.lastEventId });
            try { es.close(); } catch { /* noop */ }
            settle(terminal ? 'job_terminal' : 'reconnect');
        });

        es.addEventListener('error', () => {
            if (settled) return;
            logger.sse('error', { runId: run.runId, mode: 'job', readyState: es.readyState, attempt });
            // Browser will auto-reconnect on its own when CONNECTING.
            // We only act on CLOSED.
            if (es.readyState === EventSource.CLOSED) {
                settle('reconnect');
            }
        });
    });
}

// Process one event coming from the replay endpoint. Mirrors the live-
// stream handler from the legacy flow but always treats SSE drops as
// "reconnect and resume" rather than "pipeline failed".
function processJobReplayEvent(run, evt) {
    // Capture session id if missing (the replay endpoint already carries
    // it via the URL, but PIPELINE_DONE payloads also include it).
    const sid = evt?.payload?.session_id;
    if (run.sessionId == null && Number.isFinite(Number(sid))) {
        run.sessionId = Number(sid);
        syncControlButtons();
    }
    if (run.sessionId != null) lastRunSessionId = Number(run.sessionId);

    if (typeof evt.type === 'string'
        && (evt.type.startsWith('L4_')
            || evt.type === 'CONSENSUS_CYCLE_LIMIT_REACHED'
            || evt.type === 'CONSENSUS_STAGNATION_DETECTED'
            || evt.type === 'CONSENSUS_MANUAL_FINALIZE_REQUESTED'
            || evt.type === 'CONSENSUS_MANUAL_FINALIZE_STARTED'
            || evt.type === 'PIPELINE_FINALIZING')) {
        lastRunL4OrTerminalSeen = true;
    }
    if (evt.type === 'PIPELINE_PAUSED') {
        run.paused = true;
        syncControlButtons();
        setStatus('Pipeline paused — click Continue to resume.');
        setActivity({ state: 'paused', label: 'Paused — awaiting continue', stage: evt.payload?.stage ?? '—' });
    }
    if (evt.type === 'PIPELINE_CONTINUED') {
        run.paused = false;
        syncControlButtons();
        setStatus('Pipeline resumed.');
        setActivity({ state: 'running', label: 'Pipeline resumed', stage: evt.payload?.stage ?? '—' });
    }
    if (evt.type === 'L1_RESPONSE') {
        run.l1Count = (run.l1Count || 0) + 1;
        syncControlButtons();
    }
    if (evt.type === 'CONSENSUS_MANUAL_FINALIZE_REQUESTED'
        || evt.type === 'CONSENSUS_MANUAL_FINALIZE_STARTED'
        || evt.type === 'PIPELINE_FINALIZING') {
        run.finalizing = true;
        syncControlButtons();
        setStatus('Stop and Run consensus accepted — synthesising final answer from collected results…');
        setActivity({ state: 'running', label: 'Finalising — synthesising from collected results', stage: '—' });
    }
    if (evt.type === 'AUTO_ROUTING_NOTICE') {
        try { renderAutoRoutingNotice(evt.payload || {}); } catch { /* non-fatal */ }
    }
    // v1.9.x - the Automatic Skill Selector's decision. Boundary: this is a
    // `skill` decision the server made for this run, NOT a chat message - the
    // event names the skill that was picked or built, and no model text
    // travels in it.
    if (evt.type === 'SKILL_AUTO_SELECTED') {
        try {
            const p = evt.payload || {};
            const what = p.created === true ? 'built a new skill' : 'picked an existing skill';
            setStatus('Auto skill selector ' + what + ': ' + String(p.skill || '?')
                + (p.role ? ' (lead role: ' + String(p.role) + ')' : '') + ' — this run follows its council.');
        } catch { /* an announcement must never break the stream */ }
    }
    handleStreamedEvent(evt);
    if (evt.type === 'PIPELINE_DONE' || evt.type === 'PIPELINE_FATAL' || evt.type === 'PIPELINE_REJECTED') {
        if (evt.type === 'PIPELINE_DONE' && evt.payload?.stats) {
            renderSessionStats(evt.payload.stats);
        }
        if (evt.type === 'PIPELINE_FATAL') {
            setStatus('Error: ' + (evt.payload?.message ?? 'pipeline failed'));
        }
        if (evt.type === 'PIPELINE_REJECTED') {
            setActivity({ state: 'failed', label: 'Pipeline rejected by user' });
            setStatus('Pipeline rejected.');
        }
        // Terminal pipeline event — close the local run; the next
        // PIPELINE_REPLAY_DONE will carry job_terminal=true.
        run.terminated = true;
        try { run.es?.close(); } catch { /* noop */ }
        stopWorkerWarnTimer(run);
        if (typeof run.settle === 'function') run.settle('job_terminal');
    }
}

// `inputEl` is the composer the query came from. It is passed in (not
// looked up here) so the field the user actually typed into is the one that
// gets emptied, and the one that gets it back when the submission never
// reaches the pipeline.
async function submitQuery(query, inputEl = null) {
    const button = document.getElementById('chat-submit');
    if (button) button.disabled = true;
    setStatus('Pipeline running…');
    clearPipeline();
    // v1.9.0 — the question itself opens the stream. The audit trail used to
    // start at L0 (skill resolution), so the page never said what had been
    // asked; the card is rendered before the activity tail so it stays the
    // first entry of the run and is cleared with it by the next
    // clearPipeline().
    renderQueryCard(query, queryParticipantsInfo(), attachmentNamesForCard());
    // R10 - ask the server what this run is likely to cost and take, and
    // whether the batch queue suits it. Best-effort on purpose: advice that
    // cannot be fetched is silence, never a guess rendered in its place.
    void fetch('/api/council/economy/advice', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            selected_models: queryParticipantsInfo().models,
            run_mode: getRunMode(),
            interactive: true,
            cycle_limit: getCycleLimit(),
            batch_ready: batchCapability && batchCapability.ready === true,
        }),
    })
        .then((r) => (r.ok ? r.json() : null))
        .then((data) => { if (data && data.ok === true) updateQueryEconomy(data); })
        .catch(() => {});
    // The question now lives in the stream, so the composer is emptied: a
    // leftover question next to a finished run invites a duplicate
    // submission. restoreQueryInput() puts it back if the request never
    // reached the backend — the text is only ever held by the browser until
    // an event proves the run started, never destroyed.
    if (inputEl) inputEl.value = '';
    // v1.8.46 — drop the previous run's "result is in History" banner so a
    // new submission never starts under a stale terminal notice.
    clearDeliveryNotice();
    // Reset share state when starting a new pipeline.
    lastCompletedSessionId = null;
    if (shareBtn) shareBtn.disabled = true;
    const shareResultBox = document.getElementById('chat-share-result');
    if (shareResultBox) { shareResultBox.hidden = true; shareResultBox.replaceChildren(); }
    startActivityTimer();
    setActivity({ state: 'running', label: 'Starting pipeline…', stage: '—' });
    // v1.8.22 — reset the persistent "any event seen" flag at the start
    // of every new submission. The flag is consumed by the catch handler
    // below to decide whether the fallback /api/council/run path is safe.
    lastRunAnyEventSeen = false;
    lastRunSessionId = null;
    lastRunL4OrTerminalSeen = false;
    try {
        // v1.9.3 - attachments cannot ride the legacy GET stream, so a run
        // that carries files is always sent through the job worker.
        const attachmentsQueued = attachmentsForSubmit().length > 0;
        if (attachmentsQueued && pipelineExecutionMode !== 'job') {
            setStatus('Attachments require the worker mode; this run is sent as a job.');
        }
        if (pipelineExecutionMode === 'job' || attachmentsQueued) {
            // v1.8.37 — Default job-mode: enqueue the durable job and
            // follow the replay endpoint. The Phase 2 worker
            // (bin/revoap-worker.php) dispatches the LLM pipeline
            // outside of PHP-FPM, so the browser is no longer coupled
            // to the LLM runtime.
            await submitQueryJobMode(query);
        } else if (typeof EventSource === 'function') {
            const result = await startSse(query);
            // If the SSE stream finished WITHOUT producing any event,
            // the server probably 404s or buffers the stream — only
            // then is it safe to fall back to the synchronous endpoint.
            // A run torn down via Reject (reason='rejected') must NOT
            // re-execute via fallbackSync — the user just cancelled it.
            if (!result?.anyEventSeen && result?.reason !== 'rejected'
                && result?.reason !== 'superseded' && result?.reason !== 'unload'
                && result?.reason !== 'pagehide') {
                await fallbackSync(query);
                setActivity({ state: 'completed', label: 'Pipeline completed', stage: 'Σ' });
                setStatus('Pipeline completed.');
            }
        } else {
            await fallbackSync(query);
            setActivity({ state: 'completed', label: 'Pipeline completed', stage: 'Σ' });
            setStatus('Pipeline completed.');
        }
    } catch (e) {
        // v1.8.22 — `activeRun` is typically cleared by closeActiveRun()
        // before this catch runs, so `activeRun?.anyEventSeen` evaluated
        // to undefined and the code happily fell back to /api/council/run
        // even after the SSE stream had returned partial results. The
        // backend, still mid-pipeline on that client_run_id, often
        // returned HTML (a stale index page from a path miss, or a 500
        // error page) — `res.json()` then exploded with
        // "Unexpected token '<', '<!DOCTYPE...'" inside the chat status.
        // The fix: use a module-scope `lastRunAnyEventSeen` flag that
        // remembers whether ANY pipeline frame had landed before the
        // stream terminated, so we never re-execute a pipeline whose
        // partial output the user is already looking at.
        const anyEventSeen = !!(activeRun?.anyEventSeen) || lastRunAnyEventSeen;
        logger.error('pipeline.run_error', {
            message: e?.message,
            error_name: e?.name,
            anyEventSeen,
            non_json: e?.name === 'NonJsonResponseError',
            non_json_path: e?.url,
            non_json_status: e?.status,
            non_json_content_type: e?.contentType,
        }, e);
        if (anyEventSeen) {
            // v1.8.23 — try to recover the final answer from the persisted
            // session row before declaring failure. ignore_user_abort(true)
            // on the backend keeps the engine running after a browser
            // drop, so the session usually carries final_answer by the
            // time we poll. We only attempt recovery when we know the
            // run had at least started L4 (or was about to) — otherwise
            // the session row will not yet contain a final answer and
            // we'd just print a "completed" status from a stale row.
            const sid = activeRun?.sessionId ?? lastRunSessionId;
            if (sid != null && Number.isFinite(Number(sid)) && lastRunL4OrTerminalSeen) {
                const recovered = await recoverSessionAfterStreamDrop(Number(sid), e?.message || 'unknown error');
                if (recovered) {
                    return; // success — finally block still runs
                }
            }
            // v1.8.22 — flip the spinner on any L1 cards that never
            // received a terminal frame so the user does not see ghost
            // "Awaiting model response…" entries forever.
            const flipped = markStaleRunningStepsFailed(
                'Pipeline stream closed before this step finished: ' + (e?.message || 'unknown error')
            );
            logger.log('pipeline.stream_closed', { flipped_running: flipped, message: e?.message });
            setActivity({ state: 'failed', label: 'Stream closed: ' + (e?.message || 'unknown error') });
            setStatus('Stream closed: ' + (e?.message || 'unknown error'));
        } else if (pipelineExecutionMode === 'job') {
            // v1.8.37 — In job mode the worker is the source of truth.
            // The /api/council/run fallback would launch a second
            // (in-request) pipeline run for a query the worker is
            // already processing, so we surface the SSE error without
            // re-executing.
            const composed = composeErrorMessage(e, null);
            logger.error('pipeline.job_mode.surface_error', {
                message: composed,
                sse_error: e?.name,
            }, e);
            setActivity({ state: 'failed', label: composed });
            // No pipeline frame ever arrived: the backend has nothing, so
            // the question goes back to the composer.
            setStatus(composed + restoreQueryNote(inputEl, query));
        } else {
            try {
                await fallbackSync(query);
                setActivity({ state: 'completed', label: 'Pipeline completed', stage: 'Σ' });
                setStatus('Pipeline completed.');
            } catch (e2) {
                // Compose a clear, actionable error message. If the
                // fallback path itself failed because the server emitted
                // HTML for /api/council/run, surface that path + status
                // so the operator does not have to guess.
                const composed = composeErrorMessage(e2, e);
                logger.error('pipeline.fallback_failed', {
                    message: composed,
                    fallback_error: e2?.name,
                    fallback_status: e2?.status,
                    fallback_content_type: e2?.contentType,
                    sse_error: e?.name,
                }, e2);
                setActivity({ state: 'failed', label: composed });
                setStatus(composed + restoreQueryNote(inputEl, query));
            }
        }
    } finally {
        // Make absolutely sure no stream is left open after a run.
        closeActiveRun('done');
        stopActivityTimer();
        if (button) button.disabled = false;
    }
}

// v1.8.23 — poll /api/council/session/{id} after an SSE drop and render
// the persisted final answer if the backend completed the pipeline. With
// ignore_user_abort(true) on the SSE controller the worker keeps running
// after the browser drops; this poller covers the gap between the drop
// and PIPELINE_DONE landing on the session row.
//
// Strategy: at most 6 attempts spaced by an increasing delay (~1s, 1.5s,
// 2s, 2.5s, 3s, 3.5s — ~13.5s total) before giving up. Returns true when
// final_answer was rendered, false when the session is still mid-flight
// or recovery itself failed.
async function recoverSessionAfterStreamDrop(sessionId, streamMessage) {
    if (!Number.isFinite(Number(sessionId)) || Number(sessionId) <= 0) return false;
    const delays = [1000, 1500, 2000, 2500, 3000, 3500];
    setActivity({ state: 'running', label: 'Stream dropped — recovering final answer…', stage: '—' });
    setStatus('Stream dropped — checking server for final answer…');
    for (let i = 0; i < delays.length; i++) {
        await new Promise((r) => setTimeout(r, delays[i]));
        let data = null;
        try {
            const { response, data: body } = await logger_fetchJsonOrSurface(
                '/api/council/session/' + encodeURIComponent(String(sessionId))
            );
            if (!response.ok) {
                continue;
            }
            data = body;
        } catch (err) {
            logger.log('pipeline.recover.attempt_failed', {
                attempt: i + 1,
                session_id: sessionId,
                message: err?.message,
            });
            continue;
        }
        if (!data) continue;
        const sessionRow = data.session || {};
        const completed = !!data.completed
            || (sessionRow.status === 'completed' && data.final_answer);
        if (!completed) {
            logger.log('pipeline.recover.pending', {
                attempt: i + 1,
                session_id: sessionId,
                status: sessionRow.status,
            });
            continue;
        }
        // Final answer is available — render it and synthesis stats.
        const synth = data.synthesis_stats || {};
        try {
            renderFinalAnswer({
                text: data.final_answer || '',
                stats: statsFromMeta({
                    ...synth,
                    latency_ms: synth.latency_ms ?? sessionRow.duration_ms ?? 0,
                }, 'completed'),
                raw: { consensus: data.consensus, synthesis_stats: synth, recovered: true },
            });
        } catch (renderErr) {
            logger.error('pipeline.recover.render_failed', { message: renderErr?.message }, renderErr);
        }
        lastCompletedSessionId = Number(sessionId);
        if (shareBtn) shareBtn.disabled = false;
        markStaleRunningStepsFailed(
            'Pipeline stream closed at L4; final answer recovered from server session state.'
        );
        setActivity({
            state: 'completed',
            label: 'Pipeline completed (recovered after stream drop)',
            stage: 'Σ',
        });
        setStatus('Final answer recovered after stream drop. Original SSE error: ' + (streamMessage || 'unknown'));
        logger.log('pipeline.recover.success', {
            attempt: i + 1,
            session_id: sessionId,
            chars: (data.final_answer || '').length,
        });
        return true;
    }
    logger.log('pipeline.recover.gave_up', { session_id: sessionId, message: streamMessage });
    return false;
}

// Build a user-facing error message that prefers the more recent error,
// but pulls in non-JSON response context so the chat status shows the
// path/status/content-type when the server returned HTML.
function composeErrorMessage(primary, fallback) {
    const e = primary || fallback;
    if (!e) return 'pipeline failed';
    if (e.name === 'NonJsonResponseError') {
        return 'Server returned ' + (e.contentType || 'non-JSON') + ' (HTTP ' + e.status + ') from '
            + (e.url || 'API') + '. The pipeline backend likely returned an HTML error page; check '
            + 'the browser console for the response preview.';
    }
    return 'Error: ' + (e.message || (fallback && fallback.message) || 'pipeline failed');
}

// v1.9.0 — put the question back in the composer when a submission never
// reached the pipeline: an enqueue rejected by /api/council/jobs, a 500 from
// the legacy stream, a non-JSON error page. submitQuery() empties the field
// on submit (the question then lives in the stream), and this is the only
// path that gives it back, so nothing the user typed is destroyed by a
// failed request.
//
// Deliberately NOT applied when the stream carried events: `anyEventSeen`
// means the backend did receive the query and the run is (or was) in
// flight, so refilling the field would invite a duplicate submission of a
// query that is already in a job row.
//
// A field the user has started typing into again is left alone: their new
// text wins over a restore.
function restoreQueryInput(inputEl, query) {
    if (!inputEl || typeof query !== 'string' || query === '') return false;
    if ((inputEl.value ?? '') !== '') return false;
    inputEl.value = query;
    try {
        inputEl.focus();
        if (typeof inputEl.setSelectionRange === 'function') {
            inputEl.setSelectionRange(query.length, query.length);
        }
    } catch { /* focus and caret are best-effort: the text is already back */ }
    return true;
}

// Sentence appended to a failure status, so the reason the field refilled
// itself is stated rather than left to be noticed.
function restoreQueryNote(inputEl, query) {
    return restoreQueryInput(inputEl, query)
        ? ' Your question was put back in the input field.'
        : '';
}

function init() {
    if (window.__ocAppInitDone) return;
    window.__ocAppInitDone = true;
    loadConnectorsAndCatalog();
    loadConsensusSkills();
    // v1.8.37 — refresh the execution mode in the background so a
    // runtime.json edit can flip job/legacy without a full reload. The
    // <meta> tag baked into the layout already drives the initial value.
    refreshPipelineMode();
    const form = document.getElementById('chat-form');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const ta = document.getElementById('chat-input');
            const q  = (ta?.value ?? '').trim();
            // The composer is handed over with the query: submitQuery()
            // empties it (the question moves into the stream) and refills it
            // if the request never left the browser.
            if (q) submitQuery(q, ta);
        });
    }
    // v1.9.3 - the attachment picker rides with the same composer.
    initAttachments();
    const skillSel = document.getElementById('consensus-skill-select');
    if (skillSel) skillSel.addEventListener('change', applyConsensusSkillBinding);
    const batchBox = document.getElementById('run-mode-batch');
    if (batchBox) {
        batchBox.addEventListener('change', () => {
            const on = batchBox.checked;
            // The model pool follows the mode: a `:batch` id is refused while
            // the run is synchronous and selectable while it is not. Without
            // this the sidebar would keep refusing the very ids batch mode
            // exists for.
            if (modelSelector) { modelSelector.setBatchMode(on); }
            // Single-model mode has nothing to park at the provider, so the two
            // are mutually exclusive by design: switch the other one off rather
            // than letting the submit fail with a 400.
            if (on) { setSingleModelMode(false); }
            // The toggle changes one thing about the next run, so say so in
            // the composer's own status line rather than in a dialog.
            setStatus(on
                ? 'Batch mode: this run is submitted to the provider Batch API. Results arrive '
                    + 'asynchronously — usually minutes, up to 24 hours — and the consensus stages run '
                    + 'when they do.'
                : 'Live mode: L1 will call the models directly.');
        });
    }
    const singleBox = document.getElementById('run-mode-single');
    if (singleBox) {
        singleBox.addEventListener('change', () => {
            const on = singleBox.checked;
            if (on) {
                // The batch toggle keeps its own state, so clear it explicitly
                // and reset the pool mode that follows it.
                if (batchBox && batchBox.checked) {
                    batchBox.checked = false;
                    if (modelSelector) { modelSelector.setBatchMode(false); }
                }
                const cycleInput = document.getElementById('cycle-limit-input');
                if (cycleInput) { cycleInput.disabled = true; }
                setStatus('Single-model mode: ONE model answers with this skill\'s system prompts and its '
                    + 'answer is the final answer. No consensus is computed and the Cycle Limit does not '
                    + 'apply. The server reports back if it cannot honour the mode.');
            } else {
                const cycleInput = document.getElementById('cycle-limit-input');
                if (cycleInput) { cycleInput.disabled = false; }
                setStatus('Council mode: L1 asks every selected model, then consensus and synthesis run.');
            }
        });
    }
    const batchCancelBtn = document.getElementById('chat-batch-cancel');
    if (batchCancelBtn) batchCancelBtn.addEventListener('click', () => { void onBatchCancelClick(); });
    pauseBtn    = document.getElementById('chat-pause');
    rejectBtn   = document.getElementById('chat-reject');
    finalizeBtn = document.getElementById('chat-finalize');
    shareBtn    = document.getElementById('chat-share');
    if (pauseBtn)    pauseBtn.addEventListener('click', onPauseClick);
    if (rejectBtn)   rejectBtn.addEventListener('click', onRejectClick);
    if (finalizeBtn) finalizeBtn.addEventListener('click', onFinalizeClick);
    if (shareBtn)    shareBtn.addEventListener('click', onShareClick);
    syncControlButtons();
    // Defensive: close any active stream when the page is being unloaded
    // so the browser does not keep it open in the back/forward cache.
    window.addEventListener('beforeunload', () => closeActiveRun('unload'));
    window.addEventListener('pagehide',     () => closeActiveRun('pagehide'));
}

// v1.8.32 — render the auto-routing banner above the pipeline. Called
// when the SSE stream emits AUTO_ROUTING_NOTICE. The banner explains
// that the analyzer/domain router replaced the manual selection and
// links to the Settings → Domain Models section so the user can adjust.
function renderAutoRoutingNotice(payload) {
    if (!payload || !payload.auto_routed) return;
    const section = document.getElementById('pipeline-section');
    if (!section) return;
    const old = document.getElementById('oc-routing-notice');
    if (old) old.remove();
    const card = document.createElement('div');
    card.id = 'oc-routing-notice';
    card.className = 'oc-routing-notice';
    const title = document.createElement('h3');
    title.textContent = `Auto-routed to ${payload.preset_name || payload.preset_slug}`;
    card.append(title);
    const meta = document.createElement('p');
    const conf = typeof payload.confidence === 'number' ? ` (confidence ${(payload.confidence * 100).toFixed(0)}%)` : '';
    meta.textContent = `Detected domain: ${payload.domain}${conf}. Source: ${payload.source || 'heuristic'}.`;
    card.append(meta);
    if (payload.reason) {
        const why = document.createElement('p');
        why.className = 'oc-muted oc-small';
        why.textContent = `Why: ${payload.reason}`;
        card.append(why);
    }
    if (payload.skill_slug) {
        const sk = document.createElement('p');
        sk.className = 'oc-small';
        sk.textContent = `Applied skill: ${payload.skill_slug}`;
        card.append(sk);
    }
    const applied = Array.isArray(payload.applied_models) ? payload.applied_models : [];
    if (applied.length) {
        const list = document.createElement('ul');
        list.className = 'oc-small';
        for (const m of applied) {
            const li = document.createElement('li');
            li.textContent = `${m.provider}/${m.model}`;
            list.append(li);
        }
        card.append(document.createTextNode('Models in use:'));
        card.append(list);
    }
    const link = document.createElement('a');
    link.href = '#oc-domain-models';
    link.textContent = 'Configure these models in Models →';
    link.className = 'oc-link';
    link.addEventListener('click', (e) => {
        e.preventDefault();
        // v1.8.49 — the domain / consensus pool editor moved out of
        // Settings into the Models tab; the anchor id is unchanged, so
        // only the tab it is reached through changed.
        const tab = document.querySelector('[data-tab="models"]');
        if (tab) tab.click();
        // Scroll after the panel becomes visible.
        setTimeout(() => {
            const anchor = document.getElementById('oc-domain-models');
            if (anchor) anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    });
    card.append(link);
    section.parentNode?.insertBefore(card, section);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}