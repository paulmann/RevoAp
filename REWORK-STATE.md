# REWORK-STATE.md — RevoAp v2.1 continuation state

> **Read this first if you are resuming.** It records what is DONE, what is IN
> PROGRESS, and the exact commands to verify each. Do not redo finished work.

**Repo:** /home/user/revoap · **Branch:** `v2` (GitHub `paulmann/RevoAp`) · **Backup:** /home/user/BACKUP/
**Runtime:** /home/user/tools/php85 (PHP 8.5.8 static build — RE-DOWNLOADED this session from
`https://dl.static-php.dev/static-php-cli/common/php-8.5.8-cli-linux-x86_64.tar.gz` after the
workspace was wiped; php83/php84 are NOT present any more. Debian `php` 8.2 is also installed
system-wide, but the gates below need 8.5 — on 8.2 the suite shows 12 extra, version-caused
failures.)
**Node:** v20.20.2 · **Tailwind deps:** `revoap/node_modules` (a real `npm install`, no longer a
symlink to /home/user/build) · **PHPStan:** /home/user/tools/phpstan.phar (2.3.1 from GitHub releases; run it
SERIALLY — parallel workers get OOM-killed in this sandbox, see Gotcha 19)
**No `curl`/`wget`/`pgrep`/`ps` in PATH** — use `/home/user/tools/php85 -r` or node for HTTP.
`api.github.com` and `dl.static-php.dev` answer 403 to python-urllib but WORK via node `fetch`;
`github.com`, `codeload.github.com` and the npm registry are reachable; apt works (aliyun mirror).

**Snapshot gap found and fixed:** the GitHub `v2` snapshot did NOT include the committed build
artefact `assets/dist/revoap.css` (out of the box LiveSmoke failed 2/163 on it). Rebuilt with
`npm install && node tools/build-css.mjs` and committed — do not delete it: LiveSmoke, the CI
`--check` job and the backup manifest all require it.

---

## Baseline already delivered (v2.0.0) — DO NOT REDO

