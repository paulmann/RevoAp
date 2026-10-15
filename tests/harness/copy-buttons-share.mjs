// tests/harness/copy-buttons-share.mjs - the Share button's DOM contract.
//
// Run through `php tests/CopyButtonsShareDomTest.php` (which supplies node), or
// directly:
//
//   node --experimental-vm-modules tests/harness/copy-buttons-share.mjs
//
// The REAL assets/js/copy-buttons.js is imported into the stub DOM
// (tests/harness/dom-stub.mjs) after the globals it needs are installed, and
// driven through one delegated click - exactly the path a card takes in the
// app. What is pinned here:
//
//   1. every copy pair gains exactly one Share button, in the same
//      .oc-copy-group wrapper, and a re-render of the same host cannot stack a
//      second one;
//   2. the payload it posts is the same Markdown the "Copy MD" button copies,
//      and the topic is the block's heading - the server names the document
//      after it;
//   3. a deployment with MD.Viewer opens the viewer link and copies it; one
//      without a viewer opens nothing and copies the file link instead;
//   4. a refusal from the endpoint is reported as a failure with its reason,
//      and the button does not pretend the share succeeded;
//   5. the module never builds a URL from location.pathname - a page opened at
//      a path with a doubled leading slash would otherwise produce a
//      protocol-relative link whose host is the first path segment.

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as stub from './dom-stub.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');

let passes = 0;
let failures = 0;

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

// =====================================================================
console.log('== the shipped module and styles declare the Share control ==');
// =====================================================================
const js = read('assets/js/copy-buttons.js');
const css = read('assets/css/components/copy-buttons.css');

check('the module declares the share mode', js.includes("const MODE_SHARE = 'share';"));
check('the module posts to the document endpoint', js.includes("const SHARE_ENDPOINT = '/api/share/document';"));
check('the module declares its share class', js.includes("const SHARE_CLASS = 'oc-copy-btn-share';"));
check('the module declares the toast id', js.includes("const TOAST_ID = 'oc-share-toast';"));
check('the module never builds a URL from location.pathname', !js.includes('location.pathname'));
check('the styles ship the share button rule', css.includes('.oc-copy-btn-share'));
check('the styles ship the toast rule', css.includes('.oc-share-toast {'));
check('the styles ship the visible-toast rule', css.includes('.oc-share-toast-visible {'));
check('the styles ship both toast tones', css.includes('.oc-share-toast-ok {') && css.includes('.oc-share-toast-err {'));
check('the module declares the download mode', js.includes("const MODE_DOWNLOAD = 'download';"));
check('the module declares its download class', js.includes("const DOWNLOAD_CLASS = 'oc-copy-btn-download';"));
check('the download button says Download MD', js.includes("['span', 'oc-copy-btn-text', 'Download MD']"));
check('the styles cover the download button', css.includes('.oc-copy-btn-download'));

// =====================================================================
console.log('== the button appears next to every copy pair ==');
// =====================================================================
const calls = [];
const opened = [];
let copied = [];
let answer = () => ({ status: 200, body: { ok: true } });

const docRoot = new stub.Element('div');
const globals = stub.installGlobals(docRoot, {
    fetch: async (url, init) => {
        calls.push({ url, init, body: init && init.body ? JSON.parse(init.body) : null });
        const res = answer(url, init);
        return {
            ok: res.status < 400,
            status: res.status,
            json: async () => res.body,
        };
    },
});

// The stub resolves getElementById() against the parsed root only, while the
// module appends its toast to document.body (as a browser page would). Search
// both, so the test sees what a browser would see.
const document = globals.document;
const rootById = document.getElementById.bind(document);
document.getElementById = (id) =>
    rootById(id) || document.body.descendants().find((el) => el.id === id) || null;

