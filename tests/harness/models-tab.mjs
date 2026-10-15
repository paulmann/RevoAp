// tests/harness/models-tab.mjs — headless checks for the Models tab.
//
// Run through `php tests/ModelsTabDomTest.php` (which supplies node), or
// directly:
//
//   node --experimental-vm-modules tests/harness/models-tab.mjs
//
// Two layers, deliberately:
//
//   1. STATIC — the shipped templates and modules are read as text and
//      asserted on: tab order in the header, the ids the JS binds to, and
//      the fact that the model sections really moved out of settings.js.
//      Cheap, and the only way to check "the tab is there before any JS
//      runs", which is what a user without a token sees.
//   2. BEHAVIOURAL — layout.html is parsed into the stub DOM, the REAL
//      modules (assets/js/models.js and its imports) are loaded as ES
//      modules through vm.SourceTextModule, and the tab is driven with
//      canned API answers: hidden-by-config, lock screen, section render,
//      preset → consensus pool, and the three actualize stages.
//
// A failure prints a `FAIL` line; the process exits non-zero. `innerHTML`
// is checked both statically (source scan) and dynamically: the stub DOM
// throws if any module reaches for it.

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
const modelsJs = read('assets/js/models.js');
const settingsJs = read('assets/js/settings.js');
const adminUiJs = read('assets/js/admin-ui.js');
const selectorJs = read('assets/js/model-selector.js');
const configPhp = read('config.php');
const configClass = read('src/Core/Config.php');

// =====================================================================
console.log('== the tabs are wired in the shipped layout ==');
// =====================================================================
const orderOf = (needle) => layout.indexOf(needle);
const chatAt = orderOf('data-tab="chat"');
const modelsAt = orderOf('data-tab="models"');
const settingsAt = orderOf('data-tab="settings"');
check('layout.html ships the Models tab button', layout.includes('id="oc-tab-models"'));
check('Models sits immediately after Chat in the header nav',
    chatAt !== -1 && modelsAt !== -1 && settingsAt !== -1 && chatAt < modelsAt && modelsAt < settingsAt,
    `chat@${chatAt} models@${modelsAt} settings@${settingsAt}`);
check('the Models button starts hidden (config decides at runtime)',
    /id="oc-tab-models"[^>]*hidden/.test(layout));
check('the Models panel exists and starts hidden',
    /data-tab-panel="models"[^>]*hidden/.test(layout) && layout.includes('id="oc-models-root"'));
check('the Models panel carries its own sticky section nav',
    layout.includes('id="oc-models-nav"') && layout.includes('oc-settings-nav'));
check('layout.html loads models.js as a module', layout.includes('/assets/js/models.js'));
check('layout.html loads models.css', layout.includes('/assets/css/components/models.css'));
check('models.js and models.css exist on disk',
    read('assets/js/models.js') !== '' && read('assets/css/components/models.css') !== '');

console.log('\n== the model sections moved, and only moved ==');
for (const fn of ['sectionProviders', 'sectionModels', 'sectionDomainModels', 'sectionAnalyzer']) {
    check(`models.js declares ${fn}()`, modelsJs.includes(`function ${fn}(`));
}
for (const fn of ['sectionProviders', 'sectionModels', 'sectionDomainModels', 'sectionAnalyzer']) {
    check(`settings.js no longer declares ${fn}()`, !settingsJs.includes(`function ${fn}(`));
}
check('settings.js calls the Models tab for the moved sections',
    !settingsJs.includes('sectionDomainModels(),') && !settingsJs.includes('sectionProviders(),'));
for (const fn of ['sectionInterface', 'sectionProfile', 'sectionDatabase', 'sectionMigrations', 'sectionWorker', 'sectionCache', 'sectionRuntime', 'sectionLogout']) {
    check(`settings.js keeps ${fn}()`, settingsJs.includes(`function ${fn}(`));
}
check('the moved sections keep their original element ids (anchors survive)',
    modelsJs.includes("'oc-domain-models'") && modelsJs.includes("'oc-providers-card'")
    && modelsJs.includes("'oc-models-card'") && modelsJs.includes("'oc-analyzer-card'"));
check('settings.js no longer advertises the moved sections in its strip',
    !settingsJs.includes("title: 'Domain models'") && !settingsJs.includes("title: 'Providers'")
    && !settingsJs.includes("title: 'Models'") && !settingsJs.includes("title: 'Analyzer'"));
check('models.js registers its own section strip',
    modelsJs.includes('createSectionNav') && modelsJs.includes('MODELS_SECTIONS'));

console.log('\n== unlock is shared, not duplicated ==');
check('the shared module owns the lock screen', adminUiJs.includes('export function createLockScreen'));
check('the shared module owns the section strip factory', adminUiJs.includes('export function createSectionNav'));
check('settings.js imports the shared shell',
    settingsJs.includes("from './admin-ui.js'") && !settingsJs.includes('node.innerHTML'));
check('models.js imports the shared shell', modelsJs.includes("from './admin-ui.js'"));
check('models.js unlocks through the shared cookie endpoint',
    modelsJs.includes('/api/models/status') && adminUiJs.includes("'/api/settings/unlock'"));
check('no client-side admin token is stored',
    !modelsJs.includes('localStorage.setItem') && !adminUiJs.includes('admin_token'));
check('the ModelsTab flag exists in config.php', /'ModelsTab'\s*=>\s*true/.test(configPhp));
check('Config exposes isModelsTabEnabled()', configClass.includes('function isModelsTabEnabled'));
check('the tab flag is a strict-boolean tab flag',
    /isModelsTabEnabled[\s\S]{0,120}tabFlag\('ModelsTab'/.test(configClass));

console.log('\n== Phase B: preset → left panel is wired ==');
check('ModelSelector grew an applyPreset() method', selectorJs.includes('applyPreset(rows)'));
check('the selector is published for other tabs', selectorJs.includes('export function setActiveModelSelector'));
check('the helper reports the outcome instead of guessing',
    selectorJs.includes('reason:') && selectorJs.includes("skipped"));
check('app.js registers the live selector', read('assets/js/app.js').includes('setActiveModelSelector(modelSelector)'));
check('models.js applies the preset the API returned',
    modelsJs.includes('res.body?.preset') && modelsJs.includes('applyPresetToSelection'));
check('an empty / unavailable preset is reported, not silently ignored',
    modelsJs.includes('defines no active consensus models')
    && modelsJs.includes('none of its models is available'));
check('app.js no longer points at the Settings tab for model config',
    read('assets/js/app.js').includes('[data-tab="models"]'));

console.log('\n== Phase C: the actualize UI is wired ==');
check('the models_actualize kind is documented in the module',
    modelsJs.includes('/api/models/actualize') && modelsJs.includes('/api/models/actualize/status'));
check('the three stages are declared in order',
    /analysing_models[\s\S]*assigning_presets[\s\S]*finish/.test(modelsJs));
check('a rollback control exists', modelsJs.includes('/api/models/actualize/rollback'));
check('the button is disabled while a run is active', modelsJs.includes('button.disabled = active'));
check('a missing worker is reported explicitly',
    modelsJs.includes('worker_available === false') && modelsJs.includes('bin/revoap-worker.php'));

console.log('\n== no innerHTML anywhere in the new/edited modules ==');
for (const [name, src] of [
    ['admin-ui.js', adminUiJs],
    ['models.js', modelsJs],
    ['model-selector.js', selectorJs],
]) {
    // Comments that mention the word are fine; an assignment or call is not.
    const used = /\.innerHTML\s*=|\.innerHTML\s*\?|\.insertAdjacentHTML\s*\(/.test(src);
    check(`${name} never uses innerHTML`, !used);
}
check('admin-ui.js offers no `html:` escape hatch in el()', !adminUiJs.includes("k === 'html'"));

console.log('\n== T1: the pool rows are a real combobox, not a native select ==');
check('model-selector.js builds no <select> any more', !selectorJs.includes("el('select'"));
check('the trigger carries the combobox role', selectorJs.includes("role: 'combobox'"));
check('the popup is a listbox of options',
    selectorJs.includes("role: 'listbox'") && selectorJs.includes("role: 'option'"));
check('the search field lives INSIDE the row popup',
    selectorJs.includes("class: 'oc-model-search'") && selectorJs.includes("class: 'oc-model-panel'"));
check('the popup is only closed from outside the widget',
    selectorJs.includes('closeComboboxesOutside') && selectorJs.includes('this.root.contains(target)'));
check('the row popup is focus-managed, not layout-managed',
    selectorJs.includes("addEventListener('focusout'") && !selectorJs.includes('bindExpandable'));
check('the empty result is spelled out with a reset hint',
    selectorJs.includes('No model matches') && selectorJs.includes('Clear the search field'));
// A page with one combobox per pool row produced one browser issue per row
// ('a form field element should have an id or name attribute') - 20 of them on
// a live install. The field's id comes from the combobox counter, so it cannot
// collide with the listbox id it already points at.
check('every combobox search field carries its own id',
    selectorJs.includes('id: this.searchId,')
    && selectorJs.includes('this.searchId = `oc-model-search-${comboboxSeq}`'));
check('the id is derived from the same counter as the listbox',
    selectorJs.indexOf('this.listId = `oc-model-options-${++comboboxSeq}`')
    < selectorJs.indexOf('this.searchId = `oc-model-search-${comboboxSeq}`'));

check('the combobox search reuses the shared filter matcher',
    selectorJs.includes('modelMatchesFilter(row, filter)'));

// =====================================================================
console.log('\n== behavioural: the tab renders, locks and reacts ==');
// =====================================================================
const stub = await import('./dom-stub.mjs');

/** The canned API, switchable per scenario. */
let apiState = {};
let fetchLog = [];

function jsonResponse(body, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    };
}

async function stubFetch(path, init = {}) {
    fetchLog.push({ path: String(path), method: init.method || 'GET', body: init.body });
    const route = String(path);
    const method = init.method || 'GET';
    // v1.8.50 — the save endpoints. Each can be canned per scenario so the
    // failure shapes (a write that did not land, a stale read-back) are
    // exercised rather than assumed.
    if (route === '/api/settings/models' && method === 'POST') {
        return cannedOr(apiState.saveModels, () => jsonResponse({
            ok: true,
            models: { list: apiState.modelsAfterSave ?? [], default_selected: [] },
            saved: { rows: (apiState.modelsAfterSave ?? []).length, kept: 0, new: 0 },
            persisted: { ok: true, state: 'ok', data: { models: apiState.modelsAfterSave ?? [] } },
        }));
    }
    if (route === '/api/settings/models/import' && method === 'POST') {
        return cannedOr(apiState.importModels, () => jsonResponse({
            ok: true, provider: 'alpha', count: 3, catalog: [], popular: [],
            imported: { added: 2, duplicates: 1, present: 1, truncated: 0, limit: 64, rows: 3, ids: [] },
            models: { list: [], default_selected: [] },
            persisted: { ok: true, state: 'ok', data: { models: [] } },
        }));
    }
    if (route === '/api/analyzer/settings' && method === 'POST') {
        return cannedOr(apiState.saveAnalyzer, () => jsonResponse({
            ok: true, updated: ['analyzer.model'],
            state: { enabled: false, provider: 'alpha', model: 'analyzer-model' },
            persisted: { ok: true, state: 'ok', data: { analyzer: { provider: 'alpha', model: 'analyzer-model' } } },
        }));
    }
    if (route === '/api/models/status') { return jsonResponse(apiState.status ?? { tab_enabled: true, admin_configured: true, admin_unlocked: true }); }
    if (route === '/api/settings') { return jsonResponse(apiState.settings ?? {}); }
    if (route === '/api/analyzer/settings') { return jsonResponse(apiState.analyzer ?? { enabled: false, provider: 'alpha', model: 'analyzer-model' }); }
    if (route === '/api/domain-presets') { return jsonResponse(apiState.presets ?? { presets: [], providers: [], catalog: {}, persisted: true }); }
    if (route === '/api/models/actualize/status') { return jsonResponse(apiState.actualize ?? { stage: 'idle', warnings: [], assigned: {} }); }
    if (route === '/api/models/actualize/settings') {
        if (method === 'POST') {
            return cannedOr(apiState.saveActualizeSettings, () => jsonResponse({
                ok: true, reset: false, message: 'The section now uses its own model.',
                section: { provider: 'alpha', model: 'openai/gpt-5.2' },
                inherited: { provider: 'alpha', model: 'openai/gpt-5.5' },
                effective: { provider: 'alpha', model: 'openai/gpt-5.2' },
                source: 'section', note: null,
                source_key: 'models.actualize.analyzer',
                inherited_key: 'analyzer.provider / analyzer.model',
                store_available: true,
                import_sources: ['bothub', 'openrouter'], default_source: 'bothub',
            }));
        }
        return jsonResponse(apiState.actualizeSettings ?? {
            ok: true, section: null,
            inherited: { provider: 'alpha', model: 'openai/gpt-5.5' },
            effective: { provider: 'alpha', model: 'openai/gpt-5.5' },
            source: 'inherited', note: null,
            source_key: 'models.actualize.analyzer',
            inherited_key: 'analyzer.provider / analyzer.model',
            store_available: true,
            import_sources: ['bothub', 'openrouter'], default_source: 'bothub',
        });
    }
    if (route === '/api/models/actualize' && method === 'POST') {
        return cannedOr(apiState.actualizePost, () => jsonResponse({ ok: true }));
    }
    if (route.startsWith('/api/domain-presets/') && route.endsWith('/select')) { return jsonResponse({ ok: true, preset: apiState.selectPreset ?? null }); }
    if (route === '/api/settings/unlock') { return jsonResponse({ ok: true }); }
    return jsonResponse({ ok: true });
}
/** A canned response for a route, or the default the fallback builds. */
function cannedOr(canned, fallback) {
    if (!canned) { return fallback(); }
    return jsonResponse(canned.body, canned.status ?? 200);
}

/**
 * Load the shipped model-selector.js as an ES module and return its exports.
 *
 * The module touches no DOM at load time (every `document`/`localStorage`
 * use is inside a function), so evaluating it through vm needs no DOM
 * globals — which is what makes the pure helpers (version parsing, filtering)
 * testable directly against the shipped source rather than a copy.
 */
let selectorHelpersCache = null;
async function loadSelectorHelpers() {
    if (selectorHelpersCache) { return selectorHelpersCache; }
    const path = resolve(root, 'assets/js/model-selector.js');
    const source = readFileSync(path, 'utf8');
    const mod = new vm.SourceTextModule(source, {
        identifier: path,
        context: vm.createContext(globalThis),
    });
    await mod.link(async () => { throw new Error('model-selector.js gained a dependency'); });
    await mod.evaluate();
    selectorHelpersCache = mod.namespace;
    return selectorHelpersCache;
}

/** Build the DOM from the REAL layout.html and load the real modules. */
async function boot({ tabEnabled = true, configured = true, unlocked = true } = {}) {    apiState = { status: { tab_enabled: tabEnabled, admin_configured: configured, admin_unlocked: unlocked } };
    fetchLog = [];

    const parsed = stub.parseHtml(layout);
    const documentRoot = parsed;
    stub.installGlobals(documentRoot, { fetch: stubFetch });

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

    const modelsModule = await load(resolve(root, 'assets/js/models.js'));
    const selectorModule = await load(resolve(root, 'assets/js/model-selector.js'));
    await modelsModule.evaluate();

    // The Chat sidebar's pool: the real selector, registered the way
    // app.js registers it.
    const selectorRoot = documentRoot.getElementById('model-selector');
    let selector = null;
    if (selectorRoot) {
        selector = new selectorModule.namespace.ModelSelector(selectorRoot);
        selector.setCatalog(
            apiState.catalog ?? [
                { provider: 'alpha', model: 'openai/gpt-5.5' },
                { provider: 'alpha', model: 'anthropic/claude-sonnet-4.6' },
                { provider: 'alpha', model: 'google/gemini-3.1-pro-preview' },
                { provider: 'alpha', model: 'deepseek/deepseek-v4-pro' },
            ],
            [],
        );
        selectorModule.namespace.setActiveModelSelector(selector);
    }

    // Let probeStatus()'s fetch settle.
    for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); }

    return {
        documentRoot,
        selector,
        clickTab: async (name) => {
            const btn = documentRoot.querySelector(`[data-tab="${name}"]`);
            if (btn) { btn.click(); }
            for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); }
        },
        flush: async () => { for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); } },
        /** Press "Actualize linked models" and let the POST settle. */
        clickActualize: async () => {
            const btn = documentRoot.getElementById('oc-actualize-btn');
            if (btn) { btn.click(); }
            for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); }
        },
        /**
         * Pick a model in the Actualize section's picker the way an operator
         * does: open the trigger, then click the option. Returns the chosen
         * option's text, or null when the model is not on offer.
         */
        pickAnalyzerModel: (model) => {
            const host = documentRoot.getElementById('oc-actualize-analyzer-picker');
            if (!host) { return null; }
            const trigger = host.querySelectorAll('.oc-model-trigger')[0];
            if (!trigger) { return null; }
            trigger.click();
            const option = host.querySelectorAll('.oc-model-option')
                .find((o) => o.textContent.includes(model));
            if (!option) { return null; }
            option.click();
            return option.textContent;
        },
        modelsTab: () => documentRoot.querySelector('[data-tab="models"]'),
        modelsPanel: () => documentRoot.querySelector('[data-tab-panel="models"]'),
        root: () => documentRoot.getElementById('oc-models-root'),
        fetchLog,
        apiState,
    };
}

