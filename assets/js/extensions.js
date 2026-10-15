// extensions.js - admin Extensions tab (MD.Scribe install / update).
//
// v2.1.0 (T4): fresh installs are MD.Scribe (github.com/paulmann/MD.Scribe,
// the successor of MD.Viewer, installed from the pinned + sha256-verified
// release archive). An existing extensions/md-viewer/ install keeps being
// served through its legacy raw-updater path unchanged; the server says which
// product is active (`status.product`) and everything below is rendered from
// that payload — the MD.Scribe strings are fallbacks, not assumptions.
//
// Three steps, each its own server round-trip so the operator always sees
// what actually happened:
//
//   1. State   - GET /api/extensions/status (repo, branch, local installer,
//                instance version, network reachability).
//   2. Download- POST /api/extensions/download: the SERVER fetches
//                updater.php from GitHub; the browser never talks to GitHub.
//   3. Check   - GET /api/extensions/check: the server reads API_KEY from
//                .md.ini and runs action=conflicts (read-only), rendering the
//                per-file table.
//   4. Install - POST /api/extensions/install {dry_run:true} shows the plan,
//                a separate confirm POST {dry_run:false} performs it.
//                The selected .md.ini values are sent along, so the run
//                uses what the operator chose instead of the defaults.
//   5. Ini     - GET  /api/extensions/settings renders the .md.ini menu
//                (one true/false select per boolean key, one text field for
//                the BROWSE_DIR string setting, plus file/key/CLI state);
//                POST /api/extensions/settings writes the selection and
//                POST /api/extensions/rotate-key rotates the machine key.
//
// Everything the server returns is rendered with textContent only - never
// innerHTML - so a hostile/odd installer answer stays text. The API_KEY is a
// server secret: it is never sent to this module, and nothing here ever asks
// for it.
//
// The DOM/fetch helpers and the shared admin lock screen come from
// admin-ui.js (the same ones Models and Settings use), so unlocking in any
// admin tab unlocks here too.

import {
    el, clear, api, adminProtected, renderProtectionNotice, createLockScreen,
} from './admin-ui.js';

const TAB_BUTTONS = document.querySelectorAll('[data-tab]');
const TAB_PANELS  = document.querySelectorAll('[data-tab-panel]');
const EXTENSIONS_TAB = document.getElementById('oc-tab-extensions');
const ROOT = document.getElementById('oc-extensions-root');

const state = {
    status: null,
    notice: null,     // {kind:'error'|'warn'|'ok', text:string}
    conflicts: null,  // last successful check payload
    plan: null,       // last dry-run install payload
    result: null,     // last real install payload
    ini: null,        // last /api/extensions/settings payload (no key material)
    busy: false,
};

function activateTab(name) {
    TAB_BUTTONS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    TAB_PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
    if (name === 'extensions') { void loadAndRender(); }
}

TAB_BUTTONS.forEach(t => {
    if (t.dataset.tab === 'extensions') {
        t.addEventListener('click', () => activateTab('extensions'));
    }
});

async function probeStatus() {
    const { body } = await api('/api/extensions/status');
    state.status = body || { tab_enabled: false };
    const enabled = state.status.tab_enabled === true;
    if (EXTENSIONS_TAB) EXTENSIONS_TAB.hidden = !enabled;
}

async function loadAndRender() {
    if (!ROOT) return;
    const { body } = await api('/api/extensions/status');
    state.status = body || { tab_enabled: false };
    const s = state.status;
    if (s.tab_enabled !== true) {
        renderDisabled('Extensions tab is disabled in config.php (ExtensionsTab=false).');
        return;
    }
    if (adminProtected(s) && !s.admin_configured) {
        renderDisabled('Admin token not configured. Set REVOAP_ADMIN_TOKEN or .admin.key first.');
        return;
    }
    if (!s.admin_unlocked) {
        createLockScreen(ROOT, {
            title: 'Extensions',
            description: 'Unlock with the admin token to install or update ' + ((state.status && state.status.repository && state.status.repository.name) || 'MD.Scribe') + '.',
            onUnlocked: () => loadAndRender(),
        });
        return;
    }
    // The .md.ini menu is admin-only on the server (it reports the file
    // state and probes for a PHP CLI interpreter - never key material), so
    // an anonymous page view cannot cost a subprocess. A failure to read it
    // is reported rather than swallowed.
    const ini = await api('/api/extensions/settings');
    if (ini.body && ini.body.ok === true) {
        state.ini = ini.body;
    } else if (!state.notice) {
        state.notice = { kind: 'warn', text: (ini.body && ini.body.message)
            || ('The .md.ini settings could not be read (HTTP ' + ini.status + ').') };
    }
    render();
}

