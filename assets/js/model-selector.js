// model-selector.js — UI for choosing the L1 consensus model pool.
// Persists selection in localStorage, validates against the catalog
// returned by /api/connectors, and emits a `change` event whenever
// the user adds, removes or swaps a model.
//
// Display rules (1.3.0, reworked in 1.8.52):
//  - A closed row stays constrained by the sidebar column width — it never
//    overflows horizontally and shows the truncated *model* part of the
//    identifier, not the long `openrouter/bothub/…` provider prefix.
//  - The provider is rendered as a small muted badge.
//  - Choosing happens in an in-row popup built from plain DOM (see
//    {@see ModelCombobox}), not in a native `<select>`: the popup carries
//    its own search field, so the operator can type *inside* the list they
//    are narrowing.
//
// Why the native `<select>` went away: its popup is drawn by the OS, so the
// sidebar's filter box could only live *above* the rows. Clicking into that
// box blurred the select (the popup closed under the cursor) and typing
// while it was closed filtered a list nobody could see — "the search in the
// dropdown does not work", reported twice, with every kind of click.

const STORAGE_KEY = 'oc.selected_models.v1';

/**
 * Debounce window for the model-list filter box. Mirrors the History tab's
 * search debounce: long enough that a fast typist does not rebuild the
 * option lists on every character, short enough to feel immediate.
 */
const FILTER_DEBOUNCE_MS = 200;

/**
 * Hard cap on how many rows the "Search all models" mode may list. The whole
 * point of that mode is searching a catalogue far larger than the pool; an
 * uncapped list rebuilt the entire catalogue inside every combobox and is
 * what made ticking the box freeze the sidebar on a real install.
 */
const SEARCH_LIMIT = 10;

function loadStoredSelection() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return null;
        return parsed.filter((row) => row && typeof row === 'object'
            && typeof row.provider === 'string' && typeof row.model === 'string');
    } catch {
        return null;
    }
}

function saveSelection(rows) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(rows));
    } catch {
        // ignore quota/serialization errors — selection still works in-memory
    }
}

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (k === 'class') node.className = v;
        else if (k === 'dataset') Object.assign(node.dataset, v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
        else if (v !== false && v !== null && v !== undefined) node.setAttribute(k, v);
    }
    for (const c of children) {
        if (c == null || c === false) continue;
        node.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return node;
}

function modelKey(row) { return row.provider + '|' + row.model; }

// The page owns exactly one selector (app.js builds it in the Chat
// sidebar), but other tabs need to push a selection into it — the Models
// tab applies a consensus preset from the admin panel and the pool has to
// update without a reload. A module-level handle is enough: there is a
// single `#model-selector` root, and registration is idempotent.
let activeSelector = null;

/** Register the page's selector so other modules can reach it. */
export function setActiveModelSelector(selector) { activeSelector = selector; }

/**
 * Apply a consensus preset to the page's selector, if one is mounted.
 * Returns null when the Chat sidebar never initialised (model-selector
 * root absent), so callers can say so instead of claiming success.
 *
 * @param {Array<{provider:string,model:string,active?:boolean,selected?:boolean}>} rows
 */
export function applyPresetToSelection(rows) {
    return activeSelector ? activeSelector.applyPreset(rows) : null;
}

// Display label: emphasize the model id (the part the user actually picks),
// keep the provider as a short prefix tag. The full `provider / model`
// string remains in `title` and `aria-label` for accessibility.
function modelDisplay(row) {
    return row.model;
}
function providerBadge(row) {
    return row.provider;
}

// ===== Batch-only identifiers ==========================================
//
// Port of App\Models\ModelId (PHP), kept in step deliberately: the same ids
// reach both sides, and "is this a batch id?" has to mean one thing. Some
// providers serve a second, asynchronous surface for the same model and
// spell it as a suffix on the id — `gpt-4o:batch` (upstream Batch API:
// upload JSONL → create → poll → download). The suffix says HOW the id is
// served, not WHAT the model is, so it must never reach the version parser
// or the family grouping.
//
// What is deliberately NOT here: the asynchronous flow itself. Only the
// classification, which is correct under any reading of the feature.

/** Is this identifier served by the asynchronous Batch API only? */
export function isBatchId(model) {
    return batchSplit(model) !== null;
}

/** The identifier without its mode suffix (idempotent, trim-only otherwise). */
export function baseModelId(model) {
    const parts = batchSplit(model);
    return parts === null ? String(model == null ? '' : model).trim() : parts.base;
}

/** `'batch'` or `'sync'`. */
export function modelMode(model) {
    return isBatchId(model) ? 'batch' : 'sync';
}

/** Why this id is not eligible for the synchronous pool, or '' when it is. */
export function batchPoolRejection(model) {
    if (!isBatchId(model)) { return ''; }
    return `${String(model).trim()} (batch-only identifier: it is served through the `
        + 'asynchronous Batch API, so it cannot take part in the synchronous consensus pool.)';
}

/**
 * The same id, described for a run that IS a batch run.
 *
 * The two notices differ because the same model is a mistake in one mode and
 * the normal case in the other: in the synchronous pool a `:batch` id is an
 * id L1 can never call, while in batch mode it is exactly what the run is for.
 * Reusing the refusal text there would tell an operator that the model they
 * deliberately selected is unusable.
 */
export function batchModeNotice(model) {
    if (!isBatchId(model)) { return ''; }
    return `${String(model).trim()} (batch-only identifier: it runs through the provider `
        + 'Batch API — results arrive asynchronously, usually in minutes and up to 24 hours.)';
}

/** The recognised suffix, and what is left of the id without it. */
function batchSplit(model) {
    const id = String(model == null ? '' : model).trim();
    const suffix = ':batch';
    if (id.length <= suffix.length) { return null; }
    if (id.toLowerCase().slice(-suffix.length) !== suffix) { return null; }
    // Separators before the colon belong to the spelling, not the name.
    const base = id.slice(0, -suffix.length).replace(/\s+$/, '');
    return base === '' ? null : { base };
}

/**
 * The visible "batch" marker for one id, or null when the id is
 * synchronous. One definition for every list that can show a model id —
 * the pool rows, the row popups and the Models tab table all import it —
 * so the badge, its wording and its tooltip cannot drift apart.
 */
/**
 * Batch-capable ids the PROVIDER listed (`GET /api/council/batch/models`),
 * or null while the answer is unknown.
 *
 * This is where the tri-state lives on the client: `null` means nobody has
 * been able to ask, so a badge may never read as "this model cannot be
 * batched". A model that is simply absent from a successful list is not
 * marked either — the provider's list is a catalogue of what works, not a
 * certificate of what does not.
 */
let batchCapabilityIds = null;

/**
 * Adopt the provider's batch-capable list. Pass null when the call failed or
 * has not been made: the badge then says batch support is unknown instead of
 * implying a verdict.
 *
 * @param {string[]|null} ids
 */
export function setBatchCapabilityIds(ids) {
    if (!Array.isArray(ids)) { batchCapabilityIds = null; return; }
    batchCapabilityIds = new Set(ids.map((id) => String(id).toLowerCase().trim()).filter((id) => id !== ''));
}

/** Has the provider's batch-capable list been read in this page? */
export function batchCapabilityResolved() {
    return batchCapabilityIds !== null;
}

