// pipeline.js — incremental rendering of pipeline steps.
//
// Append-only is preserved: the persistent step record keeps its DOM
// node from creation. Live SSE events update *only* the status, stats
// and response panes of the matching node so the user sees real-time
// progress without losing the audit trail.
//
// Public ES module exports (consumed by app.js):
//   humanActivityLabel, consensusStatusClass, ensureRoundHeader,
//   renderStep, renderQueryCard, clearPipeline, startActivityTimer,
//   stopActivityTimer, setActivity, renderFinalAnswer,
//   upsertStepFromEvent, markStaleRunningStepsFailed.
// `tests/SmokeTest.php` statically asserts that this list stays in
// sync with the imports in `app.js` so a deploy can never serve an
// `app.js` that imports a name this module does not export.
//
// v1.8.5 additions:
//   - "Current activity" card pinned to the bottom of the pipeline so
//     the user always sees what is currently being executed (and when
//     it has finished or failed). It is visually distinct from regular
//     pipeline step cards and is updated in place from SSE events.
//   - Consensus Round headers visually group L0/L1/L2/L3/L4 batches.
//     If the backend emits no round number, round 1 is implied and a
//     header is inserted before the first step.
//   - L3 (consensus) step gets dedicated styling — accent border,
//     status badge derived from the consensus verdict, and a more
//     structured/visual breakdown of score, participants and pairs.
//   - Final Answer renders into a separate section (outside the
//     pipeline) so it never visually blends in with pipeline steps.
//
// v1.9.0 additions:
//   - The submitted question is the first card of the stream
//     (`renderQueryCard`) so a saved page says what was asked, not
//     only what was computed.
//   - Stage bodies longer than four lines are clamped by CSS and
//     expanded with a per-card "Show more" toggle whose presence is
//     decided by measuring the pane, not by a character count.
//   - A floating "Final answer" control appears while the answer is
//     off screen (IntersectionObserver, no scroll handler).

import { addCopyButtons, isCopyable } from './copy-buttons.js';
import { stepCacheRows } from './prompt-cache.js';

const container         = () => document.getElementById('pipeline-section');
const finalAnswerHost   = () => document.getElementById('final-answer-section');

// Layers whose card body is a model answer. Used for the *wording* of the
// copy pair only ("Copy the L1 answer…"); whether a card gets buttons at
// all is decided by the text it actually shows — see the gate in
// renderStep().
const ANSWER_LAYERS = new Set(['L1', 'L2', 'L3', 'L4']);

// Tooltip text per layer / event type. Used as accessible `title` on
// the layer chip in each step header so the user can hover any tag and
// learn what the layer does and why it exists.
const LAYER_TOOLTIPS = {
    L0: 'L0 — Skill resolution. The router picks (or auto-creates) a Skill: a JSON definition that lists roles, prompts, and an output schema. No LLM call here unless auto-creation is needed.',
    L1: 'L1 — Distribution. One parallel chat call per selected consensus model. Each model answers the same prompt; their independent answers feed L2/L3.',
    L2: 'L2 — Normalization. The raw text from each L1 answer is reshaped into discrete claims. JSON outputs are projected onto the skill schema; plain text is split on sentence boundaries. No LLM call.',
    L3: 'L3 — Consensus. Pairwise Jaccard similarity over per-claim token bags produces an agreement score in [0,1]. Status = agreement / partial / conflict. No LLM call.',
    L4: 'L4 — Synthesis. The synthesis model receives every L1 role output plus the L3 consensus verdict and produces the final, balanced answer.',
    'Σ': 'Final answer — the L4 model output displayed verbatim with synthesis stats.',
};
const EVENT_TOOLTIPS = {
    L0_SKILL: 'Skill chosen for this query — slug/name + the resolved roles, output schema, tags and definition JSON.',
    L0_OPTIONS: 'Runtime options for the run — selected models, reasoning level, parallel L1 setting.',
    L1_STEP_STARTED: 'L1 invocation kicked off (running). Updates to "completed" or "failed" once the model returns.',
    L1_RESPONSE: 'L1 model returned successfully. Carries token + latency + cost stats.',
    L1_FAILED: 'L1 model rejected — message explains the failure (HTTP 4xx, missing key, invalid model id, …).',
    L1_NO_AVAILABLE_MODELS: 'No usable model found in the selected pool (every provider is missing a key or marked inactive).',
    L1_NO_RESULTS: 'Every L1 call failed — pipeline cannot run consensus or synthesis.',
    BATCH_L1_STEP_SUBMITTED: 'A model was queued for the provider Batch API. Results arrive asynchronously — usually minutes, up to 24 hours — and this card completes when they land.',
    BATCH_L1_NO_RESULTS: 'No model answered the batch, so there is nothing to build a consensus from.',
    BATCH_L1_NO_AVAILABLE_MODELS: 'No usable model in the selected pool for a Batch API run (a `:batch` id is allowed here).',
    L2_NORMALIZED: 'L2 reshaped the L1 answer for this role into discrete claims for consensus scoring.',
    L3_CONSENSUS: 'L3 produced a consensus verdict — score, status, explanation and pair count.',
    L4_SYNTHESIS: 'L4 synthesis model produced the final answer.',
    L4_FAILED: 'L4 synthesis failed — fallback synthesis (concatenated role summaries) is shown.',
    PIPELINE_DONE: 'Pipeline completed end-to-end.',
    PIPELINE_FATAL: 'Pipeline aborted due to an uncaught error.',
    LOOP_DETECTED: 'A step key recurred in the same session — pipeline halted to prevent runaway recursion.',
    CONSENSUS_CYCLE_LIMIT_REACHED: 'Cycle limit reached without agreement — engine proceeded to final synthesis using the conflict arbiter or fallback synthesis. No additional model rounds were started.',
    CONSENSUS_CYCLE_LIMIT_CLAMPED: 'The requested cycle limit was outside the safe range and was clamped server-side. The effective limit is shown in the payload.',
    CONSENSUS_STAGNATION_DETECTED: 'Consensus score plateaued between cycles — the engine stopped early to avoid burning tokens on a non-converging loop.',
    CONSENSUS_MANUAL_FINALIZE_REQUESTED: 'User pressed "Stop and Run consensus" — the engine will not start any further cycles and will synthesise a final answer from the results already collected.',
    CONSENSUS_MANUAL_FINALIZE_STARTED: 'Engine accepted the Stop and Run consensus request and is heading to L4 with the collected results.',
    PIPELINE_FINALIZING: 'Pipeline is finalising early on user request — no further model calls will be made for the consensus loop.',
    CONSENSUS_LOOP_GUARD_TRIGGERED: 'Defense-in-depth loop invariant guard triggered — refusing to start another L1 batch (cycle limit logic above failed). Engine proceeds to final synthesis.',
    PIPELINE_DUPLICATE_RUN_REJECTED: 'A duplicate SSE connection was rejected (the original pipeline is still running for the same client_run_id). The duplicate stream closes immediately without launching a new run.',
};

