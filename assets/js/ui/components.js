/**
 * ui/components.js — the shadcn/ui primitive layer, in vanilla JS.
 *
 * Every factory returns a real DOM node built by ui/dom.js, carries the same
 * `data-slot` / `data-state` / `data-variant` attributes as the React
 * original, and is styled by the `@layer components` block in ui/tailwind.css.
 * Swapping this module for the React registry would therefore change the
 * implementation and not the markup.
 *
 * Overlay stack
 * -------------
 * Dialog, Sheet, Dropdown, Tooltip and the Command palette all render into one
 * portal root (`#revoap-overlay-root`) and register themselves on a stack.
 * That single mechanism is what makes four things work at once and would
 * otherwise be four separate bugs:
 *
 *   - Escape closes only the TOPMOST layer (not everything);
 *   - the scroll lock is reference-counted, so closing an inner dialog does
 *     not unblock a page that is still behind an outer one;
 *   - `inert` is applied to the rest of the document while a modal is open,
 *     which is what actually removes the background from the accessibility
 *     tree — `aria-hidden` alone does not, and screen-reader users could
 *     otherwise tab into a page that is visually gone;
 *   - focus returns to the control that opened the layer.
 *
 * Nothing here uses `innerHTML` with interpolated values. Text goes through
 * `textContent`; the only `html` option is caller-supplied static markup.
 *
 * @project RevoAp   @since 2.0.0
 */
import { h, txt, clear, append, uid, focusables, trapFocus, lockScroll, nextFrame, prefersReducedMotion } from './dom.js';
import { icon, spinner } from './icons.js';

/* =====================================================================
 * Portal root + overlay stack
 * =================================================================== */

let overlayRoot = null;

/** The single container every floating layer renders into. */
export function portal() {
    if (overlayRoot && overlayRoot.isConnected) return overlayRoot;
    overlayRoot = document.getElementById('revoap-overlay-root');
    if (!overlayRoot) {
        overlayRoot = h('div', {
            id: 'revoap-overlay-root',
            attrs: { 'data-print-hide': '' },
        });
        document.body.appendChild(overlayRoot);
    }
    return overlayRoot;
}

/** @type {Array<{layer:HTMLElement, close:Function, modal:boolean, dispose:Function|null}>} */
const stack = [];

function pushLayer(layer, close, { modal = true } = {}) {
    const entry = { layer, close, modal, dispose: null };
    stack.push(entry);
    portal().appendChild(layer);
    if (modal) applyInert(true);
    return entry;
}

function popLayer(entry) {
    const index = stack.indexOf(entry);
    if (index === -1) return;
    stack.splice(index, 1);
    entry.layer.remove();
    // Release inertness only when the last modal is gone; an open dropdown on
    // top of a dialog must not unlock the page.
    if (entry.modal && !stack.some((e) => e.modal)) applyInert(false);
}

/**
 * Mark everything outside the topmost modal as inert.
 *
 * `inert` is the correct tool and it is baseline since 2022. Where it is
 * missing, `aria-hidden` is set as a partial substitute: it does not block
 * pointer events, but it does remove the subtree from the accessibility tree,
 * which is the more important half.
 */
function applyInert(on) {
    const top = [...stack].reverse().find((e) => e.modal);
    for (const child of Array.from(document.body.children)) {
        if (child === portal() || child.tagName === 'SCRIPT') continue;
        const outside = !top || !child.contains(top.layer);
        if (on && outside) {
            child.setAttribute('inert', '');
            child.setAttribute('aria-hidden', 'true');
            child.dataset.revoapInert = '1';
        } else if (child.dataset.revoapInert) {
            child.removeAttribute('inert');
            child.removeAttribute('aria-hidden');
            delete child.dataset.revoapInert;
        }
    }
}

/** Close the topmost layer. Returns true when something was closed. */
export function closeTopLayer() {
    const entry = stack[stack.length - 1];
    if (!entry) return false;
    entry.close();
    return true;
}

/** Close every open layer. Used on a hard navigation (tab switch). */
export function closeAllLayers() {
    while (stack.length) stack[stack.length - 1].close();
}

/** Global Escape handler — one listener for the whole stack. */
if (typeof document !== 'undefined' && !document.__revoapOverlayEscBound) {
    document.__revoapOverlayEscBound = true;
    document.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Escape' || stack.length === 0) return;
        // Only the topmost layer reacts; an Escape inside a dialog that also
        // has an open dropdown must close the dropdown alone.
        ev.preventDefault();
        ev.stopPropagation();
        closeTopLayer();
    }, true);
}

/* =====================================================================
 * Button
 * =================================================================== */

/**
 * @param {object} opts
 * @param {'default'|'secondary'|'destructive'|'outline'|'ghost'|'link'|'success'} [opts.variant='default']
 * @param {'default'|'sm'|'lg'|'icon'} [opts.size='default']
 * @param {string} [opts.iconName]     leading icon
 * @param {string} [opts.trailingIcon] trailing icon
 * @param {string} [opts.label]        text label (assigned as textContent)
 * @param {boolean} [opts.loading]
 * @param {string} [opts.ariaLabel]    REQUIRED when there is no visible label
 * @param {Function} [opts.onClick]
 */
export function Button(opts = {}) {
    const {
        variant = 'default', size = 'default', iconName = null, trailingIcon = null,
        label = null, loading = false, ariaLabel = null, onClick = null, disabled = false,
        type = 'button', title = null, ...rest
    } = opts;

    const classes = ['btn'];
    if (variant !== 'default') classes.push(variant === 'destructive' ? 'btn-destructive' : `btn-${variant}`);
    if (size === 'sm') classes.push('btn-sm');
    else if (size === 'lg') classes.push('btn-lg');
    else if (size === 'icon') classes.push('btn-icon');
    if (opts.class) classes.push(opts.class);

    const el = h('button', {
        class: classes,
        type,
        disabled: disabled || loading,
        attrs: {
            ...(ariaLabel ? { 'aria-label': ariaLabel } : {}),
            ...(title ? { title } : {}),
            ...(loading ? { 'aria-busy': 'true' } : {}),
            'data-variant': variant,
            'data-size': size,
            'data-slot': 'button',
        },
        on: onClick ? { click: onClick } : undefined,
        ...rest,
    });

    if (loading) el.appendChild(spinner(14, 'btn-spinner'));
    if (iconName) el.appendChild(icon(iconName, { size: size === 'lg' ? 18 : 16 }));
    if (label !== null) el.appendChild(h('span', { text: label }));
    if (trailingIcon) el.appendChild(icon(trailingIcon, { size: size === 'lg' ? 18 : 16 }));
    return el;
}

