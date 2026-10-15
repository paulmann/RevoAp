# BETA-TEST-FINDINGS.md — T1/T6 beta pass (v2.1.0)

> Method, per REWORK-STATE T6: exercise the RUNNING app (live MariaDB 10.11,
> `php85 -S`, real worker runs, real GitHub downloads), not a read of it.
> Every finding: reproduce → failing assertion first → fix → re-run → record.
>
> Environment: PHP 8.5.8 static (`/home/user/tools/php85`) · MariaDB 10.11.19
> (strict mode) · Node 20.20.2 · four scripted walk phases (141 HTTP-level
> checks, kept under `/home/user/beta/walk*.mjs`) · six worker runs ·
> LiveSmoke after every fix round.
>
> Status legend: **FIXED** = code changed + assertion pinned in the suite.
> **BY DESIGN** = investigated, correct behaviour, recorded so the next
> tester does not re-report it.

---

## Findings

### 1. `db/schema.sql` + migration 023 could not be imported on a real server — FIXED (critical)
`revoap_admin_users` declared its primary key TWICE (`id … AUTO_INCREMENT
PRIMARY KEY` plus a standalone `PRIMARY KEY (id)`) → **ERROR 1068** on
MySQL/MariaDB. A fresh `mysql < db/schema.sql` died at that line, so on any
real deployment **none of the v2 tables existed** — including everything
after it: budgets, ledger, notifications. The v2.0.0 test suites could not
catch it: they parse schema.sql textually (SchemaParser) and drive sqlite
fixtures with hand-written DDL.
Repro: import schema.sql into MariaDB (T6, first live DB of this project).
Fix: standalone PK line removed in both files.
Pin: SmokeTest — textual invariant "no CREATE TABLE declares its PRIMARY
KEY twice" over schema.sql + every migration.

### 2. `revoap_backups.trigger` — unquoted reserved word — FIXED (critical)
The import then died at **ERROR 1064**: `trigger` is reserved in MySQL and
MariaDB alike. Nothing read the column yet (Backups panel pending), so it
was renamed to `trigger_kind` (values unchanged) rather than backticked —
a backticked reserved word is a landmine in every future query.
`@table-version revoap_backups` bumped 1.0.0 → 1.0.1 in both files.
Pin: SmokeTest — reserved-word column scan (35-word list, backticked uses
accepted).

### 3. Worker never attached the job-audit sink — FIXED (high)
`index.php` wires `$jobRepo->setAuditRepository($jobAuditRepo)`; the worker
bootstrap did not. Every worker-run job therefore had a one-event timeline
(the web-written `job.created`): acquired/running/result/error/failed/
recovered transitions happened without a trace — the History "Retry &
recovery" panel was empty exactly for the jobs that retried and recovered.
Repro: enqueue → `revoap-worker --once` → `revoap_job_events` had 1 row.
Fix: sink attached in `bin/revoap-worker.php`.
Pin: JobSessionReuseTest (T6 section) + live re-run (full timeline now).

### 4. Engine-reported failure marked the job COMPLETED — FIXED (high)
`PipelineEngine::run()` returns a rejected envelope (`status:'failed'`,
no answer — e.g. "no model can be called") instead of throwing.
`PipelineJobRunner` treated any non-throw as success: the Queue panel
painted the run green while History showed the same run failed, and
`/v1/runs/{id}` answered `completed` with a null answer.
Repro: enqueue on a host without provider keys → job `completed`, session
`failed`.
Fix (two layers): the runner now persists the envelope (the WHY) and fails
the job terminally — no retry, a rejection is a configuration fact, not a
transient fault; and `ApiV1Controller::buildRunFromJob` downgrades
`completed` to `failed` when the session says failed (the session is the
canonical run record — this also heals legacy rows like job #1 of this beta
DB).
Pin: JobSessionReuseTest (3 checks through a real engine with an empty
connector registry) + live re-run (job 2/3/4/5/6 `failed`, `RunRejected`
with the catalogue reason).

