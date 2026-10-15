/**
 * copy-buttons.js — copy-to-clipboard controls for LLM answer blocks.
 *
 * Every copyable block gets FOUR labelled buttons, not one:
 *
 *   Copy     — the block's text exactly as it is shown on screen;
 *   Copy MD  — the same text, byte for byte, wrapped in a Markdown
 *              snippet with the block's heading (and, where the renderer
 *              shows one, a metadata line) above it, so a paste into a
 *              chat, a ticket or an editor is self-describing;
 *   Download MD - that same snippet, saved by the browser as a `.md` file
 *              named after the topic. Nothing is sent anywhere and no
 *              window opens: the block lands in the downloads folder.
 *   Share    - writes that same snippet to share/<topic>.md through
 *              POST /api/share/document, copies a link to it, and opens
 *              the document in MD.Viewer when that extension is installed
 *              (the plain file URL is copied when it is not). It is the
 *              one action here that talks to the server, so it is also the
 *              one that can come back with a reason worth showing.
 *
 * Neither mode reformats the body. Model answers are already Markdown,
 * and any "cleanup" would corrupt them: "Copy MD" only PREPENDS a heading
 * line, "Copy" prepends nothing. Each button says so in its `title` and
 * `aria-label`, and both carry a visible text label — the difference
 * between the two must be readable, not guessed from an icon.
 *
 * Design notes
 * ------------
 * The text to copy is carried explicitly in `data-copy-value` /
 * `data-copy-md-value` on the button, written by addCopyButtons() from
 * the same string the renderer puts on screen. The click handler
 * therefore never walks the DOM looking for "the answer": it reads one
 * attribute. That matters because a stage card also contains a header, a
 * status badge, a stats table and a raw JSON pane — any
 * `closest()/textContent` scraping of the card would happily copy all of
 * that.
 *
 * A block's buttons live in one `.oc-copy-group` wrapper, inserted as a
 * single child of the block's header row. Idempotence is keyed on that
 * wrapper, so a re-render that reuses the host cannot stack buttons, and
 * the pair can never end up split across two re-renders.
 *
 * Clicks are handled by ONE delegated listener on `document`, so buttons
 * keep working in cards that are created, replaced or re-rendered later
 * by the SSE stream (pipeline.js rebuilds a step's whole <article> on
 * every update) without any re-binding.
 *
 * Clipboard strategy, in order:
 *   1. `navigator.clipboard.writeText()` in a secure context;
 *   2. otherwise a hidden off-screen <textarea> + `document.execCommand('copy')`,
 *      which is the only thing that works on plain-http deployments;
 *   3. if both fail, select the answer text so the user can copy by hand.
 *
 * This module deliberately reimplements nothing from the theme toggle,
 * Mermaid loader or file browser of the reference snippet it was modelled
 * on: this app already owns those (assets/js/theme.js,
 * assets/js/mermaid-render.js) and there is no files table here. Rendering
 * uses createElement/append/textContent only — never innerHTML.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.3.0
 * @since    2026-09-20
 */

/** Class carried by every generated button; the delegated handler keys off it. */
const BUTTON_CLASS = 'oc-copy-btn';

/** Wrapper holding a block's buttons; its presence means "already decorated". */
const GROUP_CLASS = 'oc-copy-group';

/** Added while the "Copied" state is showing. */
const COPIED_CLASS = 'oc-copied';

/** Id of the shared polite live region used to announce copy outcomes. */
const STATUS_ID = 'copy-status';

/** `data-copy-mode` values - which payload attribute a button reads. */
const MODE_TEXT = 'text';
const MODE_MD   = 'md';
/** The Share button; it reads data-share-value / data-share-title instead. */
const MODE_SHARE = 'share';

/** Class that marks the Share button inside a group (CSS + state). */
const SHARE_CLASS = 'oc-copy-btn-share';
/** The Download button; it reads data-download-value / data-download-title. */
const MODE_DOWNLOAD = 'download';