/** Toggle the loading state of an existing button without rebuilding it. */
export function setButtonLoading(el, loading, { spinnerOnly = false } = {}) {
    if (!el) return;
    if (loading) {
        el.setAttribute('aria-busy', 'true');
        el.disabled = true;
        if (!el.querySelector('.btn-spinner')) {
            const sp = spinner(14, 'btn-spinner');
            if (spinnerOnly) el.prepend(sp);
            else el.prepend(sp);
        }
    } else {
        el.removeAttribute('aria-busy');
        el.disabled = false;
        el.querySelector('.btn-spinner')?.remove();
    }
}

/* =====================================================================
 * Card
 * =================================================================== */

/**
 * @param {{title?:string, description?:string, actions?:Node[], footer?:Node[], class?:string, interactive?:boolean}} opts
 * @param {...Node} children content
 */
export function Card(opts = {}, ...children) {
    const el = h('section', {
        class: ['card', opts.interactive ? 'card-interactive' : '', opts.class].filter(Boolean).join(' '),
        attrs: { 'data-slot': 'card' },
    });

    if (opts.title || opts.description || opts.actions?.length) {
        const header = h('div', { class: 'card-header', attrs: { 'data-slot': 'card-header' } });
        const row = h('div', { class: 'card-header-row' });
        const left = h('div', { class: 'stack-sm' });
        if (opts.eyebrow) left.appendChild(h('div', { class: 'xs muted uppercase tracking-wide font-medium', text: opts.eyebrow }));
        if (opts.title) left.appendChild(h('h3', { class: 'card-title', attrs: { 'data-slot': 'card-title' }, text: opts.title }));
        if (opts.description) left.appendChild(h('p', { class: 'card-description', attrs: { 'data-slot': 'card-description' }, text: opts.description }));
        row.appendChild(left);
        if (opts.actions?.length) {
            row.appendChild(h('div', { class: 'row wrap', attrs: { 'data-slot': 'card-actions' } }, opts.actions));
        }
        header.appendChild(row);
        el.appendChild(header);
    }

    const content = h('div', { class: 'card-content', attrs: { 'data-slot': 'card-content' } });
    append(content, children);
    el.appendChild(content);

    if (opts.footer) {
        el.appendChild(h('div', { class: 'card-footer', attrs: { 'data-slot': 'card-footer' } }, opts.footer));
    }
    return el;
}

/* =====================================================================
 * Badge / Alert / Stat
 * =================================================================== */

/**
 * @param {'default'|'secondary'|'destructive'|'outline'|'success'|'warning'|'info'|'muted'} [tone]
 */
export function Badge(label, { tone = 'secondary', dot = false, pulse = false, class: cls = '', title = null } = {}) {
    const classes = ['badge', tone === 'default' ? 'badge-primary' : `badge-${tone}`];
    if (dot) classes.push('badge-dot');
    if (pulse && dot) classes.push('badge-pulse');
    if (cls) classes.push(cls);
    return h('span', {
        class: classes.join(' '),
        attrs: { 'data-slot': 'badge', 'data-tone': tone, ...(title ? { title } : {}) },
        text: label,
    });
}

/**
 * @param {'default'|'success'|'warning'|'destructive'|'info'} [tone]
 */
export function Alert({ tone = 'default', title = null, description = null, iconName = null, actions = null, dismissible = false, onDismiss = null } = {}) {
    const iconNameResolved = iconName || ({
        success: 'circleCheck', warning: 'alertTriangle', destructive: 'circleX', info: 'info',
    })[tone] || 'info';

    const el = h('div', {
        class: `alert ${tone === 'default' ? '' : `alert-${tone}`}`.trim(),
        attrs: { 'data-slot': 'alert', role: tone === 'destructive' || tone === 'warning' ? 'alert' : 'status' },
    });
    el.appendChild(icon(iconNameResolved, { size: 16, class: 'alert-icon' }));

    const body = h('div', { class: 'alert-body stack-sm' });
    if (title) body.appendChild(h('div', { class: 'alert-title', text: title }));
    if (description) body.appendChild(h('div', { class: 'alert-description', text: description }));
    if (actions?.length) body.appendChild(h('div', { class: 'row wrap mt-1' }, actions));
    el.appendChild(body);

    if (dismissible) {
        el.appendChild(Button({
            variant: 'ghost', size: 'icon', iconName: 'x', class: 'self-start -mt-1 -me-1 opacity-70',
            ariaLabel: 'Dismiss', onClick: () => { el.remove(); onDismiss?.(); },
        }));
    }
    return el;
}

/** A dashboard metric tile. */
export function Stat({ label, value, hint = null, icon: iconName = null, trend = null, trendValue = null } = {}) {
    const el = h('div', { class: 'stat', attrs: { 'data-slot': 'stat' } });
    const labelRow = h('div', { class: 'stat-label' });
    if (iconName) labelRow.appendChild(icon(iconName, { size: 14 }));
    labelRow.appendChild(h('span', { text: label }));
    el.appendChild(labelRow);

    const valueRow = h('div', { class: 'row gap-2' });
    valueRow.appendChild(h('div', { class: 'stat-value', text: String(value ?? '—') }));
    if (trend) {
        const up = trend === 'up';
        valueRow.appendChild(h('span', {
            class: `row text-xs font-medium ${up ? 'stat-trend-up' : 'stat-trend-down'}`.trim(),
            title: hint || '',
        }, icon(up ? 'trendingUp' : 'trendingDown', { size: 14 }), txt(trendValue ?? '')));
    }
    el.appendChild(valueRow);

    if (hint && !trend) el.appendChild(h('div', { class: 'stat-hint', text: hint }));
    return el;
}

/* =====================================================================
 * Form controls
 * =================================================================== */

/**
 * A labelled field: label + control + description + error message, wired with
 * `for`/`id` and `aria-describedby`/`aria-invalid` so the association survives
 * being read by a screen reader in any order.
 *
 * @param {{label:string, control:HTMLElement, description?:string, error?:string|null, required?:boolean, id?:string}} opts
 */
export function Field({ label, control, description = null, error = null, required = false, id = null, class: cls = '' }) {
    const fieldId = id || control.id || uid('field');
    control.id = fieldId;

    const descId = `${fieldId}-desc`;
    const errId = `${fieldId}-err`;
    const describedBy = [description ? descId : null, error ? errId : null].filter(Boolean).join(' ');
    if (describedBy) control.setAttribute('aria-describedby', describedBy);
    if (required) control.setAttribute('aria-required', 'true');

    const wrap = h('div', { class: `form-group ${cls}`.trim(), attrs: { 'data-slot': 'form-field' } });
    const labelEl = h('label', {
        class: `label ${required ? 'label-required' : ''}`.trim(),
        attrs: { for: fieldId },
        text: label,
    });
    wrap.appendChild(labelEl);
    wrap.appendChild(control);
    if (description) wrap.appendChild(h('p', { class: 'form-description', id: descId, text: description }));
    if (error) {
        const err = h('p', { class: 'form-message', id: errId, attrs: { role: 'alert' } }, icon('alertCircle', { size: 14 }), txt(error));
        control.setAttribute('aria-invalid', 'true');
        wrap.appendChild(err);
    }
    wrap.__errorId = errId;
    return wrap;
}