function renderDisabled(msg) {
    clear(ROOT);
    ROOT.append(el('div', { class: 'oc-alert oc-alert-warn', text: msg }));
}

function render() {
    clear(ROOT);
    const s = state.status || {};
    renderProtectionNotice(ROOT, s);

    ROOT.append(el('h2', { class: 'oc-ext-title', text: 'Extensions' }));
    ROOT.append(el('p', { class: 'oc-ext-subtitle',
        text: (s.repository && s.repository.legacy)
            ? 'Install and update the third-party MD.Viewer extension from GitHub. This installation predates MD.Scribe and keeps using MD.Viewer.'
            : 'Install and update the third-party MD.Scribe extension from GitHub (the successor of MD.Viewer).' }));

    ROOT.append(renderStateCard(s));
    ROOT.append(renderActions());
    if (state.ini) ROOT.append(renderSettings(state.ini));

    if (state.notice) {
        const cls = state.notice.kind === 'ok' ? 'oc-alert oc-alert-ok'
            : state.notice.kind === 'warn' ? 'oc-alert oc-alert-warn'
            : 'oc-alert oc-alert-error';
        ROOT.append(el('div', { class: cls, text: state.notice.text }));
    }

    if (state.conflicts) ROOT.append(renderConflicts(state.conflicts));
    if (state.plan)      ROOT.append(renderPlan(state.plan));
    if (state.result)    ROOT.append(renderResult(state.result));
}

function kv(label, value, cls) {
    const row = el('div', { class: 'oc-ext-kv' });
    row.append(el('span', { class: 'oc-ext-kv-key', text: label }));
    row.append(el('span', { class: 'oc-ext-kv-val' + (cls ? ' ' + cls : ''), text: value == null || value === '' ? '-' : String(value) }));
    return row;
}

function renderStateCard(s) {
    const card = el('section', { class: 'oc-ext-card' });
    card.append(el('h3', { text: 'State' }));

    const repo = s.repository || {};
    const inst = s.installer || {};
    const instance = s.instance || {};
    const net = s.network;
    const transport = s.transport || {};

    card.append(kv('Repository', (repo.name || 'MD.Scribe')
        + (repo.branch ? ' @ ' + repo.branch : '')
        + (repo.release_tag ? ' @ ' + repo.release_tag : '')
        + (repo.legacy ? ' (legacy)' : '')));
    card.append(kv('Source', repo.path || '-'));
    card.append(kv('Transport', transport.available ? transport.kind : 'unavailable (no HTTPS)'));
    card.append(kv('Installer', inst.present
        ? ('present, version ' + (inst.version || 'unknown') + (inst.api_supported ? '' : ' (machine API unavailable)'))
        : 'not downloaded'));
    card.append(kv('Instance', instance.installed
        ? ('installed, version ' + (instance.version || 'unknown'))
        : 'not installed'));
    card.append(kv('Directory', s.directory || 'extensions/md-scribe'));
    // During a migration both trees can exist; the panel says so instead of
    // letting the operator guess which one the share links will use.
    if (s.legacy && s.legacy.present && s.product !== 'md-viewer') {
        card.append(kv('Legacy install', 'extensions/md-viewer/ still exists - share links prefer the installed MD.Scribe tree'));
    }
    if (s.release && s.release.zip_supported === false) {
        card.append(kv('Release extraction', 'the PHP zip extension is missing - install it or deploy MD.Scribe manually', 'oc-ext-bad'));
    }

    if (net && net.checked) {
        const label = net.reachable ? 'reachable' : 'unreachable';
        const detail = net.reachable
            ? (' (HTTP ' + net.http_status + ', ' + net.bytes + ' bytes, ' + net.ms + ' ms)')
            : (net.message ? ' - ' + net.message : '');
        card.append(kv('Network', label + detail, net.reachable ? 'oc-ext-ok' : 'oc-ext-bad'));
    } else {
        card.append(kv('Network', 'not checked yet'));
    }
    return card;
}

