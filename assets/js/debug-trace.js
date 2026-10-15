// debug-trace.js - deep, opt-in trace for `?debug=1`.
//
// Why this exists. A real install froze hard on one History entry: the pane
// painted "Loading." and then the tab stopped answering, the CPU stayed busy
// and only a page reload recovered it. Nothing in the server logs and nothing
// in an offline render harness reproduced it - the loop only happens in a real
// browser. This module is the instrument for exactly that class of bug.
//
// What it does when (and only when) `?debug=1` is present:
//
//   1. Prints every named phase on entry and exit, with the duration. A phase
//      that enters and never exits is the freeze: the last `-> name` line in
//      the console is the culprit, which is why the log is useful even when
//      the tab never comes back.
//   2. Wraps setTimeout / setInterval / requestAnimationFrame so that ANY code
//      path that keeps rescheduling itself is counted per call site - the
//      shape a frozen tab almost always has, whatever module the loop lives in.
//   3. Breaks the loop instead of only describing it: once a call site crosses
//      the hard ceiling its callbacks are dropped, the tab recovers, and the
//      console says which call site was dropped and why.
//   4. Keeps the last 80 breadcrumbs in memory and dumps them when something
//      goes wrong.
//
// It is inert without the flag: every export is a no-op and nothing is
// installed. It never logs payload contents - only names, counts, timings and
// stacks - so the log can be pasted as-is.

const PREFIX = 'oc-trace';

const ENABLED = (() => {
    try {
        if (typeof location === 'undefined') return false;
        return /[?&]debug=(1|true)\b/i.test(String(location.search || ''));
    } catch {
        return false;
    }
})();

/** Per-call-site counters, keyed by the top stack frame. */
const sites = new Map();
/** Rolling breadcrumb trail. */
const trail = [];
const TRAIL_MAX = 80;
/** Open phases, so an exit can report the duration and an unclosed one is obvious. */
const open = [];

const SOFT_PER_SECOND = 120;
const HARD_TOTAL = 20000;
const PRINT_PER_SECOND = 60;

let phase = 'boot';
let seq = 0;
let lastSecond = 0;
let lastSecondTotal = 0;
let printedThisSecond = 0;
let suppressed = 0;
let heartbeat = null;
let broken = 0;

function stackKey() {
    try {
        const lines = String(new Error().stack || '').split('\n');
        return (lines[2] || lines[1] || 'unknown').trim().slice(0, 180);
    } catch {
        return 'unknown';
    }
}

function openSecond(now) {
    const second = Math.floor(now / 1000);
    if (second !== lastSecond) {
        if (suppressed > 0) {
            console.log('[' + PREFIX + '] ... ' + suppressed + ' more lines suppressed in the previous second');
            suppressed = 0;
        }
        lastSecond = second;
        lastSecondTotal = 0;
        printedThisSecond = 0;
    }
}

/** Rate-limited console output; the trail keeps everything regardless. */
function say(level, message, fields) {
    const now = Date.now();
    openSecond(now);
    if (printedThisSecond >= PRINT_PER_SECOND) {
        suppressed++;
        return;
    }
    printedThisSecond++;
    const payload = Object.assign({ t: now, phase, seq }, fields || {});
    const fn = level === 'warn' ? console.warn : (level === 'error' ? console.error : console.log);
    fn.call(console, '[' + PREFIX + '] ' + message, payload);
}

/** A breadcrumb. Cheap enough for hot paths: one object push. */
export function mark(name, fields) {
    if (!ENABLED) return;
    seq++;
    trail.push({ seq, at: Date.now(), phase, name, fields: fields || null });
    if (trail.length > TRAIL_MAX) trail.shift();
}

/** Enter a named phase: printed, so the log shows where a freeze started. */
export function enter(name, fields) {
    if (!ENABLED) return;
    phase = name;
    seq++;
    const at = Date.now();
    open.push({ name, at, seq });
    trail.push({ seq, at, phase: name, name: 'enter:' + name, fields: fields || null });
    if (trail.length > TRAIL_MAX) trail.shift();
    const depth = open.length > 1 ? ' (depth ' + open.length + ')' : '';
    say('log', '-> ' + name + depth, fields || undefined);
}

