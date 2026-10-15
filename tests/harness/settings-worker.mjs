// tests/harness/settings-worker.mjs — headless checks for the Settings →
// Worker card's "the running process predates the deployed code" banner.
//
// Run through `php tests/WorkerCodeStateTest.php` (which supplies node), or
// directly:
//
//   node --experimental-vm-modules tests/harness/settings-worker.mjs
//
// Why this harness exists: the incident it guards against was invisible.
// A long-lived worker keeps the PHP classes it loaded at start-up, so a
// freshly deployed caller meets an old callee inside one process and dies
// with `Call to undefined method …` — a fault no test could provoke and no
// UI reported, because "worker alive" looked identical to "worker alive and
// running last week's code".
//
// The check therefore drives the REAL assets/js/settings.js through the
// stub DOM, with a canned /api/health/worker snapshot, and asserts what the
// operator would read: with `code_state: 'stale'` the card carries a
// warning naming the file, the reason and the restart command; with
// `current` or `unknown` it stays silent (a false alarm here would train
// the operator to ignore the one warning that matters).

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');

let failures = 0;
let passes = 0;

function check(label, cond, detail = '') {
    if (cond) {
        console.log(`  ok  ${label}`);
        passes++;
    } else {
        console.log(`  FAIL ${label}${detail ? ` — ${detail}` : ''}`);
        failures++;
    }
}

function read(rel) {
    const path = resolve(root, rel);
    return existsSync(path) ? readFileSync(path, 'utf8') : '';
}

const layout = read('assets/templates/layout.html');
const settingsJs = read('assets/js/settings.js');

// =====================================================================
console.log('== the stale-code warning is shipped, not paraphrased ==');
// =====================================================================
check('settings.js reads code_state from the health snapshot', settingsJs.includes('snap.code_state'));
check('the warning is rendered for code_state=stale', settingsJs.includes("code_state === 'stale'"));
check('it names the reason the snapshot reports', settingsJs.includes('snap.code_stale_reason'));
check('it names the file that is newer than the process', settingsJs.includes('snap.code_newest_file'));
check('it explains why a restart is the only fix',
    settingsJs.includes('PHP loads each class once per process'));
check('it offers the restart command', settingsJs.includes('sudo systemctl restart revoap-worker'));
check('the warning carries its own id (a banner, not a table cell)',
    settingsJs.includes("'oc-worker-code-stale'"));