// Human-readable per-event labels for the "current activity" tail card.
// Pure mapping — no DOM access — so it can be unit-tested.
export function humanActivityLabel(type, payload) {
    if (!type) return 'Working…';
    if (type === 'L0_SKILL')           return 'Resolving skill…';
    if (type === 'L0_OPTIONS')         return 'Configuring run options…';
    if (type === 'L1_STEP_STARTED')    return 'L1 — calling ' + (payload?.role ?? 'model') + '…';
    if (type === 'L1_RESPONSE')        return 'L1 — ' + (payload?.role ?? 'model') + ' returned';
    if (type === 'L1_FAILED')          return 'L1 — ' + (payload?.role ?? 'model') + ' failed';
    if (type === 'L1_NO_AVAILABLE_MODELS') return 'No usable models for L1';
    if (type === 'L1_NO_RESULTS')      return 'All L1 calls failed';
    if (type === 'BATCH_L1_STEP_SUBMITTED') return 'L1 — ' + (payload?.role ?? 'model') + ' queued as a batch request';
    if (type === 'BATCH_L1_NO_RESULTS') return 'No model answered the batch';
    if (type === 'BATCH_L1_NO_AVAILABLE_MODELS') return 'No usable models for a batch L1';
    if (type === 'L2_NORMALIZED')      return 'L2 — normalizing ' + (payload?.role ?? 'claims');
    if (type === 'L3_CONSENSUS')       return 'L3 — consensus computed';
    if (type === 'L4_SYNTHESIS')       return 'L4 — synthesis complete';
    if (type === 'L4_FAILED')          return 'L4 — synthesis failed';
    if (type === 'CONSENSUS_ROUND_STARTED')   return 'Consensus cycle ' + (payload?.cycle ?? payload?.round ?? '?') + ' / ' + (payload?.cycle_limit ?? '?') + ' started';
    if (type === 'CONSENSUS_ROUND_COMPLETED') return 'Consensus cycle ' + (payload?.cycle ?? payload?.round ?? '?') + ' / ' + (payload?.cycle_limit ?? '?') + ' completed';
    if (type === 'CONSENSUS_ROUND_REPEAT')    return 'No consensus — starting cycle ' + (payload?.next_round ?? '?') + ' / ' + (payload?.cycle_limit ?? '?');
    if (type === 'CONSENSUS_CYCLE_LIMIT_REACHED') return 'Cycle limit reached (' + (payload?.cycle_limit ?? '?') + ') — running final synthesis';
    if (type === 'CONSENSUS_CYCLE_LIMIT_CLAMPED') return 'Cycle limit clamped to ' + (payload?.effective ?? '?');
    if (type === 'CONSENSUS_STAGNATION_DETECTED') return 'Stagnation detected — stopping early at cycle ' + (payload?.cycle ?? payload?.round ?? '?');
    if (type === 'CONSENSUS_MANUAL_FINALIZE_REQUESTED') return 'Stop and Run consensus requested at cycle ' + (payload?.cycle ?? payload?.round ?? '?');
    if (type === 'CONSENSUS_MANUAL_FINALIZE_STARTED')   return 'Finalising — synthesising from collected results';
    if (type === 'PIPELINE_FINALIZING')                 return 'Pipeline finalising — running final synthesis';
    if (type === 'CONSENSUS_LOOP_GUARD_TRIGGERED')      return 'Loop guard triggered — running final synthesis';
    if (type === 'PIPELINE_DUPLICATE_RUN_REJECTED')     return 'Duplicate stream rejected — original run continues';
    if (type === 'PIPELINE_DONE')      return 'Pipeline completed';
    if (type === 'PIPELINE_FATAL')     return 'Pipeline aborted: ' + (payload?.message ?? 'fatal error');
    if (type === 'LOOP_DETECTED')      return 'Loop detected — pipeline halted';
    if (type === 'SKILL_AUTO_SELECTED') return 'Auto selector - ' + ((payload?.created) ? 'built ' : 'picked ') + ((payload?.skill ?? '') || 'a skill') + (payload?.role ? ' (role: ' + payload.role + ')' : '');
    return type.replace(/_/g, ' ').toLowerCase();
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

function statsList(meta) {
    const dl = el('dl', { class: 'oc-step-stats' });
    const totalSuffix = meta.tokens_estimated ? ' (estimated)' : '';
    const rows = [
        ['Model',             meta.model ?? '—'],
        // The role that answered, when the step has one: L1 cards are the only
        // ones that do, and an always-present empty row would read as missing data.
        ...((meta.role_label || meta.role)
            ? [['Role', meta.role_label ?? meta.role]]
            : []),
        ['Provider',          meta.provider ?? '—'],
        ['Prompt tokens',     meta.prompt_tokens ?? '—'],
        ['Completion tokens', meta.completion_tokens ?? '—'],
        ['Total tokens',      (meta.total_tokens ?? '—') + (meta.total_tokens != null && meta.total_tokens !== '—' ? totalSuffix : '')],
        ['Latency',           meta.latency ?? '—'],
        ['Status',            meta.status ?? '—'],
        ['Timestamp',         meta.timestamp ?? '—'],
    ];
    if (meta.cost != null)             rows.push(['Cost (USD)', Number(meta.cost).toFixed(6)]);
    if (meta.cached_tokens != null)    rows.push(['Cached tokens',    meta.cached_tokens]);
    if (meta.reasoning_tokens != null) rows.push(['Reasoning tokens', meta.reasoning_tokens]);
    if (meta.generation_id)            rows.push(['Generation ID',    meta.generation_id]);
    // Prompt cache: read/write tokens and the saving, or an explicit
    // "no data" row — never a zero standing in for a provider that simply
    // reported nothing. Appended last, so a response without cache
    // statistics renders exactly the card it always rendered. Steps that
    // made no provider call at all (L2/L3) contribute no rows.
    for (const cacheRow of stepCacheRows(meta)) rows.push(cacheRow);
    for (const [k, v] of rows) {
        const row = el('div', {}, [el('dt', {}, [k]), el('dd', {}, [String(v)])]);
        dl.append(row);
    }
    return dl;
}

function tabbed(panes) {
    const nav = el('nav', { class: 'oc-step-tabs', role: 'tablist' });
    const wrap = document.createDocumentFragment();
    const buttons = [];
    const sections = [];
    for (const [name, label, content] of panes) {
        const btn = el('button', {
            type: 'button',
            class: 'oc-tab',
            'aria-selected': sections.length === 0 ? 'true' : 'false',
            dataset: { tab: name },
        }, [label]);
        const sec = el('section', { class: 'oc-tab-pane', dataset: { pane: name } }, [content]);
        if (sections.length > 0) sec.hidden = true;
        btn.addEventListener('click', () => {
            for (const b of buttons) b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            for (const s of sections) s.hidden = s !== sec;
        });
        buttons.push(btn);
        sections.push(sec);
        nav.append(btn);
    }
    wrap.append(nav, ...sections);
    return wrap;
}

// Map a consensus status string to a visual class (used to colour the
// L3 verdict badge). Pure / testable.
export function consensusStatusClass(status) {
    const s = String(status || '').toLowerCase();
    if (s === 'agreement' || s === 'agree' || s === 'consensus') return 'ok';
    if (s === 'partial' || s === 'partial-agreement')             return 'partial';
    if (s === 'conflict' || s === 'disagreement' || s === 'no-consensus') return 'conflict';
    return 'neutral';
}

function explanationPane(exp) {
    const wrap = el('div', { class: 'oc-l3-explain' });
    const decision = exp?.decision ?? {};
    const participants = exp?.participants ?? {};
    const score = typeof decision.score === 'number' ? decision.score.toFixed(3) : '—';
    const pct   = decision.score_pct != null ? decision.score_pct + '%' : '—';
    const pairs = decision.pairs != null ? decision.pairs : '—';
    const status = decision.status ?? '—';
    const label  = decision.label ?? '';
    const roles  = Array.isArray(participants.roles) ? participants.roles : [];
    const count  = participants.count != null ? participants.count : roles.length;

    // Top-row: large verdict badge + score gauge.
    const verdictClass = consensusStatusClass(status);
    const top = el('div', { class: 'oc-l3-top' }, [
        el('div', { class: 'oc-l3-verdict oc-l3-verdict-' + verdictClass }, [
            el('span', { class: 'oc-l3-verdict-label' }, [String(status).toUpperCase()]),
            label ? el('span', { class: 'oc-l3-verdict-detail' }, [label]) : null,
        ]),
        el('div', { class: 'oc-l3-score' }, [
            el('span', { class: 'oc-l3-score-num' }, [score]),
            el('span', { class: 'oc-l3-score-pct' }, [pct]),
            el('span', { class: 'oc-l3-score-label' }, ['agreement']),
        ]),
    ]);

    // Stat tiles row.
    const tiles = el('div', { class: 'oc-l3-tiles' }, [
        el('div', { class: 'oc-l3-tile' }, [
            el('span', { class: 'oc-l3-tile-num' }, [String(count)]),
            el('span', { class: 'oc-l3-tile-label' }, ['participant' + (count === 1 ? '' : 's')]),
        ]),
        el('div', { class: 'oc-l3-tile' }, [
            el('span', { class: 'oc-l3-tile-num' }, [String(pairs)]),
            el('span', { class: 'oc-l3-tile-label' }, ['pair' + (pairs === 1 ? '' : 's')]),
        ]),
    ]);

    if (roles.length > 0) {
        const chips = el('div', { class: 'oc-l3-roles' });
        for (const r of roles) {
            chips.append(el('span', { class: 'oc-l3-role-chip' }, [String(r)]));
        }
        wrap.append(top, tiles, el('div', { class: 'oc-l3-section' }, [
            el('div', { class: 'oc-l3-section-title' }, ['Roles']),
            chips,
        ]));
    } else {
        wrap.append(top, tiles);
    }

    wrap.append(el('div', { class: 'oc-l3-section' }, [
        el('div', { class: 'oc-l3-section-title' }, ['What was evaluated']),
        el('p', { class: 'oc-l3-what' }, [
            exp?.what ?? 'Comparison of normalized claims from L1 roles.',
        ]),
    ]));

    wrap.append(el('div', { class: 'oc-l3-section' }, [
        el('div', { class: 'oc-l3-section-title' }, ['Conclusion']),
        el('p', { class: 'oc-l3-conclusion' }, [label || (exp?.conclusion ?? '')]),
        (exp?.conclusion && label && exp.conclusion !== label)
            ? el('p', { class: 'oc-l3-detail oc-muted' }, [exp.conclusion])
            : null,
    ]));

    return wrap;
}

function skillPane(payload) {
    const wrap = el('div', { class: 'oc-skill-card' });
    const skill = payload || {};
    const roles = Array.isArray(skill.roles) ? skill.roles : [];
    wrap.append(
        el('p', {}, [
            el('strong', {}, ['Skill: ']),
            (skill.name || skill.slug || '—'),
            ' (',
            (skill.slug || '—'),
            skill.auto ? ', auto-generated' : '',
            ')',
        ]),
        el('p', { class: 'oc-muted' }, [skill.description || '']),
    );
    if (skill.tags && skill.tags.length > 0) {
        wrap.append(el('p', {}, [
            el('strong', {}, ['Tags: ']),
            skill.tags.join(', '),
        ]));
    }
    if (roles.length > 0) {
        wrap.append(el('p', {}, [
            el('strong', {}, ['Roles (' + roles.length + '): ']),
        ]));
        const ul = el('ul', { class: 'oc-skill-roles' });
        for (const r of roles) {
            const li = el('li', {}, []);
            li.append(el('strong', {}, [r.label || r.key || 'role']));
            li.append(' — ');
            li.append(document.createTextNode((r.system || '').slice(0, 240)));
            ul.append(li);
        }
        wrap.append(ul);
    }
    if (skill.synthesis_prompt) {
        wrap.append(el('p', {}, [el('strong', {}, ['Synthesis prompt: '])]));
        wrap.append(el('div', { class: 'oc-pre' }, [skill.synthesis_prompt]));
    }
    if (skill.has_output_schema) {
        wrap.append(el('p', { class: 'oc-muted oc-small' },
            ['Output schema present — L2 will project JSON answers onto this shape.']));
    }
    return wrap;
}

// Insert a node *before* the current-activity tail (if any), so the
// tail card always remains the last child of the pipeline section.
function appendBeforeActivityTail(root, node) {
    const tail = root.querySelector('.oc-current-activity');
    if (tail) root.insertBefore(node, tail);
    else      root.append(node);
}

// Heading line for a card's Markdown snippet: the same layer chip and
// label the card header shows, so a pasted answer says which stage it
// came from.
function copyHeading(layerKey, label) {
    const name = typeof label === 'string' ? label.trim() : '';
    return name === '' ? String(layerKey) : layerKey + ' · ' + name;
}

// Wording for a card's copy pair. An answer layer is named as such ("Copy
// the L1 answer"); every other copyable card — a run-options payload, a
// consensus guard event — is called "this card", because calling a JSON
// payload "the L0 answer" would mis-describe what lands on the clipboard.
function copyLabels(layerKey) {
    if (ANSWER_LAYERS.has(layerKey)) {
        return {
            label: `Copy the ${layerKey} answer to clipboard, exactly as shown`,
            mdLabel: `Copy the ${layerKey} answer as a Markdown snippet (card heading added, text unchanged)`,
        };
    }
    return {
        label: 'Copy this card\'s text to clipboard, exactly as shown',
        mdLabel: 'Copy this card as a Markdown snippet (card heading added, text unchanged)',
    };
}

// Metadata line for a card's Markdown snippet: provider/model, token
// split and latency when the card knows them (they are what the
// Statistics tab shows). Returns '' when nothing is known, in which case
// the snippet carries the heading only.
function copyMetaFromStats(stats) {
    const s = stats || {};
    const known = (v) => v !== undefined && v !== null && v !== '' && v !== '—';
    const bits = [];
    if (known(s.model)) {
        bits.push(known(s.provider) ? String(s.provider) + ' / ' + String(s.model) : String(s.model));
    } else if (known(s.provider)) {
        bits.push(String(s.provider));
    }
    if (known(s.prompt_tokens) || known(s.completion_tokens) || known(s.total_tokens)) {
        bits.push(`${known(s.prompt_tokens) ? s.prompt_tokens : '?'} + `
            + `${known(s.completion_tokens) ? s.completion_tokens : '?'} = `
            + `${known(s.total_tokens) ? s.total_tokens : '?'} tok`);
    }
    if (known(s.latency)) bits.push(String(s.latency));
    return bits.join(' · ');
}

// Ensure a Consensus Cycle header exists for the given cycle number;
// inserts it just before the activity tail if missing. Returns the
// header node (existing or new). If `cycleLimit` is supplied, the
// header reads "Consensus Cycle X / Y" so the operator sees how far
// along the run is in real time.
export function ensureRoundHeader(round, cycleLimit) {
    const root = container();
    if (!root) return null;
    const n = Math.max(1, Number(round) || 1);
    const limit = Number(cycleLimit) > 0 ? Math.floor(Number(cycleLimit)) : null;
    const key = 'round-' + n;
    let header = root.querySelector('[data-round-key="' + key + '"]');
    if (header) {
        // Update the trailing "/ Y" if the limit was unknown at first emit.
        if (limit != null) {
            const titleEl = header.querySelector('.oc-round-title');
            if (titleEl) titleEl.textContent = 'Consensus Cycle ' + n + ' / ' + limit;
            header.dataset.cycleLimit = String(limit);
        }
        return header;
    }
    const title = limit != null ? ('Consensus Cycle ' + n + ' / ' + limit) : ('Consensus Cycle ' + n);
    header = el('div', {
        class: 'oc-round-header',
        dataset: {
            roundKey: key,
            round: String(n),
            cycle: String(n),
            cycleLimit: limit != null ? String(limit) : '',
        },
        role: 'separator',
        'aria-label': title,
    }, [
        el('span', { class: 'oc-round-icon', 'aria-hidden': 'true' }, ['◆']),
        el('span', { class: 'oc-round-title' }, [title]),
        el('span', { class: 'oc-round-sub' }, ['L0 → L1 → L2 → L3 → L4']),
    ]);
    appendBeforeActivityTail(root, header);
    return header;
}

// ---------- Collapsible stage bodies ----------
//
// A run produces one full model answer per selected model, plus the
// normalized, consensus and synthesis bodies: four lines of each is what a
// human can scan, and the rest is what makes the page a wall. Every stage
// card's Response pane is therefore clamped to a few lines behind a
// per-card toggle.
//
// The clamp is CSS-only (`max-height` + `overflow: hidden`, see
// assets/css/components/pipeline.css). The full text stays in the DOM, so
// nothing downstream has to know about it:
//   * the copy pair hands over `data-copy-value` / `data-copy-md-value`,
//     written from the same `step.response_text` the pane renders — never
//     read back from the DOM, so a clamped card still copies the whole
//     answer;
//   * the "copy failed, select it for me" fallback selects the pane, whose
//     text content is complete;
//   * Ctrl+A / "select all and copy" by hand still yields every word,
//     because nothing was removed — only clipped.
// Truncating the text node instead would have broken all three, silently.

// Lines kept visible while a card is collapsed. The CSS clamp uses the same
// number (4 * 1.55em in pipeline.css) — keep the two in step; the labels
// below read this constant so the promise and the clamp cannot drift apart
// in wording at least.
const COLLAPSE_LINES = 4;

// Rounding slack for the overflow test: scrollHeight/clientHeight are
// integers, and a fractional line box can sit a hair above the clamp.
const COLLAPSE_SLACK_PX = 1;

// Cards the user expanded, keyed by the same dom_key the SSE stream uses
// to address a card. A live update rebuilds the whole <article>
// (upsertStepFromEvent replaces the node, it does not patch it), so
// without this the user's "Show more" would be undone by the next frame of
// the very run they are reading. Only expanded cards are stored: absent
// means collapsed, which is also the default for a card seen for the first
// time. Cleared with the rest of the run state in clearPipeline().
const expandedCards = new Map();

// Suffix for the generated pane ids that `aria-controls` points at.
let collapsePaneSeq = 0;

const COLLAPSE_HINT_MORE = 'This answer is longer than the card shows — click to read the rest.';
const COLLAPSE_HINT_LESS = 'Collapse this step back to its first ' + COLLAPSE_LINES + ' lines.';
// The accessible names open with the visible label, so "Show more" is still
// what a screen reader announces (WCAG 2.5.3, label in name) while the rest
// of the sentence says what it does.
const COLLAPSE_LABEL_MORE = 'Show more — reveal the rest of this step\'s text';
const COLLAPSE_LABEL_LESS = 'Show less — collapse this step back to ' + COLLAPSE_LINES + ' lines';

// The key that identifies a card across re-renders: the SSE stream's
// dom_key when the caller has one (it always does for live steps), the
// step id otherwise. Cards rendered from a persisted session by
// app.js::fallbackSync carry neither in a stable form, and simply do not
// remember their state — documented, not accidental.
function stepCardKey(step) {
    if (step && step.dom_key) return String(step.dom_key);
    if (step && step.id !== undefined && step.id !== null && step.id !== '') return 'id:' + String(step.id);
    return null;
}

// Does the pane show more than it can display? Measured, never guessed: a
// character count would either offer "Show more" on a card that is already
// fully visible (wide viewport) or hide the rest of a short answer (narrow
// one). The caller has already applied the clamp class, so scrollHeight is
// the full content height and clientHeight is the clamped height.
function paneOverflows(pane) {
    const scroll = Number(pane.scrollHeight) || 0;
    const client = Number(pane.clientHeight) || 0;
    if (client <= 0) return false;
    return scroll > client + COLLAPSE_SLACK_PX;
}

// Can this pane be measured at all? A node with no layout — a hidden tab
// panel, a `display: none` ancestor, a browser that has not rendered it yet —
// reports zeroes for both heights, and "not measurable" is a different fact
// from "fits".
function paneHasLayout(pane) {
    return (Number(pane.clientHeight) || 0) > 0;
}

// Cards whose collapse decision had to be postponed, and the one observer
// that resolves them. The run streams while the user is on another tab as a
// matter of course (that is what job mode is for); the chat panel is then
// `hidden`, every freshly rendered pane has no layout, and the answer to
// "does this fit?" does not exist yet. Leaving those cards unclamped for the
// rest of the run would quietly lose the feature for exactly the long runs
// it was added for, so the decision is retried the first time the pane
// becomes visible — which is what an IntersectionObserver reports, with no
// scroll handler and no polling.
const pendingCollapse = new Map();
let pendingCollapseObserver = null;

function deferCollapseDecision(header, pane, key, expanded) {
    if (typeof IntersectionObserver !== 'function') return null;
    if (pendingCollapse.has(pane)) return null;
    if (!pendingCollapseObserver) {
        pendingCollapseObserver = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                const target = entry.target;
                pendingCollapseObserver.unobserve(target);
                const pending = pendingCollapse.get(target);
                pendingCollapse.delete(target);
                // `allowDefer = false`: this IS the retry. A pane that is
                // still not measurable now (an empty body) is given up on,
                // rather than re-observed for ever — observe() reports the
                // current state immediately, so a re-defer here would spin.
                if (pending) attachCollapseToggle(pending.header, target, pending.key, pending.expanded, false);
            }
        }, { threshold: 0 });
    }
    pendingCollapse.set(pane, { header, key, expanded });
    pendingCollapseObserver.observe(pane);
    return null;
}