export function Input(opts = {}) {
    const { class: cls = '', mono = false, size = null, ...rest } = opts;
    return h('input', {
        class: ['input', mono ? 'input-mono' : '', size ? `input-${size}` : '', cls].filter(Boolean).join(' '),
        attrs: { 'data-slot': 'input', ...rest.attrs },
        ...rest,
    });
}

export function Textarea(opts = {}) {
    const { class: cls = '', mono = false, ...rest } = opts;
    return h('textarea', {
        class: ['textarea', mono ? 'textarea-mono' : '', cls].filter(Boolean).join(' '),
        attrs: { 'data-slot': 'textarea', ...rest.attrs },
        ...rest,
    });
}

/**
 * A native <select>.
 *
 * Native rather than a custom listbox on purpose: a <select> gives correct
 * keyboard handling, form submission, autofill, mobile wheel pickers and
 * screen-reader semantics for free, and re-implementing all five is where
 * custom dropdowns go to fail accessibility audits. The custom `Combobox`
 * below exists for the one case a native select cannot serve — a filterable
 * list of 300 model ids.
 *
 * @param {Array<{value:string,label:string,group?:string,disabled?:boolean}>} options
 */
export function Select(options, opts = {}) {
    const { value = null, class: cls = '', size = null, placeholder = null, ...rest } = opts;
    const el = h('select', {
        class: ['select', size ? `select-${size}` : '', cls].filter(Boolean).join(' '),
        attrs: { 'data-slot': 'select', ...rest.attrs },
        ...rest,
    });
    if (placeholder !== null) {
        el.appendChild(h('option', { value: '', text: placeholder, disabled: true, selected: value === null }));
    }
    let currentGroup = null;
    let optgroup = null;
    for (const option of options) {
        if (option.group && option.group !== currentGroup) {
            currentGroup = option.group;
            optgroup = h('optgroup', { label: option.group });
            el.appendChild(optgroup);
        } else if (!option.group && optgroup) {
            optgroup = null;
            currentGroup = null;
        }
        const node = h('option', { value: option.value, text: option.label, disabled: !!option.disabled });
        if (option.title) node.title = option.title;
        (optgroup || el).appendChild(node);
    }
    if (value !== null) el.value = String(value);
    return el;
}

/**
 * Switch = `<input type="checkbox" role="switch">`.
 *
 * `role="switch"` is what makes a screen reader announce "on/off" instead of
 * "checked/not checked", which is the vocabulary the visual control uses.
 */
