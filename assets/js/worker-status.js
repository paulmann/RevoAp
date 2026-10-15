// worker-status.js — v1.8.45 — header worker-status indicator.
//
// Adds one always-visible icon to the nav (immediately left of
// #theme-toggle in layout.html) that reports the durable job worker in
// exactly three lanes, in this precedence:
//
//   RED   "problems"  worker not running / job-queue table missing or
//                     migration not applied / last self-test failed /
//                     stale jobs / retries exhausted / state unknown.
//   BLUE  "busy"      jobs are being worked on (running > 0, or queued
//                     with a live worker) — the icon is the same
//                     .oc-activity-spinner pipeline.css uses for the
//                     "Waiting for worker" activity row.
//   GREEN "free"      the worker is alive and the queue is quiet.
//
// Data comes from three public endpoints (no admin token, so the
// indicator works on a deployment where Settings is disabled):
//
//   GET /api/health/worker              — snapshot (status, counts,
//                                         workers, retry policy, commands)
//   GET /api/health/worker/jobs         — the ≤10 jobs in flight /
//                                         recently finished (ids, statuses,
//                                         timings — never the query text)
//   GET /api/health/worker/diagnostics  — last self-test verdict
//                                         (provider identities masked) plus
//                                         the problems derived from the
//                                         snapshot
//
// Polling: every 5 s and ONLY while the tab is visible; switching to the
// tab fires an immediate refresh. A failed request never clears the last
// known state, never throws into the page, and is reported to the
// console at most once per failure series.
//
// Rendering rule: DOM is built exclusively through el() + textContent.
// The raw-HTML attribute that settings.js::el() supports is deliberately
// NOT implemented here, so untrusted text can never be parsed as markup.
//
// The indicator is a read-only observer: the only side effect of a click
// is switching to the Settings tab and scrolling to the Worker card.

import { warn } from './logger.js';

const HEALTH_URL      = '/api/health/worker';
const JOBS_URL        = '/api/health/worker/jobs';
const DIAGNOSTICS_URL = '/api/health/worker/diagnostics';

const BUTTON_ID       = 'oc-worker-status';
const TIP_ID          = 'oc-worker-status-tip';
const SETTINGS_TAB_ID = 'oc-tab-settings';
const WORKER_CARD_ID  = 'oc-worker-card';

const POLL_INTERVAL_MS = 5000;
const CLOSE_GRACE_MS   = 150;
const CARD_WAIT_MS     = 4000;
const CARD_POLL_MS     = 200;
const REQUEST_TIMEOUT_MS = 5000;

/** Lane identifiers. `problem` > `busy` > `free`. */
export const LANE_FREE    = 'free';
export const LANE_BUSY    = 'busy';
export const LANE_PROBLEM = 'problem';
/** A run that is paused waits for the operator, not for the worker. */
export const LANE_WAITING = 'waiting';

const LANE_LABEL = {
    free:    'idle — free',
    busy:    'working',
    problem: 'problems',
    waiting: 'waiting - paused',
};

export const LANE_TITLE = {
    free:    'Worker idle',
    busy:    'Worker is running jobs',
    problem: 'Worker problems',
    waiting: 'Worker is idle - jobs paused',
};

/**
 * Last known state. Everything the renderer needs, kept in one object so
 * a failed poll can leave it untouched.
 */
const ctl = {
    button: null,
    icon: null,
    tip: null,
    tipOpen: false,
    tipSignature: '',
    tipBody: null,
    tipHead: null,
    tipFoot: null,
    timer: null,
    closeTimer: null,
    inflight: false,
    health: null,
    jobs: null,
    diagnostics: null,
    lane: LANE_PROBLEM,
    reasons: [],
    renderedLane: '',
    lastSuccessAt: 0,
    transportFailing: false,
    failSeriesLogged: false,
    jobsFailed: false,
    diagnosticsFailed: false,
};

// ---------------------------------------------------------------------
// Tiny DOM helper — same shape as settings.js::el(), minus the raw-HTML
// branch.
// ---------------------------------------------------------------------
function isDomNode(value) {
    return value !== null && typeof value === 'object' && typeof value.nodeType === 'number';
}

function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null) continue;
        if (k === 'class') node.className = String(v);
        else if (k === 'text') node.textContent = String(v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2).toLowerCase(), v);
        else if (typeof v === 'boolean') { if (v) node.setAttribute(k, ''); else node.removeAttribute(k); }
        else node.setAttribute(k, String(v));
    }
    for (const child of children.flat()) {
        if (child == null) continue;
        node.append(isDomNode(child) ? child : document.createTextNode(String(child)));
    }
    return node;
}

function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
}

function setHidden(node, hidden) {
    if (hidden) node.setAttribute('hidden', '');
    else node.removeAttribute('hidden');
}

// ---------------------------------------------------------------------
// Formatting (local copies — the header module stays free of cross-file
// component imports beyond the logger).
// ---------------------------------------------------------------------
function pad2(n) {
    return String(n).padStart(2, '0');
}

export function formatAge(seconds) {
    if (seconds == null || !Number.isFinite(Number(seconds))) return '—';
    const s = Math.max(0, Math.floor(Number(seconds)));
    if (s < 60) return s + 's';
    if (s < 3600) return Math.floor(s / 60) + 'm ' + pad2(s % 60) + 's';
    if (s < 86400) return Math.floor(s / 3600) + 'h ' + pad2(Math.floor((s % 3600) / 60)) + 'm';
    return Math.floor(s / 86400) + 'd ' + Math.floor((s % 86400) / 3600) + 'h';
}