// Decide, for one rendered card, whether its body needs a toggle, and wire
// the toggle up. Returns the button, or null when the body fits — which is
// the common case for short steps and must stay free of any control.
function attachCollapseToggle(header, pane, key, expanded, allowDefer = true) {
    if (!pane || typeof pane.classList?.add !== 'function') return null;

    pane.classList.add('oc-collapsible', 'oc-collapsed');
    if (!paneOverflows(pane)) {
        pane.classList.remove('oc-collapsible', 'oc-collapsed');
        // Either the body fits (no control — the common case) or there is
        // nothing to measure yet (retry when it is first visible).
        if (paneHasLayout(pane)) return null;
        return allowDefer ? deferCollapseDecision(header, pane, key, expanded) : null;
    }
    if (expanded) pane.classList.remove('oc-collapsed');

    if (!pane.id) pane.id = 'oc-collapse-pane-' + (++collapsePaneSeq);

    const button = el('button', {
        type: 'button',
        class: 'oc-collapse-toggle',
        'aria-expanded': expanded ? 'true' : 'false',
        'aria-controls': pane.id,
        'aria-label': expanded ? COLLAPSE_LABEL_LESS : COLLAPSE_LABEL_MORE,
        title: expanded ? COLLAPSE_HINT_LESS : COLLAPSE_HINT_MORE,
    }, [expanded ? 'Show less' : 'Show more']);

    button.addEventListener('click', () => {
        const collapsed = pane.classList.toggle('oc-collapsed');
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        button.setAttribute('aria-label', collapsed ? COLLAPSE_LABEL_MORE : COLLAPSE_LABEL_LESS);
        button.title = collapsed ? COLLAPSE_HINT_MORE : COLLAPSE_HINT_LESS;
        button.textContent = collapsed ? 'Show more' : 'Show less';
        if (key) {
            if (collapsed) expandedCards.delete(key);
            else           expandedCards.set(key, true);
        }
        // No scroll compensation is needed on either transition: the
        // control lives in the card header, above the body it grows and
        // shrinks, so the anchor the user just clicked does not move and
        // the page cannot jump.
    });

    // Left of the copy pair when the card has one, at the end of the
    // header otherwise. `.oc-step-label` is flex:1, so both land at the
    // right edge of the row.
    const copyGroup = header.querySelector('.oc-copy-group');
    if (copyGroup) header.insertBefore(button, copyGroup);
    else           header.append(button);
    return button;
}

