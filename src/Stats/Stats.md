# Module: Stats

## Purpose
In-memory collection and aggregation of per-call ChatResponse statistics
into per-step, per-model, per-layer, and session-level summaries.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| TokenUsage.php       | `App\Stats\TokenUsage`       | Immutable VO for one (or summed) call. |
| SessionStats.php     | `App\Stats\SessionStats`     | Immutable VO for the full session summary. |
| StatsCollector.php   | `App\Stats\StatsCollector`   | Records ChatResponses against step keys. |
| StatsAggregator.php  | `App\Stats\StatsAggregator`  | Builds `SessionStats` from collected rows. |

## Public API
### TokenUsage (readonly)
- `model`, `provider`, `promptTokens`, `completionTokens`, `totalTokens`,
  `latencyMs`, `calls`
- `add(self): self` — returns a new merged usage.
- **Invariant**: `totalTokens === promptTokens + completionTokens`.

### StatsCollector
- `record(string $stepKey, ChatResponse $r, ?string $layer = null): void`
- `finish(): void`
- `getStepStats(string): TokenUsage[]`
- `getSessionStats(): SessionStats`

### StatsAggregator
- `aggregate(array $rows, float $totalLatencyMs): SessionStats`

### SessionStats (readonly)
- `totalTokens`, `totalLatencyMs`,
- `perModel: array<string,TokenUsage>`,
- `perLayer: array<string,TokenUsage>`,
- `avgTokensPerRole`, `mostEfficientModel`, `roleCount`.

## Invariants & Constraints
- Layer is inferred from `step_key` prefix (`L1.`, `L2.`, …).
- `roleCount` is the number of `L1.*` calls (≥ 1).
- `mostEfficientModel` is the model with the lowest `totalTokens/calls`.

## Integration Points
- Called by: `Pipeline\PipelineEngine`, `Http\Controllers\StatsController`.
- Calls: `Connectors\ChatResponse`.

## LLM Extension Guide
- Add cost tracking by extending `TokenUsage` with a `costUsd` field and
  computing it at record-time using a price table from config.
- Do not modify the `add()` semantics — downstream code relies on it
  being commutative and additive.
