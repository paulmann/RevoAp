// tests/harness/chat-stream.mjs — headless checks for the chat stream window.
//
// Run through `php tests/ChatStreamDomTest.php` (which supplies node), or
// directly:
//
//   node --experimental-vm-modules tests/harness/chat-stream.mjs
//
// What is exercised, and how honestly
// -----------------------------------
// Two layers, same as tests/harness/models-tab.mjs:
//
//   1. STATIC — the shipped stylesheet and modules are read as text and
//      asserted on. This is the only way to check the *visual* half of the
//      collapse (the clamp is a CSS rule; no DOM harness can prove that a
//      browser honours `max-height`), and the only way to check that no
//      module reaches for innerHTML.
//
//   2. BEHAVIOURAL — layout.html is parsed into the stub DOM, the REAL
//      modules (assets/js/app.js, assets/js/pipeline.js,
//      assets/js/copy-buttons.js and their imports) are loaded as ES modules
//      through vm.SourceTextModule, and the stream is driven the way the
//      browser drives it: the form is submitted, SSE frames are fed to
//      upsertStepFromEvent, buttons are clicked through the document-level
//      delegation copy-buttons.js installs, and the final answer is
//      rendered.
//
// What is *simulated*, and therefore not proven here:
//   * Layout. tests/harness/dom-stub.mjs answers scrollHeight/clientHeight
//     from a model installed below (`__ocLayout`), not from a renderer. The
//     decision logic — clamp, measure, offer "Show more" only on overflow —
//     is what this exercises; the pixel truth of "four lines" belongs to the
//     browser.
//   * The 4-line budget itself (COLLAPSE_LINES in pipeline.js vs the CSS
//     clamp): only a browser can show that they agree.
//
// A failure prints a `FAIL` line; the process exits non-zero. `innerHTML`
// is enforced twice: statically, and by the stub DOM throwing if any module
// reaches for it at runtime.

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
        console.log(`  FAIL ${label}${detail ? ` — ${detail}` : ''}`);
        failures++;
    }
}

function read(rel) {
    const path = resolve(root, rel);
    return existsSync(path) ? readFileSync(path, 'utf8') : '';
}

const layoutHtml = read('assets/templates/layout.html');
const chatHtml   = read('assets/templates/chat.html');
const pipelineJs = read('assets/js/pipeline.js');
const appJs      = read('assets/js/app.js');
// Comments stripped before any rule-shape assertion: prose about a rule must
// not be able to satisfy (or break) a check about the rule.
const pipelineCss = read('assets/css/components/pipeline.css').replace(/\/\*[\s\S]*?\*\//g, '');

// =====================================================================
console.log('== the shipped stylesheet carries the clamp ==');
// =====================================================================
check('pipeline.css ships the collapse clamp',
    pipelineCss.includes('.oc-collapsible.oc-collapsed'));
check('the clamp is a height + overflow, not a text truncation',
    /\.oc-collapsible\.oc-collapsed\s*\{[^}]*max-height[^}]*overflow:\s*hidden/s.test(pipelineCss),
    'expected max-height and overflow:hidden inside one rule');
check('the clamp uses four lines of the panel line-height',
    /max-height:\s*calc\(4 \* 1\.55em\)/.test(pipelineCss),
    'keep the CSS budget in step with COLLAPSE_LINES');