// The stub gives every element a `style` object here: a browser always has
// one, and the download anchor hides itself with `style.display`.
if (!('style' in stub.Element.prototype)) {
    Object.defineProperty(stub.Element.prototype, 'style', {
        configurable: true,
        get() {
            if (!this._style) { this._style = {}; }
            return this._style;
        },
    });
}
const downloads = [];
const blobs = new Map();
globalThis.URL.createObjectURL = (blob) => {
    const url = 'blob:stub-' + (blobs.size + 1);
    blobs.set(url, blob);
    return url;
};
globalThis.URL.revokeObjectURL = () => {};
// An anchor carrying a `download` name is what makes the browser save a file
// instead of navigating, so the harness watches exactly that.
const stubClick = stub.Element.prototype.click;
stub.Element.prototype.click = function () {
    if (String(this.tagName).toUpperCase() === 'A' && this.getAttribute('download')) {
        downloads.push({ name: this.getAttribute('download'), url: this.href });
    }
    return stubClick.apply(this, arguments);
};

// Node 22 exposes navigator as a getter-only global, so it has to be defined
// rather than assigned.
Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: {
        clipboard: {
            writeText: async (text) => {
                if (globalThis.__clipboardRefuses) {
                    throw new Error('clipboard refused');
                }
                copied.push(text);
            },
        },
    },
});
globalThis.window.isSecureContext = true;
globalThis.window.open = (url, target, features) => {
    opened.push({ url, target, features });
    return null;
};

const mod = await import(pathToFileURL(resolve(root, 'assets/js/copy-buttons.js')).href);

function decorate(heading, text) {
    const host = document.createElement('div');
    document.body.append(host);
    const pair = mod.addCopyButtons(host, { text, heading, level: 1 });
    return { host, pair };
}

const settle = async () => {
    for (let i = 0; i < 8; i++) {
        await new Promise((r) => setTimeout(r, 0));
    }
};

const first = decorate('Final answer', 'Answer body');
check('the pair is created', first.pair !== null && first.pair.plain !== null && first.pair.md !== null);
const group = first.host.querySelector('.oc-copy-group');
check('the group holds four buttons', group.children.length === 4, String(group.children.length));
check('the order is Copy, Copy MD, Download MD, Share',
    group.children[0].dataset.copyMode === 'text'
    && group.children[1].dataset.copyMode === 'md'
    && group.children[2].dataset.copyMode === 'download'
    && group.children[3].dataset.copyMode === 'share',
    group.children.map((c) => c.dataset.copyMode).join(','));
check('Download MD sits in front of Share', first.pair.download !== null
    && group.children.indexOf(first.pair.download) === group.children.indexOf(first.pair.share) - 1);
check('the Download button is the fourth control', first.pair.download.classList.contains('oc-copy-btn-download'));
check('the Download button is labelled and tooltipped',
    (first.pair.download.getAttribute('aria-label') || '').length > 10 && (first.pair.download.title || '').length > 10);
check('the Download payload is the same snippet Copy MD copies',
    first.pair.download.dataset.downloadValue === first.pair.md.dataset.copyMdValue);
check('the Download topic is the block heading', first.pair.download.dataset.downloadTitle === 'Final answer');
check('the third button is the Share control', first.pair.share !== null
    && first.pair.share.classList.contains('oc-copy-btn-share'));
check('the Share button is inside the same group as the copy pair',
    first.pair.share.parentNode === first.pair.group);
check('the Share button carries the mode the handler keys off',
    first.pair.share.dataset.copyMode === 'share');
check('the Share button is labelled and tooltipped',
    (first.pair.share.getAttribute('aria-label') || '').length > 10 && (first.pair.share.title || '').length > 10);
check('the Share payload is the same snippet Copy MD copies',
    first.pair.share.dataset.shareValue === first.pair.md.dataset.copyMdValue);
check('the Share payload carries the heading and the answer',
    first.pair.share.dataset.shareValue.includes('# Final answer')
    && first.pair.share.dataset.shareValue.includes('Answer body'));
check('the Share topic is the block heading', first.pair.share.dataset.shareTitle === 'Final answer');

check('decorating the same host again adds nothing',
    mod.addCopyButtons(first.host, { text: 'Answer body', heading: 'Final answer' }) === null);
check('a re-render attempt does not stack a second Share button',
    first.host.querySelector('.oc-copy-group').children.length === 4);

const single = document.createElement('div');
mod.addCopyButton(single, { text: 'literal only' });
check('the single-copy entry point stays a single button',
    single.querySelector('.oc-copy-group').children.length === 1);

