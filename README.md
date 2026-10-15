# RevoAp

**AI Consensus Platform** — Multi-LLM deliberative intelligence engine.

> Version: **2.0.0** | PHP **8.5+** | MySQL 8.x | BotHub + OpenRouter
> Tailwind CSS + shadcn/ui · light/dark/system · 5 languages incl. RTL · zero CDN

RevoAp takes a natural-language query, routes it through a **four-layer AI council
pipeline** (L0 skill selection → L1 parallel model answers → L2 structural
normalisation → L3 semantic consensus → L4 synthesis), and delivers one
synthesised answer with the full audit trail visible: every model response, every
token count, every consensus score, every substitution.

---

## v2.0.0 — what changed

A full front-end rebuild, internationalisation, a persistent model-availability
layer with scored alternatives, five new administrative subsystems, and PHP 8.5
production readiness. **[BREAKING]** one default changed — see the upgrade note
below.

### Interface

* **Tailwind CSS + a shadcn/ui-compatible component layer.** shadcn/ui is a React
  registry and this is a vanilla ES-module SPA, so what was ported is its
  *contract*: the same token names, class names, `data-slot`/`data-state`
  attributes and ARIA wiring. 26 primitives in `assets/js/ui/components.js`.
  Compiled output is one **committed** file, 93 KB raw / **16.6 KB gzipped** — a
  deployment needs no Node and no `npm install`. → [`docs/UI_GUIDE.md`](docs/UI_GUIDE.md)
* **Three independent appearance axes**, each persisted, each resolved before the
  first paint: colour mode (`light` / `dark` / **`system`** as a first-class
  value), eight accent presets that rebrand the whole console from one token, and
  three densities. No flash of the wrong theme — an inline nonce'd script in
  `<head>` writes `class="dark"`, `lang` and `dir` before any stylesheet loads.
* **Responsive, mobile-first, and 100 % width on wide screens.** Bottom navigation
  with `env(safe-area-inset-bottom)` and a drawer under 768 px; a collapsible
  icon-rail sidebar above. The shell has **no `max-inline-size` at any breakpoint**
  — only long-form prose opts into a 78 ch measure.
* **Command palette** (`Ctrl`/`⌘`+`K`) over every destination, appearance control
  and registered panel action, plus `g`-prefix goto.
* **Progressive enhancement.** The header, tab registry and the "admin surface is
  unprotected" disclosure are rendered by PHP; `shell.js` *enhances* them. The
  console is navigable with scripting disabled.
* **Full RTL.** `dir="rtl"` mirrors the entire shell because every directional rule
  uses logical properties. There is no override stylesheet.

### Internationalisation

English, German, Russian, Simplified Chinese and **Arabic (RTL)**. 1 385 keys per
catalogue at **100 % coverage**, CLDR plurals (including the Russian mod-100
exception and the Arabic six-way system with a grammatical dual), locale-aware
numbers/percentages/dates with and without `ext-intl`. Catalogues are **embedded in
the document**, not fetched — a round-trip would render English for 200 ms and then
flip the page direction for an Arabic reader. `php bin/revoap-i18n.php lint` is the
CI gate. → [`docs/I18N.md`](docs/I18N.md)

### Zero CDN

KaTeX, Mermaid and every font are vendored. The CSP is `'self'`-only with a
per-request nonce: `object-src`, `frame-src`, `frame-ancestors` and `child-src` are
all `'none'`, and `'unsafe-inline'` is gone from `style-src`. Enforced in four
independent places so it cannot regress. → [`docs/NO_CDN.md`](docs/NO_CDN.md)

### Model availability and alternatives

A **persistent** availability layer on top of the per-run circuit breaker, with a
five-signal weighted **fit score** whose components are exposed so a substitution
can be argued with rather than accepted. Hard constraints (context window, vendor
lock, latency ceiling) exclude candidates outright; soft preferences only rank
them. Four modes: `never` / **`auto`** / `ask` / `strict`. A scheduled monitor
probes models nobody has called recently, so a recovered model stops being
substituted out and a dead one is known before a user hits it.
→ [`docs/MODEL_FALLBACK.md`](docs/MODEL_FALLBACK.md)