export function Switch({ checked = false, label = null, description = null, onChange = null, disabled = false, id = null } = {}) {
    const switchId = id || uid('switch');
    const input = h('input', {
        type: 'checkbox', role: 'switch', class: 'switch', id: switchId, checked, disabled,
        attrs: { 'data-slot': 'switch' },
        on: onChange ? { change: (ev) => onChange(ev.target.checked, ev) } : undefined,
    });
    if (!label) return input;

    const row = h('div', { class: 'switch-row', attrs: { 'data-slot': 'switch-row' } });
    const text = h('div', { class: 'stack-sm min-w-0' });
    text.appendChild(h('label', { class: 'label mb-0 cursor-pointer', attrs: { for: switchId }, text: label }));
    if (description) text.appendChild(h('p', { class: 'form-description mt-0', text: description }));
    row.appendChild(text);
    row.appendChild(input);
    // Clicking the row (not just the 40px switch) toggles it — a 40×22 target
    // is below the 44px touch minimum on its own.
    row.addEventListener('click', (ev) => {
        if (ev.target === input || input.disabled) return;
        input.checked = !input.checked;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    return row;
}

export function Checkbox({ checked = false, indeterminate = false, label = null, onChange = null, disabled = false, id = null } = {}) {
    const boxId = id || uid('cb');
    const input = h('input', {
        type: 'checkbox', class: 'checkbox', id: boxId, checked, disabled,
        attrs: { 'data-slot': 'checkbox' },
        on: onChange ? { change: (ev) => onChange(ev.target.checked, ev) } : undefined,
    });
    input.indeterminate = !!indeterminate;
    if (!label) return input;
    const row = h('label', { class: 'check-row', attrs: { for: boxId } }, input, h('span', { class: 'label mb-0', text: label }));
    return row;
}

/**
 * Filterable combobox for long lists (the model picker, the skill picker).
 *
 * Implements the ARIA 1.2 combobox pattern: the input owns
 * `aria-expanded`/`aria-controls`/`aria-activedescendant`, the listbox is a
 * sibling with `role="listbox"`, options have stable ids, and ArrowUp/Down/
 * Home/End/Enter/Escape/Tab all behave as specified. That is a lot of code for
 * a dropdown, and it is the difference between a control a keyboard user can
 * drive and one they cannot.
 *
 * @param {{options:Array<{value:string,label:string,hint?:string,group?:string,disabled?:boolean}>, value?:string|null, placeholder?:string, emptyText?:string, multi?:boolean, onChange?:Function, filter?:Function}} opts
 */
export function Combobox(opts = {}) {
    const {
        options = [], value = null, placeholder = '', emptyText = 'No results',
        multi = false, onChange = null, filter = null, class: cls = '',
    } = opts;

    const listId = uid('combobox-list');
    const state = {
        open: false,
        activeIndex: -1,
        query: '',
        selected: multi ? new Set(Array.isArray(value) ? value : []) : value,
        filtered: options.slice(),
    };

    const root = h('div', { class: `relative ${cls}`.trim(), attrs: { 'data-slot': 'combobox' } });
    const input = h('input', {
        class: 'input pe-8',
        type: 'text',
        role: 'combobox',
        placeholder,
        attrs: {
            'aria-expanded': 'false', 'aria-controls': listId, 'aria-autocomplete': 'list',
            'data-slot': 'combobox-input',
        },
    });
    const chevron = h('button', {
        type: 'button', tabindex: -1,
        class: 'btn btn-ghost btn-icon btn-sm absolute inset-y-0 end-0 my-auto h-7 w-7 opacity-60',
        attrs: { 'aria-hidden': 'true', tabindex: '-1' },
    }, icon('chevronDown', { size: 14 }));

    const list = h('div', {
        id: listId, role: 'listbox', hidden: true,
        class: 'dropdown absolute z-popover mt-1 w-full max-h-72 overflow-y-auto',
        attrs: { 'aria-multiselectable': multi ? 'true' : 'false', 'data-slot': 'combobox-list' },
    });

    root.append(input, chevron, list);

    const applyFilter = () => {
        const q = state.query.trim().toLowerCase();
        if (!q) { state.filtered = options.slice(); return; }
        state.filtered = filter
            ? options.filter((o) => filter(o, q))
            : options.filter((o) => `${o.label} ${o.value} ${o.hint ?? ''} ${o.group ?? ''}`.toLowerCase().includes(q));
    };

    const render = () => {
        clear(list);
        state.activeIndex = -1;
        if (state.filtered.length === 0) {
            list.appendChild(h('div', { class: 'command-empty', text: emptyText }));
            return;
        }
        let currentGroup = null;
        state.filtered.forEach((option, index) => {
            if (option.group && option.group !== currentGroup) {
                currentGroup = option.group;
                list.appendChild(h('div', { class: 'command-group-label', text: option.group }));
            }
            const id = `${listId}-opt-${index}`;
            const selected = multi ? state.selected.has(option.value) : state.selected === option.value;
            const item = h('div', {
                id, role: 'option', class: 'command-item',
                attrs: { 'aria-selected': selected ? 'true' : 'false', 'data-value': option.value },
            });
            if (multi) {
                const box = h('span', { class: 'checkbox w-4 h-4 shrink-0 pointer-events-none' });
                box.style.display = 'grid';
                if (selected) box.setAttribute('checked', '');
                item.appendChild(box);
            }
            const textCol = h('span', { class: 'min-w-0 flex-1' });
            textCol.appendChild(h('span', { class: 'block truncate', text: option.label }));
            if (option.hint) textCol.appendChild(h('span', { class: 'block truncate xs muted', text: option.hint }));
            item.appendChild(textCol);
            if (!multi && selected) item.appendChild(icon('check', { size: 14, class: 'shrink-0 text-primary' }));
            item.addEventListener('mousedown', (ev) => ev.preventDefault()); // keep focus in the input
            item.addEventListener('click', () => choose(option.value));
            list.appendChild(item);
        });
    };

    const setActive = (index) => {
        const items = Array.from(list.querySelectorAll('[role="option"]'));
        if (items.length === 0) return;
        state.activeIndex = ((index % items.length) + items.length) % items.length;
        items.forEach((el, i) => { el.dataset.selected = i === state.activeIndex ? 'true' : 'false'; });
        input.setAttribute('aria-activedescendant', items[state.activeIndex].id);
        items[state.activeIndex].scrollIntoView({ block: 'nearest' });
    };

    const open = () => {
        if (state.open) return;
        state.open = true;
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        render();
        setActive(0);
    };
    const close = () => {
        if (!state.open) return;
        state.open = false;
        list.hidden = true;
        input.removeAttribute('aria-activedescendant');
        input.setAttribute('aria-expanded', 'false');
    };

    const choose = (val) => {
        if (multi) {
            if (state.selected.has(val)) state.selected.delete(val); else state.selected.add(val);
            onChange?.(Array.from(state.selected));
            render();
        } else {
            state.selected = val;
            const chosen = options.find((o) => o.value === val);
            input.value = chosen?.label ?? val;
            state.query = '';
            close();
            onChange?.(val);
        }
    };

    input.addEventListener('focus', () => { state.query = ''; applyFilter(); open(); });
    input.addEventListener('input', () => { state.query = input.value; applyFilter(); open(); });
    input.addEventListener('blur', () => setTimeout(close, 120));
    input.addEventListener('keydown', (ev) => {
        switch (ev.key) {
            case 'ArrowDown': ev.preventDefault(); if (!state.open) { open(); return; } setActive(state.activeIndex + 1); break;
            case 'ArrowUp': ev.preventDefault(); setActive(state.activeIndex - 1); break;
            case 'Home': if (state.open) { ev.preventDefault(); setActive(0); } break;
            case 'End': if (state.open) { ev.preventDefault(); setActive(state.filtered.length - 1); } break;
            case 'Enter':
                if (state.open && state.activeIndex >= 0) {
                    ev.preventDefault();
                    choose(state.filtered[state.activeIndex].value);
                }
                break;
            case 'Escape': if (state.open) { ev.preventDefault(); ev.stopPropagation(); close(); } break;
            default: break;
        }
    });
    chevron.addEventListener('click', () => { state.open ? close() : (input.focus(), open()); });

    // Seed the visible text for a single-select that starts with a value.
    if (!multi && value !== null) {
        const chosen = options.find((o) => o.value === value);
        input.value = chosen?.label ?? String(value);
    }

    root.__combobox = {
        setOptions(next) { options.length = 0; options.push(...next); applyFilter(); if (state.open) render(); },
        getValue: () => (multi ? Array.from(state.selected) : state.selected),
        setValue(v) { state.selected = multi ? new Set(v ?? []) : v; if (!multi) { const c = options.find((o) => o.value === v); input.value = c?.label ?? ''; } },
        close,
    };
    return root;
}

/* =====================================================================
 * Dialog / Sheet / Confirm
 * =================================================================== */

/**
 * A modal dialog.
 *
 * @param {{title?:string, description?:string, size?:'sm'|'md'|'lg'|'xl', content?:Node[], footer?:Node[], onClose?:Function, dismissible?:boolean}} opts
 * @returns {{el:HTMLElement, close:Function}}
 */
export function Dialog(opts = {}) {
    const {
        title = null, description = null, size = 'md', content = [], footer = null,
        onClose = null, dismissible = true, initialFocus = null,
    } = opts;

    const titleId = uid('dialog-title');
    const descId = uid('dialog-desc');

    const overlay = h('div', { class: 'overlay', attrs: { 'data-state': 'open' } });
    const panel = h('div', {
        class: `dialog ${size !== 'md' ? `dialog-${size}` : ''}`.trim(),
        role: 'dialog',
        tabindex: -1,
        attrs: {
            'aria-modal': 'true', 'data-slot': 'dialog', 'data-state': 'open',
            ...(title ? { 'aria-labelledby': titleId } : {}),
            ...(description ? { 'aria-describedby': descId } : {}),
        },
    });

    if (title) panel.appendChild(h('h2', { class: 'dialog-title', id: titleId, text: title }));
    if (description) panel.appendChild(h('p', { class: 'dialog-description', id: descId, text: description }));

    const body = h('div', { class: 'stack', attrs: { 'data-slot': 'dialog-body' } }, content);
    panel.appendChild(body);

    if (footer) panel.appendChild(h('div', { class: 'dialog-footer', attrs: { 'data-slot': 'dialog-footer' } }, footer));

    let closed = false;
    const close = () => {
        if (closed) return;
        closed = true;
        releaseFocus?.();
        if (prefersReducedMotion()) { popLayer(entry); onClose?.(); return; }
        panel.dataset.state = 'closed';
        overlay.dataset.state = 'closed';
        setTimeout(() => { popLayer(entry); onClose?.(); }, 140);
    };

    if (dismissible) {
        panel.appendChild(Button({
            variant: 'ghost', size: 'icon', iconName: 'x', class: 'dialog-close',
            ariaLabel: 'Close dialog', onClick: close,
        }));
        overlay.addEventListener('click', close);
        // A click that starts inside the panel and ends on the overlay (a drag
        // to select text) must not close the dialog.
        panel.addEventListener('click', (ev) => ev.stopPropagation());
    }

    const entry = pushLayer(overlay, close, { modal: true });
    portal().appendChild(panel);
    // The panel is a sibling of the overlay inside the portal, so the stack
    // entry has to close both.
    const originalClose = entry.close;
    entry.close = () => { panel.remove(); originalClose(); };
    entry.layer = overlay;
    const releaseFocus = trapFocus(panel, { initialFocus });

    return { el: panel, overlay, close, body };
}

/**
 * A confirmation dialog that returns a Promise<boolean>.
 *
 * `danger: true` makes the confirm button destructive and requires typing the
 * confirmation word when `confirmText` is given — the pattern for operations
 * that cannot be undone.
 */
export function Confirm({ title, description, confirmLabel, cancelLabel = null, danger = false, confirmText = null }) {
    return new Promise((resolve) => {
        let settled = false;
        const finish = (value) => { if (!settled) { settled = true; resolve(value); } };

        const content = [];
        if (confirmText) {
            const hint = h('p', { class: 'form-description' });
            append(hint, [txt('Type '), h('code', { class: 'code-inline', text: confirmText }), txt(' to confirm.')]);
            const typed = Input({ class: 'input-mono', autocomplete: 'off', spellcheck: 'false' });
            content.push(hint, typed);
            content.__typed = typed;
        }

        const dlg = Dialog({
            title, description, size: 'sm', content,
            onClose: () => finish(false),
        });

        const confirmBtn = Button({
            variant: danger ? 'destructive' : 'default',
            label: confirmLabel,
            disabled: !!confirmText,
            onClick: () => { finish(true); dlg.close(); },
        });
        const cancelBtn = Button({ variant: 'outline', label: cancelLabel ?? 'Cancel', onClick: () => { finish(false); dlg.close(); } });

        if (confirmText) {
            const typed = content.__typed;
            typed.addEventListener('input', () => { confirmBtn.disabled = typed.value.trim() !== confirmText; });
            typed.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter' && !confirmBtn.disabled) { ev.preventDefault(); confirmBtn.click(); }
            });
            requestAnimationFrame(() => typed.focus());
        }

        dlg.el.appendChild(h('div', { class: 'dialog-footer' }, cancelBtn, confirmBtn));
        if (!confirmText) confirmBtn.focus();
    });
}

