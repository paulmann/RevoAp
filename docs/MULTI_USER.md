# Multi-user foundation (v2.1.0, T5)

Who a request belongs to, who may see what, and how every run gets billed.
This document is the map; the invariants live in the class docblocks it points
at, and every one of them is asserted by a test named next to it.

---

## 1. Two identities — never a third

| Table | Who | Authenticates how | Sees |
|---|---|---|---|
| `revoap_admin_users` | the OPERATOR (console RBAC, migration 023) | admin gate today (token); RBAC login pending | everything |
| `users` (+ `user_profiles`) | the END USER the pipeline writes | `user_id` on API calls; the local profile user for console runs | only its own rows |

A third identity table would mean two rows can claim the same person and every
ownership check would have to decide which to believe. `App\Auth\Identity` is
the single resolver that folds both tables into one answer per request:

```
AdminGate open               → Identity::admin()      (sees all)
$_SESSION['revoap_user_id']  → Identity::user(id)     (sees own rows only)
otherwise                    → Identity::anonymous()  (legacy-open, or
                                                        sees NOTHING when the
                                                        multiuser.ownership
                                                        feature flag is on)
```

Nothing sets `revoap_user_id` yet — the end-user login (invitations, password
reset, sessions) is the next layer of this foundation. The plumbing is in
place now so ownership filtering has ONE resolver to be right in instead of
being re-derived per controller when login lands. An Identity with a broken
user id fails **closed** (`seesNothing()`), because "no id" degrading into
"no filter" is the exact inversion of the promise.

**Upgrade safety:** with the `multiuser.ownership` flag OFF (the shipped
default) nothing changes — History/Statistics/Share keep the documented v1.8.6
visibility model of a single-operator install. Turning the flag ON is the
operator's explicit multi-user switch: from then on an anonymous request owns
nothing and sees nothing.

## 2. Ownership filtering (reads)

| Surface | Behaviour for a scoped (end-user) viewer | Test |
|---|---|---|
| `GET /api/history/list` | only `council_sessions.user_id = viewer`; search cannot cross the boundary; answer carries `scope: 'user'` | `tests/OwnershipTest.php` |
| `GET /api/history/session/{id}` | foreign or unattributed session → the same `404` a missing session gets (no existence oracle) | same |
| `POST …/complete`, `POST …/export` | write paths check ownership per id before acting; fail closed when the owner cannot be established | same |
| `POST /api/share/session` | minting republishes full run content, so the MINT is gated; refusal is `404 session_not_found`, indistinguishable from missing | same |
| `GET /api/statistics/summary` | `user_id` filter on sessions AND per-model money; a scoped identity's `?user_id=` is ignored, not honoured; strict-anonymous gets the empty shape via scope `-1` | same |
| `GET /v1/usage` | optional `?user_id=` — an API key is an operator credential, so the scope is explicit | — |
| `GET /v1/runs*` | unchanged: API keys are operator-scoped until key↔user binding ships (§6) | — |

Unattributed rows (`user_id IS NULL` — runs predating this foundation, or
callers that presented no identity) belong to NO end user: invisible to every
scoped viewer, visible to operators, and counted as their own honest bucket in
the admin panel rather than being folded into someone's total or dropped.

## 3. The usage ledger — `revoap_usage_ledger` (migration 024)

Append-only billing, one row per model call per settled run **plus one marker
row per run** (`response_id = 0`) so "how many runs" is answerable from the
ledger alone and the convergence sweep terminates. Derived from
`council_model_responses` (which stays authoritative) by
`App\Usage\UsageLedger::syncSession()`.

Invariants (all asserted by `tests/UsageLedgerTest.php`):

- **Append-only.** No UPDATE, no DELETE, no method that could. A billing row
  an administrator can edit is not billing. Corrections append; retention
  archives.
- **Idempotent.** `UNIQUE (session_id, response_id)` + INSERT…IGNORE: every
  completion path may call sync unconditionally, twice is a no-op.
- **Every settle path bills.** The hook hangs off
  `SessionRepository::complete()/markFailed()` — the one choke point the sync
  engine, the stream, the worker, the safety net and manual repair all pass
  through — plus a bounded `syncMissing()` sweep in the worker and
  `bin/usage-ledger-backfill.php` for first-run history.
- **NULL means "not reported", never zero** — for `cost`, `cached_tokens`,
  `cache_write_tokens`, `cache_discount`, `reasoning_tokens`. Aggregates
  report `cost_coverage` instead of pretending the gap was free.
- **Failed runs are billed** (`run_status = 'failed'`): they burned tokens.
- **Backfills bill the day the run RAN** (`run_created_at`), not the day the
  row was written (`created_at`).
- **Reasoning tokens** come from the step meta only where attribution is
  unambiguous (exactly one response on the step); a shared step stores NULL
  rather than a figure that would double-count when summed.
- **Cache attribution:** `cached` mirrors `cache_read_tokens > 0`, and the
  signed `cache_discount` travels along, so "what did a cached run really
  cost" is one indexed predicate instead of an archaeology project.

## 4. Per-user quotas (`src/Admin/Budgets/`)

`revoap_budgets` rows with `scope='user'`, enforced where runs are created:

- `ApiV1Controller::createRun()` — the named enforcement point, covering
  `/v1/runs`, `/v1/chat/completions` and `/v1/messages` (one code path) in
  all three launch shapes (sync / stream / async) BEFORE any dispatch;
- `JobService::createJob()` / `createBatchJob()` — the web + async funnel,
  refusing **before the session row exists** (a blocked run leaves no orphan
  "running" row in History);
- `CouncilController::run()/stream()` — the web console paths.

The verdict contract (`UserQuotaInterface`, tested by
`tests/UserBudgetTest.php` and `tests/ApiQuotaGateTest.php`):

- **`ok` / `warn` / `block`.** `block` → HTTP 429 `budget_exceeded` with
  `Retry-After` at the period end and the budget's own numbers in the body
  (dialect-correct: an OpenAI SDK sees `rate_limit_error` and its backoff
  loop already does the right thing). `warn` → the run proceeds with an
  `X-RevoAp-Budget-Warning` header.
- **Spend is computed LIVE from the ledger** over the calendar window (UTC:
  midnight / Monday / 1st / Jan 1). The `spent_amount` rollup is refreshed as
  a side effect for the pending Budgets panel; it is never the source.
- **Alerts fire once per (budget, period, threshold)** into
  `revoap_budget_alerts` — a channel that re-pages on every run after 95 %
  gets muted by the operator it was supposed to protect.
- **`throttle` is declared but NOT enforced yet** (model steering is a
  pipeline feature). It is downgraded to `warn` with a loud
  `throttle_not_enforced` warning in every verdict, alert and panel — never
  silently pretended.
- **Enforcement requires the `cost.budgets` feature flag** (operator opt-in,
  shipped OFF). No flag registry, no tables, no identity, or any thrown error
  → `ok, checked=false`: a missing migration must not become a run-surface
  outage. Unlike an auth check, the money question has a compensating
  control — the alerts still fire when the tables come back.
- **A blocked run is audited** (`budget.run_blocked`, RESULT_DENIED, actor
  label `user:<id>`) — a denial is the more interesting event.

## 5. Panels

| Panel | Mount | Endpoint(s) |
|---|---|---|
| **Users** (admin, tab `users`) | `assets/js/users.js` → `#oc-users-root` | `GET /api/admin/users[/{id}]`, `POST …/{id}/budget`, `POST /api/admin/users/sync`, `GET …/{id}/export` |
| **My usage** (user, inside Statistic tab) | `assets/js/my-usage.js` → `#oc-myusage-root` | `GET /api/my/usage` |

`/api/my/usage` is deliberately NOT admin-gated — a user's own totals are not
an operator surface; identity, not a token, is what protects them. Scoping:
end user → own id (query string ignored); operator → `?user_id=` or the local
console identity; anonymous → local identity on a legacy-open install,
`403 identity_required` under enforcement. The quota banner shows the
SERVER's verdict; no panel recomputes a limit client-side, because a second
implementation of the rule is a second answer.

**Data export (GDPR-shaped):** `GET /api/admin/users/{id}/export` downloads
everything the installation holds about one end user — identity, profile,
every session including full query/answer text, and every ledger row — as one
JSON document, and records the pull in the audit trail. It is admin-gated
because the requester is an operator acting on a request, and the trail is
what makes that accountable.

The panel keeps an explicit "Operator accounts (RBAC) — pending" card: the
Users tab is live for END users while `revoap_admin_users` management remains
the separate surface tracked in `docs/ADMIN.md` §3. An honest named gap beats
an empty space that reads as broken.

## 6. Not built yet (the honest remainder)

- **End-user login** — registration/invitation flow, password hashing for
  `users`, sessions that set `revoap_user_id`, password reset. The Identity
  resolver, the session key and every read filter already speak to it; what
  is missing is the credential surface itself.
- **API key ↔ user binding** — until an `api_keys.user_id` exists, `/v1`
  keys are operator credentials: they MAY pass any `user_id`, and `/v1/runs`
  reads stay operator-scoped. Per-key ownership of reads needs that column
  and a migration.
- **`throttle` action** — steering a blocked-budget user to the cheapest
  models at selection time (see §4).
- **Global/provider/model budget scopes** — the enforcement here is
  `scope='user'`; the generic Budgets panel (all scopes, forecast,
  notification wiring) is still pending per `docs/ADMIN.md` §3.
- **Per-user RATE limits** (runs/minute) — today's limits are per API key
  (`rpm_limit`/`daily_limit`) and per IP; a per-user rate dimension belongs
  with the login surface.

## 7. Verification

```bash
php85 tests/UsageLedgerTest.php    # 73 assertions — ledger invariants
php85 tests/UserBudgetTest.php     # 65 assertions — budgets, windows, alerts, fail-open
php85 tests/OwnershipTest.php      # 43 assertions — Identity + every scoped read
php85 tests/ApiQuotaGateTest.php   # 19 assertions — launch-path wiring & refusals
php85 bin/revoap-i18n.php lint     # 5 locales × the new usage.* catalogue
node tools/build-css.mjs --check   # committed stylesheet matches sources
# LiveSmoke (php85 -S 127.0.0.1:81xx -t .) → 163/163
```