export function formatUptime(seconds) {
    if (seconds == null || !Number.isFinite(Number(seconds))) return '—';
    const s = Math.max(0, Math.floor(Number(seconds)));
    const days = Math.floor(s / 86400);
    const hms = pad2(Math.floor((s % 86400) / 3600)) + ':' + pad2(Math.floor((s % 3600) / 60)) + ':' + pad2(s % 60);
    return days > 0 ? days + 'd ' + hms : hms;
}

/**
 * v1.8.48 — the label for `latest_heartbeat_age_s`.
 *
 * The snapshot's freshness value is now whichever liveness signal is
 * freshest — the 1 Hz presence beacon when the worker is beaconing, the job
 * heartbeat only when there is no beacon row (see
 * WorkerHealthService::resolveFreshness). A line that always said "Last
 * heartbeat" would therefore describe a beacon as a heartbeat and send the
 * operator looking for a job that never existed, which is the same
 * confusion the classification fix removed. `latest_heartbeat_source` says
 * which one it is; an older snapshot without the key keeps the old wording.
 */
export function freshnessLabel(health) {
    const source = health && typeof health.latest_heartbeat_source === 'string'
        ? health.latest_heartbeat_source : '';
    if (source === 'presence') return 'Last presence beacon';
    if (source === 'job') return 'Last job heartbeat';
    return 'Last heartbeat';
}

export function formatStamp(value) {
    if (!value || typeof value !== 'string') return '—';
    return value.replace('T', ' ').replace(/Z$/, ' UTC');
}

function num(value, fallback = 0) {
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}

// ---------------------------------------------------------------------
// State resolution — pure, exported, unit-testable.
// ---------------------------------------------------------------------

/**
 * Pick the lane from the public snapshot (+ the last self-test verdict).
 *
 * Complete condition table (first match wins, so RED always beats BLUE):
 *
 *   RED when ANY of:
 *     - snapshot.jobs_table_available === false        → job_queue_missing
 *     - snapshot.migration_applied === false           → migration_missing
 *     - snapshot.worker_status === 'migration_missing' → migration_missing
 *     - snapshot.worker_status === 'not_running'       → worker_not_running
 *     - snapshot.worker_status === 'stale'             → worker_stale
 *     - snapshot.worker_status === 'unknown'           → status_unknown
 *     - snapshot.stale_jobs > 0                        → stale_jobs
 *     - snapshot.retry_exhausted > 0                   → retry_exhausted
 *     - diagnostics.available && diagnostics.ok === false → selftest_failed
 *     - no snapshot at all (never answered)            → state_unknown
 *   BLUE when no RED condition holds and ANY of:
 *     - counts.running > 0
 *     - counts.queued > 0 AND workers_alive > 0
 *     - counts.pause_requested/resume_requested/consensus_requested > 0
 *       while a worker is alive
 *   GREEN otherwise.
 *
 * @param {object|null} health      GET /api/health/worker body
 * @param {object|null} diagnostics GET /api/health/worker/diagnostics body
 * @returns {{lane: string, reasons: Array<{code: string, message: string}>}}
 */
export function resolveState(health, diagnostics) {
    if (!health || typeof health !== 'object') {
        return {
            lane: LANE_PROBLEM,
            reasons: [{
                code: 'state_unknown',
                message: 'Worker state is unknown — the health API has not answered yet.',
            }],
        };
    }

    const counts   = health.counts && typeof health.counts === 'object' ? health.counts : {};
    const running  = num(counts.running);
    const queued   = num(counts.queued);
    const paused   = num(counts.paused)
        + num(counts.pause_requested)
        + num(counts.resume_requested)
        + num(counts.consensus_requested)
        + num(counts.cancel_requested);
    const stale    = num(health.stale_jobs);
    const alive    = num(health.workers_alive);
    const status   = String(health.worker_status || 'unknown');
    const reasons  = [];

    const already = (code) => reasons.some((r) => r.code === code);

    if (health.jobs_table_available === false) {
        reasons.push({
            code: 'job_queue_missing',
            message: 'The durable job queue table (revoap_jobs) is missing — import db/schema.sql '
                + '(Settings → Database → ReCreate DB).',
        });
    }
    if (health.migration_applied === false && !already('job_queue_missing')) {
        reasons.push({
            code: 'migration_missing',
            message: 'The job-queue migration has not been applied on this database — apply db/schema.sql '
                + '(Settings → Database → Apply Migrations).',
        });
    }
    if (status === 'migration_missing' && !already('job_queue_missing') && !already('migration_missing')) {
        reasons.push({
            code: 'migration_missing',
            message: 'The worker reports a missing job-queue migration — import db/schema.sql '
                + '(Settings → Database → ReCreate DB).',
        });
    }
    if (status === 'not_running') {
        reasons.push({
            code: 'worker_not_running',
            message: 'No worker process is running — queued jobs are not being processed.',
        });
    }
    if (status === 'stale') {
        reasons.push({
            code: 'worker_stale',
            message: 'The last worker heartbeat is older than the stale threshold — the worker may be stuck '
                + 'or dead.',
        });
    }
    if (status === 'unknown' && !already('job_queue_missing')) {
        reasons.push({
            code: 'status_unknown',
            message: 'The worker state could not be determined from the health snapshot.',
        });
    }
    if (stale > 0) {
        reasons.push({
            code: 'stale_jobs',
            message: stale + ' stale job(s) hold a lapsed heartbeat — the worker is dying mid-run.',
        });
    }
    if (num(health.retry_exhausted) > 0) {
        reasons.push({
            code: 'retry_exhausted',
            message: num(health.retry_exhausted) + ' job(s) exhausted the retry budget and will not be retried.',
        });
    }
    if (diagnostics && typeof diagnostics === 'object'
        && diagnostics.available === true && diagnostics.ok === false) {
        const failed = num(diagnostics.failed);
        reasons.push({
            code: 'selftest_failed',
            message: 'The last worker self-test failed'
                + (failed > 0 ? ' (' + failed + ' check(s) failed)' : '')
                + ' — run Test again in Settings → Worker.',
        });
    }

    if (reasons.length > 0) {
        return { lane: LANE_PROBLEM, reasons };
    }

    // A paused job is not work in progress: the worker is free and the run is
    // waiting for the operator. Counting it as busy made the header spin.
    const hasRunning = running > 0 || (queued > 0 && alive > 0);
    if (hasRunning) {
        const bits = [];
        if (running > 0) bits.push(running + ' running');
        if (queued > 0) bits.push(queued + ' queued');
        if (paused > 0) bits.push(paused + ' paused');
        return {
            lane: LANE_BUSY,
            reasons: [{ code: 'working', message: 'Worker is processing jobs (' + bits.join(', ') + ').' }],
        };
    }
    if (paused > 0 && alive > 0) {
        return {
            lane: LANE_WAITING,
            reasons: [{
                code: 'waiting',
                message: 'Worker is idle: ' + paused + ' paused job(s) are waiting for you to continue. '
                    + 'Nothing is running until then.',
            }],
        };
    }

    return {
        lane: LANE_FREE,
        reasons: [{
            code: 'idle',
            message: alive > 0
                ? 'Worker is alive and idle — ' + alive + ' process(es) polling, nothing queued or running.'
                : 'Queue is empty and no worker heartbeat is recorded.',
        }],
    };
}

