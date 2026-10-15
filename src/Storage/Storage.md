# Module: Storage

## Purpose
Repository implementations for the persistent state of the council
pipeline. Each repository accepts a nullable `PDO` so the application
can operate in-memory when no database is configured (useful for
tests and CI).

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| SessionRepository.php  | `App\Storage\SessionRepository`  | `council_sessions` CRUD. |
| StepRepository.php     | `App\Storage\StepRepository`     | `council_steps` + `pipeline_events`. |
| ResponseRepository.php | `App\Storage\ResponseRepository` | `council_model_responses`. |
| VectorStore.php        | `App\Storage\VectorStore`        | `vector_embeddings` upsert + cosine search. |

## Public API (selected)
### SessionRepository
- `create(int $skillId, string $query, ?int $userId = null): int`
- `find(int $id): ?array`
- `complete(int, string, ?float, string, int, int): void`
- `markFailed(int, int): void`

### StepRepository
- `createStep(int, string, string, int, ?array): int`
- `completeStep(int, string, ?string): void` — status transitions only.
- `appendEvent(int, ?int, string, ?array): int`
- `listStepsBySession(int): array`
- `listEventsBySession(int): array`

### ResponseRepository
- `record(int $stepId, ChatResponse, ?string $roleName = null, ?array $normalized = null): int`
- `listByStep(int): array`

### VectorStore
- `upsert(string $collection, int $sourceId, float[] $vector, array $metadata = []): void`
- `search(string $collection, float[] $query, int $topK = 10): array`
- `cosine(float[] $a, float[] $b): float` (static)

## Invariants & Constraints
- All SQL queries use prepared statements; no string concatenation.
- `pipeline_events` and `council_steps` are **append-only** in domain
  semantics: only `completeStep()` updates the status/summary fields,
  never the others.
- When `PDO === null` repositories store rows in private arrays — but
  data is lost at the end of the request. This is intentional and only
  used for non-production environments.

## Integration Points
- Called by: `Pipeline\PipelineEngine`, `Http\Controllers\*`.
- Calls: PDO + `Helpers\JsonHelper`.

## LLM Extension Guide
- Add new repositories as `final class` with a nullable `?PDO` param.
- Mirror the in-memory fallback so callers don't need to special-case.
- Never expose raw rows beyond the module boundary unless a controller
  is consuming them as plain JSON; the rest of the app should work with
  domain VOs.
