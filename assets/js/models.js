// models.js — the Models tab (v1.8.51, split out of Settings).
//
// What lives here: everything that decides *which* models run.
//
//   1. Providers / API keys — connector activation, base URL, key.
//   2. Models — the persisted catalogue (var/config/runtime.json).
//   3. Domain models — per-domain consensus presets (the "consensus
//      type" the analyzer auto-routes to).
//   4. Request analyzer — the classifier model and its probe.
//   5. Actualize — reconcile the linked models with what the providers
//      actually serve, in the worker (see ModelsActualizer).
//
// The tab is admin-gated exactly like Settings: the tab BUTTON tracks
// the ModelsTab config flag alone (so the affordance is visible before a
// token exists), while the BODY renders the shared lock screen from
// admin-ui.js. The admin token itself only ever lives in the HttpOnly
// `oc_admin` cookie, so unlocking here unlocks Settings too — there is no
// second unlock mechanism and no client-side token to keep in sync.

import {
    el, clear, api, probeAdminStatus, renderDisabledNotice, renderErrorNotice,
    createLockScreen, createSectionNav, adminProtected, renderProtectionNotice,
} from './admin-ui.js';
import {
    pickDiverseModels, applyPresetToSelection, createModelPicker,
    normaliseModelFilter, modelMatchesFilter, newerVersionOf,
    isBatchId, batchBadgeNode, batchPoolRejection,
} from './model-selector.js';

const TABS = document.querySelectorAll('[data-tab]');
const PANELS = document.querySelectorAll('[data-tab-panel]');
const ROOT = document.getElementById('oc-models-root');
const MODELS_TAB = document.getElementById('oc-tab-models');

/**
 * Debounce for the Models-tab filter box, matching the sidebar selector's
 * window (assets/js/model-selector.js, FILTER_DEBOUNCE_MS).
 */
const FILTER_DEBOUNCE_MS = 200;

// ===== Save reporting ==================================================
//
// Every Save button in this tab goes through these two helpers. The rule
// they enforce is the whole point of the fix they exist for: a button may
// only say "Saved." when the server confirmed the bytes are on disk.
//
// "Confirmed" means all three of:
//   1. the HTTP call succeeded (`res.ok`),
//   2. the body carries no `error` and is not `ok: false`,
//   3. when the server echoed `persisted`, it agrees with what was sent.
//
// The old code checked (1) alone — and on a host where the settings file
// could not be replaced, the API answered `{"ok":true}` for a write that
// never happened, so the operator read "Saved." and then watched the list
// revert on the next page load.

/**
 * @param {HTMLElement} status Where the verdict is written.
 * @param {{ok:boolean,status:number,body:object}} res
 * @param {string} okText Text to show on a confirmed save.
 * @returns {boolean} true when the save is confirmed.
 */
function reportSave(status, res, okText = 'Saved.') {
    if (!status) return false;
    const body = res && res.body && typeof res.body === 'object' ? res.body : {};
    const failed = !res || res.ok !== true || body.error !== undefined || body.ok === false;
    if (failed) {
        status.textContent = describeSaveFailure(res);
        return false;
    }
    // The write landed in the config file, but the server could not read it
    // back — reported rather than glossed over, because the value will be
    // gone on the next request.
    if (body.persisted && body.persisted.ok === false) {
        status.textContent = `Saved, but the settings file could not be read back: ${
            (body.persisted.error && body.persisted.error.message) || body.persisted.state || 'unknown error'}.`;
        return false;
    }
    status.textContent = okText;
    return true;
}

/** Human-readable failure text, including the server's filesystem detail. */
function describeSaveFailure(res) {
    const body = res && res.body && typeof res.body === 'object' ? res.body : {};
    const message = body.message || body.error || `Failed (${res && res.status ? res.status : 'no response'})`;
    const details = body.details && typeof body.details === 'object' ? body.details : {};
    const fs = details.fs && typeof details.fs === 'object' ? details.fs : {};
    const bits = [];
    if (body.target || details.target) bits.push(`file: ${body.target || details.target}`);
    const stage = body.stage || details.stage;
    if (stage) bits.push(`stage: ${stage}`);
    if (fs.file_perms || fs.file_owner || fs.process_user) {
        bits.push(`mode ${fs.file_perms || 'n/a'} / owner ${fs.file_owner || 'n/a'} / php user ${
            fs.process_user || 'n/a'}`);
    }
    if (details.hint) bits.push(details.hint);
    return bits.length > 0 ? `${message} [${bits.join(' — ')}]` : message;
}

/**
 * Read a value out of the `persisted` echo (what the settings file on disk
 * contains right now), by dot-notation path. Returns undefined when the
 * server did not echo anything — callers treat that as "cannot verify".
 */
function persistedValue(body, path) {
    let cursor = body && body.persisted ? body.persisted.data : undefined;
    for (const part of String(path).split('.')) {
        if (!cursor || typeof cursor !== 'object') return undefined;
        cursor = cursor[part];
    }
    return cursor;
}

/** Section strip registry — order mirrors renderModels(). */
const MODELS_SECTIONS = [
    { id: 'oc-actualize-card', title: 'Actualize' },
    { id: 'oc-providers-card', title: 'Providers' },
    { id: 'oc-models-card',    title: 'Models' },
    { id: 'oc-domain-models',  title: 'Domain models' },
    { id: 'oc-analyzer-card',  title: 'Analyzer' },
    { id: 'oc-skill-drafts-card', title: 'Skills' },
    { id: 'oc-auto-selector-card', title: 'Auto selector' },
    { id: 'oc-research-card', title: 'Research' },
];

const modelsNav = createSectionNav({
    nav: document.getElementById('oc-models-nav'),
    band: 0.45,
    sections: MODELS_SECTIONS,
});

let state = {
    status: { tab_enabled: true, admin_configured: false, admin_unlocked: false },
    settings: null,
    lastImport: null, // { provider, catalog, popular }
};

// Per-provider catalog ({provider => [{model,label,active}]}) for the
// Domain Models "Apply" toolbar, populated by hydrateDomainPresets().
let domainCatalog = {};

function activateTab(name) {
    TABS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
    if (name !== 'models') {
        actualizeStopRefresh();
        modelsNav.stop();
    }
    if (name === 'models') void loadAndRender();
}

TABS.forEach(t => {
    t.addEventListener('click', () => activateTab(t.dataset.tab));
});

async function probeStatus() {
    state.status = await probeAdminStatus('/api/models/status');
    // The tab button tracks the config flag alone — an operator must be
    // able to reach the lock screen before an admin token exists.
    MODELS_TAB.hidden = state.status.tab_enabled === false;
}

async function loadAndRender() {
    // v1.8.60 — `admin_configured` alone is not the question. With
    // `Protect_Settings_by_Admin_Key = 0` an install with no `.admin.key` is
    // healthy and the panel must render; only a *protected* install that has
    // no token to check against is the broken case this notice is for.
    if (adminProtected(state.status) && !state.status.admin_configured) {
        modelsNav.stop();
        renderDisabledNotice(ROOT, { flag: 'ModelsTab', feature: 'Model settings' });
        return;
    }
    if (!state.status.admin_unlocked) {
        modelsNav.stop();
        createLockScreen(ROOT, {
            title: 'Admin authentication required',
            description: 'The Models tab manages provider keys, the model catalogue and the consensus presets.',
            onState: (unlocked) => { state.status.admin_unlocked = unlocked; },
            onUnlocked: () => loadAndRender(),
        });
        return;
    }
    const { ok, body, status } = await api('/api/settings');
    if (status === 401) {
        state.status.admin_unlocked = false;
        createLockScreen(ROOT, {
            title: 'Admin authentication required',
            description: 'The Models tab manages provider keys, the model catalogue and the consensus presets.',
            onState: (unlocked) => { state.status.admin_unlocked = unlocked; },
            onUnlocked: () => loadAndRender(),
        });
        return;
    }
    if (!ok) {
        modelsNav.stop();
        renderErrorNotice(ROOT, body?.message || 'Failed to load model settings');
        return;
    }
    state.settings = body;
    renderModels();
    // v1.8.60 — the page-level strip is server-rendered; this keeps the
    // disclosure attached to the panel across a re-render.
    renderProtectionNotice(ROOT, state.status);
}

function renderModels() {
    // Same guard Settings uses: a deployment whose template has no Models
    // panel yet must render nothing rather than throw inside an async
    // load path (an unhandled rejection nobody sees).
    if (!ROOT) return;
    clear(ROOT);
    ROOT.append(
        el('h1', { text: 'Models' }),
        el('div', { class: 'oc-alert oc-alert-warn' },
            'API keys are stored server-side. They are masked in this UI and never logged. ',
            'Treat the admin token like a root credential: rotate it if leaked.'),
    );
    // A settings file the PHP process cannot read means every value below is
    // the shipped default from config.php, not what the operator saved. That
    // is worth a banner of its own: without it the tab calmly shows the
    // initial settings, which is exactly how "my models were reset" starts.
    const storageError = state.settings && state.settings.storage
        ? state.settings.storage.runtime_error
        : null;
    if (storageError && typeof storageError === 'object') {
        const fs = storageError.fs && typeof storageError.fs === 'object' ? storageError.fs : {};
        const facts = [];
        if (fs.file_perms || fs.file_owner) {
            facts.push(`mode ${fs.file_perms || 'n/a'} / owner ${fs.file_owner || 'n/a'}`);
        }
        if (fs.process_user) facts.push(`php user ${fs.process_user}`);
        ROOT.append(el('div', { class: 'oc-alert oc-alert-error', id: 'oc-models-storage-error' },
            `The saved settings could not be read (${String(storageError.target || 'var/config/runtime.json')}: ${
                String(storageError.state || 'unreadable')}).`,
            ' Everything below is the shipped default from config.php, not what you saved — saving now would '
            + 'overwrite your stored settings.',
            facts.length > 0 ? ` Filesystem: ${facts.join(', ')}.` : '',
            ' Fix the ownership/permissions of that file, then reload.'));
    }
    ROOT.append(
        sectionUsefulness(),
        sectionActualize(),
        sectionProviders(),
        sectionModels(),
        sectionDomainModels(),
        sectionAnalyzer(),
        sectionSkillDrafts(),
        sectionAutoSelector(),
        sectionResearch(),
    );
    // Lazy-load the preset / analyzer data after the DOM is mounted;
    // failures surface inline so the rest of the tab keeps working.
    void hydrateDomainPresets();
    void hydrateAnalyzer();
    // The Skills card loads its own settings; it must not hold up the tab.
    void hydrateSkillDrafts();
    void hydrateAutoSelector();
    // The Research card is independent; it loads on its own too.
    void hydrateResearch();
    void hydrateActualizeSettings();
    void refreshActualizeStatus();
    // The actualize card polls while it is on screen (its stages advance
    // in the worker, not here).
    const actualizeCard = document.getElementById('oc-actualize-card');
    if (actualizeCard) actualizeStartRefresh(actualizeCard);
    // Build the section strip once the cards exist.
    modelsNav.refresh();
}

// ===== Actualize ======================================================
//
// Reconciling the linked models with what the providers actually serve
// means a live catalogue fetch plus several analyzer-model calls, so it
// runs as a worker job (payload.kind = 'models_actualize') instead of
// inside the HTTP request. The card below is a view over
// GET /api/models/actualize/status: it renders the three stages the
// worker publishes as job events and polls only while the tab is on
// screen.
const ACTUALIZE_REFRESH_MS = 3000;

/** The words the card shows between "pressed" and "the worker started". */
const ACTUALIZE_QUEUED_TEXT = 'Queued — waiting for the worker to pick it up';

/**
 * The stages the worker publishes, in order, with their UI labels.
 *
 * v1.8.53 — the import is first because that is what the worker does first: the
 * catalogue every later stage reasons about comes from it, so a row for it
 * has to exist — a run that died in the import would otherwise look like a
 * run that never started.
 */
const ACTUALIZE_STAGES = [
    { key: 'importing_models',  label: 'Import models' },
    { key: 'analysing_models',  label: 'Analysing models' },
    { key: 'assigning_presets', label: 'Assigning models to consensuses' },
    { key: 'finish',            label: 'Result' },
];

let actualizeCtl = {
    timer: null,
    observer: null,
    card: null,
    visible: false,
    busy: false,
    /**
     * The run this card is waiting for after the operator pressed the
     * button (v1.8.51). A verdict on screen belongs to ONE job, and until
     * the worker publishes that job's first stage the status endpoint
     * still answers with the previous run — which used to be painted as
     * this run's result. Cleared as soon as a snapshot carrying this
     * run's identity arrives.
     *
     * `jobId` is the id the enqueue answered with (null when the answer
     * carried none); `supersededJobId` is then the id the last snapshot
     * named, so "a different run" is still checkable.
     *
     * @type {{jobId: number|null, supersededJobId: number|null}|null}
     */
    pendingRun: null,
    /** The job id of the last snapshot that was rendered, for the gate. */
    lastJobId: null,
    /** The section's own settings snapshot (`/api/models/actualize/settings`). */
    settings: null,
    /** The single-model picker for the section's analyzer, if mounted. */
    picker: null,
};

function actualizeStopRefresh() {
    if (actualizeCtl.timer) { clearInterval(actualizeCtl.timer); actualizeCtl.timer = null; }
    if (actualizeCtl.observer) { actualizeCtl.observer.disconnect(); actualizeCtl.observer = null; }
    actualizeCtl.visible = false;
}

function actualizeStartRefresh(card) {
    actualizeCtl.card = card;
    const panel = document.querySelector('[data-tab-panel="models"]');
    const onScreen = () => {
        if (panel && panel.hidden) return false;
        return actualizeCtl.visible;
    };
    if (typeof IntersectionObserver === 'function') {
        actualizeCtl.observer = new IntersectionObserver((entries) => {
            for (const entry of entries) actualizeCtl.visible = entry.isIntersecting;
        }, { threshold: 0 });
        actualizeCtl.observer.observe(card);
    } else {
        // No observer in this environment: fall back to "tab visible".
        actualizeCtl.visible = true;
    }
    if (actualizeCtl.timer) clearInterval(actualizeCtl.timer);
    actualizeCtl.timer = setInterval(() => {
        // Poll only while the card is on screen and the tab is not hidden —
        // a background tab must not keep asking the worker for status, and
        // a request already in flight must not be doubled.
        if (actualizeCtl.busy || !onScreen()) return;
        void refreshActualizeStatus();
    }, ACTUALIZE_REFRESH_MS);
}

/**
 * R9 extension - what the recorded runs say about each model.
 *
 * Two facts, kept visibly apart: the share of each answer the other
 * participants corroborated (averaged per round), and the calls a model did
 * not answer. The second is a transport or provider fact, so it is never
 * folded into a negative contribution - the column is there to be read, not to
 * be summed with the others.
 */