/** What the provider says about one id, as a sentence. */
export function batchCapabilityNote(model) {
    if (batchCapabilityIds === null) {
        return ' The provider list of batch-capable ids could not be read, so batch support is unknown for this id '
            + '— that is not a statement that the model lacks it.';
    }
    return batchCapabilityIds.has(String(model).toLowerCase().trim())
        ? ' The provider lists this id as supporting background processing.'
        : ' The provider list of batch-capable ids does not include this id, which does not by itself rule it out.';
}

export function batchBadgeNode(model) {
    if (!isBatchId(model)) { return null; }
    return el('span', {
        class: 'oc-model-batch-badge',
        title: batchPoolRejection(model) + batchCapabilityNote(model),
        'aria-label': 'Batch-only model, not usable in the synchronous consensus pool',
    }, ['batch']);
}

// Tokenise a model id for diversity comparison: lowercase, strip a leading
// "~" alias marker, keep only the segment after the last "/", then split
// into [a-z0-9]+ runs. Empty results fall back to ["_"] so every row still
// has a comparable token set.
function diversityTokens(model) {
    const normalized = String(model).toLowerCase().replace(/^~/, '').split('/').pop();
    const tokens = (normalized.match(/[a-z0-9]+/g) || []).filter((t) => t !== '');
    return tokens.length > 0 ? tokens : ['_'];
}

// ===== Release versions ================================================
//
// Port of App\Models\ModelVersion (PHP), kept in step deliberately: the
// "only newer releases" filter in the Models tab has to agree with what the
// actualization run considers a newer release of the same line, or the tab
// would offer to keep a model the worker is about to replace. The line is
// everything that is not a number, the version is the sequence of numeric
// tokens, and two ids are comparable only when their lines match:
//
//   anthropic/claude-sonnet-4.6 → line "claudesonnet", version [4,6]
//   gpt-5.5-pro                 → line "gptpro",        version [5,5]
//   deepseek-r1                 → line "deepseekr1",    version []   (never comparable)
//
// tests/harness/models-tab.mjs pins both implementations against one
// fixture table so a change on either side is caught.

/**
 * Strip the vendor prefix, the `~` alias marker and the mode suffix, as
 * ProviderFamily::slug() does. `:batch` goes with it: the suffix says how
 * the id is served, so leaving it in would tokenise `gpt-4o:batch` to the
 * line `gptobatch` — a different "model" from `gpt-4o`, and therefore
 * incomparable with it.
 */
function versionSlug(model) {
    const id = String(model == null ? '' : model).toLowerCase().trim();
    const slash = id.lastIndexOf('/');
    return baseModelId((slash >= 0 ? id.slice(slash + 1) : id).replace(/^~+/, ''));
}

/** `{line, version}` for a model id. */
export function modelVersionParse(model) {
    const slug = versionSlug(model);
    const tokens = slug.split(/[^a-z0-9]+/).filter((t) => t !== '');
    let line = '';
    const version = [];
    for (const token of tokens) {
        if (/^\d+$/.test(token)) { version.push(Number(token)); continue; }
        line += token;
    }
    return { line: line === '' ? slug : line, version };
}

/** The release line of a model id (`gpt-5.5-pro` → `gptpro`). */
export function modelVersionLine(model) {
    return modelVersionParse(model).line;
}

/** @returns {number[]} the numeric tuple of a model id. */
export function modelVersionTuple(model) {
    return modelVersionParse(model).version;
}

/**
 * Compare two identifiers on the same line. A missing component counts as
 * older than any number, so `[4,6]` beats `[4]`.
 *
 * @returns {number} -1 when a is older, 0 on a tie, 1 when a is newer
 */
export function modelVersionCompare(a, b) {
    const va = modelVersionTuple(a);
    const vb = modelVersionTuple(b);
    const len = Math.max(va.length, vb.length);
    for (let i = 0; i < len; i++) {
        const x = i < va.length ? va[i] : -1;
        const y = i < vb.length ? vb[i] : -1;
        if (x !== y) { return x < y ? -1 : 1; }
    }
    return 0;
}

/**
 * Is there a newer release of `model`'s line among `candidates`?
 *
 * @param {string} model
 * @param {string[]} candidates other model ids (any order, may include model)
 * @returns {string|null} the newest newer id, or null
 */
export function newerVersionOf(model, candidates) {
    const line = modelVersionLine(model);
    let best = null;
    for (const candidate of Array.isArray(candidates) ? candidates : []) {
        if (typeof candidate !== 'string' || candidate === '') { continue; }
        if (modelVersionLine(candidate) !== line) { continue; }
        if (best === null || modelVersionCompare(candidate, best) > 0) { best = candidate; }
    }
    if (best === null || modelVersionCompare(best, model) <= 0) { return null; }
    return best;
}

// ===== Filtering =======================================================
//
// Shared by both surfaces that list models — the Chat sidebar pool and the
// Models tab table — so "matches the filter" means the same thing in both
// places: a case-insensitive substring of `provider / model` or of the row's
// label.

/** Normalise a filter string for comparison. */
export function normaliseModelFilter(text) {
    return String(text == null ? '' : text).toLowerCase().trim();
}

/** Does a catalogue row match a normalised filter string? */
export function modelMatchesFilter(row, filter) {
    if (!filter) { return true; }
    if (!row || typeof row !== 'object') { return false; }
    const haystack = `${row.provider == null ? '' : row.provider} / ${row.model == null ? '' : row.model} ${
        row.label == null ? '' : row.label}`.toLowerCase();
    return haystack.includes(filter);
}

// Farthest-first diversity picker over model ids. Returns `count` rows whose
// models are maximally different from each other (Jaccard distance on the
// tokens above), so a fallback pool is never filled with near-identical
// variants of one model family. Returns picks in selection order; ties
// resolve to the earliest original index. `count >= rows.length` returns
// every row in original order.
export function pickDiverseModels(rows, count) {
    if (!Array.isArray(rows) || rows.length === 0 || !(count > 0)) return [];
    const n = Math.min(Math.floor(count), rows.length);
    if (n >= rows.length) return rows.slice();
    const tokens = rows.map((row) => diversityTokens(row && row.model));
    const distance = (i, j) => {
        const union = new Set(tokens[i]);
        for (const t of tokens[j]) union.add(t);
        if (union.size === 0) return 0;
        const bSet = new Set(tokens[j]);
        let inter = 0;
        for (const t of tokens[i]) if (bSet.has(t)) inter++;
        return 1 - inter / union.size;
    };
    // Seed: the row with the maximum TOTAL distance to every other row
    // (ties -> earliest original index).
    let seed = 0;
    let bestTotal = -1;
    for (let i = 0; i < rows.length; i++) {
        let total = 0;
        for (let j = 0; j < rows.length; j++) {
            if (i === j) continue;
            total += distance(i, j);
        }
        if (total > bestTotal) { bestTotal = total; seed = i; }
    }
    // Greedy: keep adding the row that maximises the MIN distance to the
    // already-selected rows (ties -> earliest original index).
    const selected = [seed];
    const chosen = new Set(selected);
    while (selected.length < n) {
        let pick = -1;
        let bestMin = -1;
        for (let i = 0; i < rows.length; i++) {
            if (chosen.has(i)) continue;
            let min = Infinity;
            for (const s of selected) {
                const d = distance(i, s);
                if (d < min) min = d;
            }
            if (min > bestMin) { bestMin = min; pick = i; }
        }
        if (pick < 0) break;
        selected.push(pick);
        chosen.add(pick);
    }
    return selected.map((i) => rows[i]);
}

