/**
 * ui/dom.js — the DOM primitives every component is built from.
 *
 * shadcn/ui is a React registry, so porting it to a vanilla SPA means porting
 * its *contract*, not its code. Three things are preserved exactly:
 *
 *   1. the class names (`.card`, `.btn`, `.badge`, …) so ui/tailwind.css
 *      styles them unchanged;
 *   2. the `data-slot` attribute on every part, so a caller can query
 *      `[data-slot="card-title"]` the same way it would in React;
 *   3. the ARIA contract — `role`, `aria-expanded`, `aria-controls`,
 *      `aria-modal`, focus trapping and `inert`. Accessibility is not a
 *      layer added later here; it is what the primitives do.
 *
 * Everything is built with `document.createElement` + property assignment.
 * There is no `innerHTML` with interpolated values anywhere in this module,
 * which is the single most important property of a UI kit that renders LLM
 * output, user-entered text and translations from the same code path.
 *
 * @project RevoAp   @since 2.0.0
 */

/**
 * Create an element.
 *
 * @param {string} tag
 * @param {object} [props]  Attributes, properties and listeners:
 *   - `class` / `className`  string or array of strings
 *   - `text`                  assigned via textContent (never innerHTML)
 *   - `html`                  assigned via innerHTML — ONLY for trusted
 *                             static markup written by the caller, never for
 *                             interpolated data
 *   - `dataset`               object merged into el.dataset
 *   - `style`                 object of CSS properties (camelCase)
 *   - `attrs`                 object of raw attributes
 *   - `on`                    object of event listeners, `{click: fn}`
 *   - `slot`                  shorthand for `data-slot`
 *   - anything else           assigned as a property when the element has it,
 *                             otherwise set as an attribute
 * @param {...(Node|string|null|undefined|Array)} children
 * @returns {HTMLElement}
 */
export function h(tag, props = {}, ...children) {
    const el = document.createElement(tag);
    if (props) {
        for (const [key, value] of Object.entries(props)) {
            if (value === null || value === undefined || value === false) continue;
            switch (key) {
                case 'class':
                case 'className':
                    el.className = Array.isArray(value) ? value.filter(Boolean).join(' ') : String(value);
                    break;
                case 'text':
                    el.textContent = String(value);
                    break;
                case 'html':
                    // Explicit, caller-supplied, never interpolated. Kept as a
                    // named option so it is greppable in review.
                    el.innerHTML = String(value);
                    break;
                case 'dataset':
                    for (const [dk, dv] of Object.entries(value ?? {})) {
                        if (dv !== null && dv !== undefined) el.dataset[dk] = String(dv);
                    }
                    break;
                case 'style':
                    if (typeof value === 'string') el.setAttribute('style', value);
                    else for (const [sk, sv] of Object.entries(value ?? {})) {
                        if (sv !== null && sv !== undefined) el.style[sk] = String(sv);
                    }
                    break;
                case 'attrs':
                    for (const [ak, av] of Object.entries(value ?? {})) {
                        if (av !== null && av !== undefined && av !== false) el.setAttribute(ak, String(av));
                    }
                    break;
                case 'on':
                    for (const [evt, fn] of Object.entries(value ?? {})) {
                        if (typeof fn === 'function') el.addEventListener(evt, fn);
                    }
                    break;
                case 'slot':
                    el.dataset.slot = String(value);
                    break;
                case 'ref':
                    if (typeof value === 'function') value(el);
                    else if (value && typeof value === 'object') value.current = el;
                    break;
                default:
                    if (key in el && key !== 'list' && key !== 'form') {
                        try { el[key] = value; break; } catch { /* read-only — fall through to attribute */ }
                    }
                    if (value === true) el.setAttribute(key, '');
                    else el.setAttribute(key, String(value));
            }
        }
    }
    append(el, children);
    return el;
}

/**
 * Append children, flattening arrays and skipping null/undefined/false.
 * Strings and numbers become text nodes, NOT parsed markup.
 *
 * @param {HTMLElement} el
 * @param {Array} children
 */
export function append(el, children) {
    for (const child of children.flat(8)) {
        if (child === null || child === undefined || child === false || child === true) continue;
        if (child instanceof Node) el.appendChild(child);
        else el.appendChild(document.createTextNode(String(child)));
    }
    return el;
}

/** Shorthand for a text node. */
export function txt(value) {
    return document.createTextNode(value === null || value === undefined ? '' : String(value));
}

/** SVG element helper — SVG needs its own namespace, `createElement` is not enough. */
const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * @param {string} tag
 * @param {object} [attrs]
 * @param {...(Node|string|null)} children
 */
