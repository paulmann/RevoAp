# Module: Pipeline

## Purpose
Orchestrates the RevoAp 4-layer council pipeline (Skill → Distribute →
Normalize → Consensus → Synthesize). All persistence is append-only:
once a step or event is recorded, it is never modified.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| PipelineEngine.php | `App\Pipeline\PipelineEngine` | Runs L0–L4 forward-only with loop detection. |
| PipelineStep.php   | `App\Pipeline\PipelineStep`   | VO for a step (id, key, label, order, layer). |
| StepResult.php     | `App\Pipeline\StepResult`     | VO bundling step + its `ChatResponse[]` + summary. |
| PipelineEvent.php  | `App\Pipeline\PipelineEvent`  | VO for an entry in the immutable event log. |
| PipelinePlan.php   | `App\Pipeline\PipelinePlan`   | Expected step count per cycle, including the single-model shape. |
| ModelSubstitution.php | `App\Pipeline\ModelSubstitution` | Pre-flight replacement of a model the breaker reports as open. |

## Public API
### PipelineEngine
- `run(string $query, ?int $userId = null): array` — executes the entire
  pipeline and returns
  `['session_id', 'final_answer', 'consensus' => ConsensusResult, 'stats' => SessionStats, 'steps' => array]`.
  With `options.single_model === true` the run takes the single-model path instead
  (see below) and returns `'consensus' => null` plus `'run_mode' => 'single_model'`,
  because no verdict exists to report.
- `PipelineEngine::MODE_SINGLE_MODEL` — the run mode value carried by
  `PIPELINE_STARTED`, `L0_OPTIONS`, `PIPELINE_DONE` (`run_mode`) and by the
  engine's return array.
- `PipelineEngine::CONSENSUS_STATUS_SINGLE` — `'single'`, the verdict stored for a
  run that computed none.

## Invariants & Constraints
- Steps move forward only: L0 → L1 → L2 → L3 → L4.
- Events are append-only; failures append a new `*_FAILED` event rather
  than mutating existing rows.
- A loop is detected when the same `step_key` is observed twice; the
  pipeline records `LOOP_DETECTED` and halts cleanly.
- `step_key` prefixes drive layer attribution in stats:
  `L1.role.<key>`, `L2.normalize.<key>`, `L3.consensus`, `L4.synthesis`.
- When the synthesis connector is unavailable the engine still produces
  a deterministic fallback answer rather than throwing.
- L1 and L4 step rows store the **full** model response in
  `council_steps.summary` (MEDIUMTEXT). A short preview is kept under
  `meta.summary_preview` for compact list views. Truncating in the
  pipeline would silently lose audit data — clients that need a preview
  read it explicitly from `meta.summary_preview` (or apply their own
  `mb_substr`).
- L2 step rows store a human-readable normalization summary (e.g.
  `Normalized answer from role "questioner" into 14 text claims for
  consensus.`) plus the claims themselves under `meta.claims`, the count
  under `meta.claims_count`, and a `meta.note` flagging that L2 makes no
  LLM call. Earlier wording was `Normalized as text.`, which led
  operators to believe an LLM had returned a one-word answer.
- L3 step rows store a multi-line **consensus explanation** built by
  `App\Consensus\ConsensusExplainer`. It states (a) WHAT was evaluated —
  normalized claims from each L1 role, (b) WHO participated — role keys
  and count, (c) WHAT WAS DECIDED — status (`agreement` / `partial` /
  `conflict`), score, score percentage and pair count, and (d) the
  RESULT — `consensus reached`, `partial agreement`, or
  `no consensus (treat L4 output as fallback synthesis)`. The structured
  payload is also exposed under `meta.explanation` so the frontend can
  render the same block on live and loaded sessions, while the raw
  consensus JSON (`score`, `status`, `summary`, `meta`) remains under
  `meta.consensus` and inside the `L3_CONSENSUS` event payload for the
  Raw tab. Earlier the bare summary `Significant disagreement between
  roles.` left operators asking what was decided and what the result
  meant.
- `PipelineEngine::diagnoseConnectorError()` pattern-matches the most
  common chat-completions failures (404 / "no endpoints found", 401/403,
  429) and attaches an actionable `hint` to the `L1_FAILED` event so
  operators see *what to fix* instead of a raw HTTP error.

