# Storage & Resilience

## Resilient PDO

`App\Core\Database` is a small wrapper around `PDO` that survives idle
disconnects. It is constructed by `App\Core\Application::database()`
from the same `config.database.*` values that used to drive the raw
PDO. Every repository now accepts either a `PDO` (legacy) or a
`Database`. When given a `Database`, every read/write goes through
`Database::run()`, which:

1. Executes the closure with the live PDO.
2. On `PDOException` whose message or `errorInfo` matches one of:
   - `2006` / `2013` driver codes,
   - "MySQL server has gone away",
   - "Lost connection ... during query",
   - "no connection",
   - "connection was killed",
   it tears the connection down and reconnects using the original DSN.
3. Re-runs the closure exactly once on the fresh connection.

Failures unrelated to a transport drop bubble up as before; the retry
is intentionally one-shot so a genuine outage surfaces a real fatal.

The wrapper records reconnect/retry counts in `Database::stats()` and
exposes the last error class/message via `Diagnostics`.

## When MySQL drops the connection

Long-running RevoAp pipelines can hold a PDO handle idle while
`curl_multi` waits on parallel L1 calls and the L4 synthesis call. If
MySQL's `wait_timeout` is shorter than the cumulative round-trip, the
next write will fail with the famous `SQLSTATE[HY000]: General error:
2006 MySQL server has gone away`. To minimise the chance:

- Raise `wait_timeout` for the application user (`SET GLOBAL wait_timeout=28800;` is MySQL's default; some shared hosts set 60s).
- Keep `pipeline.parallel_l1=true` (default) so L1 calls overlap rather than serialize.
- The wrapper's auto-retry handles the residual case transparently.

When the wrapper retries, the very first `PIPELINE_FATAL` from a single
gone-away event no longer lands in production: only when the *retry*
also fails will the pipeline abort.

## Pipeline fatal handling

If a fatal does escape, `PipelineEngine::finalizeRunningSteps()`:

1. Reads every step still in `running` for the active session.
2. Updates them to `failed` with the error message as the summary.
3. Emits an `L1_FAILED` / `L4_FAILED` / `STEP_FAILED` event per step.
4. Then emits the terminal `PIPELINE_FATAL`.

The UI replaces RUNNING cards with the failure summary; `EventSource`
closes on the terminal event so the browser does not auto-reconnect
into a re-run loop.

## Model response cache

`App\Cache\ModelResponseCache` (instantiated by `index.php` via
`fromConfig()`) caches successful chat responses keyed by
sha256(provider, model, normalized messages, reasoning, whitelisted
options). It uses two backends:

1. **Database** — the `model_response_cache` table defined in
   `db/schema.sql`. The table is also
   self-installed at runtime via `CREATE TABLE IF NOT EXISTS` so a
   forgotten migration does not break the feature.
2. **Filesystem fallback** — `var/cache/model-responses/<sha>.json`
   when the database write fails or no DB is configured.

Failed responses are NEVER cached. API keys are NEVER part of the key
or value. Cache hits are reported with `cache_hit=true`,
`cache_short_key=sha256(model+query)` (the user-facing checksum the
spec asked for) and `cache_key=full sha256` in:

- `L1_RESPONSE` and `L4_SYNTHESIS` SSE events,
- the `council_steps.meta` JSON,
- the per-step Statistics tab.

### Invalidation

- TTL: set `cache.model_responses.ttl_seconds` (0 = no TTL).
- Bulk: `TRUNCATE model_response_cache;` or `rm -rf var/cache/model-responses/`.
- Targeted: drop the row whose `cache_key` you want to evict.

## Migrations

`db/schema.sql` is the single source of truth — import it once and the
whole schema (including `model_response_cache`, `app_config`, the job
tables, etc.) is in place:

```sh
mysql -u <user> -p <db> < db/schema.sql
```

`db/migrations/` is **not** a stub any more: it holds the incremental
migrations `014…017` that converge an existing deployment on the current
shape (see `db/migrations/README.md` for the file list and the rule for
adding `018`). Every table those files create — `memory_items` included —
is also declared in `db/schema.sql`, so a fresh import (or
**Settings → Database → ReCreate DB**) produces the complete schema on its
own. The former per-feature migration chain `001…013` was consolidated
into `db/schema.sql`.

The `model_response_cache` table is created automatically on first use
when permissions allow `CREATE TABLE`; importing `db/schema.sql` is the
preferred path on managed hosts where DDL is restricted to a deploy
script.

### Schema verification tooling (`var/schema-verify/`)

`var/schema-verify/verify_schema.py` is the offline schema checker (no PHP
required — `php -l` cannot be relied on everywhere). `compare` parses
`db/schema.sql` and diffs it against the expected table state; `columns`
reproduces `SchemaParser::parseColumn()` in Python for hosts where PHP
cannot run.

It used to keep that expected state in `var/schema-verify/snapshot.json`, a
replay of the (now deleted) `001…013` chain. **That file was removed during
the v1.8.47 cleanup** — it still described `opencorum_jobs` /
`opencorum_job_events` (renamed to `revoap_*`) and knew nothing of
`api_keys`, `webhooks`, `webhook_deliveries`, `revoap_worker_presence`,
`schema_migrations` or `memory_items`, so it was stale on both its table set
and its column types. Nothing in the runtime or in this documentation read
it; only `verify_schema.py` referenced it, as `build`'s output path and as
`compare`'s optional argv. It could not be "refreshed" either, because
`build` replays `db/migrations/*.sql`, which now holds only `014…017` — a
rebuild produces a 5-table snapshot, a *narrower* and more misleading
artifact than the stale 14-table one.

Consequences of the removal, none of them a regression:

- `python verify_schema.py build [path]` still works and simply writes a new
  file at the given path (default: `var/schema-verify/snapshot.json`);
- the default `python verify_schema.py compare` never read the snapshot when
  `db/migrations/*.sql` exists — it prints
  `expected source: db/migrations/*.sql (live)` and uses those files;
- an explicit `compare var/schema-verify/snapshot.json` fails with a file
  error until a `build` run recreates it.

Note that the consolidation itself left the tool's `compare` mode comparing
against the *incremental* files only, so it now reports every premigration
table as `EXTRA table in schema.sql (not in migrations)`. That is a property
of the tool, not of this cleanup, and fixing it means teaching `compare` to
treat `db/schema.sql` as the baseline — out of scope here.

### Schema-version bookkeeping (v1.8.40+)

Every `CREATE TABLE` block in `db/schema.sql` is preceded by a
`-- @table-version <name> <semver>` comment. Future migrations under
`db/migrations/NNN_*.sql` carry the same annotation declaring the
versions they advance affected tables to. After a successful apply
(`/api/settings/migrations/apply` or the per-file
`/api/settings/migrations/apply-one`) the resolved versions are
written to the `app_config` table; the Settings UI then compares the
live entries against `db/schema.sql` to surface drift and renders a
top banner when a migration is needed. See `docs/Settings.md →
Schema versioning` for the full workflow.