// ---------------------------------------------------------------------
// Tooltip model — pure, exported, unit-testable.
// A section is {title, kind, entries|items|checks} and is turned into DOM
// by renderSection(); nothing in here touches the document.
// ---------------------------------------------------------------------

/**
 * Build the tooltip sections for one lane.
 *
 * @param {string} lane
 * @param {{health: object|null, jobs: object|null, diagnostics: object|null,
 *          reasons: Array<{code: string, message: string}>,
 *          transportFailing: boolean, lastSuccessAgeS: number|null,
 *          healthFailed?: boolean, jobsFailed?: boolean, diagnosticsFailed?: boolean}} ctx
 * @returns {Array<{title: string, kind: string, entries: Array, items: Array}>}
 */
export function buildTooltipSections(lane, ctx) {
    const h = ctx.health && typeof ctx.health === 'object' ? ctx.health : {};
    const counts = h.counts && typeof h.counts === 'object' ? h.counts : {};
    const workers = Array.isArray(h.running_workers) ? h.running_workers : [];
    const policy = h.retry_policy && typeof h.retry_policy === 'object' ? h.retry_policy : {};
    const sections = [];

    if (lane === LANE_PROBLEM) {
        sections.push({
            title: 'What is broken',
            kind: 'items',
            items: (ctx.reasons || []).map((r) => ({ label: r.code, text: r.message, ok: false })),
        });
        const diag = ctx.diagnostics && typeof ctx.diagnostics === 'object' ? ctx.diagnostics : null;
        if (diag && diag.available === true) {
            const checks = Array.isArray(diag.checks) ? diag.checks : [];
            sections.push({
                title: 'Last self-test — ' + (diag.ok === true ? 'passed' : 'FAILED')
                    + ' (' + num(diag.passed) + ' ok, ' + num(diag.failed) + ' failed'
                    + (diag.duration_ms != null ? ', ' + num(diag.duration_ms) + ' ms' : '')
                    + ')',
                kind: 'checks',
                note: 'job #' + (diag.job_id != null ? diag.job_id : '?')
                    + ' · ' + formatStamp(diag.at)
                    + (diag.age_s != null ? ' · ' + formatAge(diag.age_s) + ' ago' : ''),
                items: checks.map((c) => ({
                    label: String(c.name || '?'),
                    text: String(c.detail || ''),
                    ok: c.ok === true,
                    meta: c.ms != null ? num(c.ms) + ' ms' : '',
                })),
            });
        } else {
            sections.push({
                title: 'Last self-test',
                kind: 'items',
                items: [{
                    label: 'never run',
                    text: 'No self-test result has been recorded yet — run Test in Settings → Worker to have the '
                        + 'worker verify the database round-trip, the audit table and the writable paths.',
                    ok: null,
                }],
            });
        }
        const derived = diag && diag.derived && typeof diag.derived === 'object' ? diag.derived : null;
        if (derived) {
            sections.push({
                title: 'What still works (health snapshot)',
                kind: 'checks',
                items: [
                    { label: 'jobs table', text: 'revoap_jobs', ok: derived.jobs_table_available === true },
                    { label: 'migration applied', text: 'schema version', ok: derived.migration_applied === true },
                    { label: 'events table', text: 'revoap_job_events', ok: derived.events_table_available === true },
                    { label: 'presence table', text: 'revoap_worker_presence', ok: derived.presence_table_available === true },
                    { label: 'worker status', text: String(derived.worker_status || 'unknown'), ok: derived.worker_status === 'healthy' || derived.worker_status === 'idle' },
                    { label: 'stale jobs', text: String(num(derived.stale_jobs)), ok: num(derived.stale_jobs) === 0 },
                    { label: 'retries exhausted', text: String(num(derived.retry_exhausted)), ok: num(derived.retry_exhausted) === 0 },
                ],
            });
            const problems = Array.isArray(derived.problems) ? derived.problems : [];
            if (problems.length > 0) {
                sections.push({
                    title: 'Fixes',
                    kind: 'fixes',
                    items: problems.map((p) => ({
                        label: String(p.code || ''),
                        text: String(p.message || ''),
                        command: p.command ? String(p.command) : '',
                        ok: false,
                    })),
                });
            }
        } else if (ctx.diagnosticsFailed) {
            sections.push({
                title: 'Derived problems',
                kind: 'items',
                items: [{ label: 'unavailable', text: 'The diagnostics endpoint did not answer — only the snapshot is shown.', ok: null }],
            });
        }
        sections.push(snapshotSection(h, workers, policy));
        return sections;
    }

    if (lane === LANE_BUSY || lane === LANE_WAITING) {
        const jobs = ctx.jobs && typeof ctx.jobs === 'object' ? ctx.jobs : null;
        const list = jobs && Array.isArray(jobs.jobs) ? jobs.jobs : [];
        if (jobs && jobs.jobs_table_available === false) {
            sections.push({
                title: 'Jobs',
                kind: 'items',
                items: [{ label: 'unavailable', text: 'The job table is missing, so individual jobs cannot be listed.', ok: null }],
            });
        } else if (!jobs || (ctx.jobsFailed && list.length === 0)) {
            sections.push({
                title: 'Jobs',
                kind: 'items',
                items: [{ label: 'unavailable', text: 'The job feed did not answer — only the queue counters below are current.', ok: null }],
            });
        } else if (list.length === 0) {
            sections.push({
                title: 'Jobs',
                kind: 'items',
                items: [{ label: 'none', text: 'No job row matched the current window.', ok: null }],
            });
        } else {
            sections.push({
                title: 'Jobs (' + list.length + ')',
                kind: 'jobs',
                note: jobs.truncated === true
                    ? 'showing the newest ' + list.length + ' of a deeper backlog'
                    : '',
                items: list.map((j) => ({
                    label: '#' + j.job_id + ' · session #' + j.session_id,
                    status: String(j.status || '?'),
                    text: jobTimingText(j),
                    meta: jobMetaText(j),
                })),
            });
        }
        const retryBits = [];
        if (num(h.retry_scheduled) > 0) {
            retryBits.push(num(h.retry_scheduled) + ' awaiting retry'
                + (h.next_retry_at ? ' (next ' + formatStamp(h.next_retry_at) + ')' : ''));
        }
        if (num(counts.paused) + num(counts.pause_requested) + num(counts.resume_requested) > 0) {
            retryBits.push(num(counts.paused) + num(counts.pause_requested) + num(counts.resume_requested) + ' paused');
        }
        sections.push({
            title: 'Queue',
            kind: 'entries',
            entries: [
                ['Queue depth (non-terminal)', String(num(h.total_non_terminal))],
                ['Running', String(num(counts.running))],
                ['Queued', String(num(counts.queued))],
                ['Oldest unfinished job', h.oldest_queued_age_s != null ? formatAge(h.oldest_queued_age_s) + ' ago' : '—'],
                [freshnessLabel(h), h.latest_heartbeat_age_s != null ? formatAge(h.latest_heartbeat_age_s) + ' ago' : '—'],
                ['Pending retries', num(h.retry_scheduled) + (h.next_retry_at ? ' · next ' + formatStamp(h.next_retry_at) : '')],
                ['Worker processes alive', String(num(h.workers_alive))],
            ],
        });
        sections.push(workersSection(workers, h));
        return sections;
    }

    // GREEN — the worker is free; report who it is and what it would do.
    sections.push({
        title: 'Worker',
        kind: 'entries',
        entries: [
            ['State', String(h.worker_status || 'unknown')],
            ['Worker processes alive', String(num(h.workers_alive))],
            ['Execution mode', String(h.execution_mode || '—')],
            ['App version', String(h.app_version || '—')],
            ['Jobs done (all workers)', workerVersusJobsDone(workers)],
            [freshnessLabel(h), h.latest_heartbeat_age_s != null ? formatAge(h.latest_heartbeat_age_s) + ' ago' : '—'],
            ['Heartbeat stale threshold', num(h.stale_threshold_sec) + 's'],
        ],
        items: [],
    });
    sections.push(workersSection(workers, h));
    sections.push({
        title: 'Queue',
        kind: 'entries',
        entries: [
            ['Queued', String(num(counts.queued))],
            ['Running', String(num(counts.running))],
            ['Paused', String(num(counts.paused) + num(counts.pause_requested) + num(counts.resume_requested))],
            ['Stale', String(num(counts.stale))],
            ['Non-terminal', String(num(h.total_non_terminal))],
            ['Completed', String(num(counts.completed))],
            ['Failed', String(num(counts.failed))],
        ],
        items: [],
    });
    sections.push({
        title: 'Retries',
        kind: 'entries',
        entries: [
            ['Auto recovery', policy.auto_recovery_enabled === false ? 'off' : 'on'],
            ['Max attempts', String(num(policy.max_attempts, 0) || '—')],
            ['Backoff', Array.isArray(policy.backoff_seconds) && policy.backoff_seconds.length > 0
                ? policy.backoff_seconds.map((v) => num(v) + 's').join(' → ')
                : '—'],
            ['Awaiting retry', String(num(h.retry_scheduled))],
            ['Next retry', h.next_retry_at ? formatStamp(h.next_retry_at) : '—'],
        ],
        items: [],
    });
    return sections;
}

