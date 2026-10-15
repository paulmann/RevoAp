// katex-render.js — opportunistic KaTeX rendering for LLM answers.
//
// Why this exists (v1.8.29):
//   LLMs frequently emit LaTeX-style math in their answers — inline
//   `$E=mc^2$`, displayed `$$\int_0^1 x\,dx$$`, `\(...\)`, `\[...\]`,
//   and `\begin{align}...\end{align}` environments. The RevoAp UI
//   renders model answers as TEXT NODES (see assets/js/pipeline.js)
//   so untrusted output never reaches innerHTML. KaTeX's auto-render
//   contrib (`renderMathInElement`) scans a node's children and
//   REPLACES the matched math substrings with KaTeX-generated DOM
//   subtrees, leaving the surrounding text untouched. That preserves
//   our escaping model — no innerHTML on raw model output, and KaTeX
//   itself takes a string and outputs sanitised DOM nodes that cannot
//   carry script payloads.
//
// Behaviour:
//   - Targets are the LLM answer containers: `.oc-final-answer-text`
//     (live and history), `.oc-step-response` (per-role L1 answers),
//     `.oc-shared-final` (public read-only view), and the History
//     detail modal's answer body when present.
//   - We mark every processed node with `data-katex-rendered="1"` so
//     re-runs (SSE re-renders, tab switches) don't re-process the
//     same nodes — KaTeX nodes look like math but contain no math
//     delimiters, so a second pass would be a no-op anyway, but the
//     guard saves the work.
//   - We only render when the input string contains at least one
//     delimiter pair. This keeps non-math answers cost-free.
//   - The KaTeX runtime is loaded with `defer` in the template, so
//     this module waits for `DOMContentLoaded` AND for the
//     `renderMathInElement` global to appear before binding. If
//     KaTeX never loads (CDN blocked, offline host, CSP refusal),
//     the page degrades gracefully — text math stays as text.
//
// Public surface: none. Side-effect-only module. The runtime hooks
// into a MutationObserver so dynamically appended pipeline steps and
// re-rendered final answers are picked up without an explicit call
// from the consumer modules.

// v1.8.33: include the shared Consensus history containers so per-role
// L1 responses (and any other model-answer text rendered into the
// public read-only page) get the same KaTeX pass as the live chat.
// Both the per-step body and the per-response body are listed; the
// static container is also included so the initial scan picks it up
// before shared.js inserts its children.
import { enter, leave, mark } from './debug-trace.js';

// A node longer than this is not an answer any more, it is a document.
// Parsing it cannot help the reader and can pin the tab, so it is skipped
// and said so in the console instead of silently burning the CPU.
const MAX_NODE_CHARS = 60000;
const TARGET_SELECTOR = [
    '.oc-final-answer-text',
    '.oc-step-response',
    '.oc-shared-final',
    '.oc-history-detail-answer',
    '.oc-history-final-answer',
    '.oc-shared-step-body',
    '.oc-shared-step-response',
    '.oc-shared-history',
].join(', ');

// Heuristic gate — cheap regex that matches the delimiter pairs
// auto-render is configured for. Skipping non-math text avoids
// invoking the KaTeX walker on every re-render of every node.
const MATH_HINT = /(\$\$[\s\S]+?\$\$)|(\\\([\s\S]+?\\\))|(\\\[[\s\S]+?\\\])|(\\begin\{[a-zA-Z*]+\})/;


/**
 * Does this text contain anything auto-render is configured for?
 *
 * Exported because it is the guard that keeps code out of KaTeX: a Perl or
 * shell answer is full of `$sigils`, and treating those as maths is what
 * froze a real tab. The offline test drives this function directly.
 *
 * @param {unknown} text
 * @returns {boolean}
 */