check('pipeline.css ships the query-card modifier', pipelineCss.includes('.oc-step-query'));
check('pipeline.css ships the collapse toggle', pipelineCss.includes('.oc-collapse-toggle'));
check('pipeline.css ships the jump control', pipelineCss.includes('.oc-jump-final'));
check('the jump control re-asserts display:none when hidden',
    /\.oc-jump-final\[hidden\]\s*\{\s*display:\s*none/.test(pipelineCss),
    'an author display value would otherwise defeat the hidden attribute');
check('the final-answer section declares its scroll-margin',
    /\.oc-final-answer-section\s*\{[^}]*scroll-margin-top/s.test(pipelineCss));

console.log('\n== the modules render with textContent only ==');
check('pipeline.js never mentions innerHTML', !pipelineJs.includes('innerHTML'));
check('app.js never mentions innerHTML', !appJs.includes('innerHTML'));
check('the collapse body is clamped, never truncated',
    !/response_text\s*=\s*[^;]*\.slice\(/.test(pipelineJs),
    'pipeline.js must not shorten response_text before rendering it');
check('visibility of the jump control is not driven by a scroll handler',
    !/addEventListener\(\s*['"]scroll['"]/.test(pipelineJs));
check('pipeline.js asks an IntersectionObserver instead',
    pipelineJs.includes('new IntersectionObserver'));
check('the copy payload is written from the render input',
    /text:\s*copyable\s*\?\s*step\.response_text/.test(pipelineJs));

// =====================================================================
// Behavioural layer
// =====================================================================

// The layout model. One line of text is LINE_PX tall and TEXT_LINE holds
// about what fits across a phone-width card, so the height of a pane is
// derived from its text. A collapsed pane reports a client height capped
// the way a CSS max-height caps it — which is exactly what the real
// measurement reads in a browser.
const LINE_PX = 20;
const TEXT_LINE = 38;
const CLAMP_LINES = 4;

globalThis.__ocLayout = (el) => {
    // A test can switch the whole model off for a while, which is what a
    // pane inside a hidden tab panel reports: no layout at all.
    if (globalThis.__ocNoLayout === true) { return { scrollHeight: 0, clientHeight: 0 }; }
    const text = el.textContent || '';
    const lines = Math.max(1, Math.ceil(text.length / TEXT_LINE));
    const content = lines * LINE_PX;
    const clamped = el.classList.contains('oc-collapsed');
    return {
        scrollHeight: content,
        clientHeight: clamped ? Math.min(content, CLAMP_LINES * LINE_PX) : content,
    };
};

const clipboardWrites = [];

function jsonResponse(body, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: (name) => (String(name).toLowerCase() === 'content-type' ? 'application/json' : null) },
        json: async () => body,
        text: async () => JSON.stringify(body),
    };
}

/** The EventSource surface app.js uses: open, listen, close. Never emits. */
class StubEventSource {
    constructor(url) {
        this.url = url;
        this.readyState = 0;
        this.listeners = new Map();
        StubEventSource.instances.push(this);
    }
    addEventListener(type, handler) {
        if (!this.listeners.has(type)) { this.listeners.set(type, []); }
        this.listeners.get(type).push(handler);
    }
    removeEventListener() {}
    close() { this.readyState = 2; }
}
StubEventSource.CONNECTING = 0;
StubEventSource.OPEN = 1;
StubEventSource.CLOSED = 2;
StubEventSource.instances = [];

// Canned answers. `councilJobs` decides whether the run starts: a 503 is the
// "the request never reached the pipeline" case requirement A2 talks about.
let apiState = {};

async function stubFetch(input, init = {}) {
    const url = String(input);
    apiState.requests.push({ url, method: init.method || 'GET', body: init.body });
    if (url === '/api/connectors')        { return jsonResponse(apiState.connectors ?? { connectors: [], available_models: [], default_selected: [] }); }
    if (url === '/api/consensus/skills')  { return jsonResponse(apiState.consensusSkills ?? { consensus_skills: [] }); }
    if (url === '/api/boot-config')       { return jsonResponse({ pipeline: { execution_mode: 'job' }, ...(apiState.bootConfig ?? {}) }); }
    if (url === '/api/council/jobs')      { return jsonResponse(apiState.councilJobs ?? { ok: true, job_id: 7, session_id: 11, events_url: '/api/council/session/11/events/stream' }, apiState.councilJobsStatus ?? 200); }
    // R-15 — the Save control persists a pool through the domain-preset
    // routes. Canned per scenario so the write, a refusal and a
    // "memory only" save are each exercised rather than assumed.
    if (url.startsWith('/api/domain-presets')) {
        return apiState.domainPresetSave
            ? apiState.domainPresetSave(url, init)
            : jsonResponse({ ok: true, persisted: true });
    }
    return jsonResponse({ ok: true });
}

/** Build the DOM from the REAL templates and load the REAL modules. */
async function boot(options = {}) {
    apiState = { requests: [], ...options };
    clipboardWrites.length = 0;
    StubEventSource.instances.length = 0;

    // index.php substitutes the chat section into the layout before serving
    // it; the harness does the same, so what is parsed is the shipped pair.
    const page = layoutHtml
        .replace('{{chat_section|raw}}', chatHtml)
        .replace(/\{\{[a-z_]+\|raw\}\}/g, '');

    const documentRoot = stub.parseHtml(page);
    const globals = stub.installGlobals(documentRoot, { fetch: stubFetch });

    // A secure context with a clipboard, which is the branch copy-buttons.js
    // prefers: it makes the copy assertions read the string the module
    // actually hands to the platform.
    globalThis.window.isSecureContext = true;
    // logger.js prints every fetch/SSE span to the console by default (the
    // app ships that way on purpose). Silence it through its own documented
    // switch so the check output stays readable.
    globalThis.window.OC_DEBUG = false;
    // Node's own `navigator` is a getter on the global object; the modules
    // under test expect the browser's, so it is replaced wholesale.
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        writable: true,
        value: { clipboard: { writeText: async (text) => { clipboardWrites.push(text); } } },
    });
    globalThis.EventSource = StubEventSource;

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

    const flush = async (rounds = 12) => {
        for (let i = 0; i < rounds; i++) { await new Promise((r) => setImmediate(r)); }
    };

    // layout.html loads copy-buttons.js before app.js so the delegated click
    // listener is installed for the content they create — the same order here.
    const copyModule = await load(resolve(root, 'assets/js/copy-buttons.js'));
    await copyModule.evaluate();
    const pipelineModule = await load(resolve(root, 'assets/js/pipeline.js'));
    const appModule = await load(resolve(root, 'assets/js/app.js'));
    await appModule.evaluate();
    await flush();

    const byId = (id) => documentRoot.getElementById(id);
    const pipelineSection = byId('pipeline-section');

    return {
        documentRoot,
        globals,
        byId,
        pipelineSection,
        pipelineModule: pipelineModule.namespace,
        appModule: appModule.namespace,
        copyModule: copyModule.namespace,
        flush,
        /** Cards in the stream, in DOM order. */
        cards: () => pipelineSection.children,
        /** The floating jump control, which lives on <body>. */
        jump: () => globalThis.document.body.querySelector('.oc-jump-final'),
        observers: globals.observers,
        /** Submit the composer the way the form does. */
        submit(query) {
            const input = byId('chat-input');
            input.value = query;
            byId('chat-form').dispatchEvent({ type: 'submit', preventDefault() {} });
            return input;
        },
        /** Click something, delivered through document-level delegation. */
        click: (target) => globalThis.document.dispatchClick(target),
        clipboardWrites,
        copyGroup: (card) => card.querySelector('.oc-copy-group'),
        copyPlain: (card) => {
            const group = card.querySelector('.oc-copy-group');
            // The literal-text button of the pair. Selected by its own
            // attribute rather than by an order-dependent child index.
            return group ? group.querySelector('.oc-copy-btn[data-copy-mode="text"]') : null;
        },
    };
}

const LONG_ANSWER = 'Long answer. '.repeat(40).trim();   // ~500 chars, ~13 model lines
const SHORT_ANSWER = 'Short answer.';

// =====================================================================
console.log('\n== (a) the question opens the stream ==');
// =====================================================================
{
    const env = await boot();
    const query = 'Why does the consensus stall on the second cycle?';
    const input = env.submit(query);

    // Sampled synchronously: submitQuery() clears the composer before its
    // first await, so this is the state the user sees the instant they press
    // Run, not a later one.
    const cards = env.cards();
    check('the question is the first card of the stream',
        cards.length > 0 && cards[0].classList.contains('oc-step-query'),
        cards.map((c) => c.className).join(' | '));
    check('the composer is emptied on submit', input.value === '', `"${input.value}"`);
    check('the question text is the card body',
        cards[0].textContent.includes(query) && cards[0].querySelector('.oc-query-text').textContent === query);

    await env.flush();

    const card = env.cards()[0];
    check('the card is marked by its own modifier class, in the pipeline chrome',
        card.classList.contains('oc-step') && card.classList.contains('oc-step-query'));
    check('the card says whose text it is', card.textContent.includes('Your question'));
    check('no layer chip: a question is not a pipeline stage',
        card.querySelector('.oc-step-layer') === null && card.getAttribute('data-layer') === null);
    check('no lifecycle status pill', card.querySelector('.oc-step-status') === null);
    check('no model / token statistics', card.querySelector('.oc-step-stats') === null
        && !card.textContent.includes('Model'));
    check('no Statistics or Raw tab', card.querySelectorAll('.oc-tab').length === 0);
    check('the question still gets the copy pair and the share control',
        card.querySelector('.oc-copy-group') !== null
        && card.querySelectorAll('.oc-copy-btn').length === 4
        && card.querySelector('.oc-copy-btn-download') !== null
        && card.querySelector('.oc-copy-btn-share') !== null);

    // A stage card rendered afterwards must land AFTER the question.
    env.pipelineModule.renderStep({ id: 1, layer: 'L1', label: 'L1 · alpha', status: 'completed',
        response_text: SHORT_ANSWER, stats: {}, raw: {} });
    const after = env.cards();
    check('stage cards land after the question, not before it',
        after[0].classList.contains('oc-step-query') && after[1].classList.contains('oc-step'),
        after.map((c) => c.className).join(' | '));

    env.pipelineModule.clearPipeline();
    check('the question leaves with the run', env.cards().length === 0);
}

// =====================================================================
console.log('\n== (a2) a submission that never reached the pipeline gives the text back ==');
// =====================================================================
{
    const env = await boot({
        councilJobs: { ok: false, error: 'job_table_missing', message: 'The durable job queue is not available.' },
        councilJobsStatus: 503,
    });
    const query = 'A question worth retyping?';
    // logger.error writes to the console unconditionally — by design, it is
    // the app's error channel. Captured for the length of this scenario so
    // the report stays readable, and asserted below so the capture cannot
    // hide a silent failure.
    const logged = [];
    const realConsoleError = console.error;
    console.error = (...args) => { logged.push(args.map((a) => String(a)).join(' ')); };

    const input = env.submit(query);
    check('the composer is emptied first', input.value === '');
    await env.flush();
    console.error = realConsoleError;

    check('the question is returned to the composer', input.value === query, `"${input.value}"`);
    check('the status says the text was put back',
        env.byId('chat-status').textContent.includes('put back in the input field'),
        env.byId('chat-status').textContent);
    check('the failure itself is still reported',
        env.byId('chat-status').textContent.includes('durable job queue'),
        env.byId('chat-status').textContent);
    check('the failure was logged, not swallowed', logged.length > 0,
        String(logged.length));
    check('the question card is kept as the record of what was attempted',
        env.cards()[0] && env.cards()[0].classList.contains('oc-step-query'));
}

// =====================================================================
console.log('\n== (b) long stage bodies collapse, short ones do not ==');
// =====================================================================
{
    const env = await boot();
    const { renderStep } = env.pipelineModule;

    const longCard = renderStep({ id: 1, dom_key: 'step-1', layer: 'L1', label: 'L1 · alpha',
        status: 'completed', response_text: LONG_ANSWER, stats: {}, raw: {} });
    const longPane = longCard.querySelector('.oc-step-response');
    check('a long body is clamped by default',
        longPane.classList.contains('oc-collapsible') && longPane.classList.contains('oc-collapsed'));
    check('the full text is still in the DOM (the clamp is visual)',
        longPane.textContent === LONG_ANSWER);
    const toggle = longCard.querySelector('.oc-collapse-toggle');
    check('a long body gets exactly one toggle', toggle !== null
        && longCard.querySelectorAll('.oc-collapse-toggle').length === 1);
    check('the toggle starts collapsed and says so',
        toggle.textContent === 'Show more' && toggle.getAttribute('aria-expanded') === 'false');
    check('the toggle is labelled and points at the body it controls',
        /Show more/.test(toggle.getAttribute('aria-label') || '')
        && toggle.getAttribute('aria-controls') === longPane.id
        && longPane.id !== '');
    check('the toggle is a real button (keyboard-operable by default)',
        toggle.tagName === 'BUTTON' && toggle.getAttribute('type') === 'button');

    const shortCard = renderStep({ id: 2, dom_key: 'step-2', layer: 'L2', label: 'L2 · alpha',
        status: 'completed', response_text: SHORT_ANSWER, stats: {}, raw: {} });
    const shortPane = shortCard.querySelector('.oc-step-response');
    check('a short body is not clamped', !shortPane.classList.contains('oc-collapsed')
        && !shortPane.classList.contains('oc-collapsible'));
    check('a short body gets no toggle', shortCard.querySelector('.oc-collapse-toggle') === null);

    toggle.click();
    check('clicking expands the body', !longPane.classList.contains('oc-collapsed'));
    check('the toggle flips its label and state',
        toggle.textContent === 'Show less' && toggle.getAttribute('aria-expanded') === 'true'
        && /Show less/.test(toggle.getAttribute('aria-label') || ''));
    // Requirement: expanding must not yank the page around. The structural
    // reason is that the control lives in the header, ABOVE the body it
    // grows, so the point the user just clicked does not move; the check
    // below pins that placement and the absence of any scroll call.
    check('the toggle sits above the body it grows, so nothing moves under the pointer',
        longCard.children[0] === longCard.querySelector('.oc-step-header')
        && longCard.querySelector('.oc-step-header').contains(toggle)
        && !longCard.querySelector('.oc-step-header').contains(longPane));
    check('expanding scrolls nothing into view',
        [longCard, longPane, env.pipelineSection, toggle].every((n) => (n.scrollCalls || []).length === 0),
        [longCard, longPane, env.pipelineSection, toggle]
            .map((n) => (n.scrollCalls || []).length).join(','));
    toggle.click();
    check('clicking again collapses it back',
        longPane.classList.contains('oc-collapsed') && toggle.textContent === 'Show more'
        && toggle.getAttribute('aria-expanded') === 'false');

    // --- copying must not lose a word -----------------------------------
    const plain = env.copyPlain(longCard);
    const md = longCard.querySelector('.oc-copy-btn-md');
    check('the copy payload is the full answer, literally',
        plain.dataset.copyValue === LONG_ANSWER && plain.dataset.copyValue.length === LONG_ANSWER.length);
    check('the Markdown payload carries the full answer too',
        (md.dataset.copyMdValue || '').endsWith(LONG_ANSWER));

    env.click(plain);
    await env.flush();
    check('clicking Copy on a collapsed card puts the whole answer on the clipboard',
        clipboardWrites.length === 1 && clipboardWrites[0] === LONG_ANSWER,
        `${clipboardWrites.length} write(s), ${clipboardWrites[0] ? clipboardWrites[0].length : 0} char(s)`);
    check('the collapsed card is still collapsed after the copy',
        longPane.classList.contains('oc-collapsed'));
}

// =====================================================================
console.log('\n== (b2) a live update does not undo the reader\'s expansion ==');
// =====================================================================
{
    const env = await boot();
    const { renderStep, upsertStepFromEvent } = env.pipelineModule;
    const statsFromMeta = (meta, status) => ({ ...meta, status });

    // A step card that is in flight, exactly as L1_STEP_STARTED renders it.
    upsertStepFromEvent({ type: 'L1_STEP_STARTED', step_id: 42,
        payload: { role: 'alpha', model: 'm', provider: 'p' } }, statsFromMeta);
    const placeholder = env.pipelineSection.querySelector('article[data-key="step-42"]');
    check('the running card is the "Awaiting model response…" placeholder',
        placeholder !== null && placeholder.textContent.includes('Awaiting model response'));
    check('a running card gets no toggle (its body is filler, not an answer)',
        placeholder.querySelector('.oc-collapse-toggle') === null);

    // The model answers; the card is rebuilt with a long body.
    upsertStepFromEvent({ type: 'L1_RESPONSE', step_id: 42,
        payload: { role: 'alpha', content: LONG_ANSWER } }, statsFromMeta);
    const answered = env.pipelineSection.querySelector('article[data-key="step-42"]');
    check('exactly one card carries the step id after the update',
        env.pipelineSection.querySelectorAll('article[data-key="step-42"]').length === 1,
        String(env.pipelineSection.querySelectorAll('article[data-key="step-42"]').length));
    const pane = answered.querySelector('.oc-step-response');
    check('the answered card arrives collapsed', pane.classList.contains('oc-collapsed'));

    answered.querySelector('.oc-collapse-toggle').click();
    check('the reader expands it', !pane.classList.contains('oc-collapsed'));

    // A second frame for the same step (a longer body, or a re-emit).
    upsertStepFromEvent({ type: 'L1_RESPONSE', step_id: 42,
        payload: { role: 'alpha', content: LONG_ANSWER + ' More.' } }, statsFromMeta);
    const rebuilt = env.pipelineSection.querySelector('article[data-key="step-42"]');
    const rebuiltPane = rebuilt.querySelector('.oc-step-response');
    check('the update did not collapse the expanded step',
        !rebuiltPane.classList.contains('oc-collapsed'));
    check('the rebuilt card shows the new text and is still one card',
        rebuiltPane.textContent === LONG_ANSWER + ' More.'
        && env.pipelineSection.querySelectorAll('article[data-key="step-42"]').length === 1);
    const rebuiltToggle = rebuilt.querySelector('.oc-collapse-toggle');
    check('the rebuilt toggle keeps the expanded state',
        rebuiltToggle !== null && rebuiltToggle.textContent === 'Show less'
        && rebuiltToggle.getAttribute('aria-expanded') === 'true');

    // A card nobody expanded must stay collapsed across updates.
    upsertStepFromEvent({ type: 'L1_RESPONSE', step_id: 43,
        payload: { role: 'beta', content: LONG_ANSWER } }, statsFromMeta);
    const untouched = env.pipelineSection.querySelector('article[data-key="step-43"]');
    check('the untouched card is not collapsed by its own update',
        untouched.querySelector('.oc-step-response') !== null
        && !untouched.querySelector('.oc-collapse-toggle').classList.contains('oc-collapsed')
        && untouched.querySelector('.oc-step-response').classList.contains('oc-collapsed'));

    // A fresh run must not inherit the previous run's expansion.
    env.pipelineModule.clearPipeline();
    upsertStepFromEvent({ type: 'L1_RESPONSE', step_id: 42,
        payload: { role: 'alpha', content: LONG_ANSWER } }, statsFromMeta);
    check('a new run starts from the collapsed default',
        env.pipelineSection.querySelector('article[data-key="step-42"] .oc-step-response')
            .classList.contains('oc-collapsed'));
}

// =====================================================================
console.log('\n== (b3) a card rendered while the chat panel was hidden ==');
// =====================================================================
{
    // The run streams while the user is on another tab, so the freshly
    // rendered panes have no layout and "does this fit?" has no answer yet.
    globalThis.__ocNoLayout = true;
    const env = await boot();
    const hidden = env.pipelineModule.renderStep({ id: 1, dom_key: 'step-1', layer: 'L1',
        label: 'L1 · alpha', status: 'completed', response_text: LONG_ANSWER, stats: {}, raw: {} });
    const hiddenPane = hidden.querySelector('.oc-step-response');
    check('an unmeasurable body is left alone, not clamped on a guess',
        !hiddenPane.classList.contains('oc-collapsed')
        && hidden.querySelector('.oc-collapse-toggle') === null);

    globalThis.__ocNoLayout = false;
    const deferrer = env.observers[env.observers.length - 1];
    check('the decision was postponed, not dropped',
        deferrer !== undefined && deferrer.targets.includes(hiddenPane));

    deferrer.callback([{ target: hiddenPane, isIntersecting: true }]);
    const toggle = hidden.querySelector('.oc-collapse-toggle');
    check('the body is measured and clamped when it becomes visible',
        hiddenPane.classList.contains('oc-collapsed') && toggle !== null);
    check('the toggle works like any other',
        toggle.getAttribute('aria-controls') === hiddenPane.id && toggle.textContent === 'Show more');
    check('the deferred observer stops watching the card',
        deferrer.targets.length === 0);

    env.pipelineModule.clearPipeline();
    check('a new run drops whatever was still pending', deferrer.targets.length === 0);
}

// =====================================================================
console.log('\n== (c) the final answer is never collapsed ==');
// =====================================================================
{
    const env = await boot();
    // A long stage card is present as well: the point is that the clamp is a
    // stage-card feature, not a "long body" feature that could catch the
    // answer by accident.
    env.pipelineModule.renderStep({ id: 1, layer: 'L1', label: 'L1 · alpha', status: 'completed',
        response_text: LONG_ANSWER, stats: {}, raw: {} });
    env.pipelineModule.renderFinalAnswer({ text: LONG_ANSWER, stats: {}, raw: {} });
    const host = env.byId('final-answer-section');
    check('the final answer body is not clamped',
        host.querySelectorAll('.oc-collapsed').length === 0
        && host.querySelectorAll('.oc-collapsible').length === 0);
    check('the final answer has no collapse toggle',
        host.querySelector('.oc-collapse-toggle') === null);
    const text = host.querySelector('.oc-final-answer-text');
    check('the final answer is rendered in full', text.textContent === LONG_ANSWER);
    check('the final answer keeps its copy pair and the share control',
        host.querySelectorAll('.oc-copy-btn').length === 4
        && host.querySelector('.oc-copy-btn-download') !== null
        && host.querySelector('.oc-copy-btn-share') !== null
        && host.querySelector('.oc-copy-btn[data-copy-mode="text"]').dataset.copyValue === LONG_ANSWER);
}

// =====================================================================
console.log('\n== (d) the jump-to-final-answer control ==');
// =====================================================================
{
    const env = await boot();
    check('no answer yet → no control at all', env.jump() === null);

    env.pipelineModule.renderStep({ id: 1, layer: 'L1', label: 'L1 · alpha', status: 'completed',
        response_text: LONG_ANSWER, stats: {}, raw: {} });
    check('a run in flight with no answer has no control either', env.jump() === null);

    env.pipelineModule.renderFinalAnswer({ text: LONG_ANSWER, stats: {}, raw: {} });
    const button = env.jump();
    check('an answer exists → the control exists', button !== null);
    check('it is offered while the answer may be off screen', button.hidden === false);
    check('it is a labelled, keyboard-operable button',
        button.tagName === 'BUTTON' && button.getAttribute('type') === 'button'
        && (button.getAttribute('aria-label') || '').length > 0);
    check('it keeps the full wording available when its label is hidden on phones',
        button.querySelector('.oc-final-answer-text') === null
        && button.textContent.includes('Final answer'));

    const observer = env.observers[env.observers.length - 1];
    check('visibility is observed, not computed from scroll offsets',
        observer !== undefined && observer.targets.includes(env.byId('final-answer-section')));
    check('the observer uses threshold 0 (first visible pixel hides it)',
        observer !== undefined && observer.options.threshold === 0);

    observer.callback([{ target: env.byId('final-answer-section'), isIntersecting: true }]);
    check('an answer in view hides the control', button.hidden === true);
    observer.callback([{ target: env.byId('final-answer-section'), isIntersecting: false }]);
    check('scrolling away brings it back', button.hidden === false);

    button.click();
    check('clicking scrolls to the answer section',
        env.byId('final-answer-section').scrollCalls.includes('scrollIntoView'));

    // A second run reuses the one control instead of stacking another.
    env.pipelineModule.renderFinalAnswer({ text: 'Second answer.', stats: {}, raw: {} });
    check('a re-render reuses the single control',
        globalThis.document.body.querySelectorAll('.oc-jump-final').length === 1);

    env.pipelineModule.clearPipeline();
    check('clearing the run takes the control away', env.jump().hidden === true);
    check('and stops the observation',
        env.observers[env.observers.length - 1].targets.length === 0);
}

// =====================================================================
console.log('\n== (e) copy buttons: the question, the first step, and nothing empty ==');
// =====================================================================
{
    const env = await boot();
    const { renderStep, renderQueryCard } = env.pipelineModule;

    const queryCard = renderQueryCard('What did I ask?');
    check('the question card carries the pair and the share control',
        queryCard.querySelectorAll('.oc-copy-btn').length === 4
        && queryCard.querySelector('.oc-copy-btn-download') !== null
        && queryCard.querySelector('.oc-copy-btn-share') !== null);
    check('the question payload is the question, unchanged',
        env.copyPlain(queryCard).dataset.copyValue === 'What did I ask?');

    // The collapse is a stage-card feature. The question is the one body the
    // reader wrote themselves: hiding it behind "Show more" would look like
    // every other step and would make the first card of the stream
    // unreadable on a long prompt.
    const longQuestion = renderQueryCard(LONG_ANSWER);
    check('a long question is never clamped',
        longQuestion.querySelector('.oc-collapse-toggle') === null
        && longQuestion.querySelectorAll('.oc-collapsible').length === 0
        && longQuestion.querySelectorAll('.oc-collapsed').length === 0
        && longQuestion.querySelector('.oc-query-text').textContent === LONG_ANSWER);

    // L0 is the first stage of a run; its card is a JSON payload dump
    // (L0_OPTIONS) or a skill card, and it used to get no pair at all.
    const l0 = renderStep({ id: 'ev-L0_OPTIONS', layer: 'L0', label: 'L0_OPTIONS',
        status: 'event', response_text: '{\n  "reasoning": "medium"\n}', stats: {}, raw: {} });
    check('the first stage card (L0) now carries the pair and the share control',
        l0.querySelectorAll('.oc-copy-btn').length === 4
        && l0.querySelector('.oc-copy-btn-share') !== null);
    check('its buttons are worded for what they copy, not as "the L0 answer"',
        /this card/.test(l0.querySelector('.oc-copy-btn').getAttribute('aria-label') || ''),
        l0.querySelector('.oc-copy-btn').getAttribute('aria-label'));
    check('its payload is the card text, exactly',
        env.copyPlain(l0).dataset.copyValue === '{\n  "reasoning": "medium"\n}');

    // Answer layers keep their stage wording.
    const l1 = renderStep({ id: 2, layer: 'L1', label: 'L1 · alpha', status: 'completed',
        response_text: SHORT_ANSWER, stats: {}, raw: {} });
    check('an L1 answer still says which stage it is',
        /Copy the L1 answer/.test(l1.querySelector('.oc-copy-btn').getAttribute('aria-label') || ''),
        l1.querySelector('.oc-copy-btn').getAttribute('aria-label'));

    const empty = renderStep({ id: 3, layer: 'L1', label: 'L1 · beta', status: 'completed',
        response_text: '', stats: {}, raw: {} });
    check('a card with no text gets no pair', empty.querySelector('.oc-copy-group') === null);

    const placeholder = renderStep({ id: 4, layer: 'L1', label: 'L1 · gamma', status: 'completed',
        response_text: '(no response)', stats: {}, raw: {} });
    check('a placeholder body gets no pair', placeholder.querySelector('.oc-copy-group') === null);

    const running = renderStep({ id: 5, layer: 'L1', label: 'L1 · delta', status: 'running',
        response_text: 'Awaiting model response…', stats: {}, raw: {} });
    check('a running card gets no pair', running.querySelector('.oc-copy-group') === null);

    // L3 renders a verdict pane, not the answer text: the pair must stay off
    // so nothing on the clipboard is text the user cannot see.
    const l3 = renderStep({ id: 6, layer: 'L3', label: 'L3 · Consensus', status: 'completed',
        response_text: 'verdict payload',
        l3_explanation: { decision: { score: 0.5, status: 'partial', pairs: 3 }, participants: { count: 3 } },
        stats: {}, raw: {} });
    check('a card whose Response tab is not the text gets no pair',
        l3.querySelector('.oc-copy-group') === null);
}

// =====================================================================
console.log('\n== (f) nothing in the harness reached for innerHTML ==');
// =====================================================================
{
    // Reaching for it would have thrown inside the stub during every render
    // above, so the behavioural layer already proves this dynamically; the
    // explicit check makes the intent readable and covers a module that is
    // loaded but never rendered.
    const env = await boot();
    let threw = null;
    try {
        env.pipelineModule.renderStep({ id: 9, layer: 'L1', label: 'L1 · epsilon', status: 'completed',
            response_text: LONG_ANSWER, stats: {}, raw: { note: '<b>not markup</b>' } });
        env.pipelineModule.renderQueryCard('<img src=x onerror=alert(1)>');
        env.pipelineModule.renderFinalAnswer({ text: '<script>alert(1)</script>', stats: {}, raw: {} });
    } catch (err) {
        threw = err;
    }
    check('rendering untrusted text (markup included) never uses innerHTML',
        threw === null, threw ? String(threw.message) : '');
    const scriptish = env.pipelineSection.textContent.includes('<img src=x onerror=alert(1)>');
    check('markup is rendered as text, not parsed', scriptish);
    check('the final answer keeps markup as text too',
        env.byId('final-answer-section').textContent.includes('<script>alert(1)</script>'));
    check('no element was created from the payload',
        env.pipelineSection.querySelectorAll('img, script').length === 0
        && env.byId('final-answer-section').querySelectorAll('img, script').length === 0);
}

// =====================================================================
console.log('\n== (batch) the Batch API mode is opt-in and reports itself honestly ==');
// =====================================================================
{
    // Default composer: the mode is off, so the enqueue request must be the
    // synchronous one — the feature is additive, not a new default.
    const env = await boot();
    env.submit('A live run, as before');
    await env.flush();
    const posted = apiState.requests.filter((r) => r.url === '/api/council/jobs');
    check('a batch job is only enqueued when the operator asks for one',
        posted.length > 0 && !String(posted[0].body || '').includes('run_mode'),
        String(posted[0]?.body ?? ''));
    const card = env.byId('chat-batch-state');
    check('and no batch readout appears for a live run',
        card !== null && card.hidden === true);
}

{
    // A deployment that can submit: the toggle works, the readout appears on
    // submit, and the request carries the mode.
    const env = await boot({ bootConfig: { batch: { ok: true, ready: true, reason: '' } } });
    await env.flush();
    const box = env.byId('run-mode-batch');
    check('the capability report from the server enables the toggle', box.disabled === false);

    box.checked = true;
    env.submit('A question worth waiting for');
    await env.flush();

    const posted = apiState.requests.filter((r) => r.url === '/api/council/jobs');
    check('the enqueue request declares the batch mode',
        posted.length > 0 && String(posted[0].body || '').includes('"run_mode":"batch"'),
        String(posted[0]?.body ?? ''));

    const card = env.byId('chat-batch-state');
    check('a batch readout is shown as soon as the run is enqueued', card.hidden === false);
    check('it names the phase and the provider window',
        card.textContent.includes('Queued') && card.textContent.includes('24 h'),
        card.textContent);
    check('it is coloured as a waiting state, not as a failure',
        card.dataset.batchState === 'awaiting', String(card.dataset.batchState));
    check('the cancel control is offered while the provider owns the batch',
        env.byId('chat-batch-cancel').hidden === false);
    const status = env.byId('chat-status').textContent;
    check('the status line says the result arrives asynchronously and names the window',
        status.includes('asynchronously') && status.includes('24 hours'), status);
    check('the readout never claims the batch is finished',
        !card.textContent.toLowerCase().includes('completed'), card.textContent);

    // A live run in the same page must not inherit the batch readout.
    env.closeRun?.();
}

{
    // A deployment that cannot submit: the toggle must be disabled AND say
    // why. A dead control with no explanation is the failure this guards.
    const env = await boot({
        bootConfig: { batch: { ok: false, ready: false, reason: 'The Batch API transport needs the PHP ext-curl extension.' } },
    });
    await env.flush();
    const box = env.byId('run-mode-batch');
    check('a deployment that cannot submit disables the toggle', box.disabled === true);
    check('a disabled toggle is unchecked, so it cannot silently affect a run', box.checked === false);
    const label = box.closest('label');
    check('the reason travels with the control instead of only reaching the log',
        label !== null && label.getAttribute('title').includes('ext-curl'),
        label ? label.getAttribute('title') : 'no label');
}
// =====================================================================
console.log('\n== M3: choosing a consensus skill brings its models to the pool ==');
// =====================================================================
//
// The reported defect: picking a skill under the composer changed nothing in
// the model panel beside it, although a skill's council runs on the models its
// domain preset defines — so the run used models the operator had not chosen.
// The binding travels with the skill (GET /api/consensus/skills serves
// `preset`, `models`, `arbiter`, `uses_user_pool`). These checks drive the
// REAL app.js against canned answers and read the panel, the stored pool and
// the status line the operator would see.

const SKILL_POOL = [
    { provider: 'alpha', model: 'openai/gpt-5.5' },
    { provider: 'alpha', model: 'anthropic/claude-sonnet-4.6' },
    { provider: 'alpha', model: 'google/gemini-3.1-pro-preview' },
    { provider: 'alpha', model: 'deepseek/deepseek-v4-pro' },
];

/** One bound skill, one unbound skill — the only two shapes there are. */
const SKILL_ANSWERS = {
    default: 'semantic_consensus_reasoning',
    consensus_skills: [
        {
            slug: 'mathematics-reasoning',
            file: 'mathematics_reasoning',
            name: 'Mathematics Reasoning Council',
            description: 'Reasoning-heavy pool',
            tags: ['consensus'],
            consensus_scoring: 'semantic_components',
            preset: 'mathematics',
            uses_user_pool: false,
            models: [
                { provider: 'alpha', model: 'openai/gpt-5.5' },
                { provider: 'alpha', model: 'deepseek/deepseek-v4-pro' },
                { provider: 'alpha', model: 'ghost/not-configured' },
            ],
            arbiter: { provider: 'alpha', model: 'openai/gpt-5.5', role: 'main' },
        },
        {
            slug: 'semantic-consensus-reasoning',
            file: 'semantic_consensus_reasoning',
            name: 'Semantic Consensus Council',
            description: '',
            tags: ['consensus'],
            consensus_scoring: 'semantic_components',
            preset: null,
            models: [],
            arbiter: null,
            uses_user_pool: true,
        },
    ],
};

/** The pool as the panel persisted it — what a run would actually send. */
function storedPool() {
    try {
        const parsed = JSON.parse(globalThis.localStorage.getItem('oc.selected_models.v1') || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

/** The models the panel draws as pool rows, in row order (the add row is not a pool row). */
function drawnPool(env) {
    return env.byId('model-selector')
        .querySelectorAll('.oc-model-label')
        .map((n) => n.textContent)
        .filter((t) => t !== '+ add model');
}

{
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: SKILL_ANSWERS,
    });
    await env.flush();
    const note = () => env.byId('consensus-skill-status').textContent;
    const select = env.byId('consensus-skill-select');

    check('the skill dropdown is filled from the endpoint',
        select.querySelectorAll('option').map((o) => o.value).join(',')
            === ',mathematics_reasoning,semantic_consensus_reasoning',
        select.querySelectorAll('option').map((o) => o.value).join(','));
    check('it starts on the server default', select.value === 'semantic_consensus_reasoning', select.value);
    check('the skill status line sits with the control it describes',
        env.byId('consensus-skill-status') !== null
        && env.byId('consensus-skill-status').parentNode === select.closest('label').parentNode);

    // ---- a skill with no binding says so and changes nothing -----------
    const untouched = JSON.stringify(storedPool());
    check('a skill with no linked preset is reported as using the operator\'s pool',
        note().includes('not linked to a preset') && note().includes('pool below'),
        note());
    check('and the pool is left exactly as it was', JSON.stringify(storedPool()) === untouched,
        `${untouched} -> ${JSON.stringify(storedPool())}`);
    check('no "default" preset is substituted for the missing binding',
        !note().includes('default'), note());

    // ---- a bound skill replaces the pool with its own models -----------
    select.value = 'mathematics_reasoning';
    select.dispatchEvent({ type: 'change', target: select });
    await env.flush();

    const applied = storedPool();
    check('choosing a bound skill replaces the pool with the preset\'s models',
        applied.length === 2
        && applied.map((r) => r.model).join(',') === 'openai/gpt-5.5,deepseek/deepseek-v4-pro',
        JSON.stringify(applied));
    check('the panel draws exactly those rows',
        drawnPool(env).join(',') === 'openai/gpt-5.5,deepseek/deepseek-v4-pro',
        drawnPool(env).join(','));
    check('a model no connector serves is skipped, not invented',
        !applied.some((r) => r.model === 'ghost/not-configured'));
    check('the note names how many models were bound',
        note().includes('2 models applied to the consensus pool'), note());
    check('and names the model that was left out, in words',
        note().includes('alpha / ghost/not-configured'), note());
    check('the count and the note agree with the pool',
        applied.length === 2 && /2 models applied/.test(note()));

    // ---- the run is untouched: the chosen skill still travels as-is ----
    const env2 = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: SKILL_ANSWERS,
    });
    await env2.flush();
    const select2 = env2.byId('consensus-skill-select');
    select2.value = 'mathematics_reasoning';
    select2.dispatchEvent({ type: 'change', target: select2 });
    await env2.flush();
    env2.submit('Does the pool follow the skill?');
    await env2.flush();
    const posted = apiState.requests.find((r) => r.url === '/api/council/jobs');
    const body = posted ? JSON.parse(posted.body) : null;
    check('the pool the run sends is the skill\'s binding',
        body !== null
        && Array.isArray(body.selected_models)
        && body.selected_models.map((r) => r.model).join(',') === 'openai/gpt-5.5,deepseek/deepseek-v4-pro',
        JSON.stringify(body ? body.selected_models : null));
    check('and consensus_skill travels exactly as chosen',
        body !== null && body.consensus_skill === 'mathematics_reasoning',
        JSON.stringify(body ? body.consensus_skill : null));
}