### New administrative subsystems

**Users & access** (RBAC, Argon2id, TOTP 2FA with recovery codes, revocable
session registry, scoped API keys with IP allow-lists) · **Audit trail**
(append-only, SHA-256 **hash-chained**, so a row deleted at the database level
breaks the chain and says where) · **Backup & restore** (sealed with a passphrase,
checksum-verified before a restore) · **Scheduler** (cron-driven tasks with an
overlap guard) · **Budgets & alerts** (per-scope spend limits with forecast and
three actions at the ceiling) · **Notifications** (SMTP, webhook with HMAC-SHA256
signing, Telegram, Slack, Teams, ntfy — credentials stored encrypted) · **Feature
flags** · **Maintenance mode** · **Queue manager** with a dead-letter queue ·
**Localization admin** · **System** (runtime report, OPcache state, writable paths,
build id). 13 new tables in migration `023`.

### PHP 8.5

`composer.json` requires `^8.5`. **344 files lint clean and the suite emits zero
`E_DEPRECATED`** — `curl_close()`, `Reflection*::setAccessible()` and the
`PDO::MYSQL_ATTR_*` constants were all deprecated in 8.5, and merely *reading* one
of those constants raises, so the usual `if (defined(...))` guard does not help.
`App\Core\PdoCompat` resolves them once from whichever spelling the runtime
provides. → [`docs/PHP85.md`](docs/PHP85.md)

### Security

Nine findings fixed, documented with their reasoning in
[`docs/SECURITY.md`](docs/SECURITY.md). The most serious was pre-existing: the
shipped `config.php` set `Protect_Settings_by_Admin_Key => 0`, leaving Settings
(which writes provider API keys and the DSN), Logs, Security and the whole operator
surface open to anyone who could reach the host — the one configuration its own
adjacent comment warns against.

> **[BREAKING] Upgrade note.** The admin gate now defaults to **on**, and it fails
> *closed*: protection on without a `.admin.key` file means the admin surface is
> unreachable. Create the key **before** deploying:
> ```bash
> openssl rand -hex 32 > .admin.key && chmod 600 .admin.key
> chown www-data:www-data .admin.key
> ```
> To keep an open intranet install, set `0` explicitly in `.config.php` — the UI
> then discloses that state in a server-rendered banner.

### Testing

Four new suites, **537 assertions**: `I18nTest` (194) · `ModelFallbackTest` (134) ·
`LiveSmoke` (163, end-to-end against a real server) · `PdoCompatTest` (46).
`LiveSmoke` is the only test that asserts on **response bytes** rather than source
text, and it is what found three of the nine security defects — the open admin
gate, the missing `woff2` MIME type (which would have made every local font fail
silently under `nosniff`), and the statically-served templates.

CI runs 8 jobs on `.github/workflows/ci.yml`, including the i18n suite **without**
`ext-intl` so the fallback path cannot rot, and an advisory PHP-next lane.

---

## v1.8.18 highlights

- **Refreshed fresh-install default model selection.** A brand-new
  install now pre-selects, in order, `openai/gpt-chat-latest`,
  `anthropic/claude-sonnet-4.6`, `google/gemini-3.1-pro-preview` and
  `deepseek/deepseek-v4-pro` on OpenRouter. `openai/gpt-5.5` is still
  shipped in the OpenRouter catalogue and can be enabled from
  Settings, but is no longer one of the four pre-selected defaults.
  Any operator-supplied selection (via `pipeline.default_selected_models`
  in `config.php` / `.config.php`, or saved through the Settings UI)
  wins unchanged.

## v1.8.17 highlights