export function looksLikeMath(text) {
    return typeof text === 'string' && text !== '' && MATH_HINT.test(text);
}
// Auto-render configuration. `$...$` is NOT enabled (see above): code in an
// answer would be parsed as maths. KaTeX still throws silently on bad syntax,
// so a malformed formula cannot break the page.
// dangerous macros (\url{javascript:...}) are rejected — matters even
// though source is text, because KaTeX builds the DOM from the macro.
const RENDER_OPTIONS = {
    delimiters: [
        { left: '$$', right: '$$', display: true },
        { left: '\\[', right: '\\]', display: true },
        { left: '\\(', right: '\\)', display: false },
        // `$...$` is deliberately NOT enabled. LLM answers routinely contain code
        // (Perl, shell, PHP) where `$` is a sigil rather than a maths delimiter, and
        // KaTeX is asked to parse every pair it can find. Measured on a real Perl
        // answer in History: 257 `$`, 128 pairs, ~9.7 KB of code - the tab froze
        // after the pane had painted. Use `$$...$$` for display maths.
    ],
    throwOnError: false,
    errorColor: 'inherit',
    strict: 'ignore',
    output: 'html',
};

const PROCESSED_ATTR = 'data-katex-rendered';

function katexAvailable() {
    return typeof window !== 'undefined'
        && typeof window.renderMathInElement === 'function';
}

function renderOne(node) {
    if (!(node instanceof Element)) return;
    if (node.hasAttribute(PROCESSED_ATTR)) return;
    const text = node.textContent || '';
    if (text.length > MAX_NODE_CHARS) {
        node.setAttribute(PROCESSED_ATTR, '1');
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('[katex-render] skipped a ' + text.length + ' character node (limit ' + MAX_NODE_CHARS + ')');
        }
        return;
    }
    if (!looksLikeMath(text)) {
        // Still mark to avoid scanning this exact node again next time.
        node.setAttribute(PROCESSED_ATTR, '1');
        return;
    }
    try {
    enter('katex:renderOne', { chars: text.length, dollars: (text.match(/\$/g) || []).length });
        window.renderMathInElement(node, RENDER_OPTIONS);
    leave('katex:renderOne');
    } catch (err) {
        leave('katex:renderOne', { failed: true });
        // Never let a math glitch break the page — log to console and
        // leave the original text in place.
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('[katex-render] renderMathInElement failed', err);
        }
    } finally {
        node.setAttribute(PROCESSED_ATTR, '1');
    }
}

function scanRoot(root) {
    if (!katexAvailable()) return;
    if (!root || typeof root.querySelectorAll !== 'function') return;
    for (const node of root.querySelectorAll(TARGET_SELECTOR)) {
        renderOne(node);
    }
    // Also process the root itself when it is a target — happens when
    // the MutationObserver hands us a freshly-inserted answer node.
    if (root instanceof Element && root.matches && root.matches(TARGET_SELECTOR)) {
        renderOne(root);
    }
}

function ready(fn) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
}

// Wait for KaTeX to be available, then bind. Since the script tags are
// `defer`, they will execute after the parser has built the DOM but
// before this module's `import`-deferred body runs — usually KaTeX is
// already present by the time we get here. If not (e.g. CDN slow), we
// poll for a short window before giving up.
function whenKatexReady(callback, attemptsLeft = 50) {
    if (katexAvailable()) {
        callback();
        return;
    }
    if (attemptsLeft <= 0) {
        // CDN never loaded — degrade silently.
        return;
    }
    setTimeout(() => whenKatexReady(callback, attemptsLeft - 1), 100);
}

function bootstrap() {
    // Initial pass over whatever is already in the DOM (typically the
    // shared view's final answer, or a step rendered before this
    // module ran).
    scanRoot(document.body);

    // Watch for new answer / step nodes added by the pipeline driver
    // and the history modal. We process the entire mutation target,
    // not just the added nodes, so a parent insertion with nested
    // answer divs is handled too.
    const observer = new MutationObserver((mutations) => {
        for (const m of mutations) {
            for (const added of m.addedNodes) {
                if (added.nodeType !== 1) continue; // Element only
                scanRoot(added);
            }
            // Also re-scan when an existing target has its children
            // replaced (renderFinalAnswer calls replaceChildren()).
            if (m.type === 'childList' && m.target instanceof Element
                && m.target.matches && m.target.matches(TARGET_SELECTOR)) {
                // Reset the processed marker so the new children get rendered.
                m.target.removeAttribute(PROCESSED_ATTR);
                renderOne(m.target);
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
}

ready(() => whenKatexReady(bootstrap));