### 5. Budget writes died with SQLSTATE[HY093] on MySQL — FIXED (critical for T5)
`BudgetRepository::upsertUserBudget()` bound `:now` TWICE in one INSERT
(created_at, updated_at). The deployment runs `ATTR_EMULATE_PREPARES =
false`; MySQL native prepares forbid reusing a named parameter. Every
budget create/update failed with `write_failed` — while the sqlite offline
suite passed, because sqlite tolerates the reuse. Found by the first live
POST `/api/admin/users/1/budget`.
Fix: `:created_at` + `:updated_at`, with a comment naming the trap.
Pin: UserBudgetTest — static scan of every SQL literal in the T5 writers
for duplicate named parameters (literal-splitting scanner; a naive regex
produced false positives across string boundaries — also recorded here so
nobody "simplifies" it back).

### 6. `share/` and `extensions/` were not gitignored — FIXED (medium)
Both are runtime artefacts next to index.php: published run documents
(user content!) and the ~10 MB installed MD.Scribe tree. `git add -A` on a
live host would commit both. The beta pass produced `share/beta-doc.md`
and `extensions/md-scribe/` (945 files) before this was noticed.
Fix: `/share/` + `/extensions/` in `.gitignore`.

### 7. Safety net wrote an illegal ENUM value; orphans stayed `running` forever — FIXED (high)
`SessionSafetyNet::closeIfOrphaned()` closed sessions with
`consensus_status = ''`. The column is `ENUM('pending','agreement',
'partial','conflict','single')` — `''` is not a member. Strict-mode
MariaDB refused the whole UPDATE (**error 1265**, logged as
`worker.session.safety_net_failed`), and the orphaned session stayed
`running` forever — the safety net silently failing at the one job it
exists for. Non-strict servers stored `''` instead: ROAD_MAP item 8
("consensus_status у прогонов актуализации — пустая строка") is the same
bug seen from the other side. `ModelsActualizeReport::CONSENSUS_STATUS`
was `''` too.
Repro: worker self-test job → session stays `running` (beta DB sessions 7,
8 — pre-fix orphans, repairable via History → complete).
Fix: migration **025** adds the honest value **`'none'`** ("closed without
a verdict, and none is owed"; travels with score NULL like `'single'`);
the safety net writes `'none'`; actualize reports write `'single'` (one
model produced the report — the exact 022 semantics). schema.sql enum +
`@table-version council_sessions 1.0.6`; 025 applied through the app's own
Apply-Migrations on the live DB and recorded in the tracker.
Pin: **tests/SessionSafetyNetTest.php** (16 checks) — sqlite table with a
CHECK constraint replicating the enum value set, so the illegal write
throws offline exactly like strict MySQL; plus static pins on schema/
migration/callers. Live re-run: self-test session 9 closed
`completed`/`none` on strict MariaDB.

### 8. `Logger::enabled()` threw when Config was unbooted — FIXED (low-medium)
`Logger::log()` is documented "Never throws", but `enabled()` →
`Config::isDebug()` throws when the config tree was never booted (CLI
harnesses — REWORK-STATE gotcha #3 says library classes must use
`tryGet`). Observable damage: `closeIfOrphaned()` returned **false AFTER
successfully closing the session** — its `Logger::info` line threw into
the method's own catch. Fixed once, at the contract boundary: unbooted
config = silent logging, never a throw (`enabled()` and
`resolvedLogDir()`).
Pin: SessionSafetyNetTest exercises the unbooted-config path on every
close (its `true` return only comes back with this fix).

### 9. Users list omitted `is_local` — FIXED (low, UI)
`assets/js/users.js` renders the "console (local)" badge from
`u.is_local`, but `GET /api/admin/users` never included the field (only
the detail endpoint had it) — the badge could never render. Fixed in
`UsersController::list()`.
Pin: walk phase 4 (`is_local === true` for the local user).

### Found and fixed earlier in this session (recorded for completeness)
- **`Logger::debug()` did not exist** while ~15 catch blocks called it —
  a latent fatal exactly when something else had already failed (T5;
  13 PHPStan findings removed with it).
- **`bin/revoap-i18n.php extract` scanned only index.php** — the
  `foreach ([iterA, iterB] as $it)` iterated the ARRAY OF ITERATORS. Fixed
  in T5; the first honest scan found `queue.stale` missing (its `|| 'Stale'`
  fallback never fired because `t()` returns the key itself — truthy).
- **`assets/dist/revoap.css` absent from the GitHub v2 snapshot** —
  rebuilt and committed in T5 (LiveSmoke 2/163 → 163/163).

---

## Verified-correct behaviour (BY DESIGN — do not re-report)

- **Maintenance mode does not 503 the loopback** — documented exemption
  (docs/ADMIN.md §2: a deploy script's own probe must not report a failure
  the deploy caused). `GET /api/maintenance/status` still tells the truth
  (`enabled:true`), health endpoints always answer, admins pass.
- **`php_cli_too_old` on this host's default CLI** — MD.Scribe 3 needs
  PHP ≥ 8.3; the Debian `php` is 8.2. The gate refuses with the exact
  binary+version and the remedy (REVOAP_MDV_PHP_CLI), instead of letting
  the shim fatal into "installer answered junk". With the env set, the
  real Scribe machine API answers `{ok:true, version:'3.1.0'}`.
- **`already_installed` on a second extensions download** — the release
  archive is never re-extracted over a live tree without force; force
  keeps a `.bak-<timestamp>` instead of deleting.
- **Self-test jobs jump the queue** — priority 10 by design; `--once`
  processes exactly one job per invocation.
- **`/v1/chat/completions` runs are not per-user budgeted** — the OpenAI
  envelope carries no numeric user id, so the gate honestly sees no
  identity. Per-key attribution awaits `api_keys.user_id`
  (docs/MULTI_USER.md §6). A native body WITH `user_id` is gated on every
  branch (sync/stream/async), verified live: 429 `budget_exceeded` +
  Retry-After + the budget's own numbers; OpenAI-dialect refusals render
  `rate_limit_error` (offline pin: ApiQuotaGateTest).
- **Async /v1 runs with no user_id land UNATTRIBUTED** — they must not
  adopt the console's local user (and its budgets); that is what an
  explicit `user_id: null` through JobService now means, while the web
  console's absent key keeps resolving to the local profile user. Pinned
  in ApiQuotaGateTest; the beta DB's unattributed bucket counts exactly
  the identity-less API runs.
- **Pre-fix orphan sessions (7, 8) stay `running`** until repaired — the
  safety net fires at job completion, not retroactively; History →
  complete is the designed repair path. Post-fix orphans close
  automatically (session 9).

---

## Coverage of the pass (what was exercised)

| Surface | How | Result |
|---|---|---|
| Fresh-import schema (36 tables) | MariaDB strict import + app Apply-Migrations (014…025) | clean after fixes 1–2, 7 |
| Boot + shell | `/` HTML: CSP self+nonce, nosniff, no leftover `{{placeholders}}`, users/my-usage roots + modules present | ok |
| Every tab's API | walk phases 1–4: health, tabs, history (list/detail/status/complete/export), statistics (+user scope), users admin (list/detail/budget/sync/export), my/usage, audit (index/verify/CSV-BOM), flags (17), maintenance cycle, localization, extensions (status/settings/download/check/save/rotate paths), apikeys, logs/security/models probes, i18n locales, dashboard sources | 141 checks, all green at exit |
| Locales | `?lang=de/ru/zh/ar` — catalog embedded, `dir=rtl` for ar | ok |
| Run lifecycle | 9 sessions: web-enqueue, /v1 sync-shape, /v1 async, OpenAI envelope, self-tests; worker ×6; failure paths honest end-to-end (job→session→ledger→panel) | ok after fixes 3–4, 7 |
| Billing | ledger markers for failed runs; unattributed vs local buckets; synthetic spend → threshold alerts once each; rollup refresh | ok |
| Quotas | block → 429+Retry-After+numbers (native & dialect), warn → header + proceed, audit `budget.run_blocked` actor `user:1` | ok after fix 5 |
| Ownership | flag ON: anonymous empty/404/403 across history/detail/statistics/share/my-usage; admin unaffected; flag OFF restores v1.8.6 | ok |
| GDPR export | `/api/admin/users/1/export` — attachment, schema `revoap.user_export/1`, sessions + ledger rows inside | ok |
| MD.Scribe | real 3.3 MB download through the app (sha enforced), machine API via the real shim, ini merge preserves Scribe keys on a live file, share URLs switch to `extensions/md-scribe/` | ok |
| SSE | `/v1/runs/1/stream?format=openai` replay chunks; council events replay | ok |
| Runtime hygiene | server stdout: 0 Deprecated/Warning/Notice/Fatal across all traffic; error log: only expected refusals + pre-fix entries | ok |
| Gates after fixes | run-tests 87 pass / 6 env fails (same set) · LiveSmoke 163/163 · i18n lint+extract · build-css --check · theme-parity · PHPStan serial: 0 new vs T4 state | ok |