// ===== The row combobox ===============================================
//
// One row = one trigger button + one popup panel, both ordinary DOM inside
// that row. The panel holds, top to bottom, a search field and a scrolling
// list of the models that currently match; an empty list says so in words
// and explains how to get the list back.
//
// What closes the panel, and what deliberately does not:
//
//   closes:   a click outside the widget root, Escape, picking a model,
//             focus leaving the row subtree, another row's panel opening.
//   keeps it open: any click inside the panel — the search field, the
//             list, its scrollbar — the debounced list rebuild while
//             typing, and the global pool filter changing underneath.
//
// Typing never rebuilds the search input itself (only the `<li>` list and
// the empty-state text), so focus and the caret position survive every
// keystroke; the rebuild is debounced with the same window the sidebar's
// own filter uses.

/** Monotonic id source: one listbox id per combobox, unique per document. */
let comboboxSeq = 0;

class ModelCombobox {
    /**
     * @param {ModelSelector} owner the selector this row belongs to
     * @param {object} opts
     * @param {string} opts.currentKey `provider|model` in effect, '' for the add row
     * @param {string} [opts.model] the row's own model id (drives the batch badge)
     * @param {string} opts.labelText visible (truncated) trigger text
     * @param {string} opts.title trigger tooltip: the full `provider / model`
     * @param {string} opts.ariaLabel trigger accessible name
     * @param {string} opts.searchLabel accessible name of the search field
     * @param {boolean} [opts.addRow] true for the "+ add model" row
     * @param {(row:object)=>void} opts.onPick called with the chosen catalogue row
     */
    constructor(owner, opts) {
        this.owner = owner;
        this.currentKey = typeof opts.currentKey === 'string' ? opts.currentKey : '';
        this.isAddRow = opts.addRow === true;
        this.onPick = typeof opts.onPick === 'function' ? opts.onPick : () => {};
        this.query = '';
        this.pendingQuery = '';
        this.timer = null;
        this.options = [];
        this.optionNodes = [];
        this.highlight = -1;
        this.isOpen = false;
        /** The row element, so open/close can carry the layout class. */
        this.rowNode = null;

        this.listId = `oc-model-options-${++comboboxSeq}`;
        /**
         * The search field needs an id of its own: a page with one combobox
         * per pool row produced one browser warning per row ("a form field
         * element should have an id or name attribute"), 20 of them on a live
         * install. Same counter as the listbox, so it cannot collide.
         */
        this.searchId = `oc-model-search-${comboboxSeq}`;
        /** True when the row's own value is a batch-only identifier. */
        this.isBatchCurrent = isBatchId(opts.model);
        /**
         * Replaces the "nothing left to choose" wording for a combobox that
         * is not a pool row (see {@see createModelPicker}). The "no match
         * for your search" message stays the combobox's own, because that
         * one is about the search field the operator is typing in.
         */
        this.emptyTextOverride = typeof opts.emptyText === 'string' ? opts.emptyText : '';

        this.label = el('span', { class: 'oc-model-label' }, [opts.labelText]);
        const triggerChildren = [this.label];
        if (this.isBatchCurrent) {
            // A pool row that is already batch-only (carried over from a
            // stored selection or an older catalogue): the badge has to be
            // on the closed row too, not only inside the popup.
            triggerChildren.push(batchBadgeNode(opts.model));
        }
        this.trigger = el('button', {
            type: 'button',
            class: 'oc-model-trigger' + (this.isAddRow ? ' oc-model-add' : ''),
            role: 'combobox',
            'aria-haspopup': 'listbox',
            'aria-expanded': 'false',
            'aria-controls': this.listId,
            title: this.isBatchCurrent
                ? (this.owner.batchModeEnabled()
                    ? batchModeNotice(opts.model)
                    : `${batchPoolRejection(opts.model)} Swap it for a synchronous model to run it in L1.`)
                : opts.title,
            'aria-label': opts.ariaLabel,
            onclick: () => this.toggle(),
            onkeydown: (event) => this.onTriggerKeydown(event),
        }, triggerChildren);

        this.search = el('input', {
            type: 'search',
            id: this.searchId,
            class: 'oc-model-search',
            placeholder: 'Search models…',
            'aria-label': opts.searchLabel,
            'aria-controls': this.listId,
            'aria-autocomplete': 'list',
            autocomplete: 'off',
            oninput: (event) => this.queueQuery(event.target.value),
            onkeydown: (event) => this.onSearchKeydown(event),
        });

        this.list = el('ul', {
            class: 'oc-model-options',
            id: this.listId,
            role: 'listbox',
            'aria-label': this.isAddRow ? 'Models to add' : 'Models to swap in',
        });

        this.note = el('p', { class: 'oc-model-panel-note oc-muted oc-small' });
        this.panel = el('div', { class: 'oc-model-panel', hidden: true },
            [this.search, this.list, this.note]);

        this.wrap = el('div', { class: 'oc-model-select-wrap' }, [this.trigger, this.panel]);

        // A click on the panel (an option, the list, its scrollbar) must not
        // move the focus out of the search field. The browser's default
        // mousedown action WOULD blur it, and the focusout handler below reads
        // that as "the row was left" — a click on a non-focusable option
        // reports relatedTarget === null — so the panel closed before the click
        // on the option was delivered. That is exactly why picking a model in
        // the "+ add model" window closed it and added nothing. Cancelling the
        // default keeps the field focused; the click itself is untouched, and a
        // click on the search field is left alone so it can still take the caret.
        this.panel.addEventListener('mousedown', (event) => {
            const target = event ? event.target : null;
            if (target === this.search) { return; }
            if (event && typeof event.preventDefault === 'function') { event.preventDefault(); }
        });

        // Focus leaving the row subtree closes the panel. relatedTarget is
        // the whole test: a click on the trigger (or anything else inside
        // the row) is not "leaving", so the toggle below still works.
        this.wrap.addEventListener('focusout', (event) => {
            const next = event ? event.relatedTarget : null;
            if (next && this.wrap.contains(next)) { return; }
            this.close();
        });
        this.refreshOptions();
    }