function sectionUsefulness() {
    const status = el('p', { class: 'oc-muted oc-small', text: 'Loading…' });
    const body = el('div', { class: 'oc-models-usefulness' });
    const card = el('section', { class: 'oc-card', id: 'oc-models-usefulness' }, [
        el('h2', { text: 'Model usefulness' }),
        el('p', {
            class: 'oc-muted oc-small',
            text: 'Share = the part of each answer the other participants corroborated, averaged per round. '
                + 'Calls a model did not answer are counted separately and never as a negative contribution.',
        }),
        status,
        body,
    ]);

    // The harnesses render this tab without a network stack; a missing fetch is
    // simply "not measured here" rather than a broken tab.
    if (typeof fetch !== 'function') {
        status.textContent = 'Not available in this environment.';

        return card;
    }

    void fetch('/api/models/usefulness', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((response) => (response.ok ? response.json() : null))
        .then((data) => {
            status.remove();
            if (!data || data.ok !== true) {
                body.append(el('p', { class: 'oc-muted oc-small', text: 'Unavailable right now.' }));

                return;
            }
            // Metrics first: how the model behaves as a worker (R9), separate
            // from what it contributed to decisions. Two tables on purpose - a
            // fast cheap model that is rarely corroborated and a slow expensive
            // one that usually is are different findings.
            const metrics = Array.isArray(data.metrics) ? data.metrics : [];
            if (metrics.length > 0) {
                body.append(el('h3', { text: 'Latency, price and cache' }));
                body.append(el('table', { class: 'oc-table oc-small' }, [
                    el('thead', {}, [el('tr', {}, [
                        el('th', { text: 'Model' }),
                        el('th', { text: 'Calls' }),
                        el('th', { text: 'Median' }),
                        el('th', { text: 'p95' }),
                        el('th', { text: 'Tokens/call' }),
                        el('th', { text: 'Cost / 1k' }),
                        el('th', { text: 'Cache hits' }),
                    ])]),
                    el('tbody', {}, metrics.map((row) => {
                        const cost = row.cost_per_1k === null || row.cost_per_1k === undefined
                            ? 'not reported'
                            : String(row.cost_per_1k);
                        const hits = row.cache_hit_rate === null || row.cache_hit_rate === undefined
                            ? 'not measured'
                            : Math.round(Number(row.cache_hit_rate) * 100) + '%';

                        return el('tr', {}, [
                            el('td', { class: 'oc-mono', text: String(row.model ?? '') }),
                            el('td', { text: String(row.calls ?? 0) }),
                            el('td', { text: String(row.median_ms ?? 0) + ' ms' }),
                            el('td', { text: String(row.p95_ms ?? 0) + ' ms' }),
                            el('td', { text: String(row.avg_tokens ?? 0) }),
                            el('td', { text: cost }),
                            el('td', { text: hits }),
                        ]);
                    })),
                ]));
            }

            const models = Array.isArray(data.models) ? data.models : [];
            if (models.length === 0) {
                body.append(el('p', { class: 'oc-muted oc-small', text: 'No measured rounds recorded yet.' }));

                return;
            }
            body.append(el('table', { class: 'oc-table oc-small' }, [
                el('thead', {}, [el('tr', {}, [
                    el('th', { text: 'Model' }),
                    el('th', { text: 'Rounds' }),
                    el('th', { text: 'Avg share' }),
                    el('th', { text: 'Strong' }),
                    el('th', { text: 'Weak' }),
                    el('th', { text: 'Not answered' }),
                ])]),
                el('tbody', {}, models.map((row) => el('tr', {
                    title: typeof row.note === 'string' ? row.note : '',
                }, [
                    el('td', { class: 'oc-mono', text: String(row.model ?? '') }),
                    el('td', { text: String(row.runs ?? 0) }),
                    el('td', { text: Math.round((Number(row.avg_share) || 0) * 100) + '%' }),
                    el('td', { text: String(row.strongly ?? 0) }),
                    el('td', { text: String(row.weakly ?? 0) }),
                    el('td', { text: String(row.non_answers ?? 0) }),
                ]))),
            ]));
        })
        .catch(() => { status.textContent = 'Unavailable right now.'; });

    return card;
}

function sectionActualize() {
    const status = el('span', { class: 'oc-status', id: 'oc-actualize-action-status' });
    const button = el('button', {
        class: 'oc-button-primary',
        type: 'button',
        id: 'oc-actualize-btn',
        onClick: () => void runActualize(),
    }, 'Actualize linked models');
    const rollbackBtn = el('button', {
        class: 'oc-button-secondary',
        type: 'button',
        id: 'oc-actualize-rollback-btn',
        hidden: true,
        onClick: () => void rollbackActualize(),
    }, 'Roll back to the last snapshot');
    const prog = el('ul', { class: 'oc-actualize-stages', id: 'oc-actualize-stages' },
        ACTUALIZE_STAGES.map(stage => el('li', {
            class: 'oc-actualize-stage',
            'data-stage-key': stage.key,
        }, el('span', { class: 'oc-actualize-stage-dot' }), el('span', { text: stage.label }))));
    const summary = el('div', { class: 'oc-actualize-summary', id: 'oc-actualize-summary' });

    // v1.8.53 — the run's own controls: which catalogue it imports from and
    // which model answers its assignment calls. Built here, hydrated from
    // the server by hydrateActualizeSettings().
    //
    // The catalogue the run's first stage imports from. Options are built by
    // renderActualizeSourceOptions() from the server's own list, so the
    // selector can never offer a source the worker would refuse.
    const sourceSelect = el('select', {
        id: 'oc-actualize-import-source',
        'aria-label': 'Catalogue to import before the run',
    });

    // The section's own analyzer model. The picker is the same combobox the
    // Chat sidebar's pool rows use (see createModelPicker) — one
    // implementation of "choose a model", not a third.
    const pickerHost = el('div', { class: 'oc-model-picker-host', id: 'oc-actualize-analyzer-picker' });
    const analyzerStatus = el('div', {
        class: 'oc-muted oc-small',
        id: 'oc-actualize-analyzer-status',
        text: 'Loading the model in effect…',
    });
    const resetAnalyzer = el('button', {
        class: 'oc-button-secondary',
        type: 'button',
        id: 'oc-actualize-analyzer-reset',
        hidden: true,
        onClick: () => void saveActualizeAnalyzer(null),
    }, 'Use the analyzer default');

    return el('div', { class: 'oc-card', id: 'oc-actualize-card' },
        el('h2', { text: 'Actualize linked models' }),
        el('p', { class: 'oc-muted oc-small' },
            'Import the model catalogue from the chosen provider, fetch what every active provider really serves, ',
            'pick the strongest models per domain, and write the result to the consensus presets, the catalogue ',
            'and your current pool. A snapshot is taken first, so the whole run can be rolled back. Runs in the ',
            'worker — no pipeline models are called.'),
        el('div', { class: 'oc-field-row oc-actualize-controls' },
            el('div', { class: 'oc-field' },
                el('label', { for: 'oc-actualize-import-source', text: 'Import catalogue from' }),
                sourceSelect),
            el('div', { class: 'oc-field' },
                el('label', { text: 'Model for this section' }),
                pickerHost,
                el('div', { class: 'oc-actions' }, resetAnalyzer)),
        ),
        analyzerStatus,
        el('div', { class: 'oc-actions' }, button, rollbackBtn, status),
        prog,
        summary,
    );
}

/**
 * Fetch the section's own settings and paint the source selector and the
 * analyzer picker. Never throws: a failed load leaves the card usable with
 * the server's defaults rather than blank.
 */
async function hydrateActualizeSettings() {
    const select = document.getElementById('oc-actualize-import-source');
    const host = document.getElementById('oc-actualize-analyzer-picker');
    const status = document.getElementById('oc-actualize-analyzer-status');
    if (!select && !host) return;
    let body = null;
    try {
        const res = await api('/api/models/actualize/settings');
        if (res.ok) body = res.body;
    } catch {
        // The explanation below is the same either way.
    }
    actualizeCtl.settings = body && typeof body === 'object' ? body : null;
    renderActualizeSourceOptions(select, actualizeCtl.settings);
    renderActualizeAnalyzer(host, status);
}

/**
 * The import sources, from the server's list.
 *
 * The list and the default are the server's answers (`import_sources`,
 * `default_source`), not a hard-coded copy: the selector must not be able
 * to offer a source the worker does not accept, and the value it starts on
 * must be the one the worker would have used anyway.
 */
function renderActualizeSourceOptions(select, settings) {
    if (!select) return;
    const sources = settings && Array.isArray(settings.import_sources) && settings.import_sources.length > 0
        ? settings.import_sources
        : ['bothub', 'openrouter'];
    const fallback = settings && typeof settings.default_source === 'string' ? settings.default_source : sources[0];
    // Keep whatever the operator already chose in this session: a status
    // poll must not silently reset their selection.
    const chosen = select.value;
    clear(select);
    for (const source of sources) {
        select.append(el('option', {
            value: source,
            text: source === fallback ? `${source} (default)` : source,
        }));
    }
    select.value = sources.includes(chosen) ? chosen : (sources.includes(fallback) ? fallback : sources[0]);
}

/**
 * The analyzer picker plus the sentence that says which value is in effect.
 *
 * Two facts are always shown, because a single field can express only one:
 * the value the run will call, and WHERE it came from. Without the second,
 * "I picked a model here" and "the analyzer's model is being used" look
 * identical — which is exactly the confusion the separate key
 * (`models.actualize.analyzer`) exists to avoid.
 */
function renderActualizeAnalyzer(host, status) {
    if (!host) return;
    const settings = actualizeCtl.settings;
    const effective = settings && settings.effective ? settings.effective : null;
    const catalogue = (state.settings && state.settings.models && Array.isArray(state.settings.models.list))
        ? state.settings.models.list
        : [];
    const inherited = settings && settings.inherited ? settings.inherited : null;
    const inheritedKey = settings && typeof settings.inherited_key === 'string'
        ? settings.inherited_key
        : 'analyzer.provider / analyzer.model';
    const sectionKey = settings && typeof settings.source_key === 'string'
        ? settings.source_key
        : 'models.actualize.analyzer';

    if (!effective) {
        clear(host);
        host.append(el('div', {
            class: 'oc-alert oc-alert-warn oc-small',
            text: 'The section settings could not be loaded, so the model in effect is unknown. Reload the tab.',
        }));
        if (status) status.textContent = '';
        return;
    }

    if (!actualizeCtl.picker) {
        actualizeCtl.picker = createModelPicker({
            catalog: catalogue,
            value: effective,
            ariaLabel: 'Model that answers this section\'s assignment calls',
            searchLabel: 'Search the catalogue',
            title: `${effective.provider} / ${effective.model}`,
            emptyText: 'The catalogue is empty — import or add models in the Models section first.',
            onPick: (row) => void saveActualizeAnalyzer(row),
        });
        clear(host);
        host.append(actualizeCtl.picker.node);
    } else {
        actualizeCtl.picker.setCatalog(catalogue);
        actualizeCtl.picker.setValue(effective);
    }

    if (!status) return;
    clear(status);
    const inheritedNow = settings.source === 'inherited';
    status.append(
        el('span', { text: 'In effect: ' }),
        el('span', { class: 'oc-mono', text: `${effective.provider} / ${effective.model}` }),
        el('span', {
            text: inheritedNow
                ? ` — inherited from ${inheritedKey}, because this section has no model of its own.`
                : ` — set for this section in ${sectionKey}.`,
        }),
    );
    if (inherited && inheritedNow) {
        status.append(el('span', {
            class: 'oc-muted',
            text: ` Change ${inheritedKey} to move it, or pick a model here to give the section its own.`,
        }));
    }
    if (settings.note) {
        status.append(el('div', { class: 'oc-alert oc-alert-warn oc-small', text: String(settings.note) }));
    }
    const resetBtn = document.getElementById('oc-actualize-analyzer-reset');
    if (resetBtn) resetBtn.hidden = inheritedNow;
}

/**
 * Save (or clear) the section's analyzer model.
 *
 * @param {{provider:string,model:string}|null} row null clears the key, so
 *        `analyzer.provider` / `analyzer.model` apply again.
 */
/**
 * The Skills card: the role generator's model, and the draft flow.
 *
 * A skill is the role set and the system prompts a council runs with. The
 * generator can have a model of its own - drafting roles is a different job
 * from writing the final answer, and an operator may want a cheaper or a
 * stronger model for it - with the synthesis model as the inherited fallback.
 *
 * The draft flow is deliberately two-step: the draft endpoint writes nothing,
 * and "Keep this skill" sends the reviewed definition back. Nothing reaches the
 * skill list without that second click.
 */
const skillDraftCtl = { settings: null, picker: null, definition: null };

function sectionSkillDrafts() {
    const pickerHost = el('div', { class: 'oc-model-picker-host', id: 'oc-skill-generator-picker' });
    const generatorStatus = el('div', {
        class: 'oc-muted oc-small',
        id: 'oc-skill-generator-status',
        text: 'Loading the model in effect.',
    });
    const resetGenerator = el('button', {
        class: 'oc-button-secondary',
        type: 'button',
        id: 'oc-skill-generator-reset',
        hidden: true,
        onClick: () => void saveSkillGenerator(null),
    }, 'Use the synthesis model');

    const query = el('textarea', {
        id: 'oc-skill-draft-query',
        rows: 3,
        placeholder: 'Describe the task the skill should handle, for example: compare two coastal flood defence options',
    });
    const draftButton = el('button', {
        class: 'oc-button-primary',
        type: 'button',
        id: 'oc-skill-draft-run',
        onClick: () => void runSkillDraft(),
    }, 'Draft a skill');
    const keepButton = el('button', {
        class: 'oc-button-secondary',
        type: 'button',
        id: 'oc-skill-draft-keep',
        hidden: true,
        onClick: () => void keepSkillDraft(),
    }, 'Keep this skill');
    const draftStatus = el('div', {
        class: 'oc-muted oc-small',
        id: 'oc-skill-draft-status',
        role: 'status',
        'aria-live': 'polite',
    });
    const preview = el('div', { id: 'oc-skill-draft-preview' });

    return el('div', { class: 'oc-card', id: 'oc-skill-drafts-card' },
        el('h2', { text: 'Skills' }),
        el('p', { class: 'oc-muted oc-small' },
            'A skill is the role set and the system prompts a council runs with. Describe a task, review the draft, ',
            'and keep it only if you want it in the list - the draft itself writes nothing.'),
        el('div', { class: 'oc-field' },
            el('label', { text: 'Model that drafts roles' }),
            pickerHost,
            el('div', { class: 'oc-actions' }, resetGenerator)),
        generatorStatus,
        el('div', { class: 'oc-field' },
            el('label', { for: 'oc-skill-draft-query', text: 'Task to design a skill for' }),
            query),
        el('div', { class: 'oc-actions' }, draftButton, keepButton),
        draftStatus,
        preview,
    );
}

/**
 * The Automatic Skill Selector card: the model and the budget behind the
 * first entry of the consensus skill list.
 *
 * The decision model reads the task against the whole skill catalogue and
 * either picks a skill (with the role that should lead it) or asks for a new
 * one; building a new skill means searching arXiv and letting the role
 * generator draft it. The decision model has its own setting and - when
 * unset - inherits the role generator's, exactly like the generator inherits
 * the synthesis model.
 */
const autoSelectorCtl = { settings: null, picker: null, saving: false };

function sectionAutoSelector() {
    const pickerHost = el('div', { class: 'oc-model-picker-host', id: 'oc-auto-selector-picker' });
    const pickerStatus = el('div', {
        class: 'oc-muted oc-small',
        id: 'oc-auto-selector-status',
        text: 'Loading the model in effect.',
    });
    const resetDecision = el('button', {
        class: 'oc-button-secondary',
        type: 'button',
        id: 'oc-auto-selector-reset',
        hidden: true,
        onClick: () => void saveAutoSelectorDecision(null),
    }, 'Inherit the role generator model');

    const papers = el('input', {
        type: 'number',
        id: 'oc-auto-selector-papers',
        min: '1',
        max: '10',
        step: '1',
        value: '5',
    });
    const autoCreate = el('input', { type: 'checkbox', id: 'oc-auto-selector-create' });
    const fallback = el('input', {
        type: 'text',
        id: 'oc-auto-selector-fallback',
        placeholder: 'consensus-reasoning',
        autocomplete: 'off',
        spellcheck: 'false',
    });
    const saveBtn = el('button', {
        class: 'oc-button-primary',
        type: 'button',
        id: 'oc-auto-selector-save',
        onClick: () => void saveAutoSelectorFields(),
    }, 'Save');
    const saveStatus = el('div', {
        class: 'oc-muted oc-small',
        id: 'oc-auto-selector-save-status',
        role: 'status',
        'aria-live': 'polite',
    });

    return el('div', { class: 'oc-card', id: 'oc-auto-selector-card' },
        el('h2', { text: 'Automatic Skill Selector' }),
        el('p', { class: 'oc-muted oc-small' },
            'The first entry of the consensus skill list. Before a run it picks the best skill for the task; ',
            'when nothing fits or the topic is narrow, it reads arXiv and builds a new skill with its own roles ',
            'and sources - and the council runs that skill on the Default configuration models.'),
        el('div', { class: 'oc-field' },
            el('label', { text: 'Model that picks the skill and the research query' }),
            pickerHost,
            el('div', { class: 'oc-actions' }, resetDecision)),
        pickerStatus,
        el('div', { class: 'oc-field' },
            el('label', { for: 'oc-auto-selector-papers', text: 'arXiv papers studied for a new skill (1-10)' }),
            papers),
        el('div', { class: 'oc-field' },
            el('label', { for: 'oc-auto-selector-create' },
                autoCreate,
                el('span', { text: ' Build a new skill automatically when nothing fits' }))),
        el('div', { class: 'oc-field' },
            el('label', { for: 'oc-auto-selector-fallback', text: 'Fallback skill when building is off or fails' }),
            fallback),
        el('div', { class: 'oc-actions' }, saveBtn),
        saveStatus,
    );
}

/** Load the selector settings and paint the card. Never throws. */
async function hydrateAutoSelector() {
    const host = document.getElementById('oc-auto-selector-picker');
    const status = document.getElementById('oc-auto-selector-status');
    if (!host && !status) return;
    let body = null;
    try {
        const res = await api('/api/skills/selector');
        if (res.ok) body = res.body;
    } catch {
        // The sentence below explains the same thing either way.
    }
    autoSelectorCtl.settings = body && typeof body === 'object' ? body : null;
    renderAutoSelector(host, status);
}

function renderAutoSelector(host, status) {
    if (!host) return;
    const settings = autoSelectorCtl.settings;
    const decision = settings && settings.decision ? settings.decision : null;
    const effective = decision && decision.effective ? decision.effective : null;
    const catalogue = (state.settings && state.settings.models && Array.isArray(state.settings.models.list))
        ? state.settings.models.list
        : [];

    if (!effective) {
        clear(host);
        host.append(el('div', {
            class: 'oc-alert oc-alert-warn oc-small',
            text: 'The selector settings could not be loaded, so the model in effect is unknown. Reload the tab.',
        }));
        if (status) status.textContent = '';
        return;
    }

    if (!autoSelectorCtl.picker) {
        autoSelectorCtl.picker = createModelPicker({
            catalog: catalogue,
            value: effective,
            ariaLabel: 'Model that picks the skill',
            searchLabel: 'Search the catalogue',
            title: `${effective.provider} / ${effective.model}`,
            emptyText: 'The catalogue is empty - import or add models in the Models section first.',
            onPick: (row) => void saveAutoSelectorDecision(row),
        });
        clear(host);
        host.append(autoSelectorCtl.picker.node);
    } else {
        autoSelectorCtl.picker.setCatalog(catalogue);
        autoSelectorCtl.picker.setValue(effective);
    }

    if (status) {
        clear(status);
        const inheritedNow = decision.source === 'inherited';
        status.append(
            el('span', { text: 'In effect: ' }),
            el('span', { class: 'oc-mono', text: `${effective.provider} / ${effective.model}` }),
            el('span', {
                text: inheritedNow
                    ? ' - inherited from the role generator model.'
                    : ' - set for the automatic selector.',
            }),
        );
        const resetBtn = document.getElementById('oc-auto-selector-reset');
        if (resetBtn) resetBtn.hidden = inheritedNow;
    }

    const papers = document.getElementById('oc-auto-selector-papers');
    if (papers) papers.value = String(settings.max_papers || 5);
    const autoCreate = document.getElementById('oc-auto-selector-create');
    if (autoCreate) autoCreate.checked = settings.auto_create !== false;
    const fallback = document.getElementById('oc-auto-selector-fallback');
    if (fallback) fallback.value = String(settings.fallback_skill || '');
}

/** Choosing a decision model (or null to inherit) saves immediately. */
async function saveAutoSelectorDecision(row) {
    const body = row === null
        ? { decision: 'inherit' }
        : { decision: { provider: row.provider, model: row.model } };
    await postAutoSelector(body, 'The decision model could not be saved.');
}

/** The remaining fields save together, one click. */
async function saveAutoSelectorFields() {
    const papers = document.getElementById('oc-auto-selector-papers');
    const autoCreate = document.getElementById('oc-auto-selector-create');
    const fallback = document.getElementById('oc-auto-selector-fallback');
    await postAutoSelector({
        max_papers: papers ? Number(papers.value || 5) : 5,
        auto_create: autoCreate ? autoCreate.checked === true : true,
        fallback_skill: fallback ? String(fallback.value || '').trim() : '',
    }, 'The settings could not be saved.');
}

async function postAutoSelector(body, failText) {
    const status = document.getElementById('oc-auto-selector-save-status');
    if (autoSelectorCtl.saving) return;
    autoSelectorCtl.saving = true;
    try {
        const res = await api('/api/skills/selector', { method: 'POST', body });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        await hydrateAutoSelector();
        if (status) status.textContent = out.message || 'Saved.';
    } catch {
        if (status) status.textContent = failText;
    } finally {
        autoSelectorCtl.saving = false;
    }
}

/** Load the generator's model and paint the picker. Never throws. */
async function hydrateSkillDrafts() {
    const host = document.getElementById('oc-skill-generator-picker');
    const status = document.getElementById('oc-skill-generator-status');
    if (!host && !status) return;
    let body = null;
    try {
        const res = await api('/api/skills/generator');
        if (res.ok) body = res.body;
    } catch {
        // The explanation below is the same either way.
    }
    skillDraftCtl.settings = body && typeof body === 'object' ? body : null;
    renderSkillGenerator(host, status);
}

/** The picker plus the sentence that says which value is in effect. */
function renderSkillGenerator(host, status) {
    if (!host) return;
    const settings = skillDraftCtl.settings;
    const effective = settings && settings.effective ? settings.effective : null;
    const catalogue = (state.settings && state.settings.models && Array.isArray(state.settings.models.list))
        ? state.settings.models.list
        : [];
    const inherited = settings && settings.inherited ? settings.inherited : null;

    if (!effective) {
        clear(host);
        host.append(el('div', {
            class: 'oc-alert oc-alert-warn oc-small',
            text: 'The skill settings could not be loaded, so the model in effect is unknown. Reload the tab.',
        }));
        if (status) status.textContent = '';
        return;
    }

    if (!skillDraftCtl.picker) {
        skillDraftCtl.picker = createModelPicker({
            catalog: catalogue,
            value: effective,
            ariaLabel: 'Model that drafts skill roles',
            searchLabel: 'Search the catalogue',
            title: `${effective.provider} / ${effective.model}`,
            emptyText: 'The catalogue is empty - import or add models in the Models section first.',
            onPick: (row) => void saveSkillGenerator(row),
        });
        clear(host);
        host.append(skillDraftCtl.picker.node);
    } else {
        skillDraftCtl.picker.setCatalog(catalogue);
        skillDraftCtl.picker.setValue(effective);
    }

    if (!status) return;
    clear(status);
    const inheritedNow = settings.source === 'inherited';
    status.append(
        el('span', { text: 'In effect: ' }),
        el('span', { class: 'oc-mono', text: `${effective.provider} / ${effective.model}` }),
        el('span', {
            text: inheritedNow
                ? ` - inherited from ${settings.inherited_key || 'the synthesis model'}, because the role generator has no model of its own.`
                : ` - set for the role generator in ${settings.source_key || 'skills.generator'}.`,
        }),
    );
    if (inherited && inheritedNow) {
        status.append(el('span', {
            class: 'oc-muted',
            text: ' Pick a model here to give the generator its own.',
        }));
    }
    const resetBtn = document.getElementById('oc-skill-generator-reset');
    if (resetBtn) resetBtn.hidden = inheritedNow;
}

/** Save (or clear) the generator's model. */
async function saveSkillGenerator(row) {
    const status = document.getElementById('oc-skill-generator-status');
    const body = row === null ? { reset: true } : { provider: row.provider, model: row.model };
    try {
        const res = await api('/api/skills/generator', { method: 'POST', body });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        await hydrateSkillDrafts();
        if (status && out.message) status.dataset.saved = 'true';
    } catch {
        if (status) status.textContent = 'The change could not be saved.';
    }
}

/** Ask for a draft and show it. Nothing is stored yet. */
async function runSkillDraft() {
    const input = document.getElementById('oc-skill-draft-query');
    const button = document.getElementById('oc-skill-draft-run');
    const keep = document.getElementById('oc-skill-draft-keep');
    const status = document.getElementById('oc-skill-draft-status');
    const preview = document.getElementById('oc-skill-draft-preview');
    const query = input ? String(input.value || '').trim() : '';
    if (query === '') {
        if (status) status.textContent = 'Describe the task first.';
        return;
    }
    if (button) button.disabled = true;
    if (keep) keep.hidden = true;
    if (status) status.textContent = 'Drafting ...';
    if (preview) clear(preview);
    try {
        const res = await api('/api/skills/draft', { method: 'POST', body: { query } });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        const definition = out.definition && typeof out.definition === 'object' ? out.definition : null;
        if (!definition) {
            if (status) status.textContent = 'The server did not return a definition.';
            return;
        }
        skillDraftCtl.definition = definition;
        const roles = Array.isArray(definition.roles) ? definition.roles : [];
        if (status) {
            status.textContent = out.source === 'model'
                ? `Draft from ${out.model} - ${roles.length} role(s). Nothing is saved yet.`
                : `The model was unavailable, so this is the generic fallback - ${roles.length} role(s). Nothing is saved yet.`;
        }
        if (preview) {
            clear(preview);
            preview.append(el('div', { class: 'oc-muted oc-small', text: `${definition.name || 'Untitled'} (${definition.slug || 'no slug'})` }));
            const list = el('ul', { class: 'oc-skill-draft-roles' });
            for (const role of roles) {
                const item = el('li', {});
                item.append(el('strong', { text: String(role.label || role.key || 'role') }));
                item.append(el('div', { class: 'oc-muted oc-small', text: String(role.system || '') }));
                list.append(item);
            }
            preview.append(list);
            const details = el('details', {});
            details.append(el('summary', { text: 'The definition as JSON' }));
            details.append(el('pre', { class: 'oc-mono oc-small', text: JSON.stringify(definition, null, 2) }));
            preview.append(details);
        }
        if (keep) keep.hidden = false;
    } catch {
        if (status) status.textContent = 'The draft could not be requested.';
    } finally {
        if (button) button.disabled = false;
    }
}

/** Keep the reviewed draft. This is the only step that writes. */
async function keepSkillDraft() {
    const keep = document.getElementById('oc-skill-draft-keep');
    const status = document.getElementById('oc-skill-draft-status');
    if (!skillDraftCtl.definition) return;
    if (keep) keep.disabled = true;
    try {
        const res = await api('/api/skills/draft/confirm', { method: 'POST', body: { definition: skillDraftCtl.definition } });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        const skill = out.skill || {};
        if (status) status.textContent = `Kept: ${skill.name || skill.slug || 'the skill'} with ${skill.roles || 0} role(s).`;
        skillDraftCtl.definition = null;
        if (keep) keep.hidden = true;
    } catch {
        if (status) status.textContent = 'The skill could not be saved.';
    } finally {
        if (keep) keep.disabled = false;
    }
}

/**
 * The Research card: search arXiv, then let a chosen model read the result.
 *
 * The searcher answers a question arXiv itself cannot - not "which papers
 * exist" but "what do they say, and what should we change". Searching is one
 * public API call and needs no account; the analysis is one model call against
 * the research model, which inherits the synthesis model until it is given one
 * of its own - the same inheritance the role generator uses.
 *
 * Runs are remembered: every search and analysis lands in the research
 * journal on this server, which is what "Only new" and the History list
 * are built from. The plan travels as a draft until the operator marks it
 * active; nothing is ever promoted or deleted silently.
 */
const researchCtl = {
    settings: null,
    picker: null,
    lastAnalysis: '',
    planMarkdown: '',
    topic: '',
    runId: null,
    planStatus: 'draft',
    checked: [],
};

function sectionResearch() {
    const pickerHost = el('div', { class: 'oc-model-picker-host', id: 'oc-research-picker' });
    const generatorStatus = el('div', {
        class: 'oc-muted oc-small',
        id: 'oc-research-status',
        text: 'Loading the model in effect.',
    });
    const resetButton = el('button', {
        class: 'oc-button-secondary',
        type: 'button',
        id: 'oc-research-reset',
        hidden: true,
        onClick: () => void saveResearchModel(null),
    }, 'Use the synthesis model');

    const topic = el('input', {
        type: 'text',
        id: 'oc-research-topic',
        placeholder: 'What to look for, for example: consensus among language models',
    });
    const searchButton = el('button', {
        class: 'oc-button-secondary',
        type: 'button',
        id: 'oc-research-search',
        onClick: () => void runResearch(false),
    }, 'Search arXiv');
    const analyseButton = el('button', {
        class: 'oc-button-primary',
        type: 'button',
        id: 'oc-research-analyse',
        onClick: () => void runResearch(true),
    }, 'Search and analyse');
    const status = el('div', {
        class: 'oc-muted oc-small',
        id: 'oc-research-run-status',
        role: 'status',
        'aria-live': 'polite',
    });
    const results = el('div', { id: 'oc-research-results' });

    return el('div', { class: 'oc-card', id: 'oc-research-card' },
        el('h2', { text: 'Research' }),
        el('p', { class: 'oc-muted oc-small' },
            'Search the public arXiv API for a topic, then let a chosen model read what it returns and write an ',
            'evidence-bound brief: what the papers agree on, where they disagree, and what to change. Every run is ',
            'kept in the research journal on this server; nothing is ever deleted silently.'),
        el('div', { class: 'oc-field' },
            el('label', { text: 'Model that analyses the papers' }),
            pickerHost,
            el('div', { class: 'oc-actions' }, resetButton)),
        generatorStatus,
        el('div', { class: 'oc-field' },
            el('label', { for: 'oc-research-topic', text: 'Topic' }),
            topic),
        el('label', { class: 'oc-inline-check', for: 'oc-research-only-new' },
            el('input', { type: 'checkbox', id: 'oc-research-only-new' }),
            el('span', { text: 'Only new: skip papers already seen in earlier searches' })),
        el('div', { class: 'oc-actions' }, searchButton, analyseButton),
        status,
        results,
        el('h3', { text: 'History' }),
        el('div', { class: 'oc-research-history', id: 'oc-research-history' },
            el('div', { class: 'oc-muted oc-small', text: 'Loading the journal ...' })),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-secondary',
                type: 'button',
                id: 'oc-research-history-clear',
                onClick: () => void clearResearchHistory(),
            }, 'Clear history')),
    );
}