{
    // A skill that IS bound, but whose models no configured connector serves:
    // applyPreset() reports applied === 0, and the note must say so instead of
    // claiming the pool changed.
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'mathematics_reasoning',
            consensus_skills: [
                {
                    slug: 'mathematics-reasoning', file: 'mathematics_reasoning',
                    name: 'Mathematics Reasoning Council', description: '', tags: ['consensus'],
                    consensus_scoring: 'semantic_components',
                    preset: 'mathematics', uses_user_pool: false,
                    models: [{ provider: 'alpha', model: 'ghost/not-configured' }],
                    arbiter: null,
                },
            ],
        },
    });
    await env.flush();
    const before = JSON.stringify(storedPool());
    const note = env.byId('consensus-skill-status').textContent;
    check('an unusable binding is reported, not silently ignored',
        note.includes('no model was applied'), note);
    check('the reason the preset could not be applied travels with it',
        note.includes('None of the preset models is currently available'), note);
    check('and it says nothing was changed', note.includes('unchanged'), note);
    check('the pool really was left alone', JSON.stringify(storedPool()) === before,
        `${before} -> ${JSON.stringify(storedPool())}`);
}

{
    // The value already selected at load time gets the same treatment: the
    // pool is brought in line without the operator touching the dropdown.
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'mathematics_reasoning',
            consensus_skills: SKILL_ANSWERS.consensus_skills,
        },
    });
    await env.flush();
    check('the value in effect at load time is applied too',
        storedPool().map((r) => r.model).join(',') === 'openai/gpt-5.5,deepseek/deepseek-v4-pro',
        JSON.stringify(storedPool()));
    check('and the note explains it without a click',
        env.byId('consensus-skill-status').textContent.includes('2 models applied'),
        env.byId('consensus-skill-status').textContent);
}