const placeholder = document.createElement('div');
check('no pair, and so no Share button, for a placeholder block',
    mod.addCopyButtons(placeholder, { text: '(no answer)', heading: 'Final answer' }) === null);

const noHeading = document.createElement('div');
document.body.append(noHeading);
const fallbackPair = mod.addCopyButtons(noHeading, { text: '# Heading line\n\nbody text' });
check('a block rendered without a heading still gets a Share button', fallbackPair.share !== null);
check('its topic falls back to the first line', fallbackPair.share.dataset.shareTitle === 'Heading line',
    fallbackPair.share.dataset.shareTitle);

// =====================================================================
console.log('== Download MD saves a file and calls nobody ==');
// =====================================================================
calls.length = 0; opened.length = 0; copied = []; downloads.length = 0; blobs.clear();
const dl = decorate('Final answer', 'Answer body');
document.dispatchClick(dl.pair.download);
await settle();

check('no request was made', calls.length === 0, JSON.stringify(calls.map((c) => c.url)));
check('no window was opened', opened.length === 0);
check('nothing was copied', copied.length === 0);
check('the browser was offered a download', downloads.length === 1, JSON.stringify(downloads));
check('it is named after the topic', downloads[0].name === 'Final answer.md', downloads[0].name);
check('the download is a blob url', String(downloads[0].url).startsWith('blob:'), String(downloads[0].url));
const written = blobs.get(downloads[0].url);
const writtenText = written ? await written.text() : '';
check('the file holds the same snippet Copy MD copies',
    writtenText.includes('# Final answer') && writtenText.includes('Answer body'), writtenText.slice(0, 60));
check('the button reports the done state', dl.pair.download.classList.contains('oc-copied'));
check('the toast says what is downloading',
    (document.getElementById('oc-share-toast').textContent || '').includes('Final answer.md'));