function renderActions() {
    const bar = el('div', { class: 'oc-ext-actions' });
    bar.append(el('button', {
        type: 'button', class: 'oc-button', id: 'oc-ext-refresh', text: 'Refresh state',
        onclick: () => { state.notice = null; void loadAndRender(); },
    }));
    bar.append(el('button', {
        type: 'button', class: 'oc-button', id: 'oc-ext-download', text: 'Download installer',
        onclick: () => { void runDownload(); },
    }));
    bar.append(el('button', {
        type: 'button', class: 'oc-button', id: 'oc-ext-check', text: 'Check before install',
        onclick: () => { void runCheck(); },
    }));
    bar.append(el('button', {
        type: 'button', class: 'oc-button', id: 'oc-ext-install', text: 'Install (plan first)',
        onclick: () => { void runInstall(true); },
    }));
    return bar;
}

async function runDownload() {
    if (state.busy) return;
    state.busy = true;
    state.notice = null;
    const { ok, body, status } = await api('/api/extensions/download', { method: 'POST', body: {} });
    state.busy = false;
    if (status === 404 && body?.error === 'extensions_disabled') { renderDisabled('Extensions tab is disabled in config.php (ExtensionsTab=false).'); return; }
    if (adminProtected(state.status) && status === 401) { state.status.admin_unlocked = false; void loadAndRender(); return; }
    if (body?.state) state.status = Object.assign({}, state.status, body.state);
    if (!ok || body?.ok !== true) {
        state.notice = { kind: 'error', text: (body?.message || ('Download failed (HTTP ' + status + ')')) };
    } else if (body.api_supported === false) {
        state.notice = { kind: 'warn', text: body.warning || 'The downloaded installer is older than the machine API version.' };
    } else {
        state.notice = { kind: 'ok', text: 'Installer ' + (body.version || '?') + ' downloaded (' + body.bytes + ' bytes, sha256 ' + String(body.sha256).slice(0, 12) + '…).' };
    }
    render();
}

async function runCheck() {
    if (state.busy) return;
    state.busy = true;
    state.notice = null;
    const { ok, body, status } = await api('/api/extensions/check');
    state.busy = false;
    if (status === 404 && body?.error === 'extensions_disabled') { renderDisabled('Extensions tab is disabled in config.php (ExtensionsTab=false).'); return; }
    if (adminProtected(state.status) && status === 401) { state.status.admin_unlocked = false; void loadAndRender(); return; }
    if (body?.ok !== true) {
        state.conflicts = null;
        state.notice = { kind: body?.format_valid === false ? 'warn' : 'error', text: describeFailure(body, 'Check failed') };
    } else {
        state.conflicts = body;
        state.notice = { kind: 'ok', text: 'Conflict report received for version ' + (body.version || '?') + '.' };
        await refreshIni();
    }
    render();
}

async function runInstall(dryRun) {
    if (state.busy) return;
    if (!dryRun) {
        const repoName = (state.status && state.status.repository && state.status.repository.name) || 'MD.Scribe';
        const dirName = (state.status && state.status.directory) || 'extensions/md-scribe';
        const confirmed = window.confirm('Install ' + repoName + ' into ' + dirName + '/ now? Existing foreign files are skipped unless force is enabled.');
        if (!confirmed) return;
    }
    state.busy = true;
    state.notice = null;
    // The selected .md.ini values travel with the install: the server writes
    // them before it calls action=install, so the run uses what is on screen.
    const { ok, body, status } = await api('/api/extensions/install', {
        method: 'POST', body: { dry_run: !!dryRun, settings: collectSettings() },
    });
    state.busy = false;
    if (status === 404 && body?.error === 'extensions_disabled') { renderDisabled('Extensions tab is disabled in config.php (ExtensionsTab=false).'); return; }
    if (adminProtected(state.status) && status === 401) { state.status.admin_unlocked = false; void loadAndRender(); return; }
    if (body?.state) state.status = Object.assign({}, state.status, body.state);
    if (body?.ok === true) await refreshIni();
    if (body?.ok !== true) {
        if (dryRun) state.plan = null;
        state.notice = { kind: body?.format_valid === false ? 'warn' : 'error', text: describeFailure(body, dryRun ? 'Plan failed' : 'Install failed') };
    } else if (dryRun) {
        state.plan = body;
        state.notice = { kind: 'ok', text: 'Plan ready: ' + summarisePlanText(body) + ' Review it, then confirm the install.' };
    } else {
        state.result = body;
        state.plan = null;
        state.notice = { kind: (body.summary && body.summary.error > 0) ? 'warn' : 'ok',
            text: 'Install finished: ' + summarisePlanText(body) };
    }
    render();
}

