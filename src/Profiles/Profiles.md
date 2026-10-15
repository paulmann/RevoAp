# App\Profiles

Per-user profile data + base system prompt assembly.

## Components

| Class | Purpose |
| --- | --- |
| `UserProfile` | Readonly DTO carrying email, phone, name, job, bio, addendum. |
| `UserProfileRepository` | PDO-backed CRUD for `users` + `user_profiles`. Falls back to in-memory when migration 009 has not been applied. |
| `SystemPromptAssembler` | Composes `base file → profile addendum → identity → role/skill prompt`. The base file lives at `prompts/system/default.md`. |

## Multi-user readiness

The schema is keyed by `external_id` (opaque, stable token). Today
every request resolves to the row `external_id = 'local'`. A future
auth layer attaches additional rows without DDL.

## Integration

`PipelineEngine::composeSystemPrompt()` routes every L1 / L1-round-2 /
synthesis / main-selected / arbiter call through the assembler. The
profile addendum is appended after the base file.
