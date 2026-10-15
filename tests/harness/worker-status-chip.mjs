/**
 * worker-status-chip harness: the header worker indicator must not claim work
 * when the only jobs it knows about are paused.
 *
 * A paused run waits for the operator, not for the worker, so the chip has to
 * show the amber waiting lane instead of the busy spinner. Runs offline: the
 * DOM comes from the shared stub, the module is the real
 * assets/js/worker-status.js, and the stylesheet is read as text so the lane
 * colour is checked next to the lane it colours.
 *
 *   node --experimental-vm-modules tests/harness/worker-status-chip.mjs
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');

let passes = 0;
let failures = 0;

function check(label, cond, detail = '') {
    if (cond) {
        console.log('  ok  ' + label);
        passes++;
    } else {
        console.log('  FAIL ' + label + (detail !== '' ? ' - ' + detail : ''));
        failures++;
    }
}

/** A worker health snapshot the classifier understands. */
function snapshot(overrides = {}) {
    return Object.assign({
        execution_mode: 'job',
        jobs_table_available: true,
        migration_applied: true,
        stale_threshold_sec: 300,
        counts: { queued: 0, running: 0, stale: 0, paused: 0, completed: 3, failed: 0 },
        total_non_terminal: 0,
        running_workers: [],
        workers_alive: 1,
        worker_presence: [],
        worker_versions: [],
        presence_table_available: true,
        app_version: '1.9.0',
        worker_status: 'idle',
        recommendations: [],
    }, overrides);
}

const stub = await import('./dom-stub.mjs');
const parsed = stub.parseHtml('<!doctype html><html><body><div id="oc-worker-status"></div></body></html>');
stub.installGlobals(parsed, {
    fetch: async () => ({ ok: true, status: 200, json: async () => ({}) }),
});

const cache = new Map();
async function load(specifier, referencing) {
    const base = referencing ? dirname(referencing) : resolve(root, 'assets/js');
    const path = resolve(base, specifier);
    if (cache.has(path)) {
        return cache.get(path);
    }
    const source = readFileSync(path, 'utf8');
    const mod = new vm.SourceTextModule(source, { identifier: path, context: vm.createContext(globalThis) });
    cache.set(path, mod);
    await mod.link(async (s, ref) => load(s, ref.identifier));

    return mod;
}

const mod = await load(resolve(root, 'assets/js/worker-status.js'));
await mod.evaluate();
const resolveState = mod.namespace.resolveState;

check('the classifier is exported for offline checks', typeof resolveState === 'function');

const pausedOnly = resolveState(snapshot({
    counts: { queued: 0, running: 0, stale: 0, paused: 1, completed: 3, failed: 0 },
    total_non_terminal: 1,
}), null);
check('a paused job with a free worker is the waiting lane, not busy',
    pausedOnly.lane === 'waiting', String(pausedOnly.lane));
check('and the reason spells out that nothing is running',
    Array.isArray(pausedOnly.reasons)
    && pausedOnly.reasons.length > 0
    && pausedOnly.reasons[0].code === 'waiting'
    && /paused/.test(String(pausedOnly.reasons[0].message)),
    JSON.stringify(pausedOnly.reasons));

const runningToo = resolveState(snapshot({
    counts: { queued: 0, running: 1, stale: 0, paused: 1, completed: 3, failed: 0 },
    total_non_terminal: 2,
}), null);
check('a run that also has real work stays busy', runningToo.lane === 'busy', String(runningToo.lane));

const queuedOnly = resolveState(snapshot({
    counts: { queued: 2, running: 0, stale: 0, paused: 0, completed: 3, failed: 0 },
    total_non_terminal: 2,
}), null);
check('queued work with an alive worker is still busy', queuedOnly.lane === 'busy', String(queuedOnly.lane));

const idle = resolveState(snapshot(), null);
check('an idle worker is still free', idle.lane === 'free', String(idle.lane));

const pausedNoWorker = resolveState(snapshot({
    counts: { queued: 0, running: 0, stale: 0, paused: 1, completed: 3, failed: 0 },
    total_non_terminal: 1,
    workers_alive: 0,
}), null);
check('a paused job with no worker alive is not dressed up as waiting',
    pausedNoWorker.lane !== 'waiting', String(pausedNoWorker.lane));

const css = readFileSync(resolve(root, 'assets/css/components/worker-status.css'), 'utf8');
check('the waiting lane paints an amber dot from the shared token',
    css.includes('.oc-worker-status-dot[data-lane="waiting"]') && css.includes('var(--color-warning)'));
check('and the tooltip lane badge carries the same colour',
    css.includes('.oc-worker-status-tip-lane[data-lane="waiting"]') && css.includes('var(--color-warning)'));
check('the spinner is not applied to the waiting lane',
    !/\[data-lane="waiting"\][^{]*\{[^}]*oc-worker-status-spinner/.test(css));

const source = readFileSync(resolve(root, 'assets/js/worker-status.js'), 'utf8');
check('the chip does not use innerHTML', !source.includes('innerHTML'));

console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
