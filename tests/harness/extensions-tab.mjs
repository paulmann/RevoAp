// tests/harness/extensions-tab.mjs - headless checks for the Extensions tab's
// new .md.ini install menu.
//
// Run through `php tests/ExtensionsTabDomTest.php` (which supplies node), or
// directly:
//
//   node --experimental-vm-modules tests/harness/extensions-tab.mjs
//
// Two layers, like the Models-tab harness:
//
//   1. STATIC  - layout.html ships the tab button/panel and loads the module;
//      extensions.js declares the six-select menu and never builds markup.
//   2. BEHAVIOURAL - layout.html is parsed into the stub DOM
//      (tests/harness/dom-stub.mjs) and the REAL assets/js/extensions.js is
//      loaded as an ES module through vm.SourceTextModule, then driven with
//      canned API answers: the menu renders one select per key pre-filled from
//      the file, the API key is visible only as `mdv_....`, the selected
//      values travel with the install, saving posts the selection, and
//      rotating the key needs the confirm dialog.
//
// The canned payload contains a full-length key so that a leak is detectable:
// every rendered text is scanned for it. `innerHTML` is absent from the stub,
// so a module that reached for it would throw instead of quietly passing.

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import * as stub from './dom-stub.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');

let failures = 0;
let passes = 0;

function check(label, cond, detail = '') {
    if (cond) {
        console.log(`  ok  ${label}`);
        passes++;
    } else {
        console.log(`  FAIL ${label}${detail ? ` - ${detail}` : ''}`);
        failures++;
    }
}

function read(rel) {
    const path = resolve(root, rel);
    return existsSync(path) ? readFileSync(path, 'utf8') : '';
}

const layout = read('assets/templates/layout.html');
const extJs = read('assets/js/extensions.js');
const extCss = read('assets/css/components/extensions.css');

const KEYS = [
    'DISABLE_UPLOAD', 'DISABLE_CLIPBOARD', 'DISABLE_SAVE_CLIPBOARD_TO_FILE',
    'ALLOW_UPDATE', 'ALLOW_RESTORE', 'ALLOW_CREATE_INDEX_PHP_LINK',
];
/** The string setting shipped next to the six booleans (a text field, not a select). */
const STRING_KEYS = ['BROWSE_DIR'];
const BROWSE_DIR_DEFAULT = '/share/';
/** A real-looking machine key: if it ever reaches the DOM, a check catches it. */
const KEY = 'mdv_' + 'a1b2c3d4'.repeat(6);
const MASK = 'mdv_••••';

// =====================================================================
console.log('== the tab is wired in the shipped layout ==');
// =====================================================================
check('layout.html ships the Extensions tab button', layout.includes('id="oc-tab-extensions"'));
check('the button starts hidden (config decides at runtime)',
    /id="oc-tab-extensions"[^>]*hidden/.test(layout));
check('layout.html ships the Extensions panel',
    layout.includes('data-tab-panel="extensions"') && layout.includes('id="oc-extensions-root"'));
check('layout.html loads extensions.js and extensions.css',
    layout.includes('/assets/js/extensions.js') && layout.includes('/assets/css/components/extensions.css'));
check('the stylesheet has rules for the settings menu',
    extCss.includes('.oc-ext-settings-grid') && extCss.includes('.oc-ext-select'));

console.log('\n== the module renders text, never markup ==');
check('extensions.js never assigns innerHTML', !/\binnerHTML\s*=/.test(extJs));
check('the menu is built from the keys the server sends',
    extJs.includes("'oc-ext-set-' + key") && extJs.includes('payload.keys'));
check('the key is shown as a mask only',
    extJs.includes('s.key_mask') && extJs.includes("'API key'"));
check('the module never asks the server for the key value',
    !/\bapi_key\b/.test(extJs) && !/key_value/.test(extJs));

// =====================================================================
console.log('\n== driving the shipped module in the stub DOM ==');
// =====================================================================