/** Leave a named phase: prints what it cost. A missing exit line is the freeze. */
export function leave(name, fields) {
    if (!ENABLED) return;
    let idx = -1;
    for (let i = open.length - 1; i >= 0; i--) {
        if (open[i].name === name) { idx = i; break; }
    }
    let ms = null;
    if (idx >= 0) {
        ms = Date.now() - open[idx].at;
        open.splice(idx, 1);
    }
    seq++;
    trail.push({ seq, at: Date.now(), phase: name, name: 'leave:' + name, fields: fields || null });
    if (trail.length > TRAIL_MAX) trail.shift();
    phase = open.length > 0 ? open[open.length - 1].name : 'idle';
    const extra = Object.assign({ tookMs: ms }, fields || {});
    say('log', '<- ' + name, extra);
}

function dumpTrail(reason) {
    say('error', 'last ' + trail.length + ' marks before ' + reason, { trail: trail.slice(-40) });
}

function countCall(site) {
    const now = Date.now();
    openSecond(now);
    let entry = sites.get(site);
    if (!entry) {
        entry = { total: 0, inSecond: 0, reported: false };
        sites.set(site, entry);
    }
    entry.total++;
    entry.inSecond++;
    lastSecondTotal++;

    if (!entry.reported && entry.inSecond > SOFT_PER_SECOND) {
        entry.reported = true;
        say('warn', 'call site rescheduling itself very fast', {
            site,
            callsThisSecond: entry.inSecond,
            callsTotal: entry.total,
        });
        dumpTrail('fast rescheduling');
    }

    if (entry.total > HARD_TOTAL) {
        broken++;
        if (broken <= 5) {
            say('error', 'loop broken: dropping callbacks from this call site', {
                site,
                callsTotal: entry.total,
                note: 'the tab should recover now; send this line and the trail above',
            });
            dumpTrail('loop break');
        }
        return false;
    }
    return true;
}

function wrapScheduler(host, name) {
    const original = host && host[name];
    if (typeof original !== 'function') return;
    host[name] = function tracedScheduler(...args) {
        const callback = args[0];
        if (typeof callback !== 'function') return original.apply(this, args);
        const site = name + ' <- ' + stackKey();
        if (!countCall(site)) return 0; // loop breaker: drop, do not reschedule
        const self = this;
        args[0] = function tracedCallback(...inner) {
            mark(name + ':fire', { site: site.slice(0, 90) });
            return callback.apply(self, inner);
        };
        return original.apply(this, args);
    };
}

function install() {
    if (typeof window === 'undefined') return;
    wrapScheduler(window, 'setTimeout');
    wrapScheduler(window, 'setInterval');
    wrapScheduler(window, 'requestAnimationFrame');

    if (typeof window.addEventListener === 'function') {
        window.addEventListener('error', (event) => {
            say('error', 'window error', {
                message: String(event && event.message || ''),
                source: String(event && event.filename || '') + ':' + String(event && event.lineno || ''),
            });
        });
        window.addEventListener('unhandledrejection', (event) => {
            say('error', 'unhandled rejection', {
                reason: String((event && event.reason && (event.reason.message || event.reason)) || ''),
            });
        });
    }

    // Heartbeat only when timers actually ran, so an idle tab stays quiet.
    heartbeat = setInterval(() => {
        if (lastSecondTotal === 0 && open.length === 0) return;
        say('log', 'heartbeat', {
            timersSeen: lastSecondTotal,
            callSites: sites.size,
            loopsBroken: broken,
            openPhases: open.map((p) => p.name + '+' + (Date.now() - p.at) + 'ms'),
        });
    }, 30000);

    say('log', 'enabled via ?debug=1 - every phase prints as "-> name" and "<- name"', {
        note: 'a "-> name" with no matching "<- name" is where it froze',
    });
}

if (ENABLED) {
    install();
}

/** True when the tracer is active (used by callers to skip extra work). */
export function isTracing() {
    return ENABLED;
}