/**
 * A sheet: edge-anchored panel. Used for the mobile navigation drawer and the
 * filter panels.
 *
 * On viewports under 768px every side collapses to a bottom sheet, because a
 * 24rem side drawer on a 360px phone leaves 40px of usable width.
 *
 * @param {'right'|'left'|'top'|'bottom'} [side]
 */
export function Sheet({ title = null, description = null, side = 'right', content = [], footer = null, onClose = null, widthClass = null } = {}) {
    const titleId = uid('sheet-title');
    const overlay = h('div', { class: 'overlay', attrs: { 'data-state': 'open' } });
    const panel = h('div', {
        class: `sheet ${widthClass || ''}`.trim(),
        role: 'dialog', tabindex: -1,
        attrs: {
            'aria-modal': 'true', 'data-slot': 'sheet', 'data-side': side, 'data-state': 'open',
            ...(title ? { 'aria-labelledby': titleId } : {}),
        },
    });

    const head = h('div', { class: 'card-header-row' });
    const left = h('div', { class: 'stack-sm' });
    if (title) left.appendChild(h('h2', { class: 'card-title', id: titleId, text: title }));
    if (description) left.appendChild(h('p', { class: 'card-description', text: description }));
    head.appendChild(left);
    head.appendChild(Button({ variant: 'ghost', size: 'icon', iconName: 'x', ariaLabel: 'Close panel', onClick: () => close() }));
    panel.appendChild(head);

    const body = h('div', { class: 'flex-1 min-h-0 overflow-y-auto stack', attrs: { 'data-slot': 'sheet-body' } }, content);
    panel.appendChild(body);
    if (footer) panel.appendChild(h('div', { class: 'row wrap justify-end gap-2 pt-2 border-t border-border' }, footer));

    let closed = false;
    const close = () => {
        if (closed) return;
        closed = true;
        releaseFocus?.();
        const done = () => { popLayer(entry); onClose?.(); };
        if (prefersReducedMotion()) done();
        else { panel.dataset.state = 'closed'; overlay.dataset.state = 'closed'; setTimeout(done, 200); }
    };

    overlay.addEventListener('click', close);
    const entry = pushLayer(overlay, close, { modal: true });
    portal().appendChild(panel);
    const originalClose = entry.close;
    entry.close = () => { panel.remove(); originalClose(); };
    const releaseFocus = trapFocus(panel);

    return { el: panel, overlay, close, body };
}

/* =====================================================================
 * Dropdown menu / Tooltip / Popover
 * =================================================================== */

/**
 * A menu anchored to a trigger.
 *
 * @param {HTMLElement} trigger
 * @param {Array<{label?:string,icon?:string,onClick?:Function,variant?:string,disabled?:boolean,separator?:boolean,shortcut?:string,checked?:boolean}>} items
 * @param {{align?:'start'|'end', side?:'bottom'|'top'}} [opts]
 */
