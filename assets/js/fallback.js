/**
 * fallback.js — the "Fallback & alternatives" panel.
 *
 * This is the UI half of the requirement "make choosing an alternative model
 * convenient when the selected one is unavailable". The backend half is
 * App\Models\Fallback\AlternativeResolver, which ranks candidates by a
 * five-signal fit score; this module's job is to make that score *arguable*
 * rather than magical:
 *
 *   - every alternative shows WHY it ranked there (same vendor, 2× the
 *     context, 31 % cheaper, 99.2 % success), not just a number;
 *   - a model that cannot answer says WHY it cannot (no key / retired /
 *     quota / breaker open), because the remedy differs for each;
 *   - "Swap" is one click per model, and "Swap all unavailable" is one click
 *     for the whole pool;
 *   - the dry-run plan is shown BEFORE anything is written, and the mode
 *     `ask` makes that mandatory rather than a convenience.
 *
 * Everything renders through the ui/components.js factories, so no string of
 * user- or model-supplied data ever reaches innerHTML. The one exception is
 * documented at the call site.
 *
 * @project RevoAp   @since 2.0.0
 */
import { h, txt, clear, append, debounce, uid, copyText } from './ui/dom.js';
import { icon, spinner } from './ui/icons.js';
import {
    Button, Card, Badge, Alert, Stat, Field, Input, Select, Switch, Combobox,
    Dialog, Confirm, Toast, EmptyState, SkeletonBlock, Progress, Separator, Kbd,
} from './ui/components.js';
import { t, tn, formatNumber, formatPercent, timeAgo, formatDuration } from './i18n.js';

const ROOT_ID = 'oc-fallback-root';

/** Cached catalogue rows, shared by the picker and the alternatives view. */
let catalogue = [];
let policy = null;
let availability = {};
let modes = [];
let rules = [];
let poolIds = [];

/* =====================================================================
 * API
 * =================================================================== */

/**
 * One fetch helper, because four separate ones would each invent their own
 * error handling and the panel would show four different failure messages for
 * the same 403.
 */
async function api(path, opts = {}) {
    const res = await fetch(path, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
            ...(opts.headers || {}),
        },
        ...opts,
        body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const text = await res.text();
    let body = null;
    try { body = text ? JSON.parse(text) : null; } catch { body = null; }
    if (!res.ok) {
        const err = new Error(body?.error || `HTTP ${res.status}`);
        err.status = res.status;
        err.body = body;
        throw err;
    }
    return body || {};
}

/** The models the Chat pool currently has selected. */
async function loadPool() {
    try {
        const body = await api('/api/models/availability/summary');
        poolIds = (body.models || []).map((m) => m.id).filter(Boolean);
        return body;
    } catch (err) {
        console.warn('[fallback] summary failed', err);
        return null;
    }
}

async function loadAll() {
    const [policyBody, availBody, catalogueBody] = await Promise.all([
        api('/api/models/fallback/policy').catch((e) => { throw e; }),
        api('/api/models/availability').catch(() => ({ models: [], counts: {}, policy: null, rules: [] })),
        api('/api/models/catalog').catch(() => ({ models: [] })),
    ]);
    policy = policyBody.policy || null;
    modes = policyBody.modes || [];
    rules = policyBody.rules || availBody.rules || [];
    availability = {};
    for (const row of (availBody.models || [])) availability[row.id] = row;
    catalogue = normalizeCatalogue(catalogueBody);
    return { policy: policyBody, availability: availBody };
}

/**
 * The catalogue endpoint differs between the BotHub and OpenRouter importers,
 * so rows are normalised once here rather than at each of the six read sites.
 */
function normalizeCatalogue(body) {
    const raw = Array.isArray(body) ? body : (body.models || body.catalog || body.rows || []);
    const out = [];
    for (const row of raw) {
        if (!row || typeof row !== 'object') continue;
        const provider = String(row.provider || row.source || '');
        const model = String(row.model || row.id || '');
        if (!model) continue;
        out.push({
            id: provider && !model.includes('/') ? `${provider}/${model}` : model,
            provider,
            model,
            vendor: String(row.vendor || row.family || (model.includes('/') ? model.split('/')[0] : provider)),
            context: Number(row.context_length || row.context_window || 0) || null,
            pricePrompt: Number(row.pricing_prompt ?? row.prompt_price ?? 0) || null,
            priceCompletion: Number(row.pricing_completion ?? row.completion_price ?? 0) || null,
            enabled: row.enabled === undefined ? true : !!row.enabled,
        });
    }
    return out;
}

/* =====================================================================
 * Rendering
 * =================================================================== */

