# Administration

What the v2.0.0 console lets an operator do, what is wired end to end today, and
what is schema-ready but still needs its HTTP surface.

The distinction below is stated plainly rather than left to be discovered: a
destination whose panel is empty reads either as *broken* or as *nothing to
configure*, and both are wrong. The seven pending panels render a server-side card
naming the exact table and service an implementer needs, so each is a wiring job
rather than an archaeology one.

---

## 1. The gate

Every operator route goes through one predicate — `App\Http\AdminGate::open()`,
which delegates to `AdminAuth::gateState()['allowed']`:

| Protection flag | `.admin.key` present | Result |
|---|---|---|
| on (default) | yes + token matches | **open** |
| on (default) | no | **closed** — fails safe, not open |
| on | yes, wrong token | closed |
| off (`.config.php`) | — | open, and the UI says so loudly |

Two ways to get this wrong, both avoided:

* Gating on `AdminAuth::check()` **alone** locks out an installation that
  deliberately set `Protect_Settings_by_Admin_Key = 0`. That flag exists for
  single-operator intranet hosts and the v1 surface honours it.
* Gating on `protectionEnabled()` **alone** opens every new panel to the internet
  on a default install — "protection is on" is not the same statement as "this
  request presented the token".

> **v2.0.0 changed the shipped default from `0` to `1`.** See
> [`SECURITY.md`](SECURITY.md) §S1. Create `.admin.key` *before* deploying:
> ```bash
> openssl rand -hex 32 > .admin.key && chmod 600 .admin.key
> chown www-data:www-data .admin.key
> ```

**CSRF** is covered globally: `App\Http\OriginGuard` runs at `index.php:1135`,
before `$router->dispatch()` at `:1157`, so every state-changing route inherits it.
A POST with a foreign `Origin` gets 403; a GET with the same `Origin` is allowed,
because links and bookmarks must keep working.

---

## 2. Wired end to end

| Panel | Controller | Routes | Notes |
|---|---|---|---|
| **System** | `AdminController::system` | `GET /api/admin/system` | Runtime report with ranked verdicts |
| **Users (end users & usage)** | `UsersController` | `GET /api/admin/users[/{id}]`, `POST …/{id}/budget`, `POST …/sync`, `GET …/{id}/export`, public-identity `GET /api/my/usage` | v2.1.0 (T5). Ledger totals, per-user budgets, GDPR export — see [`MULTI_USER.md`](MULTI_USER.md). The operator-RBAC half of "Users & access" remains pending (§3) and says so inside the panel |
| **Feature flags** | `AdminController::{flags,saveFlag,resetFlags}` | `GET/POST /api/admin/flags`, `POST …/reset` | 17 shipped flags |
| **Maintenance** | `AdminController::{maintenance,saveMaintenance}` | `GET/POST /api/admin/maintenance`, public `GET /api/maintenance/status` | 503 for everyone except admins, loopback and the allow-list |
| **Localization** | `AdminController::localization`, `I18nController` | `GET /api/admin/localization`, `GET/POST /api/i18n/*` | Coverage per locale, default locale, single-key editor |
| **Audit** | `AuditController` | `GET /api/audit`, `/api/audit/{id}`, `/verify`, `/export` | Read-only by design |
| **Fallback & alternatives** | `ModelFallbackController` | 12 routes under `/api/models/…` | See [`MODEL_FALLBACK.md`](MODEL_FALLBACK.md) |
| **Models · Settings · Logs · Security · History · Statistic · Skills · API · Extensions** | pre-v2 controllers | unchanged | Restyled by the token bridge, not rewritten |

### System

`EnvironmentReport` assembles the report field by field from allow-lists and runs
every value through the redactor, so there is no path by which a secret reaches the
browser — including through the "copy this into a bug report" affordance, which is
the whole point of the panel. `phpinfo()` leaks the environment, `ini_get_all()`
leaks values, and `$_SERVER` carries `HTTP_AUTHORIZATION` and the admin cookie;
none of them are read.