// =====================================================================
console.log('\n== M3b: a skill with no preset of its own runs on Default ==');
// =====================================================================
//
// R-15: the left column must show the selected skill's models for EVERY skill,
// not only Mathematics. The catalog keeps answering `uses_user_pool: true` for
// a skill no preset names (it does not invent a binding), but the server now
// also publishes the Default preset's pool as `default_pool`, and a skill with
// no preset of its own is applied on that pool instead of leaving the panel
// untouched. With no published pool the honest old behaviour stands.

const DEFAULT_POOL = [
    { provider: 'alpha', model: 'openai/gpt-5.5' },
    { provider: 'alpha', model: 'google/gemini-3.1-pro-preview' },
];

{
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'semantic_consensus_reasoning',
            default_pool: DEFAULT_POOL,
            consensus_skills: SKILL_ANSWERS.consensus_skills,
        },
    });
    await env.flush();
    check('an unbound skill applies the Default pool the server published',
        storedPool().map((r) => r.model).join(',') === 'openai/gpt-5.5,google/gemini-3.1-pro-preview',
        JSON.stringify(storedPool()));
    check('and the panel draws exactly those rows',
        drawnPool(env).join(',') === 'openai/gpt-5.5,google/gemini-3.1-pro-preview',
        drawnPool(env).join(','));
    check('the note says the skill has no preset of its own and names the Default preset',
        env.byId('consensus-skill-status').textContent.includes('no preset of its own')
        && env.byId('consensus-skill-status').textContent.includes('Default preset'),
        env.byId('consensus-skill-status').textContent);
    check('and still reports how many models were applied',
        env.byId('consensus-skill-status').textContent.includes('2 models applied to the consensus pool'),
        env.byId('consensus-skill-status').textContent);

    const select = env.byId('consensus-skill-select');
    select.value = 'mathematics_reasoning';
    select.dispatchEvent({ type: 'change', target: select });
    await env.flush();
    check('a skill with its own preset still uses that preset, not the fallback',
        storedPool().map((r) => r.model).join(',') === 'openai/gpt-5.5,deepseek/deepseek-v4-pro',
        JSON.stringify(storedPool()));
}