function workerVersusJobsDone(workers) {
    const done = workers
        .map((w) => (w.jobs_done != null ? num(w.jobs_done) : null))
        .filter((v) => v !== null);
    return done.length > 0 ? String(done.reduce((a, b) => a + b, 0)) : '—';
}

function workersSection(workers, health) {
    if (workers.length === 0) {
        return {
            title: 'Worker processes',
            kind: 'items',
            items: [{
                label: 'none alive',
                text: 'No worker process is reporting a heartbeat or a presence beacon within the '
                    + num(health.stale_threshold_sec, 0) + 's stale threshold.',
                ok: false,
            }],
        };
    }
    return {
        title: 'Worker processes (' + workers.length + ')',
        kind: 'workers',
        items: workers.map((w) => ({
            label: String(w.worker_id || '?'),
            text: workerText(w),
            ok: w.alive === true,
            meta: w.current_job_id != null ? 'holding job #' + w.current_job_id : 'idle',
        })),
    };
}

function workerText(w) {
    const bits = [];
    if (w.app_version) bits.push('v' + w.app_version);
    if (w.mode) bits.push('mode ' + w.mode);
    if (w.uptime_s != null) bits.push('uptime ' + formatUptime(w.uptime_s));
    if (w.host) bits.push('host ' + w.host);
    if (w.pid != null) bits.push('pid ' + w.pid);
    if (w.jobs_done != null) bits.push(num(w.jobs_done) + ' jobs done');
    if (w.heartbeat_age_s != null) bits.push('heartbeat ' + formatAge(w.heartbeat_age_s) + ' ago');
    if (w.source) bits.push('source ' + w.source);
    if (w.alive !== true) bits.push('NOT ALIVE');
    return bits.join(' · ');
}

