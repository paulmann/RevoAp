// tests/harness/dom-stub.mjs — a minimal, honest DOM for headless tests.
//
// It exists so the Models-tab checks can run the REAL browser modules
// (assets/js/models.js and everything it imports) instead of a paraphrase
// of them: the modules are loaded as genuine ES modules through
// vm.SourceTextModule and talk to this object graph exactly as they talk
// to a browser.
//
// What it implements is deliberately the subset the admin modules use —
// createElement / createTextNode / append / replaceChildren / classList /
// attributes / hidden / textContent / a small selector engine /
// addEventListener + click() / IntersectionObserver / localStorage /
// fetch. Nothing more. When a module starts using a DOM feature that is
// missing here, the harness fails loudly (a TypeError) rather than
// silently passing, which is the property that makes a stub like this
// worth having.
//
// `innerHTML` is intentionally absent: any module that reaches for it
// throws, so "renders with textContent only" is enforced by construction
// and not only by a source scan.

export class Node {
    constructor() {
        this.childNodes = [];
        this.parentNode = null;
    }
    get firstChild() { return this.childNodes.length > 0 ? this.childNodes[0] : null; }
    get children() { return this.childNodes.filter((n) => n instanceof Element); }
    append(...nodes) {
        for (const node of nodes) {
            const n = typeof node === 'string' ? new TextNode(node) : node;
            // A fragment is not a child: its contents are (insertion flattens
            // it), which is what makes `parent.append(fragment)` behave the
            // way every renderer here assumes.
            if (n instanceof DocumentFragment) { this.append(...[...n.childNodes]); continue; }
            if (n.parentNode) { n.parentNode.childNodes = n.parentNode.childNodes.filter((c) => c !== n); }
            n.parentNode = this;
            this.childNodes.push(n);
        }
    }
    /**
     * Mirror of append(), at the front: argument order is preserved, a text
     * string becomes a TextNode, and a fragment flattens into its children.
     */
    prepend(...nodes) {
        for (const node of [...nodes].reverse()) {
            const n = typeof node === 'string' ? new TextNode(node) : node;
            if (n instanceof DocumentFragment) { this.prepend(...[...n.childNodes]); continue; }
            if (n.parentNode) { n.parentNode.childNodes = n.parentNode.childNodes.filter((c) => c !== n); }
            n.parentNode = this;
            this.childNodes.unshift(n);
        }
    }
    replaceChildren(...nodes) {
        for (const child of this.childNodes) { child.parentNode = null; }
        this.childNodes = [];
        if (nodes.length > 0) { this.append(...nodes); }
    }
    /** Single-child form of append(), which several modules still use. */
    appendChild(node) {
        this.append(node);
        return node;
    }
    /** Nearest ancestor (or self) matching `selector`, or null. */
    closest(selector) {
        let node = this;
        while (node instanceof Element) {
            if (matches(node, selector)) { return node; }
            node = node.parentNode;
        }
        return null;
    }
    insertBefore(node, reference) {
        const n = typeof node === 'string' ? new TextNode(node) : node;
        if (!this.childNodes.includes(reference)) {
            throw new Error('insertBefore: the reference node is not a child of this node');
        }
        if (n.parentNode) { n.parentNode.childNodes = n.parentNode.childNodes.filter((c) => c !== n); }
        const index = this.childNodes.indexOf(reference);
        n.parentNode = this;
        this.childNodes.splice(index, 0, n);
        return n;
    }
    remove() {
        if (!this.parentNode) { return; }
        this.parentNode.childNodes = this.parentNode.childNodes.filter((c) => c !== this);
        this.parentNode = null;
    }
    removeChild(child) {
        const before = this.childNodes.length;
        this.childNodes = this.childNodes.filter((c) => c !== child);
        if (this.childNodes.length === before) {
            throw new Error('removeChild: node is not a child of this node');
        }
        child.parentNode = null;
        return child;
    }
    contains(node) {
        if (node === this) { return true; }
        return this.descendants().includes(node);
    }
    /**
     * Whether this node is attached to a root the harness declared as a
     * document (see installGlobals). Nodes created by createElement and
     * never appended are not connected, exactly like the real property.
     */
    get isConnected() {
        let node = this;
        while (node.parentNode) { node = node.parentNode; }
        return CONNECTED_ROOTS.has(node);
    }
    get parentElement() {
        return this.parentNode instanceof Element ? this.parentNode : null;
    }
}

/** Roots that count as "in the document" for isConnected. */
const CONNECTED_ROOTS = new Set();

/**
 * The element the last `focus()` call landed on, or null. One per process,
 * like a real document has one activeElement.
 */
