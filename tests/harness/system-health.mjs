// tests/harness/system-health.mjs — headless checks for the R7 "System
// health" card in the Settings tab.
//
// Run through `php tests/SystemHealthTest.php`'s sibling (it is standalone):
//
//   node --experimental-vm-modules tests/harness/system-health.mjs
//
// Why this harness exists. The card's product is not a colour, it is a
// contract with the operator:
//
//   * every failing check says what is wrong AND renders the button that
//     answers it;
//   * pressing that button actually posts the documented endpoint, and the
//     state is then READ AGAIN - the panel says "State re-read at …", which
//     is the difference between a fix and a hopeful repaint;
//   * a closed admin gate is reported with the server's own words
//     (`Refused (HTTP 401): admin token required`), never as success;
//   * anything that lives outside the application (file ownership, a
//     service restart) is rendered as a command with the disclosure that the
//     panel will not run it, and clicking around it must not fire a request;
//   * an operator who cancels the confirmation sends nothing.
//
// It drives the REAL assets/js/settings.js through the stub DOM with a
// canned /api/health/system report and a fetch log, so what is asserted is
// what an operator would see.

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
console.log('== the panel is shipped, not paraphrased ==');
// =====================================================================
check('settings.js renders the System health card', settingsJs.includes("'oc-system-health-card'"));
check('the card reads the aggregate read-only endpoint', settingsJs.includes("api('/api/health/system')"));
check('the section is mounted by renderSettings()', settingsJs.includes('sectionSystemHealth(),'));
check('the card is read once when the tab renders',
    settingsJs.includes('if (document.getElementById(\'oc-system-health-card\')) void refreshSystemHealth();'));
check('the first-run checklist is rendered', settingsJs.includes('First-run checklist'));
check('every check renders its headline and detail',
    settingsJs.includes('check?.headline') && settingsJs.includes('check.detail'));
check('an external action states that the panel will not run it',
    settingsJs.includes('the panel reports the command and does not run it'));
check('a fix asks for confirmation first', settingsJs.includes('window.confirm'));
check('the action re-reads the state and says so', settingsJs.includes('State re-read at'));
check('the outcome is written into a slot that survives the re-render',
    settingsJs.includes('oc-health-check-note-') && settingsJs.includes('if (note) note.textContent = outcome;'));
check('a refusal is reported with the server\'s own words', settingsJs.includes('Refused (HTTP'));
check('the read-only re-check is a GET, so it carries no body',
    settingsJs.includes("if (method !== 'GET' && method !== 'HEAD') init.body = {};"));