check('settings.js still never uses innerHTML',
    !/\.innerHTML\s*=|\.insertAdjacentHTML\s*\(/.test(settingsJs));

// =====================================================================
console.log('\n== behavioural: the real card renders the real snapshot ==');
// =====================================================================
const stub = await import('./dom-stub.mjs');

/** A worker health snapshot, with the v1.8.51 code-state keys. */
function workerSnapshot(codeState, extra = {}) {
    return {
        execution_mode: 'job',
        jobs_table_available: true,
        migration_applied: true,
        stale_threshold_sec: 300,
        counts: { queued: 0, running: 0, stale: 0, paused: 0, completed: 3, failed: 0 },
        total_non_terminal: 0,
        running_workers: [],
        workers_alive: 0,
        worker_presence: [],
        worker_versions: [],
        presence_table_available: true,
        app_version: '1.8.51',
        worker_status: 'idle',
        recommendations: [],
        commands: { status: 'php bin/revoap-worker.php --status' },
        generated_at: '2026-09-21T10:00:00Z',
        code_state: codeState,
        ...extra,
    };
}

/** Boot the Settings tab from the shipped layout and settings.js. */
async function boot(snapshot) {
    const parsed = stub.parseHtml(layout);
    const fetchLog = [];
    const stubFetch = async (path) => {
        const route = String(path);
        fetchLog.push(route);
        const json = (body, status = 200) => ({ ok: status < 300, status, json: async () => body });
        if (route === '/api/settings/status') {
            return json({ tab_enabled: true, admin_configured: true, admin_unlocked: true });
        }
        if (route === '/api/health/worker') {
            return json(snapshot);
        }
        return json({ ok: true });
    };
    stub.installGlobals(parsed, { fetch: stubFetch });

    const cache = new Map();
    async function load(specifier, referencing) {
        const base = referencing ? dirname(referencing) : resolve(root, 'assets/js');
        const path = resolve(base, specifier);
        if (cache.has(path)) { return cache.get(path); }
        const source = readFileSync(path, 'utf8');
        const mod = new vm.SourceTextModule(source, { identifier: path, context: vm.createContext(globalThis) });
        cache.set(path, mod);
        await mod.link(async (s, ref) => load(s, ref.identifier));
        return mod;
    }

    const settingsModule = await load(resolve(root, 'assets/js/settings.js'));
    await settingsModule.evaluate();

    const flush = async () => { for (let i = 0; i < 12; i++) { await new Promise((r) => setImmediate(r)); } };
    await flush();

    // Opening the tab is what renders the cards (and arms the 5s poller).
    const tab = parsed.querySelector('[data-tab="settings"]');
    if (tab) { tab.click(); }
    await flush();

    // The card refreshes when it scrolls into view; the stub observer does
    // not fire on its own, so the harness delivers the entry a browser
    // would — which is also the only way the code path is exercised.
    const card = parsed.getElementById('oc-worker-card');
    const observer = (globalThis.__observers || []).find((o) => o.targets.includes(card));
    check('the worker card arms its visibility-gated refresh', observer !== undefined);
    if (observer) {
        observer.callback([{ isIntersecting: true, target: card }]);
    }
    await flush();

    return { parsed, fetchLog, flush, card };
}

const STALE = workerSnapshot('stale', {
    code_stale_reason: 'src/Models/ModelsActualizer.php was modified at 2026-09-21T09:00:00Z, '
        + 'after the worker process started at 2026-09-21T08:00:00Z — the running process still holds '
        + 'the classes it loaded then.',
    code_newest_at: '2026-09-21T09:00:00Z',
    code_newest_file: 'src/Models/ModelsActualizer.php',
});

{
    const env = await boot(STALE);
    check('the health endpoint was actually asked', env.fetchLog.includes('/api/health/worker'));
    const banner = env.parsed.getElementById('oc-worker-code-stale');
    check('code_state=stale renders a warning banner', banner !== null);
    const text = banner ? banner.textContent : '';
    check('the banner says the worker is running stale code',
        text.includes('running stale code'), text.slice(0, 160));
    check('the banner tells the operator to restart it',
        text.toLowerCase().includes('restart'), text.slice(0, 200));
    check('it names the file that is newer than the process',
        text.includes('src/Models/ModelsActualizer.php'), text);
    check('it repeats the reason the snapshot gave',
        text.includes('after the worker process started at'), text);
    check('it explains that PHP keeps the loaded classes',
        text.includes('PHP loads each class once per process'), text);
    check('it shows the failure this explains',
        text.includes('Call to undefined method'), text);
    check('the restart command is offered for copying',
        text.includes('sudo systemctl restart revoap-worker'), text);
    check('the banner is an error-level alert, not muted fine print',
        banner !== null && banner.classList.contains('oc-alert-error'),
        banner ? banner.className : 'no banner');
}

{
    const env = await boot(workerSnapshot('current'));
    check('code_state=current renders no warning',
        env.parsed.getElementById('oc-worker-code-stale') === null);
    check('the worker card itself still rendered',
        env.parsed.getElementById('oc-worker-live') !== null
        && env.parsed.getElementById('oc-worker-live').textContent.includes('Queue'));
}

{
    const env = await boot(workerSnapshot('unknown'));
    check('code_state=unknown is not dressed up as stale',
        env.parsed.getElementById('oc-worker-code-stale') === null);
}

console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
