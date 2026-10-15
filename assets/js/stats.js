// stats.js — final session-level stats panel.
//
// Renders three things stacked at the bottom of every council session:
//   1. A compact key/value summary (totals + most efficient model + cost).
//   2. A compact per-model usage table — the original Tokens/Calls/Latency
//      view for at-a-glance scanning.
//   3. A wide "Detailed per-LLM breakdown" table covering provider,
//      prompt/completion/total/cached/reasoning tokens, calls, total and
//      average latency, cost, and an `est` marker for runs whose token
//      counts came from the local estimator (no usage block).
//
// All values are added via createElement + textContent — never innerHTML —
// so model names returned by providers cannot inject markup.

import { fmtDiscount, runCacheRows } from './prompt-cache.js';

function fmtMs(ms) {
    if (ms == null) return '—';
    if (ms < 1000) return Math.round(ms) + 'ms';
    if (ms < 60_000) return (ms / 1000).toFixed(1) + 's';
    const m = Math.floor(ms / 60_000);
    const s = Math.round((ms - m * 60_000) / 1000);
    return `${m}m${String(s).padStart(2, '0')}s`;
}

function fmtCost(cost, available) {
    if (!available || cost == null || !Number.isFinite(Number(cost))) return '—';
    const n = Number(cost);
    if (n === 0) return '$0';
    if (n < 0.000001) return '$' + n.toExponential(2);
    return '$' + n.toFixed(6);
}

function fmtInt(v) {
    if (v == null || v === '' || Number.isNaN(Number(v))) return '—';
    return String(Math.round(Number(v)));
}

function cell(text) {
    const td = document.createElement('td');
    td.textContent = text == null ? '—' : String(text);
    return td;
}

function headRow(cells) {
    const tr = document.createElement('tr');
    for (const c of cells) {
        const th = document.createElement('th');
        th.textContent = c;
        tr.append(th);
    }
    return tr;
}