export function svg(tag, attrs = {}, ...children) {
    const el = document.createElementNS(SVG_NS, tag);
    for (const [k, v] of Object.entries(attrs ?? {})) {
        if (v === null || v === undefined || v === false) continue;
        el.setAttribute(k, String(v));
    }
    for (const child of children.flat(4)) {
        if (child === null || child === undefined) continue;
        el.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return el;
}

/** Remove every child node. Cheaper and safer than `innerHTML = ''`. */
export function clear(el) {
    if (!el) return el;
    while (el.firstChild) el.removeChild(el.firstChild);
    return el;
}

/** Replace an element's children in one operation. */
export function replaceChildren(el, ...children) {
    clear(el);
    append(el, children);
    return el;
}

/** Toggle a class only when the state actually changes, to avoid layout churn. */
export function setClass(el, name, on) {
    if (!el) return;
    const want = !!on;
    if (el.classList.contains(name) !== want) el.classList.toggle(name, want);
}

/**
 * Debounce. Trailing edge by default; `leading: true` fires immediately and
 * then suppresses until the wait elapses.
 *
 * @template {(...args:any[]) => any} F
 * @param {F} fn
 * @param {number} wait
 * @param {{leading?:boolean}} [opts]
 * @returns {F & {cancel():void, flush():void}}
 */
export function debounce(fn, wait = 200, opts = {}) {
    let timer = 0;
    let lastArgs = null;
    const invoke = () => { timer = 0; if (lastArgs) { const a = lastArgs; lastArgs = null; fn(...a); } };
    const wrapped = (...args) => {
        lastArgs = args;
        const callNow = opts.leading && !timer;
        clearTimeout(timer);
        timer = setTimeout(invoke, wait);
        if (callNow) invoke();
    };
    wrapped.cancel = () => { clearTimeout(timer); timer = 0; lastArgs = null; };
    wrapped.flush = () => { if (timer) { clearTimeout(timer); invoke(); } };
    return wrapped;
}

/** Throttle to at most one call per `wait` ms, with a trailing invocation. */
export function throttle(fn, wait = 200) {
    let last = 0;
    let timer = 0;
    let lastArgs = null;
    return (...args) => {
        const now = Date.now();
        lastArgs = args;
        const remaining = wait - (now - last);
        if (remaining <= 0) {
            clearTimeout(timer);
            timer = 0;
            last = now;
            fn(...args);
        } else if (!timer) {
            timer = setTimeout(() => {
                timer = 0;
                last = Date.now();
                if (lastArgs) fn(...lastArgs);
            }, remaining);
        }
    };
}

/**
 * Generate a DOM id that is unique on the page.
 *
 * Needed because ARIA wiring (`aria-controls`, `aria-describedby`,
 * `aria-labelledby`) requires real ids, and a component rendered twice must
 * not collide. `crypto.randomUUID()` is overkill and not available on plain
 * HTTP origins, so this is a counter + random suffix.
 */
let idCounter = 0;
export function uid(prefix = 'rv') {
    idCounter += 1;
    const rand = Math.random().toString(36).slice(2, 8);
    return `${prefix}-${idCounter.toString(36)}-${rand}`;
}

/**
 * The focusable elements inside a container, in tab order.
 *
 * Mirrors what the browser considers reachable: not `disabled`, not
 * `[tabindex="-1"]`, not hidden, and not inside an `inert` subtree. Used by
 * the focus trap in dialog/sheet.
 *
 * @param {ParentNode} root
 * @returns {HTMLElement[]}
 */
export function focusables(root) {
    const SELECTOR = [
        'a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])',
        'audio[controls]', 'video[controls]', '[contenteditable]:not([contenteditable="false"])',
    ].join(',');
    return Array.from(root.querySelectorAll(SELECTOR)).filter((el) => {
        if (el.closest('[inert]')) return false;
        if (el.hasAttribute('hidden') || el.getAttribute('aria-hidden') === 'true') return false;
        // `offsetParent` is null for display:none, but also for position:fixed,
        // so the visibility check has to allow that case.
        if (el.offsetParent === null && getComputedStyle(el).position !== 'fixed') return false;
        return true;
    });
}

/**
 * Trap focus inside `container` while it is open.
 *
 * Returns a disposer. Tab and Shift+Tab wrap at both ends, which is what
 * `aria-modal="true"` promises a screen reader — and what makes a dialog
 * usable without a mouse.
 *
 * @param {HTMLElement} container
 * @param {{initialFocus?: HTMLElement|null, restoreTo?: HTMLElement|null}} [opts]
 * @returns {() => void}
 */
export function trapFocus(container, opts = {}) {
    const previouslyFocused = opts.restoreTo || document.activeElement;

    const onKeydown = (ev) => {
        if (ev.key !== 'Tab') return;
        const items = focusables(container);
        if (items.length === 0) { ev.preventDefault(); container.focus?.(); return; }
        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;
        if (ev.shiftKey && (active === first || !container.contains(active))) {
            ev.preventDefault();
            last.focus();
        } else if (!ev.shiftKey && active === last) {
            ev.preventDefault();
            first.focus();
        }
    };

    container.addEventListener('keydown', onKeydown);

    // Move focus in on the next frame: the element must be in the document and
    // visible for `.focus()` to take, and a sheet is still animating in.
    const raf = requestAnimationFrame(() => {
        const target = opts.initialFocus
            || container.querySelector('[data-autofocus]')
            || focusables(container)[0]
            || container;
        try { target.focus({ preventScroll: true }); } catch { target.focus?.(); }
    });

    return () => {
        cancelAnimationFrame(raf);
        container.removeEventListener('keydown', onKeydown);
        // Restoring focus is what makes "close the dialog and keep typing in
        // the field you were in" work. Guarded because the trigger may itself
        // have been removed by a re-render.
        if (previouslyFocused && document.contains(previouslyFocused)) {
            try { previouslyFocused.focus({ preventScroll: true }); } catch { /* detached */ }
        }
    };
}

/**
 * Prevent the page behind an overlay from scrolling.
 *
 * Reference-counted: two stacked dialogs must not unblock the page when the
 * inner one closes. `paddingRight` compensates for the scrollbar that
 * disappears, so the layout does not jump sideways — visible in RTL too,
 * where the scrollbar is on the left.
 */
let scrollLockCount = 0;
let savedBodyOverflow = '';
let savedBodyPadding = '';
export function lockScroll(on) {
    if (on) {
        scrollLockCount += 1;
        if (scrollLockCount === 1) {
            const scrollbar = window.innerWidth - document.documentElement.clientWidth;
            savedBodyOverflow = document.body.style.overflow;
            savedBodyPadding = document.body.style.paddingInlineEnd;
            document.body.style.overflow = 'hidden';
            if (scrollbar > 0) document.body.style.paddingInlineEnd = `${scrollbar}px`;
        }
    } else {
        scrollLockCount = Math.max(0, scrollLockCount - 1);
        if (scrollLockCount === 0) {
            document.body.style.overflow = savedBodyOverflow;
            document.body.style.paddingInlineEnd = savedBodyPadding;
        }
    }
}

/** True when the user has asked the OS to reduce motion. */
export function prefersReducedMotion() {
    return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** True when the viewport is at or below the mobile breakpoint. */
export function isMobile(breakpoint = 768) {
    return typeof window.matchMedia === 'function' && window.matchMedia(`(max-width: ${breakpoint - 1}px)`).matches;
}

/**
 * Copy text to the clipboard, with the legacy fallback.
 *
 * `navigator.clipboard` is undefined on plain-HTTP origins, which is exactly
 * how most intranet deployments of this console run. The `execCommand` path
 * is deprecated but still universally supported and is the only thing that
 * works there; the fallback is why the Copy button does not silently do
 * nothing on http://10.0.0.5.
 *
 * @param {string} text
 * @returns {Promise<boolean>}
 */
export async function copyText(text) {
    const value = String(text ?? '');
    if (navigator.clipboard && window.isSecureContext) {
        try { await navigator.clipboard.writeText(value); return true; }
        catch { /* fall through to the legacy path */ }
    }
    try {
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, value.length);
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch {
        return false;
    }
}

/**
 * Escape a string for safe insertion into an HTML attribute or text node.
 *
 * Provided for the two places in the app that still build an HTML string
 * (the print view and the Markdown exporter). It is NOT a sanitiser — it
 * escapes the five characters that can terminate an attribute or start a tag
 * and nothing more.
 */
export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Wait for the next paint. `requestAnimationFrame` alone fires BEFORE the
 * paint, so a class added in the same frame is never seen transitioning.
 * Two rAFs is the reliable way to start a CSS transition on a fresh element.
 */
export function nextFrame() {
    return new Promise((resolve) => {
        requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
    });
}

/**
 * Observe an element and call back with its size.
 *
 * Wraps ResizeObserver where available and falls back to a throttled window
 * resize listener, because the sidebar collapse and the sticky section strip
 * both need to react to their own box changing, not just the viewport's.
 *
 * @param {HTMLElement} el
 * @param {(entry:{width:number,height:number}) => void} cb
 * @returns {() => void} disposer
 */
export function observeSize(el, cb) {
    if (!el) return () => {};
    if (typeof ResizeObserver === 'function') {
        const ro = new ResizeObserver((entries) => {
            const box = entries[0]?.contentRect;
            cb({ width: box?.width ?? 0, height: box?.height ?? 0 });
        });
        ro.observe(el);
        return () => ro.disconnect();
    }
    const handler = throttle(() => {
        const rect = el.getBoundingClientRect();
        cb({ width: rect.width, height: rect.height });
    }, 150);
    window.addEventListener('resize', handler, { passive: true });
    handler();
    return () => window.removeEventListener('resize', handler);
}

export default {
    h, txt, svg, append, clear, replaceChildren, setClass,
    debounce, throttle, uid, focusables, trapFocus, lockScroll,
    prefersReducedMotion, isMobile, copyText, escapeHtml, nextFrame, observeSize,
};