function snapshotSection(health, workers, policy) {
    return {
        title: 'Snapshot',
        kind: 'entries',
        entries: [
            ['Worker status', String(health.worker_status || 'unknown')],
            ['Processes alive', String(num(health.workers_alive))],
            ['Execution mode', String(health.execution_mode || '—')],
            ['App version', String(health.app_version || '—')],
            [freshnessLabel(health), health.latest_heartbeat_age_s != null
                ? formatAge(health.latest_heartbeat_age_s) + ' ago' : '—'],
            ['Stale threshold', num(health.stale_threshold_sec) + 's'],
            ['Pending retries', String(num(health.retry_scheduled))],
            ['Retries exhausted', String(num(health.retry_exhausted))],
            ['Worker processes', String(workers.length)],
            ['Retry policy', policy.auto_recovery_enabled === false
                ? 'auto recovery off'
                : 'max ' + num(policy.max_attempts) + ' attempts'],
        ],
        items: [],
    };
}

/**
 * Human sentence for one job row: queued → waiting, running → elapsed,
 * terminal → how long it took. Mirrors the wait_s / run_s / total_s
 * contract of GET /api/health/worker/jobs.
 */
export function jobTimingText(job) {
    const status = String(job && job.status ? job.status : '');
    const bits = [];
    if (status === 'running') {
        bits.push('running for ' + formatAge(job.run_s));
        if (job.started_at) bits.push('started ' + formatStamp(job.started_at));
        if (job.wait_s != null) bits.push('waited ' + formatAge(job.wait_s) + ' to start');
    } else if (status === 'queued' || status === 'resume_requested') {
        bits.push('waiting ' + formatAge(job.wait_s));
        if (job.queued_at) bits.push('queued ' + formatStamp(job.queued_at));
    } else if (status === 'stale') {
        bits.push('held ' + formatAge(job.run_s) + ' before the heartbeat lapsed');
    } else if (job.finished_at) {
        bits.push('took ' + formatAge(job.run_s));
        bits.push('finished ' + formatStamp(job.finished_at));
        if (job.wait_s != null) bits.push('waited ' + formatAge(job.wait_s) + ' to start');
    } else {
        bits.push('in ' + status + ' for ' + formatAge(job.total_s));
    }
    if (job.next_retry_at) bits.push('next retry ' + formatStamp(job.next_retry_at));
    return bits.join(' · ');
}

function jobMetaText(job) {
    const bits = [];
    if (job.worker_id) bits.push('worker ' + job.worker_id);
    if (job.stage) bits.push('stage ' + job.stage);
    if (job.priority) bits.push('priority ' + job.priority);
    if (job.attempts != null) bits.push('attempt ' + num(job.attempts) + '/' + num(job.max_attempts));
    if (job.total_s != null) bits.push('total ' + formatAge(job.total_s));
    return bits.join(' · ');
}

// ---------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------

function iconFor(lane) {
    if (lane === LANE_BUSY) {
        // The very same spinner the "Waiting for worker" activity row uses
        // (assets/css/components/pipeline.css — .oc-activity-spinner).
        return el('span', { class: 'oc-activity-spinner oc-worker-status-spinner', 'aria-hidden': 'true' });
    }
    if (lane === LANE_PROBLEM) {
        return el('span', { class: 'oc-worker-status-dot', 'data-lane': LANE_PROBLEM, 'aria-hidden': 'true' }, '!');
    }
    if (lane === LANE_WAITING) {
        // A paused run is not progress: an amber dot, never the spinner.
        return el('span', { class: 'oc-worker-status-dot', 'data-lane': LANE_WAITING, 'aria-hidden': 'true' });
    }
    return el('span', { class: 'oc-worker-status-dot', 'data-lane': LANE_FREE, 'aria-hidden': 'true' });
}