{
    // No published Default pool (an empty store): the honest old note stands.
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'semantic_consensus_reasoning',
            consensus_skills: SKILL_ANSWERS.consensus_skills,
        },
    });
    await env.flush();
    check('with no Default pool published, an unbound skill still leaves the pool alone',
        env.byId('consensus-skill-status').textContent.includes('not linked to a preset'),
        env.byId('consensus-skill-status').textContent);
}

// =====================================================================
console.log('\n== R-15: the Save control persists the pool for the skill ==');
// =====================================================================
//
// "Save" must store the pool for the SELECTED skill through that skill's
// domain preset, not just for the next Chat run: the skill's own preset when it
// has one, the Default preset otherwise. The checks read the request the page
// really sends, the text the operator reads, and the pool a fresh load brings
// back for the skill.
{
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'semantic_consensus_reasoning',
            default_pool: DEFAULT_POOL,
            consensus_skills: SKILL_ANSWERS.consensus_skills,
        },
    });
    await env.flush();
    const saveBtn = () => env.byId('oc-model-save');
    const status = () => env.byId('oc-model-save-status');
    const requests = () => apiState.requests.filter((r) => r.url.startsWith('/api/domain-presets'));

    check('the pool block renders a Save control', saveBtn() !== null && saveBtn().textContent.includes('Save'));
    check('the Save control lives inside the model-selection block',
        env.byId('model-selector').contains(saveBtn()));
    check('and it has its own status line',
        status() !== null && status().getAttribute('role') === 'status');
    check('the control starts without a stale result', status().textContent === '', status().textContent);

    const removers = () => env.byId('model-selector').querySelectorAll('.oc-model-remove');
    removers()[0].click();
    await env.flush();
    check('removing a row leaves one model in the pool',
        drawnPool(env).length === 1, drawnPool(env).join(','));
    const onePool = drawnPool(env).join(',');
    saveBtn().click();
    await env.flush();
    const saveReq = requests().find((r) => r.method === 'POST');
    const saveBody = saveReq ? JSON.parse(saveReq.body) : null;
    check('Save posts the current pool to the skill\'s Default preset',
        saveReq !== null && saveReq.url === '/api/domain-presets/default/select'
        && saveBody !== null && saveBody.models.length === 1,
        saveReq ? saveReq.url : 'no request');
    check('and the saved model is the one that was in the pool',
        saveBody !== null && saveBody.models[0].model === onePool, JSON.stringify(saveBody));
    check('the control reports the save in words',
        status().textContent.includes('Saved 1 model')
        && status().textContent.includes('Semantic Consensus Council'),
        status().textContent);

    const select = env.byId('consensus-skill-select');
    select.value = 'mathematics_reasoning';
    select.dispatchEvent({ type: 'change', target: select });
    await env.flush();
    const mathPool = drawnPool(env).length;
    saveBtn().click();
    await env.flush();
    const mathReq = requests().filter((r) => r.method === 'POST').pop();
    check('a skill with its own preset saves into that preset',
        mathReq !== null && mathReq.url === '/api/domain-presets/mathematics/select',
        mathReq ? mathReq.url : 'no request');
    check('and it posts exactly the drawn pool',
        mathReq !== null && JSON.parse(mathReq.body).models.length === mathPool,
        mathReq ? mathReq.body : 'no request');

    // A fresh load, as after a reload: the server answers with the pool that
    // was just saved, and picking that skill brings it back.
    const savedModels = saveBody.models;
    const env2 = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'semantic_consensus_reasoning',
            default_pool: savedModels,
            consensus_skills: SKILL_ANSWERS.consensus_skills,
        },
    });
    await env2.flush();
    check('a reload restores the saved pool for the skill',
        drawnPool(env2).join(',') === savedModels.map((r) => r.model).join(','),
        drawnPool(env2).join(','));
    check('and the note explains it again without a click',
        env2.byId('consensus-skill-status').textContent.includes('Default preset'),
        env2.byId('consensus-skill-status').textContent);
}