| Area | State | Verify |
|---|---|---|
| Tailwind + shadcn/ui design system | DONE | `node tools/build-css.mjs --check` |
| Tokens: OKLCH, light+dark, 8 accents, 3 densities | DONE | `ui/tokens.css` |
| Component layer (26 primitives) | DONE | `assets/js/ui/components.js` |
| Responsive shell (mobile bottom-nav + desktop sidebar, 100% width) | DONE | `assets/js/shell.js`, `ui/shell.css` |
| Theme: light/dark/**system** + persistence + no-FOUC inline boot | DONE | `assets/js/theme.js`, `theme-boot.js` |
| i18n en/de/ru/zh/ar, 1455 keys (incl. T5 usage.*), 100% coverage, CLDR plurals, RTL | DONE | `php bin/revoap-i18n.php lint` + `extract` |
| Zero CDN (KaTeX/Mermaid/fonts vendored, CSP self+nonce) | DONE | `tests/LiveSmoke.php` |
| PHP 8.5 readiness, 0 E_DEPRECATED, PdoCompat shim | DONE | `tests/PdoCompatTest.php` |
| Model availability + scored alternatives + fallback | DONE | `tests/ModelFallbackTest.php` |
| Audit (hash-chained), Feature flags, Maintenance, Localization admin | DONE | `src/Admin/`, `AdminController` |
| Dashboard + Queue panels | DONE | `assets/js/dashboard.js`, `queue.js` |
| DB migration 023 (13 tables), schema.sql = 36 tables (35 + the T5 ledger) | DONE | `db/migrations/023_*.sql`, `024_usage_ledger.sql` |
| CI (8 jobs), phpstan.neon, docs ×7 | DONE | `.github/workflows/ci.yml` |

**Test gates (green after T5; before-snapshot /tmp/BASELINE_v2.txt, after-snapshot /tmp/AFTER_T5_BACKEND.txt):**
```
php85 tests/UsageLedgerTest.php    →  73/73   (T5, new)
php85 tests/UserBudgetTest.php     →  65/65   (T5, new)
php85 tests/OwnershipTest.php      →  43/43   (T5, new)
php85 tests/ApiQuotaGateTest.php   →  19/19   (T5, new)
php85 tests/I18nTest.php           → 194/194
php85 tests/ModelFallbackTest.php  → 134/134
php85 tests/PdoCompatTest.php      →  46/46
php85 tests/LiveSmoke.php          → 163/163   (needs: php85 -S 127.0.0.1:81xx -t . ; REVOAP_BASE=<url> overrides the port)
php85 bin/revoap-i18n.php lint     → OK (1452 keys, 5 locales)
node tools/build-css.mjs --check   → OK (92.4 KB artefact committed)
node tools/theme-parity.mjs        → OK (86 checks)
PHP=/home/user/tools/php85 bash tools/run-tests.sh → 86 pass / 6 fail
```
(run-tests.sh defaults `PHP` to `"$ARENA_WORKSPACE"/tools/php85` — pass `PHP=` explicitly.)
The 6 failures are the **v1.9 baseline**, environment-dependent (need provider API
keys; migrations 001–010 were consolidated into schema.sql; need `.config.php`).
Baseline snapshot: `/tmp/BASELINE_v1.9.txt`. **Any NEW failure is a regression.**

**Pending admin panels (schema + honest placeholders shipped, no controller yet):**
Users/RBAC · Budgets · Scheduler · Notifications · Backup — see `docs/ADMIN.md` §3.

---

## Progress this session

| Task | State |
|---|---|
| Backup / continuation infrastructure | DONE (`tools/sync-backup.sh`, this file, `"$ARENA_WORKSPACE"/BACKUP/revoap` = 594 files, secrets excluded) |
| **T2** Theme parity audit | **DONE** — 86 checks green, 1 real WCAG 1.4.11 bug fixed |
| **T3** OpenAI + Anthropic protocols | **DONE** — StreamEncoder + 3 routes, ApiProtocolTest 40/40 |
| **T1** Beta-tester pass | **DONE** — 4 walk phases (141 live HTTP checks) + 6 worker runs on real MariaDB 10.11 strict; findings in `BETA-TEST-FINDINGS.md` |
| **T4** MD.Scribe migration | **DONE** — product-aware Extensions layer, pinned+sha256 release install, legacy md-viewer untouched; MdScribeTest 53/53 + live E2E |
| **T5** Multi-user foundation | **DONE** — migrations 024+025 + ledger + quotas + ownership + 2 panels; 200 new assertions; docs/MULTI_USER.md |
| **T6** Beta-test → fix loop | **DONE** — 9 findings, all FIXED + pinned (2 critical schema-import bugs, 2 worker/runner integrity bugs, the HY093 budget-write killer, the illegal-ENUM safety-net bug, gitignore, Logger contract, is_local); `BETA-TEST-FINDINGS.md` |

**All v2.1 session tasks (T1–T6) are DONE.** The next substantive items live
in `BETA-TEST-FINDINGS.md` (BY DESIGN section) and `docs/MULTI_USER.md` §6
(end-user login, api_keys.user_id binding, throttle action, Budgets panel).

**T5 is DELIVERED — read `docs/MULTI_USER.md` first and do NOT rebuild any of this:**
- `db/migrations/024_usage_ledger.sql` (+ schema.sql) — `revoap_usage_ledger`:
  append-only, one row per model call per settled run PLUS a per-run MARKER row
  (`response_id = 0`). The marker is what makes runs countable from the ledger
  alone and makes the `syncMissing()` sweep terminate. UNIQUE
  `(session_id, response_id)` + INSERT…IGNORE makes every sync idempotent, so
  all completion paths can call it unconditionally.
- `src/Usage/UsageLedger.php` — derives rows from `council_model_responses`
  (source stays authoritative; `bin/usage-ledger-backfill.php` rebuilds), plus
  totals/series/byModel/listUsers/recentRuns/rowsForUser. NULL = "provider
  reported nothing" is carried through for cost/cache/reasoning; aggregates
  answer `cost_rows`/coverage instead of summing gaps as zero; failed runs ARE
  billed (`run_status`); reasoning tokens only where the step has exactly one
  response (shared step → NULL, never a double-count).
- Billing hook: `SessionRepository::setSettledHook()` — the ONE choke point
  every settle path passes through (engine ×4, worker, safety net, manual
  repair). Wired in index.php AND bin/revoap-worker.php; the worker also runs
  `syncMissing(50)` after every job as the crash-window backstop.
- `src/Auth/Identity.php` — admin / user / anonymous resolver. End-user LOGIN
  does not exist yet; `$_SESSION['revoap_user_id']` is the reserved key nothing
  sets. `multiuser.ownership` feature flag (17th flag, shipped OFF) is the
  operator's enforcement switch: OFF = v1.8.6 open visibility unchanged,
  ON = anonymous sees NOTHING. A malformed user identity fails CLOSED.
- Ownership reads: `HistoryService::list/detail(…, ?Identity)` (scoped list
  answers ok+empty, foreign detail answers the same 404 as missing — no
  existence oracle), HistoryController write guards (complete/export),
  ShareController MINT (sharing republishes content, so the mint is the leak),
  `StatisticsService::summary(…, ?int $userId)` (controller maps strict-
  anonymous to scope `-1` = empty shape). Unattributed rows (user_id NULL)
  belong to NO end user; own bucket in the admin panel.
- `src/Admin/Budgets/` — BudgetRepository + UserQuotaEnforcer (+ interface,
  exception). Enforcement: `ApiV1Controller::createRun()` BEFORE the
  sync/stream/async branch (covers /v1/chat/completions + /v1/messages — one
  code path), `JobService::createJob/createBatchJob` BEFORE the session row
  exists, `CouncilController::run/stream` (stream refuses via a PIPELINE_FATAL
  frame + releases the stream lock). 429 `budget_exceeded` with Retry-After at
  the calendar-period end; OpenAI dialect renders `rate_limit_error`. Spend is
  computed LIVE from the ledger (the rollup is refreshed as a side effect,
  never the source). Gated by the `cost.budgets` flag; fails OPEN on
  infrastructure trouble; `throttle` downgraded to warn with a loud
  `throttle_not_enforced`; alerts once per (budget, period, threshold);
  refusals audited as `budget.run_blocked` (RESULT_DENIED, actor `user:<id>`).
- Web-run attribution: index.php's shared `$defaultUserResolver` (local profile
  user) now feeds BOTH JobService and CouncilController, so console runs are
  billable to someone instead of landing unattributed.
- Panels: `assets/js/users.js` → `#oc-users-root` (replaced the pending card;
  keeps an honest "operator RBAC pending" card INSIDE the panel) and
  `assets/js/my-usage.js` → `#oc-myusage-root` (sibling of the statistic root,
  statistic.js untouched). Routes: `GET/POST /api/admin/users*` (literal /sync
  registered BEFORE /{id} — Router matches in order) and `GET /api/my/usage`
  (identity-scoped, NOT admin-gated; anonymous → local user, strict → 403
  identity_required). Panels show the SERVER's verdict — no client-side
  re-implementation of the limit rule.
- `usage.*` catalogue: 66 keys × 5 locales (lint OK, 1452 total). GDPR export:
  `GET /api/admin/users/{id}/export` (audited).
- STILL MISSING from T5's "think beyond" list (tracked in MULTI_USER.md §6):
  end-user login / invitations / password reset, `api_keys.user_id` binding
  (until then /v1 keys stay operator-scoped and /v1 run reads are NOT
  per-user), the real `throttle` action, per-user RATE limits, and the generic
  Budgets panel (global/provider scopes, forecast, notifications).

## v2.1 TASKS (this session)

### T1 · Beta-tester pass over code and interface — **NOT STARTED**
Find real bugs by exercising the running app, not by reading it.
- [ ] Boot: `php85 -S 127.0.0.1:8140 -t .`
- [ ] Walk every tab, every locale, both themes
- [ ] Record findings in `BETA-TEST-FINDINGS.md`

### T2 · Theme parity audit (light ⇄ dark) — **DONE**
`node tools/theme-parity.mjs` → **86 checks, 0 failures**.

Token census: 164 light props / 87 dark props → **87 colour-bearing** (parity
required and verified), **51 theme-independent** (geometry, type scale, motion,
z-ladder, layout metrics — correctly declared once in `:root`), **26 legacy
`--color-*` bridge** aliases (correctly `:root`-only; each is `var(--v2-token)`
so it follows the theme through the underlying token).

**REAL BUG FOUND AND FIXED — WCAG 1.4.11 violation:**
`--border-strong` / `--input` measured **1.48:1 (light)** and **1.79:1 (dark)**
against the page background. Those tokens mark the boundary of an *interactive
component* (input, select, switch), where WCAG 1.4.11 requires **3:1** for the
visual information needed to identify it. Practical impact: a low-vision user
could not see where a field began, and an empty required field was
indistinguishable from an absent one.
→ light `oklch(0.87 …)` → `oklch(0.62 0.012 286.111)` = **3.4:1**
→ dark  `oklch(1 0 0 / 20%)` → `/ 42%` = **3.1:1**
The switch *track* is a fill, not a boundary (state is conveyed by thumb position
and by `--primary` when checked), so it got its own `--switch-track` token at the
old softer value rather than being dragged to 3:1 and looking heavy.
`--border` (decorative card separators) deliberately stays as-is: 1.4.11 applies
to information needed to identify a component, not to every line on the page.

Also verified: no hardcoded hex/rgb/hsl in `ui/tailwind.css` or `ui/shell.css`
(`color-mix(… black)` is allowed — it is a *relative* operation and follows the
theme by construction); every `var()` resolves or has a fallback; all 16
text/graphic contrast pairs clear their floor in both themes; all 7 accent presets
define the 4 brand tokens in both themes and clear 3:1 for their label.

Legacy `assets/css/components/*.css`: **67 hardcoded colour declarations across
20 files** — reported as a migration metric, not a failure (those files predate
the token set and are covered by the bridge). Tracked for `docs/MIGRATION_UI.md`.

Three checker bugs fixed while building it (each would have produced a false
green or a false red):
1. Parity was demanded for ALL tokens; geometry/typography/motion are
   theme-independent by design → 77 spurious failures.
2. The accent reference set was taken from `indigo`, which is the DEFAULT accent
   declared in `:root` and therefore has no `[data-accent="indigo"]` rule → the
   reference was empty and all 7 presets looked wrong.
3. `--shadow-*` was classified as geometry, so its *correct* duplication in the
   dark block (a 6 %-black shadow is invisible on a dark background) was flagged.
   Shadows are colour-bearing.
- [ ] still to do: wrap it as `tests/ThemeParityTest.php` so it runs in
      `tools/run-tests.sh` (needs node; skip gracefully when absent)

### T3 · OpenAI + Anthropic wire protocols — **DONE**

**Discovery that changed the scope:** `src/Api/FormatAdapter.php` (585 lines)
ALREADY parsed OpenAI and Anthropic request bodies and rendered non-streaming
responses in both dialects, and `ApiV1Controller::createRun()` ALREADY branched on
`stream` into a private `streamRun()` that streams the live pipeline. So the real
gap was narrower and more specific than "add protocol support":

1. **The routes an SDK actually calls did not exist.** An OpenAI client posts to
   `/v1/chat/completions`; an Anthropic client posts to `/v1/messages`. Neither
   path was registered, so pointing an off-the-shelf SDK at this server gave a 404
   no matter how correct the translation layer was.
2. **The live stream emitted RevoAp's own event names** (`PIPELINE_STARTED`,
   `PIPELINE_DONE`, …), which neither SDK can parse. There was no way to get a run
   as OpenAI `chat.completion.chunk` frames or Anthropic's typed event sequence.

**Added:**
- `src/Api/Protocol/StreamEncoder.php` — pure encoder, run record → SSE frames in
  three dialects. OpenAI: `chat.completion.chunk` + role in the first delta +
  `finish_reason` + the `data: [DONE]` sentinel as a NON-JSON frame. Anthropic:
  the full documented sequence message_start → content_block_start → ping →
  content_block_delta… → content_block_stop → message_delta → message_stop, each
  with both an `event:` and a `data:` line. Native: real stage boundaries + result.
- `POST /v1/chat/completions`, `POST /v1/messages` — delegate to
  `ApiV1Controller::createRun()` with the route as the format hint, so auth, rate
  limits, idempotency and the audit trail are ONE code path.
- `GET /v1/runs/{id}/stream?format=…` → `ApiV1Controller::replayRunStream()`,
  reusing the same auth gate and `resolveRun()` as `getRunResult()`.
- `tests/ApiProtocolTest.php` — 40 assertions.

**Design decisions worth not re-litigating:**
- The stream is HONEST about RevoAp being a council, not a decoder: real stage
  boundaries and the final answer in bounded frames. Inventing per-token deltas
  would misrepresent what happened and break clients that reason about token
  boundaries. Live progress remains `/api/council/stream`.
- A failed/cancelled run reports `content_filter` / `refusal`, NEVER `stop` /
  `end_turn` — those tell the client the answer is complete and trustworthy.
- Absent or all-zero usage is OMITTED, not rendered as a free run.
- `segment()` splits on CHARACTERS (`mb_substr`), not bytes. `str_split` would cut
  a 3-byte CJK character in half and put invalid UTF-8 on the wire — a bug that
  only appears in non-Latin locales, i.e. exactly where nobody tests. Asserted for
  CJK, Cyrillic and Arabic, including round-trip reassembly.
- Chunking at 1024 chars is not decoration: a single multi-hundred-KB `data:` line
  is truncated by proxies and SDK line buffers, and a consensus answer routinely
  exceeds that.
- The method is named `replayRunStream`, NOT `streamRun` — a `streamRun` already
  existed for the live path. Two methods with that name is a fatal redeclare and a
  maintenance trap.

**Route ordering matters:** the literal `/v1/chat/completions` and `/v1/messages`
are registered BEFORE `/v1/runs/{id}` patterns; `App\Core\Router` matches in
registration order, so a literal registered later would be captured as an `{id}`.
- [ ] `src/Api/Protocol/{OpenAI,Anthropic}Protocol.php`
- [ ] Detect by path (`/v1/chat/completions`, `/v1/messages`) and by body shape
- [ ] Streaming (SSE) in both dialects, incl. Anthropic's event types
- [ ] Error envelope per dialect
- [ ] `src/Api/FormatAdapter.php` already exists — extend, don't duplicate

### T4 · MD.Scribe instead of MD.Viewer — **DONE**
Verify: `php85 tests/MdScribeTest.php` (53/53, fully offline via the installer's
php85 tests/SessionSafetyNetTest.php → 16/16  (T6, new; sqlite CHECK mimics the strict ENUM)
test seam) + Extensions{Api,Settings,PhpCli,TabDom}Test unchanged green + a
live E2E was run once (real 3.3 MB download → sha256 → extract → machine API
answered `{ok:true, version:'3.1.0'}` through the Scribe shim).

What MD.Scribe is: github.com/paulmann/MD.Scribe, v3.1.0 "Aurora", the
successor of MD.Viewer. Its docs/upgrade-from-v2.md keeps EVERY contract this
integration uses: root `md.php?file=` shim, `updater.php?api_key&action&format
=json` machine API with the identical envelope, the same `.md.ini`. What
changed is the install source: Scribe's raw updater.php is a shim that
`require`s src/bootstrap.php — it CANNOT bootstrap a fresh install like the
111 KB self-contained v2 updater, so a fresh install is the release archive
(vendored composer deps inside).

Delivered:
- **Product selection by DIRECTORY EXISTENCE**: `extensions/md-viewer/` on
  disk ⇒ legacy product, every path (download raw updater, machine API, ini,
  share URLs) byte-for-byte as before; otherwise the product is **MD.Scribe**
  in `extensions/md-scribe/`. This rule is also why every offline Extensions
  test passed untouched — their fixtures create the legacy dir.
- **Pinned release install**: `MdViewerApi::SCRIBE_RELEASE_{TAG,ASSET,URL,SHA256}`
  (v3.1.0, sha `69cfbeb3…6b6` — ENFORCED, verify-before-extract; a mismatch
  writes nothing). `MdViewerInstaller::downloadRelease()` +
  `extractRelease()` (public static, testable): staging dir → shape check
  (md.php/updater.php/src/bootstrap.php/src/Core/Version.php) → **the bundled
  .md.ini is DELETED** (the zip ships a PUBLIC sample API_KEY — keeping it
  would authorise the machine API with a credential anyone can download;
  `SCRIBE_SAMPLE_API_KEY` is also rejected on read and rotated away on
  bootstrap) → force-mode backs the old tree up to `.bak-<ts>`, never deletes.
- **Per-product machine-API floor**: Scribe 3.1.0 would fail the viewer-era
  `>= 3.9.0` check; `supportsMachineApiFor()` splits it (scribe ≥ 3.0.0).
  Scribe's version comes from `src/Core/Version.php` (its shims carry no
  `Version:` banner). `installerPresent()` for Scribe requires the engine
  next to the shim — a lone shim is not an installer.
- **PHP ≥ 8.3 gate for the subprocess** (`SCRIBE_PHP_MIN`): Scribe is 8.3+
  (typed class constants); running its shim with an older CLI would answer
  the machine API with a PHP fatal, so `callApi()` probes the resolved CLI
  and fails as `php_cli_too_old` with the exact binary+version (verified
  live: Debian php 8.2 → precise refusal; REVOAP_MDV_PHP_CLI=php85 → works).
- **.md.ini surgery, not rewrite**: `MdViewerIni::mergeInto()` replaces only
  RevoAp's seven keys in place and preserves everything else — a Scribe
  install's ADMIN_PASSWORD_HASH / SITE_NAME / cache keys survive every write
  (render() would have locked the operator out of Scribe's admin panel).
  Scribe's installation-level keys are recognized (`scribe_managed` bucket),
  so the panel no longer calls them "unknown". Legacy keeps render() bytes.
- **Share links follow the installed product**: `installedViewerDir()` checks
  both trees, Scribe preferred; DocumentShareController uses it.
- **Panels/payloads**: `/api/extensions/status` reports `product`, the active
  product's repository/release block, and the other tree when present;
  extensions.js rebranded to MD.Scribe (payload-driven, legacy installs say
  so); i18n `extensions.md_scribe*` added, `md_viewer*` relabeled legacy
  (×5 locales, lint+extract green); docs: AGENTS-context §9, PROJECT_MAP,
  index.php route comments.
- **Naming decision (do not re-litigate)**: the PHP classes KEEP their
  `MdViewer*` names — they ARE the v2-contract layer both products speak,
  and renaming them would break five test suites' pins plus every doc for
  zero behaviour. Everything user-facing says MD.Scribe.
- **Bumping the pinned release** (future): set SCRIBE_RELEASE_TAG/ASSET/URL
  to the new version, re-pin SCRIBE_RELEASE_SHA256 (`sha256sum` of the
  asset), check the sample-key constant against the new zip's bundled
  .md.ini, and extend MdScribeTest's fixture only if the shape changed.
- NOT done (deliberately): no automatic legacy→Scribe migration (Scribe's
  own docs say "v3 never writes to your v2 directory"; an operator migrates
  by installing fresh and carrying API_KEY/BROWSE_DIR — the ini merge makes
  that safe), and MD.Viewer's raw-updater channel stays available for legacy
  updates.

### T5 · Multi-user foundation (admin / user roles) — **DONE**
Full write-up: `docs/MULTI_USER.md` (identity model, ledger invariants, quota
contract, panel semantics, and §6 "not built yet"). Verify:
`php85 tests/{UsageLedger,UserBudget,Ownership,ApiQuotaGate}Test.php` (200
assertions) + the gates block above.

- [x] `src/Auth/Identity.php` — the ONE resolver (admin / user / anonymous,
      fail-closed). Deliberately NO third identity table and no new credential
      columns: operator RBAC stays `revoap_admin_users` (migration 023, its own
      pending panel), end users stay `users`. Login/invitation/password-reset
      is the NEXT layer (MULTI_USER.md §6) — the session key
      `revoap_user_id` and every read filter already speak to it.
- [x] Ownership on reads — HistoryService list/detail (+ complete/export write
      guards), ShareController mint, StatisticsService summary; `multiuser.ownership`
      flag decides what anonymous means; v1.8.6 behaviour is the shipped default.
- [x] `revoap_usage_ledger` — migration 024 + schema.sql, append-only,
      per-call rows + per-run marker, idempotent UNIQUE, settle-hook billing,
      worker sweep, backfill CLI. Cached-response cost attribution: `cached`
      flag + `cache_discount` + NULL-vs-zero honesty per row.
- [x] Per-user quotas — `src/Admin/Budgets/`, enforced at createRun() and both
      other launch funnels, 429 + Retry-After, alerts once per period/threshold,
      refusals audited, `cost.budgets` flag-gated, fail-open on infrastructure.
- [x] Panels — admin Users (`assets/js/users.js`, incl. budget editor + GDPR
      export + resync) and user "My usage" (`assets/js/my-usage.js` inside the
      Statistic tab). `usage.*` × 5 locales.
- [ ] **Think beyond the ask — consciously deferred** (each with its reason in
      MULTI_USER.md §6): invitation flow, password reset, session revocation
      FOR END USERS (admin session registry exists since 023), rate limits per
      user. Audit attribution by real identity works the moment an RBAC login
      sets `$_SESSION['revoap_admin_user_id']` — and until then, budget
      refusals already attribute as `user:<id>`.

### T6 · Beta-test → fix → re-test loop until clean — **NOT STARTED**
Each cycle: reproduce → write the failing assertion first → fix → re-run →
record in `BETA-TEST-FINDINGS.md` with status.

---

## Working method (per operator instruction)

**Every artefact is written TWICE**: `/home/user/revoap/…` (working) and
`/home/user/BACKUP/…` (backup). Sync with `tools/sync-backup.sh` after each
commit. Git is the primary safety net; the backup dir is the second.

```bash
cd /home/user/revoap && git add -A && git commit -m "…" && bash tools/sync-backup.sh
```

**Delegation:** sub-tasks that are pure computation or independent file
generation run as background `nohup … &` jobs writing to `/tmp`, so the main
context is not consumed by their output. Long verifications (full test suite,
lint over 352 files) always run in background and are polled.

---

## Gotchas already paid for — do not rediscover

1. **Backed enums cannot declare `from()`** — it is final. Tolerant parsers are
   named `parse()`. (`Locale::fromTag()` is the older name.)
2. **`defined('PDO::MYSQL_ATTR_*')` does not help on 8.5** — the guard passes and
   the *read* on the next line raises. Use `App\Core\PdoCompat`.
3. **`Config::get()` throws when unbooted.** Library classes use `Config::tryGet()`.
4. **PHP `trim()` strips NUL** — validate control chars on the raw value first.
5. **`RuntimeConfig::merge()` is a reader, not a writer.** Writing is
   `updateChecked()`, and its `ok` flag MUST be surfaced (a silent write failure
   reports "saved" for a setting that was never persisted).
6. **Tailwind cannot generate a class it never sees literally.** `w-${n}%` in JS
   yields nothing; set `el.style.width`.
7. **A `*/` inside a CSS comment terminates it.** `/* --primary*/--ring */` breaks.
8. **`extract` is a PHP builtin** — a CLI helper named `extract()` fatals.
9. **`php -S -t .` serves `/assets/**` from disk**, bypassing `serveAsset()`.
   Web-server-layer rules (`assets/.htaccess`, `nginx/security-hardening.include`)
   are what actually protect it. `LiveSmoke` knows this and adapts.
10. **`array + array` keeps the LEFT operand** for duplicate keys — merging a
    computed list in afterwards silently keeps a stub.
11. **Tests in this repo are standalone scripts**, not PHPUnit. Exit non-zero on
    failure; `tools/run-tests.sh` detects markers + exit code + `N failed` lines.
12. **The suite greps for the word "failed"** — a summary reading `0 failed`
    looks like a failure to a naive harness. Use `tools/run-tests.sh`.
13. **SmokeTest shells out to bare `php`** for its `php -l` checks; those 11
    failures are environmental (no `php` on PATH), not real. (Since this
    session a Debian php 8.2 IS on PATH — SmokeTest still fails for its other
    environmental reasons; do not "fix" those 11.)
14. **`Dialog()` opens on construction** (it pushes its own layer) and returns
    `{el, overlay, close, body}` — there is NO `.open()`. Calling one fatals.
15. **`Switch({label})` returns the ROW div, not the input** — `.checked` on it
    is undefined. Track the value through its `onChange` closure.
16. **`Select(options, opts)` does not wire `opts.onChange`** (rest-props land
    as attributes) — use `sel.addEventListener('change', …)` like admin.js.
17. **A fresh clone of the GitHub `v2` snapshot lacks `assets/dist/revoap.css`**
    (it was never pushed). `npm install && node tools/build-css.mjs` rebuilds
    it; LiveSmoke asserts it serves 200 text/css.
18. **The committed CSS only contains classes some scanned source already
    used.** Before inventing an arbitrary-value class (`min-w-[3px]`,
    `max-h-[16rem]`, `bg-primary/70`…) grep the OTHER js/templates for it — or
    rebuild the CSS in the same commit. `stack-xs` does not exist at all
    (`.stack`/`.stack-sm` are the component classes).
19. **PHPStan's parallel workers get OOM-killed here** (exit 137, "Result is
    incomplete"). Run serially: a temp neon that `includes: phpstan.neon` and
    sets `parallel.maximumNumberOfProcesses: 1`, with `-d memory_limit=2G`.
20. **`StatisticsPeriod::range()`'s `to` is exclusive to the SECOND** — a
    fixture row created in the same second as an implicit "now" anchor falls
    outside the window. Tests must pass an explicit future anchor (the class
    docblock says exactly this).
21. **The sandbox rewrites literal home-dir paths inside heredocs** — python
    heredoc bodies that contain them break quoting. Build such paths inside
    python (`'/ho'+'me/us'+'er'`) or use relative paths.
22. **`bin/revoap-i18n.php extract` never scanned src/ or assets/js/** — it
    did `foreach ([iterA, iterB] as $it)`, iterating the ARRAY OF ITERATORS
    (two objects, `isFile()` false on an unstarted iterator), so only
    index.php was ever checked. FIXED in this session (per-directory
    iteration + concatenation-prefix validation + plural-base acceptance).
    The first honest run found 17 keys: 12 were tn() plural BASES (extractor
    now accepts a base when a CLDR leaf exists), 4 were genuinely missing
    (`models.model`, `queue.stale` — a real UI bug, its `|| 'Stale'` fallback
    never fired because t() returns the key itself —, `fallback.max_
    alternatives`, plus a doc-comment example in i18n.js), now added to all
    five locales (1455 en keys).
23. **`Logger::debug()` DID NOT EXIST** while ~15 catch blocks across the
    codebase called it — each a latent fatal \Error firing exactly when
    something else had already failed. Added in this session (routes through
    log('debug') like every other level). This alone removed 13 PHPStan
    findings.
24. **SmokeTest pins exact index.php constructor strings.** Changing any
    wiring line (ShareController, CouncilController, …) means updating the
    matching `str_contains` pin, or SmokeTest grows a NEW failure on top of
    its environmental ones. Grep `tests/SmokeTest.php` for the class name
    before re-wiring anything in index.php. It also pins the schema.sql
    TABLE COUNT (36 since migration 024) — and `.github/workflows/ci.yml`
    greps for that exact sentence, so both move together.
25. **PHPStan here: ~358 findings on the PRISTINE tree** (2.3.1, empty
    baseline, serial mode) — that is the noise floor, NOT a pass/fail line.
    The gate that matters is the DIFF against pristine (T5+T4+T6 added 0
    and removed 62). CI's composer-pinned 2.1.x may count differently.
26. **A whole bug class is INVISIBLE to the offline suite** because it
    only fires on a real strict-mode MySQL/MariaDB: ENUM membership
    (finding #7 — sqlite stores anything), reserved words in DDL (#2),
    ERROR 1068 double PRIMARY KEY (#1), and reused named parameters under
    ATTR_EMULATE_PREPARES=false (#5 — sqlite tolerates them). T6 kept a
    MariaDB 10.11 running in the sandbox (`mariadbd --user=mysql`, socket
    dir /run/mysqld must exist; DB `revoap_beta`, user `revoap`, DSN via
    gitignored `.sql.php`) — restore it for any future schema/DB work and
    import db/schema.sql FIRST: it is now executable end-to-end, which
    before T6 it never was.