export function renderStep(step) {
    const root = container();
    if (!root) return;
    // A step just became visible: read its plan (the engine puts one on every
    // step) and count it, so the activity card can say where the run is.
    applyPlan(step && step.raw && step.raw.step && step.raw.step.meta
        ? step.raw.step.meta.plan
        : (step && step.plan));
    planDrawn += 1;
    if (planTotal > 0 && planCycleGrown > 0 && planDrawn >= planTotal && planGrownAt !== planTotal) {
        // The run has passed the total it published: it went into another
        // cycle, which the engine says takes `next_cycle_steps`. Grow the
        // plan once per crossing so the counter never reads "more than
        // 100%", and never grows twice for the same crossing.
        planGrownAt = planTotal;
        planTotal += planCycleGrown;
    }

    let responsePane;
    const explanation = step.l3_explanation
        ?? (step.raw && step.raw.step && step.raw.step.meta && step.raw.step.meta.explanation)
        ?? null;
    if (step.layer === 'L3' && explanation && typeof explanation === 'object') {
        responsePane = explanationPane(explanation);
    } else if (step.layer === 'L0' && step.skill_payload) {
        responsePane = skillPane(step.skill_payload);
    } else {
        responsePane = el('div', { class: 'oc-step-response' }, [step.response_text || '(no response)']);
    }
    const statsPane = statsList(step.stats || {});
    const rawPane   = el('pre', { class: 'oc-pre' }, [JSON.stringify(step.raw ?? {}, null, 2)]);

    const articleClass = ['oc-step'];
    if (step.layer === 'L3') articleClass.push('oc-step-l3');

    const article = el('article', {
        class: articleClass.join(' '),
        dataset: { stepId: String(step.id ?? ''), status: step.status ?? 'completed', layer: step.layer ?? '' },
    });
    if (step.dom_key) article.dataset.key = step.dom_key;

    const layerKey = step.layer ?? 'L?';
    const layerTitle = LAYER_TOOLTIPS[layerKey]
        ?? EVENT_TOOLTIPS[step.label]
        ?? 'Pipeline stage tag.';
    const labelTitle = step.label_title ?? layerTitle;
    const headerChildren = [
        el('span', { class: 'oc-step-layer', title: layerTitle, 'data-layer': layerKey }, [layerKey]),
        el('span', { class: 'oc-step-label', title: labelTitle }, [step.label ?? '']),
    ];
    // For L3, surface the consensus verdict as an extra accent badge.
    if (step.layer === 'L3') {
        const decision = explanation?.decision ?? {};
        const status = decision.status ?? '';
        if (status) {
            const verdictClass = consensusStatusClass(status);
            headerChildren.push(el('span', {
                class: 'oc-step-verdict oc-step-verdict-' + verdictClass,
                title: 'Consensus verdict: ' + status,
            }, [String(status).toUpperCase()]));
        }
    }
    headerChildren.push(el('span', { class: 'oc-step-status oc-status-' + (step.status ?? 'completed'),
        title: 'Lifecycle status: running while in flight, completed/failed once done.' },
        [step.status ?? 'completed']));
    const header = el('header', { class: 'oc-step-header' }, headerChildren);

    // Copy affordances for cards whose Response tab shows text. The pair
    // hands over `step.response_text` — the same string rendered into the
    // Response tab, so "Copy" can never pick up the header badges, the
    // stats table or the Raw JSON pane that share this article — and
    // "Copy MD" puts the layer/label heading (plus the model facts, when
    // the card knows them) above that same text.
    //
    // The gate used to be a layer allow-list (`COPYABLE_LAYERS`), which
    // silently left every L0 card — the first card of a run — without a
    // copy pair. A layer name is a proxy for "this body is an answer", and
    // a poor one: what a user can copy is the text on screen. So the gate
    // is the text: the card must show `step.response_text` in its Response
    // tab, must not be mid-flight (its body is then the "Awaiting model
    // response…" filler), and that text must survive isCopyable(), the
    // placeholder guard owned by copy-buttons.js ("(no response)",
    // "loading…", …). Cards whose Response tab is something else — an L3
    // verdict pane, an L0 skill card — keep their pair off, because their
    // payload would be text the user cannot see.
    const showsAnswerText = responsePane.classList.contains('oc-step-response');
    const copyable = showsAnswerText && step.status !== 'running' && isCopyable(step.response_text);
    const labels = copyLabels(layerKey);
    addCopyButtons(header, {
        text: copyable ? step.response_text : '',
        source: showsAnswerText ? responsePane : null,
        heading: copyHeading(layerKey, step.label),
        level: 3,
        meta: copyMetaFromStats(step.stats),
        label: labels.label,
        mdLabel: labels.mdLabel,
    });

    const tabs = tabbed([
        ['response', 'Response',   responsePane],
        ['stats',    'Statistics', statsPane],
        ['raw',      'Raw',        rawPane],
    ]);

    article.append(header, tabs);
    appendBeforeActivityTail(root, article);
    // Clamp the body only now: whether four lines are enough is a
    // measurement, and a pane outside the document has none. The header is
    // passed in so the toggle can sit next to the copy pair.
    const cardKey = stepCardKey(step);
    attachCollapseToggle(header, responsePane, cardKey,
        cardKey !== null && expandedCards.get(cardKey) === true);
    return article;
}

export function clearPipeline() {
    const root = container();
    if (root) root.replaceChildren();
    const fa = finalAnswerHost();
    if (fa) {
        fa.replaceChildren();
        fa.hidden = true;
    }
    // The answer is gone with the run, so the control that jumps to it has
    // nothing to point at.
    hideJumpToFinalAnswer();
    // Reset round / live-step tracking when a new run starts.
    liveSteps.clear();
    seenRounds.clear();
    expandedCards.clear();
    // Cards waiting to be measured belonged to the run that just went away.
    if (pendingCollapseObserver) {
        pendingCollapseObserver.disconnect();
        pendingCollapseObserver = null;
    }
    pendingCollapse.clear();
}

// ---------- Current-activity tail card ----------
//
// A persistent card pinned to the bottom of the pipeline that always
// shows what the backend is doing right now. Updated in place from
// SSE events. Visually distinct from regular pipeline step cards so
// users never confuse it with an audit-trail entry.

const activityState = { startedAt: null, timer: null };

function ensureActivityCard() {
    const root = container();
    if (!root) return null;
    let card = root.querySelector('.oc-current-activity');
    if (card) return card;
    card = el('div', { class: 'oc-current-activity', dataset: { state: 'running' }, role: 'status', 'aria-live': 'polite' }, [
        el('div', { class: 'oc-activity-pulse', 'aria-hidden': 'true' }, [
            el('span', { class: 'oc-activity-spinner' }, []),
        ]),
        el('div', { class: 'oc-activity-body' }, [
            el('div', { class: 'oc-activity-row' }, [
                el('span', { class: 'oc-activity-eyebrow' }, ['Current activity']),
                el('span', { class: 'oc-activity-state', 'data-state-label': 'running' }, ['running']),
            ]),
            el('div', { class: 'oc-activity-label' }, ['Starting pipeline…']),
            el('div', { class: 'oc-activity-meta' }, [
                el('span', { class: 'oc-activity-stage' }, ['—']),
                el('span', { class: 'oc-activity-sep' }, ['·']),
                el('span', { class: 'oc-activity-elapsed' }, ['0s']),
                el('span', { class: 'oc-activity-sep' }, ['·']),
                el('span', { class: 'oc-activity-time' }, [new Date().toLocaleTimeString()]),
            ]),
        ]),
    ]);
    root.append(card);
    return card;
}

function fmtElapsed(ms) {
    if (!Number.isFinite(ms) || ms < 0) return '0s';
    if (ms < 1000) return Math.round(ms) + 'ms';
    if (ms < 60_000) return (ms / 1000).toFixed(1) + 's';
    const m = Math.floor(ms / 60_000);
    const s = Math.round((ms - m * 60_000) / 1000);
    return m + 'm' + String(s).padStart(2, '0') + 's';
}

function tickActivityElapsed() {
    const card = container()?.querySelector('.oc-current-activity');
    if (!card || !activityState.startedAt) return;
    const span = card.querySelector('.oc-activity-elapsed');
    if (!span) return;
    span.textContent = fmtElapsed(Date.now() - activityState.startedAt);
}

export function startActivityTimer() {
    activityState.startedAt = Date.now();
    if (activityState.timer) clearInterval(activityState.timer);
    activityState.timer = setInterval(tickActivityElapsed, 500);
    ensureActivityCard();
}

export function stopActivityTimer() {
    if (activityState.timer) clearInterval(activityState.timer);
    activityState.timer = null;
}

/**
 * Steps drawn so far, and the plan the run published. The counter in the
 * activity card reads "step N of M" while the plan is known; a later cycle
 * grows M by what the engine says one cycle adds, so a run that cycles is
 * never reported as more than 100%.
 */
let planTotal = 0;
let planDrawn = 0;
let planCycleGrown = 0;
let planGrownAt = 0;

/** Apply the plan a step carried; safe to call with nothing. */
function applyPlan(plan) {
    if (!plan || typeof plan !== 'object') return;
    const first = Number(plan.steps_first_cycle);
    if (!Number.isFinite(first) || first <= 0) return;
    if (planTotal === 0) {
        planTotal = first;
        planDrawn = 0;
        planCycleGrown = Number(plan.next_cycle_steps) || 0;
    }
}

/** `step N of M`, or an empty string while the plan is unknown. */
function planLabel() {
    if (planTotal <= 0) return '';
    const n = Math.max(1, planDrawn + 1);
    return 'step ' + n + ' of ' + planTotal;
}

