# db/migrations — incremental migrations from 014 onward

> **`db/schema.sql` is the single source of truth for a fresh install.**
> This directory holds the incremental migrations that converge an
> *existing* deployment on the shape `db/schema.sql` declares, starting
> after the `001…013` chain was consolidated.

## What is in here

| file | tables it advances | `@table-version` |
|---|---|---|
| `014_memory_items.sql` | `memory_items` | `memory_items 1.0.0` |
| `015_api_keys.sql` | `api_keys` | `api_keys 1.0.0` |
| `016_webhooks.sql` | `webhooks`, `webhook_deliveries` | `webhooks 1.0.0`, `webhook_deliveries 1.0.0` |
| `017_worker_presence.sql` | `revoap_worker_presence` | `revoap_worker_presence 1.0.0` |
| `018_prompt_cache_stats.sql` | `council_model_responses` (+`cache_read_tokens`, `cache_write_tokens`, `cache_discount`) | `council_model_responses 1.1.0` |
| `019_response_cost.sql` | `council_model_responses` (+`cost`) | `council_model_responses 1.2.0` |
| `020_research_journal.sql` | `research_runs`, `research_seen` | `research_runs 1.0.0`, `research_seen 1.0.0` |
| `021_research_candidates.sql` | `research_runs` (+`candidates_json`) | `research_runs 1.1.0` |
| `022_single_model_consensus_status.sql` | `council_sessions` (`consensus_status` gains `'single'`) | `council_sessions 1.0.5` |

Every file is idempotent (`CREATE TABLE IF NOT EXISTS`), so applying one
twice is a no-op, and each carries the same
`-- @table-version <table> <semver>` annotation `db/schema.sql` uses, so
`MigrationManager` writes the matching version into `app_config` after a
successful apply.

`018` is the first file here that *alters* an existing table instead of
creating one: MySQL has no `ADD COLUMN IF NOT EXISTS`, so each of its three
columns is guarded by its own `information_schema.COLUMNS` probe. All three
are nullable, and `NULL` means "the provider reported no cache data" — a
reported zero is stored as `0`, which is what lets the UI say "no cache
statistics" instead of "the cache did not fire".

`022` *widens* a column instead of adding one. It re-declares the
`consensus_status` ENUM with one extra value, `'single'`, for a run that
deliberately asked a single model and computed no consensus at all. The probe
there matches on `COLUMN_TYPE LIKE '%single%'`, so a database created from
`db/schema.sql` (which already carries the wider ENUM) is left untouched,
and a database that never applies the file cannot receive the value — the
engine refuses to write a verdict the column does not accept, instead of
letting a non-strict MySQL store it as `''`.

**Every table a file here creates is also declared in `db/schema.sql`.**
That is not cosmetic: `SchemaManager::recreate()` (Settings → Database →
**ReCreate DB**) replays `db/schema.sql` and then *baselines* this
directory — it records each file as applied **without executing it**. If a
table existed only as a migration, the tracker would report that migration
as applied while the table was still absent. `tests/SmokeTest.php` asserts
the invariant for every file in this directory, and
`tests/VectorMemoryTest.php` asserts it for `memory_items` specifically.

The next free number is **`023`**.

## What happened to `001…013`

On 2026-08-26 (v1.8.40) the incremental chain `001_initial.sql` …
`013_job_audit_events.sql` was **consolidated into `db/schema.sql`** and
removed. `db/schema.sql` (`@schema-version 1.9.0`) now declares the final
state of everything that chain produced — including the job subsystem
(`revoap_jobs`, `revoap_job_events`) — as one idempotent
`CREATE TABLE IF NOT EXISTS` script, 20 tables in total. Reimporting it on a
fresh database reproduces those tables, so the `schema_migrations` tracker
has nothing from `001…013` left to apply.

Migrations `014…017` were added *after* that consolidation, which is why this
directory is no longer empty. The five tables they introduce (`memory_items`,
`api_keys`, `webhooks`, `webhook_deliveries`, `revoap_worker_presence`) are
also declared in `db/schema.sql`, so a fresh install gets them from the import
and an existing install gets them from the migration — including after a
**ReCreate DB**, which never executes this directory.

## `memory_items` was the exception (fixed)

Until the fix described below, `014_memory_items.sql` was the **only**
source of `memory_items`: `db/schema.sql` did not declare it. After a
fresh import or a **ReCreate DB** the table therefore did not exist while
`schema_migrations` claimed `014` had been applied, and
`App\Memory\PdoMemoryRepository::ensureTableReady()` degraded to
"no memory" mode with a `memory.repo.table_missing` warning pointing at
this file.

`memory_items` is now declared in `db/schema.sql` (same DDL as `014`,
`-- @table-version memory_items 1.0.0`), so the recreate path produces it
and `014` remains the convergence path for already-deployed databases.
`SchemaInspector` now expects the table (it is part of the
`@table-version` set parsed from `db/schema.sql`), so **Check DB** reports
it as missing on a database that lacks it instead of silently ignoring it.
No table reachable from this directory is migration-only any more.

## Policy

1. **`db/schema.sql` is the source of truth.** Every schema change starts
   there: edit the table, bump its `-- @table-version <table> <semver>`
   comment, and bump the top-of-file `-- @schema-version`.
2. **A migration here exists only when an already-deployed database must
   converge** on the new shape without a full `mysql < db/schema.sql`
   import. A change that only fresh installs need does not get one.
3. **Fresh installs / ReCreate DB** never consult this directory for table
   definitions — they get everything `db/schema.sql` declares (the ReCreate
   action also seeds `app_config` with the declared versions and baselines
   this directory). A migration is therefore a *second* declaration of the
   same shape, never the only one; see the invariant at the top of this
   file.

## How to add a new migration

1. Edit `db/schema.sql` first — add/alter the table AND bump its
   `@table-version` (and `@schema-version`).
2. Create `db/migrations/NNN_short_name.sql` (zero-padded, next number):
   - carry the **same** `-- @table-version <table> <new-version>`
     annotation as `db/schema.sql`, so `MigrationManager` writes the
     matching version into `app_config` after a successful apply;
   - be **idempotent** — guard every `CREATE TABLE` with
     `IF NOT EXISTS`, every `ALTER`/`CREATE INDEX` with an
     `information_schema` existence check, and every seed `INSERT`
     with `INSERT IGNORE`. `MigrationManager` additionally treats
     benign "already exists" / "duplicate column" / "duplicate key
     name" errors as a no-op, but do not rely on that alone.
3. Apply it through the admin Settings tab → Database maintenance →
   **Apply Migrations** (or the per-file Apply button). The tracker
   records the filename in `schema_migrations`; the annotation is
   upserted into `app_config`.
4. **Do not** reintroduce consolidated tables (`001…013`) as new
   migration files — `db/schema.sql` already carries them.

## How the tracker walks this directory

`App\Settings\MigrationManager` enumerates the directory with
`glob('*.sql')` + `sort()` and consults the `schema_migrations` table, so it
picks up whatever is here without any registration step:

- `list()` → one row per file (`name`, `size`, `applied`, the parsed
  `tables` map and `schema_version`), sorted by filename;
- `applyPending()` → applies every file not yet recorded, skipping the ones
  already applied;
- `baseline()` → records every file as applied **without executing it**
  (used by `SchemaManager::recreate()` after it replays `db/schema.sql`);
- `applyOneByName($name)` → runs a single file, or returns
  `invalid_filename` for a name that does not match the expected pattern.

The directory is never assumed to be empty, so adding or removing a file
changes what `list()` / `applyPending()` / `baseline()` see immediately. The
prose in this file is the only thing that has to be kept in sync by hand.