- **Semantic-component consensus scoring.** L3 no longer compares model
  answers as raw token bags. The new `SemanticConsensusEngine` extracts
  semantic components (claims / bullets / sentences) from each model's
  reply, clusters them across models using keyword stems, character
  3-grams and numeric / named-entity hints, and scores consensus as
  the fraction of cross-model semantic content covered by shared
  clusters. Russian and English paraphrases of the same idea now score
  in the partial / agreement band rather than collapsing to ≈ 0.07.
  Legacy `lexical_jaccard` scoring is preserved behind
  `pipeline.consensus.scoring = lexical_jaccard` and a per-skill
  `consensus_scoring` override. See [`docs/consensus-research.md`](docs/consensus-research.md)
  for the methodology and citations.
- **Selectable consensus skill on the main page.** The chat form now
  exposes a `Consensus skill` dropdown driven by `/api/consensus/skills`.
  The new bundled `semantic-consensus-reasoning` skill is the default
  and pairs with the semantic scorer; the legacy `consensus-reasoning`
  skill remains available. The selection is honoured per request — the
  pipeline picks the round-2 system prompt and the scoring backend
  from the active skill.
- **Share button + read-only public share links.** A completed pipeline
  result can be shared via `POST /api/share/session` (used by the
  Chat tab's new *Share* button). The endpoint mints a 64-character
  opaque hex token (256 bits of entropy from `random_bytes`) and the
  share URL `/shared?token=…` renders a read-only view of the query,
  final answer and full consensus / audit history. The shared page
  never exposes model selection controls, Settings, Logs, Security,
  History/Statistic admin panels or the ability to run pipelines, and
  all rendered text is passed through the secret-redaction filter.
  Tokens are persisted in `shared_sessions` (migration `006_shared_sessions.sql`).

## v1.8.16 highlights

- **Round-2 consensus with peer answers.** When the L3 verdict after
  round 1 is not `agreement`, the pipeline now re-runs every L1 model
  call with peer context: each model receives the original query, its
  own previous answer, every OTHER model's answer (clearly labelled by
  role / provider / model — its own answer is NOT in the peer block),
  the round-1 consensus score, and a new lateral-thinking system
  prompt (`round2_system_prompt` on the
  `consensus-reasoning` skill). Models are asked to revise OR defend
  their position using peer evidence rather than blindly copying.
  Round budget is governed by `pipeline.max_consensus_rounds` (default
  `2`). UI groups rounds under the existing "Consensus Round N"
  headers automatically.

## v1.8.5 highlights

- **Pipeline UX overhaul.** A new "Current activity" card pinned to
  the bottom of the pipeline always shows what the backend is doing
  right now (live spinner, stage, elapsed time, last-update timestamp);
  on completion it switches to a green `completed` state and to a red
  `failed` state on aborted runs. Consensus rounds are now visually
  grouped by "Consensus Round N" headers. The L3 (consensus) step gets
  a dedicated accent-bordered card with a verdict badge, score gauge,
  participants/pairs tiles and structured sections. The Final Answer
  is rendered in its own visually-distinct section outside the
  pipeline so it is never confused with a regular step card.

## v1.8.4 highlights

- **Robust admin token detection** — `.admin.key` is now resolved
  relative to the project root (the directory holding `config.php` /
  `index.php`), independently of `Config::rootDir()` and the process
  cwd. It works under php-fpm, `php -S`, CLI, and tests. When the
  file exists but the PHP process cannot read it, the admin tabs now
  surface a permissions hint instead of the generic "Admin token not
  configured" message. `/api/tabs` and the per-tab `*/status`
  endpoints expose a non-secret `admin_diagnostics` block
  (`source`, `env`, `file.{exists,readable,empty}`) — the token
  value itself is never returned. **Operators: place `.admin.key` in
  the project root next to `config.php`, `chmod 600`, and ensure it
  is owned by (or readable by) the php-fpm user (e.g. `www-data`,
  `nginx`). Do NOT put `REVOAP_ADMIN_TOKEN` in `config.php` —
  that variable is read from the environment only.**

## v1.8.0 highlights