// ---- tab visibility tracks the flag -------------------------------
{
    const env = await boot({ tabEnabled: true });
    check('the Models tab is revealed when ModelsTab=true', env.modelsTab().hidden === false);
}
{
    const env = await boot({ tabEnabled: false });
    check('the Models tab is hidden when ModelsTab=false', env.modelsTab().hidden === true);
    check('the Settings tab keeps its own visibility',
        env.documentRoot.querySelector('[data-tab="settings"]') !== null);
}

// ---- lock screen --------------------------------------------------
{
    const env = await boot({ tabEnabled: true, configured: true, unlocked: false });
    await env.clickTab('models');
    const rootEl = env.root();
    check('an unlocked-less visitor sees the shared lock screen',
        rootEl.textContent.includes('Admin authentication required'), rootEl.textContent.slice(0, 120));
    check('the lock screen is the shared one (same input id as Settings)',
        env.documentRoot.getElementById('oc-admin-token') !== null);
    check('the lock screen performs no model write',
        !env.fetchLog.some((f) => f.path === '/api/models/actualize'));
}
{
    const env = await boot({ tabEnabled: true, configured: false, unlocked: false });
    await env.clickTab('models');
    check('a host with no admin token is told which flag/credential to set',
        env.root().textContent.includes('REVOAP_ADMIN_TOKEN')
        && env.root().textContent.includes('.admin.key'));
}

// ---- the four moved sections render -------------------------------
{
    const env = await boot();
    await env.clickTab('models');
    const rootEl = env.root();
    const ids = ['oc-providers-card', 'oc-models-card', 'oc-domain-models', 'oc-analyzer-card'];
    for (const id of ids) {
        check(`Models renders #${id}`, env.documentRoot.getElementById(id) !== null);
    }
    check('the actualize card is present', env.documentRoot.getElementById('oc-actualize-card') !== null);
    check('the four stage rows are rendered up front',
        env.documentRoot.querySelectorAll('.oc-actualize-stage').length === 4,
        String(env.documentRoot.querySelectorAll('.oc-actualize-stage').length));
    check('the import is the first row the operator sees',
        env.documentRoot.querySelectorAll('.oc-actualize-stage')[0].getAttribute('data-stage-key')
            === 'importing_models',
        String(env.documentRoot.querySelectorAll('.oc-actualize-stage')[0].getAttribute('data-stage-key')));
    check('the rows are labelled in the order the worker publishes them',
        env.documentRoot.querySelectorAll('.oc-actualize-stage')
            .map((r) => r.getAttribute('data-stage-key')).join(',')
            === 'importing_models,analysing_models,assigning_presets,finish',
        env.documentRoot.querySelectorAll('.oc-actualize-stage')
            .map((r) => r.getAttribute('data-stage-key')).join(','));
    check('the actualize button starts enabled',
        env.documentRoot.getElementById('oc-actualize-btn').disabled === false);
    check('Settings is asked for the provider/model data',
        env.fetchLog.some((f) => f.path === '/api/settings'));
    check('the analyzer card hydrates from its own endpoint',
        env.fetchLog.some((f) => f.path === '/api/analyzer/settings'));
    check('the preset list hydrates from its own endpoint',
        env.fetchLog.some((f) => f.path === '/api/domain-presets'));
    check('the settings tab panel was not populated with model sections',
        env.documentRoot.querySelector('[data-tab-panel="settings"]').textContent.trim() === '',
        env.documentRoot.querySelector('[data-tab-panel="settings"]').textContent.slice(0, 80));
}

// ---- Phase B: choosing a preset updates the left panel -------------
{
    const env = await boot();
    env.apiState.presets = {
        persisted: true,
        providers: [{ provider: 'alpha', active: true }],
        catalog: { alpha: [{ model: 'openai/gpt-5.5', label: 'GPT 5.5', active: true }] },
        presets: [{
            slug: 'mathematics',
            name: 'Mathematics',
            description: 'Reasoning-heavy pool',
            is_system: true,
            skill_slug: 'mathematics-reasoning',
            models: [
                { provider: 'alpha', model: 'openai/gpt-5.5', label: 'GPT 5.5', active: true, selected: true },
                { provider: 'alpha', model: 'deepseek/deepseek-v4-pro', label: 'DeepSeek V4 Pro', active: true, selected: true },
                { provider: 'alpha', model: 'grok-9-ultra', label: 'Nope', active: true, selected: true },
            ],
            arbiter: { provider: 'alpha', model: 'openai/gpt-5.5', role: 'main' },
        }],
    };
    env.apiState.selectPreset = env.apiState.presets.presets[0];
    await env.clickTab('models');

    check('the sidebar starts with a different pool', env.selector.selected().length === 3);
    const useBtn = env.documentRoot.querySelectorAll('#oc-domain-models button')
        .find((b) => b.textContent === 'Use as my consensus pool');
    check('the "use as my consensus pool" action exists', useBtn !== undefined);
    useBtn.click();
    await env.flush();

    const after = env.selector.selected();
    check('selecting a preset replaces the sidebar pool',
        after.length === 2 && after[0].model === 'openai/gpt-5.5' && after[1].model === 'deepseek/deepseek-v4-pro',
        JSON.stringify(after));
    check('an unavailable preset model is skipped, not added',
        !after.some((r) => r.model === 'grok-9-ultra'));
    check('the skip is reported to the operator',
        env.documentRoot.getElementById('oc-domain-pool-status').textContent.includes('not available'),
        env.documentRoot.getElementById('oc-domain-pool-status').textContent);
    check('the pool was persisted the way the panel persists it',
        (globalThis.localStorage.getItem('oc.selected_models.v1') || '').includes('deepseek/deepseek-v4-pro'));
    check('the select endpoint was called once', env.fetchLog.filter((f) => f.path.endsWith('/select')).length === 1);
    check('the preset was read from the response, not re-fetched',
        env.fetchLog.filter((f) => f.path === '/api/domain-presets').length === 1);
}
{
    const env = await boot();
    env.apiState.presets = {
        persisted: true,
        providers: [],
        catalog: {},
        presets: [{
            slug: 'empty', name: 'Empty', description: '', is_system: true, skill_slug: null,
            models: [{ provider: 'alpha', model: 'openai/gpt-5.5', active: false, selected: false }],
            arbiter: null,
        }],
    };
    env.apiState.selectPreset = env.apiState.presets.presets[0];
    await env.clickTab('models');
    const before = env.selector.selected();
    const useBtn = env.documentRoot.querySelectorAll('#oc-domain-models button')
        .find((b) => b.textContent === 'Use as my consensus pool');
    useBtn.click();
    await env.flush();
    check('an empty preset leaves the pool untouched instead of emptying it',
        JSON.stringify(env.selector.selected()) === JSON.stringify(before));
    check('and says why',
        env.documentRoot.getElementById('oc-domain-pool-status').textContent.includes('no active consensus models'),
        env.documentRoot.getElementById('oc-domain-pool-status').textContent);
}