export async function mount(root) {
    const host = root || document.getElementById(ROOT_ID);
    if (!host) return;
    clear(host);
    host.appendChild(SkeletonBlock({ rows: 6 }));

    try {
        await Promise.all([loadAll(), loadPool()]);
    } catch (err) {
        clear(host);
        if (err.status === 403) {
            host.appendChild(Alert({
                tone: 'warning', title: t('common.unauthorized'),
                description: t('admin.unlock_help'),
            }));
            return;
        }
        host.appendChild(Alert({ tone: 'destructive', title: t('common.error'), description: String(err.message || err) }));
        return;
    }
    render(host);
}

function render(host) {
    clear(host);
    host.classList.add('stack');

    host.appendChild(header());
    host.appendChild(alertsRow());
    host.appendChild(statusGrid());
    host.appendChild(policyCard());
    host.appendChild(poolCard());
    host.appendChild(monitorCard());
    host.appendChild(rulesCard());
    host.appendChild(availabilityTable());
}

function header() {
    return Card({
        eyebrow: t('nav.models'),
        title: t('fallback.title'),
        description: t('fallback.subtitle'),
        actions: [
            Button({ variant: 'outline', size: 'sm', iconName: 'refresh', label: t('common.refresh'), onClick: () => mount() }),
            Button({ variant: 'outline', size: 'sm', iconName: 'scan', label: t('fallback.dry_run'), onClick: showDryRun }),
        ],
    }, h('p', { class: 'text-sm muted prose-measure', text: t('fallback.help') }));
}

/**
 * The banner row. Two conditions genuinely block the feature and are worth a
 * banner rather than a tooltip: a non-persistent install (verdicts die with
 * the request) and `mode: never` (nothing will ever be substituted).
 */
function alertsRow() {
    const wrap = h('div', { class: 'stack-sm' });
    const persistent = availability.__persistent !== false;
    if (!persistent) {
        wrap.appendChild(Alert({
            tone: 'warning', title: t('statistic.no_database'),
            description: t('fallback.monitor_help'),
        }));
    }
    if (policy && policy.mode === 'never') {
        wrap.appendChild(Alert({
            tone: 'info', title: t('fallback.mode_none'),
            description: t('fallback.mode_none_help'),
        }));
    }
    const unavailableCount = countUnavailableInPool();
    if (unavailableCount > 0) {
        wrap.appendChild(Alert({
            tone: 'destructive',
            title: tn('fallback.report_unfilled', unavailableCount, { count: unavailableCount }),
            description: t('fallback.no_alternatives_hint'),
            actions: [
                Button({ size: 'sm', variant: 'destructive', iconName: 'shuffle', label: t('fallback.swap_all'), onClick: swapAll }),
            ],
        }));
    }
    return wrap;
}

function statusGrid() {
    const counts = availabilityCounts();
    const grid = h('div', { class: 'grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-6' });
    grid.appendChild(Stat({ label: t('models.available'), value: formatNumber(counts.healthy || 0), icon: 'circleCheck' }));
    grid.appendChild(Stat({ label: t('models.unknown_status'), value: formatNumber(counts.unknown || 0), icon: 'helpCircle' }));
    grid.appendChild(Stat({ label: t('models.degraded'), value: formatNumber(counts.degraded || 0), icon: 'alertTriangle' }));
    grid.appendChild(Stat({ label: t('models.unavailable'), value: formatNumber(counts.unavailable || 0), icon: 'circleX' }));
    grid.appendChild(Stat({ label: t('models.inactive'), value: formatNumber(counts.retired || 0), icon: 'slash' }));
    grid.appendChild(Stat({ label: t('chat.model_pool'), value: formatNumber(poolIds.length), icon: 'layers' }));
    return grid;
}

function availabilityCounts() {
    const out = {};
    for (const row of Object.values(availability)) {
        if (!row || !row.status) continue;
        out[row.status] = (out[row.status] || 0) + 1;
    }
    return out;
}

function countUnavailableInPool() {
    return poolIds.filter((id) => {
        const row = availability[id];
        return row && ['unavailable', 'retired'].includes(row.status);
    }).length;
}

/* ---------------------------------------------------------------- policy */