- **Admin Statistic tab** — aggregated usage of the council pipeline over selectable windows (`All` / `Year` / `Quarter` / `Week` / `Day` / `Hour`): session totals (completed/failed/running), prompt/completion/total tokens, cached and reasoning tokens, cache-hit counts, total and average latency, total and average duration, and provider-reported cost (`null` when not available — never fabricated as `$0.00`). A per-LLM table breaks every metric down per `(provider, model)`. Gated by `StatisticTab=true` AND the admin token AND a configured database. Default `false` because the metrics describe operational and per-user usage. Adds migration `005_statistics_indexes.sql` (idempotent, indexes only). See [`docs/Statistic.md`](docs/Statistic.md).
- **Tab probe extended** — `/api/tabs` now returns `{settings, logs, security, history, statistic}`.

## v1.7.0 highlights

- **Admin History tab** — browse every past council session (prompt, final answer, every L0/L1/L2/L3/L4 step, every per-step model response, every event, consensus result and the runtime options snapshot) from the UI. Server-paginated list with year/month grouping, search across query / answer / skill slug, status filter. Gated by `HistoryTab=true` AND the admin token AND a configured database. Default `false` because every prompt and model response is stored in plaintext (subject to the standard secret-redaction filter). Adds migration `004_history_session_metadata.sql` (idempotent) which introduces `council_sessions.skill_slug`, `council_sessions.options_json` and supporting indexes. See [`docs/History.md`](docs/History.md).
- **Tab probe extended** — `/api/tabs` now returns `{settings, logs, security, history}`.

## v1.6.0 highlights

- **Admin Security tab** — manage the runtime IP firewall from the browser: enable/disable whitelist or blacklist enforcement, edit IP/CIDR rules (IPv4 + IPv6), configure trusted reverse proxies for safe `X-Forwarded-For` handling, and run an ad-hoc IP rule test. Loopback (`127.0.0.0/8`, `::1/128`) is always allowed, the current admin IP is auto-added on every visit, and any loopback rule pasted into the blacklist is silently stripped — operators cannot lock themselves out. Gated by `SecurityTab=true` AND the admin token. Enforcement runs at the front of every request (`IpFirewall::enforce`) and denied attempts are logged sanitized. See [`docs/Security.md`](docs/Security.md).
- **Tab probe extended** — `/api/tabs` now returns `{settings, logs, security}` so the SPA can hide tabs that are off in `config.php` without a round-trip per probe.

## v1.5.0 highlights

- **Config-driven tab visibility** — `SettingsTab`, `LogsTab` and (since 1.6.0) `SecurityTab` flags in `config.php` (top-level or under `app.*`) decide which top-nav tabs render. Strict comparison: only boolean `true` enables a tab. When `false`, the matching API surface returns `404 *_disabled`.
- **Admin Logs tab** — view the last N lines of whitelisted log files (`revoap-debug.log` / `revoap-error.log` / `php-error.log` / `access.log`), refresh on demand, optionally auto-refresh every 5 s, and (with confirmation) truncate a file. Output is sanitized through the same redactor as the structured debug logger — Bearer tokens, `sk-…` API keys, DSN passwords and absolute paths never reach the wire. Gated by `LogsTab=true` AND the admin token. See [`docs/Logs.md`](docs/Logs.md).

## v1.4.0 highlights

- **Admin Settings tab** — manage OpenRouter / BotHub keys, the SQL DSN, the active model list, migrations, runtime flags and the model-response cache (statistics + purge) from the browser. Gated by `REVOAP_ADMIN_TOKEN` env or a `.admin.key` file at the project root. Secrets are stored in `.{provider}.key` files (0600) and never echoed back. See [`docs/Settings.md`](docs/Settings.md).
- **Runtime overrides** — `var/config/runtime.json` is merged on top of `config.php` so every Settings change takes effect on the next request, without editing the static config and without a PHP-FPM reload.
- **Migration tracker** — `db/migrations/*.sql` files are inspected and applied through a `schema_migrations` table; "already exists" errors are treated as benign so re-runs are safe.

## v1.2.0 highlights