const fileValues = {
    DISABLE_UPLOAD: true,
    DISABLE_CLIPBOARD: false,
    DISABLE_SAVE_CLIPBOARD_TO_FILE: true,
    ALLOW_UPDATE: true,
    ALLOW_RESTORE: false,
    ALLOW_CREATE_INDEX_PHP_LINK: false,
    BROWSE_DIR: BROWSE_DIR_DEFAULT,
};
const labels = {};
for (const key of KEYS) { labels[key] = `Label of ${key}`; }
for (const key of STRING_KEYS) { labels[key] = `Label of ${key}`; }

const iniState = () => ({
    path: 'extensions/md-viewer/.md.ini',
    present: true,
    values: { ...fileValues, ...postedValues },
    defaults: { ...fileValues },
    string_defaults: { BROWSE_DIR: BROWSE_DIR_DEFAULT },
    keys: KEYS,
    string_keys: STRING_KEYS,
    missing_keys: [],
    invalid_keys: [],
    unknown_keys: [],
    key_present: true,
    key_mask: MASK,
    key_generated: true,
    writable: true,
});
let postedValues = {};

const fetchLog = [];

function json(data, status = 200) {
    return { status, ok: status < 400, json: async () => data, text: async () => JSON.stringify(data) };
}

async function stubFetch(url, init = {}) {
    const route = String(url).split('?')[0];
    const method = (init.method || 'GET').toUpperCase();
    let body = null;
    if (typeof init.body === 'string' && init.body !== '') {
        try { body = JSON.parse(init.body); } catch { body = null; }
    }
    fetchLog.push({ route, method, body });

    if (route === '/api/extensions/status') {
        // admin_protection: true + admin_unlocked: true is the realistic state
        // for this tab: the operator has a token and unlocked with it. It also
        // keeps the "not protected" banner out of the way - that banner is
        // rendered with Element.prepend(), which the stub DOM does not
        // implement, so this harness deliberately does not exercise it.
        return json({
            tab_enabled: true, admin_protection: true, admin_configured: true, admin_unlocked: true,
            database: {}, repository: { name: 'MD.Viewer', path: 'paulmann/MD.Viewer', branch: 'main' },
            transport: { available: true, kind: 'curl', reason: '' },
            installer: { present: true, path: 'extensions/md-viewer/updater.php', version: '3.9.0', api_supported: true },
            instance: { installed: true, version: '3.9.0', path: 'extensions/md-viewer/md.php' },
            directory: 'extensions/md-viewer', ini_present: true,
            network: { checked: true, reachable: true, transport: 'curl', http_status: 200, bytes: 88377, ms: 42 },
        });
    }
    if (route === '/api/extensions/settings') {
        if (method === 'POST') {
            postedValues = { ...postedValues, ...(body && body.settings ? body.settings : {}) };
            Object.keys(postedValues).forEach((k) => {
                postedValues[k] = postedValues[k] === 'true' ? true : postedValues[k] === 'false' ? false : postedValues[k];
            });
            return json({
                ok: true, action: 'settings_saved', created: false, changed: true, rotated: false,
                message: 'Written to extensions/md-viewer/.md.ini.',
                settings: iniState(),
                runtime: { php_cli: { available: true, source: 'php_bindir', binary: 'php', candidates: 5, error: null } },
            });
        }
        return json({
            ok: true,
            keys: KEYS,
            labels,
            defaults: { ...fileValues },
            string_keys: STRING_KEYS,
            string_defaults: { BROWSE_DIR: BROWSE_DIR_DEFAULT },
            recommended: { DISABLE_UPLOAD: true },
            settings: iniState(),
            runtime: { php_cli: { available: true, source: 'php_bindir', binary: 'php', candidates: 5, error: null } },
        });
    }
    if (route === '/api/extensions/rotate-key') {
        return json({
            ok: true, action: 'key_rotated', rotated: true, changed: true,
            message: 'A new API key is in place. It is stored in extensions/md-viewer/.md.ini '
                + 'and is never sent to the browser - copy it into your external clients.',
            settings: iniState(),
            runtime: { php_cli: { available: true, source: 'php_bindir', binary: 'php', candidates: 5, error: null } },
        });
    }
    if (route === '/api/extensions/install') {
        return json({
            ok: true, action: 'install', dry_run: true, force: false,
            version: '3.9.0',
            rows: [{ path: 'md.php', action: 'create', action_label: 'Create', status: 'would_write', status_label: 'Would write', from_version: '', version: '', existing_path: '', error: '' }],
            summary: { create: 1, update: 0, skip: 0, error: 0, total: 1, written: 0, skipped: 0, errors: 0 },
            steps: [], warnings: [], errors: [], counts: {},
            ms: 12, ini: iniState(), state: {},
        });
    }
    return json({ ok: true });
}