{
    // "Nothing to save": no skill chosen yet.
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'semantic_consensus_reasoning',
            default_pool: DEFAULT_POOL,
            consensus_skills: SKILL_ANSWERS.consensus_skills,
        },
    });
    await env.flush();
    const select = env.byId('consensus-skill-select');
    select.value = '';
    select.dispatchEvent({ type: 'change', target: select });
    await env.flush();
    env.byId('oc-model-save').click();
    await env.flush();
    // v1.9.x - the placeholder option is not "no skill": it resolves to the
    // server's default skill, so saving the pool for it is a real write (to the
    // Default preset, the same target an unbound skill uses). It used to be
    // refused with "choose a Consensus skill first", which left the panel
    // empty and unsaveable for the operator's plain default choice.
    check('saving with the default option chosen names the server default skill',
        env.byId('oc-model-save-status').textContent.includes('Semantic Consensus Council'),
        env.byId('oc-model-save-status').textContent);
    check('and it reports a write rather than a refusal',
        env.byId('oc-model-save-status').textContent.includes('Saved')
        && !env.byId('oc-model-save-status').textContent.includes('Nothing saved'));
}

{
    // A refusal from the server is reported with its reason.
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'semantic_consensus_reasoning',
            default_pool: DEFAULT_POOL,
            consensus_skills: SKILL_ANSWERS.consensus_skills,
        },
        domainPresetSave: () => jsonResponse({ error: 'unauthorized', message: 'Admin key required' }, 401),
    });
    await env.flush();
    env.byId('oc-model-save').click();
    await env.flush();
    check('a refused save is reported, not swallowed',
        env.byId('oc-model-save-status').textContent.includes('Save failed'),
        env.byId('oc-model-save-status').textContent);
    check('and the server\'s own reason travels with it',
        env.byId('oc-model-save-status').textContent.includes('Admin key required'),
        env.byId('oc-model-save-status').textContent);
}