- **Resilient MySQL** — repository writes survive idle disconnects (SQLSTATE HY000 2006/2013, "MySQL server has gone away") via a one-shot reconnect-and-retry wrapper. Long parallel L1 + L4 calls no longer abort with `PIPELINE_FATAL` after a wait_timeout drop.
- **Fatal finalisation** — when the pipeline aborts mid-flight, every step still in `running` is marked failed and a `*_FAILED` event is emitted so the UI replaces stale RUNNING cards with a readable failure.
- **Single-stream SSE** — the front-end closes any active `EventSource` before opening a new one and on every terminal `PIPELINE_DONE`/`PIPELINE_FATAL`, so duplicate runs cannot occur.
- **Model response cache** — successful chat responses are cached by sha256(provider, model, messages, reasoning, options). Hits skip the network call entirely and surface as `cache_hit=true` in `L1_RESPONSE` / `L4_SYNTHESIS` events.
- **Diagnostics** — `/api/debug` now lists expected routes (including `/api/council/stream`), the cache backend status, and a build_id so operators can confirm a deploy has actually rolled out.

### Operator notes

- **MySQL `wait_timeout`** — a low value (default 28800s, but some hosts set 60s) will trip the gone-away path. Either raise it for the RevoAp schema's user, or rely on the new auto-retry. See `docs/Storage.md`.
- **nginx SSE buffering** — for `/api/council/stream`, set `proxy_buffering off; proxy_cache off; gzip off;` (Apache: `SetEnv no-gzip 1`). Without this, the EventSource looks frozen and the client may auto-reconnect.
- **Stale deploy** — if `curl -N 'https://<host>/api/council/stream?query=test'` returns `{"error":"not found"}`, the deployed code predates v1.1.0. Pull, restart PHP-FPM (or run `opcache_reset()`), and verify the build_id at `/api/debug`.
- **Cache invalidation** — set `cache.model_responses.ttl_seconds` in `config.php`, drop rows from `model_response_cache`, or remove files under `var/cache/model-responses/`.

## Overview

RevoAp accepts a natural-language query, routes it through a **4-layer AI council pipeline**
(Distribution → Normalization → Consensus → Synthesis), and delivers a synthesized answer
with full transparency — every model response, token count, and consensus score is visible.

## Key Features

- Multi-model, multi-role council sessions via **BotHub.chat** and **OpenRouter.ai**
- **Per-query consensus pool** — pick 3+ models from the sidebar; selection persists in localStorage
- **Parallel L1 dispatch** via `curl_multi` — every selected model is invoked concurrently
- **Live SSE pipeline updates** — each L0/L1/L2/L3/L4 step is rendered as soon as it completes
- **Reasoning / thinking modes** (Off / Low / Medium / High) forwarded to OpenRouter chat completions
- **Hover tooltips** on every layer / event chip explaining the stage and its purpose
- Universal **connector architecture** — add any new LLM by dropping one file
- **SKILLS** system: declarative JSON workflows defining agent roles and consensus rules
- **Forward-only pipeline** — append-only event log, no history rewriting
- Per-step **statistics tabs**: tokens, latency, model, provider, cost, cached & reasoning tokens
- **Session statistics**: total tokens per model, efficiency metrics
- **Light/dark theme** with system preference detection
- PHP 8.4 strict types, readonly properties, enums throughout
- MySQL 8.x with PDO, prepared statements, explicit transactions

## Quick Start

```bash
git clone https://github.com/paulmann/AI-RevoAp.git
cd AI-RevoAp
composer install                              # optional; src/autoload.php is used as a fallback
cp .env.example .env                          # or set keys in config.php / .{provider}.key files
mysql -u root opencorum < db/schema.sql       # optional; in-memory fallback works without DB
php -S localhost:8080 -t .
```

### Database setup

RevoAp boots without MySQL — the in-memory fallback is fine for trying
the council pipeline locally. To persist sessions, the History tab and
the Statistic tab, point `database.dsn` (in `config.php` or via the
admin Settings tab) at a MySQL 8.x database and load the consolidated
schema:

- **Fresh install** (empty database) — apply the consolidated schema in
  one shot:

  ```bash
  mysql -u root opencorum < db/schema.sql
  ```

  `db/schema.sql` is the **single source of truth**: it contains every
  column, table and index the app needs — including the job subsystem
  (`revoap_jobs`, `revoap_job_events`) and the memory store
  (`memory_items`) — as idempotent `CREATE TABLE IF NOT EXISTS`
  statements. The `db/migrations/` directory holds the incremental
  migrations `014…017` added after the `001…013` chain was consolidated
  (see `db/migrations/README.md`), so there is nothing to apply on top of
  a fresh import.

- **Upgrade an existing RevoAp database** — `db/schema.sql` is safe
  to re-import against an already-populated database (all statements
  are idempotent). The usual upgrade path is a *new* migration shipped
  under `db/migrations/NNN_*.sql` when a schema change must reach
  existing deployments; apply it through the admin Settings tab →
  Database maintenance → **Apply Migrations** (or the per-file Apply
  button). The `MigrationManager` skips any migration whose name
  already lives in `schema_migrations` and treats `already exists` /
  `duplicate column` errors as benign so re-runs are safe.

Append `?creator` to any URL to view author info (no configuration data is exposed there).

### External REST API (v1.8.43+)

RevoAp ships a REST API for launching consensus runs, polling results
and receiving webhooks. Authentication is by API keys (`rv_*`), which
operators create on the **Settings → API** tab (SHA-256-hashed at
rest, scopes `full`/`read`, per-key `rpm`/`daily` quotas plus a
per-IP budget of 120 req/min).

```bash
# Launch (async 202), then poll to completion.
curl -fsS -X POST 'https://your-host/v1/runs' \
  -H 'X-API-Key: rv_…' -H 'Content-Type: application/json' \
  -d '{"query":"Compare MySQL and PostgreSQL",
       "models":[{"provider":"openrouter","model":"openai/gpt-5.5"}]}'
curl -fsS 'https://your-host/v1/runs/123/result' -H 'X-API-Key: rv_…'

# Same API through the OpenAI / Anthropic dialect (?format=openai|anthropic).
curl -fsS -X POST 'https://your-host/v1/runs?format=openai' \
  -H 'X-API-Key: rv_…' -H 'Content-Type: application/json' \
  -d '{"model":"openai/gpt-5.5","provider":"openrouter",
       "messages":[{"role":"user","content":"Hello"}],"async":false}'
```

Full reference (launch modes sync/202/SSE-stream, read endpoints,
errors, HMAC-signed webhooks with retries, examples):
[`docs/api-rest.md`](docs/api-rest.md).

Set your API keys in `config.php` or create key files:
```bash
echo 'your-bothub-key'     > .bothub.key
echo 'your-openrouter-key' > .openrouter.key

# Direct providers (v1.8.15+) — flip connectors.{provider}.active=true
# in config.php first, then drop the matching key file. Alias names
# (.claude.key, .gemini.key, .google.key, .grok.key) and per-model
# files (.gpt-5.5.key, .claude-sonnet-4.6.key) are also recognised.
echo 'sk-...'              > .openai.key
echo 'sk-ant-...'          > .anthropic.key       # or .claude.key
echo 'AIza...'             > .gemini.key          # or .google.key
echo 'sk-...'              > .deepseek.key
echo 'xai-...'             > .xai.key             # or .grok.key
```

### Model catalogue (v1.8.15+)

The shipped model list lives in three files under `config/models/`:

- `config/models/openrouter.php` — top 10 OpenRouter slugs (latest GPT,
  Claude, Gemini, DeepSeek, Grok, Qwen).
- `config/models/bothub.php` — top 10 BotHub slugs (no vendor prefix).
- `config/models/direct.php` — top 5 direct providers (OpenAI,
  Anthropic, Google/Gemini, DeepSeek, xAI/Grok).

