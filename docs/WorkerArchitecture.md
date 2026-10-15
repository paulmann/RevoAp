# Worker Architecture v2

## Why

RevoAp's legacy `/api/council/run` and `/api/council/stream` endpoints
execute the long LLM consensus pipeline inside the HTTP request that
opened them. nginx, PHP-FPM, MariaDB, the browser's `EventSource` layer,
or the network can drop that connection well before L1 / L2 / L3 / L4
finish, even when the upstream LLM providers are still answering.

Worker Architecture v2 splits the system into two planes:

```
Browser
  -> PHP / RevoAp API           (control plane: UI, Settings, Profile,
                                    Domain Models, Auth, History, Share,
                                    job creation, SSE event replay)
  -> MariaDB                       (durable state: sessions, steps,
                                    responses, events, revoap_jobs)
  -> RevoAp Worker Service      (data plane: runs the LLM pipeline
                                    out-of-band, persists every event
                                    before progressing)
  -> PHP SSE replay                (read-only, streams persisted
                                    `pipeline_events` to the browser)
```

Phase 1 (this release) introduces the durable job layer in PHP. The
legacy pipeline still runs synchronously — Phase 1 ships the new
surface alongside it for testing and integration. Phase 2 introduces a
PHP CLI worker that pops queued jobs and runs the existing
`PipelineEngine` outside of PHP-FPM. Phase 3 swaps the worker for a
native Python async runtime.

## Roadmap

| Phase | Goal | Delivered in |
|-------|------|--------------|
| 1     | Durable job layer in PHP (this release) | v1.8.35 |
| 2     | PHP CLI worker bridge | TBD |
| 3     | Native Python async worker | TBD |
| 4     | n8n / tool integration | TBD |
| 5     | User API keys + external frontend access | TBD |

## Phase 1 components

### `revoap_jobs` table

Defined in `db/schema.sql` (the v1.8.35 job layer and the v1.8.39
auto-recovery columns were consolidated into it). Schema version 1.8.40,
table version `revoap_jobs 1.1.0`.

Columns: `id`, `session_id`, `user_id`, `status`, `priority`,
`attempts`, `max_attempts`, `payload_json`, `result_json`,
`error_json`, `locked_by`, `locked_at`, `heartbeat_at`, `next_run_at`,
`last_recovered_at`, `recovery_reason`, `last_error_at`, `created_at`,
`started_at`, `finished_at`, `updated_at`. Indexed on
`(status, priority, created_at)`, `session_id`, `user_id`,
`(locked_by, locked_at)`, `heartbeat_at`, `(status, next_run_at,
priority, id)`, and `(attempts, status)`.

`status` accepts the lifecycle values defined by
`App\Jobs\JobStatus`:

* `queued`
* `running`
* `pause_requested`
* `paused`
* `resume_requested`
* `cancel_requested`
* `cancelled`
* `consensus_requested`
* `completed`
* `failed`
* `stale`

### PHP domain layer

* `App\Jobs\JobStatus` — backed enum, exposes `isTerminal()`, `isActive()`, and `values()`.
* `App\Jobs\Job` — readonly DTO. `toPublicArray()` strips every key whose name matches an API-secret heuristic, so job status responses are safe to return to the browser.
* `App\Jobs\JobRepository` — PDO-backed CRUD with an in-memory fallback for hosts that boot without a database. Wraps writes in `App\Core\Database::run()` so a single idle-disconnect is transparently retried. Closes every cursor after fetching.
* `App\Jobs\JobService` — application service. `createJob()` validates input, creates the `council_sessions` row, persists session metadata (skill slug + serialized options), assembles the canonical payload, and inserts the `revoap_jobs` row. Other helpers manage the intent transitions (`requestPause` / `requestResume` / `requestCancel` / `requestConsensus`).

