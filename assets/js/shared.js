// shared.js — hydrates the read-only shared session page from the
// JSON snapshot embedded in <script id="oc-shared-snapshot">. No model
// controls, no settings, no admin actions — display only.
//
// v1.8.33:
//   - Single-column layout: this module no longer touches column
//     classes; layout is dictated entirely by shared.css.
//   - Renders the new statistics card (snapshot.stats) between the
//     final answer and the consensus history.
//   - Each step renders its per-model responses so KaTeX can render
//     math identically to the live chat surface.

function readSnapshot() {
    const tag = document.getElementById('oc-shared-snapshot');
    if (!tag) return null;
    try {
        return JSON.parse(tag.textContent || 'null');
    } catch (e) {
        console.error('shared snapshot parse failed', e);
        return null;
    }
}

function el(tag, attrs = {}, children = []) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null) continue;
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else n.setAttribute(k, String(v));
    }
    for (const c of [].concat(children)) {
        if (c == null) continue;
        n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return n;
}

function renderError(snapshot) {
    const root = document.querySelector('.oc-shared-main');
    if (!root) return;
    const box = el('section', { class: 'oc-shared-error' }, [
        el('h2', { text: snapshot.error || 'Share link unavailable' }),
        el('p', { text: snapshot.detail || '' }),
    ]);
    root.prepend(box);
}

function fmtInt(n) {
    if (n == null || !Number.isFinite(Number(n))) return '—';
    try { return new Intl.NumberFormat().format(Number(n)); } catch { return String(n); }
}

function fmtDuration(ms) {
    const n = Number(ms);
    if (!Number.isFinite(n) || n <= 0) return '—';
    if (n < 1000) return `${Math.round(n)} ms`;
    const s = n / 1000;
    if (s < 60) return `${s.toFixed(2)} s`;
    const m = Math.floor(s / 60);
    const r = Math.round(s - m * 60);
    return `${m}m ${r}s`;
}

function fmtCost(n) {
    if (n == null || !Number.isFinite(Number(n)) || Number(n) <= 0) return '—';
    const v = Number(n);
    if (v < 0.01) return `$${v.toFixed(4)}`;
    return `$${v.toFixed(3)}`;
}

function fmtScore(n) {
    if (n == null || !Number.isFinite(Number(n))) return '—';
    return Number(n).toFixed(3);
}

function renderStat(label, value, title) {
    const item = el('div', { class: 'oc-shared-stat' });
    item.appendChild(el('dt', { class: 'oc-shared-stat-label', text: label }));
    const dd = el('dd', { class: 'oc-shared-stat-value' });
    if (title) dd.title = String(title);
    dd.textContent = value == null || value === '' ? '—' : String(value);
    item.appendChild(dd);
    return item;
}

function renderStats(stats) {
    const grid = document.getElementById('oc-shared-stats-grid');
    const modelsBox = document.getElementById('oc-shared-stats-models');
    if (!grid) return;
    grid.replaceChildren();
    const s = stats || {};

    grid.appendChild(renderStat('Models', fmtInt(s.model_count), 'Number of distinct LLMs that contributed at least one response'));
    grid.appendChild(renderStat('Duration', fmtDuration(s.duration_ms), 'Total wall-clock time from first step to completion'));
    grid.appendChild(renderStat('Total tokens', fmtInt(s.total_tokens), 'Sum of prompt + completion tokens across all model calls'));
    grid.appendChild(renderStat('Prompt tokens', fmtInt(s.prompt_tokens)));
    grid.appendChild(renderStat('Completion tokens', fmtInt(s.completion_tokens)));
    grid.appendChild(renderStat('Estimated cost', fmtCost(s.estimated_cost_usd),
        'Best-effort USD estimate using recorded usage. Dash when pricing data was not persisted.'));
    grid.appendChild(renderStat('Consensus status', s.consensus_status || '—'));
    grid.appendChild(renderStat('Consensus score', fmtScore(s.consensus_score)));
    if (s.cache_hits != null) {
        grid.appendChild(renderStat('Cache hits', fmtInt(s.cache_hits),
            'Number of model responses served from the recorded prompt-cache layer'));
    }
    grid.appendChild(renderStat('Total steps', fmtInt(s.step_count)));
    grid.appendChild(renderStat('Total responses', fmtInt(s.response_count)));
    if (s.avg_latency_ms != null && Number(s.avg_latency_ms) > 0) {
        grid.appendChild(renderStat('Avg model latency', fmtDuration(s.avg_latency_ms)));
    }

    // Model contribution call-outs.
    if (modelsBox) {
        modelsBox.replaceChildren();
        const callouts = [];
        if (s.top_contributor) {
            callouts.push({ label: 'Most contribution', model: s.top_contributor });
        }
        if (s.weakest_contributor && s.weakest_contributor !== s.top_contributor) {
            callouts.push({ label: 'Least contribution', model: s.weakest_contributor });
        }
        if (s.fastest_model) {
            callouts.push({ label: 'Fastest model', model: s.fastest_model });
        }
        if (s.slowest_model && s.slowest_model !== s.fastest_model) {
            callouts.push({ label: 'Slowest model', model: s.slowest_model });
        }
        if (callouts.length === 0) {
            modelsBox.hidden = true;
        } else {
            modelsBox.hidden = false;
            const ul = el('ul', { class: 'oc-shared-stats-callouts' });
            for (const c of callouts) {
                const li = el('li', { class: 'oc-shared-stats-callout' });
                li.appendChild(el('span', { class: 'oc-shared-stats-callout-label', text: c.label }));
                const m = c.model || {};
                const id = (m.provider ? `${m.provider}/` : '') + (m.model || '');
                const detail = [];
                if (m.responses != null) detail.push(`${fmtInt(m.responses)} call${Number(m.responses) === 1 ? '' : 's'}`);
                if (m.total_tokens != null && Number(m.total_tokens) > 0) detail.push(`${fmtInt(m.total_tokens)} tok`);
                if (m.avg_latency_ms != null && Number(m.avg_latency_ms) > 0) detail.push(fmtDuration(m.avg_latency_ms));
                const value = el('span', { class: 'oc-shared-stats-callout-value' });
                value.appendChild(el('code', { class: 'oc-mono', text: id || '—' }));
                if (detail.length) {
                    value.appendChild(el('span', { class: 'oc-shared-stats-callout-detail oc-muted oc-small', text: ' · ' + detail.join(' · ') }));
                }
                li.appendChild(value);
                ul.appendChild(li);
            }
            modelsBox.appendChild(ul);
        }
    }
}