/** Load the research model and paint the picker. Never throws. */
async function hydrateResearch() {
    const host = document.getElementById('oc-research-picker');
    const status = document.getElementById('oc-research-status');
    if (!host && !status) return;
    let body = null;
    try {
        const res = await api('/api/research/settings');
        if (res.ok) body = res.body;
    } catch {
        // The sentence below is the same either way.
    }
    researchCtl.settings = body && typeof body === 'object' ? body : null;
    renderResearchModel(host, status);
    // The journal is independent of the model settings: load it either way.
    void refreshResearchHistory();
}

/** The picker plus the sentence that says which model is in effect. */
function renderResearchModel(host, status) {
    if (!host) return;
    const settings = researchCtl.settings;
    const effective = settings && settings.effective ? settings.effective : null;
    const catalogue = (state.settings && state.settings.models && Array.isArray(state.settings.models.list))
        ? state.settings.models.list
        : [];
    if (!effective) {
        clear(host);
        host.append(el('div', {
            class: 'oc-alert oc-alert-warn oc-small',
            text: 'The research settings could not be loaded, so the model in effect is unknown. Reload the tab.',
        }));
        if (status) status.textContent = '';
        return;
    }
    if (!researchCtl.picker) {
        researchCtl.picker = createModelPicker({
            catalog: catalogue,
            value: effective,
            ariaLabel: 'Model that analyses arXiv papers',
            searchLabel: 'Search the catalogue',
            title: `${effective.provider} / ${effective.model}`,
            emptyText: 'The catalogue is empty - import or add models in the Models section first.',
            onPick: (row) => void saveResearchModel(row),
        });
        clear(host);
        host.append(researchCtl.picker.node);
    } else {
        researchCtl.picker.setCatalog(catalogue);
        researchCtl.picker.setValue(effective);
    }
    if (!status) return;
    clear(status);
    const inheritedNow = settings.source === 'inherited';
    status.append(
        el('span', { text: 'In effect: ' }),
        el('span', { class: 'oc-mono', text: `${effective.provider} / ${effective.model}` }),
        el('span', {
            text: inheritedNow
                ? ` - inherited from ${settings.inherited_key || 'the synthesis model'}, because research has no model of its own.`
                : ` - set for research in ${settings.source_key || 'research'}.`,
        }),
    );
    const resetBtn = document.getElementById('oc-research-reset');
    if (resetBtn) resetBtn.hidden = inheritedNow;
}