check('settings.js still never uses innerHTML',
    !/\.innerHTML\s*=|\.insertAdjacentHTML\s*\(/.test(settingsJs));

// =====================================================================
console.log('\n== behavioural: the real card, the real actions, the real re-read ==');
// =====================================================================
const stub = await import('./dom-stub.mjs');

function workerSnapshot() {
    return {
        execution_mode: 'job', jobs_table_available: true, migration_applied: true,
        stale_threshold_sec: 300,
        counts: { queued: 0, running: 0, stale: 0, paused: 0, completed: 0, failed: 0 },
        total_non_terminal: 0, running_workers: [], workers_alive: 0, worker_presence: [],
        worker_versions: [], presence_table_available: true, app_version: '1.9.0',
        worker_status: 'idle', recommendations: [], commands: {}, generated_at: '2026-09-24T10:00:00Z',
        code_state: 'current',
    };
}

function checkShape(id, label, severity, headline, detail, actions) {
    return { id, label, severity, headline, detail, actions, facts: {} };
}

const STALE_ACTIONS = [
    {
        id: 'restart_worker', label: 'Restart the workers', kind: 'endpoint',
        target: '/api/worker/restart', method: 'POST', needs_confirmation: true, external: false,
        note: 'Asks every running worker to stop after its current poll.',
    },
    {
        id: 'restart_command', label: 'Restart by hand instead', kind: 'command',
        target: 'sudo systemctl restart revoap-worker.service', method: null,
        needs_confirmation: false, external: true,
        note: 'Run this on the host. The panel reports it and does not run it.',
    },
];

const DIR_ACTIONS = [
    {
        id: 'chown_dir', label: 'Take ownership as the PHP user', kind: 'command',
        target: 'sudo chown -R www-data:www-data cache', method: null,
        needs_confirmation: false, external: true,
        note: 'Run this on the host. The panel reports it and does not run it.',
    },
    {
        id: 'chmod_dir', label: 'Grant write permission', kind: 'command',
        target: 'sudo chmod -R u+w cache', method: null,
        needs_confirmation: false, external: true,
        note: 'Run this on the host. The panel reports it and does not run it.',
    },
];

/** A report with two failures: stale worker code and an unwritable cache. */
function failingReport() {
    return {
        ok: true,
        generated_at: '2026-09-24T10:00:00Z',
        overall: 'error',
        summary: { checks: 6, ok: 4, warn: 0, error: 2, unknown: 0 },
        checks: [
            checkShape('worker_code', 'Worker code version', 'error',
                'The worker is running code older than what is deployed.',
                'PHP loads each class once per process, so a worker started before a deploy keeps the old classes.',
                STALE_ACTIONS),
            checkShape('schema', 'Migrations and schema version', 'ok',
                'The schema is up to date.', '20 of 20 migration file(s) applied.', []),
            checkShape('writable_dirs', 'Writable directories', 'error',
                '1 writable directory/directories cannot be written: Response cache.',
                'Response cache (cache): exists but not writable, mode 0555, owner root, PHP runs as www-data.',
                DIR_ACTIONS),
            checkShape('catalog_freshness', 'Model catalogue freshness', 'ok',
                'The catalogue is current.', '12 of 40 row(s) active.', []),
            checkShape('pool_invariant', 'Pool and roles inside the active catalogue', 'ok',
                'All 2 referenced model(s) are in the active catalogue.', 'Nothing dangles.', []),
            checkShape('db_connection', 'Database connections', 'ok',
                'The database connection is healthy.', 'No loss recorded.', []),
        ],
        setup_checklist: [
            {
                id: 'admin_key', label: 'Admin key', done: true,
                detail: 'An admin key is configured.', actions: [],
            },
            {
                id: 'worker', label: 'Worker process', done: false,
                detail: 'No worker process is running, so queued jobs are never picked up.',
                actions: [{
                    id: 'start_worker', label: 'Start the worker', kind: 'command',
                    target: 'sudo systemctl enable --now revoap-worker.service', method: null,
                    needs_confirmation: false, external: true,
                    note: 'Run this on the host. The panel reports it and does not run it.',
                }],
            },
        ],
        commands: { status: 'php bin/revoap-worker.php --status' },
    };
}

/** Everything green, so the card must offer nothing. */
function healthyReport() {
    const report = failingReport();
    report.overall = 'ok';
    report.summary = { checks: 6, ok: 6, warn: 0, error: 0, unknown: 0 };
    report.checks = report.checks.map((c) => ({ ...c, severity: 'ok', actions: [] }));
    // The checklist is deliberately NOT reset: it answers a different
    // question (is this installation set up), so it stays as it was.
    return report;
}

/**
 * Boot the Settings tab from the shipped layout and settings.js.
 *
 * `reports` is consumed one per `/api/health/system` GET, so the harness can
 * hand the card a different verdict on the re-read — which is the only way
 * to prove the state was read again rather than repainted from memory.
 */
async function boot({ reports, fixResponse, fixStatus = 200, confirm = true }) {
    const parsed = stub.parseHtml(layout);
    const fetchLog = [];
    let reads = 0;
    const stubFetch = async (path, init) => {
        const route = String(path);
        fetchLog.push({ route, method: (init && init.method) || 'GET' });
        const json = (body, status = 200) => ({ ok: status < 300, status, json: async () => body });
        if (route === '/api/settings/status') {
            return json({ tab_enabled: true, admin_configured: true, admin_unlocked: true });
        }
        if (route === '/api/settings') {
            return json({ interface: { theme: 'system' }, app: {}, runtime: {}, prompt_cache: {} });
        }
        if (route === '/api/health/worker') {
            return json(workerSnapshot());
        }
        if (route === '/api/health/system') {
            const report = reports[Math.min(reads, reports.length - 1)];
            reads++;
            return json(report);
        }
        if (route === '/api/worker/restart') {
            return json(fixResponse ?? {
                ok: fixStatus < 300, requested_at: 1790000000, running_jobs: 0, note: 'No job is running.',
            }, fixStatus);
        }
        return json({ ok: true });
    };
    stub.installGlobals(parsed, { fetch: stubFetch });
    // The stub has no window.confirm; the card asks before it posts, and the
    // harness decides the answer.
    globalThis.window.confirm = () => confirm;

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

    const flush = async () => { for (let i = 0; i < 40; i++) { await new Promise((r) => setImmediate(r)); } };
    await flush();

    const tab = parsed.querySelector('[data-tab="settings"]');
    if (tab) { tab.click(); }
    await flush();

    return {
        parsed,
        fetchLog,
        flush,
        reads: () => reads,
        count: (route, method) => fetchLog.filter((e) => e.route === route && (!method || e.method === method)).length,
        card: () => parsed.getElementById('oc-system-health-card'),
        byId: (id) => parsed.getElementById(id),
    };
}

// --- scenario 1: a failing check offers its button, and the button works ---
{
    const env = await boot({ reports: [failingReport(), healthyReport()] });

    check('the card rendered', env.card() !== null);
    check('the card is read once when the tab renders',
        env.count('/api/health/system', 'GET') === 1, String(env.count('/api/health/system', 'GET')));

    const stale = env.byId('oc-health-check-worker_code');
    check('the failing check rendered its own block', stale !== null);
    check('it says what is wrong', stale !== null
        && stale.textContent.includes('running code older than what is deployed'));
    check('it explains why a restart is the only cure', stale !== null
        && stale.textContent.includes('PHP loads each class once per process'));

    const button = env.byId('oc-health-action-worker_code-restart_worker');
    check('the fix is a button, not a dead end', button !== null);

    const command = env.byId('oc-health-command-worker_code-restart_command');
    check('the host-level alternative is shown as a command', command !== null
        && command.textContent.includes('sudo systemctl restart revoap-worker.service'));
    check('and it says the panel will not run it',
        command !== null && command.textContent.includes('does not run it'));

    const ownsCommand = env.byId('oc-health-command-writable_dirs-chown_dir');
    check('an unwritable directory is answered with the chown command, not a button',
        ownsCommand !== null && env.byId('oc-health-action-writable_dirs-chown_dir') === null);

    const before = env.count('/api/worker/restart', 'POST');
    if (button) { button.click(); }
    await env.flush();

    check('pressing the fix posted the documented endpoint',
        before === 0 && env.count('/api/worker/restart', 'POST') === 1,
        JSON.stringify(env.fetchLog));
    check('and the state was READ AGAIN afterwards',
        env.count('/api/health/system', 'GET') === 2, String(env.count('/api/health/system', 'GET')));

    const status = env.byId('oc-health-check-note-worker_code');
    check('the operator is told the state was re-read', status !== null
        && status.textContent.includes('State re-read at'), status ? status.textContent : 'no status node');
    check('and the fresh verdict for that check is printed', status !== null
        && status.textContent.includes('ok'), status ? status.textContent : 'no status node');
    check('the outcome survives the re-render that cleared the action row',
        env.byId('oc-health-action-status-worker_code-restart_worker') === null,
        'the action row is expected to be gone once the check is healthy');
    const freshPill = env.byId('oc-health-check-worker_code');
    check('the check itself now renders the re-read verdict', freshPill !== null
        && freshPill.textContent.includes('ok'));

    const noCommandRequest = env.fetchLog.filter((e) => e.route.includes('chown') || e.route.includes('chmod'));
    check('no command action ever fired a request', noCommandRequest.length === 0,
        JSON.stringify(noCommandRequest));

    const checklist = env.byId('oc-system-health-checklist-block');
    check('the first-run checklist is rendered', checklist !== null
        && checklist.textContent.includes('First-run checklist (1 of 2 done)'),
        checklist ? checklist.textContent.slice(0, 120) : 'missing');
    check('an open checklist item carries its action',
        env.byId('oc-health-action-setup-worker-start_worker') !== null
        || env.byId('oc-health-command-setup-worker-start_worker') !== null);
}

// --- scenario 2: a closed gate is reported, never papered over ------------
{
    const env = await boot({
        reports: [failingReport(), failingReport()],
        fixStatus: 401,
        fixResponse: { ok: false, error: 'unauthorized', message: 'admin token required' },
    });

    const button = env.byId('oc-health-action-worker_code-restart_worker');
    if (button) { button.click(); }
    await env.flush();

    const status = env.byId('oc-health-action-status-worker_code-restart_worker');
    check('a refused action reports the HTTP status', status !== null
        && status.textContent.includes('Refused (HTTP 401)'), status ? status.textContent : 'no status node');
    check('and repeats the server\'s own reason', status !== null
        && status.textContent.includes('admin token required'));
    check('the refusal still triggers a re-read', env.count('/api/health/system', 'GET') === 2,
        String(env.count('/api/health/system', 'GET')));
    const stale = env.byId('oc-health-check-worker_code');
    check('and the check is not painted as fixed', stale !== null
        && stale.textContent.includes('running code older than what is deployed'));
}

// --- scenario 3: cancelling the confirmation sends nothing ----------------
{
    const env = await boot({ reports: [failingReport(), failingReport()], confirm: false });
    const button = env.byId('oc-health-action-worker_code-restart_worker');
    if (button) { button.click(); }
    await env.flush();

    check('cancelling sends no request', env.count('/api/worker/restart', 'POST') === 0,
        JSON.stringify(env.fetchLog));
    const status = env.byId('oc-health-action-status-worker_code-restart_worker');
    check('and it says so', status !== null && status.textContent.includes('Cancelled'),
        status ? status.textContent : 'no status node');
}

// --- scenario 4: a healthy installation offers nothing to press -----------
{
    const env = await boot({ reports: [healthyReport()] });
    const card = env.card();
    check('the card rendered', card !== null);
    check('the banner summarises the verdict', card !== null
        && card.textContent.includes('System health: 6 ok, 0 warning(s), 0 error(s)'));
    check('a healthy card renders no action buttons',
        env.parsed.querySelectorAll('[id^="oc-health-action-"]').length === 0,
        String(env.parsed.querySelectorAll('[id^="oc-health-action-"]').length));
    check('a healthy card renders no commands either',
        env.parsed.querySelectorAll('[id^="oc-health-command-"]').length === 0);
}

// --- scenario 5: the endpoint failing is not a broken tab -----------------
{
    const env = await boot({ reports: [null] });
    await env.flush();
    const card = env.card();
    check('a failing health endpoint renders an inline notice', card !== null);
    check('and the rest of the Settings tab still rendered',
        env.parsed.getElementById('oc-worker-card') !== null
        && env.parsed.getElementById('oc-cache-card') !== null);
}

console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
