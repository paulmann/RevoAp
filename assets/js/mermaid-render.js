// mermaid-render.js — opportunistic Mermaid diagram rendering for LLM
// answers (v1.8.41).
//
// Why this exists:
//   LLM outputs frequently describe flows, architectures, decision
//   trees, dependency graphs or state machines that are far easier to
//   read as a diagram than as prose. We ask the model (via the central
//   system prompt and the mathematics/physics skills) to emit such
//   structures as Mermaid fenced code blocks:
//
//       ```mermaid
//       flowchart LR
//         A --> B
//         B --> C
//       ```
//
//   This module scans the same LLM answer containers as
//   `katex-render.js` (`.oc-final-answer-text`, `.oc-step-response`,
//   `.oc-shared-final`, `.oc-history-detail-answer`,
//   `.oc-history-final-answer`, `.oc-shared-step-body`,
//   `.oc-shared-step-response`, `.oc-shared-history`), locates
//   fenced ```mermaid blocks INSIDE TEXT NODES ONLY, and replaces
//   each match with a sanitised <div class="oc-mermaid"> wrapping the
//   SVG that Mermaid produces from the diagram source.
//
// Safety:
//   - We never call innerHTML on raw model output. Text nodes are split
//     and re-inserted with `document.createTextNode`. The only HTML we
//     inject is the SVG that Mermaid itself produces via its parser.
//   - Mermaid is initialised with `securityLevel: 'strict'` and
//     `startOnLoad: false`. Strict mode HTML-escapes node labels and
//     disables foreign object / inline HTML in the diagram source.
//   - If the CDN fails to load (offline / blocked / CSP refusal) the
//     fenced ```mermaid block remains rendered as plain text and a
//     non-fatal warning is logged when log_to_console is enabled.
//
// Order with KaTeX:
//   Mermaid runs BEFORE KaTeX on the same insertion so the fenced
//   diagram source (which may contain `$` characters in node labels)
//   never reaches KaTeX's `$...$` matcher. KaTeX still re-scans the
//   surrounding text nodes after Mermaid mutates the DOM thanks to its
//   own MutationObserver.

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

const PROCESSED_ATTR    = 'data-mermaid-rendered';
const DIAGRAM_BLOCK_CLS = 'oc-mermaid';
const DIAGRAM_FALLBACK  = 'oc-mermaid-fallback';

// v11.12.2 — pinned ESM build served by jsDelivr. Pinning a specific
// version protects us from a Mermaid major bump silently changing the
// rendered output (or the security model) under our feet. To upgrade,
// bump this URL and re-run tests/SmokeTest.php which asserts the
// version + integrity of the URL.
const MERMAID_CDN =
    'https://cdn.jsdelivr.net/npm/mermaid@11.12.2/dist/mermaid.esm.min.mjs';

// Fenced code block matcher. We accept an optional leading newline +
// indentation so the matcher works whether the LLM emits the fence at
// the very start of the answer or after prose. The `[\s\S]+?` capture
// is non-greedy so adjacent diagrams are not merged.
const MERMAID_FENCE_RE = /```\s*mermaid\s*\n([\s\S]+?)\n?```/g;