It reports PHP 8.5 conformance against the `^8.5` requirement, extension tiers
with the *impact* of each gap rather than just a name, OPcache state and hit rate,
a 19-key ini allow-list, path writability and modes, disk/memory/load, the database
**driver only** (never the DSN — it carries host, database name and often the
password), security posture and the build id.

The build id is what explains a stale deploy: with `opcache.validate_timestamps=0`
a deploy that forgets to reload php-fpm keeps serving old bytecode, and the id is
the only thing that says so.

Then it ranks **verdicts** — conclusions rather than numbers, worst first:

```
[critical] admin.token_missing
[warning ] system.extension_missing   intl: dates and numbers fall back to the built-in table
[warning ] system.extension_missing   opcache: expect a several-fold slowdown
[warning ] system.opcache_off
[warning ] system.display_errors_on
[info    ] system.expose_php_on
```

### Feature flags

Defaults are the source of truth; `revoap_feature_flags` is an override. A fresh,
DB-less or unmigrated install therefore behaves identically to a configured one.

Percentage rollouts bucket on a **sticky key** (session id, API key id, or client
IP) *salted with the flag name*. Unkeyed randomness makes a feature flicker between
requests — the most common complaint about naive rollouts, and the reason they get
abandoned. An unsalted hash puts the same 10 % of callers in every cohort, so each
percentage rollout would be perfectly correlated and the "10 %" group would carry
all the risk.

An unregistered flag reads as **OFF**: returning true would make a typo at a call
site silently enable a feature nobody registered. Writes refuse unregistered keys
for the same reason, so the table cannot fill with entries no code reads.

A flag toggle that fails to persist **reverts**. Leaving the switch on while the
write failed is a lie about the state of the system.

### Maintenance mode

State lives in the runtime overlay — no second source of truth, and it takes effect
on the next request with no PHP-FPM reload.

* **Administrators are let through**, because a switch that locks out whoever
  enabled it is a switch nobody dares enable.
* **Health endpoints always answer** (`/api/health*`, `/healthz`, `/readyz`,
  `/livez`). A load balancer that cannot reach its health check pulls the node out
  of rotation, which turns "temporarily unavailable" into "permanently removed from
  the pool" — and the node never comes back on its own.
* **Loopback and the operator allow-list pass**, so a deploy script's own
  `curl localhost/api/health` probe does not report a failure the deploy caused.
* CIDR handling delegates to `App\Security\IpMatcher` and address resolution to
  `App\Security\ClientIp`, so this allow-list and the IP firewall in the Security
  tab can never disagree about who the client is. Two implementations of "which
  address is this?" is how an operator gets locked out by one and let through by
  the other.
* Enforcement sits between the origin guard and dispatch, so no handler writes
  anything while the console is closed. A cross-site request is refused for the
  *better* reason.

### Localization

Per-locale coverage against `en.json`, missing and extra key lists, the plural
categories each language needs, writability, the active and negotiated locale, and
the default-locale override. The single-key editor writes atomically through
`I18nController::setKey()`.

### Audit