/** Class that marks the Download button inside a group (CSS + state). */
const DOWNLOAD_CLASS = 'oc-copy-btn-download';

/** How long the "Copied" affordance stays before reverting, in ms. */
const FEEDBACK_MS = 1800;

/** How long an announcement stays in the live region, in ms. */
const ANNOUNCE_MS = 3000;

/** How long the Share toast stays on screen before it fades out, in ms. */
const TOAST_MS = 3200;

/** Id of the single toast element; every share reuses that one node. */
const TOAST_ID = 'oc-share-toast';

/**
 * Endpoint that publishes a document. Fixed for this deployment; a harness
 * may point the module elsewhere through window.REVOAP_SHARE_ENDPOINT
 * instead of patching this file.
 */
const SHARE_ENDPOINT = '/api/share/document';

/** Visible label, tooltip and spoken label of the Share button. */
const LABEL_SHARE = 'Save this block as a document in share/ and copy a link to it';
const TITLE_SHARE = 'Save this block as a document named after its topic, copy the link '
    + 'to it, and open it in MD.Viewer when that extension is installed.';

/** Visible label, tooltip and spoken label of the Download button. */
const LABEL_DOWNLOAD = 'Download this block as a .md file';
const TITLE_DOWNLOAD = 'Save this block to your computer as a Markdown file named after '
    + 'its topic. Nothing is sent to the server and no window opens.';

/** Deepest Markdown heading level accepted for a snippet heading. */
const MAX_HEADING_LEVEL = 6;

/** Default tooltips. Callers may override per block via title/mdTitle. */
const TITLE_TEXT = 'Copy the text exactly as shown on screen — nothing is added, nothing is reformatted.';
const TITLE_MD = 'Copy the same text as a Markdown snippet: a heading line is added above it, '
    + 'the text itself is not reformatted.';

/**
 * Strings a renderer puts on screen when there is no answer yet.
 * A copy button on any of these would copy a placeholder, so they are
 * treated as "nothing to copy" and no button is created.
 */
const PLACEHOLDER_TEXTS = new Set([
    '(no response)',
    '(no answer)',
    '(no final answer)',
    '(empty)',
    '(awaiting full response from session events)',
    'awaiting model response…',
    'awaiting model response...',
    'loading…',
    'loading...',
]);

/**
 * Reset timers, keyed by button. A WeakMap keeps the timer off the DOM
 * node so nothing leaks a property name into the markup.
 *
 * @type {WeakMap<Element, ReturnType<typeof setTimeout>>}
 */
const resetTimers = new WeakMap();

/**
 * The element holding the *visible* answer text, keyed by button. Used
 * only for the "copy failed, select it for me" fallback — never for
 * reading the value that gets copied.
 *
 * @type {WeakMap<Element, Element>}
 */
const sourceNodes = new WeakMap();

/** Currently pending live-region clear timer. */
let announceTimer = null;

/** Currently pending toast fade-out timer. */
let toastTimer = null;

/** True once the delegated listener is installed (initCopyButtons is idempotent). */
let listening = false;

/**
 * Is this a real answer worth offering a copy button for?
 *
 * @param {unknown} text
 * @returns {boolean}
 */
export function isCopyable(text) {
    if (typeof text !== 'string') return false;
    const trimmed = text.trim();
    if (trimmed === '') return false;
    return !PLACEHOLDER_TEXTS.has(trimmed.toLowerCase());
}

/**
 * Wrap text in the Markdown snippet that "Copy MD" puts on the clipboard.
 *
 * The body is appended unchanged — only a heading line (and an optional
 * metadata line) is prepended, separated by blank lines. Newlines in the
 * wrapper are LF; the body keeps whatever line endings it already had.
 *
 * @param {string} text
 * @param {{heading?: unknown, level?: unknown, meta?: unknown}} [options]
 * @returns {string}
 */