    /**
     * One option in the panel's list.
     *
     * The option is a pointer target only: the keyboard highlight lives on
     * the combobox's search field (published through `aria-activedescendant`),
     * which is where the focus stays while the operator types and arrows
     * through the list.
     */
    optionNode(row, opts) {
        const key = modelKey(row);
        const batchId = isBatchId(row.model);
        // Batch mode makes these the point of the run rather than a mistake:
        // the id stays marked, but it stops being refused.
        const batchMode = this.owner.batchModeEnabled();
        const batch = batchId && !batchMode;
        const attrs = {
            class: 'oc-model-option' + (opts.selected ? ' oc-model-option-current' : ''),
            role: 'option',
            id: `${this.listId}-${opts.index}`,
            dataset: { value: key },
            'aria-selected': opts.selected ? 'true' : 'false',
            title: `${row.provider} / ${row.model}`,
        };
        const children = [
            // Inside the list the FULL `provider / model` is readable, so a
            // long id is never ambiguous while choosing.
            el('span', { class: 'oc-model-option-model' }, [row.model]),
            el('span', { class: 'oc-model-option-provider' }, [row.provider]),
        ];
        if (batchId) {
            attrs.class += ' oc-model-option-batch';
            if (batchMode) {
                // Selectable: this run goes to the Batch API, so a batch-only
                // id is a first-class pool member. The badge and the tooltip
                // stay, because the asynchronous wait is the thing the
                // operator has to expect.
                attrs.title = batchModeNotice(row.model);
            } else {
                // Listed, marked, and not pickable: hiding the id would leave
                // the operator wondering why their provider's catalogue and
                // this list disagree, while offering it would put a model in
                // the pool that L1 can never call.
                attrs['aria-disabled'] = 'true';
                attrs.title = batchPoolRejection(row.model);
            }
            children.push(batchBadgeNode(row.model));
        }
        const node = el('li', attrs, children);
        node.addEventListener('click', () => this.pick(node, row));
        return node;
    }

    /** Current option list: pool/catalogue rules, then this panel's query. */
    availableOptions() {
        const base = this.owner.catalogOptions(this.currentKey);
        const filter = normaliseModelFilter(this.query);
        return filter === '' ? base : base.filter((row) => modelMatchesFilter(row, filter));
    }

    /**
     * Re-derive the list from the live filter and rebuild the `<li>`s.
     *
     * The search input is never touched, which is what keeps the caret
     * still while typing. The trigger is only disabled when the *global*
     * filter leaves nothing to offer — the same rule the add row had when
     * it was a `<select>` — never because of what is being typed right now.
     */
    refreshOptions() {
        const base = this.owner.catalogOptions(this.currentKey);
        const options = this.availableOptions();
        this.options = options;
        this.optionNodes = [];
        this.list.replaceChildren();
        options.forEach((row, index) => {
            const node = this.optionNode(row, {
                selected: modelKey(row) === this.currentKey,
                index,
            });
            this.optionNodes.push(node);
            this.list.append(node);
        });
        this.note.textContent = options.length > 0 ? '' : this.emptyText();
        if (!this.currentKey) {
            // The pool's own selector answers "can anything be added, and if
            // not, why" in one place (see addAvailability); the standalone
            // single-model picker has no pool to add to and keeps the
            // generic wording.
            const availability = typeof this.owner.addAvailability === 'function'
                ? this.owner.addAvailability()
                : null;
            const disabled = availability === null ? base.length === 0 : availability.addable === 0;
            const reason = availability === null ? '' : String(availability.reason || '');
            this.trigger.disabled = disabled;
            if (!disabled) {
                this.trigger.title = 'Add another model to the consensus pool';
            } else if (reason !== '') {
                this.trigger.title = reason;
            } else {
                this.trigger.title = 'No model is available to add — every catalogue model is already in the pool, '
                    + 'or the filter above hides them all.';
            }
        }
        if (this.isOpen) { this.setHighlight(this.defaultHighlight()); }
    }

    /** What the panel says when the list is empty. */
    emptyText() {
        const own = this.query.trim();
        if (own !== '') {
            return `No model matches "${own}". Clear the search field to see the whole list again.`;
        }
        if (this.emptyTextOverride !== '') {
            return this.emptyTextOverride;
        }
        if (this.owner.showAll === true) {
            // Search mode: an empty box and a box that matched nothing are two
            // different states, and the panel says which one it is instead of
            // pretending the catalogue is empty.
            return normaliseModelFilter(this.owner.filterText) === ''
                ? 'Search all models is on: type in the box above to search the whole catalogue — up to '
                    + `${SEARCH_LIMIT} matches are listed.`
                : 'No catalogue model matches the search above. Change it to search again.';
        }
        return 'No model to choose here: every available model is already part of the pool. '
            + 'Turn on "Search all models" above to look one up, or clear the filter.';
    }

    /** Index the highlight starts on: the row's own model, else the first match. */
    defaultHighlight() {
        for (let i = 0; i < this.options.length; i++) {
            if (modelKey(this.options[i]) === this.currentKey && this.isSelectable(i)) { return i; }
        }
        for (let i = 0; i < this.optionNodes.length; i++) {
            if (this.isSelectable(i)) { return i; }
        }
        return -1;
    }

    /** Whether an option can be picked at all (batch-only ids cannot). */
    isSelectable(index) {
        const node = this.optionNodes[index];
        if (!node) { return false; }
        return node.getAttribute('aria-disabled') !== 'true';
    }

    setHighlight(index) {
        const total = this.optionNodes.length;
        if (this.highlight >= 0 && this.optionNodes[this.highlight]) {
            this.optionNodes[this.highlight].classList.remove('oc-model-option-active');
        }
        if (total === 0 || index < 0 || index >= total) {
            this.highlight = -1;
            this.search.removeAttribute('aria-activedescendant');
            return;
        }
        this.highlight = index;
        this.optionNodes[index].classList.add('oc-model-option-active');
        // The search field holds the focus while the list is navigated, so
        // the active option is announced through it.
        this.search.setAttribute('aria-activedescendant', this.optionNodes[index].id);
    }

    /** Move the highlight by `delta`, skipping options that cannot be picked. */
    move(delta) {
        const total = this.optionNodes.length;
        if (total === 0) { return; }
        let index = this.highlight;
        for (let step = 0; step < total; step++) {
            index = index < 0
                ? (delta > 0 ? 0 : total - 1)
                : (index + delta + total) % total;
            if (this.isSelectable(index)) { this.setHighlight(index); return; }
        }
    }

    /** Pick the highlighted option (Enter, or the option's own click). */
    commitActive() {
        if (this.highlight < 0 || !this.isSelectable(this.highlight)) { return false; }
        this.pick(this.optionNodes[this.highlight], this.options[this.highlight]);
        return true;
    }

    /**
     * Choose a row: close first, then hand the row to the owner. The owner
     * re-renders the whole widget, so this instance is detached by the time
     * the call returns — closing afterwards would touch a dead node.
     */
    pick(node, row) {
        if (!node || !row || node.getAttribute('aria-disabled') === 'true') { return; }
        this.close();
        this.onPick(row);
    }

    open() {
        if (this.isOpen) { return; }
        if (this.trigger.disabled === true) { return; }
        this.owner.closeComboboxes(this);
        this.isOpen = true;
        this.panel.hidden = false;
        this.trigger.setAttribute('aria-expanded', 'true');
        if (this.rowNode) { this.rowNode.classList.add('oc-model-row-open'); }
        this.setHighlight(this.defaultHighlight());
        // Straight into the search field: opening the list and typing is one
        // gesture, and the field is the only thing that narrows it.
        this.search.focus();
    }

    close() {
        if (!this.isOpen) { return; }
        this.isOpen = false;
        this.panel.hidden = true;
        this.trigger.setAttribute('aria-expanded', 'false');
        if (this.rowNode) { this.rowNode.classList.remove('oc-model-row-open'); }
        this.search.removeAttribute('aria-activedescendant');
        // Re-opening starts from the row itself, not from the last search.
        if (this.query !== '') {
            this.query = '';
            this.pendingQuery = '';
            if (this.timer) { clearTimeout(this.timer); this.timer = null; }
            this.search.value = '';
            this.refreshOptions();
        }
    }