let ACTIVE_ELEMENT = null;

export class TextNode extends Node {
    constructor(text) {
        super();
        this.nodeValue = String(text);
    }
    get textContent() { return this.nodeValue; }
    set textContent(v) { this.nodeValue = String(v); }
}

/**
 * A container whose children are inserted where it is appended, never as a
 * nested node — the one behaviour renderers rely on when they build a
 * subtree in a fragment and append it in one call.
 */
export class DocumentFragment extends Node {}

class ClassList {
    constructor(el) { this.el = el; this.set = new Set(); }
    _sync() { this.el._attrs.class = [...this.set].join(' '); }
    add(...names) { for (const n of names) { this.set.add(n); } this._sync(); }
    remove(...names) { for (const n of names) { this.set.delete(n); } this._sync(); }
    contains(name) { return this.set.has(name); }
    toggle(name, force) {
        const on = force === undefined ? !this.set.has(name) : !!force;
        if (on) { this.set.add(name); } else { this.set.delete(name); }
        this._sync();
        return on;
    }
}

export class Element extends Node {
    constructor(tagName) {
        super();
        this.tagName = String(tagName).toUpperCase();
        this._attrs = {};
        this.classList = new ClassList(this);
        this._listeners = new Map();
        // Layout numbers the section strip reads. Always zero: the harness
        // has no layout, and every consumer has to tolerate that (the real
        // code does — it only derives scroll offsets from them).
        this.offsetHeight = 0;
        this.offsetWidth = 0;
        this.offsetLeft = 0;
        this.scrollLeft = 0;
        // `value` follows the browser's reflection rule: until the property is
        // written, it mirrors the `value` attribute (which is how `el('input',
        // {value: 'x'})` is expected to read back); after a write it is the
        // written value. A plain data property would make every attribute-set
        // value read as "" and silently break any code that reads a form field
        // it did not create itself.
        this._value = undefined;
        // Same reflection rule for the checkbox/radio property: a `<input
        // type="checkbox" checked>` built by `el()` reads back as checked.
        this._checked = undefined;
        this.scrollCalls = [];
    }

    get value() {
        if (this._value !== undefined) { return this._value; }
        if (this._attrs.value !== undefined) { return this._attrs.value; }
        // A real <select> reports the value of its selected option (the first
        // one when none is marked). Without that, a dropdown a module filled
        // by marking an option `selected` would read back as '' — and a check
        // on it would be testing the stub instead of the shipped code, which
        // is the one thing this file exists to prevent.
        if (this.tagName === 'SELECT') {
            const options = this.descendants().filter((n) => n.tagName === 'OPTION');
            const chosen = options.find((o) => o.selected === true || o.hasAttribute('selected')) || options[0];
            if (chosen) {
                return chosen._value !== undefined ? String(chosen._value) : (chosen._attrs.value ?? '');
            }
        }
        return '';
    }
    set value(v) { this._value = v === null || v === undefined ? '' : String(v); }

    get checked() { return this._checked === undefined ? this.hasAttribute('checked') : this._checked; }
    set checked(v) { this._checked = v === true; }

    // `disabled` follows the same reflection rule a real form control uses
    // (and the one `el({disabled: true})` relies on): the attribute is the
    // truth, the property writes it. A plain data property would read as
    // `undefined` for an element the markup disabled, and every
    // "cannot be switched on" assertion would silently pass.
    get disabled() { return this.hasAttribute('disabled'); }
    set disabled(v) {
        if (v) { this.setAttribute('disabled', ''); } else { this.removeAttribute('disabled'); }
    }

    // Same reflection rule as `disabled`: `el.title = …` writes the
    // attribute, as it does in a browser (a plain property would leave a
    // tooltip the assertions cannot read, and — worse — no tooltip at all).
    get title() { return this.getAttribute('title') ?? ''; }
    set title(v) { this.setAttribute('title', String(v)); }

    get id() { return this._attrs.id || ''; }
    set id(v) { this._attrs.id = String(v); }
    get className() { return this._attrs.class || ''; }
    set className(v) {
        this._attrs.class = String(v);
        this.classList.set = new Set(String(v).split(/\s+/).filter((s) => s !== ''));
    }
    get hidden() { return Object.prototype.hasOwnProperty.call(this._attrs, 'hidden'); }
    set hidden(v) { if (v) { this._attrs.hidden = ''; } else { delete this._attrs.hidden; } }

    get dataset() {
        const el = this;
        return new Proxy({}, {
            get(_t, prop) {
                return el._attrs[datasetAttr(prop)];
            },
            set(_t, prop, value) {
                el.setAttribute(datasetAttr(prop), String(value));
                return true;
            },
        });
    }