// Live <select> elements of the .md.ini card, keyed by setting name. Kept
// outside the render so the install action can send exactly what is shown
// (the server validates: true/false only, nothing else).
let settingSelects = {};
// The string settings (BROWSE_DIR) are free-text <input> elements, keyed the
// same way. Both maps live outside the render so the install action can send
// exactly what is shown.
let settingInputs = {};

/** What the controls currently say, as {KEY: 'true'|'false'} plus BROWSE_DIR's string. */
function collectSettings() {
    const out = {};
    for (const [key, node] of Object.entries(settingSelects)) {
        if (node && typeof node.value === 'string' && node.value !== '') {
            out[key] = node.value;
        }
    }
    // A string setting is sent even when it is empty: an empty BROWSE_DIR is
    // a valid choice (the folder md.php itself is in), not "unset".
    for (const [key, node] of Object.entries(settingInputs)) {
        if (node && typeof node.value === 'string') {
            out[key] = node.value;
        }
    }
    return out;
}

/** Re-read /api/extensions/settings after anything that may have rewritten the file. */
async function refreshIni() {
    const { body } = await api('/api/extensions/settings');
    if (body && body.ok === true) state.ini = body;
    return state.ini;
}

// The .md.ini install menu: one <select> per boolean key and one text field
// for each string setting (BROWSE_DIR), pre-filled from the file (defaults
// when it does not exist yet), the file/key/CLI state, and the two write
// actions. The API key is never part of this payload - only 'set / not set'
// and a mask - so nothing here can leak it into the DOM.
function renderSettings(payload) {
    const card = el('section', { class: 'oc-ext-card', id: 'oc-ext-settings' });
    card.append(el('h3', { text: '.md.ini settings (server-side overrides)' }));

    const s = payload.settings || {};
    const keys = Array.isArray(payload.keys) ? payload.keys : [];
    const labels = payload.labels || {};
    const recommended = payload.recommended || {};
    const values = s.values || {};

    card.append(el('p', { class: 'oc-ext-summary', text:
        'Written to ' + (s.path || 'extensions/md-scribe/.md.ini')
        + '. These are hard overrides - the viewer\'s own admin panel cannot undo them.' }));

    // String settings: keyed by name, with the server's default as the
    // fallback when the file (or an older payload) does not carry one.
    const stringKeys = Array.isArray(payload.string_keys) ? payload.string_keys : ['BROWSE_DIR'];
    const stringDefaults = Object.assign({}, s.string_defaults || {}, payload.string_defaults || {});

    const grid = el('div', { class: 'oc-ext-settings-grid' });
    settingSelects = {};
    settingInputs = {};
    for (const key of keys) {
        const row = el('div', { class: 'oc-ext-setting' });
        const label = el('label', { class: 'oc-ext-setting-label', for: 'oc-ext-set-' + key });
        label.append(el('span', { class: 'oc-ext-setting-name', text: labels[key] || key }));
        label.append(el('code', { class: 'oc-ext-setting-key', text: key }));
        if (recommended[key] === true) {
            label.append(el('span', { class: 'oc-ext-badge', text: 'recommended: true' }));
        }
        row.append(label);

        const select = el('select', { class: 'oc-input oc-ext-select', id: 'oc-ext-set-' + key });
        for (const value of ['true', 'false']) {
            select.append(el('option', {
                value, text: value === 'true' ? 'true (enabled)' : 'false (disabled)',
            }));
        }
        select.value = values[key] === true ? 'true' : 'false';
        row.append(select);
        settingSelects[key] = select;
        grid.append(row);
    }
    for (const key of stringKeys) {
        const row = el('div', { class: 'oc-ext-setting' });
        const label = el('label', { class: 'oc-ext-setting-label', for: 'oc-ext-set-' + key });
        label.append(el('span', { class: 'oc-ext-setting-name', text: labels[key] || key }));
        label.append(el('code', { class: 'oc-ext-setting-key', text: key }));
        row.append(label);

        // A path, not a switch: the server rejects anything that would not
        // stay on one line of .md.ini.
        const input = el('input', {
            class: 'oc-input oc-ext-text', type: 'text', id: 'oc-ext-set-' + key,
        });
        input.value = typeof values[key] === 'string' ? values[key] : (stringDefaults[key] || '');
        row.append(input);
        settingInputs[key] = input;
        grid.append(row);
    }
    card.append(grid);

    const facts = el('div', { class: 'oc-ext-kv-list' });
    facts.append(kv('File', s.present ? 'present' : 'not created yet'));
    facts.append(kv('API key', s.key_present ? ('set (' + (s.key_mask || 'mdv_****') + ')') : 'not set'));
    if (Array.isArray(s.missing_keys) && s.missing_keys.length > 0) {
        facts.append(kv('Not in file', s.missing_keys.join(', ') + ' - saved with the defaults', 'oc-ext-bad'));
    }
    if (Array.isArray(s.invalid_keys) && s.invalid_keys.length > 0) {
        facts.append(kv('Unreadable', s.invalid_keys.join(', ') + ' - shown as the default', 'oc-ext-bad'));
    }
    const cli = payload.runtime && payload.runtime.php_cli;
    if (cli) {
        facts.append(kv('PHP CLI', cli.available
            ? ('found (' + (cli.source || 'unknown') + ')')
            : 'not found - the installer cannot run; set MdViewerPhpCli in .config.php or REVOAP_MDV_PHP_CLI',
            cli.available ? 'oc-ext-ok' : 'oc-ext-bad'));
    }
    facts.append(kv('Writable', s.writable
        ? 'yes'
        : 'no - check the permissions of ' + ((state.status && state.status.directory) || 'extensions/md-scribe') + '/',
        s.writable ? 'oc-ext-ok' : 'oc-ext-bad'));
    card.append(facts);

    card.append(el('div', { class: 'oc-ext-actions' },
        el('button', {
            type: 'button', class: 'oc-button', id: 'oc-ext-save-ini', text: 'Save .md.ini',
            onclick: () => { void saveSettings(); },
        }),
        el('button', {
            type: 'button', class: 'oc-button oc-button-danger', id: 'oc-ext-rotate-key', text: 'Rotate key',
            title: 'Generate a new machine API key (the old one keeps working only until clients are updated)',
            onclick: () => { void rotateKey(); },
        })));

    card.append(el('p', { class: 'oc-ext-summary', text:
        'The key is never sent to this page. To copy it into an external client, open '
        + (s.path || 'extensions/md-viewer/.md.ini') + ' on the server.' }));
    return card;
}

