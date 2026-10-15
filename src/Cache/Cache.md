# Module: Cache

## Purpose
Persistent cache for successful LLM chat responses. Hits short-circuit
the network call entirely and surface as `cache_hit=true` in the
pipeline events.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| ModelResponseCache.php | `App\Cache\ModelResponseCache` | sha256-keyed cache with DB + filesystem backends. |

## Public API
### ModelResponseCache::buildKey()
- **Input**: `provider`, `model`, `messages`, `options`
- **Output**: `array{key:string, short_key:string}` — full sha256 key
  (used internally for storage) and the user-facing short key
  `sha256(model + last user message)` for UI tooltips.

### ModelResponseCache::lookup()
- **Output**: `null` (miss/disabled/expired) or
  `array{response:ChatResponse, key, short_key, hits, created_at}`.

### ModelResponseCache::store()
- Caches a successful `ChatResponse`. Best-effort — failures are logged
  but never thrown. Returns `array{stored:bool, key, short_key}`.

### ModelResponseCache::recordHit()
- Increments the hit counter for a key (DB only). Best-effort.

## Invariants & Constraints
- Failed responses are NEVER cached (call site responsibility).
- API keys / Authorization headers are NEVER part of the key or value.
- Backend errors degrade gracefully — the caller falls back to a
  network call.
- The full sha256 key is the source of truth for collisions; the short
  key is for human-readable display only.

## Integration Points
- Called by: `App\Pipeline\PipelineEngine` (L1 and L4 dispatch sites).
- Calls: `App\Core\Database` (when available), filesystem.

## LLM Extension Guide
- New cache key inputs: extend `buildKey()` and bump `VERSION`. The
  existing rows will become misses on the new version, which is the
  desired safety behaviour.
- New backends: extend `dbStore` / `dbLookup` or `fileStore` /
  `fileLookup`. Both must remain best-effort and degrade silently.
- Do NOT cache failures or partial responses; the contract is binary.