Append-only, SHA-256 **hash-chained**: each row stores `prev_hash` (the previous
row's `entry_hash`) and `entry_hash` over its own canonical fields. `GET
/api/audit/verify` recomputes the chain and reports the first break by index.

An audit log an administrator can edit through the same UI is not an audit log, so
`AuditController` exposes **no** update and no delete route — "we only expose delete
to administrators" is the argument every tampered log ever made. Retention is
archival, not deletion.

The chain is only meaningful when the trail is persisted; the response says so
rather than reporting a trivially-intact in-memory trail as evidence.

`before`/`after` carry the **diff**, not the object, and values are redacted
*individually before* JSON encoding — the redactor's patterns are written for raw
values, so a key that has already been escaped (`sk-abc\"def`) would not match a
pattern written for `sk-abc`. Keys whose *name* matches
`password|secret|token|api_key|dsn|…` are replaced wholesale regardless of what the
value looks like.

CSV export neutralises formula injection (`=`, `+`, `-`, `@`, TAB, CR are executed
by Excel and LibreOffice, and audit fields contain attacker-influenced text) and
emits a UTF-8 BOM, without which Cyrillic, Chinese and Arabic open as mojibake.

---

## 3. Schema-ready, HTTP surface pending

Migration `023` created these tables and `src/Admin/` holds their service
conventions. Each pending panel renders a card naming exactly what an implementer
needs.

| Panel | Tables | What exists / what is missing |
|---|---|---|
| **Dashboard** | `revoap_model_availability`, `revoap_budgets`, `revoap_task_runs` | All four source endpoints already answer (`/api/admin/system`, `/api/models/availability/summary`, `/api/statistic`, `/api/health/worker`). Needs one aggregator controller and a tile layout — the `Stat` primitive and the `dashboard.*` catalogue keys are done. |
| **Queue** | `revoap_jobs`, `revoap_job_events`, `revoap_worker_presence` | Read side exists (`JobsController`, `WorkerFeedController`, `WorkerHealthService` including the stale-code verdict). Needs the dead-letter view, the retry-policy editor and per-job retry/promote/cancel. |
| **Users & access (operator RBAC)** | `revoap_admin_users`, `revoap_admin_sessions` | Schema carries Argon2id hashes, encrypted TOTP secrets and recovery codes, per-account permissions, and a revocable session registry with a CSRF token column. Needs the controller and the panel. Separate from the pipeline's `users` table on purpose — conflating them would mean a pipeline user could be promoted to administrator by editing one column, and would put console credentials in the same table a data-export request has to cover. **The END-user half is wired since v2.1.0** (Users panel, usage ledger, per-user budgets, export — [`MULTI_USER.md`](MULTI_USER.md)); what remains pending here is the operator-account surface. |
| **Budgets** | `revoap_budgets`, `revoap_budget_alerts` | Schema carries scope, period, the denormalised period spend, thresholds and the action-at-limit. The spend rollup is deliberately authoritative only for the *limit check* (which must be fast and may be seconds stale); the cost report still reads source rows. **`scope='user'` enforcement is live since v2.1.0** (`UserQuotaEnforcer`, gated by the `cost.budgets` flag, alerts fired once per period/threshold); the generic panel — all scopes, forecast, notification wiring — is still pending. |
| **Scheduler** | `revoap_scheduled_tasks`, `revoap_task_runs` | Schema carries the cron expression, a denormalised `next_run_at` (so the per-minute entry point is one indexed query), the `locked_by`/`locked_until` overlap guard and a timeout. Nine task keys and their labels already exist in the catalogue. |
| **Notifications** | `revoap_notification_channels`, `revoap_notification_deliveries` | Schema carries the channel kind, `config_enc` for credentials, the subscribed event list, quiet hours, and a delivery log with attempt count and response code. Eleven event keys already exist in the catalogue. |
| **Backup** | `revoap_backups` | Schema carries the manifest, size, `sha256`, contents bitmap, encryption flag and KDF algorithm. `path` is relative to `var/backups` and validated against traversal on every read — a stored absolute path or a `../` would let a crafted row stream an arbitrary file through the download endpoint. |

Two invariants to preserve when implementing them:

1. `config_enc` and `totp_secret_enc` are **encrypted, not hashed** — TOTP has to
   recompute the code from the secret on every login, so a one-way hash is not
   usable. The envelope key comes from `security.encryption_key`; an install
   without one cannot enable 2FA or notification channels, and that is enforced in
   code rather than by a nullable column that quietly stores plaintext.
2. A plaintext bot token or SMTP password in a database that is also in every
   backup archive is how a console becomes a spam relay.

---

## 4. Conventions for anything added here

* First statement of every route handler is `if ($denied = AdminGate::deny('x')) return $denied;`.
* Every write goes to the audit trail, **including denied attempts** — a denial is
  the more interesting event, because it is either a mistake or a probe.
* Every write reports whether it actually persisted (`RuntimeConfig::updateChecked()`),
  because an overlay that cannot be written must not be reported as saved.
* Every list endpoint is `no-store`: these payloads are time-sensitive and a shared
  proxy caching them shows a dead model as healthy for as long as it feels like it.
* Panels mount lazily on first reveal, render through `assets/js/ui/components.js`,
  and never assign interpolated data to `innerHTML`.
* A 403 renders the unlock affordance and any other failure renders the remedy —
  never an empty state that reads as "no data".