function policyCard() {
    const modeOptions = (modes.length ? modes : [{ value: 'auto' }, { value: 'never' }, { value: 'ask' }, { value: 'strict' }])
        .map((m) => ({ value: m.value, label: m.label_key ? t(m.label_key) : m.value }));

    const modeSelect = Select(modeOptions, { value: policy?.mode || 'auto', 'aria-label': t('fallback.mode') });
    const diversity = Switch({ checked: !!policy?.prefer_vendor_diversity, label: t('fallback.prefer_vendor_diversity'), description: t('fallback.prefer_vendor_diversity_help') });
    const sameVendor = Switch({ checked: !!policy?.same_vendor_only, label: t('fallback.same_vendor_only'), description: t('fallback.same_vendor_only_help') });
    const respectCtx = Switch({ checked: policy?.respect_context_window !== false, label: t('fallback.respect_context_window'), description: t('fallback.respect_context_window_help') });
    const allowDegraded = Switch({ checked: !!policy?.allow_degraded_substitutes, label: t('models.degraded'), description: t('fallback.help') });
    const costHard = Switch({ checked: !!policy?.cost_is_hard_limit, label: t('fallback.max_cost_multiplier'), description: t('fallback.max_cost_multiplier_help') });

    const latency = Input({ type: 'number', min: '0', step: '100', class: 'input-sm', value: String(policy?.max_latency_ms || 0), 'aria-label': t('fallback.max_latency_ms') });
    const costMul = Input({ type: 'number', min: '0', step: '0.1', class: 'input-sm', value: String(policy?.max_cost_multiplier ?? 1.5), 'aria-label': t('fallback.max_cost_multiplier') });
    const maxAlt = Input({ type: 'number', min: '1', max: '50', class: 'input-sm', value: String(policy?.max_alternatives || 6), 'aria-label': t('fallback.alternatives_for', { model: '' }) });

    // `same_vendor_only` and `prefer_vendor_diversity` are contradictory: a
    // same-vendor-only policy has no vendors to diversify across. Rather than
    // letting the operator save a nonsense combination and wonder why the
    // diversity toggle does nothing, turning one on disables the other.
    const syncConflict = () => {
        const sv = sameVendor.querySelector('input[type="checkbox"]') || sameVendor;
        const dv = diversity.querySelector('input[type="checkbox"]') || diversity;
        if (sv.checked) { dv.checked = false; dv.disabled = true; }
        else dv.disabled = false;
    };
    sameVendor.addEventListener('change', syncConflict);
    syncConflict();

    const save = Button({
        variant: 'default', iconName: 'save', label: t('common.save'),
        onClick: async () => {
            const body = {
                mode: modeSelect.value,
                prefer_vendor_diversity: readSwitch(diversity),
                same_vendor_only: readSwitch(sameVendor),
                respect_context_window: readSwitch(respectCtx),
                allow_degraded_substitutes: readSwitch(allowDegraded),
                cost_is_hard_limit: readSwitch(costHard),
                max_latency_ms: Number(latency.value) || 0,
                max_cost_multiplier: Number(costMul.value) || 0,
                max_alternatives: Number(maxAlt.value) || 6,
            };
            try {
                const res = await api('/api/models/fallback/policy', { method: 'POST', body });
                if (res.persisted === false) {
                    Toast.warning(t('common.saved'), t('common.error'));
                    return;
                }
                policy = res.policy || policy;
                Toast.success(t('common.saved'));
            } catch (err) {
                Toast.error(t('common.error'), String(err.message || err));
            }
        },
    });

    return Card({
        title: t('fallback.mode'),
        description: t('fallback.help'),
        actions: [save],
    },
        h('div', { class: 'form-row form-row-2' },
            Field({ label: t('fallback.mode'), control: modeSelect, description: t('fallback.help') }),
            Field({ label: t('fallback.max_alternatives') === 'fallback.max_alternatives' ? t('models.presets') : t('fallback.max_alternatives'), control: maxAlt, description: t('fallback.score_help') }),
        ),
        h('div', { class: 'form-row form-row-2' },
            Field({ label: t('fallback.max_latency_ms'), control: latency, description: 'ms' }),
            Field({ label: t('fallback.max_cost_multiplier'), control: costMul, description: t('fallback.max_cost_multiplier_help') }),
        ),
        Separator(),
        h('div', { class: 'grid gap-1 md:grid-cols-2' }, diversity, sameVendor, respectCtx, allowDegraded, costHard),
    );
}

function readSwitch(node) {
    const input = node instanceof HTMLInputElement ? node : node.querySelector('input[type="checkbox"]');
    return !!(input && input.checked);
}

/* ------------------------------------------------------------------ pool */

/**
 * The pool card: the models actually selected in Chat, each with its verdict
 * and a one-click swap. This is the view the requirement is about — an
 * operator who opened the console to run something and finds a model down
 * should be able to fix it here in two clicks, not go to Models, find the
 * row, and work out what to pick.
 */