function markdownSnippet(text, options = {}) {
    const heading = typeof options.heading === 'string' ? options.heading.trim() : '';
    const meta = typeof options.meta === 'string' ? options.meta.trim() : '';
    const rawLevel = Number(options.level);
    const level = Number.isInteger(rawLevel) && rawLevel > 0
        ? Math.min(rawLevel, MAX_HEADING_LEVEL)
        : 3;

    const blocks = [];
    if (heading !== '') blocks.push('#'.repeat(level) + ' ' + heading);
    if (meta !== '') blocks.push(meta);
    blocks.push(text);
    return blocks.join('\n\n');
}

/**
 * The shared polite live region. Created on demand and appended to <body>
 * (not to the template) so it exists on every page that loads this module,
 * including ones where the History tab is disabled.
 *
 * @returns {HTMLElement|null}
 */
function statusRegion() {
    let region = document.getElementById(STATUS_ID);
    if (region) return region;
    if (!document.body) return null;
    region = document.createElement('div');
    region.id = STATUS_ID;
    region.className = 'oc-copy-status';
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'true');
    document.body.append(region);
    return region;
}

/**
 * Announce a copy outcome to assistive tech.
 *
 * @param {string} message
 * @returns {void}
 */
function announce(message) {
    const region = statusRegion();
    if (!region) return;
    region.textContent = message;
    if (announceTimer !== null) clearTimeout(announceTimer);
    announceTimer = setTimeout(() => {
        announceTimer = null;
        region.textContent = '';
    }, ANNOUNCE_MS);
}

/**
 * Off-screen <textarea> + execCommand('copy').
 *
 * The textarea must stay in the layout (position:fixed off-screen) rather
 * than `display:none`: a non-rendered node is not selectable, and
 * execCommand('copy') then copies nothing.
 *
 * @param {string} text
 * @returns {void}
 * @throws {Error} when the browser refuses the synthetic copy.
 */
function legacyCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.top = '-9999px';
    area.style.left = '-9999px';
    area.style.opacity = '0';
    document.body.append(area);
    let ok = false;
    try {
        area.select();
        area.setSelectionRange(0, area.value.length);
        ok = document.execCommand('copy');
    } finally {
        area.remove();
    }
    if (!ok) throw new Error('document.execCommand("copy") was refused');
}

/**
 * Write text to the clipboard, preferring the async Clipboard API and
 * degrading to execCommand outside a secure context.
 *
 * @param {string} text
 * @returns {Promise<'clipboard'|'execCommand'>} which transport succeeded.
 */
async function writeToClipboard(text) {
    if (typeof navigator !== 'undefined' && navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return 'clipboard';
    }
    legacyCopy(text);
    return 'execCommand';
}

/**
 * Select an element's contents so the user can finish the copy by hand.
 *
 * @param {Element|undefined} node
 * @returns {boolean} whether a selection was made.
 */
function selectNode(node) {
    if (!(node instanceof Element) || !node.isConnected) return false;
    const selection = typeof window.getSelection === 'function' ? window.getSelection() : null;
    if (!selection) return false;
    try {
        const range = document.createRange();
        range.selectNodeContents(node);
        selection.removeAllRanges();
        selection.addRange(range);
        return true;
    } catch {
        return false;
    }
}

/**
 * Build the button's inner spans. Two glyph/label pairs are always present
 * and CSS shows whichever matches the current state, so no text is
 * rewritten at runtime and no innerHTML is needed.
 *
 * Each button carries a full label ("Copy MD" / "Copy") and a short one
 * ("MD" / "Copy") that replaces it on phone-width headers, so the two
 * buttons stay labelled and distinguishable there instead of collapsing
 * into two identical glyphs.
 *
 * @param {Document} doc
 * @param {string} mode
 * @returns {DocumentFragment}
 */
