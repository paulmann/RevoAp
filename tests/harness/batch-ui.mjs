import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import * as stub from './dom-stub.mjs';

const here = dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const root = resolve(here, '..', '..');
const read = (p) => readFileSync(resolve(root, p), 'utf8');

const section = read('assets/templates/chat.html');
const appJs = read('assets/js/app.js');
const historyJs = read('assets/js/history.js');
const chatCss = read('assets/css/components/chat.css');
const historyCss = read('assets/css/components/history.css');

let passes = 0;
let failures = 0;
function check(label, cond, detail = '') {
    if (cond) { passes++; console.log(`  ok  ${label}`); }
    else { failures++; console.log(`  FAIL ${label}${detail ? ` — ${detail}` : ''}`); }
}
function stripComments(text) {
    return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

console.log('\n== (b1) the composer offers the batch mode and explains what it means ==');
{
    check('the composer carries a batch-mode toggle',
        section.includes('id="run-mode-batch"') && section.includes('name="run_mode"'));
    check('the toggle is a checkbox whose value is the mode name, so the form is self-describing',
        /<input[^>]+id="run-mode-batch"[^>]+type="checkbox"[^>]+value="batch"/.test(section)
        || /<input[^>]+id="run-mode-batch"[^>]+value="batch"[^>]+type="checkbox"/.test(section));
    check('the toggle is OFF by default (a batch run is opt-in)',
        !/<input[^>]+id="run-mode-batch"[^>]+checked/.test(section));
    check('its title says the result arrives asynchronously and names the window',
        /Run via Batch API/.test(section)
        && /asynchronous/.test(section)
        && /24 hours?/.test(section));
    check('the composer has a dedicated batch readout, hidden until a batch exists',
        section.includes('id="chat-batch-state"') && /id="chat-batch-state"[^>]*hidden/.test(section));
    check('the readout is a live region, so a screen reader hears the phase change',
        /id="chat-batch-state"[^>]*role="status"/.test(section));
    check('and a cancel control that starts hidden (nothing to cancel yet)',
        section.includes('id="chat-batch-cancel"') && /id="chat-batch-cancel"[^>]*hidden/.test(section));
    check('the cancel control says the PROVIDER has to be told, not that the click stops it',
        /Ask the provider to stop/.test(section));
}

console.log('\n== (b2) app.js: the mode is chosen at enqueue and reported from the job row ==');
{
    check('the enqueue body carries run_mode only when the toggle is on',
        appJs.includes("if (runMode === 'batch') body.run_mode = 'batch';"));
    check('app.js reads the mode from the checkbox rather than from a hidden default',
        /function getRunMode\(\)/.test(appJs) && appJs.includes("box.checked ? 'batch' : 'sync'"));
    check('the capability report comes from the server, not from a guess',
        appJs.includes('/api/boot-config') && appJs.includes('batchCapability'));
    check('a disabled toggle keeps the reason with it',
        appJs.includes('Batch API unavailable: ') && appJs.includes('label.title'));
    check('the lifecycle readout is a pure function of the batch state',
        /function batchStateText\(batch\)/.test(appJs) && /function batchElapsedText\(/.test(appJs));
    check('the readout names the phase, the provider status, the request count and the window',
        appJs.includes('phase_label') && appJs.includes('status_label')
        && appJs.includes('request_count') && appJs.includes('completion_window_h'));
    check('the readout says the result is asynchronous, in the operator\'s words',
        /provider window: up to /.test(appJs));
    check('a partial batch is reported per row, not as a run failure',
        appJs.includes('failed rows: ') && appJs.includes('missing answers: '));
    check('the card is coloured from the batch state attribute',
        appJs.includes('box.dataset.batchState = info.state')
        && appJs.includes("'awaiting'") && appJs.includes("'cancelled'"));
    check('the cancel button is only offered while the provider owns the batch',
        appJs.includes("cancel.hidden = info.state !== 'awaiting'"));
    check('the browser polls the JOB row, not the event stream, for the batch phase',
        appJs.includes("'/api/council/jobs/' + encodeURIComponent(String(jobId))"));
    check('the cancel goes through the job cancel endpoint',
        /'\/cancel'/.test(appJs) && appJs.includes('onBatchCancelClick'));
    check('and it never claims the batch is stopped before the worker has delivered the request',
        appJs.includes('the worker will tell the provider to stop'));
    check('batch polling stops when the run it belongs to is torn down',
        /function closeActiveRun\(reason\) \{[\s\S]{0,200}stopBatchPolling\(\);/.test(appJs));
    check('a batch run announces the asynchronous wait instead of imitating a live call',
        appJs.includes('results arrive asynchronously, in minutes to 24 hours'));
    check('the model pool follows the mode, so batch ids stop being refused',
        appJs.includes('modelSelector.setBatchMode(on)') && appJs.includes('setBatchMode(getRunMode()'));
    check('app.js never smuggles markup', !stripComments(appJs).includes('innerHTML'));
}

console.log('\n== (b3) History reads a batch session as "awaiting", not as stalled ==');
{
    check('history.js knows the batch state the server derives',
        historyJs.includes('item.batch') && historyJs.includes('batch.awaiting'));
    check('the pill becomes "awaiting batch" for exactly that case',
        historyJs.includes("'awaiting batch'") && historyJs.includes('oc-history-status-batch'));
    check('the detail pane does the same, so list and detail agree',
        historyJs.includes('detailAwaitingBatch') && historyJs.includes('s.batch'));
    check('the readout spells out the phase, the provider status, the counts and the window',
        /function batchMetaText\(batch\)/.test(historyJs)
        && historyJs.includes('phase_label') && historyJs.includes('completion_window_h')
        && historyJs.includes('submitted '));
    check('and the tooltip explains why a waiting run is neither finished nor failed',
        historyJs.includes('waiting on the provider Batch API'));
    check('history.js never smuggles markup',
        !stripComments(historyJs).includes('innerHTML'));
}

console.log('\n== (b4) the pool rules really do change with the mode ==');
{
    // Behavioural: the real selector against the real dom stub. This is the
    // half of B5 that says a `:batch` id must be SELECTABLE in batch mode —
    // a static check could not tell whether the refusal survived.
    const parsed = stub.parseHtml(
        '<div id="model-selector" class="oc-model-selector"></div>',
    );
    stub.installGlobals(parsed, {});
    const path = resolve(root, 'assets/js/model-selector.js');
    const mod = new vm.SourceTextModule(read('assets/js/model-selector.js'), {
        identifier: path,
        context: vm.createContext(globalThis),
    });
    await mod.link(async () => { throw new Error('model-selector.js gained a dependency'); });
    await mod.evaluate();
    const ns = mod.namespace;

    const rootEl = globalThis.document.getElementById('model-selector');
    const selector = new ns.ModelSelector(rootEl);
    selector.setCatalog(
        [
            { provider: 'bothub', model: 'gpt-4o' },
            { provider: 'bothub', model: 'gpt-4o:batch' },
        ],
        [],
    );

    /** The `<li>` for one model id, opened through the "+ add model" row. */
    const optionFor = (model) => {
        const rows = rootEl.querySelectorAll('.oc-model-row');
        const addRow = rows[rows.length - 1];
        addRow.querySelector('.oc-model-trigger').click();
        const options = Array.from(rootEl.querySelectorAll('.oc-model-option'));
        return options.find((li) => (li.getAttribute('data-value') || '').includes(model)) || null;
    };

    check('the selector exposes the mode flag', selector.batchModeEnabled() === false);

    const syncBatch = optionFor('gpt-4o:batch');
    check('synchronous mode: a :batch id is listed but refused',
        syncBatch !== null && syncBatch.getAttribute('aria-disabled') === 'true',
        syncBatch ? String(syncBatch.getAttribute('aria-disabled')) : 'no option');
    check('synchronous mode: and it is refused with the reason, not silently',
        syncBatch !== null && String(syncBatch.getAttribute('title')).includes('asynchronous Batch API'),
        syncBatch ? String(syncBatch.getAttribute('title')) : 'no option');
    check('synchronous mode: the badge marks it',
        syncBatch !== null && syncBatch.querySelector('.oc-model-batch-badge') !== null);
    check('the refusal is what the pool actually enforces, not only paint',
        syncBatch !== null && ns.batchPoolRejection('gpt-4o:batch') !== ''
        && ns.batchPoolRejection('gpt-4o') === '');

    // The provider capability list is three-valued on the client too: not
    // read, listed, or absent-from-a-successful-list. Only the first two may
    // ever change what the badge says, and none of the three may say
    // "unsupported" — a dropped request is not a verdict about a model.
    check('the capability list starts unread', ns.batchCapabilityResolved() === false);
    const unreadNote = ns.batchCapabilityNote('gpt-4o:batch');
    check('unread: the badge says the answer is unknown, and says so explicitly',
        unreadNote.includes('unknown') && unreadNote.includes('not a statement that the model lacks it'),
        unreadNote);
    ns.setBatchCapabilityIds(['gpt-4o:batch']);
    check('a listed id is reported as listed',
        ns.batchCapabilityResolved() === true
        && ns.batchCapabilityNote('gpt-4o:batch').includes('supporting background processing'),
        ns.batchCapabilityNote('gpt-4o:batch'));
    check('an id absent from a successful list is NOT called unsupported',
        !ns.batchCapabilityNote('other:batch').includes('supporting background processing')
        && !ns.batchCapabilityNote('other:batch').toLowerCase().includes('unsupported'),
        ns.batchCapabilityNote('other:batch'));
    ns.setBatchCapabilityIds(null);
    check('a failed call puts the client back to unknown, never to "unsupported"',
        ns.batchCapabilityResolved() === false
        && ns.batchCapabilityNote('gpt-4o:batch') === unreadNote);

    selector.setBatchMode(true);
    check('batch mode is on', selector.batchModeEnabled() === true);
    const batchOption = optionFor('gpt-4o:batch');
    check('batch mode: the SAME id becomes selectable',
        batchOption !== null && batchOption.getAttribute('aria-disabled') !== 'true',
        batchOption ? String(batchOption.getAttribute('aria-disabled')) : 'no option');
    check('batch mode: it is still marked and still explains the asynchronous wait',
        batchOption !== null
        && batchOption.querySelector('.oc-model-batch-badge') !== null
        && String(batchOption.getAttribute('title')).includes('up to 24 hours'),
        batchOption ? String(batchOption.getAttribute('title')) : 'no option');
    check('batch mode: the wording is a description, not a refusal',
        ns.batchModeNotice('gpt-4o:batch') !== '' && ns.batchPoolRejection('gpt-4o:batch') !== ''
        && !ns.batchModeNotice('gpt-4o:batch').includes('cannot take part')
        && ns.batchModeNotice('gpt-4o') === '');

    // The click path, not just the attribute: picking it must add it to the pool.
    if (batchOption !== null) {
        batchOption.click();
        for (let i = 0; i < 4; i++) { await new Promise((r) => setImmediate(r)); }
    }
    check('batch mode: clicking the id actually puts it in the pool',
        selector.selected().some((row) => row.model === 'gpt-4o:batch'),
        JSON.stringify(selector.selected()));

    selector.setBatchMode(false);
    const backToSync = optionFor('gpt-4o');
    check('switching back does not lose the pool (the operator keeps what they chose)',
        selector.selected().some((row) => row.model === 'gpt-4o:batch') && backToSync !== null);
}

console.log('\n== (b5) the CSS uses only tokens this project already publishes ==');
{
    const themeCss = read('assets/css/themes/dark.css') + read('assets/css/themes/light.css');
    const chatRules = stripComments(chatCss);
    const historyRules = stripComments(historyCss);
    const tokensUsed = [...`${chatRules}\n${historyRules}`.matchAll(/var\(\s*(--[A-Za-z0-9_-]+)/g)]
        .map((m) => m[1]);
    const known = new Set(
        [...themeCss.matchAll(/(?:^|[;{])\s*(--[A-Za-z0-9_-]+)\s*:/gm)].map((m) => m[1]),
    );
    const unknown = [...new Set(tokensUsed)].filter((t) => !known.has(t));
    check('the theme files publish a token set to check against', known.size > 20, String(known.size));
    check('every token the batch styling uses is published by the themes', unknown.length === 0, unknown.join(', '));
    check('no colour literal is used as a fallback inside var()',
        !/var\(\s*--[A-Za-z0-9_-]+\s*,\s*(#|rgb|hsl)/.test(chatRules + historyRules));
    check('the batch card is styled as a waiting state by default',
        chatRules.includes('.oc-batch-state{') || chatRules.includes('.oc-batch-state {'));
    check('and its terminal states reuse the existing success/error pair, not new colours',
        chatRules.includes('[data-batch-state="completed"]')
        && chatRules.includes('[data-batch-state="failed"]')
        && chatRules.includes('[data-batch-state="cancelled"]'));
    check('the History pill has its own class so the status pill keeps its own colours',
        historyRules.includes('.oc-history-status-batch'));
    check('the batch card is hidden by attribute, not by a JS style write',
        chatRules.includes('.oc-batch-state[hidden]'));
}

console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
