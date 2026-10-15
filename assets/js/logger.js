// logger.js — v1.8.22 — structured Chrome console logger.
//
// Reads `app.log_to_console` from the layout's <meta name="oc-log-to-console">
// (rendered from config.php) and exposes a small API used throughout the
// frontend. Logs ONLY metadata useful for debugging — never prompt or
// answer text, never API keys. Bodies are included only if the operator
// explicitly opts in by setting `window.OC_DEBUG_VERBOSE = true` from
// the browser console.
//
// Public API:
//   isEnabled()                  — whether console logging is on now
//   log(tag, fields)             — generic info-level event
//   warn(tag, fields)            — warning level
//   error(tag, fields, err)      — error level
//   apiStart(method, url) -> id  — opens a fetch span, returns request id
//   apiEnd(id, response, fields) — closes a fetch span (no body!)
//   apiError(id, err, fields)    — closes a fetch span with an error
//   sse(event, fields)           — sse lifecycle log (open/event/error/close)
//   pipelineEvent(evt)           — log a pipeline SSE event (metadata only)
//   control(action, fields)      — control button action log
//   activity(state, fields)      — current activity state change
//   nonJsonPreview(url, ct, txt) — surface a non-JSON HTML preview that
//                                  triggered an "Unexpected token '<'"
//                                  parse-attempt, sanitized for log
//
// All public functions are no-ops when console logging is disabled,
// except `nonJsonPreview` and `error` which ALWAYS surface (the operator
// needs to see those even when logs are otherwise silent — they are the
// smoking gun for the regression class this version fixes).

const META_LOG_TO_CONSOLE = 'oc-log-to-console';
const META_APP_DEBUG      = 'oc-app-debug';
const META_APP_VERSION    = 'oc-app-version';
const META_BUILD          = 'oc-build';

function readMeta(name) {
    try {
        const el = document.querySelector(`meta[name="${name}"]`);
        return el ? String(el.content || '').trim() : '';
    } catch {
        return '';
    }
}

function readUrlOverride() {
    try {
        const m = /[?&]debug=(0|1|true|false)\b/i.exec(location.search || '');
        if (!m) return null;
        const v = String(m[1] || '').toLowerCase();
        if (v === '1' || v === 'true') return true;
        if (v === '0' || v === 'false') return false;
    } catch { /* SSR-safe */ }
    return null;
}

function readBodyOverride() {
    try {
        if (typeof document !== 'undefined' && document.body && document.body.dataset && document.body.dataset.debug) {
            const v = String(document.body.dataset.debug).toLowerCase();
            if (v === '1' || v === 'true') return true;
            if (v === '0' || v === 'false') return false;
        }
    } catch { /* noop */ }
    return null;
}

function readWindowOverride() {
    try {
        if (typeof window === 'undefined') return null;
        if (window.OC_DEBUG === true) return true;
        if (window.OC_DEBUG === false) return false;
    } catch { /* noop */ }
    return null;
}

// Resolve once at module load. Higher precedence first: URL ?debug=,
// `window.OC_DEBUG`, body[data-debug], the layout meta, default true.
const resolved = (() => {
    const url = readUrlOverride();
    if (url !== null) return url;
    const win = readWindowOverride();
    if (win !== null) return win;
    const body = readBodyOverride();
    if (body !== null) return body;
    const meta = readMeta(META_LOG_TO_CONSOLE).toLowerCase();
    if (meta === 'true')  return true;
    if (meta === 'false') return false;
    // Default: ON. We bias toward debuggability of fresh installs because
    // this version was cut to fix a parse-error regression and operators
    // need the console output to root-cause the next surprise quickly.
    return true;
})();

const VERSION   = readMeta(META_APP_VERSION) || 'unknown';
const BUILD     = readMeta(META_BUILD)       || 'unknown';
const APP_DEBUG = (readMeta(META_APP_DEBUG).toLowerCase() === 'true');
const VERBOSE   = (typeof window !== 'undefined' && window.OC_DEBUG_VERBOSE === true);

// Compact a payload object so very long string fields do not blow up the
// devtools console. Strings longer than 200 chars are truncated to
// 60 + "…" unless verbose mode is on. Prompt/answer bodies live in
// fields named `content`, `prompt`, `messages`, `answer`, etc — these
// are dropped entirely from the metadata view unless VERBOSE.
const PROMPT_FIELDS = new Set([
    'content', 'prompt', 'messages', 'answer', 'response',
    'system', 'user', 'final_answer', 'preview', 'summary',
]);