function textOf(node) {
    if (!node) { return ''; }
    let out = typeof node.textContent === 'string' ? node.textContent : '';
    for (const child of node.childNodes || []) {
        out += textOf(child);
    }
    return out;
}

const parsed = stub.parseHtml(layout);
stub.installGlobals(parsed, { fetch: stubFetch });
globalThis.window.confirm = () => true;

let confirmCalls = 0;
const confirmAnswers = [];
globalThis.window.confirm = () => {
    confirmCalls++;
    return confirmAnswers.length > 0 ? confirmAnswers.shift() : true;
};

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

const flush = async () => {
    for (let i = 0; i < 12; i++) { await new Promise((r) => setImmediate(r)); }
};

let bootError = null;
try {
    const mod = await load(resolve(root, 'assets/js/extensions.js'));
    await mod.evaluate();
    await flush();
} catch (e) {
    bootError = e;
}
check('the shipped module loads and boots in the stub DOM', bootError === null, bootError ? String(bootError && bootError.message) : '');
if (bootError === null) {
    const panel = parsed.getElementById('oc-extensions-root');
    check('the panel exists in the parsed layout', panel !== null);

    const tabButton = parsed.querySelector('[data-tab="extensions"]');
    check('the tab button was found', tabButton !== null);
    tabButton.click();
    await flush();

    check('the tab is revealed once the probe says it is enabled',
        parsed.getElementById('oc-tab-extensions').hidden === false);

    // ---- the menu ---------------------------------------------------
    const selects = {};
    for (const key of KEYS) { selects[key] = parsed.getElementById('oc-ext-set-' + key); }
    check('one select per setting is rendered',
        KEYS.every((k) => selects[k] !== null),
        KEYS.filter((k) => selects[k] === null).join(','));
    check('each select offers true and false',
        KEYS.every((k) => {
            const opts = selects[k] ? selects[k].querySelectorAll('option') : [];
            return opts.length === 2;
        }));
    check('the values come from the file',
        selects.ALLOW_UPDATE && selects.ALLOW_UPDATE.value === 'true'
        && selects.DISABLE_CLIPBOARD && selects.DISABLE_CLIPBOARD.value === 'false'
        && selects.DISABLE_UPLOAD && selects.DISABLE_UPLOAD.value === 'true',
        KEYS.map((k) => `${k}=${selects[k] ? selects[k].value : '?'}`).join(' '));
    check('the key names are visible next to their labels',
        KEYS.every((k) => textOf(panel).includes(k)));
    check('DISABLE_UPLOAD carries the recommended badge',
        textOf(panel).includes('recommended: true'));

    // ---- the seventh key: a text field next to the six selects ----------
    const dirField = parsed.getElementById('oc-ext-set-BROWSE_DIR');
    check('the string setting renders a text field', dirField !== null && dirField.tagName === 'INPUT');
    check('the text field is pre-filled with the server value',
        dirField !== null && dirField.value === BROWSE_DIR_DEFAULT, dirField ? String(dirField.value) : 'missing');
    check('the string setting carries its label and its key name',
        textOf(panel).includes('Label of BROWSE_DIR') && textOf(panel).includes('BROWSE_DIR'));

    // ---- the secret -------------------------------------------------
    const panelText = textOf(panel);
    check('the panel shows the key only as a mask', panelText.includes(MASK));
    check('the panel NEVER contains the key itself', !panelText.includes(KEY));
    check('the key is not in any attribute of the panel',
        !(panel.descendants ? panel.descendants() : []).some((n) => JSON.stringify(n.attrs || {}).includes(KEY)));

    // ---- the state rows --------------------------------------------
    check('the file state is shown', panelText.includes('present'));
    check('the CLI interpreter is reported as found',
        panelText.includes('PHP CLI') && panelText.toLowerCase().includes('found'));
    check('the file reports itself as writable', panelText.includes('Writable') && panelText.includes('yes'));

    // ---- saving ------------------------------------------------------
    fetchLog.length = 0;
    selects.ALLOW_RESTORE.value = 'true';
    parsed.getElementById('oc-ext-save-ini').click();
    await flush();
    const saves = fetchLog.filter((e) => e.route === '/api/extensions/settings' && e.method === 'POST');
    check('saving posts to /api/extensions/settings', saves.length === 1);
    check('the save carries the six booleans and the string setting',
        saves.length === 1
        && Object.keys(saves[0].body.settings).length === 7
        && saves[0].body.settings.ALLOW_RESTORE === 'true'
        && saves[0].body.settings.ALLOW_UPDATE === 'true'
        && saves[0].body.settings.DISABLE_CLIPBOARD === 'false'
        && saves[0].body.settings.BROWSE_DIR === BROWSE_DIR_DEFAULT,
        JSON.stringify(saves[0] ? saves[0].body : null));
    check('the save carries nothing else that could leak a key',
        saves.length === 1 && !JSON.stringify(saves[0].body).includes(KEY));
    check('the client sends the booleans as the strings "true"/"false", not 1/0',
        saves.length === 1
        && KEYS.every((k) => saves[0].body.settings[k] === 'true' || saves[0].body.settings[k] === 'false')
        && typeof saves[0].body.settings.BROWSE_DIR === 'string');
    check('the values are re-read from the server after the save',
        parsed.getElementById('oc-ext-set-ALLOW_RESTORE').value === 'true',
        parsed.getElementById('oc-ext-set-ALLOW_RESTORE').value);

    // ---- installing --------------------------------------------------
    fetchLog.length = 0;
    parsed.getElementById('oc-ext-set-ALLOW_RESTORE').value = 'false';
    parsed.getElementById('oc-ext-set-DISABLE_UPLOAD').value = 'false';
    parsed.getElementById('oc-ext-install').click();
    await flush();
    const installs = fetchLog.filter((e) => e.route === '/api/extensions/install');
    check('the install POSTs to /api/extensions/install', installs.length === 1);
    check('the install plans by default (dry_run true)',
        installs.length === 1 && installs[0].body.dry_run === true);
    check('the install carries the SELECTED values, as shown',
        installs.length === 1 && installs[0].body.settings.ALLOW_RESTORE === 'false'
        && installs[0].body.settings.DISABLE_UPLOAD === 'false'
        && installs[0].body.settings.ALLOW_UPDATE === 'true'
        && installs[0].body.settings.BROWSE_DIR === BROWSE_DIR_DEFAULT,
        JSON.stringify(installs[0] ? installs[0].body : null));
    check('the plan is rendered back to the operator',
        parsed.getElementById('oc-ext-plan') !== null && textOf(parsed.getElementById('oc-ext-plan')).includes('Install plan'));
    check('the plan never contains key material',
        !textOf(parsed.getElementById('oc-ext-plan')).includes(KEY));

    // ---- rotating ----------------------------------------------------
    fetchLog.length = 0;
    confirmAnswers.push(false);
    parsed.getElementById('oc-ext-rotate-key').click();
    await flush();
    check('a declined confirm dialog sends nothing',
        fetchLog.filter((e) => e.route === '/api/extensions/rotate-key').length === 0 && confirmCalls === 1);
    confirmAnswers.push(true);
    parsed.getElementById('oc-ext-rotate-key').click();
    await flush();
    const rotates = fetchLog.filter((e) => e.route === '/api/extensions/rotate-key');
    check('a confirmed rotation posts confirm:true',
        rotates.length === 1 && rotates[0].method === 'POST' && rotates[0].body.confirm === true,
        JSON.stringify(rotates[0] ? rotates[0].body : null));
    check('the rotation notice points at the file, not at a key',
        textOf(panel).includes('.md.ini') && !textOf(panel).includes(KEY));
    check('rotating leaves a notice for the operator',
        textOf(panel).includes('key'));
}

// =====================================================================
console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