/** Save (or clear) the research model. */
async function saveResearchModel(row) {
    const status = document.getElementById('oc-research-status');
    const body = row === null ? { reset: true } : { provider: row.provider, model: row.model };
    try {
        const res = await api('/api/research/settings', { method: 'POST', body });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        await hydrateResearch();
    } catch {
        if (status) status.textContent = 'The change could not be saved.';
    }
}

/** Search, and optionally hand the result to the model. */
async function runResearch(withAnalysis) {
    const input = document.getElementById('oc-research-topic');
    const searchButton = document.getElementById('oc-research-search');
    const analyseButton = document.getElementById('oc-research-analyse');
    const status = document.getElementById('oc-research-run-status');
    const results = document.getElementById('oc-research-results');
    const value = input ? String(input.value || '').trim() : '';
    researchCtl.topic = value;
    if (value === '') {
        if (status) status.textContent = 'Describe the topic first.';
        return;
    }
    if (searchButton) searchButton.disabled = true;
    if (analyseButton) analyseButton.disabled = true;
    if (status) status.textContent = withAnalysis ? 'Searching arXiv, then analysing ...' : 'Searching arXiv ...';
    if (results) clear(results);
    try {
        const res = await api(withAnalysis ? '/api/research/analyse' : '/api/research/search', {
            method: 'POST',
            body: {
                topic: value,
                max: 5,
                only_new: !!(document.getElementById('oc-research-only-new')?.checked),
            },
        });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            if (results && Array.isArray(out.papers) && out.papers.length) paintResearchPapers(results, out);
            return;
        }
        if (status) {
            const candidates = Number.isFinite(out.candidates) ? ` of ${out.candidates} candidate(s)` : '';
            const hidden = Number.isFinite(out.seen_hidden) && out.seen_hidden > 0
                ? `, ${out.seen_hidden} seen hidden` : '';
            const unsaved = out.saved === false
                ? ' History is unavailable on this server; this run was not saved.' : '';
            status.textContent = (withAnalysis
                ? `${out.count}${candidates}${hidden}; analysed with ${out.provider} / ${out.model} (${out.tokens} tokens).`
                : `${out.count}${candidates}${hidden} shown for ${out.query}, best matches first.`) + unsaved;
        }
        if (results) paintResearchPapers(results, out);
        void refreshResearchHistory();
    } catch {
        if (status) status.textContent = 'The request did not complete.';
    } finally {
        if (searchButton) searchButton.disabled = false;
        if (analyseButton) analyseButton.disabled = false;
    }
}

/** The papers, the analysis, and the change plan when the answer carries one. */
function paintResearchPapers(host, out) {
    clear(host);
    const papers = Array.isArray(out.papers) ? out.papers : [];
    const list = el('ol', { class: 'oc-research-papers' });
    for (const paper of papers) {
        const item = el('li', {});
        const link = el('a', {
            href: String(paper.url || '#'),
            target: '_blank',
            rel: 'noopener noreferrer',
            text: String(paper.title || paper.id || 'paper'),
        });
        item.append(link);
        const bits = [];
        if (Array.isArray(paper.authors) && paper.authors.length) bits.push(paper.authors.join(', '));
        if (paper.published) bits.push(String(paper.published));
        if (bits.length) item.append(el('div', { class: 'oc-muted oc-small', text: bits.join(' - ') }));
        // Why this paper was kept: the topic terms it covers. A paper with no
        // matches can still be here - it came in arXiv's own window - and says
        // nothing rather than "relevance 0" over nothing.
        if (Array.isArray(paper.matched) && paper.matched.length) {
            item.append(el('div', {
                class: 'oc-muted oc-small',
                text: `relevance ${paper.score} - ${paper.matched.join(', ')}`,
            }));
        }
        if (paper.seen_first) {
            item.append(el('div', {
                class: 'oc-muted oc-small',
                text: `seen before - first shown ${String(paper.seen_first)}`,
            }));
        }
        item.append(el('div', { class: 'oc-muted oc-small', text: String(paper.summary || '') }));
        list.append(item);
    }
    host.append(list);

    const analysis = String(out.analysis || '');
    if (analysis !== '') {
        researchCtl.lastAnalysis = analysis;
        host.append(el('h3', { text: 'Analysis' }));
        host.append(el('pre', { class: 'oc-research-analysis', text: analysis }));
    }

    // The change plan: the proposals the analysis closed with, as an
    // operator-sized checklist. A run that reached the journal carries live
    // state - its status and its checkboxes write back; a run that could not
    // be saved stays a text plan, and says so.
    const proposals = Array.isArray(out.proposals) ? out.proposals : [];
    researchCtl.planMarkdown = typeof out.plan_markdown === 'string' ? out.plan_markdown : '';
    researchCtl.runId = Number.isInteger(out.run_id) ? out.run_id : null;
    researchCtl.planStatus = typeof out.plan_status === 'string' ? out.plan_status : 'draft';
    researchCtl.checked = Array.isArray(out.plan_checked) ? out.plan_checked.map(Boolean) : [];
    if (analysis !== '' && proposals.length) {
        host.append(el('h3', { text: `Change plan (${proposals.length})` }));
        if (researchCtl.runId !== null) {
            const statusSelect = el('select', {
                id: 'oc-plan-status',
                onChange: (event) => void saveResearchPlanStatus(String(event.target.value)),
            });
            for (const [value, label] of [
                ['draft', 'Draft'],
                ['active', 'Active: in work'],
                ['done', 'Done'],
                ['dropped', 'Dropped'],
            ]) {
                statusSelect.append(el('option', {
                    value: value,
                    text: label,
                    selected: researchCtl.planStatus === value,
                }));
            }
            host.append(el('div', { class: 'oc-actions oc-plan-status-row' },
                el('span', { class: 'oc-muted oc-small', text: 'Status:' }),
                statusSelect,
                el('button', {
                    class: 'oc-button-secondary',
                    type: 'button',
                    id: 'oc-plan-delete',
                    onClick: () => void deleteResearchRun(),
                }, 'Delete run')));
        }
        const planList = el('ul', { class: 'oc-plan-list' });
        proposals.forEach((proposal, index) => {
            const item = el('li', {});
            if (researchCtl.runId !== null) {
                item.append(el('input', {
                    type: 'checkbox',
                    class: 'oc-plan-check',
                    id: `oc-plan-check-${index}`,
                    checked: researchCtl.checked[index] === true,
                    onChange: () => void saveResearchPlanChecks(),
                }), el('span', { text: ' ' }));
            }
            item.append(el('span', { text: String(proposal) }));
            planList.append(item);
        });
        host.append(planList);
        host.append(el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-secondary',
                type: 'button',
                id: 'oc-plan-copy',
                onClick: () => void copyResearchPlan(),
            }, 'Copy plan'),
            el('button', {
                class: 'oc-button-secondary',
                type: 'button',
                id: 'oc-plan-download',
                onClick: () => downloadResearchPlan(),
            }, 'Download .md')));
    } else if (analysis !== '') {
        host.append(el('div', {
            class: 'oc-muted oc-small',
            text: 'The analysis did not separate a list of changes; the plan would be empty.',
        }));
    }
}

/** Copy the ready change plan (markdown) to the clipboard. */
async function copyResearchPlan() {
    const status = document.getElementById('oc-research-run-status');
    try {
        await navigator.clipboard.writeText(researchCtl.planMarkdown);
        if (status) status.textContent = 'Change plan copied.';
    } catch {
        if (status) status.textContent = 'The clipboard is unavailable; use Download .md instead.';
    }
}

/** Download the change plan as a file - the same shape as the other downloads. */
function downloadResearchPlan() {
    const status = document.getElementById('oc-research-run-status');
    const text = researchCtl.planMarkdown;
    if (text === '') return;
    const slug = String(researchCtl.topic || '')
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60) || 'topic';
    let objectUrl = '';
    try {
        if (typeof Blob !== 'function' || typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') {
            if (status) status.textContent = 'This browser cannot build the file.';
            return;
        }
        objectUrl = URL.createObjectURL(new Blob([text], { type: 'text/markdown;charset=utf-8' }));
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = `change-plan-${slug}.md`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        if (status) status.textContent = 'Change plan saved.';
    } catch {
        if (status) status.textContent = 'The file could not be built.';
    } finally {
        if (objectUrl !== '') URL.revokeObjectURL(objectUrl);
    }
}

/** Reload the journal list. Never throws; an unavailable journal says so. */
async function refreshResearchHistory() {
    const host = document.getElementById('oc-research-history');
    if (!host) return;
    let body = null;
    try {
        const res = await api('/api/research/history');
        if (res.ok) body = res.body;
    } catch {
        // The message below covers both cases.
    }
    clear(host);
    if (!body || body.available === false) {
        host.append(el('div', {
            class: 'oc-muted oc-small',
            text: 'The journal is unavailable on this server (the database or its tables are missing). Runs will not be kept.',
        }));
        return;
    }
    const runs = Array.isArray(body.runs) ? body.runs : [];
    if (runs.length === 0) {
        host.append(el('div', { class: 'oc-muted oc-small', text: 'Nothing yet: searches and analyses will appear here.' }));
        return;
    }
    const list = el('ol', { class: 'oc-research-history-list' });
    for (const run of runs) {
        const kind = run.kind === 'analyse' ? 'analysis' : 'search';
        const plans = Number.isFinite(run.proposals) && run.proposals > 0
            ? `; plan: ${String(run.plan_status || 'draft')} (${run.proposals})` : '';
        // v1.9.4 - the list says how much of the found window was shown.
        const shown = Number(run.papers) || 0;
        const found = Number(run.found) || 0;
        const papersBit = found > shown ? `${shown} of ${found} paper(s)` : `${shown} paper(s)`;
        const item = el('li', {});
        item.append(el('button', {
            class: 'oc-research-history-open',
            type: 'button',
            title: 'Load this run into the card',
            onClick: () => void loadResearchRun(Number(run.id)),
        }, `${String(run.created_at || '')} - ${kind} - ${String(run.topic || '')} (${papersBit}${plans})`));
        list.append(item);
    }
    host.append(list);
}

/** Load a saved run back into the card, exactly as it was. */
async function loadResearchRun(id) {
    const status = document.getElementById('oc-research-run-status');
    const results = document.getElementById('oc-research-results');
    if (!Number.isInteger(id) || id <= 0) return;
    if (status) status.textContent = 'Loading the run ...';
    try {
        const res = await api(`/api/research/run/${id}`);
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        const run = out.run || {};
        researchCtl.topic = String(run.topic || '');
        const input = document.getElementById('oc-research-topic');
        if (input && researchCtl.topic !== '') input.value = researchCtl.topic;
        if (status) {
            status.textContent = `Loaded ${String(run.created_at || '')} (${run.kind === 'analyse' ? 'analysis' : 'search'}, ${Number(run.candidates) || 0} candidate(s)).`;
        }
        if (results) paintResearchPapers(results, run);
    } catch {
        if (status) status.textContent = 'The run could not be loaded.';
    }
}

/** Write the plan status back; the history list reflects it on the next refresh. */
async function saveResearchPlanStatus(value) {
    const status = document.getElementById('oc-research-run-status');
    if (researchCtl.runId === null) return;
    try {
        const res = await api('/api/research/plan/status', {
            method: 'POST',
            body: { id: researchCtl.runId, status: value },
        });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        researchCtl.planStatus = value;
        if (status) status.textContent = `Plan marked ${value}.`;
        void refreshResearchHistory();
    } catch {
        if (status) status.textContent = 'The status could not be saved.';
    }
}

/** Collect the checkboxes and write them back; the server answers fresh markdown. */
async function saveResearchPlanChecks() {
    const status = document.getElementById('oc-research-run-status');
    if (researchCtl.runId === null) return;
    const checked = Array.from(document.querySelectorAll('.oc-plan-check')).map((box) => box.checked === true);
    try {
        const res = await api('/api/research/plan/check', {
            method: 'POST',
            body: { id: researchCtl.runId, checked: checked },
        });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        researchCtl.checked = checked;
        if (typeof out.plan_markdown === 'string') researchCtl.planMarkdown = out.plan_markdown;
        if (status) status.textContent = 'Plan saved.';
    } catch {
        if (status) status.textContent = 'The checklist could not be saved.';
    }
}

/** Delete the loaded run; the text stays on screen, the journal forgets it. */
async function deleteResearchRun() {
    const status = document.getElementById('oc-research-run-status');
    if (researchCtl.runId === null) return;
    if (!window.confirm('Delete this run and its plan from the journal?')) return;
    try {
        const res = await api('/api/research/run/delete', { method: 'POST', body: { id: researchCtl.runId } });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        researchCtl.runId = null;
        if (status) status.textContent = 'Run deleted.';
        void refreshResearchHistory();
    } catch {
        if (status) status.textContent = 'The run could not be deleted.';
    }
}