// ---- Phase C: the three stages, the button, and the errors ---------
//
// Every node is re-resolved on each assertion: re-entering the tab
// re-renders the cards (clear + append), so a handle captured once would
// point at a detached node and the assertions would silently pass or fail
// against stale markup.
{
    const env = await boot();
    const byId = (id) => env.documentRoot.getElementById(id);
    const stateOf = (key) => {
        const row = byId('oc-actualize-card')
            .querySelectorAll('.oc-actualize-stage')
            .find((r) => r.getAttribute('data-stage-key') === key);
        if (!row) { return 'missing'; }
        if (row.classList.contains('oc-actualize-stage-done')) { return 'done'; }
        if (row.classList.contains('oc-actualize-stage-failed')) { return 'failed'; }
        if (row.classList.contains('oc-actualize-stage-running')) { return 'running'; }
        return 'pending';
    };
    const refresh = async () => {
        // Re-entering the tab re-runs loadAndRender(), which re-reads the
        // status endpoint — the same path the 3s poller takes.
        await env.clickTab('models');
    };

    await env.clickTab('models');

    // The import runs.
    env.apiState.actualize = { stage: 'importing_models', active: true, warnings: [], assigned: {}, import: { source: 'bothub', fetched: 412, added: ['bothub|openai/gpt-5.6'], duplicates: 410, truncated: 1, unusable: 0, limit: 64 } };
    await refresh();
    check('the import row reads as running', stateOf('importing_models') === 'running', stateOf('importing_models'));
    check('the analysis is still pending while the import runs',
        stateOf('analysing_models') === 'pending', stateOf('analysing_models'));
    check('the import report is rendered while the run is in flight',
        byId('oc-actualize-summary').textContent.includes('412 model(s) received'),
        byId('oc-actualize-summary').textContent);
    check('the import report names the source',
        byId('oc-actualize-summary').textContent.includes('from bothub'),
        byId('oc-actualize-summary').textContent);

    // Stage 2 in flight: the import is done.
    env.apiState.actualize = { stage: 'analysing_models', active: true, warnings: [], assigned: {} };
    await refresh();
    check('the import row is done once the analysis starts',
        stateOf('importing_models') === 'done', stateOf('importing_models'));
    check('stage 1 reads as running', stateOf('analysing_models') === 'running', stateOf('analysing_models'));
    check('stage 2 is still pending', stateOf('assigning_presets') === 'pending', stateOf('assigning_presets'));
    check('the button is locked while a run is in flight', byId('oc-actualize-btn').disabled === true);
    check('the button label reports the run', byId('oc-actualize-action-status').textContent === 'Running…',
        byId('oc-actualize-action-status').textContent);

    // Stage 2 in flight: stage 1 done.
    env.apiState.actualize = { stage: 'assigning_presets', active: true, warnings: [], assigned: {} };
    await refresh();
    check('stage 1 reads as done once stage 2 starts', stateOf('analysing_models') === 'done', stateOf('analysing_models'));
    check('stage 2 reads as running', stateOf('assigning_presets') === 'running', stateOf('assigning_presets'));
    check('the final stage is still pending', stateOf('finish') === 'pending');
    check('the button stays locked', byId('oc-actualize-btn').disabled === true);

    // Success.
    env.apiState.actualize = {
        stage: 'success', active: false, message: 'Actualization complete.',
        warnings: ['Upgraded claude-sonnet-4 → claude-sonnet-4.6.'],
        assigned: { default: ['alpha/openai/gpt-5.5'], mathematics: ['alpha/openai/gpt-5.5'] },
        linked: [
            { role: 'analyzer', label: 'Analyzer', provider: 'alpha', previous_provider: 'alpha', model: 'openai/gpt-5.6', previous_model: 'openai/gpt-5.5' },
            { role: 'synthesis', label: 'Synthesis', provider: 'alpha', previous_provider: 'alpha', model: 'openai/gpt-5.6', previous_model: 'openai/gpt-5.5' },
            { role: 'arbiter', label: 'Arbiter', provider: 'alpha', previous_provider: 'alpha', model: 'anthropic/claude-sonnet-4.6', previous_model: 'anthropic/claude-sonnet-4' },
        ],
        selection: [{ provider: 'alpha', model: 'openai/gpt-5.5' }],
        started_at: '2026-09-21T10:00:00Z', finished_at: '2026-09-21T10:00:42Z', job_id: 77,
        snapshot: { available: true, taken_at: '2026-09-21T09:59:59Z' },
        worker_available: true,
    };
    await refresh();
    check('the first two stages read as done', stateOf('analysing_models') === 'done' && stateOf('assigning_presets') === 'done',
        `${stateOf('analysing_models')}/${stateOf('assigning_presets')}`);
    check('the final stage reports success', stateOf('finish') === 'done', stateOf('finish'));
    check('the button is unlocked again', byId('oc-actualize-btn').disabled === false);
    check('the status label reports done', byId('oc-actualize-action-status').textContent === 'Done.',
        byId('oc-actualize-action-status').textContent);
    check('the summary names the assignment',
        byId('oc-actualize-summary').textContent.includes('mathematics'));
    check('warnings are listed', byId('oc-actualize-summary').querySelectorAll('.oc-actualize-warnings li').length === 1);
    check('the timing and job id are shown',
        byId('oc-actualize-summary').textContent.includes('job #77'));

    // The linked models (analyzer / synthesis / arbiter): they are not part
    // of a preset nor of the pool, so the summary has to name them itself.
    const linkedList = byId('oc-actualize-summary').querySelectorAll('.oc-actualize-linked');
    check('the linked-model block is rendered once', linkedList.length === 1, String(linkedList.length));
    const linkedRows = linkedList.length > 0 ? linkedList[0].querySelectorAll('li') : [];
    check('every linked role is a row', linkedRows.length === 3, String(linkedRows.length));
    check('each row names its role and the old → new value',
        linkedRows.length === 3
        && linkedRows[0].textContent.includes('Analyzer')
        && linkedRows[0].textContent.includes('openai/gpt-5.5 → openai/gpt-5.6')
        && linkedRows[2].textContent.includes('Arbiter')
        && linkedRows[2].textContent.includes('anthropic/claude-sonnet-4 → anthropic/claude-sonnet-4.6'),
        linkedRows.map((r) => r.textContent).join(' | '));
    check('the block is introduced in words, not only by a class',
        byId('oc-actualize-summary').textContent.includes('Linked models updated'));
    check('the rollback button appears once a snapshot exists',
        byId('oc-actualize-rollback-btn').hidden === false);

    // Problems, with the worker naming the stage it died in.
    env.apiState.actualize = {
        stage: 'problems', active: false, job_id: 41,
        message: 'Actualization aborted: the analyzer model failed. Nothing was written.',
        failed_stage: 'assigning_presets',
        warnings: ['The analyzer model could not produce a pool for preset "default"; nothing was written.'],
        assigned: {}, selection: [], snapshot: { available: false }, worker_available: true,
        started_at: '2026-09-21T09:00:00Z', finished_at: '2026-09-21T09:00:20Z',
    };
    await refresh();
    check('a failed run never reads as "every stage finished"',
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length !== 3,
        String(byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length));
    check('stage 1 stays done (it really finished)', stateOf('analysing_models') === 'done',
        stateOf('analysing_models'));
    check('the stage the run died in is marked failed', stateOf('assigning_presets') === 'failed',
        stateOf('assigning_presets'));
    check('exactly one row carries the failure',
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-failed').length === 1,
        String(byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-failed').length));
    check('the stage after the failure never ran, so it stays pending',
        stateOf('finish') === 'pending', stateOf('finish'));
    check('the problem message is rendered',
        byId('oc-actualize-summary').textContent.includes('nothing was written'));
    check('the status label says problems', byId('oc-actualize-action-status').textContent === 'Done with problems.',
        byId('oc-actualize-action-status').textContent);
    check('the rollback button is hidden when there is no snapshot',
        byId('oc-actualize-rollback-btn').hidden === true);
    check('no linked-model block when nothing was updated',
        byId('oc-actualize-summary').querySelectorAll('.oc-actualize-linked').length === 0);
    check('the button is usable again after problems', byId('oc-actualize-btn').disabled === false);
    check('the verdict is bound to the run it belongs to',
        byId('oc-actualize-summary').textContent.includes('job #41'),
        byId('oc-actualize-summary').textContent);
    check('the run identity block exists on its own', byId('oc-actualize-run') !== null);
    check('the run identity carries the start and the finish',
        byId('oc-actualize-run').textContent.includes('started 2026-09-21T09:00:00Z')
        && byId('oc-actualize-run').textContent.includes('finished 2026-09-21T09:00:20Z'),
        byId('oc-actualize-run').textContent);

    // The import report and the analyzer the run actually used: both are
    // facts the card has to carry, because a run on a different model — or
    // against a different catalogue — is a different result.
    env.apiState.actualize = {
        stage: 'success', active: false, job_id: 43,
        message: 'Imported 412 model(s) from openrouter (53 new row(s)); actualized 3 consensus preset(s).',
        warnings: [], assigned: { default: ['alpha/openai/gpt-5.5'] },
        import: {
            source: 'openrouter', fetched: 412,
            added: ['openrouter|openai/gpt-5.6'], duplicates: 358, present: 11,
            truncated: 1, unusable: 2, limit: 64, batch: [],
        },
        analyzer: { provider: 'alpha', model: 'openai/gpt-5.2', source: 'section', requests: 3, corrections: 1 },
        reasons: { default: 'one flagship per vendor family' },
        selection: [], linked: [], snapshot: { available: false }, worker_available: true,
    };
    await refresh();
    const importLine = byId('oc-actualize-import');
    check('the import report is rendered after the run', importLine !== null);
    check('it names the source and the count',
        importLine.textContent.includes('from openrouter') && importLine.textContent.includes('412 model(s) received'),
        importLine.textContent);
    check('it separates new rows from ones already catalogued',
        importLine.textContent.includes('1 new row(s) added')
        && importLine.textContent.includes('358 already catalogued'),
        importLine.textContent);
    check('it discloses what the row cap left out',
        importLine.textContent.includes('1 left out') && importLine.textContent.includes('capped at 64 rows'),
        importLine.textContent);
    check('it discloses entries with no usable id',
        importLine.textContent.includes('2 entr(ies) carried no model id'), importLine.textContent);
    const analyzerLine = byId('oc-actualize-analyzer');
    check('the analyzer that answered is named', analyzerLine !== null);
    check('it names the model and where the value came from',
        analyzerLine.textContent.includes('alpha / openai/gpt-5.2')
        && analyzerLine.textContent.includes('set for this section'),
        analyzerLine.textContent);
    check('it counts the calls and the corrections the contract cost',
        analyzerLine.textContent.includes('3 call(s)') && analyzerLine.textContent.includes('1 correction(s)'),
        analyzerLine.textContent);
    check('the analyzer reasons are listed',
        byId('oc-actualize-summary').querySelectorAll('.oc-actualize-reasons li').length === 1,
        String(byId('oc-actualize-summary').querySelectorAll('.oc-actualize-reasons li').length));
    check('an inherited analyzer is reported as inherited, not as this section\'s choice', (await (async () => {
        env.apiState.actualize.analyzer = {
            provider: 'alpha', model: 'openai/gpt-5.5', source: 'inherited', requests: 1, corrections: 0,
        };
        await refresh();
        const line = byId('oc-actualize-analyzer');
        return line !== null && line.textContent.includes('inherited from analyzer.provider / analyzer.model');
    })()), byId('oc-actualize-analyzer') ? byId('oc-actualize-analyzer').textContent : 'missing');

    // A worker that died without publishing a stage: the failure is pinned
    // to the last row rather than spread over the whole list.
    env.apiState.actualize = {
        stage: 'problems', active: false, job_id: 42,
        message: 'Actualization failed before reporting a result.',
        warnings: [], assigned: {}, selection: [], failed_stage: null,
        snapshot: { available: false }, worker_available: true,
    };
    await refresh();
    check('an unknown failing stage still marks something red',
        stateOf('finish') === 'failed', stateOf('finish'));
    check('and does not mark everything green',
        // Import, analysis and assignment finished; only `finish` is red.
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length === 3,
        String(byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length));

    // Worker down.
    env.apiState.actualize = {
        stage: 'analysing_models', active: true, warnings: [], assigned: {},
        worker_available: false, snapshot: { available: false },
    };
    await refresh();
    check('a stopped worker is reported, not hidden behind a spinner',
        byId('oc-actualize-summary').textContent.includes('worker is not running'));
    check('the remedy is spelled out',
        byId('oc-actualize-summary').textContent.includes('bin/revoap-worker.php'));
    check('the run is still shown as in flight', byId('oc-actualize-btn').disabled === true);
}

// ---- the section's own controls: import source + analyzer model ----
//
// Two independent choices live in this card. The import source travels in
// the job payload (the fetch runs in the worker, not in the browser), and
// the analyzer model lives in the section's own key — never in the Request
// Analyzer's, which is the whole reason it has a key of its own.
{
    const env = await boot();
    const byId = (id) => env.documentRoot.getElementById(id);
    // The catalogue the picker chooses from is the Models tab's own list
    // (`/api/settings` → `models.list`), so the tab needs one to offer.
    env.apiState.settings = {
        models: {
            list: [
                { provider: 'alpha', model: 'openai/gpt-5.5', active: true },
                { provider: 'alpha', model: 'openai/gpt-5.2', active: true },
                { provider: 'alpha', model: 'anthropic/claude-sonnet-4.6', active: true },
            ],
            default_selected: [],
        },
    };
    await env.clickTab('models');

    const select = byId('oc-actualize-import-source');
    check('the card offers an import source selector', select !== null);
    check('the sources come from the server, not from a hard-coded copy',
        select.querySelectorAll('option').map((o) => o.value).join(',') === 'bothub,openrouter',
        select.querySelectorAll('option').map((o) => o.value).join(','));
    check('the server default is marked as such',
        select.querySelectorAll('option')[0].textContent.includes('default'),
        select.querySelectorAll('option')[0].textContent);
    check('it starts on the server default', select.value === 'bothub', select.value);

    check('the card offers an analyzer model picker',
        byId('oc-actualize-analyzer-picker') !== null
        && byId('oc-actualize-analyzer-picker').querySelectorAll('.oc-model-trigger').length === 1);
    const analyzerStatus = byId('oc-actualize-analyzer-status');
    check('the picker shows the value in effect',
        analyzerStatus.textContent.includes('In effect:')
        && analyzerStatus.textContent.includes('alpha / openai/gpt-5.5'),
        analyzerStatus.textContent);
    check('and says it is inherited while the section has no value of its own',
        analyzerStatus.textContent.includes('inherited from analyzer.provider / analyzer.model'),
        analyzerStatus.textContent);
    check('the reset control is hidden while the value is inherited',
        byId('oc-actualize-analyzer-reset').hidden === true);
    check('the picker offers the catalogue, not just the current value',
        (() => {
            byId('oc-actualize-analyzer-picker').querySelectorAll('.oc-model-trigger')[0].click();
            const options = byId('oc-actualize-analyzer-picker').querySelectorAll('.oc-model-option');
            return options.length >= 2;
        })(), String(byId('oc-actualize-analyzer-picker').querySelectorAll('.oc-model-option').length));

    // Choosing a model saves it for the section. The GET that follows the
    // save answers with what was stored — the card paints the server's
    // state, never its own optimistic guess.
    const sectionStored = {
        ok: true, section: { provider: 'alpha', model: 'openai/gpt-5.2' },
        inherited: { provider: 'alpha', model: 'openai/gpt-5.5' },
        effective: { provider: 'alpha', model: 'openai/gpt-5.2' },
        source: 'section', note: null,
        source_key: 'models.actualize.analyzer',
        inherited_key: 'analyzer.provider / analyzer.model',
        store_available: true,
        import_sources: ['bothub', 'openrouter'], default_source: 'bothub',
    };
    const picked = env.pickAnalyzerModel('openai/gpt-5.2');
    check('picking a model in the panel is possible',
        picked !== null && picked.includes('openai/gpt-5.2'), String(picked));
    await env.flush();
    // `fetchLog` records the request body as the string the browser sent,
    // so every assertion below reads it back through this.
    const sent = (entry) => {
        try { return JSON.parse(entry.body); } catch { return null; }
    };
    const post = env.fetchLog.find((f) => f.path === '/api/models/actualize/settings' && f.method === 'POST');
    check('the choice is posted to the section endpoint',
        sent(post) !== null && sent(post).provider === 'alpha' && sent(post).model === 'openai/gpt-5.2',
        JSON.stringify(post ? sent(post) : null));
    check('and it is not sent to the Request Analyzer\'s endpoint',
        !env.fetchLog.some((f) => f.path === '/api/analyzer/settings' && f.method === 'POST'
            && sent(f) && sent(f).model === 'openai/gpt-5.2'),
        JSON.stringify(env.fetchLog.filter((f) => f.method === 'POST').map((f) => f.path)));
    env.apiState.saveActualizeSettings = { status: 200, body: sectionStored };
    env.apiState.actualizeSettings = sectionStored;
    env.pickAnalyzerModel('openai/gpt-5.2');
    await env.flush();
    check('the card repaints the value the server stored',
        byId('oc-actualize-analyzer-status').textContent.includes('set for this section'),
        byId('oc-actualize-analyzer-status').textContent);
    check('and the reset control becomes available',
        byId('oc-actualize-analyzer-reset').hidden === false);

    // The source the operator picked is what the run is asked to import.
    env.apiState.actualizePost = { status: 201, body: { ok: true, job_id: 7, status: 'queued', reused: false } };
    select.value = 'openrouter';
    await env.clickActualize();
    const enqueued = env.fetchLog.find((f) => f.path === '/api/models/actualize' && f.method === 'POST');
    check('the run is enqueued with the chosen source',
        sent(enqueued) !== null && sent(enqueued).import_source === 'openrouter',
        JSON.stringify(enqueued ? sent(enqueued) : null));

    // A refusal from the server is shown with its reason, not swallowed.
    env.apiState.saveActualizeSettings = {
        status: 400,
        body: {
            ok: false, error: 'invalid_model',
            message: 'batch-only identifier: it is served through the asynchronous Batch API, so it cannot '
                + 'take part in the synchronous consensus pool.',
        },
    };
    env.pickAnalyzerModel('anthropic/claude-sonnet-4.6');
    await env.flush();
    check('a refused model is reported with the server\'s reason',
        byId('oc-actualize-analyzer-status').textContent.includes('batch-only identifier'),
        byId('oc-actualize-analyzer-status').textContent);
    check('and the card says nothing was saved',
        byId('oc-actualize-action-status').textContent.includes('Not saved'),
        byId('oc-actualize-action-status').textContent);

    // Resetting hands the choice back to the analyzer's own setting.
    const inheritedAgain = {
        ok: true, section: null,
        inherited: { provider: 'alpha', model: 'openai/gpt-5.5' },
        effective: { provider: 'alpha', model: 'openai/gpt-5.5' },
        source: 'inherited', note: null,
        source_key: 'models.actualize.analyzer',
        inherited_key: 'analyzer.provider / analyzer.model',
        store_available: true,
        import_sources: ['bothub', 'openrouter'], default_source: 'bothub',
    };
    env.apiState.saveActualizeSettings = { status: 200, body: inheritedAgain };
    env.apiState.actualizeSettings = inheritedAgain;
    byId('oc-actualize-analyzer-reset').click();
    await env.flush();
    const resetPost = env.fetchLog.filter((f) => f.path === '/api/models/actualize/settings' && f.method === 'POST').pop();
    check('the reset is a reset, not a write of the inherited value',
        sent(resetPost) !== null && sent(resetPost).reset === true && sent(resetPost).model === undefined,
        JSON.stringify(resetPost ? resetPost.body : null));
    check('the card goes back to describing an inherited value',
        byId('oc-actualize-analyzer-status').textContent.includes('inherited from analyzer.provider'),
        byId('oc-actualize-analyzer-status').textContent);
}

// ---- a second press starts a new run, and the card must say so ------
//
// The reported symptom: after a terminal `problems` the operator pressed
// "Actualize linked models" again and the card kept showing the PREVIOUS
// run's verdict — and, because a terminal state greened every stage, that
// verdict read as success. Two things have to hold: the press clears the
// card immediately, and until the worker publishes the new run's first
// stage the card shows the wait — never the old verdict.
{
    const env = await boot();
    const byId = (id) => env.documentRoot.getElementById(id);
    const stateOf = (key) => {
        const row = byId('oc-actualize-card')
            .querySelectorAll('.oc-actualize-stage')
            .find((r) => r.getAttribute('data-stage-key') === key);
        if (!row) { return 'missing'; }
        if (row.classList.contains('oc-actualize-stage-done')) { return 'done'; }
        if (row.classList.contains('oc-actualize-stage-failed')) { return 'failed'; }
        if (row.classList.contains('oc-actualize-stage-running')) { return 'running'; }
        return 'pending'; // eslint-disable-line no-unreachable
    };
    await env.clickTab('models');

    // The last run (#41) ended in problems and named a job.
    env.apiState.actualize = {
        stage: 'problems', active: false, job_id: 41,
        message: 'Actualization aborted: the analyzer model failed. Nothing was written.',
        failed_stage: 'assigning_presets',
        warnings: ['The analyzer model could not produce a pool for preset "default".'],
        assigned: {}, selection: [], snapshot: { available: false }, worker_available: true,
        started_at: '2026-09-21T09:00:00Z', finished_at: '2026-09-21T09:00:20Z',
    };
    await env.clickTab('models');
    check('the previous run is on screen before the second press',
        stateOf('assigning_presets') === 'failed'
        && byId('oc-actualize-summary').textContent.includes('job #41'),
        stateOf('assigning_presets'));

    // The queue accepts the new job; the worker has not started it yet, so
    // the status endpoint still answers with run #41 until then.
    env.apiState.actualizePost = {
        status: 201,
        body: { ok: true, job_id: 42, status: 'queued', reused: false, message: 'Actualization enqueued.' },
    };
    const button = byId('oc-actualize-btn');
    button.click();
    // Synchronously, before the POST has even resolved: the card is reset.
    check('the press clears every stage row at once',
        stateOf('analysing_models') === 'pending' && stateOf('assigning_presets') === 'pending'
        && stateOf('finish') === 'pending',
        `${stateOf('analysing_models')}/${stateOf('assigning_presets')}/${stateOf('finish')}`);
    check('the press clears the previous run\'s summary',
        byId('oc-actualize-summary').textContent.trim() === '',
        byId('oc-actualize-summary').textContent.slice(0, 120));
    check('the status line says the job is queued',
        byId('oc-actualize-action-status').textContent === 'Queued — waiting for the worker to pick it up',
        byId('oc-actualize-action-status').textContent);
    check('the button is locked while the job is queued', button.disabled === true);
    check('no stage is green right after the press',
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length === 0);
    await env.flush();

    // The status endpoint is still talking about run #41.
    const waiting = byId('oc-actualize-summary').textContent;
    check('the previous run\'s verdict is not rendered as this run\'s',
        !waiting.includes('analyzer model failed'), waiting);
    check('the card shows the wait instead', waiting.includes('waiting for the worker'), waiting);
    check('the card names the run it is waiting for', waiting.includes('job #42'), waiting);
    check('no warning of the previous run survives the reset',
        byId('oc-actualize-summary').querySelectorAll('.oc-actualize-warnings li').length === 0);
    check('the stale snapshot does not green the stage list either',
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length === 0,
        String(byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length));
    check('the queued job is still reported as in flight', button.disabled === true);

    // The worker picks #42 up and publishes its first stage: from here the
    // card follows the NEW run, and the gate is released.
    env.apiState.actualize = {
        stage: 'analysing_models', active: true, job_id: 42,
        message: 'Fetching live model lists',
        warnings: [], assigned: {}, snapshot: { available: false }, worker_available: true,
        started_at: '2026-09-21T09:05:00Z',
    };
    await env.clickTab('models');
    check('once the new run speaks, its stage is the one shown',
        stateOf('analysing_models') === 'running', stateOf('analysing_models'));
    check('and the verdict of the old run stays gone',
        !byId('oc-actualize-summary').textContent.includes('analyzer model failed'));
    check('the card now names the new run',
        byId('oc-actualize-summary').textContent.includes('job #42'),
        byId('oc-actualize-summary').textContent);
    check('the label stops claiming the job is only queued',
        byId('oc-actualize-action-status').textContent === 'Running…',
        byId('oc-actualize-action-status').textContent);
}
{
    // A 200 answers "an identical job is already queued" (idempotent
    // enqueue) — the card must not wait for a run id that will never come.
    const env = await boot();
    await env.clickTab('models');
    env.apiState.actualize = {
        stage: 'analysing_models', active: true, job_id: 51,
        message: 'Fetching live model lists', warnings: [], assigned: {},
        snapshot: { available: false }, worker_available: true,
    };
    env.apiState.actualizePost = {
        status: 200,
        body: { ok: true, job_id: 51, status: 'running', reused: true, message: 'An actualization is already queued or running; reusing it.' },
    };
    await env.clickTab('models');
    env.documentRoot.getElementById('oc-actualize-btn').click();
    await env.flush();
    check('a reused job is followed, not waited on',
        env.documentRoot.getElementById('oc-actualize-card').querySelectorAll('.oc-actualize-stage-running').length === 1,
        String(env.documentRoot.getElementById('oc-actualize-card').querySelectorAll('.oc-actualize-stage-running').length));
    check('the reused run keeps its own stage visible',
        env.documentRoot.getElementById('oc-actualize-action-status').textContent === 'Running…',
        env.documentRoot.getElementById('oc-actualize-action-status').textContent);
}
{
    // The enqueue answer carries no job id (an older server): the gate
    // falls back to "must not be the run that was on screen".
    const env = await boot();
    await env.clickTab('models');
    env.apiState.actualize = {
        stage: 'success', active: false, job_id: 61, message: 'Actualization complete.',
        warnings: [], assigned: {}, selection: [], snapshot: { available: true }, worker_available: true,
    };
    await env.clickTab('models');
    check('the finished run is on screen', env.documentRoot.getElementById('oc-actualize-summary').textContent.includes('Actualization complete'));
    env.apiState.actualizePost = { status: 201, body: { ok: true, status: 'queued', reused: false } };
    env.documentRoot.getElementById('oc-actualize-btn').click();
    await env.flush();
    check('without a job id the previous run is still not shown as the result',
        !env.documentRoot.getElementById('oc-actualize-summary').textContent.includes('Actualization complete'),
        env.documentRoot.getElementById('oc-actualize-summary').textContent);
    check('the card waits for a run it has not seen yet',
        env.documentRoot.getElementById('oc-actualize-summary').textContent.includes('waiting for the worker'));
}

{
    // The queue says the run is queued: nothing has started yet, so no stage
    // may be painted as running (the server reports stage 1 as "next").
    const env = await boot();
    await env.clickTab('models');
    env.apiState.actualize = {
        stage: 'analysing_models', active: true, job_status: 'queued', job_id: 71,
        message: '', warnings: [], assigned: {}, snapshot: { available: false },
        worker_available: true, queued_seconds: 12,
    };
    await env.clickTab('models');
    const card = env.documentRoot.getElementById('oc-actualize-card');
    check('a queued job paints no stage as running',
        card.querySelectorAll('.oc-actualize-stage-running').length === 0,
        String(card.querySelectorAll('.oc-actualize-stage-running').length));
    check('a queued job paints no stage as done',
        card.querySelectorAll('.oc-actualize-stage-done').length === 0);
    check('the card says the job is waiting for the worker',
        env.documentRoot.getElementById('oc-actualize-summary').textContent.includes('waiting for the worker'),
        env.documentRoot.getElementById('oc-actualize-summary').textContent);
    check('the status label says queued rather than running',
        env.documentRoot.getElementById('oc-actualize-action-status').textContent === 'Queued — waiting for the worker to pick it up',
        env.documentRoot.getElementById('oc-actualize-action-status').textContent);
    check('the card names the queued run',
        env.documentRoot.getElementById('oc-actualize-summary').textContent.includes('job #71'));
    check('the button stays locked while it is queued',
        env.documentRoot.getElementById('oc-actualize-btn').disabled === true);
}

// ---- enqueue failures surface -------------------------------------
{
    const env = await boot();
    await env.clickTab('models');
    const original = globalThis.fetch;
    globalThis.fetch = async (path, init) => {
        if (String(path) === '/api/models/actualize') {
            return { ok: false, status: 503, json: async () => ({ error: 'job_table_missing', message: 'The durable job queue is not available.' }) };
        }
        return original(path, init);
    };
    const button = env.documentRoot.getElementById('oc-actualize-btn');
    button.click();
    await env.flush();
    check('an enqueue failure is shown next to the button',
        env.documentRoot.getElementById('oc-actualize-action-status').textContent.includes('durable job queue'),
        env.documentRoot.getElementById('oc-actualize-action-status').textContent);
    check('the button is re-enabled after a failure', button.disabled === false);
}

// =====================================================================
console.log('\n== M1/M2: a save that did not land never reads "Saved." ==');
// =====================================================================
//
// The reported bug in one sentence: the API answered {"ok":true} for a write
// that never reached the file, so the button said "Saved." and the list
// reverted on the next load. These checks drive the real button against a
// canned failure body and read the status line the operator would see.

/** The status line that belongs to a card's action row. */
function statusLine(env, cardId) {
    const card = env.documentRoot.getElementById(cardId);
    if (!card) { return null; }
    return card.querySelectorAll('.oc-actions .oc-status')[0] || null;
}

/** A button inside a card, found by its visible label. */
function buttonByText(env, cardId, label) {
    const card = env.documentRoot.getElementById(cardId);
    if (!card) { return null; }
    return card.querySelectorAll('button').find((b) => b.textContent.trim() === label) || null;
}

const MODELS_SETTINGS = {
    providers: {
        openrouter: { active: true, base_url: '', api_key: '', has_key: false, source: 'none' },
        bothub: { active: true, base_url: '', api_key: '', has_key: false, source: 'none' },
    },
    models: {
        list: [
            { provider: 'alpha', model: 'gpt-5.4', active: true },
            { provider: 'alpha', model: 'gpt-5.5', active: true },
            { provider: 'alpha', model: 'claude-sonnet-4', active: false },
        ],
        default_selected: [],
    },
    recommended_models: [],
};

const WRITE_FAILURE = {
    status: 500,
    body: {
        ok: false,
        error: 'config_write_failed',
        saved: false,
        target: 'var/config/runtime.json',
        stage: 'rename',
        message: 'Settings were NOT saved: The temporary file could not be moved into place. '
            + 'Nothing changed — the previous settings are still in effect.',
        details: {
            error: 'config_write_failed',
            stage: 'rename',
            target: 'var/config/runtime.json',
            fs: { file_perms: '0644', file_owner: 'root', process_user: 'www-data' },
            hint: 'Check that the PHP process owns (or can write) the settings file and its directory.',
        },
    },
};

{
    const env = await boot();
    env.apiState.settings = MODELS_SETTINGS;
    env.apiState.saveModels = WRITE_FAILURE;
    await env.clickTab('models');

    const status = statusLine(env, 'oc-models-card');
    check('the model card has a status line to report into', status !== null);
    buttonByText(env, 'oc-models-card', 'Save models').click();
    await env.flush();

    const text = status.textContent;
    check('a failed write never reads "Saved."', !text.startsWith('Saved.') && text.trim() !== 'Saved.', text);
    check('it says the settings were NOT saved', text.includes('NOT saved'), text);
    check('it names the file that could not be written', text.includes('var/config/runtime.json'), text);
    check('it names the failing stage', text.includes('rename'), text);
    check('it shows the filesystem facts (mode/owner/php user)',
        text.includes('0644') && text.includes('root') && text.includes('www-data'), text);
    check('it explains the consequence', text.includes('Nothing changed'), text);
    check('the rows are still on screen after the failure',
        env.documentRoot.querySelectorAll('#oc-models-card tbody tr').length === 3);
}
{
    const env = await boot();
    env.apiState.settings = MODELS_SETTINGS;
    env.apiState.modelsAfterSave = [
        { provider: 'alpha', model: 'gpt-5.4', active: true },
        { provider: 'alpha', model: 'gpt-5.5', active: true },
        { provider: 'alpha', model: 'claude-sonnet-4', active: false },
    ];
    await env.clickTab('models');
    const status = statusLine(env, 'oc-models-card');
    buttonByText(env, 'oc-models-card', 'Save models').click();
    await env.flush();

    const posted = env.fetchLog.filter((f) => f.path === '/api/settings/models' && f.method === 'POST');
    check('the save posts every row, in order', posted.length === 1, String(posted.length));
    const sent = JSON.parse(posted[0].body);
    check('all three rows were sent', sent.models.length === 3, JSON.stringify(sent.models));
    check('the active flag travels with each row',
        sent.models[0].active === true && sent.models[2].active === false);
    check('a confirmed save says how many rows were stored',
        status.textContent.startsWith('Saved 3 models'), status.textContent);
}
{
    // The write landed, but the file's own content disagrees with what was
    // sent — reported instead of a cheerful "Saved.".
    const env = await boot();
    env.apiState.settings = MODELS_SETTINGS;
    env.apiState.saveModels = {
        status: 200,
        body: {
            ok: true,
            models: { list: MODELS_SETTINGS.models.list, default_selected: [] },
            saved: { rows: 2, kept: 2, new: 0 },
            persisted: {
                ok: true, state: 'ok',
                data: { models: [{ provider: 'alpha', model: 'gpt-5.4', active: true }] },
            },
        },
    };
    await env.clickTab('models');
    const status = statusLine(env, 'oc-models-card');
    buttonByText(env, 'oc-models-card', 'Save models').click();
    await env.flush();
    check('a mismatching read-back is reported, not glossed over',
        status.textContent.includes('the stored catalogue differs'), status.textContent);
    check('the mismatch names the rows that did not stick',
        status.textContent.includes('alpha/gpt-5.5'), status.textContent);
}
{
    // The file could not be read back at all.
    const env = await boot();
    env.apiState.settings = MODELS_SETTINGS;
    env.apiState.saveModels = {
        status: 200,
        body: {
            ok: true,
            models: { list: MODELS_SETTINGS.models.list, default_selected: [] },
            persisted: { ok: false, state: 'unreadable', data: {}, error: { message: 'The file exists but this process cannot read it.' } },
        },
    };
    await env.clickTab('models');
    const status = statusLine(env, 'oc-models-card');
    buttonByText(env, 'oc-models-card', 'Save models').click();
    await env.flush();
    check('an unreadable settings file is reported, not called "Saved."',
        status.textContent.includes('could not be read back'), status.textContent);
}

// ---- the analyzer button ------------------------------------------
{
    const env = await boot();
    env.apiState.saveAnalyzer = {
        status: 500,
        body: {
            ok: false, error: 'config_write_failed', target: 'var/config/runtime.json', stage: 'verify',
            message: 'Settings were NOT saved: The settings file was written but reading it back returned different bytes.',
            details: { fs: { file_perms: '0600', process_user: 'www-data' } },
        },
    };
    await env.clickTab('models');
    const status = statusLine(env, 'oc-analyzer-card');
    buttonByText(env, 'oc-analyzer-card', 'Save analyzer settings').click();
    await env.flush();
    check('the analyzer save reports the failure instead of "Saved."',
        status.textContent.includes('NOT saved') && status.textContent.includes('verify'), status.textContent);
}
{
    const env = await boot();
    env.apiState.saveAnalyzer = {
        status: 200,
        body: {
            ok: true, updated: ['analyzer.model'],
            state: { enabled: false, provider: 'alpha', model: 'analyzer-model' },
            // The file still holds the old model: the write was accepted but
            // did not take effect.
            persisted: { ok: true, state: 'ok', data: { analyzer: { provider: 'alpha', model: 'someone-elses-model' } } },
        },
    };
    await env.clickTab('models');
    const status = statusLine(env, 'oc-analyzer-card');
    buttonByText(env, 'oc-analyzer-card', 'Save analyzer settings').click();
    await env.flush();
    check('the analyzer save verifies the file rather than trusting `ok`',
        status.textContent.includes('NOT saved') && status.textContent.includes('someone-elses-model'), status.textContent);
}
{
    const env = await boot();
    await env.clickTab('models');
    const status = statusLine(env, 'oc-analyzer-card');
    buttonByText(env, 'oc-analyzer-card', 'Save analyzer settings').click();
    await env.flush();
    check('a confirmed analyzer save names the model that will be used',
        status.textContent.includes('analyzer will use alpha / analyzer-model'), status.textContent);
}

// =====================================================================
console.log('\n== M3: the imported catalogue is persisted, not tab-local ==');
// =====================================================================
{
    const env = await boot();
    env.apiState.settings = MODELS_SETTINGS;
    env.apiState.importModels = {
        status: 200,
        body: {
            ok: true, provider: 'openrouter', count: 12,
            catalog: [{ id: 'openai/gpt-6', name: 'GPT 6' }],
            imported: { added: 2, duplicates: 1, present: 3, truncated: 4, limit: 64, rows: 5, ids: [] },
            models: { list: MODELS_SETTINGS.models.list, default_selected: [] },
            persisted: { ok: true, state: 'ok', data: { models: [] } },
        },
    };
    await env.clickTab('models');
    const settingsBefore = env.fetchLog.filter((f) => f.path === '/api/settings').length;
    const status = statusLine(env, 'oc-providers-card');
    buttonByText(env, 'oc-providers-card', 'Import models').click();
    await env.flush();

    const importCalls = env.fetchLog.filter((f) => f.path === '/api/settings/models/import');
    check('the import posts to the import endpoint', importCalls.length === 1, String(importCalls.length));
    check('it names the provider', JSON.parse(importCalls[0].body).provider === 'openrouter');
    check('the result reports the stored rows', status.textContent.includes('2 new row(s) stored'), status.textContent);
    check('it reports the duplicates it skipped', status.textContent.includes('1 already in the catalogue'), status.textContent);
    check('it admits when the row cap left models out',
        status.textContent.includes('4 left out') && status.textContent.includes('capped at 64'), status.textContent);
    check('the tab re-reads /api/settings, so the stored rows are on screen',
        env.fetchLog.filter((f) => f.path === '/api/settings').length > settingsBefore);
    check('an import failure is reported with the same detail',
        modelsJs.includes('describeSaveFailure(res)') && modelsJs.includes('body.imported'));
}

// =====================================================================
console.log('\n== M4: filters on the catalogue table ==');
// =====================================================================
{
    const env = await boot();
    env.apiState.settings = MODELS_SETTINGS;
    await env.clickTab('models');
    const rows = () => env.documentRoot.querySelectorAll('#oc-models-card tbody tr');
    const visible = () => rows().filter((r) => !r.hidden);
    const note = () => env.documentRoot.getElementById('oc-models-filter-note').textContent;

    check('every catalogue row is rendered', rows().length === 3, String(rows().length));
    check('the table has a text filter', env.documentRoot.getElementById('oc-models-filter-text') !== null);
    check('the table has a "newer version" checkbox', env.documentRoot.getElementById('oc-models-filter-newer') !== null);

    const input = env.documentRoot.getElementById('oc-models-filter-text');
    input.value = 'sonnet';
    input.dispatchEvent({ type: 'input', target: input });
    await new Promise((r) => setTimeout(r, 400));
    check('the text filter keeps only matching rows',
        visible().length === 1 && visible()[0].dataset.model === 'claude-sonnet-4',
        visible().map((r) => r.dataset.model).join(','));
    check('hidden rows are hidden, not removed (so the save cannot drop them)',
        rows().length === 3);
    check('the counter says how many rows are hidden', note().includes('2 models hidden by filter'), note());
    check('the counter says how many of the hidden rows are active and still saved',
        note().includes('2 of them active and still saved'), note());

    input.value = '';
    input.dispatchEvent({ type: 'input', target: input });
    await new Promise((r) => setTimeout(r, 400));
    check('clearing the filter restores every row', visible().length === 3);

    const newer = env.documentRoot.getElementById('oc-models-filter-newer');
    newer.checked = true;
    newer.dispatchEvent({ type: 'change', target: newer });
    await new Promise((r) => setImmediate(r));
    check('"only models with a newer version" keeps exactly the superseded row',
        visible().length === 1 && visible()[0].dataset.model === 'gpt-5.4',
        visible().map((r) => r.dataset.model).join(','));
    check('the newest row of the line is filtered out', visible()[0].dataset.model !== 'gpt-5.5');
    check('the superseded row says which release supersedes it',
        rows().find((r) => r.dataset.model === 'gpt-5.4').textContent.includes('gpt-5.5'),
        rows().find((r) => r.dataset.model === 'gpt-5.4').textContent);

    // The row kept its active flag while it was hidden, and the save still
    // carries it: a filter is a view, not an edit.
    env.apiState.modelsAfterSave = MODELS_SETTINGS.models.list;
    buttonByText(env, 'oc-models-card', 'Save models').click();
    await env.flush();
    const posted = JSON.parse(env.fetchLog.filter((f) => f.path === '/api/settings/models' && f.method === 'POST')[0].body);
    check('a hidden row is still saved, with its active flag',
        posted.models.length === 3 && posted.models.some((m) => m.model === 'gpt-5.4' && m.active === true),
        JSON.stringify(posted.models));
}

// =====================================================================
console.log('\n== T1: the sidebar rows are comboboxes, and their search works ==');
// =====================================================================
//
// The reported bug in one sentence: the pool's filter box sat ABOVE the rows,
// so clicking into it blurred the native `<select>` popup it was supposed to
// narrow, and typing while that popup was closed filtered a list nobody could
// see. These checks drive the replacement — an in-row popup with its own
// search field — through the very gestures the operator described.

/** Wait out a debounce window (the modules debounce at 200ms). */
const settle = () => new Promise((r) => setTimeout(r, 400));
/** Type into a field the way a keyboard does: value, then `input`. */
const typeInto = (input, text) => {
    input.value = text;
    input.dispatchEvent({ type: 'input', target: input });
};
/** A keydown with the shape the modules switch on. */
const keyDown = (node, key) => node.dispatchEvent({ type: 'keydown', key, preventDefault() {} });

{
    const env = await boot();
    const rootEl = env.documentRoot.getElementById('model-selector');
    const combos = () => env.selector.comboboxes;
    const addCombo = () => combos()[combos().length - 1];
    /** `provider|model` of a combobox's current option list, from the DOM. */
    const renderedKeys = (combo) => combo.list
        .querySelectorAll('.oc-model-option')
        .map((li) => li.dataset.value);
    const key = (row) => `${row.provider}|${row.model}`;

    check('the sidebar renders the pool-wide filter box',
        env.documentRoot.getElementById('oc-model-filter-text') !== null);
    check('the sidebar renders the "search all models" checkbox',
        env.documentRoot.getElementById('oc-model-filter-all') !== null);
    check('the pool starts with three rows', env.selector.selected().length === 3,
        JSON.stringify(env.selector.selected()));
    check('no row is a native <select> any more',
        rootEl.querySelectorAll('select').length === 0);
    check('every pool row is a combobox, plus the add row',
        combos().length === env.selector.selected().length + 1,
        String(combos().length));

    const first = combos()[0];
    const firstRow = env.selector.selected()[0];
    const trigger = first.trigger;
    check('the trigger carries the combobox role and its popup relationship',
        trigger.getAttribute('role') === 'combobox'
        && trigger.getAttribute('aria-haspopup') === 'listbox'
        && trigger.getAttribute('aria-expanded') === 'false'
        && trigger.getAttribute('aria-controls') === first.list.id,
        JSON.stringify({
            role: trigger.getAttribute('role'),
            haspopup: trigger.getAttribute('aria-haspopup'),
            expanded: trigger.getAttribute('aria-expanded'),
            controls: trigger.getAttribute('aria-controls'),
            list: first.list.id,
        }));
    check('the trigger shows the model, and the full `provider / model` in title and aria-label',
        first.label.textContent === firstRow.model
        && trigger.getAttribute('title').includes(`${firstRow.provider} / ${firstRow.model}`)
        && trigger.getAttribute('aria-label').includes(`${firstRow.provider} / ${firstRow.model}`),
        `${first.label.textContent} | ${trigger.getAttribute('title')}`);
    check('the panel exists inside the row and starts closed',
        first.panel.hasAttribute('hidden') && first.wrap.parentNode.classList.contains('oc-model-row'));
    check('the panel holds a search field above the list',
        first.panel.querySelector('.oc-model-search') === first.search
        && first.panel.querySelector('.oc-model-options') === first.list
        && first.panel.querySelector('.oc-model-panel-note') === first.note);
    check('the options are listbox options carrying their id, not <option>s',
        first.list.querySelectorAll('[role="option"]').length === first.options.length
        && first.list.querySelectorAll('option').length === 0);

    // ---- open, and the focus the search field must take ---------------
    trigger.click();
    check('clicking the trigger opens the panel in place (no native popup)',
        first.isOpen === true && first.panel.hasAttribute('hidden') === false
        && trigger.getAttribute('aria-expanded') === 'true');
    check('the open row is marked for CSS (the layout hook still works)',
        first.rowNode.classList.contains('oc-model-row-open'));
    check('the search field takes the focus when the panel opens',
        globalThis.document.activeElement === first.search,
        `activeElement=${globalThis.document.activeElement && globalThis.document.activeElement.className}`);

    // ---- a click INSIDE the panel never closes it --------------------
    const searchNode = first.search;
    globalThis.document.dispatchClick(searchNode);
    check('a click in the search field does not close the panel',
        first.isOpen === true, `open=${first.isOpen}`);
    const optionNode = first.list.querySelectorAll('.oc-model-option')[0];
    globalThis.document.dispatchClick(optionNode);
    check('a click on the option list does not close the panel',
        first.isOpen === true, `open=${first.isOpen}`);
    // The scrollbar is part of the list box: it is inside the panel, so the
    // same containment rule covers it.
    globalThis.document.dispatchClick(first.list);
    check('a click on the scrolled list itself does not close the panel',
        first.isOpen === true);

    // ---- typing narrows the list live, and the input survives ---------
    const before = JSON.stringify(env.selector.selected());
    const wideOpen = renderedKeys(first).length;
    typeInto(searchNode, 'gpt');
    await settle();
    check('typing filters the option list without closing the panel',
        first.isOpen === true
        && renderedKeys(first).length > 0
        && renderedKeys(first).every((k) => k.toLowerCase().includes('gpt')),
        `${first.isOpen} :: ${renderedKeys(first).join(',')}`);
    check('the search field is never re-created, so focus and caret stay put',
        first.panel.querySelector('.oc-model-search') === searchNode
        && searchNode.value === 'gpt');
    check('the list really narrowed',
        renderedKeys(first).length < wideOpen,
        `${renderedKeys(first).length} vs ${wideOpen}`);

    // ---- the empty state says what happened and how to undo it --------
    typeInto(searchNode, 'no-such-model-xyz');
    await settle();
    check('an empty result is stated in words',
        first.note.textContent.includes('No model matches "no-such-model-xyz"'),
        first.note.textContent);
    check('the empty state says how to get the list back',
        first.note.textContent.toLowerCase().includes('clear the search field'),
        first.note.textContent);
    check('the empty result keeps the panel open',
        first.isOpen === true);
    check('an empty result is not offered as a pick',
        first.commitActive() === false);

    typeInto(searchNode, '');
    await settle();
    check('clearing the query brings the list back',
        renderedKeys(first).length > 0 && first.note.textContent === '');

    // ---- arrows move the highlight, Enter picks ----------------------
    const wanted = first.options.find((row) => key(row) !== key(firstRow));
    typeInto(searchNode, wanted.model);
    await settle();
    keyDown(searchNode, 'ArrowDown');
    keyDown(searchNode, 'ArrowDown');
    check('the arrow keys highlight an option (published to the search field)',
        first.highlight >= 0
        && searchNode.getAttribute('aria-activedescendant')
            === first.optionNodes[first.highlight].id,
        String(first.highlight));
    keyDown(searchNode, 'Enter');
    check('Enter picks the highlighted model, closes the panel and swaps the row',
        env.selector.selected()[0].model === wanted.model
        && first.isOpen === false,
        JSON.stringify(env.selector.selected()));
    check('the swap is persisted the way the pool always persisted it',
        (globalThis.localStorage.getItem('oc.selected_models.v1') || '').includes(wanted.model));
    check('the pool still holds three models — a swap, not an append',
        env.selector.selected().length === 3, JSON.stringify(env.selector.selected()));
    check('searching never changed the pool on its own',
        JSON.parse(before).length === 3);

    // ---- Escape: close, change nothing ------------------------------
    const reopened = combos()[0];
    const keepKey = key(env.selector.selected()[0]);
    reopened.trigger.click();
    typeInto(reopened.search, 'zzz');
    await settle();
    keyDown(reopened.search, 'Escape');
    check('Escape closes the panel',
        reopened.isOpen === false && reopened.panel.hasAttribute('hidden'));
    check('Escape leaves the selection exactly as it was',
        key(env.selector.selected()[0]) === keepKey,
        JSON.stringify(env.selector.selected()));
    check('Escape puts the focus back on the row',
        globalThis.document.activeElement === reopened.trigger);
    check('a re-opened panel starts from the row, not from the last search',
        reopened.search.value === '' && reopened.query === '');

    // ---- closing without changing anything else ----------------------
    const outside = env.documentRoot.querySelector('[data-tab="chat"]');
    const outsideCombo = combos()[1];
    outsideCombo.trigger.click();
    check('a second row opens its own panel', outsideCombo.isOpen === true);
    check('opening one row closes the other', combos()[0].isOpen === false);
    globalThis.document.dispatchClick(outside);
    check('a click outside the widget closes every panel',
        outsideCombo.isOpen === false && combos().every((c) => c.isOpen === false));
    outsideCombo.trigger.click();
    outsideCombo.wrap.dispatchEvent({ type: 'focusout', relatedTarget: outside });
    check('focus leaving the row closes its panel', outsideCombo.isOpen === false);
    outsideCombo.trigger.click();
    outsideCombo.wrap.dispatchEvent({ type: 'focusout', relatedTarget: outsideCombo.search });
    check('focus moving INSIDE the row keeps the panel open', outsideCombo.isOpen === true);
    outsideCombo.close();

    // ---- "Search all models" searches the whole catalogue ----------------
    //
    // Run before the add row below, while the pool still holds three models
    // and the catalogue holds four: with every catalogue model in the pool
    // the lists collapse to the rows' own values, which would make these
    // checks say nothing.
    const pooledKey = key(env.selector.selected()[0]);
    env.selector.setShowAll(true);
    check('"Search all models" with an empty box lists nothing (it is a search, not a dump)',
        renderedKeys(addCombo()).length === 0, renderedKeys(addCombo()).join(','));
    check('and its panel asks for a query rather than showing the catalogue',
        addCombo().note.textContent.includes('type in the box above'), addCombo().note.textContent);
    env.selector.setFilter('alpha');
    check('searching the whole catalogue offers the pooled model too',
        renderedKeys(addCombo()).includes(pooledKey), renderedKeys(addCombo()).join(','));
    check('and only matches are listed',
        renderedKeys(addCombo()).every((k) => k.toLowerCase().includes('alpha')),
        renderedKeys(addCombo()).join(','));
    env.selector.setFilter('no-such-model-xyz');
    check('a search that matches nothing lists nothing', renderedKeys(addCombo()).length === 0);
    check('and says which empty state it is',
        addCombo().note.textContent.includes('No catalogue model matches'), addCombo().note.textContent);
    env.selector.setFilter('');
    env.selector.setShowAll(false);
    check('turning it off hides the pooled models again',
        !renderedKeys(addCombo()).includes(pooledKey));
    const beforeFilter = JSON.stringify(env.selector.selected());
    env.selector.setFilter('gpt');
    const afterFilter = renderedKeys(combos()[0]);
    const ownKey = key(env.selector.selected()[0]);
    check('the pool-wide filter narrows every combobox list (the row\'s own model stays listed)',
        afterFilter.filter((k) => k.toLowerCase().includes('gpt')).length > 0
        && afterFilter.every((k) => k.toLowerCase().includes('gpt') || k === ownKey),
        afterFilter.join(','));
    check('the filter never changes the selection',
        JSON.stringify(env.selector.selected()) === beforeFilter);
    const outside2 = env.selector.selectionOutsideFilter();
    check('the helper counts the selected models the filter hides', outside2.total >= 1,
        JSON.stringify(outside2));
    check('the counter reports the selected models the filter hides',
        rootEl.textContent.includes(`${outside2.total} selected hidden by filter`),
        rootEl.textContent.slice(-120));
    check('each selected model outside the filter is marked as such',
        rootEl.querySelectorAll('.oc-model-row-filtered').length === outside2.total,
        String(rootEl.querySelectorAll('.oc-model-row-filtered').length));

    env.selector.setFilter('');
    check('clearing the filter unmarks the rows',
        rootEl.querySelectorAll('.oc-model-row-filtered').length === 0);
    check('the counter is back to a plain selection count',
        rootEl.textContent.includes('3 models selected.'), rootEl.textContent);

    // ---- the add row is the same control ----------------------------
    const add = addCombo();
    check('the add row is a combobox labelled "+ add model"',
        add.trigger.getAttribute('role') === 'combobox' && add.label.textContent === '+ add model');
    const pooled = key(env.selector.selected()[0]);
    check(`the add panel hides models already in the pool (${pooled})`,
        !renderedKeys(add).includes(pooled), renderedKeys(add).join(','));
    const addable = add.options[0];
    add.trigger.click();
    add.optionNodes[0].click();
    await new Promise((r) => setImmediate(r));
    check('picking from the add panel appends the model to the pool',
        env.selector.selected().length === 4
        && key(env.selector.selected()[3]) === key(addable),
        JSON.stringify(env.selector.selected()));
    check('choosing a model closes the panel',
        addCombo().isOpen === false);
    check('the counter follows the new pool',
        rootEl.textContent.includes('4 models selected.'), rootEl.textContent);
}

// =====================================================================
console.log('\n== M1/M2: "Search all models" searches the whole catalogue, capped ==');
// =====================================================================
//
// The reported defect: ticking "Show all models" (now "Search all models") froze the sidebar. The cause
// was structural — the mode listed the ENTIRE catalogue inside every combobox,
// so the sidebar rebuilt catalogue-sized option lists on every change and the
// cost grew with the provider's catalogue. The mode is now a search ("Search
// all models"): the whole catalogue is searched (pooled models included), only
// matches are listed, and the list is hard-capped at ten rows, so the work is
// bounded by the cap instead of by the catalogue. The counter never claims the
// whole catalogue is shown, because it never is.
{
    const env = await boot();
    const rootEl = env.documentRoot.getElementById('model-selector');
    const combos = () => env.selector.comboboxes;
    const addCombo = () => combos()[combos().length - 1];
    const key = (row) => `${row.provider}|${row.model}`;
    const renderedKeys = (combo) => combo.list
        .querySelectorAll('.oc-model-option').map((li) => li.dataset.value);
    const filterInput = () => env.documentRoot.getElementById('oc-model-filter-text');
    const showAllBox = () => env.documentRoot.getElementById('oc-model-filter-all');
    const tickShowAll = (on) => {
        const box = showAllBox();
        box.checked = on;
        box.dispatchEvent({ type: 'change', target: box });
    };
    const CATALOG4 = [
        { provider: 'alpha', model: 'openai/gpt-5.5' },
        { provider: 'alpha', model: 'anthropic/claude-sonnet-4.6' },
        { provider: 'alpha', model: 'google/gemini-3.1-pro-preview' },
        { provider: 'alpha', model: 'deepseek/deepseek-v4-pro' },
    ];
    const WITHHELD = 'alpha|deepseek/deepseek-v4-pro';
    const FIRST = 'alpha|openai/gpt-5.5';

    // A deterministic pool: three of the four catalogue models are in it, so
    // exactly one model is left for the add row to offer — and the one left
    // out is NOT the one the name filter matches. That is the reported
    // arrangement.
    env.selector.setCatalog(CATALOG4, [
        { provider: 'alpha', model: 'openai/gpt-5.5' },
        { provider: 'alpha', model: 'anthropic/claude-sonnet-4.6' },
        { provider: 'alpha', model: 'google/gemini-3.1-pro-preview' },
    ]);
    check('the fixture is the reported one: four catalogue models, three in the pool',
        env.selector.catalog.length === 4 && env.selector.selected().length === 3,
        `${env.selector.catalog.length}/${env.selector.selected().length}`);
    check('with no filter, the add row offers the one model that is not in the pool',
        renderedKeys(addCombo()).length === 1 && renderedKeys(addCombo())[0] === WITHHELD,
        renderedKeys(addCombo()).join(','));
    check('and its trigger is usable',
        addCombo().trigger.disabled === false && addCombo().trigger.title === 'Add another model to the consensus pool',
        addCombo().trigger.title);

    // ---- the default filter still narrows, and it still says why --------
    typeInto(filterInput(), 'gpt');
    await settle();
    check('typing in the box reaches the selector state',
        env.selector.filterText === 'gpt', env.selector.filterText);
    check('the name filter narrows the add list down to nothing',
        renderedKeys(addCombo()).length === 0, renderedKeys(addCombo()).join(','));
    check('the add trigger is switched off while the filter hides everything',
        addCombo().trigger.disabled === true);
    check('the reason is readable as text under the list, not only in the tooltip',
        rootEl.textContent.includes('The name filter above hides every remaining model.'),
        rootEl.textContent.slice(-200));
    check('the tooltip carries the same reason',
        addCombo().trigger.title === 'The name filter above hides every remaining model.',
        addCombo().trigger.title);
    check('and the counter names how much of the catalogue the filter hides',
        rootEl.textContent.includes('3 catalogue models hidden by filter.'), rootEl.textContent.slice(-200));
    check('the filter field is active while the toggle is off',
        filterInput().disabled === false && showAllBox().checked === false);

    // ---- M1: ticking the box turns the box into a catalogue search ------
    tickShowAll(true);
    check('the typed text is NOT thrown away when the box is ticked',
        filterInput().value === 'gpt', filterInput().value);
    check('the field keeps working — it is the search box now, not a disabled one',
        filterInput().disabled === false);
    check('and its tooltip says it searches the whole catalogue',
        filterInput().title.includes('Searches the WHOLE catalogue'), filterInput().title);
    check('the search may offer a model that is already in the pool (that is the point)',
        renderedKeys(addCombo()).includes(key(env.selector.selected()[0])),
        renderedKeys(addCombo()).join(','));
    check('only matches are listed',
        renderedKeys(addCombo()).every((k) => k.toLowerCase().includes('gpt')),
        renderedKeys(addCombo()).join(','));
    check('the counter says the whole catalogue is being searched',
        rootEl.textContent.includes('Searching every catalogue model for "gpt"'),
        rootEl.textContent.slice(-240));

    // ---- M1/M2: the cap is what stops the freeze ------------------------
    // A catalogue big enough that an uncapped list would be the whole thing: a
    // search matching 40 models must still collapse to ten rows in every
    // combobox, which is what bounds the work the toggle can trigger.
    const big = [];
    for (let i = 0; i < 40; i++) { big.push({ provider: 'alpha', model: `bulk/model-${i}` }); }
    const startedAt = Date.now();
    env.selector.setCatalog(big.concat(CATALOG4), [{ provider: 'alpha', model: 'openai/gpt-5.5' }]);
    env.selector.setFilter('bulk/');
    const cappedKeys = renderedKeys(addCombo());
    const elapsed = Date.now() - startedAt;
    check('a search over a 44-model catalogue is capped at ten rows',
        cappedKeys.length === 10, String(cappedKeys.length));
    check('every listed row is a match',
        cappedKeys.every((k) => k.toLowerCase().includes('bulk/')), cappedKeys.join(','));
    check('no combobox rebuilt the whole catalogue (the freeze cannot come back)',
        combos().every((c) => c.list.querySelectorAll('.oc-model-option').length <= 10),
        combos().map((c) => c.list.querySelectorAll('.oc-model-option').length).join(','));
    check('and the search returned promptly', elapsed < 2000, `${elapsed}ms`);
    check('the counter names the cap',
        rootEl.textContent.includes('at most 10 listed'), rootEl.textContent.slice(-240));

    // ---- the two empty states are told apart ----------------------------
    env.selector.setFilter('');
    check('an empty search lists nothing, not the whole catalogue',
        renderedKeys(addCombo()).length === 0, renderedKeys(addCombo()).join(','));
    check('and its panel asks for a query',
        addCombo().note.textContent.includes('type in the box above'), addCombo().note.textContent);
    check('the counter puts the same instruction in words',
        rootEl.textContent.includes('Search all models is on: type in the box above'),
        rootEl.textContent.slice(-240));
    env.selector.setFilter('no-such-model-xyz');
    check('a search that matches nothing lists nothing', renderedKeys(addCombo()).length === 0);
    check('and says which of the two empty states it is',
        addCombo().note.textContent.includes('No catalogue model matches'), addCombo().note.textContent);

    tickShowAll(false);
    env.selector.setFilter('');
    globalThis.localStorage.clear();
    env.selector.setCatalog(CATALOG4, [
        { provider: 'alpha', model: 'openai/gpt-5.5' },
        { provider: 'alpha', model: 'anthropic/claude-sonnet-4.6' },
        { provider: 'alpha', model: 'google/gemini-3.1-pro-preview' },
    ]);
    check('unticking turns the box back into the pool filter: the missing model is offered',
        renderedKeys(addCombo()).length === 1 && renderedKeys(addCombo())[0] === WITHHELD,
        renderedKeys(addCombo()).join(','));

    // ---- M2: the whole add path, end to end -----------------------------
    let changeEvents = 0;
    env.selector.addEventListener('change', () => { changeEvents++; });
    const beforeAdd = env.selector.selected().length;
    const add = addCombo();
    add.trigger.click();
    check('clicking the trigger opens the add panel in place',
        add.isOpen === true && add.panel.hasAttribute('hidden') === false);
    const addableIndex = add.options.findIndex((row) => key(row) === WITHHELD);
    check('the withheld model is on offer in it', addableIndex >= 0, renderedKeys(add).join(','));
    add.optionNodes[addableIndex].click();
    await new Promise((r) => setImmediate(r));
    check('picking it appends the model to the pool',
        env.selector.selected().length === beforeAdd + 1
        && key(env.selector.selected()[env.selector.selected().length - 1]) === WITHHELD,
        JSON.stringify(env.selector.selected()));
    check('the choice closes the panel',
        addCombo().isOpen === false);
    check('the change event fired', changeEvents === 1, String(changeEvents));
    check('the addition is persisted the way the pool always persisted it',
        (globalThis.localStorage.getItem('oc.selected_models.v1') || '').includes('deepseek/deepseek-v4-pro'));
    check('the counter follows the new pool',
        rootEl.textContent.includes('4 models selected.'), rootEl.textContent.slice(0, 60));
    check('with the whole catalogue in the pool, the counter names the real reason',
        rootEl.textContent.includes('Nothing left to add: every catalogue model is already in the pool.'),
        rootEl.textContent.slice(-200));
    check('and the disabled trigger carries that same reason',
        addCombo().trigger.disabled === true
        && addCombo().trigger.title === 'Nothing left to add: every catalogue model is already in the pool.',
        addCombo().trigger.title);

    // ---- removal still works, and un-blocks adding ----------------------
    const beforeRemove = env.selector.selected().length;
    const removeBtn = rootEl.querySelectorAll('.oc-model-remove')[0];
    check('every pool row still carries its remove control',
        rootEl.querySelectorAll('.oc-model-remove').length === beforeRemove,
        String(rootEl.querySelectorAll('.oc-model-remove').length));
    removeBtn.click();
    await new Promise((r) => setImmediate(r));
    check('the cross removes the model from the pool',
        env.selector.selected().length === beforeRemove - 1
        && !env.selector.selected().some((row) => key(row) === FIRST),
        JSON.stringify(env.selector.selected()));
    check('the removal is persisted too',
        !(globalThis.localStorage.getItem('oc.selected_models.v1') || '').includes('"openai/gpt-5.5"'));
    check('the change event fired for the removal as well',
        changeEvents === 2, String(changeEvents));
    check('a freed catalogue slot makes the add row usable again',
        addCombo().trigger.disabled === false, addCombo().trigger.title);

    // ---- "+ add model" reproduces the pointer gesture that used to fail --
    // In a browser, mousedown on an option moves the focus out of the panel;
    // the focusout handler read that as "the row was left" and closed the
    // panel BEFORE the click on the option was delivered, so the model was
    // never added — the window closed and nothing happened. The option now
    // cancels the mousedown default, which is what keeps the panel open under
    // the pointer. Both halves are driven explicitly here.
    const add2 = addCombo();
    add2.trigger.click();
    let searchPrevented = null;
    add2.panel.dispatchEvent({
        type: 'mousedown', target: add2.search,
        preventDefault() { searchPrevented = true; },
    });
    check('a mousedown on the search field is NOT cancelled (the caret still works)',
        searchPrevented === null);
    const optionTarget = add2.optionNodes[0];
    let optionPrevented = false;
    add2.panel.dispatchEvent({
        type: 'mousedown', target: optionTarget,
        preventDefault() { optionPrevented = true; },
    });
    check('a mousedown on an option IS cancelled, so the panel is not closed under the pointer',
        optionPrevented === true);
    const beforePointerAdd = env.selector.selected().length;
    optionTarget.click();
    await new Promise((r) => setImmediate(r));
    check('and the click that follows still adds the model to the pool',
        env.selector.selected().length === beforePointerAdd + 1
        && key(env.selector.selected()[env.selector.selected().length - 1]) === optionTarget.dataset.value,
        JSON.stringify(env.selector.selected()));
    check('the added model is drawn as a pool row (visible in the DOM)',
        rootEl.querySelectorAll('.oc-model-label').map((n) => n.textContent)
            .includes(optionTarget.dataset.value.split('|')[1]),
        rootEl.textContent.slice(0, 120));
}

// =====================================================================console.log('\n== M1: an unreadable settings file is a banner, not a silent default ==');
// =====================================================================
{
    const env = await boot();
    env.apiState.settings = {
        ...MODELS_SETTINGS,
        storage: {
            runtime_path: 'var/config/runtime.json',
            has_runtime: true,
            runtime_error: {
                error: 'config_read_failed',
                state: 'unreadable',
                message: 'The file exists but this process cannot read it.',
                target: 'var/config/runtime.json',
                fs: { file_perms: '0644', file_owner: 'root', process_user: 'www-data' },
            },
        },
    };
    await env.clickTab('models');
    const banner = env.documentRoot.getElementById('oc-models-storage-error');
    check('an unreadable settings file is announced', banner !== null);
    check('the banner says the values below are defaults, not the saved ones',
        banner.textContent.includes('shipped default from config.php'), banner.textContent);
    check('the banner warns that saving would overwrite the stored settings',
        banner.textContent.includes('overwrite your stored settings'));
    check('the banner names the file and the permissions',
        banner.textContent.includes('var/config/runtime.json')
        && banner.textContent.includes('root') && banner.textContent.includes('www-data'),
        banner.textContent);
    check('the banner names the remedy', banner.textContent.includes('ownership/permissions'));
}
{
    const env = await boot();
    env.apiState.settings = MODELS_SETTINGS;
    await env.clickTab('models');
    check('a readable settings file shows no banner',
        env.documentRoot.getElementById('oc-models-storage-error') === null);
}

// =====================================================================
console.log('\n== M5: the actualize card always ends with a verdict ==');
// =====================================================================
{
    const env = await boot();
    await env.clickTab('models');
    const byId = (id) => env.documentRoot.getElementById(id);
    env.apiState.actualize = {
        stage: 'problems', active: false,
        message: 'Actualization failed and nothing was written: the settings file could not be written.',
        failed_stage: 'assigning_presets',
        warnings: [],
        error: {
            error: 'config_write_failed', stage: 'verify', target: 'var/config/runtime.json',
            message: 'The settings file was written but reading it back returned different bytes.',
            fs: { file_perms: '0600', file_owner: 'root', process_user: 'www-data' },
        },
        assigned: {}, selection: [], snapshot: { available: true, taken_at: '2026-09-21T09:00:00Z' },
        worker_available: true,
    };
    await env.clickTab('models');
    const errorBox = byId('oc-actualize-error');
    check('a failed run renders an error block', errorBox !== null);
    check('the error block carries the failure message',
        errorBox.textContent.includes('reading it back returned different bytes'), errorBox.textContent);
    check('the error block names the file', errorBox.textContent.includes('var/config/runtime.json'));
    check('the error block names the stage and the permissions',
        errorBox.textContent.includes('verify') && errorBox.textContent.includes('www-data'));
    check('the run is not painted as three finished stages',
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length !== 3,
        String(byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length));
    check('the row the worker named is the one marked failed',
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-failed')[0].getAttribute('data-stage-key')
            === 'assigning_presets',
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-failed')[0].getAttribute('data-stage-key'));
}
{
    const env = await boot();
    await env.clickTab('models');
    const byId = (id) => env.documentRoot.getElementById(id);
    // Queued, waiting for a worker that is not there: the card has to say so
    // instead of spinning, and it has to say how long it has waited.
    env.apiState.actualize = {
        stage: 'analysing_models', active: true, queued_seconds: 240,
        message: 'Queued and waiting for a worker to pick it up.',
        warnings: [], assigned: {}, worker_available: false, snapshot: { available: false },
    };
    await env.clickTab('models');
    check('a queued job with no worker names the wait',
        byId('oc-actualize-summary').textContent.includes('queued for 240s')
        && byId('oc-actualize-summary').textContent.includes('worker is not running'),
        byId('oc-actualize-summary').textContent);
    check('the in-flight queued state explains itself instead of an empty card',
        byId('oc-actualize-summary').textContent.includes('waiting for a worker'));

    // Idle with nothing to report: no leftover text from the previous state.
    env.apiState.actualize = { stage: 'idle', active: false, warnings: [], assigned: {} };
    await env.clickTab('models');
    check('a fresh card says nothing rather than something stale',
        byId('oc-actualize-summary').textContent.trim() === '',
        byId('oc-actualize-summary').textContent);
    check('and no stage is marked done',
        byId('oc-actualize-card').querySelectorAll('.oc-actualize-stage-done').length === 0);
}

console.log('\n== M4: the JS version parser matches App\\Models\\ModelVersion ==');
{
    // The fixture table is duplicated in tests/ModelsActualizeTest.php (the
    // PHP side), so a change to either implementation breaks one of them.
    const fixtures = [
        ['anthropic/claude-sonnet-4.6', 'claudesonnet', [4, 6]],
        ['gpt-5.5-pro', 'gptpro', [5, 5]],
        ['deepseek-r1', 'deepseekr1', []],
        ['gemini-2.5-flash-lite-preview-09-2025', 'geminiflashlitepreview', [2, 5, 9, 2025]],
        ['~anthropic/claude-sonnet-latest', 'claudesonnetlatest', []],
        // The mode suffix is not a token: same line, same tuple as the plain id.
        ['gpt-4o:batch', 'gpt4o', []],
        ['anthropic/claude-sonnet-4.6:batch', 'claudesonnet', [4, 6]],
    ];
    const helpers = await loadSelectorHelpers();
    for (const [id, line, tuple] of fixtures) {
        const parsed = helpers.modelVersionParse(id);
        check(`parse(${id}) → line ${line}`,
            parsed.line === line && JSON.stringify(parsed.version) === JSON.stringify(tuple),
            JSON.stringify(parsed));
    }
    check('a newer release on the same line is detected',
        helpers.newerVersionOf('gpt-5.4', ['gpt-5.4', 'gpt-5.5']) === 'gpt-5.5');
    check('an older sibling is not reported as newer',
        helpers.newerVersionOf('gpt-5.5', ['gpt-5.4', 'gpt-5.5']) === null);
    check('a different line is never comparable',
        helpers.newerVersionOf('gpt-5.5', ['gpt-5.5-pro', 'claude-sonnet-4.6']) === null);
    check('a version-less alias is never "old"',
        helpers.newerVersionOf('deepseek-r1', ['deepseek-r1', 'deepseek-v4-pro']) === null);
    check('the comparator pads a shorter tuple',
        helpers.modelVersionCompare('claude-sonnet-4.6', 'claude-sonnet-4') === 1);
}

// =====================================================================
console.log('\n== T2: the JS and PHP readers agree on what `:batch` means ==');
// =====================================================================
//
// Same fixture table as tests/ModelBatchTest.php on the PHP side, so the two
// implementations cannot drift: the ids arrive from both directions (the
// provider's catalogue into the browser, the operator's form into PHP).
{
    const helpers = await loadSelectorHelpers();
    const reading = [
        ['gpt-4o:batch', true, 'gpt-4o', 'batch'],
        ['openai/gpt-4o:batch', true, 'openai/gpt-4o', 'batch'],
        ['gpt-4o:BATCH', true, 'gpt-4o', 'batch'],
        ['  gpt-4o:batch  ', true, 'gpt-4o', 'batch'],
        ['gpt-4o :batch', true, 'gpt-4o', 'batch'],
        ['gpt-4o', false, 'gpt-4o', 'sync'],
        ['', false, '', 'sync'],
        // `batch` that is not a trailing mode suffix stays part of the name.
        ['batch-model', false, 'batch-model', 'sync'],
        ['gpt-4o:batch-preview', false, 'gpt-4o:batch-preview', 'sync'],
        ['gpt-4o:batch2', false, 'gpt-4o:batch2', 'sync'],
        [':batch', false, ':batch', 'sync'],
    ];
    for (const [id, isBatch, base, mode] of reading) {
        check(`isBatchId(${JSON.stringify(id)}) → ${isBatch}`,
            helpers.isBatchId(id) === isBatch);
        check(`baseModelId(${JSON.stringify(id)}) → ${base}`,
            helpers.baseModelId(id) === base, helpers.baseModelId(id));
        check(`modelMode(${JSON.stringify(id)}) → ${mode}`,
            helpers.modelMode(id) === mode, helpers.modelMode(id));
    }
    check('the base of a base is the base (idempotent)',
        helpers.baseModelId(helpers.baseModelId('openai/gpt-4o:batch')) === 'openai/gpt-4o');
    check('a batch twin is never NEWER than its base',
        helpers.modelVersionCompare('gpt-4o:batch', 'gpt-4o') === 0
        && helpers.modelVersionLine('gpt-4o:batch') === helpers.modelVersionLine('gpt-4o'));
    check('the rejection text names the id and the reason',
        helpers.batchPoolRejection('gpt-4o:batch').includes('gpt-4o:batch')
        && helpers.batchPoolRejection('gpt-4o:batch').includes('Batch API')
        && helpers.batchPoolRejection('gpt-4o') === '');
}

// =====================================================================
console.log('\n== T2: a batch-only id is marked, and never offered as a pool member ==');
// =====================================================================
{
    const env = await boot();
    const rootEl = env.documentRoot.getElementById('model-selector');
    const BATCH = { provider: 'alpha', model: 'openai/gpt-5.5:batch' };
    const catalog = [
        { provider: 'alpha', model: 'openai/gpt-5.5' },
        { provider: 'alpha', model: 'anthropic/claude-sonnet-4.6' },
        { provider: 'alpha', model: 'google/gemini-3.1-pro-preview' },
        BATCH,
    ];
    const key = (row) => `${row.provider}|${row.model}`;
    const combos = () => env.selector.comboboxes;
    const renderedKeys = (combo) => combo.list
        .querySelectorAll('.oc-model-option')
        .map((li) => li.dataset.value);

    // The pool seeded from the catalogue never picks the batch id.
    env.selector.setCatalog(catalog, []);
    check('the diverse seed never fills the pool with a batch id',
        !env.selector.selected().some((row) => row.model.includes(':batch')),
        JSON.stringify(env.selector.selected()));

    // A stored selection that already had one: visible, marked, swappable.
    env.selector.setCatalog(catalog, [BATCH]);
    const first = combos()[0];
    check('a batch row in the pool is still shown (not silently dropped)',
        env.selector.selected().length === 1
        && key(env.selector.selected()[0]) === key(BATCH),
        JSON.stringify(env.selector.selected()));
    check('its closed row carries the batch badge',
        first.trigger.querySelectorAll('.oc-model-batch-badge').length === 1
        && first.trigger.textContent.includes('batch'),
        first.trigger.textContent);
    check('the badge explains why, and what to do',
        first.trigger.getAttribute('title').includes('Batch API')
        && first.trigger.getAttribute('title').includes('Swap it'),
        first.trigger.getAttribute('title'));

    // The popup: the batch option is listed, marked, and not pickable.
    first.trigger.click();
    const batchIndex = first.options.findIndex((row) => key(row) === key(BATCH));
    const batchNode = first.optionNodes[batchIndex];
    check('the batch id is listed in the popup, marked as batch',
        batchIndex >= 0
        && batchNode.classList.contains('oc-model-option-batch')
        && batchNode.querySelectorAll('.oc-model-batch-badge').length === 1,
        String(batchIndex));
    check('it is not selectable (aria-disabled, not aria-hidden)',
        batchNode.getAttribute('aria-disabled') === 'true'
        && batchNode.getAttribute('role') === 'option');
    check('its tooltip carries the reason, not just the label',
        batchNode.getAttribute('title').includes('Batch API'),
        batchNode.getAttribute('title'));

    const beforePick = JSON.stringify(env.selector.selected());
    batchNode.click();
    check('clicking it changes nothing and keeps the panel open',
        JSON.stringify(env.selector.selected()) === beforePick && first.isOpen === true,
        JSON.stringify(env.selector.selected()));
    // Arrow keys walk the list and never land on an option that cannot be
    // picked; from every starting point, a full lap misses it.
    first.setHighlight(0);
    const visited = new Set();
    for (let step = 0; step < first.optionNodes.length; step++) {
        first.move(1);
        visited.add(first.highlight);
    }
    check('the arrow keys never highlight an unpickable option',
        !visited.has(batchIndex), [...visited].join(','));
    check('Enter on the batch option is a no-op',
        (first.setHighlight(batchIndex), first.commitActive()) === false);
    first.close();

    // A pool WITHOUT the batch id, so the add popup has to offer it (and
    // mark it): the pool and the provider's catalogue disagree otherwise.
    env.selector.setCatalog(catalog, [{ provider: 'alpha', model: 'openai/gpt-5.5' }]);
    const add = combos()[combos().length - 1];
    check('neither popup can be left showing a batch id as an ordinary option',
        renderedKeys(add).includes(key(BATCH))
        && add.optionNodes[renderedKeys(add).indexOf(key(BATCH))]
            .getAttribute('aria-disabled') === 'true',
        renderedKeys(add).join(','));
    const addable = add.options.find((row) => key(row) !== key(BATCH));
    const addableIndex = add.options.findIndex((row) => key(row) === key(addable));
    add.trigger.click();
    add.optionNodes[addableIndex].click();
    await new Promise((r) => setImmediate(r));
    check('a synchronous model can still be added from it',
        env.selector.selected().length === 2
        && key(env.selector.selected()[1]) === key(addable)
        && !env.selector.selected().some((row) => row.model.includes(':batch')),
        JSON.stringify(env.selector.selected()));
}

// ---- the same marking on the Models tab's table ---------------------
{
    const env = await boot();
    env.apiState.settings = {
        providers: {
            openrouter: { active: true, base_url: '', api_key: '', has_key: false, source: 'none' },
            bothub: { active: true, base_url: '', api_key: '', has_key: false, source: 'none' },
        },
        models: {
            list: [
                { provider: 'bothub', model: 'gpt-5.6-sol', active: true },
                { provider: 'bothub', model: 'gpt-5.6-sol:batch', active: false },
            ],
            default_selected: [],
        },
        recommended_models: [],
    };
    await env.clickTab('models');
    const rows = env.documentRoot.querySelectorAll('#oc-models-card tbody tr');
    check('both catalogue rows are rendered', rows.length === 2, String(rows.length));
    const batchRow = rows.find((r) => r.dataset.model === 'gpt-5.6-sol:batch');
    const plainRow = rows.find((r) => r.dataset.model === 'gpt-5.6-sol');
    check('the batch row is marked in the table',
        batchRow !== undefined && batchRow.dataset.batchOnly === 'true'
        && batchRow.querySelectorAll('.oc-model-batch-badge').length === 1,
        batchRow === undefined ? 'no row' : batchRow.textContent);
    check('its checkbox is disabled so it cannot be switched on',
        batchRow.querySelectorAll('input')[0].disabled === true);
    check('the reason travels with the row, not only with the badge',
        batchRow.getAttribute('title').includes('Batch API')
        && batchRow.querySelectorAll('input')[0].getAttribute('title').includes('Batch API'),
        batchRow.getAttribute('title'));
    check('the synchronous row is untouched',
        plainRow.dataset.batchOnly === undefined
        && plainRow.querySelectorAll('.oc-model-batch-badge').length === 0
        && plainRow.querySelectorAll('input')[0].disabled === false);

    // Even a hand-edited row (checkbox forced on in the DOM) is saved off.
    batchRow.querySelectorAll('input')[0].checked = true;
    env.apiState.modelsAfterSave = env.apiState.settings.models.list;
    buttonByText(env, 'oc-models-card', 'Save models').click();
    await env.flush();
    const posted = JSON.parse(
        env.fetchLog.filter((f) => f.path === '/api/settings/models' && f.method === 'POST')[0].body,
    );
    check('the save never sends a batch id as active',
        posted.models.every((m) => !m.model.endsWith(':batch') || m.active === false),
        JSON.stringify(posted.models));
}

console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