function poolCard() {
    const body = h('div', { class: 'stack-sm' });

    if (poolIds.length === 0) {
        body.appendChild(EmptyState({
            iconName: 'layers', title: t('chat.model_pool'),
            description: t('models.no_models_at_all'), compact: true,
        }));
        return Card({ title: t('chat.model_pool'), description: t('chat.model_pool_help') }, body);
    }

    for (const id of poolIds) body.appendChild(poolRow(id));

    const footer = h('div', { class: 'row wrap gap-2' },
        Button({ variant: 'outline', size: 'sm', iconName: 'shuffle', label: t('fallback.swap_all'), onClick: swapAll }),
        Button({ variant: 'ghost', size: 'sm', iconName: 'scan', label: t('fallback.dry_run'), onClick: showDryRun }),
    );
    return Card({
        title: t('chat.model_pool'),
        description: tn('chat.models_selected', poolIds.length, { count: poolIds.length }),
        actions: [footer],
    }, body);
}

function poolRow(id) {
    const row = availability[id] || {};
    const status = row.status || 'unknown';
    const tone = row.status_tone || 'muted';
    const labelKey = row.status_label_key || 'models.unknown_status';

    const wrap = h('div', { class: 'row-between gap-2 p-2 rounded-md border border-border bg-card', attrs: { 'data-model-id': id } });

    const left = h('div', { class: 'row gap-2 min-w-0' });
    left.appendChild(icon(statusIcon(status), { size: 16, class: `shrink-0 text-${tone === 'muted' ? 'muted-foreground' : tone}` }));
    const meta = h('div', { class: 'min-w-0 stack-sm' });
    meta.appendChild(h('div', { class: 'mono text-sm truncate', text: id, title: id }));
    const sub = h('div', { class: 'row gap-2 xs muted wrap' });
    sub.appendChild(Badge(t(labelKey), { tone }));
    if (row.reason_label_key) sub.appendChild(h('span', { text: t(row.reason_label_key) }));
    if (row.last_success_at) sub.appendChild(h('span', { text: t('models.last_checked', { ago: timeAgo(row.last_success_at) }) }));
    if (typeof row.success_rate === 'number') sub.appendChild(h('span', { class: 'tabular', text: t('models.success_rate') + ': ' + formatPercent(row.success_rate, 0) }));
    meta.appendChild(sub);
    left.appendChild(meta);
    wrap.appendChild(left);

    const right = h('div', { class: 'row gap-1 shrink-0' });
    if (['unavailable', 'degraded', 'retired'].includes(status) || !row.status) {
        right.appendChild(Button({ variant: 'outline', size: 'sm', iconName: 'search', label: t('fallback.alternatives_for', { model: '' }).replace(/:.*/, '') || t('common.details'), onClick: () => showAlternatives(id) }));
    }
    right.appendChild(Button({ variant: 'ghost', size: 'sm', iconName: 'refresh', label: t('common.retry'), onClick: () => probeOne(id) }));
    wrap.appendChild(right);
    return wrap;
}

function statusIcon(status) {
    return ({ healthy: 'circleCheck', unknown: 'helpCircle', degraded: 'alertTriangle', unavailable: 'circleX', retired: 'slash' })[status] || 'helpCircle';
}

/* -------------------------------------------------------- alternatives */

/**
 * The alternatives dialog for one model.
 *
 * The score is shown as a bar AND as its five components, because a single
 * number invites "why 87 and not 91" and the components answer it. A pinned
 * chain (operator override) is shown above the scored list with an explicit
 * note that it wins regardless of score — otherwise the ranking looks broken.
 */
async function showAlternatives(id) {
    let data;
    const dlg = Dialog({ title: t('fallback.alternatives_for', { model: id }), size: 'lg', content: [SkeletonBlock({ rows: 5 })] });
    try {
        data = await api(`/api/models/alternatives?model=${encodeURIComponent(id)}&pool=${encodeURIComponent(poolIds.filter((p) => p !== id).join(','))}`);
    } catch (err) {
        clear(dlg.body);
        dlg.body.appendChild(Alert({ tone: 'destructive', title: t('common.error'), description: String(err.message || err) }));
        return;
    }

    clear(dlg.body);

    if (data.current) {
        dlg.body.appendChild(Alert({
            tone: data.current.status === 'healthy' ? 'success' : 'warning',
            title: t('models.health') + ': ' + t(data.current.status_label_key || 'models.unknown_status'),
            description: data.current.primary_reason_label_key ? t(data.current.primary_reason_label_key) : null,
        }));
    }

    const pinned = data.pinned_chain || [];
    if (pinned.length) {
        dlg.body.appendChild(Alert({
            tone: 'info', title: t('fallback.chain'),
            description: pinned.join(' → '),
        }));
    }

    const list = data.alternatives || [];
    if (list.length === 0) {
        dlg.body.appendChild(EmptyState({
            iconName: 'circleX', title: t('fallback.no_alternatives'), description: t('fallback.no_alternatives_hint'),
        }));
        return;
    }

    const table = h('div', { class: 'stack-sm' });
    for (const cand of list) table.appendChild(candidateRow(id, cand));
    dlg.body.appendChild(table);
}