async function saveSettings() {
    if (state.busy) return;
    state.busy = true;
    const { ok, body, status } = await api('/api/extensions/settings', {
        method: 'POST', body: { settings: collectSettings() },
    });
    state.busy = false;
    if (adminProtected(state.status) && status === 401) { state.status.admin_unlocked = false; void loadAndRender(); return; }
    if (!ok || body?.ok !== true) {
        state.notice = { kind: 'error', text: body?.message || ('Saving .md.ini failed (HTTP ' + status + ').') };
    } else {
        state.notice = { kind: 'ok', text: body.changed
            ? ('Saved to ' + ((body.settings && body.settings.path) || 'extensions/md-viewer/.md.ini') + '.')
            : 'Nothing to change - the file already matches.' };
    }
    await loadAndRender();
}

async function rotateKey() {
    if (state.busy) return;
    const confirmed = window.confirm('Generate a NEW machine API key for MD.Viewer?\n\n'
        + 'External clients using the current key (RevoAp itself, cron jobs, scripts) stop working '
        + 'until you copy the new key out of extensions/md-viewer/.md.ini. The key is not shown in '
        + 'this page, and the old one cannot be recovered.');
    if (!confirmed) return;
    state.busy = true;
    const { ok, body, status } = await api('/api/extensions/rotate-key', {
        method: 'POST', body: { confirm: true },
    });
    state.busy = false;
    if (adminProtected(state.status) && status === 401) { state.status.admin_unlocked = false; void loadAndRender(); return; }
    if (!ok || body?.ok !== true) {
        state.notice = { kind: 'error', text: body?.message || ('Rotating the key failed (HTTP ' + status + ').') };
    } else {
        state.notice = { kind: 'warn', text: body.message || 'A new API key is in place - copy it out of .md.ini.' };
    }
    await loadAndRender();
}