function laneTitle(health) {
    const counts = health && health.counts ? health.counts : {};
    const running = num(counts.running);
    const queued = num(counts.queued);
    if (ctl.lane === LANE_BUSY) {
        const bits = [];
        if (running > 0) bits.push(running + ' running');
        if (queued > 0) bits.push(queued + ' queued');
        return LANE_TITLE.busy + (bits.length > 0 ? ' (' + bits.join(', ') + ')' : '')
            + '. Click for Settings → Worker.';
    }
    if (ctl.lane === LANE_WAITING) {
        const paused = num(counts.paused);
        return LANE_TITLE.waiting + (paused > 0 ? ' (' + paused + ' paused)' : '')
            + '. Click for Settings  Worker.';
    }
    if (ctl.lane === LANE_PROBLEM) {
        const first = ctl.reasons.length > 0 ? ctl.reasons[0].message : 'state unknown';
        return LANE_TITLE.problem + ': ' + first + ' Click for Settings → Worker.';
    }
    return LANE_TITLE.free
        + ' — ' + (queued > 0 ? queued + ' queued, nothing running' : 'no queued or running jobs')
        + (health && health.app_version ? ' (v' + health.app_version + ')' : '')
        + '. Click for Settings → Worker.';
}

function applyButton() {
    const button = ctl.button;
    if (!button) return;
    const health = ctl.health;
    button.setAttribute('data-lane', ctl.lane);
    button.setAttribute('aria-label', 'Worker status: ' + LANE_LABEL[ctl.lane]
        + (ctl.reasons.length > 0 && ctl.lane === LANE_PROBLEM ? ' — ' + ctl.reasons[0].message : ''));
    button.setAttribute('title', laneTitle(health));
    // The icon is only rebuilt when the lane changes: re-creating the
    // spinner node every 5 s would restart its CSS animation mid-turn.
    if (ctl.renderedLane !== ctl.lane || ctl.icon === null) {
        clear(button);
        ctl.icon = iconFor(ctl.lane);
        button.append(ctl.icon);
        ctl.renderedLane = ctl.lane;
    }
}

function ensureTip() {
    if (ctl.tip) return ctl.tip;
    if (!document.body) return null;
    const head = el('div', { class: 'oc-worker-status-tip-head' });
    const body = el('div', { class: 'oc-worker-status-tip-body' });
    const foot = el('div', { class: 'oc-worker-status-tip-foot' });
    ctl.tipHead = head;
    ctl.tipBody = body;
    ctl.tipFoot = foot;
    ctl.tip = el('div', {
        id: TIP_ID,
        class: 'oc-worker-status-tip',
        role: 'tooltip',
    }, head, body, foot);
    setHidden(ctl.tip, true);
    document.body.append(ctl.tip);
    return ctl.tip;
}

/** Model → DOM. Everything goes through el()/textContent. */
function renderSection(section) {
    const parts = [el('h4', { class: 'oc-worker-status-section-title', text: section.title })];
    if (section.note) {
        parts.push(el('div', { class: 'oc-worker-status-note', text: section.note }));
    }

    if (section.kind === 'entries') {
        const list = el('dl', { class: 'oc-worker-status-kv' });
        for (const [label, value] of section.entries || []) {
            list.append(el('div', { class: 'oc-worker-status-kv-row' },
                el('dt', { text: String(label) }),
                el('dd', { text: String(value) }),
            ));
        }
        parts.push(list);
    }

    if (section.kind === 'items' || section.kind === 'checks' || section.kind === 'workers') {
        const list = el('ul', { class: 'oc-worker-status-list' });
        for (const item of section.items || []) {
            list.append(el('li', { class: 'oc-worker-status-item', 'data-ok': item.ok === true ? 'true' : (item.ok === false ? 'false' : 'unknown') },
                el('span', { class: 'oc-worker-status-mark', 'aria-hidden': 'true',
                    text: item.ok === true ? '✓' : (item.ok === false ? '✗' : '•') }),
                el('span', { class: 'oc-worker-status-item-label', text: String(item.label || '') }),
                item.status ? el('span', { class: 'oc-worker-status-badge', text: String(item.status) }) : null,
                el('span', { class: 'oc-worker-status-item-text', text: String(item.text || '') }),
                item.meta ? el('span', { class: 'oc-worker-status-item-meta', text: String(item.meta) }) : null,
            ));
        }
        parts.push(list);
    }

    if (section.kind === 'jobs') {
        const list = el('ul', { class: 'oc-worker-status-list oc-worker-status-jobs' });
        for (const item of section.items || []) {
            list.append(el('li', { class: 'oc-worker-status-job', 'data-status': String(item.status || '') },
                el('div', { class: 'oc-worker-status-job-head' },
                    el('span', { class: 'oc-worker-status-item-label', text: String(item.label || '') }),
                    el('span', { class: 'oc-worker-status-badge', text: String(item.status || '') }),
                ),
                el('div', { class: 'oc-worker-status-item-text', text: String(item.text || '') }),
                item.meta ? el('div', { class: 'oc-worker-status-item-meta', text: String(item.meta) }) : null,
            ));
        }
        parts.push(list);
    }

    if (section.kind === 'fixes') {
        const list = el('ul', { class: 'oc-worker-status-list' });
        for (const item of section.items || []) {
            const li = el('li', { class: 'oc-worker-status-fix' },
                el('span', { class: 'oc-worker-status-mark', 'aria-hidden': 'true', text: '✗' }),
                el('span', { class: 'oc-worker-status-item-text', text: String(item.text || '') }),
            );
            if (item.command) {
                li.append(el('code', { class: 'oc-worker-status-cmd', text: String(item.command) }));
            }
            list.append(li);
        }
        parts.push(list);
    }

    return el('section', { class: 'oc-worker-status-section' }, ...parts);
}