/** Clear the whole journal, after one confirmation. */
async function clearResearchHistory() {
    const status = document.getElementById('oc-research-run-status');
    if (!window.confirm('Delete the whole research history? This also forgets which papers were seen.')) return;
    try {
        const res = await api('/api/research/history/clear', { method: 'POST', body: {} });
        const out = res.body || {};
        if (!res.ok || out.ok === false) {
            if (status) status.textContent = out.message || `Failed (${res.status})`;
            return;
        }
        researchCtl.runId = null;
        if (status) status.textContent = `History cleared (${Number(out.deleted) || 0} run(s)).`;
        void refreshResearchHistory();
    } catch {
        if (status) status.textContent = 'The history could not be cleared.';
    }
}

async function saveActualizeAnalyzer(row) {
    const status = document.getElementById('oc-actualize-action-status');
    const analyzerStatus = document.getElementById('oc-actualize-analyzer-status');
    const body = row === null ? { reset: true } : { provider: row.provider, model: row.model };
    const res = await api('/api/models/actualize/settings', { method: 'POST', body });
    const out = res.body || {};
    if (!res.ok || out.ok === false) {
        // The refusal text is the point: a `:batch` id is refused with the
        // reason the operator can act on, not with "invalid model".
        const text = out.message || `Failed (${res.status})`;
        if (analyzerStatus) analyzerStatus.textContent = text;
        if (status) status.textContent = 'Not saved.';
        await hydrateActualizeSettings();
        if (analyzerStatus) analyzerStatus.textContent = text;
        return;
    }
    if (analyzerStatus && out.message) analyzerStatus.dataset.saved = 'true';
    await hydrateActualizeSettings();
    if (status) status.textContent = row === null ? 'Section reset.' : 'Section model saved.';
}

/** Fetch the last run's status and paint it. Never throws. */
async function refreshActualizeStatus() {
    if (actualizeCtl.busy) return;
    actualizeCtl.busy = true;
    try {
        const { ok, body } = await api('/api/models/actualize/status');
        if (ok) renderActualizeStatus(body);
    } catch {
        // A failed status poll is not an error the operator must act on:
        // the next tick retries, and the button stays in its last state.
    } finally {
        actualizeCtl.busy = false;
    }
}

/**
 * Put every stage row back to "pending" and drop the previous verdict.
 *
 * Called synchronously from the click handler, before the POST is even
 * sent (v1.8.51): the verdict on screen belongs to a run that is over, so
 * a second press must clear it at once. Painting the new run by leaving
 * the old green rows in place is exactly what made "press it again" read
 * as "nothing happened".
 */
function actualizeResetStages() {
    const card = document.getElementById('oc-actualize-card');
    if (!card) return;
    for (const row of card.querySelectorAll('.oc-actualize-stage')) {
        row.classList.remove('oc-actualize-stage-done', 'oc-actualize-stage-running', 'oc-actualize-stage-failed');
        row.classList.add('oc-actualize-stage-pending');
    }
    const summary = document.getElementById('oc-actualize-summary');
    if (summary) clear(summary);
}

/**
 * Does this status snapshot still describe the PREVIOUS run?
 *
 * True while the operator has pressed the button and the answer on screen
 * belongs to an older job: the card must then show "waiting for the
 * worker" instead of the old verdict. The gate is released the moment a
 * snapshot carries the awaited job's identity, so the poller goes back to
 * ordinary rendering without a second flag to keep in sync.
 *
 * @param {number|null} snapJobId The job id the snapshot names, if any.
 * @returns {boolean}
 */
function actualizeAwaitingNewRun(snapJobId) {
    const pending = actualizeCtl.pendingRun;
    if (!pending) return false;
    const stale = pending.jobId !== null
        // The enqueue named the job: anything that is not that job is the
        // previous run's record — including a snapshot with no id at all.
        ? snapJobId !== pending.jobId
        // The enqueue said nothing: fall back to "must not be the run that
        // was on screen a moment ago".
        : (snapJobId !== null && snapJobId === pending.supersededJobId);
    if (!stale) {
        actualizeCtl.pendingRun = null;
        return false;
    }
    return true;
}

/**
 * Which stage row a failed run stopped on.
 *
 * The worker reports the last stage it published (`failed_stage`). When
 * that is unknown — a process killed between stages, a crash that left no
 * progress record — the failure is pinned to the final row rather than
 * spread over all three: something must be red, and the run did not reach
 * a result.
 *
 * @param {object} snap  The status snapshot.
 * @param {string[]} reached The stage keys in UI order.
 * @returns {number} Index into `reached`.
 */
function actualizeFailedIndex(snap, reached) {
    const reported = typeof snap.failed_stage === 'string' ? snap.failed_stage : '';
    const idx = reported === '' ? -1 : reached.indexOf(reported);
    return idx >= 0 ? idx : reached.length - 1;
}

function renderActualizeStatus(snap) {
    const card = document.getElementById('oc-actualize-card');
    if (!card || !snap || typeof snap !== 'object') return;
    const button = document.getElementById('oc-actualize-btn');
    const status = document.getElementById('oc-actualize-action-status');
    const summary = document.getElementById('oc-actualize-summary');
    const rollbackBtn = document.getElementById('oc-actualize-rollback-btn');
    const snapJobId = Number.isInteger(snap.job_id) && snap.job_id > 0 ? snap.job_id : null;
    if (snapJobId !== null) actualizeCtl.lastJobId = snapJobId;
    // A snapshot of the previous run is not this run's result: render the
    // wait, not the verdict (see actualizeAwaitingNewRun). A job the queue
    // itself calls `queued` is the same situation from the other side — the
    // run exists, the worker has not touched it, and stage 1 has not
    // started, so nothing may be painted as running yet.
    const waiting = actualizeAwaitingNewRun(snapJobId);
    const queuedOnly = !waiting && snap.job_status === 'queued';
    const stage = waiting || queuedOnly ? 'queued' : (typeof snap.stage === 'string' ? snap.stage : 'idle');
    const active = waiting || snap.active === true || stage === 'queued'
        || ACTUALIZE_STAGES.some(s => s.key === stage && s.key !== 'finish');

    if (button) button.disabled = active;

    // Stage rows: done / running / pending / failed. A terminal SUCCESS
    // completes every row. A terminal FAILURE does not: the stages that
    // finished stay done, the stage the run died in is marked failed, and
    // anything after it never ran. Before v1.8.51 a `problems` verdict
    // greened all three rows, so a failed run was indistinguishable from a
    // successful one at a glance.
    const reached = ACTUALIZE_STAGES.map(s => s.key);
    const succeeded = stage === 'success';
    const failed = stage === 'problems';
    const failIdx = failed ? actualizeFailedIndex(snap, reached) : -1;
    const stopAt = (succeeded || failed) ? -1 : reached.indexOf(stage);
    for (const row of card.querySelectorAll('.oc-actualize-stage')) {
        const key = row.getAttribute('data-stage-key') || '';
        const idx = reached.indexOf(key);
        const done = succeeded
            || (failed && idx >= 0 && idx < failIdx)
            || (stopAt >= 0 && idx >= 0 && idx < stopAt);
        const isFailed = failed && idx === failIdx;
        const running = !succeeded && !failed && active && idx === stopAt;
        row.classList.toggle('oc-actualize-stage-done', done);
        row.classList.toggle('oc-actualize-stage-failed', isFailed);
        row.classList.toggle('oc-actualize-stage-running', running);
        row.classList.toggle('oc-actualize-stage-pending', !done && !isFailed && !running);
    }

    if (summary) {
        clear(summary);
        if (waiting || queuedOnly) {
            // Nothing has been published for THIS run yet. The previous run's
            // message, error and assignment lists describe a job that is
            // over, so only the wait is true here.
            summary.append(el('div', { class: 'oc-muted oc-small', id: 'oc-actualize-waiting' },
                ACTUALIZE_QUEUED_TEXT + '.'));
        } else if (stage === 'success' || stage === 'problems') {
            summary.append(el('div', {
                class: stage === 'success' ? 'oc-alert oc-alert-ok' : 'oc-alert oc-alert-warn',
                text: snap.message || (stage === 'success' ? 'Actualization complete.' : 'Actualization finished with problems.'),
            }));
        } else if (active && snap.message) {
            // A queued or in-flight run says where it is instead of leaving an
            // empty card that reads like "nothing happened".
            summary.append(el('div', { class: 'oc-muted oc-small', text: String(snap.message) }));
        }
        // The failure text, in its own block: `warnings[]` explains what was
        // skipped, `error` names what actually broke (class, message, and for
        // a failed config write the file and the filesystem facts). Inside a
        // wait it would be the PREVIOUS run's failure, so it is skipped.
        const error = !waiting && snap.error && typeof snap.error === 'object' ? snap.error : null;
        if (error) {
            const parts = [];
            if (error.message) parts.push(String(error.message));
            if (error.class) parts.push(`(${String(error.class)})`);
            if (error.stage && error.stage !== 'config_write_failed') parts.push(`stage: ${String(error.stage)}`);
            if (error.target) parts.push(`file: ${String(error.target)}`);
            const fs = error.fs && typeof error.fs === 'object' ? error.fs : {};
            if (fs.file_perms || fs.process_user) {
                parts.push(`mode ${fs.file_perms || 'n/a'} / owner ${fs.file_owner || 'n/a'} / php user ${fs.process_user || 'n/a'}`);
            }
            // A rollback that did not happen is a second, worse fact than the
            // write that failed: an unrecovered partial write is exactly what
            // "the report contradicts itself" used to hide.
            if (error.restore_error) {
                parts.push(`the previous state could NOT be restored: ${String(error.restore_error)}`);
            }
            if (error.restore_ok === true) {
                parts.push('the previous state was restored');
            }
            summary.append(el('div', { class: 'oc-alert oc-alert-error', id: 'oc-actualize-error' },
                parts.length > 0 ? parts.join(' — ') : 'The run reported an error without a message.'));
        }
        const warnings = !waiting && Array.isArray(snap.warnings) ? snap.warnings : [];
        if (warnings.length > 0) {
            summary.append(el('ul', { class: 'oc-actualize-warnings' },
                warnings.map(w => el('li', { text: String(w) }))));
        }
        // What the first stage imported: the source, how many models the
        // provider sent, how many were new, how many were already known and
        // how many could not be taken. "The catalogue has 412 models" and
        // "my catalogue kept 64" are different facts, and the second one is
        // the one an operator can act on.
        const imported = !waiting && snap.import && typeof snap.import === 'object' ? snap.import : null;
        if (imported && (imported.source || imported.fetched > 0)) {
            const bits = [];
            if (imported.source) bits.push(`from ${String(imported.source)}`);
            if (imported.fetched > 0) bits.push(`${Number(imported.fetched)} model(s) received`);
            if (Array.isArray(imported.added) && imported.added.length > 0) {
                bits.push(`${imported.added.length} new row(s) added`);
            } else if (imported.fetched > 0) {
                bits.push('no new rows');
            }
            if (imported.duplicates > 0) bits.push(`${Number(imported.duplicates)} already catalogued`);
            if (imported.truncated > 0) {
                bits.push(`${Number(imported.truncated)} left out — the catalogue is capped at ${Number(imported.limit)} rows`);
            }
            if (imported.unusable > 0) bits.push(`${Number(imported.unusable)} entr(ies) carried no model id`);
            summary.append(el('div', { class: 'oc-muted oc-small', id: 'oc-actualize-import' },
                'Import: ' + bits.join('; ') + '.'));
        }
        // Which model answered the assignment calls. Part of the contract of
        // the section: the same run on a different model is a different
        // result, and the card has to say which one produced this one.
        const used = !waiting && snap.analyzer && typeof snap.analyzer === 'object' ? snap.analyzer : null;
        if (used && used.provider && used.model) {
            const where = used.source === 'section'
                ? 'set for this section'
                : 'inherited from analyzer.provider / analyzer.model';
            const counts = [];
            if (used.requests > 0) counts.push(`${Number(used.requests)} call(s)`);
            if (used.corrections > 0) counts.push(`${Number(used.corrections)} correction(s)`);
            summary.append(el('div', { class: 'oc-muted oc-small', id: 'oc-actualize-analyzer' },
                'Analyzer: ',
                el('span', { class: 'oc-mono', text: `${String(used.provider)} / ${String(used.model)}` }),
                ` (${where})${counts.length > 0 ? ' — ' + counts.join(', ') : ''}.`));
        }
        // The answer's own justification per preset: the prompt asks for it,
        // so the report shows it rather than discarding what was asked for.
        const reasons = !waiting && snap.reasons && typeof snap.reasons === 'object' ? snap.reasons : null;
        if (reasons) {
            const rows = Object.entries(reasons).map(([slug, reason]) => el('li', {},
                el('span', { class: 'oc-mono', text: String(slug) }),
                ': ',
                el('span', { text: String(reason) }),
            ));
            if (rows.length > 0) {
                summary.append(el('p', { class: 'oc-muted oc-small', text: 'Why the analyzer chose each pool:' }));
                summary.append(el('ul', { class: 'oc-actualize-reasons' }, rows));
            }
        }
        // The models bound to the providers by config keys rather than by a
        // preset: the run keeps them on the newest release of their line too,
        // so the summary has to name them — "the presets moved to gpt-5.6"
        // is misleading while the analyzer is still on gpt-5.5.
        const linked = !waiting && Array.isArray(snap.linked) ? snap.linked : [];
        const linkedRows = linked.map(row => {
            if (!row || typeof row !== 'object') return null;
            const label = String(row.label || row.role || '');
            const before = String(row.previous_model || '');
            const after = String(row.model || '');
            if (label === '' || after === '') return null;
            return el('li', { 'data-role': String(row.role || '') },
                el('span', { class: 'oc-mono', text: label }),
                ': ',
                el('span', { class: 'oc-mono', text: before === '' ? after : `${before} → ${after}` }),
            );
        }).filter(Boolean);
        if (linkedRows.length > 0) {
            summary.append(el('p', { class: 'oc-muted oc-small', text: 'Linked models updated:' }));
            summary.append(el('ul', { class: 'oc-actualize-linked' }, linkedRows));
        }
        const assigned = !waiting && snap.assigned && typeof snap.assigned === 'object' ? snap.assigned : null;
        if (assigned) {
            const rows = Object.entries(assigned).map(([slug, models]) => el('li', {},
                el('span', { class: 'oc-mono', text: slug }),
                ': ',
                el('span', { text: Array.isArray(models) ? models.join(', ') : String(models) }),
            ));
            if (rows.length > 0) {
                summary.append(el('p', { class: 'oc-muted oc-small', text: 'Assigned models per consensus:' }));
                summary.append(el('ul', { class: 'oc-actualize-assigned' }, rows));
            }
        }
        // Run identity: which job this verdict belongs to, and when it ran.
        // Without it a green/red card was attached to no run in particular —
        // the operator could not tell the verdict of the run they just
        // pressed the button for from the one before it. While waiting, the
        // id on screen is the WAITED-FOR job: naming the stale snapshot's
        // number would pin the card to the run it is replacing.
        const awaitedJob = actualizeCtl.pendingRun ? actualizeCtl.pendingRun.jobId : null;
        const identityJob = waiting ? awaitedJob : (snapJobId !== null ? snapJobId : awaitedJob);
        const timing = [];
        if (identityJob !== null) timing.push(`job #${identityJob}`);
        if (!waiting) {
            if (snap.started_at) timing.push(`started ${formatActualizeStamp(snap.started_at)}`);
            if (snap.finished_at) timing.push(`finished ${formatActualizeStamp(snap.finished_at)}`);
        }
        if (timing.length > 0) {
            summary.append(el('p', { class: 'oc-muted oc-small', id: 'oc-actualize-run', text: `Run: ${timing.join(' · ')}` }));
        }
        // The worker is what executes this job. Saying so is better than
        // an "analysing…" spinner that never advances.
        if (snap.worker_available === false) {
            const queued = typeof snap.queued_seconds === 'number' && snap.queued_seconds > 0
                ? ` It has been queued for ${Math.round(snap.queued_seconds)}s.`
                : '';
            summary.append(el('div', { class: 'oc-alert oc-alert-error' },
                'The worker is not running, so this job cannot be picked up.', queued,
                ' Start it with ',
                el('span', { class: 'oc-mono', text: 'php bin/revoap-worker.php' }),
                ' (Settings → Worker shows the live state).'));
        } else if (active && typeof snap.queued_seconds === 'number' && snap.queued_seconds > 20) {
            // A live worker but a job that is not moving is worth naming too:
            // it means the pool is busy, not that the button was ignored.
            summary.append(el('p', { class: 'oc-muted oc-small' },
                `Queued for ${Math.round(snap.queued_seconds)}s — the worker has not started it yet.`));
        }
    }

    if (rollbackBtn) {
        rollbackBtn.hidden = !(snap.snapshot && snap.snapshot.available === true);
    }
    if (status) {
        status.textContent = (waiting || queuedOnly) ? ACTUALIZE_QUEUED_TEXT
            : active ? 'Running…'
                : (stage === 'success' ? 'Done.'
                    : stage === 'problems' ? 'Done with problems.'
                        : '');
    }
}

