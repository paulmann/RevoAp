/**
 * debug-trace harness: proves the ?debug=1 instrument actually breaks a
 * self-rescheduling loop and names the call site.
 *
 * The tracer is what we will diagnose a production freeze with, so it gets the
 * same treatment as production code: an offline test that drives a synthetic
 * runaway loop and checks the log it produces.
 *
 * The fake environment mirrors the browser in the one way that matters here -
 * `window` IS the global object, so wrapping `window.setTimeout` wraps the
 * function every module calls.
 *
 *   node --experimental-vm-modules tests/harness/debug-trace.mjs
 */
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
// Works from tests/harness/ inside the repo and from a scratch directory.
const root = process.env.RV_REPO
    || (existsSync(resolve(here, '..', '..', 'assets', 'js', 'debug-trace.js'))
        ? resolve(here, '..', '..')
        : 'C:/AutoClaw/revoap.com');

let passes = 0;
let failures = 0;

function check(label, cond, detail = '') {
    // Written through process.stdout so the console shim above cannot swallow
    // the result of the very checks that inspect it.
    if (cond) {
        process.stdout.write('  ok  ' + label + '\n');
        passes++;
    } else {
        process.stdout.write('  FAIL ' + label + (detail !== '' ? ' - ' + detail : '') + '\n');
        failures++;
    }
}

/** Fresh fake browser: a timer queue we drive by hand. */
function installFakeBrowser() {
    const queue = [];
    const original = {
        setTimeout(fn) { queue.push(fn); return queue.length; },
        setInterval(fn) { queue.push(fn); return queue.length; },
        requestAnimationFrame(fn) { queue.push(fn); return queue.length; },
        addEventListener() {},
    };
    globalThis.window = globalThis;
    globalThis.setTimeout = original.setTimeout;
    globalThis.setInterval = original.setInterval;
    globalThis.requestAnimationFrame = original.requestAnimationFrame;
    globalThis.addEventListener = original.addEventListener;
    return { queue, original };
}

/** Import a fresh copy of the tracer with the console captured. */
async function loadTracer(search, caseName) {
    const captured = [];
    const real = {};
    for (const n of ['log', 'warn', 'error']) {
        real[n] = console[n];
        console[n] = (...args) => captured.push({ level: n, args });
    }
    const restore = () => {
        for (const n of ['log', 'warn', 'error']) console[n] = real[n];
    };
    globalThis.location = { search };
    const url = pathToFileURL(resolve(root, 'assets/js/debug-trace.js')).href + '?case=' + caseName;
    const mod = await import(url);
    return { mod, captured, restore };
}

const messages = (captured) => captured
    .map((c) => c.args.map((a) => (typeof a === 'string' ? a : JSON.stringify(a))).join(' '))
    .join('\n');

// ---------------------------------------------------------------------------
// 1. Without the flag nothing is installed and nothing is logged.
// ---------------------------------------------------------------------------
{
    const { original } = installFakeBrowser();
    const { mod, captured, restore } = await loadTracer('?debug=0', 'off');
    check('without the flag the tracer reports itself inactive', mod.isTracing() === false);
    check('without the flag no timer primitive is replaced',
        globalThis.window.setTimeout === original.setTimeout);
    check('without the flag nothing is logged', captured.length === 0);
    restore();
}

// ---------------------------------------------------------------------------
// 2. With ?debug=1 a runaway loop is detected, described and stopped.
// ---------------------------------------------------------------------------
{
    const { queue, original } = installFakeBrowser();
    const { mod, captured, restore } = await loadTracer('?debug=1', 'on');
    check('with the flag the tracer reports itself active', mod.isTracing() === true);
    check('with the flag the timer primitive is replaced',
        globalThis.window.setTimeout !== original.setTimeout);
    check('an enable line is logged', /enabled via \?debug=1/.test(messages(captured)));

    // A callback that reschedules itself forever: the shape of a frozen tab.
    let fires = 0;
    const runaway = () => {
        fires++;
        globalThis.setTimeout(runaway, 0);
    };
    globalThis.setTimeout(runaway, 0);

    let drained = 0;
    while (queue.length > 0 && drained < 30000) {
        const fn = queue.shift();
        fn();
        drained++;
    }
    // The phase pair is what makes a freeze readable: an unclosed "->" line
    // is the place it froze, so both halves have to reach the console.
    mod.enter('demo:phase', { a: 1 });
    mod.leave('demo:phase', { a: 1 });
    restore();

    const text = messages(captured);
    check('the loop was actually driven', fires > 1000, 'fires=' + fires);
    check('the fast-rescheduling call site is reported', /rescheduling itself very fast/.test(text));
    check('the report names the call site with a real stack frame', /setTimeout <- /.test(text));
    check('the loop is broken, not merely described', /loop broken/.test(text));
    check('the runaway really stopped rescheduling', drained < 29999, 'drained=' + drained);
    check('the breadcrumb trail is dumped for diagnosis', /marks before/.test(text));
    check('a phase entry is printed so the console shows where a freeze started',
        /-> demo:phase/.test(text));
    check('a phase exit prints what the phase cost',
        /<- demo:phase/.test(text) && /tookMs/.test(text));
}

console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