Each row carries `provider`, `model`, `label`, `rank`, `category`,
`active`, and `default`. `Config::load()` walks the `model_files` key
in `config.php` and merges these files into the runtime `models`
list. An operator-supplied `models` list (in `config.php`,
`.config.php`, or `runtime.json`) wins over the shipped catalogue —
all existing deployments keep working unchanged.

## Documentation

- [`TECHNICAL_SPECIFICATION.md`](TECHNICAL_SPECIFICATION.md) — Full architecture and requirements
- [`SKILL.md`](SKILL.md) — Perplexity Computer skill for AI-assisted development
- [`src/Connectors/HOW_TO_CREATE_CONNECTOR.md`](src/Connectors/HOW_TO_CREATE_CONNECTOR.md) — Guide to adding new LLM providers
- `src/[Module]/[Module].md` — Per-module documentation for each component

## Project Structure

See `TECHNICAL_SPECIFICATION.md` Section 2 for the full directory tree.

## API routing & front-controller

RevoAp has **no `api/` directory on disk**. Every URL beginning with
`/api/...` is a *virtual route* registered with the in-process router
(`src/Core/Router.php`) and dispatched by the front controller `index.php`.
Your web server must therefore forward every request that does not match
a real file or directory to `index.php`.

### Apache (mod_rewrite)

The bundled `.htaccess` already does this:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

Make sure `AllowOverride All` (or `AllowOverride FileInfo`) is set for the
project directory in your vhost so `.htaccess` is honoured, and that
`mod_rewrite` is loaded (`a2enmod rewrite` on Debian/Ubuntu).

### Nginx (PHP-FPM)

```nginx
server {
    listen 443 ssl http2;
    server_name bank.revoap.com;
    root /var/www/revoap;
    index index.php;

    # Static assets
    location /assets/ {
        try_files $uri =404;
        access_log off;
        expires 1h;
    }

    # Front-controller fallback — every non-existent path goes through index.php.
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    # PHP execution (split path-info so /index.php/api/... still works).
    location ~ ^/index\.php(/|$) {
        include       fastcgi_params;
        fastcgi_pass  unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO       $fastcgi_path_info;
    }

    # Block direct access to sensitive files.
    location ~* /\.(env|.+\.key)$            { deny all; }
    location ~* /(config\.php|composer\.(json|lock))$ { deny all; }
}
```

If `/api/health` works but `/api/council/session/<id>` 404s, routing is
healthy and the issue is **application-level** (typically the in-memory
storage mode, see Debug below).

## Deployment & cache invalidation

RevoAp is a single-front-controller PHP app. After pulling a new version
on the server, **you must clear caches that may still reference the previous
build**, otherwise stale code or stale browser assets will mask the fix:

```bash
git pull --ff-only
# Reload PHP-FPM so the new sources are read (OPcache reset).
sudo systemctl reload php8.4-fpm        # or: kill -USR2 $(pgrep -f php-fpm)
# If OPcache is not invalidated by reload (validate_timestamps=0):
php -r 'opcache_reset();' || true
# Hard-refresh the browser (Ctrl+Shift+R / Cmd+Shift+R) — the asset URLs
# now include ?v={app_version}.{mtime} so a new deploy auto-busts caches,
# but a tab opened before the deploy can still hold the old JS module.
```

`/api/debug` returns `app.build_id` (the short git SHA when `.git/` is
present, otherwise the asset-mtime token). Compare it with `git rev-parse
--short=12 HEAD` on the server to confirm the deployed code matches the
commit you expect:

```bash
curl -fsS https://your-host/api/debug | jq .app
# { "name": "RevoAp", "version": "1.0.4", "debug": false, "build_id": "abcdef012345" }
```

If `app.build_id` is older than HEAD, your reload/OPcache reset did not
take effect. Confirm L1 routes through the available provider (typically
`openrouter` when no `bothub` key is configured):

```bash
curl -fsS -X POST 'https://your-host/api/council/run?debug=1' \
     -H 'Content-Type: application/json' \
     -d '{"query":"hello"}' \
  | jq '.debug.trace[] | select(.type=="L1_ROLE_ASSIGNED") | .payload.provider'
```