{
    // A save that only landed in memory says so (the documented case is the
    // domain_presets table being absent).
    const env = await boot({
        connectors: { connectors: [], available_models: SKILL_POOL, default_selected: [] },
        consensusSkills: {
            default: 'semantic_consensus_reasoning',
            default_pool: DEFAULT_POOL,
            consensus_skills: SKILL_ANSWERS.consensus_skills,
        },
        domainPresetSave: () => jsonResponse({ ok: true, persisted: false, warning: 'Stored in memory only.' }),
    });
    await env.flush();
    env.byId('oc-model-save').click();
    await env.flush();
    check('a memory-only save is not advertised as persisted',
        env.byId('oc-model-save-status').textContent.includes('memory only'),
        env.byId('oc-model-save-status').textContent);
}
// =====================================================================
console.log('\n== (i) who runs, and who arbitrates: the card under the question ==');
// =====================================================================
{
    const env = await boot();
    const { renderQueryCard, updateQueryParticipants } = env.pipelineModule;

    const plain = renderQueryCard('No composition attached?');
    check('without composition data the card is exactly as it was',
        plain.querySelector('.oc-query-participants') === null);

    const card = renderQueryCard('Who runs this?', {
        models: [
            { provider: 'bothub', model: 'gpt-6-astra-pro' },
            { provider: 'bothub', model: 'glm-5.3' },
        ],
        arbiter: { provider: 'bothub', model: 'gpt-6-astra-pro', role: 'main' },
        state: 'requested',
    });
    const block = card.querySelector('.oc-query-participants');
    check('the card names every participant',
        block !== null && block.querySelectorAll('.oc-query-participant').length === 2,
        block ? String(block.querySelectorAll('.oc-query-participant').length) : 'no block');
    check('it says the composition is what was requested',
        block.dataset.state === 'requested'
        && block.querySelector('.oc-query-participants-head').textContent.includes('as requested'));
    check('the arbiter is named with its role',
        block.querySelector('.oc-query-arbiter').textContent.includes('gpt-6-astra-pro')
        && block.querySelector('.oc-query-arbiter').textContent.includes('main'),
        block.querySelector('.oc-query-arbiter').textContent);

    // The engine resolves the pool after its own checks. Here one of the two
    // models is dropped, and the card has to say so rather than keep showing a
    // composition that never ran.
    updateQueryParticipants({
        selected_models: [{ provider: 'bothub', model: 'glm-5.3' }],
        arbiter: { provider: 'bothub', model: 'glm-5.3', role: 'main' },
        selection_diagnostic: {
            rejected: [{ provider: 'bothub', model: 'gpt-6-astra-pro', reason: 'not_in_catalog' }],
        },
    });
    const resolved = card.querySelector('.oc-query-participants');
    check('the block switches to what the server resolved',
        resolved !== block && resolved.dataset.state === 'resolved'
        && resolved.querySelector('.oc-query-participants-head').textContent.includes('as resolved'));
    check('the model the run did not get is named, with the reason',
        resolved.querySelector('.oc-query-participants-note') !== null
        && resolved.querySelector('.oc-query-participants-note').textContent.includes('gpt-6-astra-pro')
        && resolved.querySelector('.oc-query-participants-note').textContent.includes('not_in_catalog'),
        resolved.querySelector('.oc-query-participants-note')?.textContent ?? '');
    check('the resolved arbiter replaces the requested one',
        resolved.querySelector('.oc-query-arbiter').textContent.includes('glm-5.3'));

    const none = renderQueryCard('No arbiter here', {
        models: [{ provider: 'bothub', model: 'glm-5.3' }],
        arbiter: null,
        state: 'requested',
    });
    check('before the server answers, an unknown arbiter is not claimed to be absent',
        none.querySelector('.oc-query-arbiter').textContent.includes('not named yet'),
        none.querySelector('.oc-query-arbiter').textContent);

    const settled = renderQueryCard('Settled without an arbiter', {
        models: [{ provider: 'bothub', model: 'glm-5.3' }],
        arbiter: null,
        state: 'resolved',
    });
    check('once the server has answered, an absent arbiter is stated as such',
        settled.querySelector('.oc-query-arbiter').textContent.includes('none configured'),
        settled.querySelector('.oc-query-arbiter').textContent);
}