export function setActivity({ state, label, stage }) {
    const card = ensureActivityCard();
    if (!card) return;
    if (state) {
        card.dataset.state = state;
        const stateBadge = card.querySelector('.oc-activity-state');
        if (stateBadge) {
            stateBadge.textContent = state;
            stateBadge.dataset.stateLabel = state;
        }
    }
    if (label != null) {
        const lbl = card.querySelector('.oc-activity-label');
        if (lbl) lbl.textContent = String(label);
    }
    if (stage != null) {
        const st = card.querySelector('.oc-activity-stage');
        if (st) {
            const counter = planLabel();
            st.textContent = counter === '' ? String(stage) : String(stage) + ' · ' + counter;
        }
    }
    const t = card.querySelector('.oc-activity-time');
    if (t) t.textContent = new Date().toLocaleTimeString();
    tickActivityElapsed();
}

// ---------- The query card ----------
//
// The question opens the stream, above every stage card. The audit trail
// used to start at L0 — "Skill resolution" — so a page kept open, a
// screenshot or a shared browser window never said what had been asked.
//
// It is deliberately NOT a stage card: no layer chip, no lifecycle status,
// no model, no token statistics and no Raw pane, because none of those are
// true of a question, and a card that pretends to have them is worse than
// no card. What it keeps is the pipeline's card chrome — border, header
// row, copy pair — so it reads as the first entry of the same list rather
// than as a new widget. It is removed together with the run by
// clearPipeline(), like every other card.
// ---------- who runs, and who arbitrates (v1.9.1) ----------

// The card under the question names the pool that was sent and the arbiter the
// council appoints, then replaces both with what the server actually resolved
// (the first `L0_OPTIONS` event). A composition that never ran must not stay on
// screen: when the engine drops a model, the card has to say so.
let queryParticipants = null;
let queryRequestedModels = [];

/** Normalise either `{provider, model}` rows or "provider/model" strings. */
function participantRows(rows) {
    const out = [];
    for (const row of Array.isArray(rows) ? rows : []) {
        if (typeof row === 'string') {
            const cut = row.indexOf('/');
            if (cut > 0 && cut < row.length - 1) {
                out.push({ provider: row.slice(0, cut), model: row.slice(cut + 1) });
            }
            continue;
        }
        if (!row || typeof row !== 'object') continue;
        const model = typeof row.model === 'string' ? row.model : '';
        if (model === '') continue;
        out.push({ provider: typeof row.provider === 'string' ? row.provider : '', model });
    }
    return out;
}

/**
 * "model (provider) - role".
 *
 * Before the server has answered, an absent arbiter means "not known yet" -
 * saying "none configured" there would be a claim the client cannot check, and
 * the council may well have one.
 */
function arbiterLine(arbiter, resolved) {
    if (!arbiter || typeof arbiter !== 'object'
        || typeof arbiter.model !== 'string' || arbiter.model === '') {
        return resolved
            ? 'none configured for this council'
            : 'not named yet - the server names it when the run starts';
    }
    const provider = typeof arbiter.provider === 'string' && arbiter.provider !== ''
        ? ' (' + arbiter.provider + ')' : '';
    const role = typeof arbiter.role === 'string' && arbiter.role !== ''
        ? ' - ' + arbiter.role : '';
    return arbiter.model + provider + role;
}

/**
 * The participants / arbiter block.
 *
 * `state` is `requested` before the server has answered and `resolved`
 * afterwards; `note` carries the difference between the two.
 */
function participantsBlock(info) {
    const models = participantRows(info && info.models);
    const resolved = info && info.state === 'resolved';
    const children = [
        el('p', { class: 'oc-query-participants-head' }, [
            'Participants (' + models.length + ') - '
            + (resolved ? 'as resolved by the server' : 'as requested') + ':',
        ]),
        el('ul', { class: 'oc-query-participants-list' }, models.map((row) => el('li', {
            class: 'oc-query-participant',
            title: row.provider === '' ? row.model : row.provider + ' / ' + row.model,
        }, [
            el('span', { class: 'oc-query-participant-model' }, [row.model]),
            row.provider === '' ? null : el('span', { class: 'oc-query-participant-provider' }, [row.provider]),
        ]))),
        el('p', { class: 'oc-query-arbiter' }, ['Arbiter: ' + arbiterLine(info && info.arbiter, resolved)]),
    ];
    if (info && typeof info.note === 'string' && info.note !== '') {
        children.push(el('p', { class: 'oc-query-participants-note' }, [info.note]));
    }
    return el('div', {
        class: 'oc-query-participants' + (resolved ? ' oc-query-participants-resolved' : ''),
        dataset: { state: resolved ? 'resolved' : 'requested' },
    }, children);
}

/**
 * Replace the card's block with the run's real composition.
 *
 * Called from the `L0_OPTIONS` handler: `selected_models` is the pool the
 * engine resolved after its own checks and `arbiter` is the model that will
 * arbitrate. Models the request carried but the run did not get are named,
 * together with the reasons the engine published, so "5 requested, 4 used" is
 * never left unexplained.
 */
export function updateQueryParticipants(payload) {
    if (!queryParticipants) return;
    const models = participantRows(payload && payload.selected_models);
    const resolvedKeys = new Set(models.map((row) => row.provider + '/' + row.model));
    const missing = queryRequestedModels.filter((row) => !resolvedKeys.has(row.provider + '/' + row.model));
    let note = '';
    if (queryRequestedModels.length > 0 && missing.length > 0) {
        note = 'Requested ' + queryRequestedModels.length + ', resolved ' + models.length + '. Not used: '
            + missing.map((row) => (row.provider === '' ? row.model : row.provider + '/' + row.model)).join(', ') + '.';
        const diagnostic = payload && payload.selection_diagnostic;
        const rejected = diagnostic && Array.isArray(diagnostic.rejected) ? diagnostic.rejected : [];
        const reasons = [];
        for (const row of rejected) {
            if (!row || typeof row !== 'object') continue;
            const reason = String(row.reason ?? '');
            if (reason === '') continue;
            reasons.push(String(row.provider ?? '') + '/' + String(row.model ?? '') + ' - ' + reason);
        }
        if (reasons.length > 0) note += ' Reasons: ' + reasons.join('; ') + '.';
        note += ' The L0_OPTIONS card holds the raw record.';
    }
    const fresh = participantsBlock({
        models,
        arbiter: payload && payload.arbiter,
        state: 'resolved',
        note,
    });
    const parent = queryParticipants.parentNode;
    if (parent) {
        // insertBefore + remove, not replaceChild: the project's DOM stub
        // implements the first pair and several harnesses depend on it.
        parent.insertBefore(fresh, queryParticipants);
        queryParticipants.remove();
        queryParticipants = fresh;
    }
}

// ---------- contribution to the decision (R9 extension) ----------

/**
 * Who contributed what to the final answer.
 *
 * The shares come from the consensus engine's per-participant breakdown: the
 * part of each answer that other participants corroborated, as a share of the
 * corroborated total. It is a share of *agreement*, not of volume - a model
 * that says a lot on its own shows up small here, which is the honest reading
 * of "contributed to the decision".
 *
 * Rendered under the final answer, because that is the thing being attributed.
 */
export function renderFinalContribution(contribution) {
    const host = document.getElementById('final-answer-section');
    if (!host || !contribution || typeof contribution !== 'object') return;
    const existing = host.querySelector('.oc-final-contribution');
    if (existing) existing.remove();
    const models = Array.isArray(contribution.models) ? contribution.models : [];
    const parts = [];
    for (const row of models) {
        if (!row || typeof row.model !== 'string' || row.model === '') continue;
        parts.push(row.model + ' ' + Math.round((Number(row.share) || 0) * 100) + '%');
    }
    if (parts.length === 0) return;

    host.append(el('p', { class: 'oc-final-contribution' }, [
        'Contribution to the decision (share of each answer corroborated by the other '
        + 'participants): ' + parts.join(' · '),
    ]));
}

// ---------- run controls under the question (Pause / Reject / Stop and Run consensus) ----------
//
// The same three controls the composer bar carries, placed under the newest
// question - which is where the operator is looking while a long run is going
// on. Labels, enabled states and handlers are passed in from app.js, so there is
// one set of rules for both places rather than two that can disagree.
let runControls = null;
let runControlHandlers = { pause: null, reject: null, finalize: null };

/** Create the block once, inside the row that already holds the newest card. */
function buildRunControls(host) {
    const make = (role, className) => {
        const button = el('button', {
            type: 'button',
            class: className,
            text: '',
        });
        button.addEventListener('click', () => {
            const handler = runControlHandlers[role];
            if (typeof handler === 'function') {
                handler();
            }
        });

        return button;
    };

    const pause = make('pause', 'oc-button oc-small');
    const reject = make('reject', 'oc-button oc-small');
    const finalize = make('finalize', 'oc-button oc-small');
    const box = el('div', { class: 'oc-query-controls' }, [
        el('p', { class: 'oc-query-controls-head', text: 'This run' }),
        el('div', { class: 'oc-query-controls-buttons' }, [pause, reject, finalize]),
    ]);
    host.append(box);
    runControls = { box: box, pause: pause, reject: reject, finalize: finalize };

    return runControls;
}

/**
 * Show, hide and re-label the controls for the current run.
 *
 * Called from the same place that drives the composer bar. `view.visible:false`
 * removes the block entirely: a finished run should not leave three dead
 * buttons under the question.
 */
export function renderRunControls(view) {
    const host = queryParticipants ? queryParticipants.parentNode : null;
    if (!host || !view || view.visible !== true) {
        if (runControls && runControls.box.parentNode) {
            runControls.box.remove();
        }
        runControls = null;

        return;
    }
    const controls = runControls || buildRunControls(host);
    if (controls.box.parentNode !== host) {
        host.append(controls.box);
    }
    runControlHandlers = {
        pause: typeof view.onPause === 'function' ? view.onPause : null,
        reject: typeof view.onReject === 'function' ? view.onReject : null,
        finalize: typeof view.onFinalize === 'function' ? view.onFinalize : null,
    };
    controls.pause.textContent = typeof view.pauseLabel === 'string' && view.pauseLabel !== '' ? view.pauseLabel : 'Pause';
    controls.pause.disabled = view.pauseEnabled !== true;
    controls.reject.textContent = typeof view.rejectLabel === 'string' && view.rejectLabel !== '' ? view.rejectLabel : 'Reject';
    controls.reject.disabled = view.rejectEnabled !== true;
    controls.finalize.textContent = typeof view.finalizeLabel === 'string' && view.finalizeLabel !== ''
        ? view.finalizeLabel
        : 'Stop and Run consensus';
    controls.finalize.disabled = view.finalizeEnabled !== true;
}