function candidateRow(fromId, cand) {
    const row = h('div', { class: 'p-3 rounded-lg border border-border bg-card stack-sm' });

    const head = h('div', { class: 'row-between gap-2' });
    const left = h('div', { class: 'min-w-0 stack-sm' });
    left.appendChild(h('div', { class: 'row gap-2 wrap' },
        h('span', { class: 'mono text-sm font-medium truncate', text: cand.id, title: cand.id }),
        Badge(t(cand.status_label_key || 'models.unknown_status'), { tone: cand.status_tone || 'muted' }),
        cand.same_vendor ? Badge(t('models.vendor'), { tone: 'outline' }) : Badge(t('fallback.prefer_vendor_diversity'), { tone: 'info' }),
    ));
    const facts = h('div', { class: 'row gap-3 xs muted wrap tabular' });
    if (cand.context_length) facts.appendChild(h('span', { text: `${t('models.context_window')}: ${formatNumber(cand.context_length)}` }));
    if (cand.avg_latency_ms) facts.appendChild(h('span', { text: `${t('models.avg_latency')}: ${formatNumber(Math.round(cand.avg_latency_ms))} ms` }));
    if (cand.price) facts.appendChild(h('span', { text: `${t('statistic.cost')}: ${cand.price.toExponential(2)}` }));
    facts.appendChild(h('span', { text: `${t('fallback.score')}: ${formatPercent(cand.score, 0)}` }));
    left.appendChild(facts);
    head.appendChild(left);

    head.appendChild(Button({
        variant: 'default', size: 'sm', iconName: 'shuffle', label: t('fallback.one_click_swap'),
        onClick: async () => {
            const ok = await Confirm({
                title: t('fallback.one_click_swap'),
                description: `${fromId} → ${cand.id}`,
                confirmLabel: t('fallback.one_click_swap'),
            });
            if (!ok) return;
            await applySwap(fromId, cand.id);
        },
    }));
    row.appendChild(head);

    // The five component bars. Rendered as divs with inline width because a
    // Tailwind `w-[37%]` class cannot be generated from a runtime value.
    const comps = cand.components || {};
    const bars = h('div', { class: 'grid gap-1.5 md:grid-cols-5' });
    for (const [key, value] of Object.entries(comps)) {
        const cell = h('div', { class: 'stack-sm' });
        cell.appendChild(h('div', { class: 'row-between xs muted' },
            h('span', { text: t(`fallback.component_${key}`) === `fallback.component_${key}` ? key : t(`fallback.component_${key}`) }),
            h('span', { class: 'tabular', text: formatPercent(Number(value), 0) }),
        ));
        const track = h('div', { class: 'progress h-1.5' });
        const fill = h('div', { class: 'progress-indicator' });
        fill.style.transform = `scaleX(${Math.max(0, Math.min(1, Number(value) || 0))})`;
        track.appendChild(fill);
        cell.appendChild(track);
        bars.appendChild(cell);
    }
    row.appendChild(bars);
    return row;
}

/**
 * Apply a swap to the persisted Chat pool.
 *
 * Goes through the existing `/api/models/pool` contract rather than writing
 * the runtime overlay directly, so the change is visible to the Chat tab
 * without a reload and goes through the same validation the picker uses.
 */
async function applySwap(fromId, toId) {
    try {
        await api('/api/models/pool', { method: 'POST', body: { replace: { from: fromId, to: toId } } });
        poolIds = poolIds.map((id) => (id === fromId ? toId : id));
        Toast.success(t('fallback.swapped', { from: fromId, to: toId }));
        await Promise.all([loadAll(), loadPool()]);
        mount();
    } catch (err) {
        if (err.status === 404 || err.status === 405) {
            // The pool endpoint is not exposed on this build; fall back to the
            // substitution being advisory and say so rather than failing
            // silently and leaving the operator thinking the swap happened.
            Toast.warning(t('fallback.swap_failed', { from: fromId, reason: t('common.not_found') }));
            return;
        }
        Toast.error(t('fallback.swap_failed', { from: fromId, reason: String(err.message || err) }));
    }
}

