// prompt-cache.js — shared prompt-cache statistics formatting.
//
// Every surface that shows token statistics (the Chat per-step card, the
// Chat session panel, the History run header and per-stage rows, the
// Statistics tab) has to answer the same three questions about the prompt
// cache the same way:
//
//   how many calls hit it, how many tokens were read and written, and how
//   much was saved.
//
// and, just as importantly, has to answer "nothing" honestly. A provider
// that returns no cache block at all is NOT a cache miss: the distinction
// between "the cache did not fire" and "the provider returned no cache
// data" is the whole reason this module exists instead of four copies of
// the same three lines. `null`/absent means unreported, `0` means a
// reported zero, and only the first is rendered as "no data".
//
// All formatting is textContent-safe: the module returns strings and
// arrays, never DOM built from provider-supplied text.
//
// Consumed by: pipeline.js (per-step card), stats.js (session panel),
// history.js (run header + per-stage rows).

/**
 * Text used whenever a provider returned no cache statistics at all.
 *
 * Bilingual on purpose, like the other "you are reading a limitation"
 * strings in this UI (see the consensus disclaimer in PipelineEngine):
 * the sentence has to be unambiguous to the operator, and the Russian half
 * is the wording the feature was specified with.
 */
export const NO_CACHE_DATA = 'No cache data from provider / Провайдер не вернул данные о кеше';

function isNum(v) {
    return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
}

function fmtInt(v) {
    return isNum(v) ? String(Math.round(Number(v))) : '—';
}

/**
 * Signed money. `cache_discount` is negative on a cache write for a vendor
 * that charges 1.25x to write against 0.1x to read, so the sign is part of
 * the value and must survive formatting — "−$0.000400" is a cost, not a
 * saving, and hiding the minus would invert the meaning.
 */
export function fmtDiscount(v) {
    if (!isNum(v)) return '—';
    const n = Number(v);
    if (n === 0) return '$0';
    const sign = n < 0 ? '−' : '';
    const abs = Math.abs(n);
    if (abs < 0.000001) return sign + '$' + abs.toExponential(2);
    return sign + '$' + abs.toFixed(6);
}

/**
 * Cache figures for ONE model call, read from a step-meta / SSE payload.
 *
 * Recognizes both dialects already normalized by the connector: the read
 * side lives under `cached_tokens` whichever dialect reported it
 * (OpenAI-like `prompt_tokens_details.cached_tokens` or Anthropic-like
 * `cache_read_input_tokens`), the write side under `cache_write_tokens`.
 *
 * `reported` is false only when none of the three fields is present.
 */
export function stepCacheStats(meta) {
    const m = meta || {};
    const read = isNum(m.cached_tokens) ? Number(m.cached_tokens) : null;
    const write = isNum(m.cache_write_tokens) ? Number(m.cache_write_tokens) : null;
    const discount = isNum(m.cache_discount) ? Number(m.cache_discount) : null;
    return {
        reported: m.cache_stats === true || read !== null || write !== null || discount !== null,
        read,
        write,
        discount,
        hit: read !== null && read > 0,
    };
}

/** Cache figures for a whole RUN, read from SessionStats::toArray(). */
export function runCacheStats(stats) {
    const s = stats || {};
    const read = isNum(s.cache_read_tokens) ? Number(s.cache_read_tokens) : 0;
    const write = isNum(s.cache_write_tokens) ? Number(s.cache_write_tokens) : 0;
    const calls = isNum(s.cache_calls) ? Number(s.cache_calls) : 0;
    const reportedCalls = isNum(s.cache_reported_calls) ? Number(s.cache_reported_calls) : 0;
    const totalCalls = isNum(s.total_calls) ? Number(s.total_calls) : 0;
    return {
        reported: reportedCalls > 0 || calls > 0 || read > 0 || write > 0,
        read,
        write,
        calls,
        reportedCalls,
        totalCalls,
        discount: isNum(s.cache_discount) ? Number(s.cache_discount) : null,
        discountAvailable: s.cache_discount_available === true,
    };
}

/**
 * The cache block for a per-step `<dl>`: at most four rows, or a single
 * "no data" row. Returns [] for a step that made no provider call at all
 * (L2 normalization, L3 consensus) — there the prompt cache is not
 * "unreported", it is not applicable, and a row saying so would be noise.
 */
export function stepCacheRows(meta) {
    const m = meta || {};
    if (m.provider == null && m.prompt_tokens == null && m.total_tokens == null) return [];
    const c = stepCacheStats(m);
    if (!c.reported) return [['Prompt cache', NO_CACHE_DATA]];
    const rows = [];
    rows.push(['Cache read tokens', c.hit ? fmtInt(c.read) : '0 (reported, no hit)']);
    if (c.write !== null) rows.push(['Cache write tokens', fmtInt(c.write)]);
    if (c.discount !== null) rows.push(['Cache savings (USD)', fmtDiscount(c.discount)]);
    return rows;
}

/**
 * The cache block for the session panel: how many calls hit the cache, how
 * many tokens were read and written, and the saving.
 *
 * `reportedCalls` is shown as a denominator on purpose — "0 of 12 calls"
 * and "0 of 0 calls (no provider reported)" are different statements, and
 * only the second one means the accounting itself is missing.
 */
export function runCacheRows(stats) {
    const c = runCacheStats(stats);
    if (!c.reported) return [[ 'Prompt cache', NO_CACHE_DATA ]];
    const rows = [
        // "Prompt cache hits" and not "Cache hits": the Cache card and the
        // Statistics tab already use `cache_hit` for the LOCAL response
        // cache, which is a different mechanism (it avoids the network call
        // altogether). Two numbers called "cache hits" in one product would
        // be read as the same number.
        ['Prompt cache hits', `${fmtInt(c.calls)} / ${fmtInt(c.totalCalls || c.reportedCalls)} calls`],
        ['Cache read tokens', fmtInt(c.read)],
        ['Cache write tokens', fmtInt(c.write)],
    ];
    if (c.discountAvailable) rows.push(['Cache savings (USD)', fmtDiscount(c.discount)]);
    else rows.push(['Cache savings (USD)', '— (cache_discount not returned)']);
    return rows;
}

/** One-line form for a run header strip (never throws "0 = miss"). */
export function runCacheSummary(stats) {
    const c = runCacheStats(stats);
    if (!c.reported) return 'prompt cache: ' + NO_CACHE_DATA;
    const parts = [`prompt cache: ${c.calls}/${c.totalCalls || c.reportedCalls} calls`];
    parts.push(`read ${fmtInt(c.read)}`);
    if (c.write > 0) parts.push(`written ${fmtInt(c.write)}`);
    if (c.discountAvailable) parts.push(`saved ${fmtDiscount(c.discount)}`);
    return parts.join(' · ');
}

/** One-line form for a single stored model response (History per-stage). */
export function responseCacheSummary(row) {
    const r = row || {};
    const read = isNum(r.cache_read_tokens) ? Number(r.cache_read_tokens) : null;
    const write = isNum(r.cache_write_tokens) ? Number(r.cache_write_tokens) : null;
    const discount = isNum(r.cache_discount) ? Number(r.cache_discount) : null;
    if (read === null && write === null && discount === null) return '';
    const parts = [];
    if (read !== null) parts.push(`prompt cache: read ${fmtInt(read)}`);
    if (write !== null && write > 0) parts.push(`written ${fmtInt(write)}`);
    if (discount !== null) parts.push(`saved ${fmtDiscount(discount)}`);
    return parts.join(' · ');
}