// ---------- economy advice (R10) ----------
//
// What the run is likely to cost and take, and where the cheap path would pay
// off. It lives inside the question card because that is where the operator is
// looking while the decision is still open, and the same block carries the
// outcome once PIPELINE_DONE lands, so estimate and fact sit together.
let queryEconomy = null;

/** Trim a provider price to something readable without pretending to precision. */
function money(value) {
    if (typeof value !== 'number' || !Number.isFinite(value)) return null;
    return value.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
}

function economyBlock(lines, head = 'Cost and speed') {
    const children = [];
    if (head !== null) {
        children.push(el('p', { class: 'oc-query-economy-head' }, [head]));
    }
    children.push(el('ul', { class: 'oc-query-economy-list' }, lines
        .filter((line) => typeof line === 'string' && line !== '')
        .map((line) => el('li', { class: 'oc-query-economy-line' }, [line]))));
    return el('div', { class: 'oc-query-economy' }, children);
}

/**
 * Render (or refresh) the advice under the participants.
 *
 * With economy mode off the server answers `advice: false` and nothing is
 * rendered at all - the block never becomes a place where a switched-off
 * feature leaves a trace.
 */
export function updateQueryEconomy(advice) {
    if (!queryParticipants || !advice || typeof advice !== 'object' || advice.advice !== true) return;

    const lines = [];
    const rec = advice.recommendation;
    if (rec && typeof rec === 'object' && typeof rec.reason === 'string' && rec.reason !== '') {
        lines.push((rec.recommend === 'batch' ? 'The batch queue suits this run: ' : 'Keep it synchronous: ') + rec.reason);
    }

    const est = advice.estimate;
    if (est && typeof est === 'object') {
        const low = money(est.cost_low);
        const high = money(est.cost_high);
        const cost = low !== null && high !== null ? low + ' - ' + high : 'no price recorded';
        lines.push('Estimate, not a quote: cost ' + cost
            + ', about ' + est.eta_low_s + '-' + est.eta_high_s + ' s'
            + (typeof est.notes === 'string' && est.notes !== '' ? '. ' + est.notes : ''));
    } else if (typeof advice.note === 'string' && advice.note !== '') {
        lines.push(advice.note);
    }

    // A comparison against zero would read as "the batch path is free": without a
    // recorded price there is nothing to compare, and the money note above already
    // says so.
    if (advice.batch && Number(advice.batch.low) > 0 && Number(advice.batch.high) > 0) {
        lines.push('Through the Batch API: about ' + money(advice.batch.low) + ' - ' + money(advice.batch.high)
            + '. ' + (typeof advice.batch.note === 'string' ? advice.batch.note : ''));
    }

    // R1 - the ceiling is a warning, not a gate: the run is already under way,
    // and what the operator needs is the fact and the way out.
    if (advice.budget && advice.budget.exceeded === true) {
        lines.push('Over your per-run ceiling: the high end of the estimate is ' + advice.budget.estimated_high
            + ' against a limit of ' + advice.budget.limit + '. ' + (advice.budget.note || ''));
    }

    for (const row of Array.isArray(advice.cache) ? advice.cache : []) {
        if (row && row.advice === 'drop' && typeof row.reason === 'string' && row.reason !== '') {
            lines.push('Caching on ' + row.model + ': ' + row.reason);
        }
    }

    if (lines.length === 0) return;

    const fresh = economyBlock(lines);
    const parent = queryParticipants.parentNode;
    if (parent) {
        if (queryEconomy && queryEconomy.parentNode) {
            parent.insertBefore(fresh, queryEconomy);
            queryEconomy.remove();
        } else {
            // append, not insertBefore(nextSibling): the participants block is the last child of the card, and the DOM stub the harnesses drive does not promise nextSibling as an insertion reference.
            parent.append(fresh);
        }
        queryEconomy = fresh;
    }
}

/**
 * The outcome, once the run has actually finished.
 *
 * Only what the engine reported: cost when the provider gave one, tokens, and
 * how many of them came from the cache. Nothing here is extrapolated, and when
 * a figure was never reported the line says so instead of showing a zero.
 */
export function updateQueryEconomyResult(payload) {
    if (!queryEconomy || !queryEconomy.parentNode) return;
    const layers = payload && payload.stats && payload.stats.per_layer
        && typeof payload.stats.per_layer === 'object' ? payload.stats.per_layer : null;
    if (layers === null) return;

    let cost = 0; let priced = false; let tokens = 0; let cached = 0;
    for (const name of Object.keys(layers)) {
        const row = layers[name];
        if (!row || typeof row !== 'object') continue;
        if (typeof row.cost === 'number' && row.cost > 0) { cost += row.cost; priced = true; }
        if (typeof row.total_tokens === 'number') { tokens += row.total_tokens; }
        if (typeof row.cached_tokens === 'number') { cached += row.cached_tokens; }
    }
    if (tokens === 0 && priced === false) return;

    queryEconomy.append(economyBlock([
        'Ran: ' + (priced ? 'cost ' + money(cost) : 'the provider reported no price')
        + (tokens > 0 ? ', ' + tokens + ' tokens' : '')
        + (cached > 0 ? ', ' + cached + ' served from cache' : ''),
    ], null));
}

export function renderQueryCard(query, info = null, files = null) {
    const root = container();
    if (!root) return null;
    const text = query == null ? '' : String(query);

    const body = el('div', { class: 'oc-query-text' }, [text]);
    const header = el('header', { class: 'oc-step-header oc-query-header' }, [
        el('span', { class: 'oc-query-badge', 'aria-hidden': 'true' }, ['?']),
        el('span', { class: 'oc-step-label oc-query-title' }, ['Your question']),
    ]);

    // Same pair as every other card, heading included, so a pasted query
    // says where it came from. The pair is skipped by addCopyButtons when
    // the question is empty — a card with nothing to copy gets no buttons.
    addCopyButtons(header, {
        text,
        source: body,
        heading: 'Your question',
        level: 3,
        label: 'Copy your question to clipboard, exactly as sent',
        mdLabel: 'Copy your question as a Markdown snippet (card heading added, text unchanged)',
    });

    const participants = info ? participantsBlock(info) : null;
    // v1.9.x - the documents the question carried: names only, and only when
    // there are any. The contents belong to the run, not to the card.
    const filesLine = Array.isArray(files) && files.length > 0
        ? el('div', { class: 'oc-query-files', text: 'Files: ' + files.map(String).join(', ') })
        : null;
    const article = el('article', {
        class: 'oc-step oc-step-query',
        dataset: { kind: 'query', status: 'completed' },
    }, [
        header,
        // Same pane wrapper as a step body, so the padding and the
        // pre-wrap behaviour are shared rather than re-stated.
        el('div', { class: 'oc-tab-pane' }, [body]),
        filesLine,
        // Who runs, and who arbitrates - under the question itself.
        participants,
    ]);

    // Remembered so the L0_OPTIONS handler can replace "as requested" with the
    // composition the run actually got.
    queryParticipants = participants;
    queryRequestedModels = info ? participantRows(info.models) : [];
    // A new question starts without the previous run's cost block: keeping the
    // old node would let the next update insert into a card it no longer
    // belongs to.
    queryEconomy = null;

    appendBeforeActivityTail(root, article);
    return article;
}

// ---------- Final Answer (separate, visually distinguished) ----------

// The final answer is the block people come back for, and on a run with a
// few long L1 answers it sits well below the fold. A floating control
// therefore appears while the answer is off screen and disappears the
// moment the answer itself is in view: a jump link offered while its target
// is already visible is noise, and one that hides the target's own text is
// worse.
//
// Visibility comes from an IntersectionObserver on the final-answer
// container — never from a scroll handler. The observer fires exactly when
// the answer enters or leaves the viewport (whatever the scroll speed, and
// with no main-thread work in between), which is also what makes it correct
// when the page is scrolled by something other than a wheel: an anchor
// jump, a keyboard, a resize, or the browser restoring a scroll position.
const JUMP_ID = 'oc-jump-final';
const JUMP_LABEL = 'Final answer';

/** The single floating control, or null when there is no document to hold it. */
let jumpButton = null;

/** The live observer, kept so it can be disconnected when the run resets. */
let finalAnswerObserver = null;

function jumpButtonNode() {
    if (typeof document === 'undefined' || !document.body) return null;
    // Reuse the node when it is still attached (a re-run re-uses it rather
    // than stacking a second control), and rebuild it when it was detached
    // with the page's furniture.
    if (jumpButton && document.body.contains(jumpButton)) return jumpButton;

    const node = el('button', {
        type: 'button',
        id: JUMP_ID,
        class: 'oc-jump-final',
        hidden: true,
        'aria-label': 'Scroll to the final answer',
        title: 'Jump to the final answer at the end of the run.',
    }, [
        el('span', { class: 'oc-jump-final-glyph', 'aria-hidden': 'true' }, ['↓']),
        el('span', { class: 'oc-jump-final-text' }, [JUMP_LABEL]),
    ]);
    node.addEventListener('click', () => {
        const host = finalAnswerHost();
        if (!host) return;
        // `scroll-margin-top` on the section is what keeps the heading out
        // from under the pinned header (v1.8.60 — the header no longer
        // scrolls away, so the section reserves the header's height plus its
        // old breathing gap). Users who ask for reduced motion get an
        // instant jump instead of a glide.
        const media = typeof window !== 'undefined' && typeof window.matchMedia === 'function'
            ? window.matchMedia('(prefers-reduced-motion: reduce)')
            : null;
        host.scrollIntoView({
            behavior: media && media.matches ? 'auto' : 'smooth',
            block: 'start',
        });
    });
    document.body.append(node);
    jumpButton = node;
    return jumpButton;
}