function compact(value, depth = 0) {
    if (value == null) return value;
    if (Array.isArray(value)) {
        return depth > 2 ? `[Array(${value.length})]` : value.map((v) => compact(v, depth + 1));
    }
    if (typeof value === 'object') {
        if (depth > 3) return '[Object]';
        const out = {};
        for (const [k, v] of Object.entries(value)) {
            if (!VERBOSE && PROMPT_FIELDS.has(k)) {
                if (typeof v === 'string') {
                    out[k] = `[redacted ${v.length} chars; set window.OC_DEBUG_VERBOSE=true to view]`;
                } else if (Array.isArray(v)) {
                    out[k] = `[redacted ${v.length} items]`;
                } else if (v !== null && typeof v === 'object') {
                    out[k] = '[redacted object]';
                } else {
                    out[k] = v;
                }
                continue;
            }
            out[k] = compact(v, depth + 1);
        }
        return out;
    }
    if (typeof value === 'string') {
        if (VERBOSE || value.length <= 200) return value;
        return value.slice(0, 60) + `… [${value.length} chars]`;
    }
    return value;
}

const TAG_PREFIX = '[RevoAp]';
let requestCounter = 0;
const inflightRequests = new Map();

export function isEnabled() {
    return resolved;
}

export function bootBanner() {
    if (!resolved) return;
    try {
        console.log(
            `${TAG_PREFIX} v${VERSION} build=${BUILD} console-logs=on debug=${APP_DEBUG ? 'on' : 'off'} ` +
            `(toggle via window.OC_DEBUG=false, ?debug=0 or app.log_to_console). ` +
            `Verbose payloads: set window.OC_DEBUG_VERBOSE=true.`
        );
    } catch { /* noop */ }
}

export function log(tag, fields = {}) {
    if (!resolved) return;
    try { console.log(TAG_PREFIX, tag, compact(fields)); } catch { /* noop */ }
}

export function warn(tag, fields = {}) {
    if (!resolved) return;
    try { console.warn(TAG_PREFIX, tag, compact(fields)); } catch { /* noop */ }
}

export function error(tag, fields = {}, err = null) {
    try {
        const payload = compact(fields);
        if (err) console.error(TAG_PREFIX, tag, payload, err);
        else     console.error(TAG_PREFIX, tag, payload);
    } catch { /* noop */ }
}

export function apiStart(method, url) {
    const id = 'r' + (++requestCounter).toString(36);
    inflightRequests.set(id, { method, url, startedAt: Date.now() });
    if (resolved) {
        try {
            const u = stripQuery(url);
            console.log(TAG_PREFIX, 'api.start', { id, method, path: u.path, hasQuery: u.hadQuery });
        } catch { /* noop */ }
    }
    return id;
}

export function apiEnd(id, response, extra = {}) {
    const rec = inflightRequests.get(id);
    inflightRequests.delete(id);
    if (!resolved) return;
    const duration = rec ? (Date.now() - rec.startedAt) : null;
    const u = rec ? stripQuery(rec.url) : { path: '?', hadQuery: false };
    const ct = response?.headers?.get ? (response.headers.get('content-type') || '') : '';
    const body = {
        id,
        method:       rec?.method,
        path:         u.path,
        status:       response?.status,
        ok:           response?.ok,
        content_type: ct,
        duration_ms:  duration,
        ...compact(extra),
    };
    try { console.log(TAG_PREFIX, 'api.end', body); } catch { /* noop */ }
}

export function apiError(id, err, extra = {}) {
    const rec = inflightRequests.get(id);
    inflightRequests.delete(id);
    const duration = rec ? (Date.now() - rec.startedAt) : null;
    const u = rec ? stripQuery(rec.url) : { path: '?', hadQuery: false };
    const body = {
        id,
        method:      rec?.method,
        path:        u.path,
        duration_ms: duration,
        error_name:  err?.name,
        error_msg:   err?.message,
        ...compact(extra),
    };
    try { console.error(TAG_PREFIX, 'api.error', body, err); } catch { /* noop */ }
}

export function sse(event, fields = {}) {
    if (!resolved) return;
    try { console.log(TAG_PREFIX, 'sse.' + event, compact(fields)); } catch { /* noop */ }
}