function renderTip() {
    if (!ctl.tipOpen || !ctl.tip) return;

    const signature = JSON.stringify({
        lane: ctl.lane,
        reasons: ctl.reasons,
        failing: ctl.transportFailing,
        health: ctl.health,
        jobs: ctl.jobs,
        diagnostics: ctl.diagnostics,
        // Relative "updated Ns ago" is bucketed so the 5 s poller does not
        // rebuild the panel (and drop a text selection) on every tick.
        bucket: ctl.lastSuccessAt > 0 ? Math.floor((Date.now() - ctl.lastSuccessAt) / 10000) : -1,
    });
    if (signature === ctl.tipSignature) return;
    ctl.tipSignature = signature;

    clear(ctl.tipHead);
    clear(ctl.tipBody);
    clear(ctl.tipFoot);

    const health = ctl.health && typeof ctl.health === 'object' ? ctl.health : {};
    ctl.tipHead.append(
        el('span', { class: 'oc-worker-status-tip-eyebrow', text: 'Worker' }),
        el('span', { class: 'oc-worker-status-tip-lane', 'data-lane': ctl.lane, text: LANE_LABEL[ctl.lane] }),
    );
    if (ctl.reasons.length > 0 && ctl.lane !== LANE_PROBLEM) {
        ctl.tipHead.append(el('span', { class: 'oc-worker-status-tip-sub', text: ctl.reasons[0].message }));
    }

    const sections = buildTooltipSections(ctl.lane, {
        health: ctl.health,
        jobs: ctl.jobs,
        diagnostics: ctl.diagnostics,
        reasons: ctl.reasons,
        transportFailing: ctl.transportFailing,
    });
    for (const section of sections) {
        ctl.tipBody.append(renderSection(section));
    }

    const footBits = [];
    footBits.push('snapshot ' + formatStamp(health.generated_at));
    if (ctl.lastSuccessAt > 0) {
        footBits.push('updated ' + formatAge((Date.now() - ctl.lastSuccessAt) / 1000) + ' ago');
    }
    ctl.tipFoot.append(el('span', { class: 'oc-worker-status-foot-text', text: footBits.join(' · ') }));
    if (ctl.transportFailing) {
        ctl.tipFoot.append(el('span', {
            class: 'oc-worker-status-foot-warn',
            text: 'health API not answering — showing the last known state',
        }));
    }

    positionTip();
}

function positionTip() {
    const tip = ctl.tip;
    const button = ctl.button;
    if (!tip || !button) return;
    if (typeof button.getBoundingClientRect !== 'function') return;
    const rect = button.getBoundingClientRect();
    const width = num(tip.offsetWidth, 0) || 380;
    const viewport = num(typeof window !== 'undefined' ? window.innerWidth : 0, 0) || 1024;
    let left = rect.right - width;
    if (left < 8) left = 8;
    if (left + width > viewport - 8) left = Math.max(8, viewport - width - 8);
    const top = num(rect.bottom, 0) + 8;
    tip.style.top = Math.round(top) + 'px';
    tip.style.left = Math.round(left) + 'px';
    tip.style.maxWidth = Math.min(520, Math.max(280, viewport - 16)) + 'px';
}

function openTip() {
    if (!ensureTip()) return;
    ctl.tipOpen = true;
    ctl.tipSignature = '';
    ctl.button?.setAttribute('aria-expanded', 'true');
    ctl.button?.setAttribute('aria-describedby', TIP_ID);
    setHidden(ctl.tip, false);
    renderTip();
}

function closeTip() {
    if (ctl.closeTimer !== null) {
        clearTimeout(ctl.closeTimer);
        ctl.closeTimer = null;
    }
    ctl.tipOpen = false;
    ctl.tipSignature = '';
    ctl.button?.setAttribute('aria-expanded', 'false');
    ctl.button?.removeAttribute('aria-describedby');
    if (ctl.tip) setHidden(ctl.tip, true);
}

function scheduleClose() {
    if (ctl.closeTimer !== null) clearTimeout(ctl.closeTimer);
    ctl.closeTimer = setTimeout(() => {
        ctl.closeTimer = null;
        closeTip();
    }, CLOSE_GRACE_MS);
}

function cancelClose() {
    if (ctl.closeTimer !== null) {
        clearTimeout(ctl.closeTimer);
        ctl.closeTimer = null;
    }
}

// ---------------------------------------------------------------------
// Data fetching
// ---------------------------------------------------------------------

async function getJson(url) {
    let timer = null;
    try {
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        if (controller) {
            timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
        }
        const res = await fetch(url, {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller ? controller.signal : undefined,
        });
        if (!res || res.ok !== true) return null;
        const body = await res.json();
        return body && typeof body === 'object' ? body : null;
    } catch {
        return null;
    } finally {
        if (timer !== null) clearTimeout(timer);
    }
}