## Single-model mode (`options.single_model === true`)

One model answers with the resolved skill's own system prompt, and **that
answer is the final answer**. Nothing is compared, so no consensus is computed
and no consensus verdict is recorded.

- Exactly **one** L1 call is made. `layer1Distribute()` is reused with a
  one-entry pool, so that call keeps its cache lookup, retry/breaker policy and
  step/usage accounting. The system prompt is built by the same
  `resolveParticipantRole()` + `buildL1Messages()` path L1 uses for a council
  member — it is the skill's role prompt, not the synthesis prompt.
- **L2, L3 and L4 are never called.** Their step rows are created and closed as
  `skipped` so the journal says the stages were considered and deliberately not
  run; an absent row would be indistinguishable from a crash.
- `consensus_status = 'single'` with `consensus_score = NULL`. The one-answer
  branch of the consensus engines would otherwise report `1.0 / agreement`,
  which claims a comparison that never happened; and `0` would claim one that
  disagreed. Both are refused on purpose.
- The cycle limit does not apply (there is no second round), so none is resolved
  and no `CONSENSUS_CYCLE_LIMIT_CLAMPED` is emitted for it. A finalize request
  that arrives anyway is answered with `SINGLE_MODEL_FINALIZE_IGNORED`.
- The pool is respected, never reshaped: auto-routing and `ModelSubstitution`
  are disabled for the run (`lock_models` / `disable_auto_routing`), and the
  first usable entry of the *requested* pool is used. A model whose breaker is
  open therefore fails the run and says so instead of being swapped for another.
- An empty (or censored-to-empty) answer **fails the run** — `empty_final_answer`
  — through the same `resolveTerminalVerdict()` the consensus path uses. No
  fallback body and no second model call.
- The mode is only honoured when the database can carry the verdict. Without
  `db/migrations/022_single_model_consensus_status.sql` a non-strict MySQL would
  store `'single'` as `''`, so the engine emits `SINGLE_MODEL_MODE_UNAVAILABLE`
  and runs the consensus pipeline instead of finishing with a blank verdict.
- `SessionCompletionService` treats a `'single'` session (or one whose L3 *and*
  L4 are `skipped` with L1 answered) as "no consensus owed" rather than as an
  unfinished run, so History's "Finish session" can never schedule an L3 that
  would invent the trivial verdict the mode exists to avoid.
- Batch mode (`JobService::createBatchJob`) **refuses** the combination: a batch
  parks a fan-out at the provider, while single-model mode makes one direct call.

Events: `SINGLE_MODEL_MODE` (the mode, on the record before any call),
`SINGLE_MODEL_MODE_UNAVAILABLE` (schema cannot carry the verdict — the consensus
path ran), `SINGLE_MODEL_FINALIZE_IGNORED`, plus the shared `L4_SKIPPED` with
`reason = single_model` and the usual `L0_OPTIONS` / `PIPELINE_DONE`.

## Integration Points
- Called by: `Http\Controllers\CouncilController`, `Api\ApiV1Controller`,
  `Jobs\PipelineJobRunner`, `History\SessionCompletionService` (repair).
- Calls: `Connectors`, `Skills`, `Consensus`, `Stats`, `Storage`.
- Selected by: `options.single_model` — set by the composer checkbox
  (`assets/templates/chat.html`), by `CouncilController::extractRunOptions()`,
  by `JobService`/`PipelineJobRunner::buildEngineOptions()` and by
  `ApiV1Controller::buildRunOptions()` for `POST /v1/runs`.

## LLM Extension Guide
- New layers must be appended with a higher `order_index` and a unique
  `step_key`. Never reuse a key across layers.
- When extending `run()`, never modify previously-created step rows.
  Always create new ones and emit a new event.
- New event types should be UPPER_SNAKE_CASE with a stable schema in
  `payload` so the frontend can handle them additively. A new engine event must
  also be added to `knownTypes` in `assets/js/app.js` — the replay endpoint
  writes an explicit `event:` line per frame, and an unbound type is dropped by
  `EventSource` in silence (`tests/SseEventContractTest.php` guards this).
- A new run mode belongs in `PipelineEngine`'s mode constants and in the
  `run_mode` payload key, not as a flag hidden inside the consensus loop: the
  loop's stages exist to compare several answers, and a mode that skips some of
  them has to say so to the journal and to the client.