export function pipelineEvent(evt) {
    if (!resolved) return;
    if (!evt || typeof evt !== 'object') return;
    const p = evt.payload || {};
    const meta = {
        type:         evt.type,
        step_id:      evt.step_id,
        at:           evt.at,
        role:         p.role,
        round:        p.round,
        cycle:        p.cycle,
        cycle_limit:  p.cycle_limit,
        model:        p.model,
        provider:     p.provider,
        status:       p.status,
        cache_hit:    p.cache_hit,
        latency_ms:   p.latency_ms,
        total_tokens: p.total_tokens,
        cost:         p.cost,
        session_id:   p.session_id,
        reason:       p.reason ?? p.loop_stop_reason,
        error_class:  p.class,
        error_msg:    p.message,
    };
    const clean = Object.fromEntries(Object.entries(meta).filter(([, v]) => v !== undefined));
    try {
        if (VERBOSE) {
            console.log(TAG_PREFIX, 'pipeline.event', clean, '(verbose payload)', compact(p));
        } else {
            console.log(TAG_PREFIX, 'pipeline.event', clean);
        }
    } catch { /* noop */ }
}

export function control(action, fields = {}) {
    if (!resolved) return;
    try { console.log(TAG_PREFIX, 'control', { action, ...compact(fields) }); } catch { /* noop */ }
}

export function activity(state, fields = {}) {
    if (!resolved) return;
    try { console.log(TAG_PREFIX, 'activity', { state, ...compact(fields) }); } catch { /* noop */ }
}

// Surface a non-JSON response that was about to be JSON-parsed. ALWAYS
// emits, even when logging is off — this is the operator's window into
// the "Unexpected token '<'" failure mode.
export function nonJsonPreview(url, contentType, status, bodyText) {
    const u = stripQuery(url);
    const preview = previewBody(bodyText);
    try {
        console.warn(TAG_PREFIX, 'api.non_json', {
            path:         u.path,
            status:       status,
            content_type: contentType,
            preview_len:  preview.length,
            preview,
        });
    } catch { /* noop */ }
}

function stripQuery(url) {
    try {
        const u = new URL(url, location.origin);
        return { path: u.pathname, hadQuery: !!u.search };
    } catch {
        const i = String(url).indexOf('?');
        return i >= 0 ? { path: String(url).slice(0, i), hadQuery: true }
                      : { path: String(url),             hadQuery: false };
    }
}

function previewBody(text) {
    if (typeof text !== 'string') return '';
    const cleaned = String(text).replace(/\s+/g, ' ').trim().slice(0, 1000);
    return cleaned;
}

// ---------------------------------------------------------------------
// Safe JSON fetch wrapper. Validates the content-type before calling
// `response.json()`. When the body is not JSON, throws a
// `NonJsonResponseError` whose `preview` field holds the first ~1000
// chars of the body so the chat status / activity card can surface it.
// ---------------------------------------------------------------------

export class NonJsonResponseError extends Error {
    constructor({ url, status, contentType, preview }) {
        super(`non-JSON response from ${url}: status=${status} content-type=${contentType || '(unset)'}`);
        this.name = 'NonJsonResponseError';
        this.url = url;
        this.status = status;
        this.contentType = contentType;
        this.preview = preview;
    }
}

export async function fetchJson(input, init = {}) {
    const url = typeof input === 'string' ? input : (input?.url || '');
    const method = (init && init.method) || 'GET';
    const id = apiStart(method, url);
    let res;
    try {
        res = await fetch(input, init);
    } catch (err) {
        apiError(id, err);
        throw err;
    }
    const ct = res.headers.get('content-type') || '';
    const isJson = /\bapplication\/(?:json|problem\+json)|\btext\/json\b/i.test(ct);
    if (!isJson) {
        let text = '';
        try { text = await res.text(); } catch { /* noop */ }
        nonJsonPreview(url, ct, res.status, text);
        apiEnd(id, res, { non_json: true });
        throw new NonJsonResponseError({
            url,
            status: res.status,
            contentType: ct,
            preview: previewBody(text),
        });
    }
    let data = null;
    try {
        data = await res.json();
    } catch (err) {
        apiError(id, err, { content_type: ct, status: res.status });
        throw err;
    }
    apiEnd(id, res);
    return { response: res, data };
}

bootBanner();
