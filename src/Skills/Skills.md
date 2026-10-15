# Module: Skills

## Purpose
Defines the "skill" — a named consensus configuration consisting of a set
of role prompts and a synthesis strategy. Skills can be loaded from JSON
files, persisted in MySQL, routed by similarity, or generated on demand.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| Skill.php             | `App\Skills\Skill`             | Domain entity (readonly). |
| SkillLoader.php       | `App\Skills\SkillLoader`       | Reads JSON definitions from disk. |
| SkillRouter.php       | `App\Skills\SkillRouter`       | Token-overlap selection of best matching skill. |
| SkillAutoCreator.php  | `App\Skills\SkillAutoCreator`  | LLM-driven generation when no skill matches. |
| SkillRepository.php   | `App\Skills\SkillRepository`   | PDO-backed CRUD; in-memory fallback. |
| definitions/*.json    | —                              | System skill catalog. |

## Public API
### Skill (readonly)
- `id: ?int`, `slug`, `name`, `description`, `definition: array`,
  `isSystem: bool`, `useCount: int`
- `roles(): array`, `synthesisPrompt(): string`, `toArray(): array`

### SkillLoader
- `loadAll(): Skill[]`
- `loadFile(string): ?Skill`

### SkillRouter
- `resolve(string $query): ?Skill` — null when no good match.

### SkillAutoCreator
- `create(string $query): Skill`

### SkillRepository
- `findBySlug(string): ?Skill`
- `findById(int): ?Skill`
- `all(): Skill[]`
- `save(Skill): Skill`
- `delete(int): void`
- `incrementUseCount(int): void`

## Invariants & Constraints
- `Skill::slug` and `Skill::name` are required (validated in constructor).
- `SkillRouter::resolve` returns null below similarity threshold (0.08)
  so callers can decide whether to auto-create.
- `SkillAutoCreator::create` always returns a usable skill — even when
  the generator LLM is unreachable (synthetic fallback).

## Integration Points
- Called by: `Pipeline\PipelineEngine`, `Http\Controllers\SkillController`.
- Calls: `Connectors\ConnectorRegistry`, `Storage` (via PDO).

## LLM Extension Guide
- New JSON skill definitions: drop into `definitions/`; pick a unique
  slug; populate `roles[*].key`, `roles[*].system`, and
  `synthesis_prompt`.
- Add an `output_schema` per role only when a strict JSON shape is
  required; otherwise leave it absent and the normalizer will use
  sentence-level claim extraction.