export function renderSessionStats(stats) {
    const target = document.getElementById('stats-section');
    if (!target) return;
    target.hidden = false;
    target.replaceChildren();

    const wrap = document.createElement('section');
    wrap.className = 'oc-stats-summary';
    const h = document.createElement('header');
    h.append(Object.assign(document.createElement('h2'), { textContent: 'Session Statistics' }));
    wrap.append(h);

    // Top-level summary list.
    const meta = document.createElement('dl');
    meta.className = 'oc-stats-meta';
    const rows = [
        ['Total tokens',    String(stats.total_tokens ?? 0)],
        ['Duration',        fmtMs(stats.total_latency_ms ?? 0)],
        ['Roles',           String(stats.role_count ?? 0)],
        ['Most efficient',  String(stats.most_efficient_model ?? '—')],
        ['Total cost',      fmtCost(stats.total_cost, !!stats.cost_available)],
    ];
    // Prompt cache roll-up for the run: calls that hit, tokens read and
    // written, and the saving. A run in which no provider returned cache
    // statistics says so, instead of showing three zeroes that read as
    // "the cache never fired".
    for (const cacheRow of runCacheRows(stats)) rows.push(cacheRow);
    for (const [k, v] of rows) {
        const div = document.createElement('div');
        const dt = document.createElement('dt'); dt.textContent = k;
        const dd = document.createElement('dd'); dd.textContent = v;
        div.append(dt, dd); meta.append(div);
    }
    wrap.append(meta);

    // Compact table (kept for at-a-glance scanning).
    const compact = document.createElement('table');
    compact.className = 'oc-stats-table';
    const thead = document.createElement('thead');
    thead.append(headRow(['Model', 'Provider', 'Tokens', 'Calls', 'Latency', 'Cost']));
    compact.append(thead);
    const tbody = document.createElement('tbody');
    const perModel = stats.per_model || {};
    const entries = Object.entries(perModel);
    if (entries.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 6;
        td.textContent = 'No model usage was recorded for this session.';
        td.className = 'oc-stats-empty';
        tr.append(td);
        tbody.append(tr);
    } else {
        for (const [, u] of entries) {
            const tr = document.createElement('tr');
            tr.append(
                cell(u.model),
                cell(u.provider),
                cell(u.total_tokens),
                cell(u.calls),
                cell(fmtMs(u.latency_ms)),
                cell(fmtCost(u.cost, !!u.cost_available)),
            );
            tbody.append(tr);
        }
    }
    compact.append(tbody);
    wrap.append(compact);

    // Detailed per-LLM breakdown — explicit columns for every figure the
    // provider returned (or the estimator filled in).
    const detailHeader = document.createElement('header');
    detailHeader.className = 'oc-stats-subheader';
    detailHeader.append(Object.assign(document.createElement('h3'), {
        textContent: 'Detailed per-LLM breakdown',
    }));
    const note = document.createElement('p');
    note.className = 'oc-stats-note';
    note.textContent = 'One row per (model × provider). "est" marks rows whose token counts were '
        + 'estimated locally because the provider did not return a usage block. Cost is shown only '
        + 'when the provider reported it. "Cached" is the read side of the prompt cache in either '
        + 'dialect, "Written" the write side (Anthropic-like providers only) and "Saved" Bothub\'s '
        + 'own cache_discount — which is negative on a write for a vendor that charges more to write '
        + 'than to read. A dash in those three columns means the provider returned no cache data.';
    detailHeader.append(note);
    wrap.append(detailHeader);

    const detail = document.createElement('table');
    detail.className = 'oc-stats-table oc-stats-table-detailed';
    const dthead = document.createElement('thead');
    dthead.append(headRow([
        'Model', 'Provider', 'Calls',
        'Prompt', 'Completion', 'Total',
        'Cached', 'Written', 'Reasoning',
        'Total latency', 'Avg latency', 'Cost', 'Saved', 'Est?',
    ]));
    detail.append(dthead);
    const dtbody = document.createElement('tbody');
    if (entries.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 14;
        td.className = 'oc-stats-empty';
        td.textContent = 'No model usage was recorded for this session.';
        tr.append(td);
        dtbody.append(tr);
    } else {
        for (const [, u] of entries) {
            const tr = document.createElement('tr');
            tr.append(
                cell(u.model),
                cell(u.provider),
                cell(fmtInt(u.calls)),
                cell(fmtInt(u.prompt_tokens)),
                cell(fmtInt(u.completion_tokens)),
                cell(fmtInt(u.total_tokens)),
                cell(fmtInt(u.cached_tokens)),
                // Cache write side: 0 is a legitimate reported value for the
                // automatic families (they never report a write), so the
                // column shows the number whenever the provider spoke about
                // the cache at all and a dash when it said nothing.
                cell(u.cache_reported_calls > 0 ? fmtInt(u.cache_write_tokens) : '—'),
                cell(fmtInt(u.reasoning_tokens)),
                cell(fmtMs(u.latency_ms)),
                cell(fmtMs(u.avg_latency_ms ?? (u.calls > 0 ? u.latency_ms / u.calls : null))),
                cell(fmtCost(u.cost, !!u.cost_available)),
                cell(u.cache_discount_available ? fmtDiscount(u.cache_discount) : '—'),
                cell(u.tokens_estimated ? 'est' : '—'),
            );
            dtbody.append(tr);
        }
        // Totals footer
        const tfoot = document.createElement('tfoot');
        const totalRow = document.createElement('tr');
        let sumCalls = 0, sumPrompt = 0, sumCompl = 0, sumTotal = 0, sumCached = 0;
        let sumWritten = 0, sumReasoning = 0, sumLat = 0, sumCost = 0, sumSaved = 0;
        let costSeen = false, cacheWriteReported = false, savedSeen = false;
        for (const [, u] of entries) {
            sumCalls     += Number(u.calls || 0);
            sumPrompt    += Number(u.prompt_tokens || 0);
            sumCompl     += Number(u.completion_tokens || 0);
            sumTotal     += Number(u.total_tokens || 0);
            sumCached    += Number(u.cached_tokens || 0);
            if (Number(u.cache_reported_calls || 0) > 0) {
                cacheWriteReported = true;
                sumWritten += Number(u.cache_write_tokens || 0);
            }
            if (u.cache_discount_available) {
                savedSeen = true;
                sumSaved += Number(u.cache_discount || 0);
            }
            sumReasoning += Number(u.reasoning_tokens || 0);
            sumLat       += Number(u.latency_ms || 0);
            if (u.cost_available && u.cost != null) {
                costSeen = true;
                sumCost += Number(u.cost);
            }
        }
        const avgLat = sumCalls > 0 ? sumLat / sumCalls : null;
        totalRow.append(
            cell('Σ'),
            cell(''),
            cell(fmtInt(sumCalls)),
            cell(fmtInt(sumPrompt)),
            cell(fmtInt(sumCompl)),
            cell(fmtInt(sumTotal)),
            cell(fmtInt(sumCached)),
            cell(cacheWriteReported ? fmtInt(sumWritten) : '—'),
            cell(fmtInt(sumReasoning)),
            cell(fmtMs(sumLat)),
            cell(fmtMs(avgLat)),
            cell(fmtCost(sumCost, costSeen)),
            cell(savedSeen ? fmtDiscount(sumSaved) : '—'),
            cell(''),
        );
        tfoot.append(totalRow);
        detail.append(dtbody, tfoot);
    }
    if (entries.length === 0) detail.append(dtbody);
    wrap.append(detail);

    target.append(wrap);
}