    /**
     * Layout metrics. The stub has no renderer, so element measurements come
     * from `globalThis.__ocLayout(el)` when a test installed one, and are 0
     * otherwise — the same numbers a real browser reports for a node with no
     * layout (display:none). Consumers therefore have to tolerate 0 either
     * way, which is exactly the property that keeps the stub honest.
     *
     * A test that wants to exercise "does this text fit?" supplies the model:
     *   globalThis.__ocLayout = (el) => ({ scrollHeight: …, clientHeight: … });
     */
    get scrollHeight() { return layoutMetric(this, 'scrollHeight'); }
    get clientHeight() { return layoutMetric(this, 'clientHeight'); }

    setAttribute(name, value) {
        const key = String(name);
        this._attrs[key] = String(value);
        if (key === 'class') { this.classList.set = new Set(String(value).split(/\s+/).filter((s) => s !== '')); }
    }
    getAttribute(name) {
        const key = String(name);
        return Object.prototype.hasOwnProperty.call(this._attrs, key) ? this._attrs[key] : null;
    }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this._attrs, String(name)); }
    removeAttribute(name) { delete this._attrs[String(name)]; }

    get textContent() {
        return this.childNodes.map((c) => c.textContent).join('');
    }
    set textContent(v) {
        this.replaceChildren();
        if (v !== '') { this.append(new TextNode(v)); }
    }

    addEventListener(type, handler) {
        if (!this._listeners.has(type)) { this._listeners.set(type, []); }
        this._listeners.get(type).push(handler);
    }
    removeEventListener(type, handler) {
        const list = this._listeners.get(type) || [];
        this._listeners.set(type, list.filter((h) => h !== handler));
    }
    /** Fire a click the way a user would: one event, every listener. */
    click() {
        for (const handler of (this._listeners.get('click') || []).slice()) {
            handler({ type: 'click', target: this, preventDefault() {}, stopPropagation() {} });
        }
    }
    dispatchEvent(event) {
        for (const handler of (this._listeners.get(event.type) || []).slice()) { handler(event); }
    }
    /**
     * Focus. The stub owns no real focus ring, so "focused" is a flag on the
     * element plus document.activeElement — enough for the one claim the
     * modules make about it ("the panel's search field takes the focus when
     * the panel opens"), without pretending to model focus traversal.
     */
    focus() {
        if (ACTIVE_ELEMENT !== null && ACTIVE_ELEMENT !== this) { ACTIVE_ELEMENT._focused = false; }
        ACTIVE_ELEMENT = this;
        this._focused = true;
    }
    blur() {
        if (ACTIVE_ELEMENT === this) { ACTIVE_ELEMENT = null; }
        this._focused = false;
    }
    get focused() { return this._focused === true; }
    scrollIntoView() { this.scrollCalls.push('scrollIntoView'); }

    descendants() {
        const out = [];
        for (const child of this.childNodes) {
            if (child instanceof Element) { out.push(child, ...child.descendants()); }
        }
        return out;
    }
    /**
     * Id lookup scoped to this subtree. A real browser only has it on
     * `document`, but the parsed root *is* the document in this harness,
     * so exposing it here keeps the test code free of a separate handle.
     */
    getElementById(id) { return this.descendants().find((el) => el.id === id) || null; }
    querySelectorAll(selector) {
        // `:scope > x` means "a direct child of this element". matches()
        // has no notion of a query root, so the child-combinator form is
        // resolved here, where the root is known, and everything else keeps
        // the descendant search the modules rely on.
        const scoped = /^:scope\s*>\s*(.+)$/.exec(String(selector).trim());
        if (scoped) {
            return this.children.filter((el) => matches(el, scoped[1]));
        }
        return this.descendants().filter((el) => matches(el, selector));
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    matches(selector) { return matches(this, selector); }

    /** Not implemented on purpose — see the file header. */
    set innerHTML(_v) { throw new Error('innerHTML is not available in the harness (use textContent)'); }
    get innerHTML() { throw new Error('innerHTML is not available in the harness (use textContent)'); }
}

/** `data-fooBar` ⇄ attribute name, the way HTMLElement.dataset maps it. */
function datasetAttr(prop) {
    return 'data-' + String(prop).replace(/[A-Z]/g, (m) => '-' + m.toLowerCase());
}

/**
 * One layout number for an element, from the current test's layout model.
 * Absent model, absent number, or NaN all read as 0.
 */
function layoutMetric(el, name) {
    const model = globalThis.__ocLayout;
    if (typeof model !== 'function') { return 0; }
    const box = model(el) || {};
    const value = Number(box[name]);
    return Number.isFinite(value) ? value : 0;
}