function buttonContent(doc, mode) {
    const isMd = mode === MODE_MD;
    const fragment = doc.createDocumentFragment();
    const parts = [
        ['span', 'oc-copy-btn-glyph', isMd ? '#' : '⧉'],
        ['span', 'oc-copy-btn-glyph oc-copy-btn-glyph-done', '✓'],
        ['span', 'oc-copy-btn-text', isMd ? 'Copy MD' : 'Copy'],
        ['span', 'oc-copy-btn-text-short', isMd ? 'MD' : 'Copy'],
        ['span', 'oc-copy-btn-text oc-copy-btn-text-done', 'Copied'],
    ];
    for (const [tag, className, text] of parts) {
        const node = doc.createElement(tag);
        node.className = className;
        node.setAttribute('aria-hidden', 'true');
        node.textContent = text;
        fragment.append(node);
    }
    return fragment;
}

/**
 * Create one button of a pair.
 *
 * @param {string} mode            MODE_TEXT or MODE_MD.
 * @param {string} value           The payload: literal text, or the snippet.
 * @param {string} label           aria-label for assistive tech.
 * @param {string} title           Tooltip.
 * @returns {HTMLButtonElement}
 */
function createButton(mode, value, label, title) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = mode === MODE_MD ? BUTTON_CLASS + ' oc-copy-btn-md' : BUTTON_CLASS;
    button.dataset.copyMode = mode;
    if (mode === MODE_MD) button.dataset.copyMdValue = value;
    else                  button.dataset.copyValue = value;
    button.dataset.copyState = 'idle';
    button.setAttribute('aria-label', label);
    button.title = title;
    button.append(buttonContent(document, mode));
    return button;
}

/**
 * Where the Share button posts. Reading the override at call time (rather
 * than once at import) keeps the module testable without a module loader.
 *
 * @returns {string}
 */
function shareEndpoint() {
    const override = typeof window !== 'undefined' && typeof window.REVOAP_SHARE_ENDPOINT === 'string'
        ? window.REVOAP_SHARE_ENDPOINT
        : '';
    return override !== '' ? override : SHARE_ENDPOINT;
}

/**
 * The topic a shared document is named after: the block's heading, or - for
 * a block rendered without one - its first non-empty line with any Markdown
 * heading marker removed. The server turns this into a safe file name, so it
 * is sent as written: the name should stay recognisable.
 *
 * @param {string} heading
 * @param {string} text
 * @returns {string}
 */
function topicFrom(heading, text) {
    const fromHeading = typeof heading === 'string' ? heading.trim() : '';
    if (fromHeading !== '') return fromHeading;
    for (const line of String(text).split(/\r?\n/)) {
        const trimmed = line.replace(/^#+\s*/, '').trim();
        if (trimmed !== '') return trimmed;
    }
    return '';
}

/**
 * Build the Share button's inner spans. Same idle/done discipline as the
 * copy buttons: both label pairs are always in the DOM and CSS shows one, so
 * nothing is rewritten at runtime and no innerHTML is needed.
 *
 * @param {Document} doc
 * @returns {DocumentFragment}
 */
function shareButtonContent(doc) {
    const fragment = doc.createDocumentFragment();
    const parts = [
        ['span', 'oc-copy-btn-glyph', '\u2197'],
        ['span', 'oc-copy-btn-glyph oc-copy-btn-glyph-done', '\u2713'],
        ['span', 'oc-copy-btn-text', 'Share'],
        ['span', 'oc-copy-btn-text-short', 'Share'],
        ['span', 'oc-copy-btn-text oc-copy-btn-text-done', 'Shared'],
    ];
    for (const [tag, className, text] of parts) {
        const node = doc.createElement(tag);
        node.className = className;
        node.setAttribute('aria-hidden', 'true');
        node.textContent = text;
        fragment.append(node);
    }
    return fragment;
}

/**
 * Create the Share button for one block.
 *
 * @param {string} topic   The name the document is published under.
 * @param {string} snippet What gets written - the same Markdown the
 *                         "Copy MD" button puts on the clipboard.
 * @returns {HTMLButtonElement}
 */
function createShareButton(topic, snippet) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = BUTTON_CLASS + ' ' + SHARE_CLASS;
    button.dataset.copyMode = MODE_SHARE;
    button.dataset.shareTitle = topic;
    button.dataset.shareValue = snippet;
    button.dataset.copyState = 'idle';
    button.setAttribute('aria-label', LABEL_SHARE);
    button.title = TITLE_SHARE;
    button.append(shareButtonContent(document));
    return button;
}