/**
 * The worker stamps `started_at` / `finished_at` as UTC (`gmdate`), so the
 * card says so instead of letting a local-looking time read as local.
 *
 * @param {unknown} value ISO-8601 UTC stamp from the status record.
 * @returns {string}
 */
function formatActualizeStamp(value) {
    const text = typeof value === 'string' ? value : String(value ?? '');
    if (text === '') return 'unknown';
    // The worker stamps with a trailing `Z` already; naming the zone twice
    // would read as a different fact than the one recorded.
    return /Z$|[+-]\d{2}:?\d{2}$/.test(text) ? text : `${text} UTC`;
}

async function runActualize() {
    const status = document.getElementById('oc-actualize-action-status');
    const button = document.getElementById('oc-actualize-btn');
    const sourceSelect = document.getElementById('oc-actualize-import-source');
    // The source the operator picked, sent in the payload: the import runs
    // in the worker (a catalogue fetch is far too long for a request), so
    // this is the only moment the choice can be handed over.
    const importSource = sourceSelect && sourceSelect.value ? sourceSelect.value : '';
    // 1) Clear the card and arm the gate BEFORE the POST: the rows on
    //    screen describe the previous run, and the new one has not started.
    actualizeResetStages();
    actualizeCtl.pendingRun = { jobId: null, supersededJobId: actualizeCtl.lastJobId };
    if (status) status.textContent = ACTUALIZE_QUEUED_TEXT;
    if (button) button.disabled = true;
    const res = await api('/api/models/actualize', {
        method: 'POST',
        body: importSource === '' ? {} : { import_source: importSource },
    });
    if (!res.ok) {
        // Nothing was queued, so there is nothing to wait for: drop the gate
        // and let the card show the real state again, then report the failure.
        actualizeCtl.pendingRun = null;
        await refreshActualizeStatus();
        if (status) status.textContent = res.body?.message || `Failed (${res.status})`;
        return;
    }
    // The enqueue names the job it created (or reused) — bind the gate to it
    // so the old verdict can never be mistaken for this run's.
    const jobId = Number(res.body?.job_id);
    if (Number.isInteger(jobId) && jobId > 0) {
        actualizeCtl.pendingRun = { jobId: jobId, supersededJobId: actualizeCtl.lastJobId };
    }
    // 200 means an identical job is already queued/running (idempotent
    // enqueue), 201 that this call created it — both continue to polling.
    if (status) status.textContent = res.status === 200 ? 'Already running…' : ACTUALIZE_QUEUED_TEXT;
    await refreshActualizeStatus();
}

async function rollbackActualize() {
    const status = document.getElementById('oc-actualize-action-status');
    if (status) status.textContent = 'Rolling back…';
    const res = await api('/api/models/actualize/rollback', { method: 'POST', body: {} });
    if (status) status.textContent = res.ok ? 'Rolled back.' : (res.body?.message || `Failed (${res.status})`);
    if (res.ok) {
        await loadAndRender();
    }
}

// ===== Domain models (consensus presets) ==============================
function sectionDomainModels() {
    return el('div', { class: 'oc-card', id: 'oc-domain-models' },
        el('h2', { text: 'Domain Models' }),
        el('p', { class: 'oc-muted oc-small' },
            'Per-domain model presets used by the v1.8.32 auto-routing layer. ',
            'When the analyzer classifies a query as Mathematics or Physics, the corresponding preset replaces your manual selection for that run. ',
            'Toggle ',
            el('span', { class: 'oc-mono', text: 'lock_models: true' }),
            ' in the chat options to opt out.'),
        // v1.8.41 — global provider toolbar: reassign every OPEN preset to
        // one provider with a diverse spread of its catalog. The select and
        // Apply wiring are populated by hydrateDomainPresets().
        el('div', { id: 'oc-domain-provider-toolbar' },
            el('span', { class: 'oc-muted oc-small', text: 'Reassign every open preset to one provider (diverse model picks):' }),
            el('select', { id: 'oc-domain-provider-select' },
                el('option', { value: '', text: 'Select provider…' })),
            el('button', { class: 'oc-button-secondary', type: 'button', id: 'oc-domain-provider-apply', disabled: true }, 'Apply'),
            el('span', { class: 'oc-status', id: 'oc-domain-provider-status' }),
        ),
        // v1.8.49 — applying a preset to the live Chat pool. The left
        // sidebar keeps its own localStorage selection, so "use this
        // consensus" has to push the preset into it explicitly.
        el('div', { id: 'oc-domain-pool-status', class: 'oc-muted oc-small' }),
        el('div', { id: 'oc-domain-presets-root' },
            el('p', { class: 'oc-muted', text: 'Loading…' })),
    );
}

async function hydrateDomainPresets() {
    const root = document.getElementById('oc-domain-presets-root');
    if (!root) return;
    const { ok, body } = await api('/api/domain-presets');
    clear(root);
    // v1.9.x - the preset set travels as a document: export keeps a copy next to
    // a configuration backup, import reads one back. The export route is the
    // same JSON the API returns; the import is a dry run that says exactly what
    // would change, and only writes after the operator confirms.
    if (!document.getElementById('oc-presets-transfer')) {
        root.prepend(presetTransferBar(root));
    }
    if (!ok) {
        root.append(el('div', { class: 'oc-alert oc-alert-error', text: body?.message || 'Failed to load presets' }));
        return;
    }
    const presets = Array.isArray(body.presets) ? body.presets : [];
    populateProviderToolbar(body);
    if (!body.persisted) {
        root.append(el('div', { class: 'oc-alert oc-alert-warn oc-small', text: 'Storage is in-memory (schema not imported). Import db/schema.sql (Settings → Database → ReCreate DB) to persist edits.' }));
    }
    for (const p of presets) {
        root.append(renderDomainPreset(p));
    }
    root.append(el('p', { class: 'oc-muted oc-small', text: 'Tip: paste a custom provider/model id at the bottom of any pool to extend it. Future domains can be added via the API.' }));
}

/**
 * Export / Import controls for the preset set (item 12).
 *
 * The document is what GET /api/domain-presets/export returns, and the import
 * posts it straight back: the server validates every model against the active
 * catalogue, touches only presets that already exist, and writes nothing
 * unless the body says apply - so this bar only has to show the plan and ask.
 *
 * @param {HTMLElement} root
 * @returns {HTMLElement}
 */
function presetTransferBar(root) {
    const status = el('span', {
        id: 'oc-presets-transfer-status',
        class: 'oc-muted',
        role: 'status',
        'aria-live': 'polite',
    });
    const fileInput = el('input', {
        id: 'oc-presets-import-file',
        type: 'file',
        accept: '.json,application/json',
        style: 'display:none',
    });

    const exportButton = el('button', {
        type: 'button',
        class: 'oc-btn',
        id: 'oc-presets-export',
        title: 'Save every preset to a JSON file you can keep',
    }, 'Export presets');

    const importButton = el('button', {
        type: 'button',
        class: 'oc-btn',
        id: 'oc-presets-import',
        title: 'Read a preset document back: it reports what would change, then asks',
    }, 'Import presets');

    exportButton.addEventListener('click', async () => {
        exportButton.disabled = true;
        status.textContent = 'Exporting ...';
        try {
            const response = await fetch('/api/domain-presets/export', { credentials: 'same-origin' });
            const text = await response.text();
            const saved = JSON.parse(text);
            if (!response.ok || saved.ok !== true) {
                throw new Error(saved.message || ('HTTP ' + response.status));
            }
            const stamp = new Date().toISOString().slice(0, 10);
            const url = URL.createObjectURL(new Blob([text], { type: 'application/json;charset=utf-8' }));
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.setAttribute('download', 'revoap-presets-' + stamp + '.json');
            anchor.style.display = 'none';
            document.body.append(anchor);
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(url);
            status.textContent = 'Exported ' + saved.count + ' preset(s).';
        } catch (error) {
            status.textContent = 'Export failed: ' + (error instanceof Error ? error.message : String(error));
        } finally {
            exportButton.disabled = false;
        }
    });

    importButton.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', async () => {
        const chosen = fileInput.files && fileInput.files[0];
        if (!chosen) return;
        importButton.disabled = true;
        status.textContent = 'Reading ' + chosen.name + ' ...';
        try {
            const document_ = JSON.parse(await chosen.text());
            const dry = await postPresetDocument(document_, false);
            const changes = (dry.plan || []).filter((entry) => entry.action === 'update');
            const skipped = (dry.plan || []).filter((entry) => entry.action === 'skipped');
            const unknown = (dry.plan || []).flatMap((entry) => entry.unknown_models || []);
            if (changes.length === 0) {
                status.textContent = 'Nothing to change: ' + (dry.plan || []).length + ' preset(s) already match.'
                    + (skipped.length ? ' Skipped ' + skipped.length + ' (unknown preset or unusable models).' : '')
                    + (unknown.length ? ' Unknown models: ' + unknown.join(', ') + '.' : '');
                return;
            }
            const summary = changes.map((entry) => {
                const bits = [];
                if ((entry.added || []).length) bits.push('+' + entry.added.join(', '));
                if ((entry.removed || []).length) bits.push('-' + entry.removed.join(', '));
                return entry.slug + ': ' + (bits.join(' ') || 'metadata');
            }).join('\n');
            const question = 'Apply these changes?\n\n' + summary
                + (unknown.length ? '\n\nUnknown models (will not be written): ' + unknown.join(', ') : '')
                + (skipped.length ? '\n\nSkipped presets: ' + skipped.map((e) => e.slug + ' (' + e.reason + ')').join(', ') : '');
            if (!window.confirm(question)) {
                status.textContent = 'Import cancelled; nothing was written.';
                return;
            }
            const applied = await postPresetDocument(document_, true);
            status.textContent = applied.ok === true
                ? 'Imported: ' + ((applied.written || []).join(', ') || 'nothing needed writing') + '.'
                : 'Import failed: ' + JSON.stringify(applied.failed || applied.message || {});
        } catch (error) {
            status.textContent = 'Import failed: ' + (error instanceof Error ? error.message : String(error));
        } finally {
            importButton.disabled = false;
            fileInput.value = '';
        }
    });

    return el('div', { id: 'oc-presets-transfer', class: 'oc-presets-transfer' },
        exportButton, importButton, fileInput, status);
}

/**
 * Post a preset document; `apply` decides whether the server writes or only
 * reports. Kept small so both calls share one shape.
 *
 * @param {object} document_
 * @param {boolean} apply
 * @returns {Promise<object>}
 */
async function postPresetDocument(document_, apply) {
    const response = await fetch('/api/domain-presets/import', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(Object.assign({}, document_, { apply: apply === true })),
    });
    const payload = await response.json().catch(() => null);
    if (!payload || typeof payload !== 'object') {
        throw new Error('the server did not answer with JSON');
    }
    if (payload.ok !== true && apply !== true) {
        throw new Error(payload.message || payload.error || ('HTTP ' + response.status));
    }
    return payload;
}

// v1.8.41 — Domain Models global provider toolbar. Populated once per
// hydrate; the Apply button rewrites every OPEN preset's model rows with a
// diverse pick from the chosen provider's catalog (and points each card's
// arbiter at the provider's first catalog entry). Nothing is saved here —
// the operator reviews the rows and presses "Save preset" per card.
function populateProviderToolbar(body) {
    const select = document.getElementById('oc-domain-provider-select');
    const applyBtn = document.getElementById('oc-domain-provider-apply');
    const status = document.getElementById('oc-domain-provider-status');
    const hint = document.querySelector('#oc-domain-provider-toolbar .oc-muted');
    if (!select || !applyBtn) return;

    domainCatalog = (body && body.catalog && typeof body.catalog === 'object' && !Array.isArray(body.catalog))
        ? body.catalog : {};
    const providers = Array.isArray(body?.providers) ? body.providers : [];
    if (providers.length === 0) {
        if (hint) hint.textContent = 'No providers with configured API keys.';
        applyBtn.disabled = true;
        return;
    }
    // Keep the placeholder option first; each provider is suffixed with
    // " (active)" when it is currently enabled.
    for (const p of providers) {
        if (!p || typeof p.provider !== 'string' || p.provider === '') continue;
        select.append(el('option', {
            value: p.provider,
            text: p.provider + (p.active === true ? ' (active)' : ''),
        }));
    }
    applyBtn.disabled = false;

    applyBtn.addEventListener('click', () => {
        const provider = select.value;
        if (!provider) { status.textContent = 'Select a provider first.'; return; }
        const cards = document.querySelectorAll('#oc-domain-presets-root details.oc-domain-preset[open]');
        if (!cards || cards.length === 0) {
            status.textContent = 'Open a preset (Default / Mathematics / Physics) first.';
            return;
        }
        const entries = Array.isArray(domainCatalog[provider]) ? domainCatalog[provider] : [];
        if (entries.length === 0) {
            status.textContent = `Provider ${provider} has no catalog entries.`;
            return;
        }
        // One diverse pick per row of the LARGEST open preset; every open
        // card shares the same picks array (cycling when a card has more
        // rows than picks).
        let rowCount = 0;
        for (const card of cards) {
            const tbody = card.querySelector('tbody');
            if (tbody) rowCount = Math.max(rowCount, tbody.querySelectorAll('tr').length);
        }
        if (rowCount === 0) {
            status.textContent = 'Open preset has no model rows to assign.';
            return;
        }
        const picks = pickDiverseModels(
            entries.map((e) => ({ provider, model: e.model, label: e.label, active: e.active })),
            rowCount,
        );
        for (const card of cards) {
            const tbody = card.querySelector('tbody');
            if (!tbody) continue;
            Array.from(tbody.querySelectorAll('tr')).forEach((tr, i) => {
                const inputs = tr.querySelectorAll('input');
                if (inputs.length < 5) return;
                const pick = picks[i % picks.length];
                inputs[2].value = provider;         // provider cell
                inputs[3].value = pick.model;       // model cell
                inputs[4].value = pick.label || ''; // label cell
            });
            const arbProv = card.querySelector('[data-oc-arb-provider]');
            const arbMod  = card.querySelector('[data-oc-arb-model]');
            if (arbProv) arbProv.value = provider;
            if (arbMod)  arbMod.value = entries[0].model;
        }
        status.textContent = `Provider reassigned to ${provider} in ${cards.length} preset(s). Review and press "Save preset".`;
    });
}