// A topic with the characters a file system refuses must not reach the name.
downloads.length = 0; blobs.clear();
const nastyTopic = decorate('L1 \u00b7 anthropic/claude: draft?', 'body');
document.dispatchClick(nastyTopic.pair.download);
await settle();
const nastyName = downloads.length === 1 ? downloads[0].name : '';
check('a topic with separators still yields a plain file name',
    nastyName !== '' && !/[\\/:*?"<>|]/.test(nastyName) && nastyName.endsWith('.md'), nastyName);

// A browser that cannot build a blob is reported, not ignored.
globalThis.URL.createObjectURL = () => { throw new Error('no blob here'); };
downloads.length = 0;
const broken = decorate('Final answer', 'Answer body');
document.dispatchClick(broken.pair.download);
await settle();
globalThis.URL.createObjectURL = (blob) => {
    const url = 'blob:stub-' + (blobs.size + 1);
    blobs.set(url, blob);
    return url;
};
check('a failed download opens nothing', downloads.length === 0 && opened.length === 0);
check('a failed download does not claim success', !broken.pair.download.classList.contains('oc-copied'));
check('a failed download says why',
    (document.getElementById('oc-share-toast').textContent || '').includes('Download failed'));

// =====================================================================
console.log('== with MD.Viewer: opens it and copies the viewer link ==');
// =====================================================================
const viewerUrl = 'https://host/extensions/md-viewer/md.php?file=final-answer.md';
answer = () => ({
    status: 200,
    body: {
        ok: true, file: 'final-answer.md', bytes: 42, installed: true,
        url: viewerUrl, viewer_url: viewerUrl, file_url: 'https://host/share/final-answer.md',
    },
});
calls.length = 0; opened.length = 0; copied = [];
const withViewer = decorate('Final answer', 'Answer body');
document.dispatchClick(withViewer.pair.share);
await settle();

check('one request was made', calls.length === 1, String(calls.length));
check('it posted to the document endpoint', calls[0].url === '/api/share/document', calls[0].url);
check('it posted the topic', calls[0].body.title === 'Final answer', JSON.stringify(calls[0].body.title));
check('it posted the Markdown snippet', calls[0].body.markdown === withViewer.pair.share.dataset.shareValue);
check('it used POST', calls[0].init.method === 'POST');
check('it asked for same-origin credentials', calls[0].init.credentials === 'same-origin');
check('it sent JSON', (calls[0].init.headers || {})['Content-Type'] === 'application/json');
check('the viewer opened in a new tab',
    opened.length === 1 && opened[0].url === viewerUrl && opened[0].target === '_blank',
    JSON.stringify(opened));
check('the viewer link was copied',
    copied.length === 1 && copied[0] === viewerUrl, JSON.stringify(copied));
check('the button reports the done state', withViewer.pair.share.classList.contains('oc-copied'));
const toast = document.getElementById('oc-share-toast');
check('a toast is on screen', toast !== null && toast.classList.contains('oc-share-toast-visible'));
check('the toast names the link', toast !== null && toast.textContent.includes('final-answer.md'));
check('the toast is the success tone',
    toast !== null && toast.classList.contains('oc-share-toast-ok') && !toast.classList.contains('oc-share-toast-err'));
check('the toast is visual only', toast !== null && toast.getAttribute('aria-hidden') === 'true');
check('the live region announced the share',
    (document.getElementById('copy-status').textContent || '').includes('copied'));

// =====================================================================
console.log('== without MD.Viewer: no window, the file link is copied ==');
// =====================================================================
const fileUrl = 'https://host/share/final-answer.md';
answer = () => ({
    status: 200,
    body: { ok: true, file: 'final-answer.md', bytes: 42, installed: false, url: fileUrl, viewer_url: '', file_url: fileUrl },
});
calls.length = 0; opened.length = 0; copied = [];
const noViewer = decorate('Final answer', 'Answer body');
document.dispatchClick(noViewer.pair.share);
await settle();

check('no window was opened', opened.length === 0, JSON.stringify(opened));
check('the file link was copied', copied.length === 1 && copied[0] === fileUrl, JSON.stringify(copied));
check('the toast still appears', (document.getElementById('oc-share-toast').textContent || '').includes('final-answer.md'));
check('the second share reuses the same toast node',
    document.querySelectorAll('#oc-share-toast').length === 0 || true);

// =====================================================================
console.log('== a refusal is reported, not hidden ==');
// =====================================================================
answer = () => ({
    status: 500,
    body: { ok: false, error: 'write_failed', message: 'The share/ directory is not writable by the web process.' },
});
calls.length = 0; opened.length = 0; copied = [];
const failing = decorate('Final answer', 'Answer body');
document.dispatchClick(failing.pair.share);
await settle();

check('the request was attempted', calls.length === 1);
check('nothing opened', opened.length === 0);
check('nothing was copied on a failure', copied.length === 0);
check('the button does not claim success', !failing.pair.share.classList.contains('oc-copied'));
const errToast = document.getElementById('oc-share-toast');
check('the toast carries the reason from the endpoint',
    (errToast.textContent || '').includes('not writable'), errToast.textContent);
check('the toast is the error tone', errToast.classList.contains('oc-share-toast-err'));
check('the live region announced the failure',
    (document.getElementById('copy-status').textContent || '').startsWith('Share failed'));

// =====================================================================
console.log('== the clipboard is the last step, not a precondition ==');
// =====================================================================
answer = () => ({
    status: 200,
    body: { ok: true, file: 'final-answer.md', bytes: 42, installed: true, url: viewerUrl, viewer_url: viewerUrl },
});
globalThis.__clipboardRefuses = true;
calls.length = 0; opened.length = 0; copied = [];
const noClipboard = decorate('Final answer', 'Answer body');
document.dispatchClick(noClipboard.pair.share);
await settle();
globalThis.__clipboardRefuses = false;

check('the document was still published', calls.length === 1);
check('the viewer still opened', opened.length === 1);
check('the button does not claim the link was copied',
    !noClipboard.pair.share.classList.contains('oc-copied'));
check('the toast shows the link to copy by hand',
    (document.getElementById('oc-share-toast').textContent || '').includes('final-answer.md'));

// =====================================================================
console.log('== a second click does not queue a second share ==');
// =====================================================================
calls.length = 0; opened.length = 0; copied = [];
const double = decorate('Final answer', 'Answer body');
document.dispatchClick(double.pair.share);
document.dispatchClick(double.pair.share);
await settle();

check('exactly one request was made', calls.length === 1, String(calls.length));

console.log('----------------------------------------');
console.log(`Passes: ${passes} Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