### HTTP surface

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/council/jobs` | Create a session + durable job. Returns immediately. |
| `GET`  | `/api/council/jobs/{id}` | Browser-safe status snapshot. |
| `POST` | `/api/council/jobs/{id}/pause` | Records `pause_requested` intent. |
| `POST` | `/api/council/jobs/{id}/resume` | Records `resume_requested` intent. |
| `POST` | `/api/council/jobs/{id}/cancel` | Records `cancel_requested` intent. |
| `POST` | `/api/council/jobs/{id}/run-consensus` | Records `consensus_requested` intent. Worker stops new cycles and proceeds to L4 synthesis. |
| `GET`  | `/api/council/session/{id}/events/stream` | SSE replay of persisted `pipeline_events`. Honours `after_event_id` query parameter and `Last-Event-ID` header. Does **not** execute the pipeline. |

Legacy endpoints `POST /api/council/run` and `GET /api/council/stream`
keep their v1.8.34 semantics for backward compatibility.

### Job payload

`revoap_jobs.payload_json` carries:

* `query`
* `session_id`
* `user_id`
* `skill` / `skill_id`
* `consensus_skill`
* `cycle_limit`
* `reasoning`
* `selected_models`
* `profile`
* `domain`
* `analyzer`
* `client_run_id`
* `request_meta` (origin + user-agent, scrubbed of authorization / cookie headers)
* `enqueued_at` (UTC ISO-8601)
* `phase` (= 1)

API keys, bearer tokens, and connector secrets are explicitly rejected
at the boundary — neither input nor stored payload may carry them. The
public job-status view (`Job::toPublicArray`) re-scrubs the payload as
a defense-in-depth measure before serialising.

### Config exporter

```bash
php bin/export-worker-config.php --safe    # default; strips secrets
php bin/export-worker-config.php --secrets # includes connector keys
```

The CLI script refuses to run from the web SAPI (`PHP_SAPI === 'cli'`
is required; otherwise it returns HTTP 403). PHP warnings are routed
to STDERR via the early error handler, so STDOUT is always pure JSON.
The exporter loads `config.php` through the standard `App\Core\Config`
loader so every overlay file (`config.php`, `.config.php`, `.url`,
`.sql.php`, `.{provider}.key`) is honoured exactly as the live web
application sees them.

Output blocks: `app`, `paths`, `database`, `cache`, `pipeline`,
`reasoning`, `session`, `admin`, `models`, `connectors`, `_meta`.

## Deployment

1. The `revoap_jobs` table is already part of `db/schema.sql` — a
   fresh install or a Settings → "ReCreate DB" creates it
   automatically. Existing deployments that predate v1.8.35 must run
   the consolidated import (`mysql -u root opencorum < db/schema.sql`,
   idempotent) or have the table created by the app.
2. Deploy the new code. The legacy endpoints continue to work; no UI
   change is required.
3. Optional smoke test the exporter:
   ```bash
   php bin/export-worker-config.php --safe | jq '.app, ._meta'
   ```
4. Smoke test the smoke suite:
   ```bash
   php tests/SmokeTest.php
   ```

## Phase 2 (v1.8.36) — PHP CLI worker bridge

Phase 2 ships the bridge that takes a row out of `revoap_jobs` and
runs the existing `App\Pipeline\PipelineEngine` outside of PHP-FPM.
The legacy `/api/council/stream` and `/api/council/run` endpoints
are NOT removed — Phase 2 ships the worker alongside them.

### What's new

* **`bin/revoap-worker.php`** — long-lived PHP CLI process. Refuses
  to run from the web SAPI. Emits one structured JSON log line per
  event to STDOUT (`info`/`debug`) and STDERR (`warn`/`error`). API
  keys, bearer tokens, and admin tokens never reach the log stream.
* **`App\Jobs\PipelineJobRunner`** — bridge between
  `App\Jobs\JobRepository` and `App\Pipeline\PipelineEngine`. Maps
  the durable job lifecycle to the engine's existing cooperative
  `RunControl` checkpoints (`ACTION_PAUSE` / `ACTION_REJECT` /
  `ACTION_FINALIZE`). Heartbeats every 5 seconds on each engine event.
* **`App\Jobs\JobRepository::acquireNext()`** — atomic acquire on
  `revoap_jobs` using `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL
  8.0+ / MariaDB 10.6+) with a transparent fallback to regular
  `FOR UPDATE` for older servers. The in-memory mirror provides a
  drop-in equivalent for tests / memory-mode boots.
* **`App\Pipeline\PipelineEngine::run()`** — accepts an optional
  `options['session_id']`. When supplied (and the row exists), the
  engine reuses it instead of creating a new `council_sessions` row,
  so the replay URL handed to the browser at job-create time stays
  valid through the worker run.
* **`deploy/systemd/revoap-worker.service`** — systemd unit
  template with hardening (`NoNewPrivileges`, `ProtectSystem=full`,
  `ProtectHome`, `PrivateTmp`, `RestrictSUIDSGID`).

### CLI usage

```bash
# Foreground / smoke check: acquire one job, run it, exit.
php bin/revoap-worker.php --once

# Daemon mode (used by systemd).
php bin/revoap-worker.php --loop --sleep=1 --stale-after=300

# Override the lock identifier (default <hostname>-<pid>).
php bin/revoap-worker.php --loop --worker-id=worker-eu-1

# Cap and exit after N jobs (useful in CI smoke loops).
php bin/revoap-worker.php --loop --max-jobs=10

# Help text.
php bin/revoap-worker.php --help
```

Exit codes: `0` success, `2` invoked from the web SAPI, `3` help
printed, `4` fatal bootstrap error.

### Job acquisition + locking

```sql
SELECT * FROM revoap_jobs
WHERE status IN ('queued','resume_requested')
ORDER BY priority DESC, id ASC
LIMIT 1
FOR UPDATE SKIP LOCKED;
```