/**
 * Support the selector shapes the admin modules use: an optional chain of
 * descendant combinators, each part being tag / #id / .class / [attr] /
 * [attr="value"] in any combination.
 */
function matches(el, selector) {
    const parts = String(selector).trim().split(/\s+/);
    if (parts.length > 1) {
        // Descendant: the last part must match, and some ancestor must
        // match the rest (right to left).
        if (!matches(el, parts[parts.length - 1])) { return false; }
        let node = el.parentNode;
        const rest = parts.slice(0, -1).join(' ');
        while (node) {
            if (node instanceof Element && matches(node, rest)) { return true; }
            node = node.parentNode;
        }
        return false;
    }
    const m = /^([a-zA-Z][a-zA-Z0-9]*)?((?:[.#][\w-]+|\[[^\]]+\])*)$/.exec(parts[0]);
    if (!m) { return false; }
    if (m[1] && el.tagName !== m[1].toUpperCase()) { return false; }
    const tokens = m[2].match(/[.#][\w-]+|\[[^\]]+\]/g) || [];
    for (const token of tokens) {
        if (token.startsWith('#')) {
            if (el.id !== token.slice(1)) { return false; }
        } else if (token.startsWith('.')) {
            if (!el.classList.contains(token.slice(1))) { return false; }
        } else {
            const inner = token.slice(1, -1);
            const eq = inner.indexOf('=');
            if (eq === -1) {
                if (!el.hasAttribute(inner)) { return false; }
            } else {
                const name = inner.slice(0, eq);
                const value = inner.slice(eq + 1).replace(/^["']|["']$/g, '');
                if (el.getAttribute(name) !== value) { return false; }
            }
        }
    }
    return true;
}

const VOID_TAGS = new Set(['meta', 'link', 'br', 'hr', 'img', 'input', 'source', 'area', 'col']);
const SKIP_TAGS = new Set(['script', 'style', 'svg']);

/**
 * Parse a well-formed HTML fragment into this object graph. It is not a
 * browser parser: it handles the tags the templates use, skips
 * script/style/svg subtrees, drops comments, and understands void
 * elements. Good enough to build the real layout.html, which is the point.
 */
export function parseHtml(html) {
    const root = new Element('div');
    const stack = [root];
    let i = 0;
    const text = String(html);
    while (i < text.length) {
        const lt = text.indexOf('<', i);
        if (lt === -1) { break; }
        if (lt > i) {
            const chunk = text.slice(i, lt);
            if (chunk.trim() !== '') { stack[stack.length - 1].append(new TextNode(chunk)); }
        }
        if (text.startsWith('<!--', lt)) {
            const end = text.indexOf('-->', lt);
            i = end === -1 ? text.length : end + 3;
            continue;
        }
        if (/^<!|^<\?/.test(text.slice(lt))) {
            const end = text.indexOf('>', lt);
            i = end === -1 ? text.length : end + 1;
            continue;
        }
        const close = text.indexOf('>', lt);
        if (close === -1) { break; }
        const raw = text.slice(lt + 1, close).trim();
        i = close + 1;
        if (raw.startsWith('/')) {
            const name = raw.slice(1).trim().toLowerCase();
            for (let s = stack.length - 1; s > 0; s--) {
                if (stack[s].tagName === name.toUpperCase()) { stack.length = s; break; }
            }
            continue;
        }
        const selfClosing = raw.endsWith('/');
        const body = selfClosing ? raw.slice(0, -1) : raw;
        const space = body.search(/\s/);
        const name = (space === -1 ? body : body.slice(0, space)).toLowerCase();
        const attrText = space === -1 ? '' : body.slice(space);
        if (SKIP_TAGS.has(name)) {
            // Skip the whole element: it holds no UI the tests touch.
            const closeTag = `</${name}>`;
            const end = text.toLowerCase().indexOf(closeTag, i);
            i = end === -1 ? text.length : end + closeTag.length;
            continue;
        }
        const el = new Element(name);
        const attrRe = /([a-zA-Z_:][\w:.-]*)(?:\s*=\s*("([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g;
        let a;
        while ((a = attrRe.exec(attrText)) !== null) {
            const value = a[3] ?? a[4] ?? a[5] ?? '';
            el.setAttribute(a[1], value);
        }
        stack[stack.length - 1].append(el);
        if (!VOID_TAGS.has(name) && !selfClosing) { stack.push(el); }
    }
    return root;
}

/** Build the globals a module expects, backed by `documentRoot`. */
export function installGlobals(documentRoot, options = {}) {
    // Document-level listeners are real (a Map), because delegated handlers
    // are load-bearing: copy-buttons.js attaches ONE click listener on the
    // document and resolves the button from `event.target` at click time.
    // They fire through document.dispatchClick(target), which is the
    // bubbling a real click would do for the modules under test.
    const documentListeners = new Map();

    const doc = {
        documentElement: new Element('html'),
        body: new Element('body'),
        get activeElement() { return ACTIVE_ELEMENT; },
        createElement: (tag) => new Element(tag),
        createTextNode: (text) => new TextNode(text),
        createDocumentFragment: () => new DocumentFragment(),
        getElementById: (id) => documentRoot.descendants().find((el) => el.id === id) || null,
        querySelector: (sel) => documentRoot.querySelector(sel),
        querySelectorAll: (sel) => documentRoot.querySelectorAll(sel),
        addEventListener: (type, handler) => {
            if (!documentListeners.has(type)) { documentListeners.set(type, []); }
            documentListeners.get(type).push(handler);
        },
        removeEventListener: (type, handler) => {
            documentListeners.set(type, (documentListeners.get(type) || []).filter((h) => h !== handler));
        },
        /** Fire a click on `target`, the way a browser delivers it. */
        dispatchClick: (target, extra = {}) => {
            const event = {
                type: 'click',
                target,
                preventDefault() {},
                stopPropagation() {},
                ...extra,
            };
            for (const handler of (documentListeners.get('click') || []).slice()) { handler(event); }
            return event;
        },
        readyState: 'complete',
    };
    doc.documentElement.style = {
        props: {},
        setProperty(name, value) { this.props[name] = value; },
        getPropertyValue(name) { return this.props[name] ?? ''; },
    };

    const storage = new Map();
    const localStorage = {
        getItem: (k) => (storage.has(k) ? storage.get(k) : null),
        setItem: (k, v) => { storage.set(k, String(v)); },
        removeItem: (k) => { storage.delete(k); },
        clear: () => storage.clear(),
        get size() { return storage.size; },
    };

    const observers = [];
    class IntersectionObserver {
        constructor(callback, opts) { this.callback = callback; this.options = opts || {}; this.targets = []; observers.push(this); }
        observe(target) { this.targets.push(target); }
        disconnect() { this.targets = []; }
        unobserve(target) { this.targets = this.targets.filter((t) => t !== target); }
    }

    class CustomEvent {
        constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
    }

    /**
     * A working EventTarget. ModelSelector extends it and dispatches a
     * `change` event after every mutation, so a stub without
     * dispatchEvent() would break the very behaviour under test rather
     * than exercise it.
     */
    class EventTarget {
        constructor() { this._targets = new Map(); }
        addEventListener(type, handler) {
            if (!this._targets.has(type)) { this._targets.set(type, []); }
            this._targets.get(type).push(handler);
        }
        removeEventListener(type, handler) {
            this._targets.set(type, (this._targets.get(type) || []).filter((h) => h !== handler));
        }
        dispatchEvent(event) {
            const handlers = (this._targets.get(event.type) || []).slice();
            for (const handler of handlers) { handler.call(this, event); }
            return handlers.length > 0;
        }
    }

    const timers = new Map();
    let timerSeq = 0;

    const g = globalThis;
    // Both roots count as "the document": the parsed layout markup and the
    // synthetic <body> a module may append to (copy-buttons.js's live
    // region, the jump-to-final-answer control). A node whose topmost
    // ancestor is either one is connected.
    CONNECTED_ROOTS.add(documentRoot);
    CONNECTED_ROOTS.add(doc.body);
    g.document = doc;
    g.window = {
        addEventListener: () => {},
        removeEventListener: () => {},
        document: doc,
        localStorage,
        location: { href: 'http://127.0.0.1/', origin: 'http://127.0.0.1' },
    };
    g.Node = Node;
    g.Element = Element;
    g.HTMLElement = Element;
    g.DocumentFragment = DocumentFragment;
    g.localStorage = localStorage;
    g.CustomEvent = CustomEvent;
    g.IntersectionObserver = IntersectionObserver;
    g.EventTarget = EventTarget;
    g.__observers = observers;
    g.__timers = timers;
    g.setInterval = (fn, ms) => { const id = ++timerSeq; timers.set(id, { fn, ms }); return id; };
    g.clearInterval = (id) => { timers.delete(id); };
    g.__runTimers = () => { for (const t of timers.values()) { t.fn(); } };
    // fetch is supplied by the caller: a test scenario needs to control
    // exactly which endpoint answers and with what.
    if (typeof options.fetch === 'function') { g.fetch = options.fetch; }
    return { document: doc, localStorage, observers, timers };
}