export function DropdownMenu(trigger, items, opts = {}) {
    const { align = 'end', side = 'bottom' } = opts;
    const menuId = uid('menu');
    let open = false;
    let menu = null;
    let entry = null;
    let releaseOutside = null;

    trigger.setAttribute('aria-haspopup', 'menu');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-controls', menuId);

    const place = () => {
        const rect = trigger.getBoundingClientRect();
        menu.style.position = 'fixed';
        menu.style.visibility = 'hidden';
        // Measure before positioning so the flip decision uses real geometry.
        const { width, height } = menu.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        const placeAbove = side === 'top' || (spaceBelow < height + 8 && rect.top > height + 8);
        menu.style.top = placeAbove ? `${Math.max(8, rect.top - height - 6)}px` : `${rect.bottom + 6}px`;
        if (align === 'end') {
            menu.style.left = `${Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8)}px`;
        } else {
            menu.style.left = `${Math.min(Math.max(8, rect.left), window.innerWidth - width - 8)}px`;
        }
        menu.style.visibility = '';
    };

    const closeMenu = () => {
        if (!open) return;
        open = false;
        trigger.setAttribute('aria-expanded', 'false');
        releaseFocus?.();
        releaseOutside?.();
        if (entry) popLayer(entry);
        menu?.remove();
        menu = null;
        entry = null;
    };

    const openMenu = () => {
        if (open) return;
        open = true;
        menu = h('div', {
            id: menuId, class: 'dropdown', role: 'menu', attrs: { 'data-slot': 'dropdown-menu', 'data-state': 'open' },
        });
        for (const item of items) {
            if (item.separator) { menu.appendChild(h('div', { class: 'dropdown-separator', role: 'separator' })); continue; }
            if (item.label && !item.onClick) { menu.appendChild(h('div', { class: 'dropdown-label', role: 'presentation', text: item.label })); continue; }
            const el = h('div', {
                class: 'dropdown-item', role: 'menuitem', tabindex: -1,
                attrs: {
                    'data-slot': 'dropdown-item',
                    ...(item.variant ? { 'data-variant': item.variant } : {}),
                    ...(item.disabled ? { 'aria-disabled': 'true' } : {}),
                },
            });
            if (item.icon) el.appendChild(icon(item.icon, { size: 16 }));
            el.appendChild(h('span', { class: 'flex-1 truncate', text: item.label ?? '' }));
            if (item.checked !== undefined) {
                el.setAttribute('role', 'menuitemcheckbox');
                el.setAttribute('aria-checked', item.checked ? 'true' : 'false');
                el.appendChild(icon(item.checked ? 'check' : 'minus', { size: 14, class: 'opacity-70' }));
            }
            if (item.shortcut) el.appendChild(h('span', { class: 'dropdown-shortcut', text: item.shortcut }));
            if (!item.disabled) {
                el.addEventListener('click', () => { closeMenu(); item.onClick?.(); });
            }
            menu.appendChild(el);
        }
        entry = pushLayer(menu, closeMenu, { modal: false });
        place();
        trigger.setAttribute('aria-expanded', 'true');

        const first = menu.querySelector('[role="menuitem"]:not([aria-disabled="true"])');
        releaseFocus = trapFocus(menu, { initialFocus: first, restoreTo: trigger });

        // Roving tabindex: arrow keys move `data-highlighted`, Enter activates.
        releaseOutside = () => {};
        menu.addEventListener('keydown', (ev) => {
            const enabled = Array.from(menu.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])'));
            const current = enabled.findIndex((el) => el.dataset.highlighted === 'true');
            if (ev.key === 'ArrowDown') { ev.preventDefault(); highlight(enabled, Math.min(enabled.length - 1, current + 1)); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); highlight(enabled, Math.max(0, current - 1)); }
            else if (ev.key === 'Home') { ev.preventDefault(); highlight(enabled, 0); }
            else if (ev.key === 'End') { ev.preventDefault(); highlight(enabled, enabled.length - 1); }
            else if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                const target = enabled[current] ?? enabled[0];
                target?.click();
            } else if (ev.key === 'Tab') { ev.preventDefault(); }
        });
        highlight(Array.from(menu.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])')), 0);
    };

    function highlight(list, index) {
        list.forEach((el, i) => { el.dataset.highlighted = i === index ? 'true' : 'false'; });
        list[index]?.scrollIntoView({ block: 'nearest' });
    }

    trigger.addEventListener('click', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        open ? closeMenu() : openMenu();
    });
    trigger.addEventListener('keydown', (ev) => {
        if (ev.key === 'ArrowDown' || ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openMenu(); }
    });
    // Any click outside closes; `modal:false` means the page stays interactive.
    document.addEventListener('pointerdown', (ev) => {
        if (open && menu && !menu.contains(ev.target) && !trigger.contains(ev.target)) closeMenu();
    }, true);
    window.addEventListener('resize', () => { if (open) place(); }, { passive: true });
    window.addEventListener('scroll', () => { if (open) place(); }, { passive: true, capture: true });

    return { open: openMenu, close: closeMenu, isOpen: () => open, reposition: () => open && place() };
}

/**
 * Tooltip.
 *
 * Native `title` is the baseline for static hints (it works with JS disabled
 * and costs nothing); this exists for the cases where the hint is rich, or
 * where the element is inside an `overflow:hidden` container that clips a
 * native tooltip.
 *
 * Shows on 400ms hover delay (not immediately — a cursor sweeping across a
 * toolbar should not paint six tooltips), hides on leave, and shows on focus
 * with no delay because focus is always deliberate.
 */
export function attachTooltip(el, textOrFn, { side = 'top' } = {}) {
    if (!el) return () => {};
    let tip = null;
    let showTimer = 0;

    const content = () => (typeof textOrFn === 'function' ? textOrFn() : textOrFn);

    const show = () => {
        const text = content();
        if (!text) return;
        hide();
        tip = h('div', { class: 'tooltip', role: 'tooltip', attrs: { 'data-side': side } });
        tip.textContent = String(text);
        portal().appendChild(tip);
        const rect = el.getBoundingClientRect();
        const { width, height } = tip.getBoundingClientRect();
        let top = rect.top - height - 6;
        if (top < 4) top = rect.bottom + 6;
        let left = rect.left + (rect.width - width) / 2;
        // RTL needs no branch: `left` is a physical coordinate and the tip is
        // centred on the trigger, which is symmetric in both directions.
        left = Math.min(Math.max(4, left), window.innerWidth - width - 4);
        tip.style.position = 'fixed';
        tip.style.top = `${top}px`;
        tip.style.left = `${left}px`;
        el.setAttribute('aria-describedby', tip.id || (tip.id = uid('tooltip')));
    };
    const hide = () => {
        clearTimeout(showTimer);
        if (tip) { el.removeAttribute('aria-describedby'); tip.remove(); tip = null; }
    };

    el.addEventListener('pointerenter', () => { showTimer = setTimeout(show, 400); }, { passive: true });
    el.addEventListener('pointerleave', hide, { passive: true });
    el.addEventListener('focus', show);
    el.addEventListener('blur', hide);
    el.addEventListener('click', hide);
    window.addEventListener('scroll', hide, { passive: true, capture: true });
    return hide;
}

/* =====================================================================
 * Toast
 * =================================================================== */

let toastViewport = null;

function toastRoot() {
    if (toastViewport && toastViewport.isConnected) return toastViewport;
    toastViewport = h('div', {
        class: 'toast-viewport', role: 'region',
        attrs: { 'aria-label': 'Notifications', 'data-slot': 'toast-viewport', 'data-print-hide': '' },
    });
    document.body.appendChild(toastViewport);
    return toastViewport;
}

/**
 * Show a toast.
 *
 * The viewport is `role="region"` rather than `aria-live="polite"` on purpose.
 * An aria-live region announces every mutation, and a console that streams
 * pipeline events would produce a continuous spoken commentary. A labelled
 * region is reachable on demand and silent otherwise; errors additionally get
 * `role="alert"` on the toast itself, which does interrupt.
 *
 * @param {{title:string, description?:string, tone?:'default'|'success'|'warning'|'destructive'|'info', duration?:number, action?:{label:string,onClick:Function}}} opts
 */