    toggle() {
        if (this.isOpen) { this.close(); } else { this.open(); }
    }

    /** Escape: close, put the focus back on the row, change nothing. */
    dismiss() {
        this.close();
        this.trigger.focus();
    }

    /** Debounced keystroke path — the input node itself is never rebuilt. */
    queueQuery(text) {
        this.pendingQuery = typeof text === 'string' ? text : '';
        if (this.timer) { clearTimeout(this.timer); }
        this.timer = setTimeout(() => {
            this.timer = null;
            this.applyQuery(this.pendingQuery);
        }, FILTER_DEBOUNCE_MS);
    }

    /** Apply a search string now (programmatic callers and tests). */
    applyQuery(text) {
        if (this.timer) { clearTimeout(this.timer); this.timer = null; }
        const next = typeof text === 'string' ? text : '';
        this.pendingQuery = next;
        if (next === this.query) { return; }
        this.query = next;
        this.refreshOptions();
    }

    onTriggerKeydown(event) {
        if (!event) { return; }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.open();
            return;
        }
        if (event.key === 'Escape' && this.isOpen) {
            event.preventDefault();
            this.close();
        }
    }

    onSearchKeydown(event) {
        if (!event) { return; }
        switch (event.key) {
            case 'ArrowDown': event.preventDefault(); this.move(1); return;
            case 'ArrowUp':   event.preventDefault(); this.move(-1); return;
            case 'Home':      event.preventDefault(); this.setHighlight(0); return;
            case 'End':       event.preventDefault(); this.setHighlight(this.options.length - 1); return;
            case 'Enter':     event.preventDefault(); this.commitActive(); return;
            case 'Escape':    event.preventDefault(); this.dismiss(); return;
            case 'Tab':       this.close(); return;
            default:          return;
        }
    }
}

/**
 * A picker for ONE model, built on the same {@see ModelCombobox} the pool
 * rows use. (v1.8.53.)
 *
 * Several admin surfaces need "choose a single provider+model" — the
 * Actualize section's analyzer is the first — and the combobox already
 * solves the parts that are easy to get subtly wrong: a search field inside
 * the list, arrow-key navigation with a live `aria-activedescendant`, and
 * the batch-only badge with its refusal. This factory supplies the three
 * things a combobox asks of its owner (a catalogue, "is batch mode on", and
 * "close the others"), so a single-model picker costs no second
 * implementation of any of that.
 *
 * Deliberately single-value: the current value is always present in the
 * list (so the trigger never describes a model the panel cannot show), and
 * picking replaces it. There is no remove, because "no value" is expressed
 * by the caller (the Actualize section clears its key instead — see
 * models.js).
 *
 * @param {object} opts
 * @param {Array<{provider:string,model:string}>} [opts.catalog]
 * @param {{provider:string,model:string}|null} [opts.value] value in effect
 * @param {(row:{provider:string,model:string})=>void} [opts.onPick]
 * @param {string} [opts.ariaLabel] trigger accessible name
 * @param {string} [opts.searchLabel] search-field accessible name
 * @param {string} [opts.title] trigger tooltip when the value is not batch-only
 * @param {string} [opts.emptyText] what the panel says with nothing to offer
 * @returns {{node:Node,value:Function,setValue:Function,setCatalog:Function}}
 */
export function createModelPicker(opts = {}) {
    const state = {
        catalog: Array.isArray(opts.catalog) ? opts.catalog.slice() : [],
        value: normaliseSinglePick(opts.value),
        onPick: typeof opts.onPick === 'function' ? opts.onPick : () => {},
    };
    let combo = null;

    // The combobox's owner contract, in three lines. Batch mode is always
    // off here: a `:batch` id cannot answer a synchronous call, so the
    // option stays listed, marked and unpickable — the same rule the server
    // enforces when the value is saved.
    const owner = {
        batchModeEnabled: () => false,
        closeComboboxes: (except) => { if (combo && combo !== except) { combo.close(); } },
        // Every catalogued model is on offer — unlike a pool row, there is
        // no "already taken" set to hide anything from: the value in effect
        // is one of these rows, and picking another replaces it.
        //
        // The one addition: a value that is NOT in the catalogue (an
        // `analyzer.model` set before the model was catalogued, say) is
        // listed too, so the closed row and the open list cannot describe
        // two different models.
        catalogOptions: (currentKey) => {
            const rows = state.catalog;
            if (currentKey === '' || rows.some((row) => modelKey(row) === currentKey)) {
                return rows;
            }
            return state.value ? [state.value, ...rows] : rows;
        },
    };

    const wrap = el('div', { class: 'oc-model-picker' });

    function render() {
        const current = state.value;
        combo = new ModelCombobox(owner, {
            currentKey: current ? modelKey(current) : '',
            model: current ? current.model : '',
            labelText: current ? current.model : 'No model selected',
            title: opts.title || '',
            ariaLabel: opts.ariaLabel || 'Model',
            searchLabel: opts.searchLabel || 'Search models',
            emptyText: opts.emptyText || '',
            onPick: (row) => {
                // Re-render from the new value: the trigger label and the
                // batch badge belong to the closed row, and rebuilding is
                // how the pool rows keep the two in step as well.
                state.value = { provider: row.provider, model: row.model };
                render();
                state.onPick({ ...state.value });
            },
        });
        wrap.replaceChildren(combo.wrap);
    }
    render();

    return {
        node: wrap,
        /** The value in effect, or null. */
        value: () => (state.value ? { ...state.value } : null),
        /** Replace the value without firing `onPick` (a load, not a choice). */
        setValue: (row) => {
            state.value = normaliseSinglePick(row);
            render();
        },
        /** Replace the catalogue (the Models tab's list can change under it). */
        setCatalog: (rows) => {
            state.catalog = Array.isArray(rows) ? rows.slice() : [];
            if (combo) { combo.refreshOptions(); }
        },
    };
}

/** `{provider, model}` with both halves non-empty, or null. */
function normaliseSinglePick(row) {
    if (!row || typeof row !== 'object') return null;
    const provider = typeof row.provider === 'string' ? row.provider.trim() : '';
    const model = typeof row.model === 'string' ? row.model.trim() : '';
    return provider !== '' && model !== '' ? { provider, model } : null;
}