/**
 * Return a button to its idle appearance.
 *
 * @param {HTMLElement} button
 * @returns {void}
 */
function resetButton(button) {
    button.dataset.copyState = 'idle';
    button.classList.remove(COPIED_CLASS);
}

/**
 * Show the "Copied" state and schedule its reset.
 *
 * @param {HTMLElement} button
 * @returns {void}
 */
function markCopied(button) {
    button.dataset.copyState = 'copied';
    button.classList.add(COPIED_CLASS);
    const existing = resetTimers.get(button);
    if (existing !== undefined) clearTimeout(existing);
    const timer = setTimeout(() => {
        resetTimers.delete(button);
        resetButton(button);
    }, FEEDBACK_MS);
    resetTimers.set(button, timer);
}

/**
 * Perform the copy for one button, from that button's own payload.
 *
 * @param {HTMLElement} button
 * @returns {Promise<void>}
 */
async function copyFromButton(button) {
    const isMd = button.dataset.copyMode === MODE_MD;
    const text = (isMd ? button.dataset.copyMdValue : button.dataset.copyValue) ?? '';
    if (!isCopyable(text)) return;

    button.dataset.copyState = 'copying';
    const existing = resetTimers.get(button);
    if (existing !== undefined) { clearTimeout(existing); resetTimers.delete(button); }

    try {
        await writeToClipboard(text);
        markCopied(button);
        announce(isMd
            ? 'Markdown snippet copied to clipboard.'
            : 'Text copied to clipboard, exactly as shown.');
    } catch (error) {
        resetButton(button);
        const selected = selectNode(sourceNodes.get(button));
        const detail = error instanceof Error ? ` (${error.message})` : '';
        announce(selected
            ? `Copy failed${detail}. The answer text is selected — press Ctrl+C or Cmd+C.`
            : `Copy failed${detail}. Please select the answer text and copy it manually.`);
    }
}

/**
 * The shared toast: created once and reused, fades in, fades out on its
 * own. It is aria-hidden on purpose - the spoken message goes through the
 * live region, so a screen reader hears one announcement rather than two.
 *
 * @param {string} message
 * @param {'ok'|'err'} [tone]
 * @returns {void}
 */
function showToast(message, tone = 'ok') {
    if (!document.body) return;
    let toast = document.getElementById(TOAST_ID);
    if (!toast) {
        toast = document.createElement('div');
        toast.id = TOAST_ID;
        toast.className = 'oc-share-toast';
        toast.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span');
        label.className = 'oc-share-toast-text';
        toast.append(label);
        document.body.append(toast);
    }
    const label = toast.querySelector('.oc-share-toast-text');
    if (label) label.textContent = message;
    toast.classList.toggle('oc-share-toast-ok', tone !== 'err');
    toast.classList.toggle('oc-share-toast-err', tone === 'err');
    // Restart the fade even when a toast is already on screen.
    toast.classList.remove('oc-share-toast-visible');
    void (toast.offsetWidth ?? 0);
    toast.classList.add('oc-share-toast-visible');
    if (toastTimer !== null) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastTimer = null;
        toast.classList.remove('oc-share-toast-visible');
    }, TOAST_MS);
}

/**
 * The reason a share failed, taken from the endpoint's error envelope.
 *
 * @param {any} payload
 * @param {number} status
 * @returns {string}
 */