// =====================================================================
console.log('\n== (j) economy advice: estimate before, outcome after, silence when off ==');
// =====================================================================
{
    const env = await boot();
    const { renderQueryCard, updateQueryEconomy, updateQueryEconomyResult } = env.pipelineModule;

    const card = renderQueryCard('What will this cost?', {
        models: [{ provider: 'bothub', model: 'glm-5.3' }],
        arbiter: null,
        state: 'requested',
    });
    check('no advice block until the server has answered',
        card.querySelector('.oc-query-economy') === null);

    updateQueryEconomy({ advice: false, mode: 'off', recommendation: null, estimate: null });
    check('with economy mode off nothing is rendered at all',
        card.querySelector('.oc-query-economy') === null);

    updateQueryEconomy({
        advice: true,
        mode: 'suggest',
        recommendation: { recommend: 'sync', reason: 'You are waiting for this answer.', decided_by: 'interactive' },
        estimate: {
            cost_low: 0.4, cost_high: 0.9, eta_low_s: 30, eta_high_s: 70, samples: 6,
            notes: 'Estimate from 6 observation(s) of comparable runs.',
        },
        batch: { low: 0.2, high: 0.45, note: 'Estimated from the documented discount, not measured.' },
        cache: [{ model: 'bothub/glm-5.3', advice: 'drop', reason: 'Written 40 times, never read.', hit_rate: 0 }],
        note: '',
    });
    const block = card.querySelector('.oc-query-economy');
    check('the advice block appears under the question',
        block !== null && block.querySelector('.oc-query-economy-head').textContent === 'Cost and speed');
    const text = block ? block.textContent : '';
    check('the recommendation is stated with its reason',
        text.includes('Keep it synchronous') && text.includes('waiting for this answer'));
    check('the estimate is a range and is labelled as an estimate',
        text.includes('0.4 - 0.9') && text.includes('Estimate, not a quote') && text.includes('30-70 s'));
    check('the batch comparison is labelled as the documented discount',
        text.includes('Batch API') && text.includes('not measured'));

    check('cache advice appears where it was measured',
        text.includes('Caching on bothub/glm-5.3'));
    check('the sample size travels with the estimate', text.includes('6 observation'));

    updateQueryEconomyResult({ stats: { per_layer: { L1: { cost: 0.5, total_tokens: 1200, cached_tokens: 400 } } } });
    const outcomeLines = [...block.querySelectorAll('.oc-query-economy-line')].map((li) => li.textContent);
    check('the outcome is appended to the same block',
        outcomeLines.some((line) => line.includes('Ran: cost 0.5, 1200 tokens, 400 served from cache')),
        outcomeLines.join(' | '));

    const bare = renderQueryCard('No figures', { models: [], arbiter: null, state: 'requested' });
    updateQueryEconomyResult({ stats: { per_layer: {} } });
    check('an outcome without figures says nothing rather than showing zeros',
        bare.querySelector('.oc-query-economy') === null);
    // The run controls: the same three actions as the composer bar, under the
    // newest question, and gone once the run is over.
    // A paused run reads as waiting, not as work in progress: the worker is
    // idle, and the ring must stop rather than keep turning.
    const { setActivity } = env.pipelineModule;
    setActivity({ state: 'paused', label: 'Paused - awaiting continue' });
    const activityCard = document.querySelector('.oc-current-activity');
    check('a paused run marks the activity card as paused',
        activityCard !== null && activityCard.dataset.state === 'paused',
        activityCard ? activityCard.dataset.state : 'no card');
    const activityBadge = activityCard === null ? null : activityCard.querySelector('.oc-activity-state');
    check('and the badge says paused, so the ring is not the only signal',
        activityBadge !== null && activityBadge.textContent === 'paused' && activityBadge.dataset.stateLabel === 'paused',
        activityBadge ? activityBadge.textContent : 'no badge');
    setActivity({ state: 'running', label: 'Pipeline resumed' });
    check('resuming returns the card to running',
        activityCard !== null && activityCard.dataset.state === 'running',
        activityCard ? activityCard.dataset.state : 'no card');
    const { renderRunControls } = env.pipelineModule;
    const controlsCard = renderQueryCard('With controls', {
        models: [{ provider: 'bothub', model: 'glm-5.3' }], arbiter: null, state: 'requested',
    });
    renderRunControls({ visible: true, pauseLabel: 'Pause', pauseEnabled: true, rejectEnabled: true, finalizeEnabled: false });
    const runControls = controlsCard.querySelector('.oc-query-controls');
    check('the run controls appear under the newest question',
        runControls !== null
        && runControls.textContent.includes('Pause')
        && runControls.textContent.includes('Stop and Run consensus'),
        runControls ? runControls.textContent : 'no block');
    const disabledCount = runControls === null ? -1
        : [...runControls.querySelectorAll('button')].filter((b) => b.disabled === true).length;
    check('an unavailable action is disabled, not merely styled', disabledCount === 1, String(disabledCount));
    renderRunControls({ visible: false });
    check('a finished run leaves no dead buttons under the question',
        controlsCard.querySelector('.oc-query-controls') === null);
    const finalHost = document.getElementById('final-answer-section');
    env.pipelineModule.renderFinalContribution({
        scoring: 'semantic_components',
        sum: 1,
        models: [
            { model: 'bothub/gpt-6-astra-pro', share: 0.42, ratio: 0.9 },
            { model: 'bothub/glm-5.3', share: 0.28, ratio: 0.6 },
        ],
    });
    const contribution = finalHost.querySelector('.oc-final-contribution');
    check('the contribution to the decision is shown under the final answer',
        contribution !== null && contribution.textContent.includes('42%') && contribution.textContent.includes('28%'),
        contribution ? contribution.textContent : 'no block');
    check('the line names the measure, so a share is not read as a score',
        contribution !== null && contribution.textContent.includes('corroborated'));

    env.pipelineModule.renderFinalContribution({ models: [] });
    check('a run with nothing corroborated shows no contribution line',
        finalHost.querySelector('.oc-final-contribution') === null);

    const priced = renderQueryCard('Zero prices', { models: [{ provider: 'b', model: 'm' }], arbiter: null, state: 'requested' });
    updateQueryEconomy({
        advice: true, mode: 'suggest',
        recommendation: { recommend: 'sync', reason: 'Waiting.', decided_by: 'interactive' },
        estimate: { cost_low: 0, cost_high: 0, eta_low_s: 10, eta_high_s: 20, samples: 3, notes: 'no price recorded' },
        batch: { low: 0, high: 0, note: 'Estimated from the documented discount.' },
        cache: [], note: '',
    });
    check('without a recorded price no comparison is shown, so nothing reads as free',
        priced.querySelector('.oc-query-economy') !== null
        && !priced.querySelector('.oc-query-economy').textContent.includes('Batch API'));

}

console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);