export class ModelSelector extends EventTarget {
    constructor(root) {
        super();
        this.root = root;
        this.catalog = [];   // [{provider, model}]
        this.selection = []; // [{provider, model}]
        // v1.8.50 — filter state. Deliberately on the instance, not in the
        // DOM: render() rebuilds the whole control block, and applyPreset()
        // / setCatalog() both re-render, so a value read from an input node
        // would be lost every time the pool changed.
        this.filterText = '';
        this.showAll = false;
        // Batch API mode. Off by default, because the synchronous pool is
        // what the sidebar is for by default: with the flag off a `:batch` id
        // is marked and refused, exactly as before this mode existed. With it
        // on — the operator ticked "Run via Batch API" in the composer — such
        // an id becomes a first-class pool member and stops being refused.
        this.batchMode = false;
        // Live comboboxes, so one opening can close the others and a click
        // outside the widget can close whichever is open.
        this.comboboxes = [];
        // Set by the page that owns the Consensus skill dropdown (app.js): the
        // Save control below asks it to persist the pool for the chosen skill.
        this.poolSaver = null;
        this.saveBtn = null;
        this.saveStatus = null;
        // Delegated: a click is delivered through the document, so the
        // "outside" test is made against the root, once, for every row.
        this.onDocumentClick = (event) => this.closeComboboxesOutside(event ? event.target : null);
        if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
            document.addEventListener('click', this.onDocumentClick);
        }
    }

    /** Is the pool being assembled for an asynchronous batch run? */
    batchModeEnabled() {
        return this.batchMode === true;
    }

    /**
     * Switch the pool between synchronous and batch rules.
     *
     * Re-renders, because the flag decides whether a `:batch` option row is
     * pickable — that is `aria-disabled` on the option itself, which only
     * exists in the DOM render() builds. The current selection is left
     * alone: models already in the pool stay there, and a batch id that was
     * picked in batch mode is not silently dropped when the operator
     * switches back (it will simply be refused at dispatch, with the reason
     * the badge and the Models tab already show).
     */
    setBatchMode(on) {
        const next = on === true;
        if (next === this.batchMode) { return; }
        this.batchMode = next;
        this.render();
    }

    /** Close every open panel except `except` (a combobox or null). */
    closeComboboxes(except) {
        for (const combo of this.comboboxes) {
            if (combo !== except) { combo.close(); }
        }
    }

    /**
     * Close on a click outside the widget.
     *
     * A click anywhere inside `#model-selector` is ignored on purpose: the
     * panel, its search field and the empty state are all inside, and a
     * click in any of them must not close the list the operator is
     * working in.
     */
    closeComboboxesOutside(target) {
        if (target && this.root && typeof this.root.contains === 'function' && this.root.contains(target)) {
            return;
        }
        this.closeComboboxes(null);
    }

    /** Let the page that owns the skill dropdown persist the pool. */
    setPoolSaver(fn) {
        this.poolSaver = typeof fn === 'function' ? fn : null;
    }

    /** The save result, written under the Save control ('' clears it). */
    setSaveStatus(text) {
        if (this.saveStatus) { this.saveStatus.textContent = text == null ? '' : String(text); }
    }

    /** Ask the page to save the pool; say so when no page can. */
    requestSave() {
        if (typeof this.poolSaver === 'function') { this.poolSaver(); return; }
        this.setSaveStatus('Saving the pool for a skill is not available on this page.');
    }

    setCatalog(available, defaults) {
        this.catalog = Array.isArray(available) ? available : [];
        const stored = loadStoredSelection();
        let initial = (stored && stored.length > 0) ? stored : (Array.isArray(defaults) ? defaults : []);
        // Drop any selection entries that are no longer available so the
        // user never sees a row pointing at a missing connector.
        const availableSet = new Set(this.catalog.map(modelKey));
        initial = initial.filter((row) => availableSet.has(modelKey(row)));
        if (initial.length === 0 && this.catalog.length > 0) {
            // No stored selection and no usable defaults left: fall back to a
            // maximally-different spread of the available catalog instead of
            // the first rows, so the pool is not dominated by one family.
            // Batch-only ids are not in that draw: the pool is dispatched
            // synchronously, so seeding it with one would fail every L1 call
            // (they stay visible and marked in the list instead).
            initial = pickDiverseModels(
                this.catalog.filter((row) => !isBatchId(row.model)),
                3,
            ).map((r) => ({ ...r }));
        }
        this.selection = initial;
        this.render();
    }

    selected() {
        return this.selection.slice();
    }

    /**
     * Replace the whole selection with the models a consensus preset
     * defines. Rows marked `active: false` or `selected: false` are not
     * consensus participants, so they are dropped; a row whose model is
     * not in the live catalog is skipped rather than added (it could not
     * be dispatched, and setCatalog() would strip it on the next load
     * anyway).
     *
     * An empty result leaves the current selection untouched — wiping the
     * pool would leave the user with an unusable Chat sidebar — and the
     * caller reports why from the returned counters.
     *
     * @param {Array<{provider:string,model:string,active?:boolean,selected?:boolean}>} rows
     * @returns {{applied:number, skipped:string[], empty:boolean, reason:string}}
     */
    applyPreset(rows) {
        const list = Array.isArray(rows) ? rows : [];
        const wanted = list.filter((row) => row && typeof row.provider === 'string'
            && typeof row.model === 'string'
            && row.active !== false && row.selected !== false);
        if (wanted.length === 0) {
            return { applied: 0, skipped: [], empty: true, reason: 'The preset defines no active consensus models.' };
        }
        const available = new Set(this.catalog.map(modelKey));
        const applied = [];
        const skipped = [];
        for (const row of wanted) {
            const key = modelKey(row);
            if (!available.has(key)) { skipped.push(key); continue; }
            if (applied.some((r) => modelKey(r) === key)) continue;
            applied.push({ provider: row.provider, model: row.model });
        }
        if (applied.length === 0) {
            return {
                applied: 0,
                skipped,
                empty: false,
                reason: 'None of the preset models is currently available from a configured connector.',
            };
        }
        this.selection = applied;
        this.persistAndRender();
        return { applied: applied.length, skipped, empty: false, reason: '' };
    }

    addRow(row) {
        if (!row || !row.provider || !row.model) return;
        const key = modelKey(row);
        if (this.selection.some((r) => modelKey(r) === key)) return;
        this.selection.push({ provider: row.provider, model: row.model });
        this.persistAndRender();
    }

    removeRow(index) {
        if (index < 0 || index >= this.selection.length) return;
        this.selection.splice(index, 1);
        this.persistAndRender();
    }

    replaceRow(index, row) {
        if (index < 0 || index >= this.selection.length) return;
        if (!row || !row.provider || !row.model) return;
        this.selection[index] = { provider: row.provider, model: row.model };
        this.persistAndRender();
    }

    persistAndRender() {
        saveSelection(this.selection);
        this.render();
        this.dispatchEvent(new CustomEvent('change', { detail: this.selected() }));
    }

    /**
     * The text that actually narrows the rows right now: the normalised
     * `filterText`, in BOTH modes.
     *
     * "Search all models" does not switch the box off — it makes it search the
     * whole catalogue instead of only the models missing from the pool. Every
     * consumer asks this one helper, so the list and the counter can never
     * read the same text two different ways.
     *
     * @returns {string} the normalised filter, or '' when nothing filters
     */
    effectiveFilter() {
        return normaliseModelFilter(this.filterText);
    }

    /**
     * Options for one row's combobox (or for the "+ add model" one when
     * `currentKey` is empty).
     *
     * Default mode, in order:
     *   1. The row's OWN value is always present. Without it the browser
     *      would display the first option while the pool still holds the
     *      filtered-out model — the UI would describe a pool that does not
     *      exist.
     *   2. Models already in the pool are excluded.
     *   3. The text is a case-insensitive substring match over provider/model.
     *
     * "Search all models" mode replaces all three: the WHOLE catalogue is
     * searched (pooled models included), only matches are offered, and the list
     * is capped at {@see SEARCH_LIMIT} so it can never grow to the size of the
     * catalogue — which is what made the toggle freeze the sidebar.
     */
    catalogOptions(currentKey) {
        const query = normaliseModelFilter(this.filterText);
        if (this.showAll) {
            if (query === '') { return []; }
            const matches = [];
            for (const row of this.catalog) {
                if (!modelMatchesFilter(row, query)) { continue; }
                matches.push(row);
                if (matches.length >= SEARCH_LIMIT) { break; }
            }
            return matches;
        }
        const taken = new Set(this.selection.map(modelKey));
        const out = [];
        for (const row of this.catalog) {
            const key = modelKey(row);
            if (key !== currentKey) {
                if (taken.has(key)) { continue; }
                if (!modelMatchesFilter(row, query)) { continue; }
            }
            out.push(row);
        }
        return out;
    }

    /**
     * How many catalogue rows the current text hides. Zero when nothing is
     * typed, so the counter never claims a filter that is not there.
     */
    filteredOutCount() {
        const filter = this.effectiveFilter();
        if (!filter) { return 0; }
        return this.catalog.filter((row) => !modelMatchesFilter(row, filter)).length;
    }

    /**
     * Pool entries that do not match the current text. They stay selected and
     * stay visible (a search must never silently drop a model from the
     * consensus pool), and the counter under the list says how many there are
     * so "3 selected" and "1 shown" never contradict each other.
     *
     * @returns {{total:number, hidden:string[]}}
     */
    selectionOutsideFilter() {
        const filter = this.effectiveFilter();
        if (!filter) { return { total: 0, hidden: [] }; }
        const hidden = this.selection.filter((row) => !modelMatchesFilter(row, filter));
        return { total: hidden.length, hidden: hidden.map((row) => row.model) };
    }

    /**
     * Whether the "+ add model" row can offer anything, and why not when it
     * cannot. `reason` is '' exactly when `addable > 0`.
     *
     * The operator reported "the models can be removed but not added". Two
     * mistakes produced that. First, availability was read off the *filtered*
     * option list, so leftover text in the name box switched the button off
     * with no visible explanation — a disabled control shows no tooltip, and
     * the only words lived in one. Second, a filter is a view, not a fact
     * about the pool: the question the button answers is "is there a
     * catalogue model that is not in the pool yet?", which no filter text can
     * change.
     *
     * Causes, each named only when it is the one in play:
     *   - nothing is catalogued at all;
     *   - "Search all models" is on with an empty box — nothing has been
     *     searched for yet;
     *   - "Search all models" is on and matched no catalogue model;
     *   - the pool already holds every catalogue model, or the name filter
     *     hides everything that is left to add.
     *
     * @returns {{addable:number, reason:string}}
     */
    addAvailability() {
        if (this.catalog.length === 0) {
            return { addable: 0, reason: 'Nothing to add: no models are catalogued at all.' };
        }
        const query = normaliseModelFilter(this.filterText);
        if (this.showAll) {
            if (query === '') {
                return {
                    addable: 0,
                    reason: 'Search all models is on: type in the box above to search the whole catalogue.',
                };
            }
            const matches = this.catalog.filter((row) => modelMatchesFilter(row, query)).length;
            if (matches === 0) {
                return { addable: 0, reason: `No catalogue model matches "${this.filterText.trim()}".` };
            }
            return { addable: matches, reason: '' };
        }
        const inPool = new Set(this.selection.map(modelKey));
        const remaining = this.catalog.filter((row) => !inPool.has(modelKey(row)));
        if (remaining.length === 0) {
            return { addable: 0, reason: 'Nothing left to add: every catalogue model is already in the pool.' };
        }
        if (query !== '' && !remaining.some((row) => modelMatchesFilter(row, query))) {
            return { addable: 0, reason: 'The name filter above hides every remaining model.' };
        }
        return { addable: remaining.length, reason: '' };
    }

    /**
     * Filter controls. Rebuilt by render(), so the values come from state.
     *
     * The box is always live: by default it narrows the models that are not in
     * the pool yet, and with "Search all models" ticked it becomes the query
     * over the whole catalogue. It is never switched off, which is why
     * {@see self::applyFilter()} keeps it enabled at all times.
     */
    filterControls() {
        const filterId = 'oc-model-filter-text';
        const allId = 'oc-model-filter-all';
        const input = el('input', {
            type: 'search',
            id: filterId,
            class: 'oc-model-filter-input',
            placeholder: 'Search / filter models…',
            'aria-label': 'Search or filter the model list by name',
            value: this.filterText,
            oninput: (e) => this.queueFilter(e.target.value),
        });
        this.filterInput = input;
        const toggle = el('input', {
            type: 'checkbox',
            id: allId,
            checked: this.showAll ? 'checked' : false,
            title: 'Search the WHOLE catalogue, not just the models that are not in the pool yet. Type in the box '
                + 'above; only matches are listed, at most ' + SEARCH_LIMIT + ' of them, and the list stays empty '
                + 'until you type something.',
            onchange: (e) => this.setShowAll(e.target.checked),
        });
        return el('div', { class: 'oc-model-filter' },
            [input, el('label', { class: 'oc-model-filter-toggle', for: allId },
                [toggle, el('span', {}, ['Search all models'])])]);
    }

    /**
     * Keystrokes are debounced so a fast typist does not rebuild the option
     * lists on every character. The input node itself is never re-created
     * (only the option lists and the counter are), so focus and caret
     * position survive the update.
     */
    queueFilter(text) {
        this.pendingFilter = typeof text === 'string' ? text : '';
        if (this.filterTimer) { clearTimeout(this.filterTimer); }
        this.filterTimer = setTimeout(() => {
            this.filterTimer = null;
            this.setFilter(this.pendingFilter);
        }, FILTER_DEBOUNCE_MS);
    }

    /**
     * Apply a filter string now (programmatic callers and tests use this;
     * `queueFilter()` is the debounced keystroke path).
     */
    setFilter(text) {
        if (this.filterTimer) { clearTimeout(this.filterTimer); this.filterTimer = null; }
        const next = typeof text === 'string' ? text : '';
        this.filterText = next;
        this.pendingFilter = next;
        this.applyFilter();
    }

    setShowAll(value) {
        const next = value === true;
        if (next === this.showAll) { return; }
        this.showAll = next;
        this.applyFilter();
    }

    /**
     * Re-derive every combobox's list, the filter field's own state and the
     * counters from the current filter. This is the *pool-wide* filter (the
     * box above the rows plus "Search all models"); a combobox's own search
     * field narrows only its own list, on top of this.
     *
     * Kept separate from {@see self.render()} on purpose: re-rendering the
     * whole widget would replace the search inputs mid-typing and blur them.
     */
    applyFilter() {
        if (!this.root) { return; }
        // The box is never disabled — in both modes it is what narrows the
        // rows. The toggle only decides whether it searches the whole
        // catalogue or just the models missing from the pool.
        if (this.filterInput) {
            this.filterInput.disabled = false;
            this.filterInput.title = this.showAll
                ? 'Searches the WHOLE catalogue: a case-insensitive part of the provider or of the model id. '
                    + `Only matches are listed, at most ${SEARCH_LIMIT}.`
                : 'Narrow the model list by name: a case-insensitive part of the provider or of the model id. '
                    + 'Tick "Search all models" to search the whole catalogue instead.';
        }
        const filter = this.effectiveFilter();
        for (const rowRef of this.rowRefs || []) {
            const hidden = !modelMatchesFilter(rowRef.row, filter);
            if (rowRef.combo) { rowRef.combo.refreshOptions(); }
            if (rowRef.wrap) {
                // The row stays in the pool and stays on screen; the class
                // only marks it as "outside the current filter".
                rowRef.wrap.classList.toggle('oc-model-row-filtered', hidden);
            }
        }
        if (this.addCombo) { this.addCombo.refreshOptions(); }
        this.renderCounter();
    }

    /**
     * The line under the list: what is filtering right now, how much of the
     * catalogue that hides, how many pool members fall outside it, and — when
     * it applies — why no model can be added.
     *
     * The wording is derived from the state, never assembled from fixed
     * pieces that can disagree: "Showing every catalogue model" and "N hidden
     * by filter" are mutually exclusive by construction, because the filter
     * that hides something is the filter {@see self::effectiveFilter()}
     * reports, and it is empty exactly when the toggle is on. That
     * contradiction on screen — both sentences at once, for a list the toggle
     * was supposed to have widened — is what the operator reported.
     *
     * The second half of the old wording matters as much: the operator has to
     * be able to tell "3 selected, 1 matching what I typed" from "the filter
     * dropped two models", which is the sentence that says the pool is
     * untouched.
     */
    renderCounter() {
        if (!this.counter) { return; }
        const selected = this.selection.length;
        const filter = this.effectiveFilter();
        const parts = [];
        if (this.showAll) {
            parts.push(filter === ''
                ? 'Search all models is on: type in the box above to search the whole catalogue.'
                : `Searching every catalogue model for "${this.filterText.trim()}" — matches only, at most `
                    + `${SEARCH_LIMIT} listed.`);
        } else if (filter !== '') {
            const hiddenCatalog = this.filteredOutCount();
            const outside = this.selectionOutsideFilter();
            parts.push(hiddenCatalog > 0
                ? `${hiddenCatalog} catalogue model${hiddenCatalog === 1 ? '' : 's'} hidden by filter.`
                : 'The name filter hides no catalogue model.');
            if (outside.total > 0) {
                parts.push(`${outside.total} selected hidden by filter — still in the pool.`);
            }
        }
        // The add row's own state, in words: a disabled button shows no
        // tooltip, so the reason has to be readable without hovering.
        const addReason = this.addAvailability().reason;
        if (addReason !== '') { parts.push(addReason); }
        this.counter.textContent = `${selected} model${selected === 1 ? '' : 's'} selected.`
            + (parts.length > 0 ? ' ' + parts.join(' ') : '');
        if (this.filterNote) {
            const outside = this.showAll ? { hidden: [] } : this.selectionOutsideFilter();
            this.filterNote.textContent = outside.hidden.length > 0 ? outside.hidden.join(', ') : '';
        }
    }

    render() {
        if (!this.root) return;
        this.root.replaceChildren();
        this.rowRefs = [];
        this.comboboxes = [];
        this.addCombo = null;
        this.counter = null;
        this.filterNote = null;
        this.filterInput = null;
        this.saveBtn = null;
        this.saveStatus = null;
        if (this.catalog.length === 0) {
            this.root.append(el('p', { class: 'oc-muted oc-small' },
                ['No models available. Configure connectors with valid API keys.']));
            return;
        }
        // Filter controls first: the list below them is what they narrow.
        this.root.append(this.filterControls());
        // Selected rows: one combobox each, built inside the row it belongs
        // to, so the popup is ordinary DOM the operator can click into.
        this.selection.forEach((row, idx) => {
            const key = modelKey(row);
            const combo = new ModelCombobox(this, {
                currentKey: key,
                model: row.model,
                labelText: modelDisplay(row),
                title: `${row.provider} / ${row.model} — selected consensus participant. Change to swap the model.`,
                ariaLabel: `Selected consensus model ${idx + 1}: ${row.provider} / ${row.model}`,
                searchLabel: `Search the model list to replace ${row.provider} / ${row.model}`,
                onPick: (pick) => {
                    // Re-picking the model that is already there is a no-op:
                    // it must not rewrite the row or emit a change event.
                    if (modelKey(pick) === key) { return; }
                    this.replaceRow(idx, { provider: pick.provider, model: pick.model });
                },
            });
            const badge = el('span', {
                class: 'oc-model-provider',
                title: 'Provider: ' + row.provider,
            }, [providerBadge(row)]);
            const removeBtn = el('button', {
                type: 'button',
                class: 'oc-model-remove',
                title: 'Remove this model from the consensus pool',
                'aria-label': 'Remove ' + row.model,
                onclick: () => this.removeRow(idx),
            }, ['×']);
            const wrap = el('div', { class: 'oc-model-row' }, [badge, combo.wrap, removeBtn]);
            combo.rowNode = wrap;
            this.root.append(wrap);
            this.comboboxes.push(combo);
            this.rowRefs.push({ row, key, wrap, combo, labelNode: combo.label });
        });

        // Add row: the same control, whose trigger reads "+ add model".
        const addCombo = new ModelCombobox(this, {
            currentKey: '',
            labelText: '+ add model',
            title: 'Add another model to the consensus pool',
            ariaLabel: 'Add a model',
            searchLabel: 'Search the model list to add to the consensus pool',
            addRow: true,
            onPick: (pick) => this.addRow({ provider: pick.provider, model: pick.model }),
        });
        const addWrap = el('div', { class: 'oc-model-row oc-model-row-add' }, [addCombo.wrap]);
        addCombo.rowNode = addWrap;
        this.root.append(addWrap);
        this.comboboxes.push(addCombo);
        this.addCombo = addCombo;

        // Counter line + the "these are outside the filter" detail.
        this.counter = el('p', {
            class: 'oc-muted oc-small',
            title: 'L1 dispatches one parallel call per selected model. Add 3+ for richer consensus.',
        }, [`${this.selection.length} model${this.selection.length === 1 ? '' : 's'} selected.`]);
        this.filterNote = el('p', { class: 'oc-muted oc-small oc-model-filter-note' });
        this.root.append(this.counter, this.filterNote);

        // Save: persists the pool for the skill chosen in the composer through
        // that skill's domain preset, so the choice survives a reload instead of
        // applying only to the next Chat run. The block renders the control (it
        // belongs beside the pool); the page supplies the behaviour with
        // setPoolSaver() and reports the result back through setSaveStatus().
        this.saveBtn = el('button', {
            type: 'button',
            id: 'oc-model-save',
            class: 'oc-button oc-button-ghost',
            title: 'Save this pool for the Consensus skill selected in the composer, so it comes back the next '
                + 'time you pick that skill.',
            onclick: () => this.requestSave(),
        }, ['Save pool for skill']);
        this.saveStatus = el('output', {
            id: 'oc-model-save-status',
            class: 'oc-muted oc-small',
            role: 'status',
        });
        this.root.append(this.saveBtn, this.saveStatus);

        // Options and counters are always derived from the live filter, so a
        // re-render (pool change, preset applied) cannot resurrect a
        // filtered-out option list.
        this.applyFilter();
    }
}