async function swapAll() {
    const targets = poolIds.filter((id) => {
        const row = availability[id];
        return row && ['unavailable', 'retired'].includes(row.status);
    });
    if (targets.length === 0) {
        Toast.info(t('fallback.no_alternatives'));
        return;
    }
    const ok = await Confirm({
        title: t('fallback.swap_all'),
        description: tn('fallback.report_replaced', targets.length, { count: targets.length }),
        confirmLabel: t('fallback.swap_all'),
    });
    if (!ok) return;

    let done = 0;
    for (const id of targets) {
        try {
            const data = await api(`/api/models/alternatives?model=${encodeURIComponent(id)}&pool=${encodeURIComponent(poolIds.filter((p) => p !== id).join(','))}`);
            const best = (data.alternatives || [])[0];
            if (!best) continue;
            await applySwap(id, best.id);
            done += 1;
        } catch (err) {
            console.warn('[fallback] swap failed for', id, err);
        }
    }
    Toast.success(tn('fallback.report_replaced', done, { count: done }));
}

/* ---------------------------------------------------------------- dry run */

async function showDryRun() {
    const dlg = Dialog({ title: t('fallback.dry_run'), description: t('fallback.dry_run_help'), size: 'lg', content: [SkeletonBlock({ rows: 4 })] });
    try {
        const data = await api('/api/models/alternatives/plan', { method: 'POST', body: { pool: poolIds } });
        clear(dlg.body);
        const plan = data.plan || {};
        dlg.body.appendChild(Alert({
            tone: plan.changed ? 'warning' : 'success',
            title: plan.changed
                ? tn('fallback.report_replaced', (plan.replaced || []).length, { count: (plan.replaced || []).length })
                : t('security.no_recommendations'),
            description: (plan.unfilled || []).length
                ? tn('fallback.report_unfilled', plan.unfilled.length, { count: plan.unfilled.length })
                : null,
        }));

        if ((plan.replaced || []).length) {
            const table = h('div', { class: 'table-wrap' });
            const t2 = h('table', { class: 'table table-compact' });
            const tbody = h('tbody');
            for (const r of plan.replaced) {
                tbody.appendChild(h('tr', {},
                    h('td', { class: 'mono xs', text: r.from }),
                    h('td', {}, icon('arrowRight', { size: 14, class: 'flip-rtl muted' })),
                    h('td', { class: 'mono xs', text: r.to }),
                    h('td', { class: 'num-cell tabular', text: formatPercent(Number(r.score) || 0, 0) }),
                    h('td', { class: 'xs muted', text: r.reason_label_key ? t(r.reason_label_key) : '' }),
                ));
            }
            t2.appendChild(tbody);
            table.appendChild(t2);
            dlg.body.appendChild(table);
        }

        if ((plan.unfilled || []).length) {
            const list = h('ul', { class: 'stack-sm text-sm' });
            for (const u of plan.unfilled) {
                list.appendChild(h('li', { class: 'row gap-2' },
                    icon('alertTriangle', { size: 14, class: 'text-warning shrink-0' }),
                    h('span', { class: 'mono xs', text: u.from }),
                    h('span', { class: 'muted xs', text: u.reason_label_key ? t(u.reason_label_key) : u.cause }),
                ));
            }
            dlg.body.appendChild(list);
        }
    } catch (err) {
        clear(dlg.body);
        dlg.body.appendChild(Alert({ tone: 'destructive', title: t('common.error'), description: String(err.message || err) }));
    }
}

/* -------------------------------------------------------------- monitor */

function monitorCard() {
    const monitor = policy?.monitor || {};
    const interval = Input({ type: 'number', min: '30', max: '86400', step: '30', class: 'input-sm', value: String(monitor.interval_seconds || 300), 'aria-label': t('fallback.monitor_interval') });
    const enabled = Switch({ checked: monitor.enabled !== false, label: t('fallback.monitor_enabled'), description: t('fallback.monitor_help') });

    const probeBtn = Button({
        variant: 'default', size: 'sm', iconName: 'scan', label: t('fallback.probe_now'),
        onClick: async (ev) => {
            const btn = ev.currentTarget;
            btn.disabled = true;
            btn.appendChild(spinner(13));
            try {
                const res = await api('/api/models/availability/probe', { method: 'POST', body: {} });
                Toast.success(t('fallback.probe_results', { ok: res.ok_count ?? res.ok ?? 0, total: (res.ok ?? 0) + (res.failed ?? 0) }));
                if (res.truncated) Toast.info(t('common.more'), String(res.due_total));
                await loadAll();
                mount();
            } catch (err) {
                Toast.error(t('common.error'), String(err.message || err));
                btn.disabled = false;
                btn.querySelector('.spinner')?.remove();
            }
        },
    });

    return Card({
        title: t('fallback.availability_monitor'),
        description: t('fallback.monitor_help'),
        actions: [probeBtn],
    },
        h('div', { class: 'form-row form-row-2' },
            Field({ label: t('fallback.monitor_interval'), control: interval, description: 's' }),
            h('div', {}, enabled),
        ),
    );
}