const HINT_RE = /```\s*mermaid\b/;

let mermaidPromise = null;
let mermaidReady = false;

const LOG_TO_CONSOLE = (() => {
    if (typeof document === 'undefined') return false;
    const meta = document.querySelector('meta[name="oc-log-to-console"]');
    return !!meta && /^(1|true|yes|on)$/i.test(meta.getAttribute('content') || '');
})();

function logWarn(...args) {
    if (LOG_TO_CONSOLE && typeof console !== 'undefined' && console.warn) {
        console.warn('[mermaid-render]', ...args);
    }
}

function pickTheme() {
    if (typeof document === 'undefined' || !document.documentElement) return 'default';
    const t = (document.documentElement.getAttribute('data-theme') || '').toLowerCase();
    return t === 'light' ? 'default' : 'dark';
}

async function loadMermaid() {
    if (mermaidReady) return window.__ocMermaid || null;
    if (mermaidPromise) return mermaidPromise;
    mermaidPromise = (async () => {
        try {
            const mod = await import(MERMAID_CDN);
            const mermaid = mod && mod.default ? mod.default : mod;
            if (!mermaid || typeof mermaid.render !== 'function') {
                throw new Error('Mermaid module loaded but mermaid.render is missing.');
            }
            mermaid.initialize({
                startOnLoad: false,
                securityLevel: 'strict',
                theme: pickTheme(),
                fontFamily: 'inherit',
                deterministicIds: true,
                deterministicIDSeed: 'revoap',
                logLevel: 'fatal',
            });
            window.__ocMermaid = mermaid;
            mermaidReady = true;
            return mermaid;
        } catch (err) {
            logWarn('failed to load Mermaid runtime', err);
            mermaidPromise = null;
            return null;
        }
    })();
    return mermaidPromise;
}

let renderCounter = 0;
function nextDiagramId() {
    renderCounter += 1;
    return 'oc-mermaid-' + renderCounter.toString(36) + '-' + Date.now().toString(36);
}

async function renderDiagram(mermaid, source) {
    const id = nextDiagramId();
    try {
        const out = await mermaid.render(id, source);
        const svg = (out && typeof out.svg === 'string') ? out.svg : '';
        if (!svg) throw new Error('Mermaid returned empty SVG.');
        return svg;
    } catch (err) {
        logWarn('diagram render failed', err);
        return null;
    }
}

// Build a <div class="oc-mermaid"> with the rendered SVG. We use a
// dedicated wrapper so re-renders can target it explicitly and so the
// CSS can size / centre the diagram independently of the surrounding
// answer typography.
function buildDiagramNode(svg) {
    const wrap = document.createElement('div');
    wrap.className = DIAGRAM_BLOCK_CLS;
    wrap.setAttribute('role', 'img');
    wrap.setAttribute('aria-label', 'Mermaid diagram');
    // Mermaid's strict mode emits HTML-escaped labels, so assigning
    // .innerHTML here only inserts the SVG that Mermaid itself produced
    // — no untrusted text from the model reaches innerHTML.
    wrap.innerHTML = svg;
    return wrap;
}

function buildFallbackNode(source, reason) {
    const wrap = document.createElement('div');
    wrap.className = DIAGRAM_FALLBACK;
    const head = document.createElement('div');
    head.className = 'oc-mermaid-fallback-head';
    head.textContent = reason
        ? 'Mermaid diagram could not be rendered (' + reason + '). Showing source:'
        : 'Mermaid diagram could not be rendered. Showing source:';
    wrap.append(head);
    const pre = document.createElement('pre');
    pre.className = 'oc-mermaid-fallback-source';
    pre.textContent = source;
    wrap.append(pre);
    return wrap;
}

// Walk the target's direct text-node children and split each one on
// the ```mermaid ... ``` fence. Returns an array of either:
//   { kind: 'text', text }   — plain text to keep as a text node
//   { kind: 'mermaid', src } — diagram source to feed Mermaid
// or null when the input contained no fence (caller can skip).
function tokenizeText(text) {
    if (!HINT_RE.test(text)) return null;
    const tokens = [];
    let last = 0;
    MERMAID_FENCE_RE.lastIndex = 0;
    let m;
    while ((m = MERMAID_FENCE_RE.exec(text)) !== null) {
        if (m.index > last) {
            tokens.push({ kind: 'text', text: text.slice(last, m.index) });
        }
        tokens.push({ kind: 'mermaid', src: m[1] });
        last = MERMAID_FENCE_RE.lastIndex;
    }
    if (last < text.length) {
        tokens.push({ kind: 'text', text: text.slice(last) });
    }
    return tokens.length ? tokens : null;
}

async function processTarget(node, mermaid) {
    if (!(node instanceof Element)) return;
    if (node.hasAttribute(PROCESSED_ATTR)) return;
    // Mark first so re-entrant MutationObserver fires during our own
    // mutation don't reprocess this target in a tight loop.
    node.setAttribute(PROCESSED_ATTR, '1');

    // Collect the text nodes that are DIRECT children of the target.
    // The pipeline driver renders LLM answers as a single text node
    // child of `.oc-final-answer-text` / `.oc-step-response`, which is
    // exactly the case we care about. We still walk siblings so the
    // history detail (which uses .oc-history-block) keeps working.
    const textNodes = [];
    for (const c of Array.from(node.childNodes)) {
        if (c.nodeType === 3 /* TEXT_NODE */) textNodes.push(c);
    }
    if (textNodes.length === 0) return;

    let touched = false;
    for (const txt of textNodes) {
        const tokens = tokenizeText(txt.nodeValue || '');
        if (!tokens) continue;
        const frag = document.createDocumentFragment();
        for (const tok of tokens) {
            if (tok.kind === 'text') {
                if (tok.text.length > 0) frag.append(document.createTextNode(tok.text));
                continue;
            }
            if (!mermaid) {
                frag.append(buildFallbackNode(tok.src, 'Mermaid runtime unavailable'));
                continue;
            }
            const svg = await renderDiagram(mermaid, tok.src);
            if (svg) frag.append(buildDiagramNode(svg));
            else     frag.append(buildFallbackNode(tok.src, 'parse error'));
        }
        txt.parentNode.replaceChild(frag, txt);
        touched = true;
    }
    if (!touched) {
        // No fence after all — keep the processed marker, KaTeX will
        // still scan the unchanged text content via its own observer.
        return;
    }
}

function findTargets(root) {
    const list = [];
    if (!root || typeof root.querySelectorAll !== 'function') return list;
    for (const n of root.querySelectorAll(TARGET_SELECTOR)) list.push(n);
    if (root instanceof Element && root.matches && root.matches(TARGET_SELECTOR)) {
        list.push(root);
    }
    return list;
}

async function scanRoot(root) {
    const targets = findTargets(root);
    if (targets.length === 0) return;
    // Cheap pre-check: skip the dynamic import entirely if no target
    // contains a Mermaid fence. This keeps the page cost-free for
    // answers that have no diagrams.
    const candidates = targets.filter(t => HINT_RE.test(t.textContent || ''));
    if (candidates.length === 0) return;
    const mermaid = await loadMermaid();
    for (const t of candidates) {
        // The fast-path above only checked targets — if a target was
        // already processed (re-render) we still need to reset the
        // marker because its children were replaced.
        try { await processTarget(t, mermaid); }
        catch (err) { logWarn('processTarget failed', err); }
    }
}

function ready(fn) {
    if (typeof document === 'undefined') return;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
}

function bootstrap() {
    // Initial pass over whatever is already in the DOM (typically the
    // shared / read-only view's final answer).
    void scanRoot(document.body);

    // Watch for new answer / step nodes inserted by pipeline.js, the
    // history modal and shared.js. Same shape as katex-render.js so
    // both run in lockstep on every insertion.
    const observer = new MutationObserver((mutations) => {
        for (const m of mutations) {
            for (const added of m.addedNodes) {
                if (added.nodeType !== 1) continue;
                void scanRoot(added);
            }
            if (m.type === 'childList'
                && m.target instanceof Element
                && m.target.matches
                && m.target.matches(TARGET_SELECTOR)) {
                m.target.removeAttribute(PROCESSED_ATTR);
                void scanRoot(m.target);
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Re-theme when the operator toggles light/dark. We can't re-style
    // existing SVG cheaply — the cleanest path is to drop the rendered
    // diagrams + the processed markers, re-initialise Mermaid with the
    // new theme and let the next observer tick rebuild every diagram.
    // The page's <html data-theme="…"> is the canonical source: watching
    // that attribute keeps us decoupled from theme.js internals.
    if (typeof document !== 'undefined' && document.documentElement) {
        const html = document.documentElement;
        let lastTheme = (html.getAttribute('data-theme') || '').toLowerCase();
        const themeObserver = new MutationObserver(() => {
            const current = (html.getAttribute('data-theme') || '').toLowerCase();
            if (current === lastTheme) return;
            lastTheme = current;
            mermaidReady = false;
            mermaidPromise = null;
            for (const wrap of document.querySelectorAll('.' + DIAGRAM_BLOCK_CLS)) {
                wrap.remove();
            }
            for (const t of document.querySelectorAll('[' + PROCESSED_ATTR + ']')) {
                t.removeAttribute(PROCESSED_ATTR);
            }
            void scanRoot(document.body);
        });
        themeObserver.observe(html, { attributes: true, attributeFilter: ['data-theme'] });
    }
}

ready(bootstrap);
