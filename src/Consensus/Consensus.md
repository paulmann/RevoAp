# Module: Consensus

## Purpose
Computes the agreement level between role responses and exposes a
normalization step that coerces raw LLM output into structured form for
comparison.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| ConsensusEngine.php       | `App\Consensus\ConsensusEngine`       | Pairwise Jaccard scoring → `ConsensusResult`. |
| ConsensusResult.php       | `App\Consensus\ConsensusResult`       | Immutable VO with score/status/summary. |
| ConsensusExplainer.php    | `App\Consensus\ConsensusExplainer`    | Builds a human-readable explanation of an L3 result. |
| NormalizationService.php  | `App\Consensus\NormalizationService`  | JSON schema projection or sentence split. |

## Public API
### NormalizationService
- `normalize(string $rawContent, ?array $outputSchema = null): array`
  Returns `['shape' => 'json'|'text', 'data' => mixed, 'claims' => string[]]`.

### ConsensusEngine
- `compute(array $normalizedResponses): ConsensusResult`

### ConsensusResult (readonly)
- `score: float` in `[0,1]`
- `status: 'agreement'|'partial'|'conflict'|'single'`
- `summary: string`
- `meta: array`
- `ConsensusResult::STATUS_SINGLE` — `'single'`: **no comparison happened**.
  One model answered and its answer is the final answer (a single-model run,
  see `src/Pipeline/Pipeline.md`). A value object still needs a score, so it
  carries `0.0`; what reaches the database is `NULL`, because "not measured" is
  neither `0` ("measured, complete disagreement") nor the trivial `1.0` the
  one-response branch below would report. The caller that knows the run mode
  declares the `NULL` — the VO does not get to guess.

### ConsensusExplainer
- `explain(ConsensusResult $result, array $roleKeys = []): array`
  Returns a structured explanation:
  ```
  [
    'summary'      => string,            // raw one-line summary
    'text'         => string,            // multi-line human-readable text
    'what'         => string,            // what was evaluated
    'participants' => ['count'=>int,'roles'=>string[]],
    'decision'     => ['status'=>string,'score'=>float,'score_pct'=>int,'pairs'=>int,'label'=>string],
    'conclusion'   => string,            // result interpretation
    'raw'          => ConsensusResult::toArray(),
  ]
  ```
  `decision.label` collapses status into one of:
  `consensus reached`, `partial agreement`,
  `no consensus (treat L4 output as fallback synthesis)`. Used by
  `PipelineEngine` to populate the L3 step `summary` and `meta.explanation`,
  and embedded in the `L3_CONSENSUS` event payload.

## Invariants & Constraints
- `ConsensusResult::score` is always in `[0,1]`.
- Status thresholds: ≥0.85 agreement, ≥0.55 partial, otherwise conflict.
- A single response is treated as trivial agreement (score 1.0) **by the
  scoring engines** — that is the correct answer to "what does agreement mean
  when there is nothing to compare". It is exactly why a single-model run does
  not reach this point at all: it records `'single'` instead, so a trivial 1.0
  can never be mistaken for a measured verdict. Do not "fix" the engines to
  return `'single'` themselves: they are also used to score a genuine council
  that happened to lose all but one member, where the honest record is
  "one participant answered".
- No comparable claims → `conflict` with score `0.0`.
- Thresholds stay monotonic: agreement ≥ partial ≥ conflict; `'single'` is
  outside that scale, not below it.

## Integration Points
- Called by: `Pipeline\PipelineEngine`.
- Calls: `Helpers\JsonHelper`, `Helpers\Utf8Helper`.

## LLM Extension Guide
- To add semantic-similarity scoring, plug in an embedding-based
  comparator behind a new strategy class; do NOT modify
  `ConsensusEngine`'s public signature.
- Status thresholds may be tuned but must remain monotonic: agreement
  ≥ partial ≥ conflict.