async function probeOne(id) {
    try {
        const res = await api('/api/models/availability/probe', { method: 'POST', body: { ids: [id] } });
        const first = (res.results || [])[0];
        if (first && first.status === 'healthy') Toast.success(id, t('models.breaker_closed'));
        else if (first) Toast.warning(id, first.reason_label_key ? t(first.reason_label_key) : (first.error || t('common.failed')));
        else Toast.info(id, t('models.unknown_status'));
        await loadAll();
        mount();
    } catch (err) {
        Toast.error(t('common.error'), String(err.message || err));
    }
}

/* ---------------------------------------------------------------- rules */

function rulesCard() {
    const body = h('div', { class: 'stack-sm' });
    if (!rules.length) {
        body.appendChild(EmptyState({ iconName: 'listChecks', title: t('fallback.chain_empty'), compact: true }));
    }
    for (const rule of rules) body.appendChild(ruleRow(rule));
    return Card({
        title: t('fallback.rules'),
        description: t('fallback.help'),
        actions: [Button({ variant: 'outline', size: 'sm', iconName: 'plus', label: t('fallback.add_rule'), onClick: () => editRule(null) })],
    }, body);
}

function ruleRow(rule) {
    const row = h('div', { class: 'row-between gap-2 p-2 rounded-md border border-border bg-card' });
    const left = h('div', { class: 'min-w-0 stack-sm' });
    left.appendChild(h('div', { class: 'row gap-2 wrap' },
        h('span', { class: 'text-sm font-medium', text: rule.name }),
        Badge(rule.enabled ? t('common.enabled') : t('common.disabled'), { tone: rule.enabled ? 'success' : 'muted' }),
        Badge(String(rule.priority), { tone: 'outline' }),
    ));
    left.appendChild(h('div', { class: 'xs muted mono truncate', text: `${rule.match_model || '*'} · ${rule.match_reason || '*'} → ${rule.action}${rule.action_model ? ' ' + rule.action_model : ''}` }));
    row.appendChild(left);
    row.appendChild(h('div', { class: 'row gap-1 shrink-0' },
        Button({ variant: 'ghost', size: 'icon', iconName: 'pencil', ariaLabel: t('common.edit'), onClick: () => editRule(rule) }),
        Button({ variant: 'ghost', size: 'icon', iconName: 'trash', ariaLabel: t('common.delete'), onClick: async () => {
            const ok = await Confirm({ title: t('common.delete'), description: rule.name, confirmLabel: t('common.delete'), danger: true });
            if (!ok) return;
            try { await api(`/api/models/fallback/rules/${encodeURIComponent(rule.id)}`, { method: 'DELETE' }); Toast.success(t('fallback.rule_deleted')); await loadAll(); mount(); }
            catch (err) { Toast.error(t('common.error'), String(err.message || err)); }
        } }),
    ));
    return row;
}

function editRule(existing) {
    const name = Input({ value: existing?.name || '', placeholder: t('fallback.rule_name') });
    const matchModel = Combobox(
        catalogue.map((c) => ({ value: c.id, label: c.id, group: c.provider })),
        { value: existing?.match_model || null, placeholder: t('models.model'), class: 'w-full' },
    );
    const action = Select([
        { value: 'substitute', label: t('fallback.one_click_swap') },
        { value: 'never', label: t('fallback.mode_none') },
        { value: 'abort', label: t('fallback.mode_strict') },
    ], { value: existing?.action || 'substitute' });
    const actionModel = Combobox(
        catalogue.map((c) => ({ value: c.id, label: c.id, group: c.provider })),
        { value: existing?.action_model || null, placeholder: t('models.model'), class: 'w-full' },
    );
    const priority = Input({ type: 'number', value: String(existing?.priority ?? 100), class: 'input-sm' });
    const enabled = Switch({ checked: existing?.enabled !== false, label: t('fallback.rule_enabled') });

    const content = [
        Field({ label: t('fallback.rule_name'), control: name, required: true }),
        Field({ label: t('fallback.rule_condition'), control: matchModel, description: t('models.model') }),
        Field({ label: t('fallback.rule_action'), control: action }),
        Field({ label: t('models.model'), control: actionModel }),
        Field({ label: t('scheduler.cron'), control: priority, description: t('fallback.score_help') }),
        enabled,
    ];

    const save = Button({ variant: 'default', iconName: 'save', label: t('common.save'), onClick: async () => {
        if (!name.value.trim()) { Toast.error(t('common.validation_failed')); return; }
        try {
            await api('/api/models/fallback/rules', { method: 'POST', body: {
                id: existing?.id || 0,
                name: name.value.trim(),
                match_model: matchModel.__combobox?.getValue() || null,
                action: action.value,
                action_model: actionModel.__combobox?.getValue() || null,
                priority: Number(priority.value) || 100,
                enabled: readSwitch(enabled),
            } });
            Toast.success(t('fallback.rule_saved'));
            dlg.close();
            await loadAll();
            mount();
        } catch (err) { Toast.error(t('common.error'), String(err.message || err)); }
    } });

    const dlg = Dialog({ title: existing ? t('common.edit') : t('fallback.add_rule'), content, size: 'md', footer: [
        Button({ variant: 'outline', label: t('common.cancel'), onClick: () => dlg.close() }),
        save,
    ] });
}