function hideJumpToFinalAnswer() {
    if (finalAnswerObserver) {
        finalAnswerObserver.disconnect();
        finalAnswerObserver = null;
    }
    const node = jumpButton && document.body && document.body.contains(jumpButton) ? jumpButton : null;
    if (node) node.hidden = true;
}

/**
 * Start (or restart) watching the final-answer container: while it is off
 * screen the jump control is offered, while it is on screen it is not.
 *
 * Called from renderFinalAnswer only, so "no answer rendered" and "no
 * button" are the same state by construction — a run that is still going,
 * or one that failed, never has one.
 */
function watchFinalAnswer() {
    const host = finalAnswerHost();
    const button = jumpButtonNode();
    if (!host || !button) return;

    if (typeof IntersectionObserver !== 'function') {
        // No observer available (a very old browser, a non-browser DOM):
        // offer the control unconditionally. It is a link — the worst case
        // is an affordance the user does not need, not a wrong statement
        // about the run.
        button.hidden = false;
        return;
    }

    if (finalAnswerObserver) finalAnswerObserver.disconnect();
    finalAnswerObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.target !== host) continue;
            // threshold 0: the first visible pixel of the answer is enough
            // to hide the control.
            button.hidden = !!entry.isIntersecting;
        }
    }, { threshold: 0 });
    // Optimistic first paint: until the observer's initial callback lands,
    // the control is available rather than missing. If the answer is
    // already on screen the callback hides it within the same frame.
    button.hidden = false;
    finalAnswerObserver.observe(host);
}

/**
 * A piece of the final answer, as it is generated.
 *
 * Rendered into the same host the finished answer will use, inside an element
 * the final render replaces: the operator sees the answer being written, and
 * when L4_SYNTHESIS arrives renderFinalAnswer() wipes the host and paints the
 * real pane - with its copy buttons, stats and the math pass - exactly as it
 * did before streaming existed. Math and diagrams are deliberately NOT rendered
 * per delta: re-running KaTeX on every token flickers and wastes the work.
 *
 * @param {string} text
 */
export function renderFinalAnswerDelta(text) {
    if (typeof text !== 'string' || text === '') return;
    const host = finalAnswerHost();
    if (!host) return;
    host.hidden = false;
    let pane = host.querySelector('.oc-final-answer-stream');
    if (!pane) {
        pane = el('div', { class: 'oc-final-answer-text oc-final-answer-stream' });
        host.replaceChildren(pane);
    }
    pane.append(document.createTextNode(text));
}
export function renderFinalAnswer({ text, stats, raw }) {
    const host = finalAnswerHost();
    if (!host) return null;
    host.hidden = false;
    host.replaceChildren();

    const responsePane = el('div', { class: 'oc-final-answer-text' }, [text || '(no answer)']);
    const statsPane = statsList(stats || {});
    const rawPane   = el('pre', { class: 'oc-pre' }, [JSON.stringify(raw ?? {}, null, 2)]);

    const header = el('header', { class: 'oc-final-answer-header' }, [
        el('span', { class: 'oc-final-answer-badge' }, ['Σ']),
        el('h2', { class: 'oc-final-answer-title' }, ['Final Answer']),
        el('span', { class: 'oc-final-answer-sub' }, ['L4 synthesis']),
    ]);

    // The final answer is the one block users most often want out of the
    // app, so it gets the same pair as every other block. Copying the
    // answer text only would make it the most mis-quoted block on the
    // page, hence the explicit label and a snippet heading that matches
    // the one History uses. `.oc-final-answer-title` is flex:1, which
    // parks the buttons at the right edge of the header.
    addCopyButtons(header, {
        text,
        source: responsePane,
        heading: 'Final answer',
        level: 1,
        label: 'Copy the final answer to clipboard, exactly as shown',
        mdLabel: 'Copy the final answer as a Markdown snippet (# heading added, text unchanged)',
    });

    const article = el('article', { class: 'oc-final-answer', dataset: { layer: 'final' } }, [
        header,
        tabbed([
            ['response', 'Answer',     responsePane],
            ['stats',    'Statistics', statsPane],
            ['raw',      'Raw',        rawPane],
        ]),
    ]);
    host.append(article);
    // The answer exists now, so the jump control has a target. Note what
    // is *not* here: no collapse toggle. The final answer is the one body
    // that is always shown in full (see attachCollapseToggle's call site in
    // renderStep — it is a stage-card feature).
    watchFinalAnswer();
    return article;
}

// ---------- Live SSE driver ----------

// Map step_id -> DOM node so subsequent events can update an existing
// step in place. We deliberately replace only the body; the header/
// status badge updates reflect the latest event.
const liveSteps = new Map();
const seenRounds = new Set();

function metaFromEventPayload(type, payload) {
    const meta = {
        layer: typeof type === 'string' ? type.split('_')[0] : 'L?',
        timestamp: payload?.at ?? new Date().toISOString(),
    };
    if (payload && typeof payload === 'object') {
        for (const k of ['model', 'provider', 'prompt_tokens', 'completion_tokens',
                         'total_tokens', 'latency_ms', 'cost', 'cached_tokens',
                         'reasoning_tokens', 'generation_id', 'tokens_estimated',
                         'reasoning_text',
                         // Prompt cache (Bothub): read side rides on
                         // cached_tokens above, these two are the write side
                         // and the saving. `cache_stats` marks a payload
                         // that carried cache statistics at all, which is
                         // what separates "reported zero" from "no data".
                         'cache_write_tokens', 'cache_discount', 'cache_stats']) {
            if (payload[k] !== undefined) meta[k] = payload[k];
        }
    }
    return meta;
}

function ensureStep(domKey, base) {
    const existing = liveSteps.get(domKey);
    if (existing && existing.isConnected) return existing;
    // Defensive: a previous run may have left an orphan article carrying
    // the same dom_key in the DOM. Remove it before creating a fresh
    // node so no stale "Awaiting model response…" placeholder remains.
    const root = container();
    if (root) {
        for (const orphan of root.querySelectorAll(`article[data-key="${cssEscape(domKey)}"]`)) {
            orphan.remove();
        }
    }
    const article = renderStep({ ...base, dom_key: domKey });
    if (article) liveSteps.set(domKey, article);
    return article;
}

// Tiny CSS.escape polyfill for environments without window.CSS.escape.
// Keeps dom_key lookup robust against punctuation in step ids.
function cssEscape(s) {
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') return CSS.escape(s);
    return String(s).replace(/[^a-zA-Z0-9_-]/g, (c) => '\\' + c);
}

// Resolve the round number for this event. Backend may stamp the
// payload with `round` or `meta.round`; otherwise we imply round 1.
function resolveRound(evt) {
    const p = evt?.payload ?? {};
    const r = p?.cycle ?? p?.round ?? p?.meta?.round ?? evt?.round ?? 1;
    const n = Number(r);
    return Number.isFinite(n) && n > 0 ? Math.floor(n) : 1;
}

// Resolve the cycle limit from the event payload (added v1.8.19). Falls
// back to null when unknown so ensureRoundHeader can render just the
// cycle number.
function resolveCycleLimit(evt) {
    const p = evt?.payload ?? {};
    const c = p?.cycle_limit ?? p?.max_rounds ?? null;
    const n = Number(c);
    return Number.isFinite(n) && n > 0 ? Math.floor(n) : null;
}

/**
 * Update / create a step from a live SSE event.
 *
 * `evt` shape: { type, step_id, at, payload }
 * `statsFromMeta` is injected from app.js so we don't duplicate the
 * dash/format logic across modules.
 */
