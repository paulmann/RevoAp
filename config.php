<?php
/**
 * RevoAp Configuration
 *
 * Connector API keys can also be stored in .{provider}.key files
 * in the project root, or in environment variables (e.g. BOTHUB_API_KEY).
 *
 * Admin authentication: the admin token is NEVER stored in this file.
 * `OPEN_CORUM_ADMIN_TOKEN` is the *environment variable* name (not a
 * `config.php` key). The token value lives either in that env var or in
 * the git-ignored `.admin.key` file at the project root. The `admin`
 * block below records only the *names* of those locations so operators
 * browsing the file know how authentication is wired.
 *
 * Model catalogues live in dedicated files under `config/models/`:
 *
 *   - `config/models/openrouter.php` — top OpenRouter slugs (top 10).
 *   - `config/models/bothub.php`     — top BotHub slugs (top 10).
 *   - `config/models/direct.php`     — top 5 direct providers/models
 *                                      (OpenAI, Anthropic, Gemini,
 *                                      DeepSeek, xAI/Grok).
 *
 * `Config::load()` reads each file and concatenates the rows into the
 * runtime `models` list. Existing deployments that still set `models`
 * here, in `.config.php`, or via `runtime.json` keep working: a
 * non-empty operator-supplied list wins over the shipped files
 * (backwards compatible). The shipped `models` key below is therefore
 * left as an empty array — the model files are authoritative.
 *
 * Deployment-specific root-file overrides (kept out of git). All are
 * optional. Application order — later wins:
 *
 *   base config.php
 *     < config/models/*.php  (shipped catalogues, applied when no
 *                             operator-supplied `models` list is set)
 *     < .config.php          (recursive leaf-merge of any section)
 *     < .url                 (overrides app.url)
 *     < .sql.php             (replaces the database block — emergency)
 *     < runtime.json         (admin Settings UI saves)
 *
 *   - `.config.php` PHP file returning a partial config tree. Recursively
 *                   merged into config.php — only the leaves you set are
 *                   overridden; sibling keys (app.name, database.dsn,
 *                   connectors.bothub.api_key, …) are preserved. List
 *                   arrays such as `models` are leaf values and replace
 *                   the base list wholesale. Use this for environment
 *                   tuning (e.g. flipping app.debug, picking a session
 *                   lifetime) without forking config.php.
 *   - `.url`       plain text — overrides `app.url` with its trimmed value.
 *   - `.sql.php`   PHP file that returns either
 *                    ['database' => [...]]
 *                  or the database fields directly:
 *                    ['dsn' => '...', 'user' => '...', 'password' => '...',
 *                     'options' => [...]]
 *                  Anything else is ignored. See src/Core/Config.php.
 *   - `.admin.key` plain-text single-line admin token (chmod 600).
 *
 * Direct provider keys can be supplied in any of three places (highest
 * precedence first):
 *
 *   1. `connectors.{provider}.api_key` in this file
 *   2. `.{provider}.key`         — e.g. `.openai.key`, `.anthropic.key`
 *      (alias dot-files also recognised: `.claude.key`, `.gemini.key`,
 *       `.google.key`, `.grok.key`)
 *   3. `{PROVIDER}_API_KEY` env var (e.g. `OPENAI_API_KEY`)
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-05
 */
declare(strict_types=1);

