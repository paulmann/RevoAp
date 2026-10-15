// tests/harness/prompt-cache-ui.mjs — headless checks for the prompt-cache
// surface: the Settings card (C5) and the statistics renderers (C4).
//
// Run through `php tests/PromptCacheUiTest.php` (which supplies node), or
// directly:
//
//   node --experimental-vm-modules tests/harness/prompt-cache-ui.mjs
//
// Two layers, matching the other harnesses in this directory:
//
//   STATIC      — the shipped files, read as text: the Settings card is
//                 registered and rendered, its save goes to the documented
//                 route, the renderers import the shared formatter, and no
//                 new file reaches for innerHTML.
//   BEHAVIOURAL — the REAL assets/js/prompt-cache.js is loaded through
//                 vm.SourceTextModule (it is DOM-free by design, which is
//                 why the renderers share it) and every state the operator
//                 can be shown is asserted: a cache hit, a reported miss, a
//                 negative discount, a run in which NO provider reported
//                 anything, and a run-level roll-up.
//
// The last group is the point of the whole feature's reporting half: "0
// tokens read" and "no cache data" must never render the same way.

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');

let failures = 0;
let passes = 0;

function check(label, cond, detail = '') {
    if (cond) {
        console.log(`  ok  ${label}`);
        passes++;
    } else {
        console.log(`  FAIL ${label}${detail ? ` — ${detail}` : ''}`);
        failures++;
    }
}

function read(rel) {
    const path = resolve(root, rel);
    return existsSync(path) ? readFileSync(path, 'utf8') : '';
}

const settingsJs = read('assets/js/settings.js');
const pipelineJs = read('assets/js/pipeline.js');
const historyJs  = read('assets/js/history.js');
const statsJs    = read('assets/js/stats.js');
const appJs      = read('assets/js/app.js');
const indexPhp   = read('index.php');

// =====================================================================
console.log('== the Settings card is shipped and wired (C5) ==');
// =====================================================================
check('settings.js builds the prompt-caching card', settingsJs.includes('function sectionPromptCaching()'));
check('the card carries its own id', settingsJs.includes("'oc-prompt-cache-card'"));
check('the card is mounted by renderSettings()', settingsJs.includes('sectionPromptCaching(),'));
check('the section strip knows the card',
    settingsJs.includes("id: 'oc-prompt-cache-card'"));
check('the save posts to the documented route',
    settingsJs.includes("api('/api/settings/prompt-cache'"));
check('the card renders the capability table from the server, not a hard-coded copy',
    settingsJs.includes('runtime.prompt_cache') && settingsJs.includes('block.capabilities'));
check('the card explains that an unknown family promises nothing',
    settingsJs.includes('no breakpoint is added and no saving is'));
check('the card states the breakpoint placement rule',
    settingsJs.includes('last stable part of the prompt prefix'));
check('the card states that the size guard is never lowered',
    settingsJs.includes('never lowered'));