function describeFailure(body, fallback) {
    if (!body) return fallback + '.';
    if (body.format_valid === false) {
        let text = body.message || 'The installer did not return JSON.';
        if (body.raw_excerpt) text += ' Returned: ' + body.raw_excerpt;
        return text;
    }
    return body.message || body.error || fallback + '.';
}

function summarisePlanText(body) {
    const s = body.summary || {};
    const parts = [];
    if (typeof s.create === 'number') parts.push(s.create + ' create');
    if (typeof s.update === 'number') parts.push(s.update + ' update');
    if (typeof s.skip === 'number') parts.push(s.skip + ' skip');
    if (typeof s.error === 'number') parts.push(s.error + ' error');
    return parts.join(', ') || 'no files';
}

function table(headers, rows) {
    const t = el('table', { class: 'oc-ext-table' });
    const thead = el('thead');
    const hrow = el('tr');
    for (const h of headers) hrow.append(el('th', { text: h }));
    thead.append(hrow);
    t.append(thead);
    const tbody = el('tbody');
    for (const cells of rows) {
        const tr = el('tr');
        for (const c of cells) tr.append(el('td', { text: c == null || c === '' ? '-' : String(c) }));
        tbody.append(tr);
    }
    t.append(tbody);
    return t;
}

function renderConflicts(payload) {
    const card = el('section', { class: 'oc-ext-card', id: 'oc-ext-conflicts' });
    card.append(el('h3', { text: 'Conflict report' }));
    const counts = payload.counts || {};
    card.append(el('p', { class: 'oc-ext-summary',
        text: 'Total ' + (counts.total || 0) + ' · free ' + (counts.ok || 0) + ' · occupied '
            + (counts.exists || 0) + ' · name used elsewhere ' + (counts.same_name_elsewhere || 0) + '.' }));
    appendWarnings(card, payload.warnings);
    const rows = (payload.rows || []).map(r => [
        r.path, r.status_label, r.existing_path, r.managed ? 'yes' : 'no', String(r.size_bytes || 0),
    ]);
    card.append(table(['File', 'Status', 'Existing path', 'Managed', 'Bytes'], rows));
    return card;
}

function renderPlan(payload) {
    const card = el('section', { class: 'oc-ext-card', id: 'oc-ext-plan' });
    card.append(el('h3', { text: 'Install plan (dry run)' }));
    card.append(el('p', { class: 'oc-ext-summary', text: summarisePlanText(payload) + '.' }));
    appendWarnings(card, payload.warnings);
    const rows = (payload.rows || []).map(r => [
        r.path, r.action_label, r.status_label, r.from_version, r.version, r.error,
    ]);
    card.append(table(['File', 'Action', 'Status', 'From', 'To', 'Error'], rows));
    card.append(el('div', { class: 'oc-ext-actions' },
        el('button', {
            type: 'button', class: 'oc-button oc-button-danger', id: 'oc-ext-confirm', text: 'Confirm install now',
            onclick: () => { void runInstall(false); },
        })));
    return card;
}

function renderResult(payload) {
    const card = el('section', { class: 'oc-ext-card', id: 'oc-ext-result' });
    card.append(el('h3', { text: 'Install result' }));
    card.append(el('p', { class: 'oc-ext-summary', text: summarisePlanText(payload) + '.' }));
    appendWarnings(card, payload.warnings);
    const rows = (payload.rows || []).map(r => [
        r.path, r.action_label, r.status_label, r.from_version, r.version, r.error,
    ]);
    card.append(table(['File', 'Action', 'Status', 'From', 'To', 'Error'], rows));
    const errors = Array.isArray(payload.errors) ? payload.errors : [];
    if (errors.length > 0) {
        const ul = el('ul', { class: 'oc-ext-errors' });
        for (const e of errors) ul.append(el('li', { text: (e.path || '?') + ': ' + (e.error || 'error') }));
        card.append(ul);
    }
    return card;
}

function appendWarnings(card, warnings) {
    if (!Array.isArray(warnings) || warnings.length === 0) return;
    const box = el('div', { class: 'oc-alert oc-alert-warn oc-ext-warnings' });
    const ul = el('ul');
    for (const w of warnings) ul.append(el('li', { text: String(w) }));
    box.append(ul);
    card.append(box);
}

void probeStatus();