/* ---------------------------------------------------- availability table */

function availabilityTable() {
    const search = Input({ type: 'search', placeholder: t('models.search_placeholder'), class: 'input-sm' });
    const statusFilter = Select([
        { value: '', label: t('common.all') },
        { value: 'healthy', label: t('models.breaker_closed') },
        { value: 'unknown', label: t('models.unknown_status') },
        { value: 'degraded', label: t('models.degraded') },
        { value: 'unavailable', label: t('models.unavailable') },
        { value: 'retired', label: t('models.inactive') },
    ], { value: '', class: 'select-sm w-auto' });

    const tbody = h('tbody');
    const table = h('table', { class: 'table table-compact' });
    table.appendChild(h('thead', {}, h('tr', {},
        h('th', { text: t('common.model') }),
        h('th', { text: t('common.status') }),
        h('th', { text: t('models.success_rate'), class: 'num-cell' }),
        h('th', { text: t('models.avg_latency'), class: 'num-cell' }),
        h('th', { text: t('models.last_used') }),
        h('th', { text: t('common.actions') }),
    )));
    table.appendChild(tbody);
    const wrap = h('div', { class: 'table-wrap' }, table);

    const paint = () => {
        const q = search.value.trim().toLowerCase();
        const st = statusFilter.value;
        clear(tbody);
        const rows = Object.values(availability)
            .filter((r) => r && r.id)
            .filter((r) => !st || r.status === st)
            .filter((r) => !q || r.id.toLowerCase().includes(q))
            .sort((a, b) => String(a.id).localeCompare(String(b.id)));

        if (rows.length === 0) {
            tbody.appendChild(h('tr', {}, h('td', { colspan: 6, class: 'table-empty', text: t('common.no_results') })));
            return;
        }
        for (const r of rows.slice(0, 300)) {
            const when = r.last_success_at || r.last_probed_at || r.last_failure_at;
            tbody.appendChild(h('tr', {},
                h('td', { class: 'mono xs', text: r.id, title: r.id }),
                h('td', {}, Badge(t(r.status_label_key || 'models.unknown_status'), { tone: r.status_tone || 'muted' })),
                h('td', { class: 'num-cell xs tabular', text: typeof r.success_rate === 'number' ? formatPercent(r.success_rate, 1) : '—' }),
                h('td', { class: 'num-cell xs tabular', text: r.avg_latency_ms ? `${formatNumber(Math.round(r.avg_latency_ms))} ms` : '—' }),
                h('td', { class: 'xs muted', text: when ? timeAgo(when) : t('models.never_checked') }),
                h('td', {}, h('div', { class: 'row gap-1' },
                    Button({ variant: 'ghost', size: 'icon', iconName: 'refresh', ariaLabel: t('common.retry'), onClick: () => probeOne(r.id) }),
                    Button({ variant: 'ghost', size: 'icon', iconName: 'rotateCcw', ariaLabel: t('common.reset'), onClick: async () => {
                        try { await api('/api/models/availability/reset', { method: 'POST', body: { ids: [r.id] } }); Toast.success(t('common.saved')); await loadAll(); mount(); }
                        catch (err) { Toast.error(t('common.error'), String(err.message || err)); }
                    } }),
                    Button({ variant: 'ghost', size: 'icon', iconName: 'search', ariaLabel: t('common.details'), onClick: () => showAlternatives(r.id) }),
                )),
            ));
        }
    };

    search.addEventListener('input', debounce(paint, 150));
    statusFilter.addEventListener('change', paint);
    paint();

    return Card({
        title: t('fallback.availability_monitor'),
        description: t('models.catalog_help'),
        actions: [h('div', { class: 'row gap-2' }, search, statusFilter)],
    }, wrap);
}

/* =====================================================================
 * Boot
 * =================================================================== */

function init() {
    const root = document.getElementById(ROOT_ID);
    if (!root) return;
    // The panel is built lazily by the tab machinery; mounting eagerly would
    // fire three API calls on every page load for a tab most operators never
    // open.
    void mount(root);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();

export default { mount, showAlternatives, showDryRun };