function shareFailureReason(payload, status) {
    if (payload && typeof payload.message === 'string' && payload.message !== '') return payload.message;
    if (payload && typeof payload.error === 'string' && payload.error !== '') return payload.error;
    return 'HTTP ' + status;
}

/**
 * Publish the block this button belongs to, copy the link, say so.
 *
 * Order matters: the document is written first, then the viewer opens, then
 * the link is copied. A clipboard that refuses is reported as "saved, copy
 * it from here" rather than as a failure - by then the document exists.
 *
 * @param {HTMLElement} button
 * @returns {Promise<void>}
 */
async function shareFromButton(button) {
    const snippet = button.dataset.shareValue ?? '';
    if (!isCopyable(snippet)) return;

    button.dataset.copyState = 'copying';
    const pending = resetTimers.get(button);
    if (pending !== undefined) { clearTimeout(pending); resetTimers.delete(button); }

    try {
        if (typeof fetch !== 'function') throw new Error('fetch is not available in this browser');
        const response = await fetch(shareEndpoint(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ title: button.dataset.shareTitle ?? '', markdown: snippet }),
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.ok !== true) {
            throw new Error(shareFailureReason(payload, response.status));
        }
        const link = typeof payload.url === 'string' ? payload.url : '';
        const viewerUrl = typeof payload.viewer_url === 'string' ? payload.viewer_url : '';
        if (payload.installed === true && viewerUrl !== '' && typeof window.open === 'function') {
            window.open(viewerUrl, '_blank', 'noopener');
        }
        let copied = false;
        if (link !== '') {
            try {
                await writeToClipboard(link);
                copied = true;
            } catch {
                copied = false;
            }
        }
        if (copied) {
            markCopied(button);
            showToast('Link copied: ' + link, 'ok');
            announce('Document saved and its link copied to clipboard.');
        } else {
            resetButton(button);
            showToast(link !== '' ? 'Saved. Copy the link: ' + link : 'Saved.', 'ok');
            announce('Document saved. The link could not be copied automatically, so it is shown on screen.');
        }
    } catch (error) {
        resetButton(button);
        const detail = error instanceof Error ? error.message : String(error);
        showToast('Share failed: ' + detail, 'err');
        announce('Share failed: ' + detail + '.');
    }
}

/**
 * Build the Download button's inner spans, in the same idle/done shape as
 * its neighbours.
 *
 * @param {Document} doc
 * @returns {DocumentFragment}
 */
function downloadButtonContent(doc) {
    const fragment = doc.createDocumentFragment();
    const parts = [
        ['span', 'oc-copy-btn-glyph', '\u2193'],
        ['span', 'oc-copy-btn-glyph oc-copy-btn-glyph-done', '\u2713'],
        ['span', 'oc-copy-btn-text', 'Download MD'],
        ['span', 'oc-copy-btn-text-short', 'MD\u2193'],
        ['span', 'oc-copy-btn-text oc-copy-btn-text-done', 'Downloaded'],
    ];
    for (const [tag, className, text] of parts) {
        const node = doc.createElement(tag);
        node.className = className;
        node.setAttribute('aria-hidden', 'true');
        node.textContent = text;
        fragment.append(node);
    }
    return fragment;
}

/**
 * Create the Download button for one block.
 *
 * @param {string} topic   The name the file is saved under.
 * @param {string} snippet The same Markdown the "Copy MD" button copies.
 * @returns {HTMLButtonElement}
 */
function createDownloadButton(topic, snippet) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = BUTTON_CLASS + ' ' + DOWNLOAD_CLASS;
    button.dataset.copyMode = MODE_DOWNLOAD;
    button.dataset.downloadTitle = topic;
    button.dataset.downloadValue = snippet;
    button.dataset.copyState = 'idle';
    button.setAttribute('aria-label', LABEL_DOWNLOAD);
    button.title = TITLE_DOWNLOAD;
    button.append(downloadButtonContent(document));
    return button;
}