Inside the same transaction the row is updated to `status=running`,
`locked_by=:worker_id`, `locked_at=CURRENT_TIMESTAMP`,
`heartbeat_at=CURRENT_TIMESTAMP`, and `started_at` is initialised on
first acquire. The commit fences the row from any other worker.

### Stale reclaim

Pre-v1.8.39 the worker called `reclaimStale($threshold)` which simply
flipped any `running` row whose `heartbeat_at` was older than the
threshold (default 300s) to `stale`. Stale rows were NOT picksble by
default — operator had to transition them back to `queued`. v1.8.39
ships **auto-recovery** so a crashed worker no longer pins the queue.
See [Phase 2.5 — auto-recovery](#phase-25--v1839-auto-recovery-for-stale-jobs)
below for the details.

### Control intent mapping

Durable job status → cooperative `RunControl` action:

| Job intent              | RunControl action      | Engine behaviour                             |
|-------------------------|------------------------|----------------------------------------------|
| `pause_requested`       | `ACTION_PAUSE`         | Engine blocks at next checkpoint             |
| `cancel_requested`      | `ACTION_REJECT`        | Engine refuses to start any further work     |
| `consensus_requested`   | `ACTION_FINALIZE`      | Engine drops to L4 with collected data       |

Limitations (documented, not faked):

* Cancellation is cooperative. An in-flight HTTP call to a remote
  model cannot be aborted mid-flight — the cancel takes effect at the
  next checkpoint (between L1 / L2 / L3 / L4 stages and between
  consensus cycles).
* If a worker dies with no `heartbeat_at` refresh (SIGKILL, OOM),
  the job will be reclaimed as `stale` after the threshold. An
  operator-initiated resume is required to push it back to `queued`.

### Deployment (systemd)

1. Copy the unit template to the system directory and edit user / path:
   ```bash
   sudo cp deploy/systemd/revoap-worker.service /etc/systemd/system/
   sudo $EDITOR /etc/systemd/system/revoap-worker.service
   ```
2. Reload, enable, start:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable revoap-worker.service
   sudo systemctl start  revoap-worker.service
   sudo systemctl status revoap-worker.service
   ```
3. Tail logs:
   ```bash
   sudo journalctl -u revoap-worker.service -f -o cat | jq .
   ```

For multiple workers, use the instantiated template
`deploy/systemd/revoap-worker@.service` instead of copying the
single unit:

```bash
sudo cp deploy/systemd/revoap-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now revoap-worker@1 revoap-worker@2
sudo systemctl status 'revoap-worker@*'
sudo journalctl -u 'revoap-worker@*' -f -o cat | jq .
```

Each instance pins `--worker-id=worker-%i`, so `/api/health/worker`
`running_workers` lists stable, readable ids (`worker-1`, `worker-2`,
...) instead of ephemeral hostname-pid pairs. The DB row lock
(`SELECT ... FOR UPDATE SKIP LOCKED` in `JobRepository::acquireNext()`)
guarantees no two workers can acquire the same job; this requires
MySQL 8.0+ / MariaDB 10.6+.

### Poll tempo (--sleep)

One worker polls the queue once per `--sleep` seconds while it is
idle, i.e. one SELECT per interval per process. The tempo is no
longer hard-coded: both units read `WORKER_SLEEP` from the
environment (inline `Environment=` default plus
`EnvironmentFile=/etc/revoap/worker.env`, tracked as
deploy/systemd/worker.env.example), so changing the pace does not
require a unit edit - only a `systemctl daemon-reload` +
reload/restart.

Recommended operating modes:

-   **Interactive** (users are around, jobs should start near-instantly):
    `WORKER_SLEEP=1` - at most 1s of extra latency, one poll per
    second per worker. With 5 workers that is 5 SELECTs/s while the
    queue is empty, acceptable on a modest VPS with a properly
    indexed `revoap_jobs` table.
-   **Quiet hours** (night / weekends, no latency expectations):
    `WORKER_SLEEP=5` - queued jobs wait up to 5s for the next poll,
    cutting idle DB load by 5x. On quiet deployments with no pending
    queue this is the recommended default to keep the DB calm; the
    queue is still drained because a non-empty acquire loop runs
    jobs back-to-back without sleeping.

Every poll iteration is accompanied by an auto-recovery sweep
(recoverStale) in v1.8.39+, so raising `WORKER_SLEEP` also
stretches the worst-case stale-recovery latency: with
`WORKER_SLEEP=5`, a crashed worker's job is re-detected at most
5s later than at `WORKER_SLEEP=1`, and `WORKER_STALE_AFTER`
still gates how old the heartbeat must be before the row is
reclaimed.

### Web-facing timeouts (nginx / PHP-FPM)

Job processing runs OUTSIDE PHP-FPM (the worker is a CLI process
with its own lifecycle, see Phase 1/2 above), so no FPM
`request_terminate_timeout` or `max_execution_time` can kill a
running pipeline. Two web paths still need a long proxy timeout:

-   the SSE replay endpoint
    (`/api/council/session/{id}/events/stream`) holds the connection
    open for the entire replay;
-   the legacy in-request pipeline (`/api/council/run`,
    `/api/council/stream`) keeps the PHP-FPM request alive while it
    processes the full run.

Both are already covered in the tracked vhost
`nginx/revoap.com.conf`: `fastcgi_read_timeout 3600s` and
`fastcgi_send_timeout 3600s` in the PHP location and the
`@fallback` block. On FastPanel-managed hosts verify these values
survive panel regeneration of the vhost.

Recommended PHP-FPM pool settings (match the repo vhost so neither
side cuts the other off):

```ini
; longest non-worker request (SSE replay) may sit open
request_terminate_timeout = 3600
; legacy pipeline path - same ceiling as nginx fastcgi_read_timeout
max_execution_time = 3600
```

### Pool sizing

Size the pool from measurements, not guesses:

1. Measure the real per-process memory on the host:
   `systemctl show revoap-worker@1 -p MemoryCurrent` after a full
drain (baseline guidance is about 4 MB per idle process; RSS spikes
while a job payload is being processed). `MemoryMax=256M` in the
template is a provisional cap - tighten it to the measured peak plus
margin after the first production drains.
2. Sizing formula: `workers = peak queue length / mean job duration`
(both measured over the same period). Example: a peak hour queues 30
jobs and a mean job takes 6 minutes, so 3 workers keep the queue
drained. Start with 1-2 instances and scale up while watching
`/api/health/worker`.
3. Every instance runs with `Restart=always`, `RestartSec=5` and
`TimeoutStopSec=600`. When `pcntl` is available the worker installs
SIGTERM/SIGINT handlers and finishes the current cycle before
exiting; without `pcntl` systemd hard-kills after the stop timeout
and the next poll reclaims the stale row (see "Stale reclaim").

## Phase 2.5 — v1.8.39: auto-recovery for stale jobs

v1.8.39 closes the "worker crashed → job pinned in stale forever" gap
introduced by Phase 2 / Phase 2.5. The new auto-recovery sweep
automatically requeues a stale job with a backoff schedule until the
retry budget is exhausted, at which point the row is flipped to a
terminal `failed` status with a structured exhausted-retries envelope.

### Migration `012_jobs_auto_recovery.sql`

Adds six retry/backoff columns and two indexes to `revoap_jobs`
(see schema.sql for the consolidated `CREATE TABLE` shape):

| Column              | Type          | Purpose                                              |
|---------------------|---------------|------------------------------------------------------|
| `attempts`          | INT NOT NULL  | How many times the row has been reclaimed/requeued. |
| `max_attempts`      | INT NOT NULL  | Per-row ceiling captured at create time.            |
| `next_run_at`       | DATETIME NULL | Earliest time a worker may pick the row up again.   |
| `last_recovered_at` | DATETIME NULL | Timestamp of the most recent recovery.              |
| `recovery_reason`   | VARCHAR(190)  | Reason tag (e.g. `stale_heartbeat`).                |
| `last_error_at`     | DATETIME NULL | Timestamp of the most recent error envelope.        |

| Index               | Columns                                | Purpose                                                |
|---------------------|----------------------------------------|--------------------------------------------------------|
| `idx_jobs_next_run` | `(status, next_run_at, priority, id)`  | Keeps `acquireNext()` cheap once retries dominate.     |
| `idx_jobs_attempts` | `(attempts, status)`                   | Diagnostic aggregates (retry_scheduled / exhausted).   |

Idempotent — each `ADD COLUMN`/`ADD INDEX` is a discrete statement
that MigrationManager swallows on re-run.

### Config

```php
'pipeline' => [
    'jobs' => [
        'recovery' => [
            'auto_recovery_enabled' => true,
            'max_attempts'          => 3,
            'stale_after_seconds'   => 300,
            'backoff_seconds'       => [30, 120, 300],
            'backoff_base_seconds'  => 30,
            'backoff_max_seconds'   => 900,
        ],
    ],
],
```

Read via `App\Core\Config::jobsRecoveryPolicy()` which clamps every
leaf to a sane range so a hostile `.config.php` cannot stall the
worker.

### Sweep behaviour

`JobRepository::recoverStale($threshold, $backoffSchedule, $enabled)`
is called by `bin/revoap-worker.php` before every acquire (in
both `--once` and `--loop` modes). For every `running` row whose
`heartbeat_at` is older than `$threshold`:

1. If `attempts + 1 <= max_attempts`: requeue.
   * `status = queued`
   * `attempts += 1`
   * `next_run_at = NOW + backoff(attempts)`
   * `last_recovered_at = NOW`
   * `recovery_reason = 'stale_heartbeat'`
   * `locked_by = NULL`, `locked_at = NULL`
2. Otherwise: fail terminally.
   * `status = failed`
   * `error_json` carries `{class:'StaleJobRecoveryExhausted',
     exhausted_retries:true, attempts:<previous>, …}`
   * `finished_at = NOW`
   * `recovery_reason = 'stale_heartbeat_exhausted'`

Both updates run inside a single transaction with `FOR UPDATE SKIP
LOCKED` (with graceful fallback to `FOR UPDATE`) so two workers can
never double-recover the same row.

`acquireNext()` is augmented to skip queued rows whose `next_run_at`
is in the future, so the backoff is honoured naturally without a
second polling timer.

### CLI flags

```bash
# Override the retry ceiling for this process
php bin/revoap-worker.php --loop --max-attempts=5

# Override backoff base / cap. Passing either flag rebuilds the
# auto-recovery ramp as x1/x4/x10 of the base, each entry capped at
# --backoff-max (e.g. base=60, max=1800 -> ramp 60/240/600).
php bin/revoap-worker.php --loop --backoff-base=60 --backoff-max=1800

# Disable auto-recovery — pre-v1.8.39 "flip to stale" behaviour
php bin/revoap-worker.php --loop --no-auto-recovery
```

### Structured logs

```
worker.stale.requeued    { count, threshold_sec, backoff_seconds }
worker.stale.exhausted   { count, threshold_sec, max_attempts }
worker.reclaim.stale     { count, threshold_sec, note=auto_recovery_disabled }
```

No payload content, no result envelopes, no API keys.

### Worker Status diagnostic envelope (v1.8.39 super-set)

`GET /api/health/worker` adds the following keys to the v1.8.38
envelope:

| Key                  | Type           | Description                                          |
|----------------------|----------------|------------------------------------------------------|
| `retry_policy`       | object         | Resolved live policy (the same shape as `Config::jobsRecoveryPolicy()`). |
| `retry_scheduled`    | int            | Queued jobs with `attempts > 0`.                     |
| `retry_exhausted`    | int            | Failed jobs with `error_json.exhausted_retries=true`.|
| `next_retry_at`      | string\|null   | Earliest future `next_run_at`.                       |
| `oldest_retry_age_s` | int\|null      | Age (s) of the oldest scheduled retry (past due).    |

The Settings → Worker Status card renders these as a new
"Auto-recovery (stale jobs)" sub-section.

### Migrations / deployment

1. The auto-recovery columns and indexes are part of `db/schema.sql`
   (`revoap_jobs` @table-version 1.1.0) — a fresh install or
   ReCreate DB already has them. Existing deployments that predate
   v1.8.39 converge via the consolidated import
   (`mysql -u root opencorum < db/schema.sql`, idempotent).
2. Deploy the v1.8.39 code (workers + web).
3. Restart the worker so it picks up the new policy:
   ```bash
   sudo systemctl restart revoap-worker.service
   ```
4. Verify via the Settings → Worker Status screen or
   `php bin/revoap-worker.php --status`.

For emergency rollback: set
`pipeline.jobs.recovery.auto_recovery_enabled = false` in
`.config.php` and restart the worker — the sweep falls back to the
pre-v1.8.39 "flip to stale" behaviour.

## Phase 2.5 — v1.8.37: UI runs job-mode by default

v1.8.37 flips the main "Run pipeline" button from the legacy in-request
SSE stream (`GET /api/council/stream?…`) to the durable job layer
(`POST /api/council/jobs` + `GET /api/council/session/{id}/events/stream?live=1`).
Long-running LLM pipelines now survive browser drops, PHP-FPM
restarts, and reverse-proxy idle timeouts — the worker writes events
to `pipeline_events` and the browser just replays them.

### `pipeline.execution_mode` switch

Set in `config.php` (or `.config.php` / `runtime.json` override):

```php
'pipeline' => [
    'execution_mode'             => 'job',    // default; 'legacy' for rollback
    'job_worker_warning_seconds' => 15,        // worker-idle UI hint timeout
],
```

| Value     | Run button behaviour                                                  |
|-----------|-----------------------------------------------------------------------|
| `'job'`   | `POST /api/council/jobs` → replay SSE with `?live=1`. **Default.**     |
| `'legacy'`| `EventSource('/api/council/stream?…')` — runs inside the web request. |

Both surfaces remain wired server-side. Unknown values coerce to
`'job'` so a typo never silently downgrades the UI.

### Frontend flow (assets/js/app.js)

```
submitQueryJobMode(query)
  ├─ POST /api/council/jobs { query, selected_models, reasoning,
  │                            consensus_skill, cycle_limit,
  │                            client_run_id }
  │       → { ok:true, job_id, session_id, events_url, status:'queued' }
  ├─ Render "Job queued — waiting for worker…" activity card.
  ├─ Schedule a worker-idle hint timer (default 15 s).
  └─ Loop:
       openOneReplayConnection(run)
         · EventSource(events_url + '?live=1' [+ '&after_event_id=<id>'])
         · For every named event frame (L0_*, L1_*, …, PIPELINE_DONE):
             update run.lastEventId, dispatch to pipeline.js renderer.
         · On 'PIPELINE_REPLAY_DONE':
             - data.job_terminal === true  → resolve('job_terminal'); STOP.
             - data.job_terminal === false → resolve('reconnect'); reopen
                                              with `?after_event_id=<latest>`
                                              so the worker can keep
                                              pushing as it makes progress.
         · On readyState=CLOSED with no PIPELINE_REPLAY_DONE: reconnect
           with brief back-off (≤ 5 s) until job terminal.
```

Reject / Pause / Continue / Stop-and-Run consensus controls map to:

| UI action                | Job-mode endpoint                                 |
|--------------------------|---------------------------------------------------|
| Pause                    | `POST /api/council/jobs/{id}/pause`               |
| Continue                 | `POST /api/council/jobs/{id}/resume`              |
| Reject                   | `POST /api/council/jobs/{id}/cancel`              |
| Stop and Run consensus   | `POST /api/council/jobs/{id}/run-consensus`       |

Legacy runs keep using `POST /api/council/control` keyed by session_id.

### Backend changes

* `JobsController::eventsStream()` accepts `?live=1` and, when set,
  keeps the connection open polling `pipeline_events` until the
  matching `revoap_jobs` row hits a terminal status (or
  `STREAM_WINDOW_SECONDS` elapses — default 55 s). The terminal
  `PIPELINE_REPLAY_DONE` frame carries `job_status` and `job_terminal`
  so the EventSource client can decide between reconnect and stop.
* Snapshot mode (no `live=1`) keeps the pre-1.8.37 single-shot
  behaviour for callers that just want the persisted events.
* `/api/boot-config` exposes `pipeline.execution_mode` and
  `pipeline.job_worker_warning_seconds`; the SPA layout also bakes
  these into `<meta>` tags for zero-fetch startup.

### Worker-idle hint

If no event arrives within `pipeline.job_worker_warning_seconds`
(default 15 s) of enqueuing, the UI surfaces an actionable hint:

> No worker activity for 15 s — start the worker process:
> `php bin/revoap-worker.php --once` for a single drain or
> `sudo systemctl start revoap-worker.service` for the persistent
> runner.

The job sits in `revoap_jobs` regardless — flipping the worker on
later picks the job up cleanly.

### Deployment notes for v1.8.37

1. The `revoap_jobs` table is part of `db/schema.sql` — fresh
   installs and ReCreate DB get it automatically. Existing deployments
   that predate v1.8.35 converge via the consolidated import
   (`mysql -u root opencorum < db/schema.sql`, idempotent).
2. Smoke-test the worker drain path **before** flipping the UI:
   ```bash
   php bin/revoap-worker.php --once
   ```
3. Start the persistent worker:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now revoap-worker.service
   sudo journalctl -u revoap-worker.service -f -o cat | jq .
   ```
4. (Optional) Roll back to legacy in-request streaming by setting
   `pipeline.execution_mode = 'legacy'` in `.config.php`:
   ```php
   <?php
   return ['pipeline' => ['execution_mode' => 'legacy']];
   ```

## Worker Status diagnostics (v1.8.38)

`App\Jobs\WorkerHealthService` is the single source of truth for the
admin **Settings → Worker Status** card, the new
`GET /api/health/worker` endpoint, the `worker` block on
`/api/health`, and the `php bin/revoap-worker.php --status` CLI
output. All four surfaces emit the same JSON shape; the UI is purely
a presentation layer over the service.

### Envelope

```jsonc
{
  "execution_mode": "job",                // pipeline.execution_mode
  "jobs_table_available": true,           // revoap_jobs exists
  "migration_applied": true,              // alias of jobs_table_available
  "stale_threshold_sec": 300,             // clamp = [30, 86400]
  "counts": {
    "queued": 0, "running": 1, "paused": 0, "pause_requested": 0,
    "resume_requested": 0, "cancel_requested": 0, "consensus_requested": 0,
    "cancelled": 0, "completed": 17, "failed": 1, "stale": 0
  },
  "total_non_terminal": 1,
  "oldest_queued_at": null,               // SQL timestamp or null
  "oldest_queued_age_s": null,            // seconds or null
  "latest_heartbeat_at": "2026-05-13 10:42:11",
  "latest_heartbeat_age_s": 4,
  "running_workers": [
    { "worker_id": "host-1234", "heartbeat_at": "…", "heartbeat_age_s": 4,
      "running": 1, "alive": true }
  ],
  "stale_jobs": 0,
  "worker_status": "healthy",             // see classification below
  "recommendations": [ "Worker is processing jobs." ],
  "commands": {
    "once": "php bin/revoap-worker.php --once",
    "loop": "php bin/revoap-worker.php --loop --sleep=1 --stale-after=300",
    "status": "php bin/revoap-worker.php --status",
    "systemd_status": "sudo systemctl status revoap-worker.service",
    "systemd_start": "sudo systemctl start revoap-worker.service",
    "systemd_restart": "sudo systemctl restart revoap-worker.service",
    "systemd_enable": "sudo systemctl enable --now revoap-worker.service",
    "journal_tail": "sudo journalctl -u revoap-worker.service -f -o cat"
  },
  "generated_at": "2026-05-13T10:42:15Z"
}
```

No payloads, no result envelopes, no API keys, no row contents — only
structural counts, public-by-design `locked_by` identifiers, and
operator copy/paste commands. The endpoint is therefore safe to expose
publicly alongside `/api/health/database`.

### Classification rules

| `worker_status`   | When | Operator action |
|---|---|---|
| `migration_missing` | `revoap_jobs` does not exist | Import db/schema.sql (Settings → ReCreate DB) |
| `not_running`       | Queued jobs but no heartbeat / no live worker | `sudo systemctl start revoap-worker.service` |
| `stale`             | Heartbeat older than `stale_threshold_sec` | Restart the worker; tail the journal |
| `healthy`           | At least one worker is running, heartbeat is fresh | None |
| `idle`              | No queued / running jobs, recent heartbeat (or fresh install) | None |
| `legacy_mode`       | `pipeline.execution_mode = legacy` | Flip back to `job` to use the durable worker |
| `unknown`           | Fallback | Re-pull; check application logs |

### CLI

```bash
# Pretty-printed JSON identical to /api/health/worker
php bin/revoap-worker.php --status

# Custom stale threshold (e.g. 600s)
php bin/revoap-worker.php --status --stale-after=600
```

Exit code is always `0` on success — the JSON envelope carries the
diagnostic; the exit code is for scriptability only.

## Phase 3 preview

A native Python 3.12+ async service (`worker/`) consumes the JSON
emitted by `bin/export-worker-config.php` on startup and reloads on
`SIGHUP`. The Python worker becomes the canonical pipeline runtime;
PHP becomes the control plane and replay layer only.

## Durable retry/audit timeline (v1.8.40)

Worker Architecture v2 — Phase 2.6 ships a durable audit trail for the
job control plane: every notable lifecycle transition of an
`revoap_jobs` row is recorded as one immutable row in the new
`revoap_job_events` table. The History UI uses this table to render
a per-session "Job timeline / Retry & recovery" panel, so an operator
can see when a job was queued, which worker acquired it, when its
heartbeat lapsed, when it was auto-requeued (with the exact attempt /
max_attempts / backoff_seconds / next_run_at used by `recoverStale()`),
when it exhausted the retry budget, and when it finally completed or
failed.

### Schema (migration `013_job_audit_events.sql`)

```sql
CREATE TABLE IF NOT EXISTS revoap_job_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id          BIGINT UNSIGNED NOT NULL,
    session_id      INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NULL,
    event_type      VARCHAR(64)     NOT NULL,
    status_from     VARCHAR(32)     NULL,
    status_to       VARCHAR(32)     NULL,
    attempt         INT             NULL,
    max_attempts    INT             NULL,
    backoff_seconds INT             NULL,
    next_run_at     DATETIME        NULL,
    worker_id       VARCHAR(128)    NULL,
    reason          VARCHAR(190)    NULL,
    message         VARCHAR(500)    NULL,
    context_json    JSON            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_events_job        (job_id, id),
    INDEX idx_job_events_session    (session_id, id),
    INDEX idx_job_events_event_type (event_type, created_at),
    INDEX idx_job_events_created    (created_at)
) ENGINE=InnoDB ...
```

The composite `(job_id, id)` and `(session_id, id)` indexes let the
timeline queries (`WHERE job_id = ? ORDER BY id ASC` and
`WHERE session_id = ? ORDER BY id ASC`) hit a covering index without
a sort step.

### Event types

| `event_type`         | Emitted by | Carries |
|----------------------|------------|---------|
| `job.created`        | `JobRepository::create()`     | `attempt=0`, `max_attempts`, `priority` in context |
| `worker.acquired`    | `JobRepository::acquireNext()`| `worker_id`, `attempt`, `max_attempts` |
| `worker.running`     | `updateStatus(Running)` (via `emitStatusAudit`) | `worker_id`, `attempt` |
| `pause_requested`    | `requestIntent(PauseRequested)` | `user_request` reason |
| `paused`             | worker checkpoint pause       | (status hop) |
| `resume_requested`   | `requestIntent(ResumeRequested)` | `user_request` reason |
| `cancel_requested`   | `requestIntent(CancelRequested)` | `user_request` reason |
| `cancelled`          | worker cancel checkpoint     | (terminal) |
| `consensus_requested`| `requestIntent(ConsensusRequested)` | `user_request` reason |
| `stale.detected`     | `recoverStale()` per row     | `attempt-1`, `max_attempts`, `worker_id`, `threshold_seconds` |
| `stale.requeued`     | `recoverStale()` per row     | `attempt`, `max_attempts`, `backoff_seconds`, `next_run_at` |
| `stale.exhausted`    | `recoverStale()` per row     | `attempt-1`, `exhausted_retries=true` in context |
| `error.recorded`     | `JobRepository::recordError()`| sanitized error envelope in context |
| `job.completed`      | `updateStatus(Completed)`    | (terminal) |
| `job.failed`         | `updateStatus(Failed)`       | (terminal) |
| `result.recorded`    | `JobRepository::recordResult()`| (advisory) |
| `lock.released`      | `JobRepository::releaseLock()` | previous `worker_id` |

### Sanitization contract

`JobAuditRepository::record()` is the single chokepoint for audit
writes. Before INSERT it runs every `context` blob through
`Job::sanitizePayload()` (drops keys matching `api_key`, `token`,
`password`, `secret`, `authorization`, `apikey`, `key`), trims
`message` to 500 chars after `Logger::sanitizeScalar()`, and caps
`reason` to 190 chars. Raw prompts and connector secrets are never
written to this table. The repository swallows every Throwable so a
missing migration or DB error never corrupts the underlying job
mutation.

### Timeline endpoints

```bash
# Per-job timeline
curl -s http://localhost/api/council/jobs/42/timeline | jq

# Per-session timeline (latest job for the session)
curl -s http://localhost/api/council/session/123/job-timeline | jq
```

Both endpoints return the compact `job` summary plus
`events: [...]` ordered chronologically, plus an
`audit_table_available` boolean so the UI can degrade gracefully when
the migration has not been applied.

### Export endpoints (v1.8.42)

Two sibling endpoints materialise the same sanitised data as a
downloadable JSON bundle (`revoap.job_timeline.v1`) or RFC-4180 CSV
stream. Both honour the v1.8.41 client filter taxonomy so curl, the
CLI and external integrations can grab the same bundle the History UI
downloads:

```bash
# JSON bundle for one job, all categories, context included.
curl -OJ "http://localhost/api/council/jobs/42/timeline/export?format=json"

# CSV for the session, only recovery events, no per-event context.
curl -OJ "http://localhost/api/council/session/123/job-timeline/export?format=csv&category=recovery"

# JSON, narrow a session export to one retry attempt and one event type.
curl -OJ "http://localhost/api/council/session/123/job-timeline/export?format=json&job_id=42&event_type=stale.requeued&include_context=1"
```

Query parameters:

| Param             | Default                | Notes                                                                                       |
|-------------------|------------------------|---------------------------------------------------------------------------------------------|
| `format`          | `json`                 | `json` or `csv`. Unsupported values → 400 with `unsupported_format`.                       |
| `category`        | `all`                  | `lifecycle`, `worker`, `recovery`, `control`, `result_error`. `result-error` is a synonym.  |
| `event_type`      | unset                  | Pin one exact event_type (e.g. `stale.requeued`).                                           |
| `q`               | unset                  | Case-insensitive needle over event_type / status_from/to / reason / message / worker_id.    |
| `include_context` | `1` JSON, `0` CSV      | `0`/`false`/`no` to drop the sanitised context. Secrets are always stripped regardless.    |
| `job_id`          | unset (session export) | Narrow a session export to a single job_id.                                                 |

Response headers carry `Content-Disposition: attachment;
filename="revoap-timeline-session-<sid>[-job-<jid>]-<UTC>.<ext>"`
and `Cache-Control: no-store`. Sanitisation is identical to the
non-export timeline endpoint — every event passes through
`JobAuditEvent::toPublicArray()` so api_key / token / password /
secret / authorization / apikey keys never reach the bundle.

### History UI

The session detail screen (History tab) shows a new panel titled
**Job timeline / Retry & recovery**. Each event is rendered as one
item in a vertical timeline with an event-type badge, a
`status_from → status_to` arrow, the attempt counter, the backoff
used (for `stale.requeued`), the worker id, and a collapsible
sanitized `context` block. Legacy sessions that pre-date the durable
job layer show a neutral "No durable job timeline recorded for this
legacy run." note so the panel never breaks on historical data.

### Retry policy keys (recap)

Retry policy is unchanged from v1.8.39. Each `stale.requeued` audit
event carries the exact `max_attempts` and `backoff_seconds` used so
the timeline UI can render the policy as it was when the row was
recovered, even if the live config has since changed.