return [
    'app' => [
        'name'     => 'RevoAp',
        // Kept in sync with db/schema.sql `@schema-version` (both 1.9.2)
        // and the Worker card's "app X" readout. Drift here misfires the
        // worker-vs-deploy version alert. The invariant is asserted without
        // a literal by tests/PromptCacheTest.php and tests/SmokeTest.php.
        'version'  => '2.0.0',
        'debug'    => false,
        // v1.8.22: emit structured Chrome console logs from the frontend
        // (api fetch start/end/error, sse open/event/error/close,
        // pipeline event facts, control actions, cache lookup outcomes
        // and pipeline terminal reasons). Prompts and answers are NEVER
        // logged at this level — only metadata useful for debugging.
        // Override per request with `?debug=1` (forces verbose) or
        // `?debug=0` (forces silent). Set window.OC_DEBUG_VERBOSE=true
        // in the browser console to include prompt/answer bodies in
        // logs (off by default for both privacy and signal-to-noise).
        'log_to_console' => true,
        'url'      => 'http://localhost:8080',
        'timezone' => 'UTC',
        // Top-nav tab visibility flags. Both the UI tab AND the matching
        // backend endpoints are gated by these. Setting either to false
        // hides the tab and disables the corresponding API surface
        // (returns 404/disabled). The admin token is still required for
        // any protected action when the tab is enabled. Backwards compat:
        // top-level `SettingsTab` / `LogsTab` are also honored.
        'SettingsTab' => true,
        'LogsTab'     => true,
        // Security tab — surfaces the IP whitelist / blacklist editor and
        // a runtime security checklist. Default is FALSE because enabling
        // a whitelist with the wrong rules can lock the operator out.
        // Loopback (127.0.0.0/8 and ::1) is always allowed even when the
        // tab is on; the current admin's IP is auto-added on first visit.
        'SecurityTab' => false,
        // History tab — browses past council sessions with full audit
        // trail (query, final answer, all L0/L1/L2/L3/L4 steps, model
        // responses, events, stats). Default is FALSE because the tab
        // exposes the contents of every prompt and the corresponding
        // model output to anyone who has the admin token. Enable it
        // only after OPEN_CORUM_ADMIN_TOKEN / .admin.key is configured
        // and on hosts where session contents are not considered
        // sensitive. Persistence requires a configured DB.
        'HistoryTab'  => false,
        // Statistic tab — aggregates per-LLM and per-session usage
        // (calls, prompt/completion/total tokens, cost, cache hits,
        // reasoning tokens, latency/duration) over selectable periods
        // (All / Year / Quarter / Week / Day / Hour). Default is FALSE
        // because the metrics describe operational/user usage and may
        // be sensitive. Enable only after OPEN_CORUM_ADMIN_TOKEN /
        // .admin.key is configured. Persistence requires a configured
        // DB; without one the tab is shown but renders a clear
        // "no_database" diagnostic instead of fabricated zeros.
        'StatisticTab' => false,
        'ApiTab' => true,
        // Models tab (v1.8.49) — the model-facing half of the Settings
        // panel: Providers/API keys, the model catalogue, the per-domain
        // consensus presets and the request analyzer. Default is TRUE
        // because it is part of the operator surface, and it carries the
        // same admin-token gate as Settings: without an unlocked token the
        // tab body renders the shared lock screen, and the model endpoints
        // answer 404 settings_disabled while this flag is off.
        'ModelsTab' => true,
        // Extensions tab - installs and updates the third-party MD.Viewer
        // extension from GitHub into extensions/md-viewer/. The download is
        // proxied by the server (the browser never talks to GitHub) and the
        // tab is gated by the admin token exactly like Logs / Settings; the
        // installer's machine-API key (.md.ini) never leaves the server.
        // Default is TRUE so the operator surface is present out of the box;
        // set ExtensionsTab=false to hide the tab and darken its API surface.
        'ExtensionsTab' => true,
    ],
    // Top-level aliases preserved for operator convenience. Either
    // `app.SettingsTab` or the top-level `SettingsTab` is honored; the
    // top-level form wins when both are set.
    'SettingsTab'  => true,
    'LogsTab'      => true,
    'SecurityTab'  => false,
    'HistoryTab'   => false,
    'StatisticTab' => false,
    'ApiTab'       => true,
    'ModelsTab'    => true,
    'ExtensionsTab' => true,
    'database' => [
        'dsn'      => 'mysql:host=localhost;dbname=opencorum;charset=utf8mb4',
        'user'     => 'root',
        'password' => '',
        // v1.8.26: PDO options live in a helper closure so we can append
        // \App\Core\PdoCompat::mysqlBufferedQuery() only when the constant is
        // actually defined (gated for non-MySQL builds). Using the array
        // spread operator on a numeric-keyed array would have re-numbered
        // the constant value — PHP renumbers integer keys in spread
        // literals — silently losing the attribute. Explicit assignment
        // keeps the PDO constant intact.
        'options'  => (static function (): array {
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            if (defined('\App\Core\PdoCompat::mysqlBufferedQuery()')) {
                // Force buffered result sets so a partially iterated SELECT
                // cannot block the next statement on this connection
                // (MySQL otherwise raises SQLSTATE[HY000] 2014
                // "Cannot execute queries while other unbuffered queries
                // are active").
                // PHP 8.5 deprecates the PDO::MYSQL_ATTR_* constants and points at
                // Pdo\Mysql::ATTR_*; that class exists from PHP 8.4, so prefer it and
                // fall back to the old constant only where it cannot be used.
                $bufferedAttr = class_exists(\Pdo\Mysql::class)
                    ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY
                    : \App\Core\PdoCompat::mysqlBufferedQuery();
                $opts[$bufferedAttr] = true;
            }
            return $opts;
        })(),
    ],
    'connectors' => [
        'bothub' => [
            // BotHub exposes an OpenAI-compatible endpoint. The base URL must
            // match the documented prefix; the legacy `https://api.bothub.chat/v1`
            // host returns 404 for /chat/completions. Override here only if
            // BotHub publishes a new path in their docs.
            'active'        => true,
            'api_key'       => '',                              // or .bothub.key file
            'base_url'      => 'https://bothub.chat/api/v2/openai/v1',
            'timeout_chat'  => 120,
            'timeout_embed' => 30,
            'max_retries'   => 3,
        ],
        'openrouter' => [
            'active'        => false,
            'api_key'       => '',                              // or .openrouter.key file
            'base_url'      => 'https://openrouter.ai/api/v1',
            'timeout_chat'  => 120,
            'timeout_embed' => 30,
            'max_retries'   => 3,
        ],
        // Direct providers. All disabled by default — flip `active` to
        // true and supply a key (via this file, `.{provider}.key`, or the
        // documented env var) to enable. Aliases such as `.claude.key`
        // or `.gemini.key` are also accepted by Config::getConnectorKey().
        'openai' => [
            'active'        => false,
            'api_key'       => '',                              // or .openai.key, $OPENAI_API_KEY
            'base_url'      => 'https://api.openai.com/v1',
            'timeout_chat'  => 120,
            'timeout_embed' => 30,
            'max_retries'   => 3,
            // Optional dot-file aliases consulted in order before the env-var
            // fallback. Useful for operators who prefer the brand name file.
            'key_files'     => ['.openai.key'],
        ],
        'anthropic' => [
            'active'        => false,
            'api_key'       => '',                              // or .anthropic.key / .claude.key
            'base_url'      => 'https://api.anthropic.com/v1',
            'timeout_chat'  => 120,
            'timeout_embed' => 30,
            'max_retries'   => 3,
            'key_files'     => ['.anthropic.key', '.claude.key'],
        ],
        'gemini' => [
            'active'        => false,
            'api_key'       => '',                              // or .gemini.key / .google.key
            'base_url'      => 'https://generativelanguage.googleapis.com/v1beta',
            'timeout_chat'  => 120,
            'timeout_embed' => 30,
            'max_retries'   => 3,
            'key_files'     => ['.gemini.key', '.google.key'],
        ],
        'deepseek' => [
            'active'        => false,
            'api_key'       => '',                              // or .deepseek.key
            'base_url'      => 'https://api.deepseek.com/v1',
            'timeout_chat'  => 120,
            'timeout_embed' => 30,
            'max_retries'   => 3,
            'key_files'     => ['.deepseek.key'],
        ],
        'xai' => [
            'active'        => false,
            'api_key'       => '',                              // or .xai.key / .grok.key
            'base_url'      => 'https://api.x.ai/v1',
            'timeout_chat'  => 120,
            'timeout_embed' => 30,
            'max_retries'   => 3,
            'key_files'     => ['.xai.key', '.grok.key'],
        ],
    ],
    // The shipped model catalogues live in `config/models/*.php`. When
    // this `models` key is left as an empty array the loader expands it
    // from the model files. An operator-supplied list (here or via
    // `.config.php` / `runtime.json`) wins over the files for full
    // backwards compatibility.
    'models' => [],
    // CATALOGUE ROW CAP (v1.8.60) and the rest of the catalogue's own
    // settings. `models_catalog.max_rows` is how many rows the persisted
    // catalogue (`var/config/runtime.json` → `models[]`) may hold:
    //
    //   max_rows = 0      no ceiling at all — every model the provider
    //                     serves is catalogued.
    //   max_rows = 1000   (default) high enough for a full provider
    //                     catalogue (BotHub currently advertises ~520
    //                     models), low enough that the Models tab stays
    //                     editable.
    //   max_rows = 64     the pre-1.8.60 hard cap. Allowed, no longer
    //                     recommended: a 64-row catalogue cannot represent
    //                     the provider's live list, which is how the pool,
    //                     the presets and the L4 synthesis model ended up
    //                     pointing at models the catalogue did not hold.
    //
    // The same setting is also accepted under the dotted spelling
    // `models.catalog.max_rows` — in config.php, in `.config.php` or in
    // `runtime.json`. The loader lifts that subtree out of `models` before
    // the catalogue is expanded (Config::liftCatalogSettings()), so the
    // spelling is safe to use; it is not used *here* only because this key
    // must stay an empty list for the shipped-file expansion to run, and a
    // reader should not have to reason about that.
    //
    // The cap is applied by App\Models\CatalogTruncation, which never drops
    // a row the pool (`pipeline.default_selected_models`), a domain preset
    // or a linked role (analyzer / synthesis / arbiter) references, and
    // ranks the rest by provider family (newest release first) instead of
    // cutting the list at an arbitrary position. Whatever it leaves out is
    // counted in the actualization report. An unusable value (a typo, a
    // negative number, an array) falls back to the default rather than
    // silently becoming "unlimited" — and never to 0, which would empty the
    // catalogue.
    'models_catalog' => [
        'max_rows' => 1000,
    ],
    // Catalogue file paths relative to the project root. The loader
    // walks this list in order and merges each file's rows into the
    // runtime `models` list when (and only when) no operator-supplied
    // override is present.
    'model_files' => [
        'config/models/openrouter.php',
        'config/models/bothub.php',
        'config/models/direct.php',
    ],
    'pipeline' => [
        'synthesis_model'     => 'gpt-6-luna-pro',
        'synthesis_provider'  => 'bothub',
        // Lifted from 5 → 12 so a skill can scale to the user-selected
        // consensus pool. PipelineEngine now creates one L1 invocation
        // per *selected model*, materializing additional consensus
        // participants when a skill defines fewer roles than there are
        // models in the pool.
        'max_roles_per_skill' => 12,
        'memory_top_k'        => 10,
        // Run L1 model calls concurrently via curl_multi when a parallel
        // dispatcher is supplied. Set to false to force sequential calls
        // (useful for low-memory hosts or to debug ordering).
        'parallel_l1'         => true,
        // ------------------------------------------------------------------
        // R11 - per-call reliability for the L1 fan-out.
        //
        // `model_timeout_s`   how long one model call may take, independent of
        //                     the run's own budget. A slow participant must not
        //                     hold the whole sweep.
        // `model_retry_budget` extra attempts a *transiently* failing model
        //                     gets (timeout, 429, 5xx, connection). Permanent
        //                     failures - a model the provider no longer serves -
        //                     are never retried: that only spends money.
        // `breaker_failures`  consecutive failures after which a model is
        //                     dropped for the rest of the run, so a dead
        //                     participant is not re-asked in every consensus
        //                     cycle. Reported, never silent.
        // ------------------------------------------------------------------
        'model_timeout_s'     => 180,
        'model_retry_budget'  => 1,
        'breaker_failures'    => 2,
        // Maximum number of consensus rounds (v1.8.16+). When L3 fails to
        // reach `agreement` after round 1, the engine reissues every L1
        // model call with peer answers injected so each model can defend
        // or revise its position. Set to 1 to disable the second round
        // entirely. Set higher than 2 to keep iterating until either
        // consensus is reached or the budget is exhausted.
        //
        // v1.8.19: this value is the *default* cycle limit. The UI and
        // API accept a per-request `cycle_limit` that overrides it,
        // clamped server-side to [cycle_limit_min, cycle_limit_max] to
        // make endless token-burn cycles impossible regardless of what
        // any client sends. Effective per-run limit also obeys the hard
        // ceiling — the engine itself uses min(cycle_limit_max, value).
        'max_consensus_rounds' => 2,
        // Inclusive bounds (v1.8.19) for the per-request `cycle_limit`.
        // The engine *rejects nothing*: anything outside this range is
        // clamped before the loop starts and a CONSENSUS_CYCLE_LIMIT_CLAMPED
        // event is emitted so the operator can see what happened.
        'cycle_limit_min' => 1,
        'cycle_limit_max' => 5,
        // Consensus score below which a second round is triggered (when
        // status is not already `agreement`). Mirrors the conflict
        // arbiter threshold so the two behaviours stay coherent.
        'round2_score_threshold' => 0.85,
        // Minimum improvement in consensus score between two consecutive
        // cycles (v1.8.19). If the cycle-over-cycle delta is below this
        // value AND the status did not change, the loop stops early —
        // running another round is unlikely to converge and just burns
        // tokens. Set to 0 to disable stagnation detection.
        'consensus_stagnation_epsilon' => 0.01,
        // Default consensus pool — the four fresh-install picks shown
        // to a brand-new operator before they touch the Settings UI.
        // Each entry must resolve to an `active=true` row in the
        // runtime catalogue (see `models` / `model_files`). When this
        // list is left empty the loader synthesises a selection from
        // any catalogue rows marked `default=true` (up to four). An
        // operator-supplied selection — either here, in `.config.php`,
        // or saved via the Settings UI — wins unchanged.
        'default_selected_models' => [
            ['provider' => 'bothub', 'model' => 'gpt-6-luna-pro'],
            ['provider' => 'bothub', 'model' => 'claude-sonnet-4.6'],
            ['provider' => 'bothub', 'model' => 'deepseek-r1'],
            ['provider' => 'bothub', 'model' => 'gemini-2.5-pro'],
        ],
        // Conflict-resolution arbiter (v1.8.6+). When L3 reports anything
        // other than a clean agreement (or the consensus score is below
        // `threshold`), the engine routes the *raw* L1 answers to this
        // model instead of running the configured synthesis_model. The
        // arbiter prompt asks for a best-of combined answer; the Final
        // Answer card prepends a disclaimer that consensus was not
        // reached. Keep the OpenRouter provider — BotHub does not host
        // an equivalent reconciliation model in our defaults.
        'conflict_arbiter' => [
            'enabled'   => true,
            'provider'  => 'bothub',
            'model'     => 'gpt-6-luna-pro',
            'threshold' => 0.7,
        ],
        // Consensus scoring backend (v1.8.17+). Two modes:
        //   - `semantic_components` (default): cluster semantic
        //     components across models with keyword/character-ngram
        //     similarity. Scores ≥ 0.85 → agreement, 0.55–0.85 →
        //     partial, < 0.55 → conflict. Robust to paraphrasing
        //     and Russian/English mixed content.
        //   - `lexical_jaccard`: legacy v1.0–1.8.16 backend (pairwise
        //     Jaccard over per-claim token bags). Kept for
        //     backwards compatibility and side-by-side benchmarking.
        // A request-time `consensus_skill` (or a skill definition's
        // `consensus_scoring` JSON field) overrides this per call.
        'consensus' => [
            'scoring' => 'semantic_components',
        ],
    ],
    // Reasoning / thinking effort presets. The UI exposes one of these
    // via the "Reasoning" dropdown; the pipeline forwards the resulting
    // `reasoning` object to OpenRouter chat completions per the
    // documented API. Providers that don't support it ignore the field.
    'reasoning' => [
        'levels' => [
            'off'    => null,
            'low'    => ['effort' => 'low'],
            'medium' => ['effort' => 'medium'],
            'high'   => ['effort' => 'high'],
        ],
        // When false, response carries provider-emitted reasoning text
        // (where available). Per OpenRouter docs, set exclude=true to
        // strip the reasoning details from the response body.
        'exclude_default' => false,
    ],
    'session' => [
        'lifetime' => 7200,
        'secure'   => false,
    ],
    // Admin authentication. Documents WHERE the admin token is read
    // from — never the token value itself. The actual lookup is
    // implemented in App\Core\AdminAuth and follows this priority:
    //   1. environment variable named in `admin.token_env`
    //   2. plain-text file named in `admin.token_file` at the project root
    // Storing the token in `config.php` is intentionally not supported,
    // since `config.php` is tracked in git and should never carry
    // secrets. To set the token: either `export OPEN_CORUM_ADMIN_TOKEN=…`
    // in the PHP-FPM/CLI environment, or write a single-line value to
    // `<project_root>/.admin.key` and `chmod 600` it.
    'admin' => [
        'token_env'  => 'OPEN_CORUM_ADMIN_TOKEN',
        'token_file' => '.admin.key',
    ],
    // WHETHER THE OPERATOR SURFACE ASKS FOR THAT TOKEN AT ALL.
    //
    //   = 1   every guarded route requires the admin token, and the UI shows
    //         the unlock prompt. This is the historical behaviour, and what
    //         an ABSENT key means — the gate is opt-out, never opt-in by
    //         accident, so a config tree that fails to load cannot silently
    //         open the settings surface.
    //   = 0   no key is requested. The guards pass without one, no unlock
    //         prompt is shown, nothing is reported as `unauthorized`, and a
    //         warning strip is rendered under the header saying so.
    //
    // Accepted spellings: 0/1, "0"/"1", false/true, "false"/"true",
    // off/on, no/yes. `.config.php` wins over this file, so a deployment can
    // flip it locally without touching the tracked default.
    //
    // WHAT IT UNLOCKS AT 0 — read this before setting it. With the flag off,
    // anyone who can reach the host can, without any credential: read and
    // rewrite the provider API keys, the model catalogue, the consensus
    // presets, the pipeline and reasoning defaults, the prompt-cache
    // settings and the database DSN; apply and baseline database migrations
    // and recreate the database; purge the response cache; read the log
    // files; rewrite the IP allow/deny lists; read every stored session
    // (query + full final answer) and every prompt; read the aggregate usage
    // statistics; create, rotate and revoke API keys; and read the worker
    // debug dump (that one is additionally IP-gated). Use 0 only behind
    // `.htaccess`/HTTP auth, on a loopback-only install, or on a machine
    // nobody else can reach.
    // ---------------------------------------------------------------------
    // Economy mode (R10). The cheap path - the asynchronous Batch API and
    // prompt caching - is offered where it pays off, never switched on behind
    // the operator's back. `off` reproduces the previous behaviour exactly:
    // nothing is suggested and no request body changes.
    //   `off`     - no advice, no estimates, previous behaviour
    //   `suggest` - estimates and recommendations are shown, runs unchanged
    //   `prefer`  - batch and caching are switched on where they are safe
    // The thresholds below are the floors the advice uses; `batch_min_seconds`
    // is the point where a run is slow enough to be worth the batch wait.
    // ---------------------------------------------------------------------
    'economy' => [
        'mode'               => 'suggest',
        'cache_min_calls'    => 20,
        'cache_min_hit_rate' => 0.05,
        'batch_min_seconds'  => 120,
        // R1 - an optional ceiling for one run, in the unit the providers report
        // cost in. null / 0 means no ceiling. The estimate is shown against it
        // before the result arrives; the app warns rather than blocks, because
        // refusing a run the operator asked for would be a stronger decision
        // than this setting makes.
        'budget_per_run'     => null,
    ],

    // ---------------------------------------------------------------------
    // The admin gate. v2.0.0 changes the shipped default from 0 to 1.
    //
    // This is a SECURITY FIX, not a preference change. With 0 the operator
    // surface — Settings (which writes provider API keys and the DSN), Models,
    // Logs (which tails the error log), Security (which edits the IP firewall),
    // and since v2.0.0 also Audit, Users, Backups and the Fallback policy — is
    // reachable by anyone who can reach the host, with no token and no prompt.
    // The comment above this setting has always said to use 0 "only behind
    // .htaccess/HTTP auth, on a loopback-only install, or on a machine nobody
    // else can reach", so shipping 0 made the default the one configuration the
    // documentation warns against. A value that was jammed onto the closing
    // bracket of the `economy` array is also not what a deliberate default
    // looks like.
    //
    // Insecure-by-default is the wrong side to fall on: the operator who needs
    // an open intranet install can set 0 in `.config.php` in one line, and the
    // UI discloses that loudly (the `admin.not_protected` banner in
    // layout.html). The operator who forgets to set 1 gets a console whose API
    // keys and firewall are writable from the internet, and nothing tells them.
    //
    // Note that the gate being ON without a `.admin.key` file means the admin
    // surface is unreachable rather than open: AdminAuth::gateState()['allowed']
    // is `unlocked && (!protected || configured)`. So turning this on without
    // creating the key file locks the operator out — read the hint the API
    // returns, or run:
    //     openssl rand -hex 32 > .admin.key && chmod 600 .admin.key
    // ---------------------------------------------------------------------
    'Protect_Settings_by_Admin_Key' => 1,
    // Model response cache. Stores successful ChatResponse payloads keyed
    // by sha256(provider, model, framed messages, significant options).
    // Hits skip the network call entirely. Failures are NEVER cached:
    // empty completions, provider error envelopes and content-filtered
    // answers are refused (ModelResponseCache::isCacheable).
    'cache' => [
        'model_responses' => [
            'enabled'     => true,
            // 0 = no TTL (entries kept until manually invalidated). Set to
            // a positive integer to expire stale rows automatically; an
            // expired entry is deleted, not merely ignored.
            //
            // Manual invalidation has a supported entry point:
            //   php bin/cache-purge.php --status                       (report)
            //   php bin/cache-purge.php --older-than-days=7 --yes      (prune)
            //   php bin/cache-purge.php --yes                          (drop all,
            //                                                          e.g. after a
            //                                                          cache VERSION bump)
            // The admin token is taken from --token=, then the
            // REVOAP_ADMIN_TOKEN environment variable, then the .admin.key
            // file in the project root — so on a server that already has
            // the key file the command needs no arguments. The same
            // statistics and the same invalidation are available in the
            // browser: Settings -> Cache.
            'ttl_seconds' => 0,
        ],
    ],
    // Prompt caching (Bothub) — provider-side prefix caching, distinct from
    // the local model-response cache above: that one skips the network call
    // entirely, this one makes the provider charge less for a repeated
    // prompt prefix. See Settings -> Prompt caching, which renders the
    // capability table the values below are defaulted from.
    //
    //   enabled           master switch. When false the request body is
    //                     byte-identical to what it was before the feature
    //                     existed (no cache_control, no usage block).
    //   ttl               '5m' (documented default) or '1h' (documented
    //                     one-hour session). Anything else is coerced to
    //                     '5m' on read.
    //   max_breakpoints   how many cache_control breakpoints to place, on
    //                     the last N messages of the stable prefix.
    //                     Clamped to 1..4 — 4 is the documented hard ceiling
    //                     (Anthropic); Gemini through Bothub has no ceiling
    //                     but honours only the last one.
    //   min_prompt_tokens size guard: a prefix smaller than this gets no
    //                     breakpoint, because caching below the provider's
    //                     documented minimum would cost a round trip and
    //                     save nothing. Raised per family by that family's
    //                     documented minimum (1024 OpenAI, 2048/1028
    //                     Gemini), never lowered.
    //   include_usage     send `usage: {include: true}` so the provider
    //                     returns cache tokens at the end of the response.
    //                     Without it there is nothing to report on the
    //                     automatic families, where caching needs no
    //                     request-side work.
    //   provider          the connector allowed to use any of this. Only
    //                     Bothub documents prompt caching, so OpenRouter
    //                     requests keep their legacy shape.
    'prompt_cache' => [
        'enabled'           => true,
        'ttl'               => '5m',
        'max_breakpoints'   => 1,
        'min_prompt_tokens' => 1024,
        'include_usage'     => true,
        'provider'          => 'bothub',
    ],
    'logging' => [
        'path'  => __DIR__ . '/logs',
        'level' => 'info',
    ],
];