export function toast(opts) {
    const { title, description = null, tone = 'default', duration = 5000, action = null } = typeof opts === 'string' ? { title: opts } : opts;
    const root = toastRoot();

    const el = h('div', {
        class: `toast ${tone !== 'default' ? `toast-${tone}` : ''}`.trim(),
        attrs: { 'data-slot': 'toast', 'data-state': 'open', role: tone === 'destructive' || tone === 'warning' ? 'alert' : 'status' },
    });

    const body = h('div', { class: 'min-w-0 flex-1' });
    body.appendChild(h('div', { class: 'toast-title', text: title }));
    if (description) body.appendChild(h('div', { class: 'toast-description', text: description }));
    if (action) {
        body.appendChild(h('div', { class: 'mt-1.5' }, Button({ size: 'sm', variant: 'outline', label: action.label, onClick: () => { dismiss(); action.onClick?.(); } })));
    }
    el.appendChild(body);
    el.appendChild(Button({ variant: 'ghost', size: 'icon', iconName: 'x', class: 'toast-close', ariaLabel: 'Dismiss notification', onClick: dismiss }));

    const ms = Math.max(0, Number(duration) || 0);
    if (ms > 0 && !prefersReducedMotion()) {
        const bar = h('div', { class: 'toast-timer' });
        el.appendChild(bar);
        bar.animate([{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }], { duration: ms, easing: 'linear', fill: 'forwards' });
    }

    let dismissed = false;
    let timer = 0;
    const dismiss = () => {
        if (dismissed) return;
        dismissed = true;
        clearTimeout(timer);
        el.dataset.state = 'closed';
        const remove = () => el.remove();
        if (prefersReducedMotion()) remove();
        else setTimeout(remove, 140);
    };

    // Hovering or focusing a toast pauses its timer: a user who is reading it
    // must not have it disappear mid-sentence.
    el.addEventListener('pointerenter', () => clearTimeout(timer));
    el.addEventListener('pointerleave', () => { if (ms > 0) timer = setTimeout(dismiss, ms); });
    el.addEventListener('focusin', () => clearTimeout(timer));
    el.addEventListener('focusout', () => { if (ms > 0) timer = setTimeout(dismiss, ms); });

    root.appendChild(el);
    // Cap the stack: 20 toasts on a phone cover the entire screen and the
    // oldest one is the least relevant.
    while (root.children.length > 4) root.firstElementChild?.remove();
    if (ms > 0) timer = setTimeout(dismiss, ms);

    return dismiss;
}

export const Toast = {
    success: (title, description, opts = {}) => toast({ title, description, tone: 'success', ...opts }),
    error: (title, description, opts = {}) => toast({ title, description, tone: 'destructive', duration: 8000, ...opts }),
    warning: (title, description, opts = {}) => toast({ title, description, tone: 'warning', duration: 7000, ...opts }),
    info: (title, description, opts = {}) => toast({ title, description, tone: 'info', ...opts }),
    plain: toast,
};

/* =====================================================================
 * Tabs / Accordion / Progress / Skeleton / Separator / EmptyState
 * =================================================================== */

/**
 * Tabs following the ARIA authoring-practice tab pattern.
 *
 * Arrow keys move between tabs AND activate them (automatic activation), which
 * is the recommended behaviour when switching is instant and cheap. Home/End
 * jump to the ends. The tablist is roving-tabindex: exactly one trigger is in
 * the tab order at a time.
 *
 * @param {Array<{id:string,label:string,icon?:string,panel:Node|Function,badge?:string}>} tabs
 */
export function Tabs(tabs, { initial = null, onChange = null, class: cls = '' } = {}) {
    const root = h('div', { class: `stack ${cls}`.trim(), attrs: { 'data-slot': 'tabs' } });
    const list = h('div', { class: 'tabs-list no-scrollbar', role: 'tablist', attrs: { 'data-slot': 'tabs-list' } });
    const panels = new Map();
    let activeId = initial || tabs[0]?.id || null;

    const select = (id, { focus = false } = {}) => {
        activeId = id;
        for (const [tabId, nodes] of panels) {
            const on = tabId === id;
            nodes.trigger.setAttribute('aria-selected', on ? 'true' : 'false');
            nodes.trigger.dataset.state = on ? 'active' : 'inactive';
            nodes.trigger.tabIndex = on ? 0 : -1;
            nodes.panel.hidden = !on;
        }
        if (focus) panels.get(id)?.trigger.focus();
        onChange?.(id);
    };

    for (const tab of tabs) {
        const triggerId = `${tab.id}-trigger`;
        const panelId = `${tab.id}-panel`;
        const trigger = h('button', {
            type: 'button', class: 'tabs-trigger', id: triggerId, role: 'tab',
            attrs: { 'aria-controls': panelId, 'aria-selected': 'false', 'data-state': 'inactive', tabindex: '-1', 'data-slot': 'tabs-trigger', ...(tab.badge ? { 'data-badge': tab.badge } : {}) },
        });
        if (tab.icon) trigger.appendChild(icon(tab.icon, { size: 15 }));
        trigger.appendChild(h('span', { text: tab.label }));
        if (tab.badge) trigger.appendChild(Badge(tab.badge, { tone: 'muted' }));

        const panel = h('div', {
            class: 'tabs-content', id: panelId, role: 'tabpanel', tabindex: 0, hidden: true,
            attrs: { 'aria-labelledby': triggerId, 'data-slot': 'tabs-content' },
        });
        panels.set(tab.id, { trigger, panel, tab });

        // The click binding happens once, after `selectLazy` exists: binding
        // here would fire the eager `select` and render nothing into the panel.
        list.appendChild(trigger);
        root.appendChild(panel);
    }

    list.addEventListener('keydown', (ev) => {
        const ids = tabs.map((t) => t.id);
        const index = ids.indexOf(activeId);
        let next = null;
        // Logical, not physical, direction: in RTL the Right arrow must move
        // to the PREVIOUS tab, because the tabs are laid out right-to-left.
        const rtl = document.documentElement.dir === 'rtl';
        const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
        const backward = rtl ? 'ArrowRight' : 'ArrowLeft';
        if (ev.key === forward) next = ids[(index + 1) % ids.length];
        else if (ev.key === backward) next = ids[(index - 1 + ids.length) % ids.length];
        else if (ev.key === 'Home') next = ids[0];
        else if (ev.key === 'End') next = ids[ids.length - 1];
        if (next) { ev.preventDefault(); selectRef(next, { focus: true }); }
    });

    root.prepend(list);
    // The keydown handler above closes over a mutable reference so it can call
    // the lazy selector, which is only defined after the panels exist.
    let selectRef = (...a) => select(...a);
    // Panels are rendered lazily: an admin tab that fetches on open should not
    // fire its request before the user asks for it.
    const originalSelect = select;
    const rendered = new Set();
    const selectLazy = (id, opts = {}) => {
        const entry = panels.get(id);
        if (entry && !rendered.has(id)) {
            rendered.add(id);
            const content = typeof entry.tab.panel === 'function' ? entry.tab.panel() : entry.tab.panel;
            append(entry.panel, Array.isArray(content) ? content : [content]);
        }
        originalSelect(id, opts);
    };
    for (const [id, nodes] of panels) {
        nodes.trigger.addEventListener('click', () => selectLazy(id));
    }
    selectRef = selectLazy;
    if (activeId) selectLazy(activeId);
    return { el: root, select: selectLazy, get active() { return activeId; } };
}