function noteTransportFailure() {
    ctl.transportFailing = true;
    if (!ctl.failSeriesLogged) {
        ctl.failSeriesLogged = true;
        // One line per failure series — a flapping backend must not turn
        // the console (or the log sink) into a firehose.
        warn('worker.status.poll_failed', { reason: 'health API unreachable or not JSON' });
    }
}

async function refresh() {
    if (ctl.inflight) return;
    ctl.inflight = true;
    try {
        const health = await getJson(HEALTH_URL);
        if (health === null) {
            noteTransportFailure();
            applyButton();
            if (ctl.tipOpen) renderTip();
            return;
        }

        ctl.health = health;
        let jobsFailed = false;
        let diagnosticsFailed = false;

        const diagnostics = await getJson(DIAGNOSTICS_URL);
        if (diagnostics === null) {
            diagnosticsFailed = true;
        } else {
            ctl.diagnostics = diagnostics;
        }

        const state = resolveState(ctl.health, ctl.diagnostics);
        if (state.lane === LANE_BUSY || state.lane === LANE_WAITING) {
            const jobs = await getJson(JOBS_URL);
            if (jobs === null) {
                jobsFailed = true;
            } else {
                ctl.jobs = jobs;
            }
        }

        ctl.lane = state.lane;
        ctl.reasons = state.reasons;
        ctl.transportFailing = false;
        ctl.failSeriesLogged = false;
        ctl.lastSuccessAt = Date.now();
        ctl.diagnosticsFailed = diagnosticsFailed;
        ctl.jobsFailed = jobsFailed;

        applyButton();
        if (ctl.tipOpen) renderTip();
    } finally {
        ctl.inflight = false;
    }
}

// ---------------------------------------------------------------------
// Polling lifecycle
// ---------------------------------------------------------------------

function pageVisible() {
    const state = typeof document !== 'undefined' ? document.visibilityState : undefined;
    if (typeof state === 'string' && state !== '') return state !== 'hidden';
    return !(typeof document !== 'undefined' && document.hidden === true);
}

export function startPolling() {
    if (ctl.timer !== null) return;
    ctl.timer = setInterval(() => {
        if (!pageVisible()) return;
        void refresh();
    }, POLL_INTERVAL_MS);
}

export function stopPolling() {
    if (ctl.timer === null) return;
    clearInterval(ctl.timer);
    ctl.timer = null;
}

function onVisibilityChange() {
    if (pageVisible()) {
        // Back on screen: refresh immediately, then resume the 5 s cadence.
        startPolling();
        void refresh();
        return;
    }
    stopPolling();
}

// ---------------------------------------------------------------------
// Settings navigation
// ---------------------------------------------------------------------

/**
 * Switch to the Settings tab and scroll to the Worker card. Silent no-op
 * when the Settings tab is disabled/absent — the indicator must never
 * fail because the operator turned the tab off.
 */
function goToWorkerCard() {
    const tab = document.getElementById(SETTINGS_TAB_ID);
    if (!tab || tab.hasAttribute('hidden')) return false;
    const card = document.getElementById(WORKER_CARD_ID);
    if (card) {
        scrollTo(card);
        return true;
    }
    tab.click();
    // The Settings panel renders asynchronously (it fetches /api/settings
    // first), so wait for the card to appear instead of guessing a delay.
    const deadline = Date.now() + CARD_WAIT_MS;
    const poll = () => {
        const node = document.getElementById(WORKER_CARD_ID);
        if (node) {
            scrollTo(node);
            return;
        }
        if (Date.now() >= deadline) return;
        setTimeout(poll, CARD_POLL_MS);
    };
    setTimeout(poll, CARD_POLL_MS);
    return true;
}

function scrollTo(node) {
    if (node && typeof node.scrollIntoView === 'function') {
        try {
            node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch {
            node.scrollIntoView();
        }
    }
}

// ---------------------------------------------------------------------
// Wiring
// ---------------------------------------------------------------------

export function init() {
    if (!document.body) return false;
    const button = document.getElementById(BUTTON_ID);
    if (!button) return false;
    ctl.button = button;

    button.setAttribute('aria-haspopup', 'true');
    button.setAttribute('aria-expanded', 'false');

    button.addEventListener('mouseenter', () => {
        cancelClose();
        openTip();
    });
    button.addEventListener('mouseleave', () => scheduleClose());
    button.addEventListener('focus', () => { cancelClose(); openTip(); });
    button.addEventListener('blur', () => scheduleClose());
    button.addEventListener('click', () => {
        closeTip();
        goToWorkerCard();
    });
    button.addEventListener('keydown', (event) => {
        if (event && event.key === 'Escape') closeTip();
    });

    const tip = ensureTip();
    if (tip) {
        tip.addEventListener('mouseenter', () => cancelClose());
        tip.addEventListener('mouseleave', () => scheduleClose());
    }

    document.addEventListener('keydown', (event) => {
        if (ctl.tipOpen && event && event.key === 'Escape') closeTip();
    });
    document.addEventListener('visibilitychange', onVisibilityChange);

    // Paint a coherent "unknown" state before the first answer arrives,
    // so the icon is never blank and never claims the worker is fine.
    if (!ctl.health) {
        const initial = resolveState(null, null);
        ctl.lane = initial.lane;
        ctl.reasons = initial.reasons;
    }
    applyButton();
    startPolling();
    void refresh();
    return true;
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { init(); });
    } else {
        init();
    }
}