function renderResponse(resp) {
    const wrap = el('div', { class: 'oc-shared-step-response-row' });
    const head = el('header', { class: 'oc-shared-step-response-head' });
    const ident = (resp.provider ? `${resp.provider}/` : '') + (resp.model || '');
    head.appendChild(el('code', { class: 'oc-mono oc-shared-step-response-id', text: ident }));
    if (resp.role_name) {
        head.appendChild(el('span', { class: 'oc-shared-step-response-role oc-muted oc-small', text: resp.role_name }));
    }
    const meta = [];
    if (resp.total_tokens) meta.push(`${fmtInt(resp.total_tokens)} tok`);
    if (resp.latency_ms) meta.push(fmtDuration(resp.latency_ms));
    if (meta.length) {
        head.appendChild(el('span', { class: 'oc-shared-step-response-meta oc-muted oc-small', text: meta.join(' · ') }));
    }
    wrap.appendChild(head);
    const body = el('div', { class: 'oc-shared-step-response oc-step-response' });
    body.textContent = resp.raw_content || '';
    wrap.appendChild(body);
    return wrap;
}

function renderStep(step) {
    const layer = (step.step_key || '').split('.')[0] || (step.meta && step.meta.layer) || 'L?';
    const wrap = el('article', { class: 'oc-shared-step', 'data-layer': layer });
    const header = el('header', {}, [
        el('span', { class: 'oc-shared-layer', text: layer }),
        el('span', { class: 'oc-shared-label', text: step.label || step.step_key || '' }),
        el('span', { class: 'oc-shared-status', text: step.status || '' }),
    ]);
    wrap.appendChild(header);
    const body = el('div', { class: 'oc-shared-step-body' });
    body.textContent = step.summary || '(no content)';
    wrap.appendChild(body);
    const responses = Array.isArray(step.responses) ? step.responses : [];
    if (responses.length) {
        const respWrap = el('div', { class: 'oc-shared-step-responses' });
        for (const r of responses) respWrap.appendChild(renderResponse(r));
        wrap.appendChild(respWrap);
    }
    if (step.meta && Array.isArray(step.meta.claims) && step.meta.claims.length) {
        const claims = el('ul', { class: 'oc-shared-claims' });
        for (const c of step.meta.claims.slice(0, 10)) {
            claims.appendChild(el('li', { text: typeof c === 'string' ? c : JSON.stringify(c) }));
        }
        wrap.appendChild(claims);
    }
    return wrap;
}

function applyUiPrefs(snapshot) {
    // The theme arrives in the snapshot (also pre-baked into the
    // template attribute). Re-apply here defensively so SSR + client
    // stay in sync if the snapshot ever differs from the static markup.
    // Layout width is no longer a preference — the column follows the
    // window — so there is nothing to re-apply for it.
    const prefs = snapshot && snapshot.ui ? snapshot.ui : {};
    // Theme: respect existing data-theme set by the template; if a
    // default is configured, honour it. The toggle button below lets
    // viewers flip it without persisting (read-only page).
    const theme = prefs.theme && (prefs.theme === 'light' || prefs.theme === 'dark')
        ? prefs.theme
        : document.documentElement.getAttribute('data-theme') || 'dark';
    document.documentElement.setAttribute('data-theme', theme);
}

function wireThemeToggle() {
    const btn = document.getElementById('oc-shared-theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', () => {
        const cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', cur === 'light' ? 'dark' : 'light');
    });
}

function init() {
    const snapshot = readSnapshot();
    if (!snapshot) return;
    applyUiPrefs(snapshot);
    wireThemeToggle();
    if (snapshot.error) { renderError(snapshot); return; }

    const sess = snapshot.session || {};
    if (sess.query_text) {
        const q = document.getElementById('oc-shared-query');
        if (q) q.textContent = sess.query_text;
    }
    if (sess.final_answer) {
        const f = document.getElementById('oc-shared-final');
        if (f) f.textContent = sess.final_answer;
    }
    renderStats(snapshot.stats || {});
    const root = document.getElementById('oc-shared-pipeline');
    if (root) {
        root.replaceChildren();
        const steps = Array.isArray(snapshot.steps) ? snapshot.steps : [];
        steps.sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
        for (const s of steps) {
            root.appendChild(renderStep(s));
        }
        if (steps.length === 0) {
            root.appendChild(el('p', { class: 'oc-muted', text: 'No pipeline steps were recorded for this session.' }));
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