/**
 * Accordion. `multiple: false` closes the open item when another opens.
 *
 * @param {Array<{id:string,title:string|Node,content:Node|Function,icon?:string}>} items
 */
export function Accordion(items, { multiple = false, initialOpen = null, class: cls = '' } = {}) {
    const root = h('div', { class: `accordion ${cls}`.trim(), attrs: { 'data-slot': 'accordion' } });
    const open = new Set(initialOpen ? [].concat(initialOpen) : []);
    const rows = new Map();

    const render = () => {
        for (const item of items) {
            const itemId = item.id || uid('acc');
            const triggerId = `${itemId}-trigger`;
            const regionId = `${itemId}-content`;
            const isOpen = open.has(itemId);

            const trigger = h('button', {
                type: 'button', class: 'accordion-trigger', id: triggerId,
                attrs: { 'aria-expanded': isOpen ? 'true' : 'false', 'aria-controls': regionId, 'data-slot': 'accordion-trigger' },
            });
            if (item.icon) trigger.appendChild(icon(item.icon, { size: 15, class: 'me-1' }));
            trigger.appendChild(h('span', { class: 'flex-1 text-start' }, typeof item.title === 'string' ? [txt(item.title)] : [item.title]));
            trigger.appendChild(icon('chevronDown', { size: 16 }));

            const content = h('div', {
                class: 'accordion-content', id: regionId, role: 'region',
                attrs: { 'aria-labelledby': triggerId, 'data-state': isOpen ? 'open' : 'closed', 'data-slot': 'accordion-content' },
                hidden: !isOpen,
            });
            if (isOpen) {
                const body = typeof item.content === 'function' ? item.content() : item.content;
                append(content, [h('div', { class: 'pb-3 pt-1' }, Array.isArray(body) ? body : [body])]);
            }

            const row = h('div', { class: 'accordion-item', attrs: { 'data-slot': 'accordion-item' } }, trigger, content);
            trigger.addEventListener('click', () => {
                if (open.has(itemId)) open.delete(itemId);
                else { if (!multiple) open.clear(); open.add(itemId); }
                rebuild();
            });
            rows.set(itemId, row);
            root.appendChild(row);
        }
    };

    const rebuild = () => {
        clear(root);
        rows.clear();
        render();
    };

    render();
    return {
        el: root,
        open(id) { if (!open.has(id)) { if (!multiple) open.clear(); open.add(id); rebuild(); } },
        close(id) { if (open.delete(id)) rebuild(); },
        toggle(id) { open.has(id) ? this.close(id) : this.open(id); },
    };
}

/** @param {number} value 0..100, or null for indeterminate. */
export function Progress(value, { tone = 'default', label = null, class: cls = '' } = {}) {
    const indeterminate = value === null || value === undefined || Number.isNaN(Number(value));
    const pct = indeterminate ? 0 : Math.max(0, Math.min(100, Number(value)));
    const el = h('div', {
        class: `progress ${tone !== 'default' ? `progress-${tone}` : ''} ${indeterminate ? 'progress-indeterminate' : ''} ${cls}`.trim(),
        role: 'progressbar',
        attrs: {
            'data-slot': 'progress',
            ...(indeterminate ? { 'aria-valuemin': '0', 'aria-valuemax': '100' } : { 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(pct)) }),
            ...(label ? { 'aria-label': label } : {}),
        },
    });
    const indicator = h('div', { class: 'progress-indicator', attrs: { 'data-slot': 'progress-indicator' } });
    if (!indeterminate) indicator.style.transform = `scaleX(${pct / 100})`;
    el.appendChild(indicator);
    el.__set = (next) => {
        const p = Math.max(0, Math.min(100, Number(next) || 0));
        indicator.style.transform = `scaleX(${p / 100})`;
        el.setAttribute('aria-valuenow', String(Math.round(p)));
    };
    return el;
}

/** @param {string} [class] extra sizing classes, e.g. 'h-4 w-40' */
export function Skeleton(cls = 'h-4 w-full') {
    return h('div', { class: `skeleton ${cls}`.trim(), attrs: { 'data-slot': 'skeleton', 'aria-hidden': 'true' } });
}

/** A placeholder block shown while a panel loads. */
export function SkeletonBlock({ rows = 4, title = true } = {}) {
    const el = h('div', { class: 'stack-sm', attrs: { role: 'status' } });
    if (title) el.appendChild(Skeleton('h-5 w-1/3'));
    // Widths are inline, not Tailwind classes: `w-${n}` never appears
    // literally in the source, so the build could not generate it.
    for (let i = 0; i < rows; i += 1) {
        const bar = Skeleton('h-4');
        bar.style.width = i % 3 === 0 ? '100%' : '83%';
        el.appendChild(bar);
    }
    el.appendChild(h('span', { class: 'sr-only', text: 'Loading' }));
    return el;
}

export function Separator({ orientation = 'horizontal', class: cls = '' } = {}) {
    return h('div', {
        class: `separator ${orientation === 'vertical' ? 'separator-v' : 'separator-h'} ${cls}`.trim(),
        role: 'separator',
        attrs: { 'data-slot': 'separator', 'aria-orientation': orientation },
    });
}

/**
 * The empty state. Shown instead of a blank panel, because "nothing here"
 * and "it failed to load" must be distinguishable.
 */
export function EmptyState({ iconName = 'folderOpen', title, description = null, action = null, compact = false } = {}) {
    const el = h('div', {
        class: `flex flex-col items-center justify-center text-center ${compact ? 'py-6 gap-2' : 'py-14 gap-3'}`.trim(),
        attrs: { 'data-slot': 'empty-state' },
    });
    el.appendChild(h('div', { class: `${compact ? 'h-8 w-8' : 'h-11 w-11'} rounded-full bg-muted grid place-items-center text-muted-foreground` },
        icon(iconName, { size: compact ? 16 : 22 })));
    el.appendChild(h('div', { class: `${compact ? 'text-sm' : 'text-base'} font-medium`, text: title }));
    if (description) el.appendChild(h('p', { class: 'text-sm muted max-w-md', text: description }));
    if (action) el.appendChild(h('div', { class: 'mt-1' }, action));
    return el;
}

/** A keyboard hint rendered as <kbd>. */
export function Kbd(children) {
    return h('kbd', { class: 'kbd' }, Array.isArray(children) ? children : [txt(children)]);
}

export default {
    portal, closeTopLayer, closeAllLayers,
    Button, setButtonLoading, Card, Badge, Alert, Stat,
    Field, Input, Textarea, Select, Switch, Checkbox, Combobox,
    Dialog, Confirm, Sheet, DropdownMenu, attachTooltip, toast, Toast,
    Tabs, Accordion, Progress, Skeleton, SkeletonBlock, Separator, EmptyState, Kbd,
};