export function upsertStepFromEvent(evt, statsFromMeta) {
    const type = evt?.type;
    const payload = evt?.payload ?? {};
    const stepId = evt?.step_id ?? null;

    // Update the persistent activity tail card for *every* event.
    const isTerminal = type === 'PIPELINE_DONE' || type === 'PIPELINE_FATAL';
    // The batch equivalents of the L1 refusal events are failures of the
    // same kind: a pool that cannot be batched and a batch nothing answered
    // are both terminal-for-this-run conditions, not progress.
    const isFailure  = type === 'PIPELINE_FATAL' || type === 'L4_FAILED'
        || type === 'L1_NO_AVAILABLE_MODELS' || type === 'L1_NO_RESULTS' || type === 'LOOP_DETECTED'
        || type === 'BATCH_L1_NO_RESULTS' || type === 'BATCH_L1_NO_AVAILABLE_MODELS';
    setActivity({
        state: isTerminal ? (type === 'PIPELINE_FATAL' ? 'failed' : 'completed') : (isFailure ? 'failed' : 'running'),
        label: humanActivityLabel(type, payload),
        stage: typeof type === 'string' ? type.split('_')[0] : '—',
    });

    // Ensure round header exists before rendering pipeline-stage events.
    const round = resolveRound(evt);
    const cycleLimit = resolveCycleLimit(evt);
    if (!seenRounds.has(round) && type !== 'PIPELINE_DONE' && type !== 'PIPELINE_FATAL') {
        ensureRoundHeader(round, cycleLimit);
        seenRounds.add(round);
    } else if (cycleLimit != null) {
        // Backfill the limit on the existing header once the first event
        // carrying cycle_limit lands (CONSENSUS_ROUND_STARTED is emitted
        // before L1_STEP_STARTED so this is almost always a no-op).
        ensureRoundHeader(round, cycleLimit);
    }

    // Session-level events with no step_id render as their own card.
    const sessionLevel = (
        type === 'L0_SKILL' || type === 'L0_OPTIONS'
        || type === 'L1_NO_AVAILABLE_MODELS' || type === 'L1_NO_RESULTS'
        || type === 'BATCH_L1_NO_AVAILABLE_MODELS' || type === 'BATCH_L1_NO_RESULTS'
        || type === 'PIPELINE_FATAL' || type === 'LOOP_DETECTED'
        || type === 'CONSENSUS_CYCLE_LIMIT_REACHED'
        || type === 'CONSENSUS_CYCLE_LIMIT_CLAMPED'
        || type === 'CONSENSUS_STAGNATION_DETECTED'
        || type === 'CONSENSUS_MANUAL_FINALIZE_REQUESTED'
        || type === 'CONSENSUS_MANUAL_FINALIZE_STARTED'
        || type === 'PIPELINE_FINALIZING'
        || type === 'CONSENSUS_LOOP_GUARD_TRIGGERED'
        || type === 'PIPELINE_DUPLICATE_RUN_REJECTED'
    );
    if (sessionLevel) {
        // v1.8.22 — terminal failure events should also flip any
        // still-running step cards (typically L1) to failed, so the user
        // never sees an "Awaiting model response…" spinner stuck after
        // the pipeline has aborted.
        if (type === 'PIPELINE_FATAL' || type === 'LOOP_DETECTED' || type === 'CONSENSUS_LOOP_GUARD_TRIGGERED') {
            const reason = type === 'PIPELINE_FATAL'
                ? ('Pipeline aborted: ' + (payload?.message ?? 'fatal error'))
                : type === 'LOOP_DETECTED'
                    ? 'Pipeline halted by loop detection.'
                    : 'Pipeline aborted by the loop-invariant guard.';
            markStaleRunningStepsFailed(reason);
        }
        if (type === 'PIPELINE_DONE') {
            // R9 extension - who contributed what, under the final answer.
            renderFinalContribution(payload && payload.contribution);
            // R10 - the estimate promised under the question gets its outcome
            // next to it: the same figures the engine reported, never a
            // recomputation.
            updateQueryEconomyResult(payload ?? null);
        }
        if (type === 'L0_OPTIONS') {
            // The server has resolved the pool and named the arbiter: replace
            // what the card asked for with what the run actually got, and say
            // so when the two differ.
            updateQueryParticipants(payload ?? null);
        }
        if (type === 'L0_SKILL') {
            const domKey = 'l0-skill';
            const root = container();
            if (root) {
                for (const orphan of root.querySelectorAll(`article[data-key="${cssEscape(domKey)}"]`)) {
                    orphan.remove();
                }
            }
            liveSteps.delete(domKey);
            const statsMeta = metaFromEventPayload(type, payload);
            const node = renderStep({
                id: domKey,
                dom_key: domKey,
                layer: 'L0',
                label: 'L0 · Skill — ' + (payload?.name || payload?.slug || 'auto'),
                label_title: 'Skill resolved by L0 (router or auto-creator).',
                status: 'completed',
                response_text: '',
                skill_payload: payload,
                stats: statsFromMeta(statsMeta, 'completed'),
                raw: { event: evt },
            });
            if (node) liveSteps.set(domKey, node);
            return node;
        }
        const id = 'ev-' + type;
        const root = container();
        if (root) {
            for (const orphan of root.querySelectorAll(`article[data-key="${cssEscape(id)}"]`)) {
                orphan.remove();
            }
        }
        liveSteps.delete(id);
        const node = renderStep({
            id,
            dom_key: id,
            layer: typeof type === 'string' ? type.split('_')[0] : '—',
            label: type,
            label_title: (typeof type === 'string' && type in EVENT_TOOLTIPS) ? EVENT_TOOLTIPS[type] : type,
            status: type.endsWith('_FATAL') || type.endsWith('_FAILED')
                || type === 'L1_NO_AVAILABLE_MODELS' || type === 'L1_NO_RESULTS'
                || type === 'BATCH_L1_NO_AVAILABLE_MODELS' || type === 'BATCH_L1_NO_RESULTS'
                ? 'failed' : 'event',
            response_text: typeof payload === 'object' && payload !== null
                ? JSON.stringify(payload, null, 2) : String(payload ?? ''),
            stats: statsFromMeta(metaFromEventPayload(type, payload), 'event'),
            raw: { event: evt },
        });
        if (node) liveSteps.set(id, node);
        return node;
    }

    // PIPELINE_DONE handled by app.js for the final-answer card.
    if (type === 'PIPELINE_DONE') return null;

    // Step-scoped events.
    if (stepId == null) return null;
    const domKey = 'step-' + stepId;
    const layer = typeof type === 'string' ? type.split('_')[0] : 'L?';

    // A batch participant is in flight in a different sense from a live call:
    // the provider has the request and nobody is waiting on a socket. The
    // card says so, because "Awaiting model response…" on a card that will
    // not move for twenty minutes reads as a hang.
    if (type === 'BATCH_L1_STEP_SUBMITTED') {
        const article = ensureStep(domKey, {
            id: stepId,
            layer,
            label: 'L1 · ' + (payload?.role ?? 'role') + ' (batch)',
            label_title: `Submitted to the provider Batch API as custom_id "${payload?.custom_id ?? '—'}" `
                + `(model "${payload?.model ?? '—'}", provider "${payload?.provider ?? '—'}"). `
                + 'Results arrive asynchronously, in minutes to 24 hours.',
            status: 'running',
            response_text: 'Submitted to the provider Batch API — awaiting results (minutes to 24 hours).',
            stats: statsFromMeta({
                model: payload?.model, provider: payload?.provider,
                latency_ms: null,
            }, 'running'),
            raw: { event: evt },
        });
        return article;
    }

    if (type === 'L1_STEP_STARTED') {
        const article = ensureStep(domKey, {
            id: stepId,
            layer,
            label: 'L1 · ' + (payload?.role ?? 'role'),
            label_title: `Calling model "${payload?.model ?? '—'}" on provider "${payload?.provider ?? '—'}" for role "${payload?.role ?? '—'}".`,
            status: 'running',
            response_text: 'Awaiting model response…',
            stats: statsFromMeta({
                model: payload?.model, provider: payload?.provider,
                latency_ms: null,
            }, 'running'),
            raw: { event: evt },
        });
        return article;
    }

    if (type === 'L1_RESPONSE' || type === 'L1_FAILED' || type === 'L4_SYNTHESIS' || type === 'L4_FAILED' || type === 'L2_NORMALIZED' || type === 'L3_CONSENSUS') {
        // Build the new, fully-rendered article and replace any existing
        // node tied to this step_id.
        const status = (type.endsWith('_FAILED')) ? 'failed' : 'completed';
        const label = type === 'L4_SYNTHESIS' ? 'L4 · Synthesis'
            : type === 'L4_FAILED'    ? 'L4 · Synthesis (failed)'
            : type === 'L1_RESPONSE'  ? 'L1 · ' + (payload?.role ?? 'role')
            : type === 'L1_FAILED'    ? 'L1 · ' + (payload?.role ?? 'role') + ' (failed)'
            : type === 'L2_NORMALIZED'? 'L2 · ' + (payload?.role ?? 'role')
            : 'L3 · Consensus';
        // Render priority: full `content` (full model output / detailed
        // L2 explanation) > `summary` > `explanation` > `preview`. Fall
        // back to the placeholder only when the payload truly carries
        // nothing — completed steps now always include at least one of
        // these fields so the placeholder is reserved for transient
        // pre-completion frames.
        const responseText = type === 'L1_FAILED'
            ? (payload?.message ?? 'L1 call failed')
            : type === 'L4_FAILED'
                ? (payload?.message ?? payload?.detail ?? 'Synthesis failed')
                : (payload?.content
                    ?? payload?.summary
                    ?? payload?.explanation
                    ?? payload?.preview
                    ?? '(awaiting full response from session events)');
        const baseArgs = {
            id: stepId,
            dom_key: domKey,
            layer,
            label,
            label_title: EVENT_TOOLTIPS[type] ?? label,
            status,
            response_text: responseText,
            stats: statsFromMeta(metaFromEventPayload(type, payload), status),
            raw: { event: evt },
        };
        if (type === 'L3_CONSENSUS' && payload?.explanation) {
            baseArgs.l3_explanation = payload.explanation;
        }
        // Drop every prior article carrying this dom_key (the placeholder
        // emitted by L1_STEP_STARTED, plus any orphan from a previous
        // run that escaped clearPipeline). The fresh article rendered
        // below takes its place, guaranteeing one card per step_id.
        const root = container();
        if (root) {
            for (const orphan of root.querySelectorAll(`article[data-key="${cssEscape(domKey)}"]`)) {
                orphan.remove();
            }
        }
        liveSteps.delete(domKey);
        const node = renderStep(baseArgs);
        if (node) liveSteps.set(domKey, node);
        return node;
    }

    return null;
}

// v1.8.22 — flip any still-running pipeline step cards to "failed" with
// an explicit reason. Used when the SSE stream closes mid-pipeline
// (the user otherwise sees the spinner indefinitely on "Awaiting model
// response…"). Callers pass a short reason that appears inside the card
// body and in the status badge title.
export function markStaleRunningStepsFailed(reason) {
    const root = container();
    if (!root) return 0;
    let flipped = 0;
    const articles = root.querySelectorAll('article.oc-step[data-status="running"]');
    for (const article of articles) {
        article.dataset.status = 'failed';
        const badge = article.querySelector('.oc-step-status');
        if (badge) {
            badge.classList.remove('oc-status-running');
            badge.classList.add('oc-status-failed');
            badge.textContent = 'failed';
            badge.setAttribute('title', reason || 'Pipeline terminated before this step finished.');
        }
        const body = article.querySelector('.oc-step-response');
        if (body && (body.textContent || '').trim() === 'Awaiting model response…') {
            body.textContent = reason || 'Step did not complete before the pipeline stream closed.';
        }
        flipped++;
    }
    return flipped;
}

// Reset the live step registry whenever the pipeline section is cleared.
// v1.8.31: wrapped in try/catch and deferred until DOMContentLoaded when
// the import lands before the body has parsed. Any failure here used to
// abort module evaluation BEFORE the trailing `export function` bindings
// were observable to importers — browsers would then throw
// "does not provide an export named 'markStaleRunningStepsFailed'" at the
// app.js import site, even though the source code clearly exports it.
function bindPipelineRegistryObserver() {
    try {
        const observed = container();
        if (observed && typeof MutationObserver === 'function') {
            new MutationObserver(() => {
                for (const [k, node] of liveSteps) {
                    if (!node.isConnected) liveSteps.delete(k);
                }
            }).observe(observed, { childList: true });
        }
    } catch (_) { /* DOM unavailable — registry will self-heal lazily */ }
}
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindPipelineRegistryObserver, { once: true });
    } else {
        bindPipelineRegistryObserver();
    }
}