The session response also exposes the per-LLM cost-and-token breakdown
under `stats.per_model`. Each model row includes `prompt_tokens`,
`completion_tokens`, `total_tokens`, `cached_tokens`, `reasoning_tokens`,
`calls`, `latency_ms`, `avg_latency_ms`, `cost`, `cost_available` and
`tokens_estimated`. The UI renders the same data as a "Detailed per-LLM
breakdown" table beneath the session summary.

```bash
curl -fsS -X POST 'https://your-host/api/council/run' \
     -H 'Content-Type: application/json' \
     -d '{"query":"hello"}' \
  | jq '.stats | {total_tokens, total_cost, cost_available, per_model}'
```

If the response still shows the synthesis model as `synthesis` / provider
`—`, or L1 still references `bothub` despite `bothub.api_key` being empty,
the deployed build is older than commit `bc4f792` (L1 silent-skip fix) and
`d8e6a70` (per-step Statistics fix). Repeat the steps above — all three
caches (OPcache, in-memory routes, browser modules) must be cleared
together.

## Debug mode

Two equivalent triggers expose a sanitized diagnostic snapshot:

- `GET /api/debug` — dedicated route, always on.
- `?debug=1` (or `?DEBUG=1`, `?debug`) — query-string override; works on
  any URL, including `POST /api/council/run`.

Setting `app.debug=true` in `config.php` enables debug output on every
request without the query parameter, plus per-request structured logs to
`logs/revoap-debug.log`. The diagnostic payload contains:

- `app`, `request.resolved_path` (post-normalization),
- `database.{configured,connected,storage_mode,error_class,error_message}`
  — `storage_mode` is `memory` when no DSN is configured or the connection
  failed, `persistent` otherwise,
- `connectors.{discovered,available,skipped,detailed[].reason}` — every
  unavailable connector explains *why* (key missing / inactive / not
  configured),
- `pipeline.active_models[].available` — which active models point at a
  provider whose connector is currently usable,
- `pipeline.stage_plan` — the L0–L4 layers as a static plan,
- `hints` — actionable next steps including the **silent-skip detector**:
  if every active model belongs to an unavailable provider, this hint
  fires.

`POST /api/council/run` with debug enabled also returns a `debug`
envelope containing the pipeline trace (`L1_MODEL_SELECTION`,
`L1_ROLE_ASSIGNED`, `L4_SELECTED`/`L4_FAILED`) and a snapshot of the
diagnostic report. `GET /api/council/session/{id}` returns a debug
section explaining storage mode and the last DB error class on 404.

API keys, DSN passwords and absolute filesystem paths are never written
to the response or to the log file — every payload is passed through
`Logger::sanitize()` first.

## Pipeline Architecture

```
User Query
    ↓
[L0] Skill Router  →  Auto-create Skill if needed
    ↓
[L1] Distribution  →  Parallel calls to all roles across models
    ↓
[L2] Normalization →  Internal: reshape each L1 answer into discrete claims
                     (no LLM call; output is "Normalized answer from role X
                      into N claims for consensus")
    ↓
[L3] Consensus     →  Agreement scoring + conflict detection
    ↓
[L4] Synthesis     →  Final answer + full audit trail
```

L1 and L4 step cards render the **complete model response** in the Response
tab — the historical 240-character preview was promoted to the full content
in v1.0.4 / persisted as MEDIUMTEXT (`council_steps.summary` in `db/schema.sql`).
A short preview is still kept under `meta.summary_preview` for compact list
views.

## Validating model IDs

Provider catalogs change. A retired model id returns HTTP 404 ("No endpoints
found" on OpenRouter); since v1.0.5 the L1_FAILED event includes a hint
pointing at the provider's `/models` endpoint. The default `config.php`
ships only model ids verified at release time — see
[`docs/Connectors.md`](docs/Connectors.md#validating-model-ids) for the
verification commands and the current default list.

## License

MIT