/**
 * Push a preset's consensus pool into the Chat sidebar's model selector.
 *
 * The sidebar persists its own selection in localStorage and app.js reads
 * it at submit time, so the panel is the single source of truth for "what
 * L1 will call" — writing the preset into it is what makes the choice
 * effective without a reload. Reported inline, including the honest
 * cases: an empty preset, a preset whose models are not currently
 * available, and no selector at all (Chat sidebar never initialised).
 *
 * @param {object|null} preset DomainPreset payload from the API
 */
function applyPresetToPool(preset) {
    const out = document.getElementById('oc-domain-pool-status');
    if (!out) return;
    const label = preset && (preset.name || preset.slug) ? (preset.name || preset.slug) : 'preset';
    const models = preset && Array.isArray(preset.models) ? preset.models : [];
    const result = applyPresetToSelection(models);
    clear(out);
    if (result === null) {
        out.append('Consensus pool not available on this page — open the Chat tab and reload, then apply again.');
        return;
    }
    if (result.empty) {
        out.append(`“${label}” defines no active consensus models, so your pool was left unchanged.`);
        return;
    }
    if (result.applied === 0) {
        out.append(`“${label}”: none of its models is available from a configured connector, so your pool was left unchanged.`);
        return;
    }
    out.append(`Your consensus pool now matches “${label}”: ${result.applied} model${result.applied === 1 ? '' : 's'}.`);
    if (result.skipped.length > 0) {
        out.append(` ${result.skipped.length} model${result.skipped.length === 1 ? ' is' : 's are'} not available and ${
            result.skipped.length === 1 ? 'was' : 'were'} skipped: `);
        out.append(el('span', { class: 'oc-mono', text: result.skipped.join(', ') }));
        out.append('.');
    }
}

function renderDomainPreset(p) {
    const arbiter = p.arbiter || null;
    const status = el('span', { class: 'oc-status' });
    const wrap = el('details', { class: 'oc-domain-preset', open: p.slug === 'default' ? '' : null },
        el('summary', {},
            el('strong', { text: p.name || p.slug }),
            ' ',
            el('span', { class: 'oc-mono oc-small', text: p.slug }),
            p.is_system ? el('span', { class: 'oc-tag oc-tag-system', text: 'system' }) : null,
        ),
        el('p', { class: 'oc-muted oc-small', text: p.description || '' }),
    );
    const list = el('table', { class: 'oc-models-table' },
        el('thead', {}, el('tr', {},
            el('th', { text: 'Selected' }),
            el('th', { text: 'Active' }),
            el('th', { text: 'Provider' }),
            el('th', { text: 'Model' }),
            el('th', { text: 'Label' }),
        )),
    );
    // Every field in this table needs an id of its own: a page of id-less
    // inputs produces one browser issue per field, and the table is generated.
    let monitorFieldSeq = 0;

    const tbody = el('tbody', {});
    const rows = Array.isArray(p.models) ? p.models : [];
    for (const m of rows) {
        const row = el('tr', {});
        const selChk = el('input', { id: `oc-monitor-selected-${++monitorFieldSeq}`, type: 'checkbox', checked: m.selected !== false });
        const actChk = el('input', { id: `oc-monitor-active-${++monitorFieldSeq}`, type: 'checkbox', checked: m.active !== false });
        const prov = el('input', { id: `oc-monitor-provider-${++monitorFieldSeq}`, type: 'text', value: m.provider || '', class: 'oc-mono' });
        const mod  = el('input', { id: `oc-monitor-model-${++monitorFieldSeq}`, type: 'text', value: m.model || '',    class: 'oc-mono' });
        const lab  = el('input', { id: `oc-monitor-label-${++monitorFieldSeq}`, type: 'text', value: m.label || '' });
        row.append(
            el('td', {}, selChk),
            el('td', {}, actChk),
            el('td', {}, prov),
            el('td', {}, mod),
            el('td', {}, lab),
        );
        row._read = () => ({
            selected: selChk.checked, active: actChk.checked,
            provider: prov.value.trim(), model: mod.value.trim(), label: lab.value.trim(),
        });
        tbody.append(row);
    }
    list.append(tbody);

    const addBtn = el('button', {
        class: 'oc-button-ghost', type: 'button',
        onClick: () => {
            const row = el('tr', {});
            const selChk = el('input', { id: `oc-monitor-selected-${++monitorFieldSeq}`, type: 'checkbox', checked: true });
            const actChk = el('input', { id: `oc-monitor-active-${++monitorFieldSeq}`, type: 'checkbox', checked: true });
            const prov = el('input', { id: `oc-monitor-new-provider-${++monitorFieldSeq}`, type: 'text', class: 'oc-mono', placeholder: 'openrouter' });
            const mod  = el('input', { id: `oc-monitor-new-model-${++monitorFieldSeq}`, type: 'text', class: 'oc-mono', placeholder: 'provider/model-id' });
            const lab  = el('input', { id: `oc-monitor-new-label-${++monitorFieldSeq}`, type: 'text', placeholder: 'Optional label' });
            row.append(el('td', {}, selChk), el('td', {}, actChk), el('td', {}, prov), el('td', {}, mod), el('td', {}, lab));
            row._read = () => ({ selected: selChk.checked, active: actChk.checked, provider: prov.value.trim(), model: mod.value.trim(), label: lab.value.trim() });
            tbody.append(row);
        },
    }, '+ Add model');

    // v1.8.41: the arbiter inputs carry data attributes so the global
    // provider toolbar's Apply handler can find and rewrite them.
    const arbProv = el('input', { id: 'oc-arbiter-provider', type: 'text', class: 'oc-mono', value: arbiter?.provider || 'openrouter', 'data-oc-arb-provider': '' });
    const arbMod  = el('input', { id: 'oc-arbiter-model', type: 'text', class: 'oc-mono', value: arbiter?.model || '', 'data-oc-arb-model': '' });
    const arbRole = el('input', { id: 'oc-arbiter-role', type: 'text', value: arbiter?.role || 'main' });

    const skill = el('input', { id: 'oc-preset-skill-slug', type: 'text', class: 'oc-mono', value: p.skill_slug || '', placeholder: 'optional skill slug (e.g. mathematics-reasoning)' });

    const readModels = () => Array.from(tbody.children).map(r => r._read?.()).filter(m => m && m.provider && m.model);
    const readArbiter = () => (arbMod.value.trim()
        ? { provider: arbProv.value.trim(), model: arbMod.value.trim(), role: arbRole.value.trim() || 'main' }
        : null);

    const saveBtn = el('button', {
        class: 'oc-button-primary', type: 'button',
        onClick: async () => {
            status.textContent = 'Saving…';
            const models = readModels();
            const arbiter = readArbiter();
            const skillSlug = skill.value.trim() || null;
            const res = await api(`/api/domain-presets/${encodeURIComponent(p.slug)}/select`, {
                method: 'POST', body: { models, arbiter },
            });
            if (!res.ok || (res.body && res.body.error)) {
                status.textContent = describeSaveFailure(res);
                return;
            }
            const saveMeta = await api('/api/domain-presets', {
                method: 'POST',
                body: { slug: p.slug, name: p.name, description: p.description, skill_slug: skillSlug, is_system: !!p.is_system, models, arbiter },
            });
            if (!saveMeta.ok || (saveMeta.body && saveMeta.body.error)) {
                status.textContent = describeSaveFailure(saveMeta);
                return;
            }
            // `persisted:false` is the in-memory fallback (no domain_presets
            // table): the API accepted the write, but it will not survive the
            // request. Saying "Saved." there was the same lie as the settings
            // buttons used to tell.
            const savedInMemory = saveMeta.body && saveMeta.body.persisted === false;
            status.textContent = savedInMemory
                ? `Saved in memory only: ${(saveMeta.body.warning || 'the database tables are missing')}`
                : 'Saved.';
            // v1.8.49 — the preset the operator just defined is the one
            // they mean to run, so mirror it into the Chat pool. Uses the
            // preset the select response returned rather than the local
            // form state, so what is applied is what the server stored.
            applyPresetToPool(res.body?.preset || { slug: p.slug, name: p.name, models });
        },
    }, 'Save preset');

    // v1.8.49 — "use this consensus" without editing it: persists the pool
    // as the consensus type for this domain and mirrors it into the Chat
    // sidebar in one action.
    const useBtn = el('button', {
        class: 'oc-button-secondary', type: 'button',
        onClick: async () => {
            status.textContent = 'Applying…';
            const res = await api(`/api/domain-presets/${encodeURIComponent(p.slug)}/select`, {
                method: 'POST', body: { models: readModels(), arbiter: readArbiter() },
            });
            if (!res.ok || (res.body && res.body.error)) {
                status.textContent = describeSaveFailure(res);
                return;
            }
            status.textContent = res.body && res.body.persisted === false
                ? `Applied to your consensus pool, but stored in memory only: ${res.body.warning || 'no database tables'}.`
                : 'Applied to your consensus pool.';
            applyPresetToPool(res.body?.preset || null);
        },
    }, 'Use as my consensus pool');

    wrap.append(list, el('div', { class: 'oc-actions' }, addBtn));
    wrap.append(
        el('h4', { text: 'Arbiter / main / checker' }),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' }, el('label', { for: 'oc-arbiter-provider', text: 'Provider' }), arbProv),
            el('div', { class: 'oc-field' }, el('label', { for: 'oc-arbiter-model', text: 'Model' }), arbMod),
            el('div', { class: 'oc-field' }, el('label', { for: 'oc-arbiter-role', text: 'Role' }), arbRole),
        ),
        el('h4', { text: 'Linked skill (auto-applied on route)' }),
        el('div', { class: 'oc-field' }, skill),
        el('div', { class: 'oc-actions' }, saveBtn, useBtn, status),
    );
    return wrap;
}

// ===== Request Analyzer ===============================================
function sectionAnalyzer() {
    return el('div', { class: 'oc-card', id: 'oc-analyzer-card' },
        el('h2', { text: 'Request Analyzer' }),
        el('p', { class: 'oc-muted oc-small' },
            'Optional pre-pipeline stage. Classifies the query, suggests an expert persona for every council role, and routes math/physics queries to the matching preset above. Default model: ',
            el('span', { class: 'oc-mono', text: 'openrouter / openai/gpt-5.5' }),
            '. The analyzer never logs the raw query body. This is also the model used to assign models during an actualization run.'),
        el('div', { id: 'oc-analyzer-root' },
            el('p', { class: 'oc-muted', text: 'Loading…' })),
    );
}

async function hydrateAnalyzer() {
    const root = document.getElementById('oc-analyzer-root');
    if (!root) return;
    const { ok, body } = await api('/api/analyzer/settings');
    clear(root);
    if (!ok) {
        root.append(el('div', { class: 'oc-alert oc-alert-error', text: body?.message || 'Failed to load analyzer settings' }));
        return;
    }
    const status = el('span', { class: 'oc-status' });
    const ids = { enabled: 'an-en', prov: 'an-prov', model: 'an-model' };
    const probeStatus = el('div', { class: 'oc-muted oc-small' });
    const probeOut = el('pre', { class: 'oc-pre', hidden: true });
    root.append(
        el('div', { class: 'oc-checkbox' },
            el('input', { type: 'checkbox', id: ids.enabled, checked: !!body.enabled }),
            el('label', { for: ids.enabled, text: 'Enable request analyzer' }),
        ),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.prov, text: 'Provider' }),
                el('input', { type: 'text', id: ids.prov, class: 'oc-mono', value: body.provider || 'openrouter' })),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.model, text: 'Model' }),
                el('input', { type: 'text', id: ids.model, class: 'oc-mono', value: body.model || 'openai/gpt-5.5' })),
        ),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-primary', type: 'button',
                onClick: async () => {
                    status.textContent = 'Saving…';
                    const payload = {
                        enabled: document.getElementById(ids.enabled).checked,
                        provider: document.getElementById(ids.prov).value.trim(),
                        model: document.getElementById(ids.model).value.trim(),
                    };
                    const res = await api('/api/analyzer/settings', { method: 'POST', body: payload });
                    if (!reportSave(status, res, 'Saved.')) { return; }
                    // The analyzer is read by the pipeline on every request, so
                    // "Saved" here has to mean the file says so. The server
                    // echoes what is on disk; a mismatch is reported instead
                    // of being papered over (the reported bug: the button said
                    // Saved while the form reverted on reload).
                    const storedModel = persistedValue(res.body, 'analyzer.model');
                    const storedProvider = persistedValue(res.body, 'analyzer.provider');
                    if (typeof storedModel === 'string' && storedModel !== payload.model) {
                        status.textContent = `NOT saved: the settings file still has model "${storedModel}" `
                            + `(you sent "${payload.model}"). Nothing else changed.`;
                        return;
                    }
                    if (typeof storedProvider === 'string' && storedProvider !== payload.provider) {
                        status.textContent = `NOT saved: the settings file still has provider "${storedProvider}".`;
                        return;
                    }
                    status.textContent = `Saved — analyzer will use ${payload.provider} / ${payload.model}.`;
                },
            }, 'Save analyzer settings'),
            status,
        ),
        el('h3', { text: 'Probe' }),
        el('p', { class: 'oc-muted oc-small', text: 'Classify a sample query without running the pipeline. The response shows the domain, persona, signals and source (analyzer vs heuristic).' }),
        el('div', { class: 'oc-field' },
            el('label', { for: 'an-probe', text: 'Sample query' }),
            el('textarea', { id: 'an-probe', rows: '3', placeholder: 'e.g. "Solve d²y/dx² + y = 0 with y(0)=1, y\'(0)=0"' }),
        ),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-ghost', type: 'button',
                onClick: async () => {
                    const q = document.getElementById('an-probe').value.trim();
                    if (!q) { probeStatus.textContent = 'Enter a query first.'; return; }
                    probeStatus.textContent = 'Classifying…';
                    const res = await api('/api/analyzer/classify', { method: 'POST', body: { query: q } });
                    probeOut.hidden = false;
                    probeOut.textContent = JSON.stringify(res.body || res, null, 2);
                    probeStatus.textContent = res.ok ? `domain=${res.body.domain} confidence=${(res.body.confidence ?? 0).toFixed(2)} source=${res.body.source}` : `Failed (${res.status})`;
                },
            }, 'Classify'),
            probeStatus,
        ),
        probeOut,
    );
}