check('settings.js still never uses innerHTML',
    !/\.innerHTML\s*=|\.insertAdjacentHTML\s*\(/.test(settingsJs));

check('index.php routes POST /api/settings/prompt-cache',
    indexPhp.includes("'/api/settings/prompt-cache'") && indexPhp.includes('savePromptCache'));

const settingsCtrl = read('src/Http/Controllers/SettingsController.php');
check('SettingsController exposes the resolved policy and the table',
    settingsCtrl.includes('Policy::fromConfig()->toArray()')
    && settingsCtrl.includes('Capabilities::documentedArray()'));
check('the save endpoint refuses an undocumented TTL',
    settingsCtrl.includes('Policy::TTL_FIVE_MINUTES, Policy::TTL_ONE_HOUR'));
check('the save endpoint clamps the breakpoint count to the documented ceiling',
    settingsCtrl.includes('Policy::MAX_BREAKPOINTS'));

// =====================================================================
console.log('\n== the statistics surfaces consume the shared formatter (C4) ==');
// =====================================================================
check('a shared formatter module exists', read('assets/js/prompt-cache.js') !== '');
check('pipeline.js imports it for the per-step card',
    pipelineJs.includes("from './prompt-cache.js'") && pipelineJs.includes('stepCacheRows(meta)'));
check('history.js imports it for the run header and per-stage rows',
    historyJs.includes('runCacheSummary(s.cache)') && historyJs.includes('responseCacheSummary(r)'));
check('history.js renders per-stage cache figures only when reported',
    historyJs.includes('stepCacheStats(step.meta).reported'));
check('stats.js renders the run roll-up', statsJs.includes('runCacheRows(stats)'));
check('stats.js shows the saving column', statsJs.includes('fmtDiscount(u.cache_discount)'));
check('the SSE stats allow-list carries the new cache keys',
    pipelineJs.includes("'cache_write_tokens', 'cache_discount', 'cache_stats'"));
check('app.js normalises the new keys instead of dropping them',
    appJs.includes('cache_write_tokens: meta?.cache_write_tokens')
    && appJs.includes('cache_discount:     meta?.cache_discount'));
check('no statistics surface uses innerHTML',
    !/\.innerHTML\s*=|\.insertAdjacentHTML\s*\(/.test(pipelineJs + historyJs + statsJs));

// =====================================================================
console.log('\n== behavioural: the real formatter, every state it can show ==');
// =====================================================================
const formatterPath = resolve(root, 'assets/js/prompt-cache.js');
const formatterMod = new vm.SourceTextModule(readFileSync(formatterPath, 'utf8'), {
    identifier: formatterPath,
    context: vm.createContext(globalThis),
});
await formatterMod.link(() => { throw new Error('prompt-cache.js must stay dependency-free'); });
await formatterMod.evaluate();
const pc = formatterMod.namespace;

function rowMap(rows) {
    const out = {};
    for (const [k, v] of rows) { out[k] = v; }
    return out;
}

{
    // A provider that returned a cache block, with a hit.
    const rows = rowMap(pc.stepCacheRows({
        provider: 'bothub', prompt_tokens: 900, total_tokens: 950,
        cached_tokens: 2048, cache_write_tokens: 0, cache_discount: 0.00123,
    }));
    check('a reported hit renders the read side', rows['Cache read tokens'] === '2048', JSON.stringify(rows));
    check('a reported hit renders the write side', rows['Cache write tokens'] === '0', JSON.stringify(rows));
    check('a reported hit renders the saving',
        typeof rows['Cache savings (USD)'] === 'string' && rows['Cache savings (USD)'].includes('0.001230'),
        String(rows['Cache savings (USD)']));
    check('a reported hit is NOT labelled as "no data"',
        !Object.values(rows).some((v) => String(v).includes('No cache data')));
}

{
    // The same call, but the provider said nothing at all about the cache.
    const rows = rowMap(pc.stepCacheRows({ provider: 'bothub', prompt_tokens: 900, total_tokens: 950 }));
    check('an unreported cache renders the explicit "no data" sentence',
        rows['Prompt cache'] === pc.NO_CACHE_DATA, JSON.stringify(rows));
    check('the "no data" sentence is the required one, both halves present',
        rows['Prompt cache'].includes('No cache data') && rows['Prompt cache'].includes('Провайдер не вернул данные о кеше'),
        rows['Prompt cache']);
    check('an unreported cache shows no zero that would read as a miss',
        !Object.values(rows).some((v) => String(v) === '0' || String(v).startsWith('0 ')), JSON.stringify(rows));
}

{
    // A reported ZERO read is a real cache miss, and must read as one.
    const rows = rowMap(pc.stepCacheRows({
        provider: 'bothub', prompt_tokens: 900, total_tokens: 950,
        cached_tokens: 0, cache_discount: 0,
    }));
    check('a reported zero read is labelled a reported non-hit',
        rows['Cache read tokens'] === '0 (reported, no hit)', JSON.stringify(rows));
    check('a reported zero is not confused with "no data"',
        !Object.values(rows).some((v) => String(v).includes('No cache data')));
}

{
    // The Anthropic case the documentation calls out: a WRITE costs 1.25x,
    // so the discount on that call is negative and must not lose its sign.
    const rows = rowMap(pc.stepCacheRows({
        provider: 'bothub', prompt_tokens: 900, total_tokens: 950,
        cached_tokens: 0, cache_write_tokens: 4096, cache_discount: -0.0004,
    }));
    check('a negative saving keeps its minus sign',
        String(rows['Cache savings (USD)']).startsWith('−'), String(rows['Cache savings (USD)']));
    check('a negative saving is not rendered as a positive amount',
        !String(rows['Cache savings (USD)']).startsWith('$'), String(rows['Cache savings (USD)']));
}

{
    // A non-LLM stage (L2/L3) has nothing to say about the prompt cache —
    // not "no data", but "not applicable".
    const rows = pc.stepCacheRows({ status: 'completed', summary: 'normalized' });
    check('a stage that made no provider call renders no cache rows at all', rows.length === 0, JSON.stringify(rows));
}

{
    const rows = rowMap(pc.runCacheRows({
        total_calls: 4, cache_calls: 2, cache_reported_calls: 3,
        cache_read_tokens: 8192, cache_write_tokens: 1024,
        cache_discount: 0.005, cache_discount_available: true,
    }));
    check('the run roll-up counts the calls that hit, under a label that cannot be confused with the local response cache',
        rows['Prompt cache hits'] === '2 / 4 calls', JSON.stringify(rows));
    check('the run roll-up sums the read side', rows['Cache read tokens'] === '8192', JSON.stringify(rows));
    check('the run roll-up sums the write side', rows['Cache write tokens'] === '1024', JSON.stringify(rows));
    check('the run roll-up shows the saving',
        String(rows['Cache savings (USD)']).includes('0.005000'), String(rows['Cache savings (USD)']));
}

{
    // A run whose providers returned no cache statistics. This is the state
    // the requirement calls out: it must not be dressed up as "0 hits".
    const stats = { total_calls: 4, cache_calls: 0, cache_reported_calls: 0,
                    cache_read_tokens: 0, cache_write_tokens: 0,
                    cache_discount: null, cache_discount_available: false };
    const rows = rowMap(pc.runCacheRows(stats));
    check('a run with no reported cache data says so',
        rows['Prompt cache'] === pc.NO_CACHE_DATA, JSON.stringify(rows));
    check('such a run shows no "0 / 0 calls" row',
        !Object.values(rows).some((v) => String(v).startsWith('0 /')), JSON.stringify(rows));
    check('the one-line summary for that run says so too',
        pc.runCacheSummary(stats).includes('No cache data'), pc.runCacheSummary(stats));
    check('the summary never reports a saving it was not given',
        !pc.runCacheSummary(stats).includes('saved'), pc.runCacheSummary(stats));
}

{
    const summary = pc.runCacheSummary({
        total_calls: 4, cache_calls: 2, cache_reported_calls: 3,
        cache_read_tokens: 8192, cache_write_tokens: 0,
        cache_discount: null, cache_discount_available: false,
    });
    check('the one-line summary counts hits over calls', summary.includes('2/4 calls'), summary);
    check('the one-line summary reports read tokens', summary.includes('read 8192'), summary);
    check('the one-line summary omits a write of zero rather than showing it', !summary.includes('written'), summary);
    check('the one-line summary omits a saving that was not returned', !summary.includes('saved'), summary);
}

{
    // Per stored response (History per-stage rows). Silence must be an
    // empty string — the caller appends it to an existing line, and an
    // empty append is what keeps an old row byte-identical.
    check('a response with no cache columns adds nothing',
        pc.responseCacheSummary({ cache_read_tokens: null, cache_write_tokens: null, cache_discount: null }) === '');
    check('a response missing the columns entirely adds nothing',
        pc.responseCacheSummary({ prompt_tokens: 10, total_tokens: 20 }) === '');
    const text = pc.responseCacheSummary({ cache_read_tokens: 512, cache_write_tokens: 0, cache_discount: 0.0002 });
    check('a response with cache columns is described', text.includes('prompt cache: read 512'), text);
    check('a zero write is not printed', !text.includes('written'), text);
    check('its saving is printed', text.includes('saved'), text);
}

{
    // Both dialects land on one read number — the guard against
    // double-counting the same tokens when a response carries both shapes.
    const openaiShaped = pc.stepCacheStats({ cached_tokens: 1024 });
    const anthropicShaped = pc.stepCacheStats({ cached_tokens: 1024, cache_write_tokens: 512 });
    check('the OpenAI-like dialect reports a read', openaiShaped.read === 1024 && openaiShaped.hit === true);
    check('the Anthropic-like dialect reuses the same read key',
        anthropicShaped.read === 1024 && anthropicShaped.write === 512);
    check('a payload flagged cache_stats is reported even with no numbers',
        pc.stepCacheStats({ cache_stats: true }).reported === true);
}

console.log('\n=========================');
console.log(`Passes: ${passes}  Failures: ${failures}`);
process.exit(failures === 0 ? 0 : 1);