/**
 * The file name the browser is offered for a download: the block's topic
 * with the characters a file system refuses replaced by a dash, trimmed and
 * capped, and the extension added once.
 *
 * Unlike the Share name this one is NOT transliterated: it never reaches a
 * server path, and the operator's own downloads folder reads better in the
 * language the block is written in.
 *
 * @param {string} topic
 * @param {string} text
 * @returns {string}
 */
function downloadFileName(topic, text) {
    const source = typeof topic === 'string' && topic.trim() !== '' ? topic : topicFrom('', text);
    const cleaned = String(source)
        .replace(/[\\/:*?"<>|\x00-\x1F\x7F]+/g, '-')
        .replace(/\s+/g, ' ')
        .replace(/^[.\s-]+|[.\s-]+$/g, '')
        .slice(0, 80)
        .trim();
    const base = cleaned !== '' ? cleaned : 'document';
    return base.toLowerCase().endsWith('.md') ? base : base + '.md';
}

/**
 * Save the block to the operator's machine. No request and no window: a
 * Blob is handed to the browser through an anchor with a `download` name,
 * which is what makes it a download rather than a navigation.
 *
 * @param {HTMLElement} button
 * @returns {Promise<void>}
 */
async function downloadFromButton(button) {
    const snippet = button.dataset.downloadValue ?? '';
    if (!isCopyable(snippet)) return;

    const name = downloadFileName(button.dataset.downloadTitle ?? '', snippet);
    const pending = resetTimers.get(button);
    if (pending !== undefined) { clearTimeout(pending); resetTimers.delete(button); }

    let objectUrl = '';
    try {
        if (typeof Blob !== 'function' || typeof URL === 'undefined'
            || typeof URL.createObjectURL !== 'function') {
            throw new Error('this browser cannot build a file to download');
        }
        objectUrl = URL.createObjectURL(new Blob([snippet], { type: 'text/markdown;charset=utf-8' }));
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.setAttribute('download', name);
        anchor.style.display = 'none';
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        markCopied(button);
        announce('Downloading ' + name + '.');
        showToast('Downloading ' + name, 'ok');
    } catch (error) {
        resetButton(button);
        const detail = error instanceof Error ? error.message : String(error);
        showToast('Download failed: ' + detail, 'err');
        announce('Download failed: ' + detail + '.');
    } finally {
        if (objectUrl !== '' && typeof URL !== 'undefined'
            && typeof URL.revokeObjectURL === 'function') {
            URL.revokeObjectURL(objectUrl);
        }
    }
}

/**
 * Document-level click handler. One listener covers every button, present
 * and future, because it resolves the target at click time.
 *
 * @param {MouseEvent} event
 * @returns {void}
 */
function onDocumentClick(event) {
    const target = event.target;
    const button = target instanceof Element ? target.closest(`.${BUTTON_CLASS}`) : null;
    if (!(button instanceof HTMLElement)) return;
    // Guard against a second click while the first is in flight or while
    // the "Copied" affordance is showing: re-issuing a clipboard write
    // there would restart the timer and shorten the visible feedback.
    if (button.dataset.copyState !== 'idle') return;
    if (button.dataset.copyMode === MODE_DOWNLOAD) {
        void downloadFromButton(button);
        return;
    }
    if (button.dataset.copyMode === MODE_SHARE) {
        void shareFromButton(button);
        return;
    }
    void copyFromButton(button);
}

/**
 * Add the copy control pair to an answer / query container.
 *
 * `text` is the block's text, taken from the same value the renderer
 * displays — the "Copy" payload is that string and nothing else. Pass
 * `heading` (and optionally `level` / `meta`) to also get a "Copy MD"
 * button whose payload is `<heading>` + `<meta>` + that same text.
 * Pass `source` (the element showing that text) when you want the failure
 * path to select it for the user.
 *
 * Returns null — adding no button at all — when the host is missing, when
 * the text is empty or a placeholder, or when the host is already
 * decorated. Callers can therefore call this unconditionally at every
 * render.
 *
 * @param {Element|null|undefined} host   Where the pair is appended (a card header row).
 * @param {{
 *   text?: unknown,
 *   source?: Element|null,
 *   heading?: string,
 *   level?: number,
 *   meta?: string,
 *   markdown?: boolean,
 *   download?: boolean,
 *   share?: boolean,
 *   label?: string,
 *   title?: string,
 *   mdLabel?: string,
 *   mdTitle?: string,
 * }} [options]
 * @returns {{group: Element, plain: HTMLButtonElement, md: HTMLButtonElement|null,
 *            download: HTMLButtonElement|null, share: HTMLButtonElement|null}|null}
 */
export function addCopyButtons(host, options = {}) {
    if (!(host instanceof Element)) return null;

    const text = typeof options.text === 'string' ? options.text : '';
    if (!isCopyable(text)) return null;

    // Idempotent per host: a re-render that reuses the host cannot stack buttons.
    if (host.querySelector(`:scope > .${GROUP_CLASS}`) !== null) return null;

    const heading = typeof options.heading === 'string' ? options.heading.trim() : '';
    const wantMd = options.markdown !== false && heading !== '';
    const source = options.source instanceof Element ? options.source : null;

    const group = document.createElement('span');
    group.className = GROUP_CLASS;

    const plain = createButton(
        MODE_TEXT,
        text,
        options.label ?? 'Copy the text to clipboard, exactly as shown',
        options.title ?? TITLE_TEXT,
    );
    group.append(plain);

    let md = null;
    let snippet = text;
    if (wantMd) {
        snippet = markdownSnippet(text, { heading, level: options.level, meta: options.meta });
        md = createButton(
            MODE_MD,
            snippet,
            options.mdLabel ?? 'Copy the text as a Markdown snippet (heading added, text unchanged)',
            options.mdTitle ?? TITLE_MD,
        );
        group.append(md);
    }

    // Download rides between the pair and Share: the same snippet, saved by
    // the browser itself. It is the only one of the four that neither
    // touches the clipboard nor the network.
    let download = null;
    if (options.download !== false) {
        download = createDownloadButton(topicFrom(heading, text), snippet);
        group.append(download);
    }

    // Share rides with the pair: it publishes the same snippet the Markdown
    // button copies, named after the block's topic. A caller that wants only
    // the literal copy control turns it off with `share: false`.
    let share = null;
    if (options.share !== false) {
        share = createShareButton(topicFrom(heading, text), snippet);
        group.append(share);
    }

    host.append(group);
    if (source) {
        sourceNodes.set(plain, source);
        if (md) sourceNodes.set(md, source);
    }
    return { group, plain, md, download, share };
}

/**
 * Add a single "Copy" button (no Markdown variant, no Download, no Share).
 *
 * Kept as a public entry point for callers that want only the literal
 * text, and for the out-of-tree copy-buttons harness that pins the
 * one-button-per-host behaviour. New render sites should use
 * addCopyButtons(), which decorates a block with the full pair.
 *
 * @param {Element|null|undefined} host
 * @param {{text?: unknown, source?: Element|null, label?: string, title?: string}} [options]
 * @returns {HTMLButtonElement|null}
 */
export function addCopyButton(host, options = {}) {
    const pair = addCopyButtons(host, { ...options, heading: '', markdown: false, share: false, download: false });
    return pair ? pair.plain : null;
}

/**
 * Install the shared live region and the delegated click listener.
 *
 * Idempotent, and safe to call before <body> exists: the live region is
 * created lazily on the first announcement, and clicks cannot happen
 * before the document is interactive.
 *
 * @returns {void}
 */
export function initCopyButtons() {
    if (listening) return;
    listening = true;
    document.addEventListener('click', onDocumentClick);
}

// Self-initialise on import so any page that loads this module gets
// working copy buttons for content it never explicitly decorated.
initCopyButtons();
