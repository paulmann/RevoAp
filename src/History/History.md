# Module: History

## Purpose
Everything an operator needs *after* a run: the History list (filtering,
searching, badges), the per-session detail page, Markdown export, public share
documents, and the "Finish session" rebuild that completes a run whose worker
died mid-flight.

The rebuild lives here because it is a History-tab action, but it executes the
pipeline through `Pipeline\PipelineEngine::repairSession()`. The distinction
that matters: History decides *what is missing*, the engine decides *how to
produce it*.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| `HistoryService.php` | `App\History\HistoryService` | List/detail queries, filtering, full-text search, the History card metadata (`options_json`, `run_mode`). |
| `SessionCompletionService.php` | `App\History\SessionCompletionService` | Plans and executes the "Finish session" rebuild: what already exists, what is missing, then hands the gap to the engine. |
| `RunMarkdownExporter.php` | `App\History\RunMarkdownExporter` | Renders a finished run as Markdown, or as one self-contained printable HTML page. Pure: takes the detail array, returns a string. |
| `DocumentSlug.php` | `App\History\DocumentSlug` | Turns a block topic into a safe `share/<slug>.md` file name for the document viewer. |

Share documents are stored by `Storage\ShareRepository`; this directory only
builds their content and names.

## Public API
- `SessionCompletionService::complete(int $sessionId, bool $dryRun = false, bool $force = false): array`
  — the whole operation: `dryRun` returns the plan without touching anything.
- `HistoryService::list(array $params): array`, `HistoryService::detail(int $sessionId): ?array`.
- `RunMarkdownExporter::render(array $detail): string`,
  `::renderHtml(array $detail): string`, `::fileName(array $detail): string`,
  `::fileNameFor(array $detail, string $extension): string`.

## Invariants & Constraints
- **Append-only.** A rebuild appends steps and events; it never rewrites
  historical rows. A step left `running` by a dead process is terminalised
  (status + note), not deleted — see `SESSION_REPAIR_STALE_STEPS`.
- **"The run finished" is not "an answer exists."** A rebuild is reported as a
  failure when the engine's own terminal verdict says so, and the session is
  written `failed` rather than green.
- **The rebuild works from round 1.** Later consensus rounds depend on peer
  context a half-written journal cannot always reproduce; the extra rows are
  kept for audit and reported as unused.
- **A failed L4 step poisons the stored answer.** A session row with a
  `final_answer` is *not* evidence that a synthesis exists — the failure flag
  wins, and the question is re-asked rather than reused.
- **History must not invent a verdict.** A run that deliberately computed no
  consensus (`consensus_status = 'single'`, or a journal whose L3 **and** L4 are
  `skipped` while L1 answered) is recognised as *such* and never scheduled an
  L3 — scoring one answer against nothing would recreate exactly the trivial
  `1.0 / agreement` a single-model run exists to avoid. See
  `SessionCompletionService::singleModelRun()`.
- **`consensus_score` stays NULL in that case**, including in the
  `session_snapshot` of a failed rebuild: `NULL` is "not measured", `0` would be
  "measured, and they disagreed completely".
- **No secrets** in a snapshot, an export or a share document.

## Integration Points
- Controller: `Http\Controllers\HistoryController` (list, detail, repair,
  export, share).
- Engine: `PipelineEngine::repairSession()` and
  `PipelineEngine::resolveTerminalVerdict()` — the rebuild reads the engine's
  returned `status` / `status_reason` / `status_details` instead of
  re-deriving a verdict from `synthesis_failure` alone.
- Storage: `SessionRepository` (including `options_json` via `setMetadata()`),
  `StepRepository`, `ResponseRepository`.
- The run mode recorded by the engine (`options_json.single_model`,
  `consensus_status`) is what makes a rebuilt single-model session
  distinguishable from a crashed consensus one.

## LLM Extension Guide
- A new stage the rebuild must be able to skip or resume is described in
  `buildContext()`'s plan (`l1_missing`, `l2_missing`, `l3`, `l4`) and executed
  in `PipelineEngine::repairSession()`. Keep the decision in one of the two
  places, never both.
- A new runtime option the rebuild has to preserve goes through
  `rebuildOptions()` — it reconstructs what `options_json` never stored
  (reasoning is a *description* there, the cycle limit only ever reached the
  `L0_OPTIONS` event).
- A run mode that skips stages needs a signal in the journal as well as on the
  session row: the row can be older than the mode, and the journal can be the
  only survivor.