// ===== Providers / API keys ===========================================
function sectionProviders() {
    const s = state.settings.providers || {};
    const card = el('div', { class: 'oc-card', id: 'oc-providers-card' },
        el('h2', { text: 'Providers / API keys' }),
        el('p', { class: 'oc-muted oc-small', text: 'Keys are written to .{provider}.key files at the project root with 0600 permissions. Submit blank to keep the current value.' }),
    );
    for (const provider of ['openrouter', 'bothub']) {
        const data = s[provider] || {};
        const activeId = `prov-${provider}-active`;
        const baseId   = `prov-${provider}-base`;
        const keyId    = `prov-${provider}-key`;
        const status   = el('span', { class: 'oc-status' });
        const sourceLbl = data.source === 'file' ? 'file' : data.source === 'env' ? 'env' : data.source === 'config' ? 'config' : 'none';
        card.append(
            el('h3', { text: provider }),
            el('div', { class: 'oc-field-row' },
                el('div', { class: 'oc-field' },
                    el('label', { for: baseId, text: 'Base URL' }),
                    el('input', { type: 'url', id: baseId, value: data.base_url || '' }),
                ),
                el('div', { class: 'oc-field' },
                    el('label', { for: keyId, text: 'API key' }),
                    el('input', {
                        type: 'password', id: keyId,
                        placeholder: data.has_key ? `${data.masked} (source: ${sourceLbl})` : 'paste new key',
                        autocomplete: 'off',
                    }),
                    el('span', { class: 'oc-field-hint', text: data.has_key ? `Currently set (source: ${sourceLbl}). Leave blank to keep.` : 'Not configured.' }),
                ),
            ),
            el('div', { class: 'oc-checkbox' },
                el('input', { type: 'checkbox', id: activeId, checked: !!data.active }),
                el('label', { for: activeId, text: 'Active' }),
            ),
            el('div', { class: 'oc-actions' },
                el('button', {
                    class: 'oc-button-primary',
                    type: 'button',
                    onClick: async () => {
                        status.textContent = 'Saving…';
                        const payload = {};
                        payload[provider] = {
                            active: document.getElementById(activeId).checked,
                            base_url: document.getElementById(baseId).value.trim(),
                            api_key: document.getElementById(keyId).value,
                        };
                        const res = await api('/api/settings/providers', { method: 'POST', body: payload });
                        if (!reportSave(status, res, 'Saved.')) {
                            return;
                        }
                        document.getElementById(keyId).value = '';
                        await loadAndRender();
                    },
                }, 'Save'),
                el('button', {
                    class: 'oc-button-secondary',
                    type: 'button',
                    onClick: async () => {
                        status.textContent = 'Testing…';
                        const candidate = document.getElementById(keyId).value;
                        const res = await api('/api/settings/providers/test', {
                            method: 'POST',
                            body: {
                                provider,
                                api_key: candidate,
                                base_url: document.getElementById(baseId).value.trim(),
                            },
                        });
                        if (res.body?.ok) {
                            status.textContent = `OK — ${res.body.message} (${res.body.models_count} models)`;
                        } else {
                            status.textContent = `Failed — ${res.body?.message || res.status}`;
                        }
                    },
                }, 'Test'),
                el('button', {
                    class: 'oc-button-secondary',
                    type: 'button',
                    onClick: async () => {
                        status.textContent = 'Importing…';
                        const res = await api('/api/settings/models/import', { method: 'POST', body: { provider } });
                        const body = res.body || {};
                        if (!res.ok || body.error || body.ok === false) {
                            status.textContent = describeSaveFailure(res);
                            return;
                        }
                        // The server persists the imported catalogue into the
                        // same list "Save models" writes (see
                        // SettingsController::importModels). `lastImport` only
                        // feeds the add-model dropdown with ids beyond the
                        // row cap; the rows themselves come from the reload,
                        // so they survive F5 like everything else.
                        state.lastImport = {
                            provider,
                            catalog: body.catalog || [],
                            popular: body.popular || [],
                            imported: body.imported || null,
                        };
                        const imported = body.imported || {};
                        const bits = [`Loaded ${body.count} models from ${provider}`];
                        if (typeof imported.added === 'number') {
                            bits.push(`${imported.added} new row(s) stored`);
                        }
                        if (imported.duplicates) {
                            bits.push(`${imported.duplicates} already in the catalogue`);
                        }
                        if (imported.truncated) {
                            bits.push(`${imported.truncated} left out — the catalogue is capped at ${imported.limit} rows`);
                        }
                        // Catalogued, but flagged unusable for the pool:
                        // saying so here is the difference between "why does
                        // this model never answer?" and knowing.
                        const batchRows = Array.isArray(imported.batch) ? imported.batch : [];
                        if (batchRows.length > 0) {
                            bits.push(`${batchRows.length} batch-only row(s) marked unusable for the synchronous `
                                + `pool: ${batchRows.slice(0, 3).join(', ')}${batchRows.length > 3 ? '…' : ''}`);
                        }
                        status.textContent = bits.join('; ') + '.';
                        await loadAndRender();
                    },
                }, 'Import models'),
                status,
            ),
        );
    }
    return card;
}

// ===== Model catalogue ================================================
function sectionModels() {
    const s = state.settings.models || { list: [], default_selected: [] };
    const recommended = state.settings.recommended_models || [];
    const status = el('span', { class: 'oc-status' });
    const tbody = el('tbody');
    // Every rendered row, so the filters can walk the list without the DOM
    // (and so a filtered-out row is never mistaken for a deleted one).
    const rows = [];
    const filterState = { text: '', onlyNewer: false };
    /** Pending debounce handle for the filter box. */
    let filterTimer = null;
    const filterNote = el('div', { class: 'oc-muted oc-small', id: 'oc-models-filter-note' });

    function rowFor(m) {
        const tr = el('tr');
        tr.dataset.provider = m.provider;
        tr.dataset.model = m.model;
        // Batch-only ids are served by the asynchronous Batch API, so they
        // can be catalogued but never activated: L1/L4 dispatch synchronous
        // calls, and a batch id there fails at dispatch time (see
        // AbstractConnector::assertSynchronousModel()). The row says so
        // instead of offering a checkbox that could never take effect.
        const batch = isBatchId(m.model);
        const batchReason = batch ? batchPoolRejection(m.model) : '';
        if (batch) {
            tr.dataset.batchOnly = 'true';
            tr.title = batchReason;
        }
        const activeAttrs = {
        id: `oc-monitor-row-${m.provider}/${m.model}/active`,
        type: 'checkbox', checked: !!m.active && !batch,
    };
        if (batch) {
            activeAttrs.disabled = true;
            activeAttrs.title = batchReason;
        }
        const activeCb = el('input', activeAttrs);
        const provInput = el('input', {
        id: `oc-monitor-row-${m.provider}/${m.model}/provider`,
        type: 'text', value: m.provider, size: '10',
    });
        const modelInput = el('input', {
        id: `oc-monitor-row-${m.provider}/${m.model}/model`,
        type: 'text', value: m.model, size: '36',
    });
        const newLabel = el('span', { class: 'oc-muted oc-small' });
        const modelCell = el('td', {}, modelInput);
        const badge = batchBadgeNode(m.model);
        if (badge) { modelCell.append(badge); }
        tr.append(
            el('td', {}, activeCb),
            el('td', {}, provInput),
            modelCell,
            el('td', {}, newLabel),
            el('td', {},
                el('button', {
                    class: 'oc-button-secondary',
                    type: 'button',
                    onClick: () => {
                        tr.remove();
                        const idx = rows.indexOf(tr);
                        if (idx >= 0) rows.splice(idx, 1);
                        applyRowFilter();
                    },
                }, 'Remove'),
            ),
        );
        tr._activeCheckbox = activeCb;
        tr._newerLabel = newLabel;
        return tr;
    }

    for (const m of s.list) {
        const tr = rowFor(m);
        rows.push(tr);
        tbody.append(tr);
    }

    function addRow(prefill = { provider: 'openrouter', model: '', active: true }) {
        const tr = rowFor(prefill);
        rows.push(tr);
        tbody.append(tr);
        applyRowFilter();
    }

    /**
     * Walk the visible model ids without the row being tested — the
     * "newer version" question is always about the *other* rows.
     */
    function modelIdsExcept(tr) {
        return rows.filter((r) => r !== tr).map((r) => r.dataset.model);
    }

    /**
     * Apply both filters.
     *
     * Filtered-out rows are HIDDEN, never removed: `saveModels` reads the
     * table, so deleting a row from the DOM to filter it would delete it
     * from the saved catalogue. The counter below the table says how many
     * rows are hidden and how many of those carry the active flag, so
     * "I only see one row" never reads as "my other models are gone".
     */
    function applyRowFilter() {
        const filter = normaliseModelFilter(filterState.text);
        let hidden = 0;
        let hiddenActive = 0;
        for (const tr of rows) {
            const row = { provider: tr.dataset.provider || '', model: tr.dataset.model || '' };
            let visible = modelMatchesFilter(row, filter);
            let newer = null;
            if (visible && filterState.onlyNewer) {
                newer = newerVersionOf(row.model, modelIdsExcept(tr));
                visible = newer !== null;
            }
            tr.hidden = !visible;
            if (tr._newerLabel) {
                tr._newerLabel.textContent = newer && visible ? `newer: ${newer}` : '';
            }
            if (!visible) {
                hidden += 1;
                if (tr._activeCheckbox && tr._activeCheckbox.checked) hiddenActive += 1;
            }
        }
        clear(filterNote);
        if (hidden > 0) {
            filterNote.append(el('span', {
                text: `${hidden} model${hidden === 1 ? '' : 's'} hidden by filter — ${
                    hiddenActive} of them active and still saved.`,
            }));
        } else if (filterState.onlyNewer || filter !== '') {
            filterNote.append(el('span', { text: 'No model is hidden by the current filter.' }));
        }
    }

    const filterInput = el('input', {
        type: 'search',
        class: 'oc-models-filter-input',
        id: 'oc-models-filter-text',
        placeholder: 'Filter by name…',
        'aria-label': 'Filter the catalogue rows by name',
        oninput: (e) => {
            const value = e.target.value;
            if (filterTimer) clearTimeout(filterTimer);
            filterTimer = setTimeout(() => {
                filterTimer = null;
                filterState.text = value;
                applyRowFilter();
            }, FILTER_DEBOUNCE_MS);
        },
    });
    const newerToggle = el('input', {
        type: 'checkbox',
        id: 'oc-models-filter-newer',
        onchange: (e) => {
            filterState.onlyNewer = e.target.checked === true;
            applyRowFilter();
        },
    });
    const filterBar = el('div', { class: 'oc-models-filter' },
        filterInput,
        el('label', {
            class: 'oc-checkbox oc-models-filter-newer',
            for: 'oc-models-filter-newer',
            title: 'Show only the rows another row in this list supersedes: '
                + 'a newer release of the same model line (gpt-5.4 → gpt-5.5) is present below.',
        }, newerToggle, el('span', { text: 'Only models with a newer version' })),
    );

    // v1.8.41: when a provider import has run, the dropdown also lists the
    // imported catalog (incl. non-versioned/alias slugs) after the curated
    // recommendations, deduplicated by provider|model — curated note wins.
    const recOptions = [];
    const seen = new Set();
    for (const r of recommended) {
        const key = `${r.provider}|${r.model}`;
        if (seen.has(key)) continue;
        seen.add(key);
        recOptions.push({ provider: r.provider, model: r.model, note: r.note || '' });
    }
    if (state.lastImport && Array.isArray(state.lastImport.catalog)) {
        for (const entry of state.lastImport.catalog) {
            const model = entry && typeof entry === 'object' && entry.id ? String(entry.id) : '';
            if (!model) continue;
            const key = `${state.lastImport.provider}|${model}`;
            if (seen.has(key)) continue;
            seen.add(key);
            recOptions.push({
                provider: state.lastImport.provider,
                model,
                note: (entry.name && typeof entry.name === 'string') ? entry.name : model,
            });
        }
    }
    const recSelect = el('select', { id: 'oc-preset-recommended-select' });
    recSelect.append(el('option', { value: '', text: '— add recommended —' }));
    for (const o of recOptions) {
        // A batch-only id stays visible (it is what the provider serves)
        // but cannot be added as a live row: it would come in inactive,
        // badged, and unable to run in the synchronous pool.
        const batch = isBatchId(o.model);
        const attrs = {
            value: `${o.provider}|${o.model}`,
            text: `${o.provider} / ${o.model} (${o.note || ''})`
                + (batch ? ' — batch-only, not for the synchronous pool' : ''),
        };
        if (batch) {
            attrs.disabled = true;
            attrs.title = batchPoolRejection(o.model);
        }
        recSelect.append(el('option', attrs));
    }
    recSelect.addEventListener('change', () => {
        if (!recSelect.value) return;
        const [provider, model] = recSelect.value.split('|');
        addRow({ provider, model, active: true });
        recSelect.value = '';
    });

    const importNote = state.lastImport
        ? el('div', { class: 'oc-alert oc-alert-ok' },
            `Last import: ${state.lastImport.provider} — ${state.lastImport.catalog.length} models. `,
            state.lastImport.imported
                ? `${state.lastImport.imported.added} new row(s) stored in the catalogue; `
                    + `${state.lastImport.imported.duplicates} already present. `
                : '',
            'The dropdown also lists imported slugs, including non-versioned aliases.')
        : null;

    return el('div', { class: 'oc-card', id: 'oc-models-card' },
        el('h2', { text: 'Models' }),
        el('p', { class: 'oc-muted oc-small', text: 'Active models drive consensus. Provider must match a configured connector.' }),
        importNote,
        filterBar,
        el('table', { class: 'oc-models-table' },
            el('thead', {}, el('tr', {},
                el('th', { text: 'Active' }),
                el('th', { text: 'Provider' }),
                el('th', { text: 'Model' }),
                el('th', { text: 'Newer' }),
                el('th', { text: '' }),
            )),
            tbody,
        ),
        filterNote,
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-secondary',
                type: 'button',
                onClick: () => addRow(),
            }, 'Add empty row'),
            recSelect,
            el('button', {
                class: 'oc-button-primary',
                type: 'button',
                onClick: async () => {
                    status.textContent = 'Saving…';
                    const models = [];
                    for (const tr of rows) {
                        const provider = tr.querySelectorAll('input')[1].value.trim().toLowerCase();
                        const model = tr.querySelectorAll('input')[2].value.trim();
                        // A batch-only id is never activated, whatever the
                        // checkbox says: the pool dispatches synchronously.
                        // The row's checkbox is disabled for exactly this
                        // reason, so a hand-edited row cannot sneak past it.
                        const active = isBatchId(model) ? false : tr._activeCheckbox.checked;
                        if (!provider || !model) continue;
                        models.push({ provider, model, active });
                    }
                    const res = await api('/api/settings/models', { method: 'POST', body: { models } });
                    if (!reportSave(status, res, `Saved ${models.length} model${models.length === 1 ? '' : 's'}.`)) {
                        return;
                    }
                    // The server echoes the file's own content. Comparing it
                    // with the table turns "Saved." into a claim the page can
                    // support — and names the rows that did not stick.
                    const stored = persistedValue(res.body, 'models');
                    if (Array.isArray(stored)) {
                        const storedActive = new Map();
                        for (const row of stored) {
                            if (row && typeof row === 'object') {
                                storedActive.set(`${row.provider}|${row.model}`, row.active === true);
                            }
                        }
                        const missing = models.filter((m) => !storedActive.has(`${m.provider}|${m.model}`)
                            || storedActive.get(`${m.provider}|${m.model}`) !== m.active);
                        if (missing.length > 0) {
                            status.textContent = `Sent ${models.length} model(s), but the stored catalogue differs for ${
                                missing.length}: ${missing.slice(0, 3).map((m) => `${m.provider}/${m.model}`).join(', ')
                            }${missing.length > 3 ? '…' : ''}. Reload the page and check the file.`;
                            return;
                        }
                    }
                    await loadAndRender();
                },
            }, 'Save models'),
            status,
        ),
    );
}

// ===== Boot ===========================================================
void probeStatus();
