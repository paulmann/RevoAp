# Vector memory (Task 5)

Cross-session semantic memory for the RevoAp pipeline. Knowledge from
earlier conversations is stored as embeddings and recalled into the
system prompt of later runs, so long-lived context survives between
sessions without re-querying the source documents.

## Components

| Component | File | Role |
|---|---|---|
| `EmbeddingMemory` | `src/Memory/EmbeddingMemory.php` | Facade: store/recall/degrade-guarantees for the whole stack. |
| `ConnectorMemoryEmbedder` | `src/Memory/ConnectorMemoryEmbedder.php` | Text→vector embedding via the connector registry (model-driven). |
| `MemoryEmbedderInterface` | `src/Memory/MemoryEmbedderInterface.php` | Embedder contract (`embed()`, `isAvailable()`). |
| `PdoMemoryRepository` | `src/Memory/PdoMemoryRepository.php` | Durable `memory_items` rows; degraded in-process fallback when the table is absent. |
| `MemoryItem` | `src/Memory/MemoryItem.php` | Value object for one memory row. |
| `VectorStore` | `src/Storage/VectorStore.php` | Default (`mysql`) vector driver: JSON columns in `vector_embeddings`, cosine in PHP. |
| `VectorStoreFactory` | `src/Storage/Vector/VectorStoreFactory.php` | Builds the configured driver (`mysql` / `qdrant`), falls back to `mysql`. |
| `QdrantVectorStore` | `src/Storage/Vector/QdrantVectorStore.php` | Optional Qdrant driver over ext-curl; degrades to "no memory" when curl is absent or unreachable. |
| `bin/vector-memory-bench.php` | benchmark CLI | Offline throughput measurement (no DB / network). |
| `bin/memory-retention.php` | retention CLI | Deletes `memory_items` older than N days (cron). |

## Configuration

- `pipeline.memory_top_k` - cross-session recall gate. `0` (default)
  keeps legacy behaviour: memory is never recalled into prompts and
  never stored. `> 0` passes the value as the recall `limit`.
- `vector.driver` - `mysql` (default) or `qdrant`.
- `memory.retention_days` - default retention window for the CLI
  (default 90).
- `skills.vector_boost` - float weight for the vector boost in
  `SkillRouter` (default 0.25).

Any failure inside the stack (embedder down, DB gone, driver
unreachable) degrades to "no memory" and is logged with
`Logger::warn` - it never breaks pipeline execution.

## Pipeline integration

`PipelineEngine` owns the optional facade (`setMemory()`), wired in
`index.php` and `bin/revoap-worker.php` via `VectorStoreFactory` +
`ConnectorMemoryEmbedder` + `PdoMemoryRepository`:

1. `buildL1Messages` recalls cross-session context
   (`recallContextGlobal`, top-k = `pipeline.memory_top_k`) and injects
   it as the `MEMORY CONTEXT` block of the system prompt.
2. After a successful L4 final answer, `storeMemoryContext()` records
   the query + final answer for future sessions.

Both hooks are no-ops when `pipeline.memory_top_k <= 0`.

## Storage

- `memory_items` - declared in `db/schema.sql`
  (`CREATE TABLE IF NOT EXISTS`, `@table-version memory_items 1.0.0`,
  session + type indexes) and shipped incrementally as the idempotent
  `db/migrations/014_memory_items.sql`, which carries the identical DDL.
  A fresh install, a re-import and **Settings → Database → ReCreate DB**
  all produce the table; `014` exists to converge an already-deployed
  database without a full import, not as its only source.
- `vector_embeddings` - existing table reused by the `mysql` driver
  (`collection` / `source_id` / `vector` JSON / `metadata` JSON); the
  interface-level `id` maps to `source_id`, no schema change needed.
- `SkillSearch` - facade over the same store for the `skills`
  collection; `SkillRouter` adds a vector boost behind
  `skills.vector_boost`.

## Measured thresholds (PHP 8.5 CLI, in-memory driver)

`php bin/vector-memory-bench.php 1000,5000,20000 64 100`

| items | upsert | per-search (64-d) | per-search (1536-d) |
|---|---|---|---|
| 1 000 | 0.014 s | 5.9 ms | 122.7 ms |
| 5 000 | 0.067 s | 31.5 ms | 621.9 ms |
| 20 000 | 0.276 s | 135.7 ms | 2640.8 ms |

Notes:
- The `mysql` driver performs exact cosine over all rows - search cost
  is linear in collection size; use it up to a few tens of thousands
  of items.
- Real embedding models emit 1536-dim vectors. The default
  `memory_limit=128M` is EXHAUSTED by a 5 000×1536 in-memory
  collection; raise `memory_limit` to at least 512M (or switch
  `vector.driver=qdrant`) before storing >5k realistic vectors.
- `top1 precise` stayed `yes` at every measured size.

## Operations

### Retention

```
php bin/memory-retention.php --status            # dry-run report
php bin/memory-retention.php --yes --days=90     # delete older than 90 days
```

- Cron suggestion: `30 3 * * * /usr/bin/php /var/www/revoap.com/bin/memory-retention.php --yes`
- Auth: the configured admin token (env var per `admin.token_env`,
  `.admin.key`, or `admin.token` config) presented via `--token=` or
  the env var; verified with `AdminAuth::verifyToken()`
  (`hash_equals`). The token is never echoed.
- Exit codes: 0 ok · 1 generic · 2 web-context · 3 unauthorized ·
  4 no database · 5 invalid window.

### Server checklist (Task 5)

1. Get the table onto the server: re-import `db/schema.sql`, or run the
   migration once (`mysql -u ... < db/migrations/014_memory_items.sql`),
   then confirm `SHOW TABLES LIKE 'memory_items'`. Both paths are
   idempotent, so re-running either is safe.
2. Set `pipeline.memory_top_k` > 0 in the config overlay to engage recall/store.
3. Choose a driver: keep `mysql` for ≤20k items, or configure
   `vector.driver=qdrant` + credentials for larger collections.
4. Raise PHP memory_limit (FPM + CLI) when using 1536-dim vectors with
   >5k items.
5. Verify recall: submit a query, check the worker L1 debug output for
   the `MEMORY CONTEXT` block, submit a second related query and
   confirm the previous final answer appears in recall.

## Tests

- `tests/VectorMemoryTest.php` - serialization, normalization, cosine,
  empty collections, Qdrant degradation, factory fallback, migration.
- `tests/MemoryPipelineGateTest.php` - `memory_top_k` gate on/off,
  failing vector store degrades safely, no row loss.
- `tests/MemoryRetentionCliTest.php` - retention CLI auth contract
  (randomized token, exit codes, no echo), runs the real CLI via
  `proc_open`.
