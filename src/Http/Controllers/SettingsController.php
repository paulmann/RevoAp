<?php
/**
 * SettingsController: HTTP layer for the admin Settings UI.
 *
 * Every endpoint requires the admin token (header / query / cookie).
 * Provides:
 *   GET  /api/settings                       — current settings (masked)
 *   POST /api/settings/unlock                — set the session cookie
 *   POST /api/settings/providers             — save OpenRouter / BotHub keys + base URLs
 *   POST /api/settings/providers/test        — probe a provider with the saved or supplied key
 *   POST /api/settings/models/import         — refresh the catalog from a provider
 *   POST /api/settings/models                — replace the active model list
 *   POST /api/settings/database              — save DSN fields + credentials
 *   POST /api/settings/database/test         — open a short-lived PDO connection
 *   GET  /api/settings/migrations            — list pending/applied migrations
 *   POST /api/settings/migrations/apply      — apply pending migrations
 *   POST /api/settings/migrations/baseline   — mark current migrations as applied
 *   GET  /api/settings/cache                 — model-response cache statistics
 *   POST /api/settings/cache/purge           — invalidate cache entries ({"confirm":"PURGE"})
 *   GET  /api/settings/export                — schema dump (text/sql)
 *   POST /api/settings/runtime               — debug/cache/SSE flags
 *
 * Secrets are NEVER returned in plaintext after save — only masked
 * fingerprints. ErrorDiagnostics is used for DB/connector errors so the
 * operator sees the same shape they get on /api/debug.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cache\CacheStatistics;
use App\Cache\ModelResponseCache;
use App\Connectors\PromptCache\Capabilities;
use App\Connectors\PromptCache\Policy;
use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\RuntimeConfig;
use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Models\ModelCatalogMerge;
use App\Models\ModelId;
use App\Models\ModelImporter;
use App\Settings\ConfigRepository;
use App\Settings\DatabaseDsn;
use App\Settings\MigrationManager;
use App\Settings\PopularModels;
use App\Settings\ProviderTester;
use App\Settings\SchemaInspector;
use App\Settings\SchemaManager;
use App\Settings\SchemaParser;
use App\Settings\SettingsPersist;
use App\Settings\VersionParser;
use PDO;

final class SettingsController
{
    /** Control word required by POST /api/settings/cache/purge. */
    public const PURGE_CONFIRM = 'PURGE';

    /** Explanation attached to every BotHub import response. */
    public const BOTHUB_IMPORT_NOTE = 'BotHub /models requires a valid API key. '
        . 'Configure one under Providers and try again.';

    /**
     * @param string $rootDir Project root.
     * @param ?PDO   $pdo     Raw handle, still required by the settings /
     *                        schema stack (SqlExecutor and friends take a
     *                        bare PDO).
     * @param ?Database $db   Resilient wrapper for the paths that can be
     *                        long-lived or repeated — the cache card is
     *                        polled, so a connection that went away
     *                        between two polls must be replaceable
     *                        instead of reported as "backend
     *                        unavailable".
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly ?PDO $pdo = null,
        private readonly ?Database $db = null,
        private readonly ?ModelImporter $importer = null,
    ) {}

    /**
     * The catalogue fetcher, built on first use when the deployment did not
     * inject one. {@see ModelImporter} is the single implementation of "how
     * a provider catalogue is fetched" — the Models tab button and the
     * first stage of an actualization run share it, so the two can never
     * disagree about which URL, which key or which failure text applies.
     */
    private function importer(): ModelImporter
    {
        return $this->importer ?? new ModelImporter();
    }

    /**
     * v1.8.33 — Resolve the operator-configured default UI theme.
     * Falls back to the pre-1.8.33 default ("system") when the
     * `app_config` table is absent or the read fails so the page never
     * breaks on a host mid-migration.
     *
     * @return array{theme:string}
     */
    public function uiPreferences(): array
    {
        if ($this->pdo === null) {
            return ['theme' => 'system'];
        }
        try {
            return (new ConfigRepository($this->pdo))->getUiPreferences();
        } catch (\Throwable) {
            return ['theme' => 'system'];
        }
    }

    /** Reject unauthenticated requests. Returns null on success. */
    private function requireAdmin(): ?string
    {
        if (!Config::isSettingsTabEnabled()) {
            return Response::json([
                'error'   => 'settings_disabled',
                'message' => 'Settings tab is disabled in config.php (SettingsTab=false).',
            ], 404);
        }
        // v1.8.60 — the decision is taken once, in AdminAuth, so every guard
        // on this surface obeys `Protect_Settings_by_Admin_Key`: with the flag
        // at 0 the gate is open (no key configured, no key presented, nothing
        // to challenge), while at 1 — or absent — the historical 503 / 401
        // pair is returned unchanged.
        $gate = AdminAuth::gateState();
        if (!$gate['allowed']) {
            if (!$gate['configured']) {
                return Response::json([
                    'error'   => 'admin_not_configured',
                    'message' => self::adminNotConfiguredMessage('Settings'),
                    'admin_diagnostics' => AdminAuth::diagnostics(),
                ], 503);
            }
            return Response::json(['error' => 'unauthorized', 'message' => 'admin token required'], 401);
        }
        return null;
    }

    /**
     * Build an actionable "admin not configured" message based on the
     * diagnostics snapshot. Surfaces the most likely failure mode first
     * (permissions, empty file, BOM, missing file) so the operator does
     * not have to guess. The message intentionally documents that
     * `REVOAP_ADMIN_TOKEN` is the *environment variable* name — not
     * a `config.php` key — to head off a recurring confusion.
     */
    public static function adminNotConfiguredMessage(string $feature): string
    {
        $diag = AdminAuth::diagnostics();
        $file = is_array($diag['file'] ?? null) ? $diag['file'] : [];
        $root = is_array($diag['root'] ?? null) ? $diag['root'] : [];
        $envName = is_string($diag['env_name'] ?? null) && $diag['env_name'] !== ''
            ? $diag['env_name']
            : AdminAuth::ENV;
        $fileName = is_string($file['name'] ?? null) && $file['name'] !== ''
            ? $file['name']
            : AdminAuth::FILE;
        $rootHint = '';
        if (is_string($root['basename'] ?? null) && $root['basename'] !== '') {
            $rootHint = ' AdminAuth searched the project root "' . $root['basename'] . '"'
                . ' (resolved via ' . (string)($root['source'] ?? '?') . ').';
        }
        // Treat both `readable=false` (legacy) and `read_ok=false` while
        // the file exists as the same operator-actionable case: the
        // PHP process cannot get bytes out of the key file. Prefer
        // read_ok because it reflects what file_get_contents() actually
        // returned, not just is_readable() (which shared hosts often
        // misreport). We also surface the captured error message so
        // operators see "Permission denied" verbatim instead of having
        // to guess.
        $readBlocked = ($file['exists'] ?? false) === true
            && (($file['read_ok'] ?? null) === false || ($file['readable'] ?? false) === false);
        if ($readBlocked) {
            $err = is_string($file['read_error'] ?? null) && $file['read_error'] !== ''
                ? ' Underlying error: ' . $file['read_error'] . '.'
                : '';
            $configHint = ' If the host disallows changing ' . $fileName
                . ' permissions, you may instead set admin.token in the gitignored .config.php overlay'
                . ' (private root file, never commit) — AdminAuth will fall back to it.';
            return $feature . ' is disabled: ' . $fileName . ' exists but cannot be read by the PHP process.'
                . $err
                . ' Set ownership/mode so php-fpm can read it (e.g. chown www-data:www-data ' . $fileName
                . ' && chmod 600 ' . $fileName . ').' . $configHint . $rootHint;
        }
        if (($file['exists'] ?? false) === true && ($file['empty'] ?? false) === true) {
            $bomHint = ($file['had_bom'] ?? false) === true
                ? ' The file starts with a UTF-8 BOM and is otherwise empty — re-save without a BOM.'
                : '';
            return $feature . ' is disabled: ' . $fileName . ' exists but is empty. Write a single-line non-empty token to it.'
                . $bomHint . $rootHint;
        }
        // v1.8.60 — name the flag that turns the requirement off entirely.
        // "No token configured" and "this install requires no token" look
        // identical from the outside, and the second one is a one-line
        // config change away from the first.
        return $feature . ' is disabled until ' . $envName . ' (environment variable, NOT a config.php key) is exported, '
            . 'or a non-empty ' . $fileName . ' file is placed in the project root (chmod 600).'
            . ' To stop asking for a key at all on a host only you can reach, set '
            . AdminAuth::PROTECT_KEY . ' = 0 in config.php or .config.php (this opens every operator route).'
            . $rootHint;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $decoded = JsonHelper::tryDecode($raw, []);
        return is_array($decoded) ? $decoded : [];
    }

    public function unlock(): string
    {
        if (!Config::isSettingsTabEnabled()) {
            return Response::json(['error' => 'settings_disabled'], 404);
        }
        // v1.8.60 — with `Protect_Settings_by_Admin_Key = 0` there is nothing
        // to unlock. Answering `ok` (rather than 503) is the honest reply:
        // the surface is already open, and an old bookmark or a script that
        // still posts a token must not be told the install is broken.
        if (!AdminAuth::protectionEnabled()) {
            return Response::json([
                'ok'      => true,
                'note'    => 'Settings are not protected by an admin key '
                    . '(Protect_Settings_by_Admin_Key = 0), so no token is required.',
                'skipped' => true,
            ]);
        }
        if (!AdminAuth::isConfigured()) {
            return Response::json(['error' => 'admin_not_configured'], 503);
        }
        $body  = $this->readJsonBody();
        $token = is_string($body['token'] ?? null) ? trim((string)$body['token']) : '';
        if ($token === '') {
            $token = AdminAuth::presented();
        }
        $configured = AdminAuth::loadToken();
        if ($configured === null || $token === '' || !hash_equals($configured, $token)) {
            return Response::json(['ok' => false, 'error' => 'invalid_token'], 401);
        }
        AdminAuth::setSessionCookie($token);
        return Response::json(['ok' => true]);
    }

    public function logout(): string
    {
        AdminAuth::clearSessionCookie();
        return Response::json(['ok' => true]);
    }

    /**
     * Public, no-auth endpoint exposing only whether the Settings feature
     * is configured. Used by the front-end to decide whether to render
     * the Settings tab in the navigation.
     *
     * `tab_enabled` reflects the `SettingsTab` config flag — when false,
     * the front-end MUST hide the Settings tab and the protected API
     * surface returns 404 (`settings_disabled`).
     */
    public function status(): string
    {
        return Response::json([
            'tab_enabled'       => Config::isSettingsTabEnabled(),
            // v1.8.60 — `admin_protection` is the raw
            // `Protect_Settings_by_Admin_Key` flag (true = a key is
            // required). `admin_unlocked` is the verdict the UI acts on and
            // is true whenever the protection is off, so every lock screen
            // and "token required" banner disappears on an unprotected
            // install without a second decision in the front end.
            // `admin_configured` still reports the literal truth ("a token
            // is configured"), which is what the diagnostics surface wants.
            'admin_protection'  => AdminAuth::protectionEnabled(),
            'admin_configured'  => AdminAuth::isConfigured(),
            'admin_unlocked'    => AdminAuth::gateState()['unlocked'],
            'admin_diagnostics' => AdminAuth::diagnostics(),
        ]);
    }

    public function get(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }

        $orKey = $this->maskedProviderKey('openrouter');
        $bhKey = $this->maskedProviderKey('bothub');
        $dsn   = (string)Config::get('database.dsn', '');
        $parts = DatabaseDsn::parse($dsn);

        return Response::json([
            'app' => [
                'name'    => (string)Config::get('app.name', 'RevoAp'),
                'version' => (string)Config::get('app.version', ''),
                'debug'   => (bool)Config::get('app.debug', false),
                'url'     => (string)Config::get('app.url', ''),
                'timezone'=> (string)Config::get('app.timezone', 'UTC'),
            ],
            'providers' => [
                'openrouter' => [
                    'active'   => (bool)Config::get('connectors.openrouter.active', true),
                    'base_url' => (string)Config::get('connectors.openrouter.base_url', 'https://openrouter.ai/api/v1'),
                    'api_key'  => $orKey['masked'],
                    'has_key'  => $orKey['has'],
                    'source'   => $orKey['source'],
                ],
                'bothub' => [
                    'active'   => (bool)Config::get('connectors.bothub.active', true),
                    'base_url' => (string)Config::get('connectors.bothub.base_url', 'https://bothub.chat/api/v2/openai/v1'),
                    'api_key'  => $bhKey['masked'],
                    'has_key'  => $bhKey['has'],
                    'source'   => $bhKey['source'],
                ],
            ],
            'database' => [
                'dsn'      => $dsn,
                'parsed'   => $parts,
                'user'     => (string)Config::get('database.user', ''),
                'has_password' => (string)Config::get('database.password', '') !== '',
            ],
            'models' => $this->modelsState(),
            'runtime' => [
                'cache' => [
                    'enabled'     => (bool)Config::get('cache.model_responses.enabled', true),
                    'ttl_seconds' => (int)Config::get('cache.model_responses.ttl_seconds', 0),
                ],
                'pipeline' => [
                    'parallel_l1'         => (bool)Config::get('pipeline.parallel_l1', true),
                    'max_roles_per_skill' => (int)Config::get('pipeline.max_roles_per_skill', 12),
                    'memory_top_k'        => (int)Config::get('pipeline.memory_top_k', 10),
                ],
                'reasoning' => [
                    'levels' => array_keys((array)Config::get('reasoning.levels', [])),
                ],
                // v1.8.45 — prompt caching. `policy` is the resolved,
                // clamped configuration the connector will actually use, so
                // a hand-edited runtime.json shows up as a different value
                // here rather than as a silently different request.
                // `capabilities` is the documented capability table the
                // card renders. Both are produced by the same classes the
                // request builder uses, so the card cannot advertise a rule
                // the planner does not follow.
                'prompt_cache' => [
                    'policy'       => Policy::fromConfig()->toArray(),
                    'capabilities' => Capabilities::documentedArray(),
                ],
            ],
            'storage' => [
                'runtime_path' => RuntimeConfig::FILE_RELATIVE,
                'has_runtime'  => is_file(RuntimeConfig::path()),
                // v1.8.50 — the Models/Settings tab shows this instead of
                // pretending everything is fine: a runtime.json the PHP
                // process cannot read makes every saved setting fall back
                // to its config.php default, which the operator experiences
                // as "my settings were reset".
                'runtime_error' => RuntimeConfig::lastReadError(),
            ],
            'recommended_models' => PopularModels::recommended(),
            // v1.8.33: default theme. Sourced from the `app_config`
            // table (key `ui.theme`). The settings JS reads this to
            // preselect the Interface tab's theme dropdown.
            'interface' => $this->uiPreferences(),
        ]);
    }

    /**
     * POST /api/settings/interface
     * Body: {"theme": "light|dark|system"}
     * v1.8.33 — operator-controlled default theme for both the main app
     * and the public read-only shared page.
     *
     * The layout-width half of this endpoint was retired: the content
     * column now follows the window instead of a stored preference, so
     * `width` is no longer accepted. A `ui.width` row written by an
     * earlier release is deleted on save (ConfigRepository::
     * purgeRetiredUiKeys) — see ConfigRepository for why it is dropped
     * rather than left inert.
     */
    public function saveInterface(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        if ($this->pdo === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'no_database',
                'message' => 'Interface preferences require a configured database (app_config table).',
            ], 503);
        }
        $body = $this->readJsonBody();
        $theme = isset($body['theme']) && is_string($body['theme']) ? $body['theme'] : null;
        $repo = new ConfigRepository($this->pdo);
        try {
            if ($theme !== null) { $repo->setUiTheme($theme); }
            $repo->purgeRetiredUiKeys();
        } catch (\Throwable $e) {
            Logger::warn('settings.interface.save_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return Response::json([
                'ok'      => false,
                'error'   => 'save_failed',
                'message' => 'Failed to persist interface preferences.',
            ], 500);
        }
        return Response::json([
            'ok'        => true,
            'interface' => $repo->getUiPreferences(),
        ]);
    }

    /**
     * @param array<string,mixed>|null $body Decoded body; injected by the
     *        offline API tests (the CLI SAPI has no `php://input`). Null
     *        means "read the request body" — the route lambda passes
     *        nothing, exactly like the `?PDO $pdo` dependencies above.
     */
    public function saveProviders(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body ??= $this->readJsonBody();
        $updates = [];
        $written = [];
        foreach (['openrouter', 'bothub'] as $provider) {
            $section = $body[$provider] ?? null;
            if (!is_array($section)) {
                continue;
            }
            if (array_key_exists('active', $section)) {
                $updates['connectors.' . $provider . '.active'] = (bool)$section['active'];
            }
            if (isset($section['base_url']) && is_string($section['base_url']) && trim($section['base_url']) !== '') {
                $updates['connectors.' . $provider . '.base_url'] = trim((string)$section['base_url']);
            }
            if (isset($section['api_key']) && is_string($section['api_key'])) {
                $key = trim((string)$section['api_key']);
                // '****' / placeholder values mean "no change". Otherwise
                // write to .{provider}.key (preferred) so the JSON stays
                // free of secrets, and clear any in-config copy.
                if ($key !== '' && !preg_match('/^[\*•]+$/u', $key)) {
                    // A key that did not land must not be reported as
                    // saved: the next request would authenticate with the
                    // old (or no) credential and the operator would have
                    // been told it worked.
                    $secret = SettingsPersist::secret('.' . $provider . '.key', $key, 'settings.providers');
                    if (!$secret['ok']) { return $secret['response']; }
                    $updates['connectors.' . $provider . '.api_key'] = '';
                    $written[] = $provider;
                } elseif ($key === '' && ($section['clear_key'] ?? false) === true) {
                    $cleared = SettingsPersist::secret('.' . $provider . '.key', '', 'settings.providers');
                    if (!$cleared['ok']) { return $cleared['response']; }
                    $updates['connectors.' . $provider . '.api_key'] = '';
                }
            }
        }
        $persist = SettingsPersist::runtime($updates, $this->rootDir, 'settings.providers');
        if (!$persist['ok']) { return $persist['response']; }
        return Response::json([
            'ok'                  => true,
            'updated_keys_for'    => $written,
            'providers' => [
                'openrouter' => $this->maskedProviderKey('openrouter'),
                'bothub'     => $this->maskedProviderKey('bothub'),
            ],
            // Echoed from the file on disk, not from memory: the client
            // compares this against what it sent before saying "Saved.".
            'persisted' => SettingsPersist::persisted(),
        ]);
    }

    public function testProvider(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $this->readJsonBody();
        $provider = strtolower((string)($body['provider'] ?? ''));
        if (!in_array($provider, ['openrouter', 'bothub'], true)) {
            return Response::json(['ok' => false, 'error' => 'invalid_provider'], 400);
        }
        $key = '';
        if (isset($body['api_key']) && is_string($body['api_key'])) {
            $candidate = trim((string)$body['api_key']);
            if ($candidate !== '' && !preg_match('/^[\*•]+$/u', $candidate)) {
                $key = $candidate;
            }
        }
        if ($key === '') {
            try {
                $key = Config::getConnectorKey($provider);
            } catch (\Throwable) {
                $key = '';
            }
        }
        $baseUrl = trim((string)($body['base_url'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = (string)Config::get('connectors.' . $provider . '.base_url', '');
        }
        $result = $provider === 'openrouter'
            ? ProviderTester::testOpenRouter($key, $baseUrl ?: 'https://openrouter.ai/api/v1')
            : ProviderTester::testBotHub($key, $baseUrl ?: 'https://bothub.chat/api/v2/openai/v1');
        return Response::json($result);
    }

    /**
     * POST /api/settings/models/import — refresh a provider's catalogue and
     * PERSIST it.
     *
     * Until v1.8.50 this endpoint only answered with the catalogue: the
     * imported rows lived in the browser tab (`state.lastImport`) and were
     * gone after F5 — "the import works only until I reload". The
     * catalogue the import produces is now merged into the same
     * `models[]` list in `var/config/runtime.json` that "Save models"
     * writes and the pipeline reads, so the table, the add-model dropdown
     * and `Config::getActiveModels()` all agree across a reload.
     *
     * The merge ({@see ModelCatalogMerge::mergeImported()}) never
     * duplicates a row and never touches the flags of one that already
     * exists; a newly discovered model is inert until the operator ticks
     * it, and the response says exactly how many rows were added,
     * already present and left out by the row cap.
     *
     * Response keys (`ok`, `provider`, `count`, `catalog`, `popular`,
     * `note`, `error`) are unchanged; `imported`, `models` and
     * `persisted` are additions.
     *
     * @param array<string,mixed>|null $body Injected by the offline tests;
     *        null means "read the request body".
     */
    public function importModels(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body ??= $this->readJsonBody();
        $provider = strtolower((string)($body['provider'] ?? ''));
        if (!in_array($provider, ['openrouter', 'bothub'], true)) {
            return Response::json(['ok' => false, 'error' => 'invalid_provider'], 400);
        }
        // The fetch itself lives in {@see ModelImporter}, which the
        // actualization worker calls for its first stage too: this endpoint
        // and that run have to fetch the same catalogue the same way. Only
        // the answer differs, so only the answer is built here.
        $fetched = $this->importer()->fetch($provider);
        if (!$fetched['ok']) {
            return $provider === 'openrouter'
                // OpenRouter's old failure body was the fetch result itself;
                // it is rebuilt key-for-key so the response contract is
                // unchanged for every existing client.
                ? Response::json([
                    'ok'      => false,
                    'status'  => $fetched['status'],
                    'models'  => [],
                    'error'   => $fetched['error'],
                ], 502)
                : Response::json([
                    'ok'       => false,
                    'provider' => 'bothub',
                    'count'    => 0,
                    'catalog'  => [],
                    'note'     => self::BOTHUB_IMPORT_NOTE,
                    'error'    => $fetched['error'],
                ], 502);
        }

        return $this->persistImportedCatalog($provider, $fetched['models'], $fetched['popular']);
    }

    /**
     * Merge a fetched provider catalogue into the persisted model list and
     * answer with the result.
     *
     * Split out of {@see self::importModels()} because it is the half that
     * touches state: the fetch needs the network, this needs a writable
     * `var/config/runtime.json`, and only the second one can be exercised
     * offline.
     *
     * @param array<int,mixed>            $catalog Provider rows ({id,name}).
     * @param array<int,array<string,mixed>> $popular Curated intersection (OpenRouter only).
     */
    public function persistImportedCatalog(string $provider, array $catalog, array $popular = []): string
    {
        $merge = ModelCatalogMerge::mergeImported(
            (array)Config::get('models', []),
            $catalog,
            $provider,
            // v1.8.60 — the cap is configuration (`models.catalog.max_rows`),
            // not the hard-coded 64 the merge used to clamp every caller to.
            Config::catalogMaxRows(),
        );
        $persist = SettingsPersist::runtime(['models' => $merge['rows']], $this->rootDir, 'settings.models.import');
        if (!$persist['ok']) { return $persist['response']; }

        return Response::json([
            'ok'       => true,
            'provider' => $provider,
            'count'    => count($catalog),
            'catalog'  => $catalog,
            'popular'  => $popular,
            'note'     => self::BOTHUB_IMPORT_NOTE,
            'imported' => [
                'added'      => count($merge['added']),
                'duplicates' => $merge['duplicates'],
                'present'    => $merge['present'],
                'truncated'  => $merge['truncated'],
                'limit'      => $merge['limit'],
                'rows'       => count($merge['rows']),
                'ids'        => $merge['added'],
                // Ids that were catalogued but marked unusable for the
                // synchronous pool, with the reason — the operator is told
                // which of the new rows will never run in L1/L4.
                'batch'      => $merge['batch'],
                'batch_reason' => ModelId::BATCH_POOL_REASON,
            ],
            'models'    => $this->modelsState(),
            'persisted' => SettingsPersist::persisted(),
        ]);
    }

    /**
     * POST /api/settings/models — replace the catalogue with the rows the
     * Models tab is showing.
     *
     * Two things this used to get wrong, both reported by operators:
     *
     *   1. The row shape written here is only `provider` / `model` /
     *      `active`, so a save stripped `label`, `rank`, `category` and
     *      `default` off every row the shipped catalogues annotate.
     *      {@see ModelCatalogMerge::mergeSubmitted()} re-attaches them.
     *   2. The write was not verified. When `var/config/runtime.json` could
     *      not be replaced (a file left owned by root), the endpoint still
     *      answered `{"ok":true}` — and the reload that follows re-read the
     *      OLD file, so the tab repainted the shipped defaults. That is the
     *      "Save Models resets my list to the initial settings" symptom;
     *      a failed write is now a 500 with the filesystem facts, and the
     *      page never says "Saved." for it.
     */
    public function saveModels(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body ??= $this->readJsonBody();
        $models = $body['models'] ?? null;
        if (!is_array($models)) {
            return Response::json(['ok' => false, 'error' => 'invalid_models'], 400);
        }
        $merge = ModelCatalogMerge::mergeSubmitted(
            $models,
            (array)Config::get('models', []),
            // v1.8.60 — configuration, not the hard-coded 64. `0` disables
            // the ceiling entirely.
            Config::catalogMaxRows(),
        );
        $updates = ['models' => $merge['rows']];
        /** Pool entries refused because they are batch-only. */
        $batchRejected = [];
        if (isset($body['default_selected']) && is_array($body['default_selected'])) {
            $sel = [];
            foreach ($body['default_selected'] as $m) {
                if (!is_array($m)) { continue; }
                $p = strtolower(trim((string)($m['provider'] ?? '')));
                $id = trim((string)($m['model'] ?? ''));
                if ($p === '' || $id === '') { continue; }
                // A batch-only id is served by the asynchronous Batch API and
                // can never be a pool member ({@see ModelId}). The Models tab
                // cannot offer one, so this is the belt to that braces: a
                // hand-written request cannot plant a model the pool would
                // fail on at every query.
                if (ModelId::isBatchId($id)) { $batchRejected[] = $p . '/' . $id; continue; }
                $sel[] = ['provider' => $p, 'model' => $id];
                if (count($sel) >= 12) { break; }
            }
            $updates['pipeline.default_selected_models'] = $sel;
        }
        $persist = SettingsPersist::runtime($updates, $this->rootDir, 'settings.models');
        if (!$persist['ok']) { return $persist['response']; }
        return Response::json([
            'ok'        => true,
            'models'    => $this->modelsState(),
            'saved'     => [
                'rows' => count($merge['rows']),
                'kept' => $merge['kept'],
                'new'  => $merge['added'],
            ],
            // Pool entries the write refused, with the reason: dropping them
            // quietly would make the saved pool differ from the requested
            // one without saying so.
            'rejected'  => [
                'batch_only' => array_values(array_unique($batchRejected)),
                'reason'     => ModelId::BATCH_POOL_REASON,
            ],
            'persisted' => SettingsPersist::persisted(),
        ]);
    }

    public function saveDatabase(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $this->readJsonBody();
        $parts = [
            'driver'      => 'mysql',
            'host'        => (string)($body['host'] ?? 'localhost'),
            'port'        => (int)($body['port'] ?? 3306),
            'dbname'      => (string)($body['dbname'] ?? ''),
            'charset'     => (string)($body['charset'] ?? 'utf8mb4'),
            'unix_socket' => (string)($body['unix_socket'] ?? ''),
        ];
        $dsn = DatabaseDsn::build($parts);
        $updates = [
            'database.dsn' => $dsn,
        ];
        if (isset($body['user']) && is_string($body['user'])) {
            $updates['database.user'] = trim((string)$body['user']);
        }
        if (isset($body['password']) && is_string($body['password'])) {
            $pw = (string)$body['password'];
            // Empty + clear_password=true clears; otherwise empty means "no change".
            if ($pw !== '' && !preg_match('/^[\*•]+$/u', $pw)) {
                $updates['database.password'] = $pw;
            } elseif (($body['clear_password'] ?? false) === true) {
                $updates['database.password'] = '';
            }
        }
        $persist = SettingsPersist::runtime($updates, $this->rootDir, 'settings.database');
        if (!$persist['ok']) { return $persist['response']; }
        return Response::json([
            'ok'     => true,
            'dsn'    => $dsn,
            'parsed' => DatabaseDsn::parse($dsn),
            'persisted' => SettingsPersist::persisted(),
        ]);
    }

    public function testDatabase(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $this->readJsonBody();
        // Allow ad-hoc test from the form before save.
        $useForm = isset($body['host']) || isset($body['dbname']) || isset($body['port']) || isset($body['unix_socket']);
        if ($useForm) {
            // v1.9.x - an empty field means "leave as configured": the form
            // mirrors config.php / .config.php (Config has already merged
            // the overlays), and its password placeholder promises exactly
            // that. Treating the blank as empty credentials is what produced
            // "Access denied ... (using password: NO)" on hosts whose real
            // credentials live in the protected overlay.
            $merged = DatabaseDsn::mergeForm($body, [
                'dsn'      => (string)Config::get('database.dsn', ''),
                'user'     => (string)Config::get('database.user', ''),
                'password' => (string)Config::get('database.password', ''),
            ]);
            $dsn  = $merged['dsn'];
            $user = $merged['user'];
            $pw   = $merged['password'];
        } else {
            $dsn  = (string)Config::get('database.dsn', '');
            $user = (string)Config::get('database.user', '');
            $pw   = (string)Config::get('database.password', '');
            $merged = [
                'sources' => [
                    'dsn'      => 'config',
                    'user'     => 'config',
                    'password' => $pw === '' ? 'none' : 'config',
                ],
            ];
        }
        $result = DatabaseDsn::test($dsn, $user, $pw);
        $result['dsn'] = $dsn;
        $result['sources'] = $merged['sources'];
        return Response::json($result);
    }

    public function listMigrations(?PDO $pdo): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        if ($pdo === null) {
            return Response::json(['ok' => false, 'error' => 'no_database', 'message' => 'Database is not connected.'], 503);
        }
        $mgr = new MigrationManager($pdo, $this->rootDir . '/db/migrations');
        return Response::json([
            'ok'         => true,
            'migrations' => $mgr->list(),
        ]);
    }

    public function applyMigrations(?PDO $pdo): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        if ($pdo === null) {
            return Response::json(['ok' => false, 'error' => 'no_database'], 503);
        }
        try {
            $mgr = new MigrationManager($pdo, $this->rootDir . '/db/migrations');
            $res = $mgr->applyPending();
            $okAll = $res['failed'] === [];
            // Every migration file is applied, so the live schema is by
            // definition the one db/schema.sql declares - record that version.
            // Migrations 014+ deliberately carry no top-level @schema-version
            // (a column addition is versioned per table instead), so applying
            // them alone left app_config holding the previous release number and
            // the interface kept reporting drift that no longer exists.
            $recordedVersion = $okAll ? $this->recordExpectedSchemaVersion($pdo) : null;
            // R7 - the journal entry for a repair the health panel (and the
            // Migrations card) offers: which run asked for it, what it applied
            // and what it skipped. Idempotent by construction - a second run
            // reports the same files as skipped.
            Logger::info('settings.migrations.apply', [
                'applied' => count($res['applied']),
                'skipped' => count($res['skipped']),
                'failed'  => count($res['failed']),
                'schema_version' => $recordedVersion,
            ]);
            return Response::json([
                'ok'         => $okAll,
                'applied'    => $res['applied'],
                'skipped'    => $res['skipped'],
                'failed'     => $res['failed'],
                'schema_version' => $recordedVersion,
                'migrations' => $mgr->list(),
            ], $okAll ? 200 : 200); // never raw 500; client renders failed[]
        } catch (\Throwable $e) {
            Logger::warn('settings.migrations.apply.fatal', ['error' => $e->getMessage()]);
            return Response::json([
                'ok'      => false,
                'error'   => 'apply_failed',
                'message' => Logger::sanitizeScalar($e->getMessage()),
                'failed'  => [[
                    'name'    => '',
                    'message' => Logger::sanitizeScalar($e->getMessage()),
                ]],
            ], 200);
        }
    }

    /**
     * Record the version db/schema.sql declares, once nothing is pending.
     *
     * Applying a migration file records the version that file announces, but
     * from 014 onwards the files announce only a per-table version: a column
     * addition is not a released schema version. The top-level annotation lives
     * in db/schema.sql and moves with the application release, so after a
     * successful "Apply Migrations" it is this value that describes the live
     * database - and leaving it at the previous release is what made the
     * interface report drift forever.
     *
     * Best-effort: a failure here is logged and reported as null, never as a
     * failed migration, because the schema itself was applied successfully.
     */
    private function recordExpectedSchemaVersion(PDO $pdo): ?string
    {
        try {
            $parsed = VersionParser::parse((string)file_get_contents($this->rootDir . '/db/schema.sql'));
            $expected = $parsed['schema'] ?? null;
            if (is_string($expected) && $expected !== '') {
                (new ConfigRepository($pdo))->setSchemaVersion($expected);

                return $expected;
            }
        } catch (\Throwable $e) {
            Logger::warn('settings.migrations.version_record_failed', [
                'error' => Logger::sanitizeScalar($e->getMessage()),
            ]);
        }

        return null;
    }

    public function baselineMigrations(?PDO $pdo): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        if ($pdo === null) {
            return Response::json(['ok' => false, 'error' => 'no_database'], 503);
        }
        $mgr = new MigrationManager($pdo, $this->rootDir . '/db/migrations');
        $res = $mgr->baseline();
        return Response::json([
            'ok'         => true,
            'baselined'  => $res['baselined'],
            'migrations' => $mgr->list(),
        ]);
    }

    /**
     * v1.8.27: apply a single migration file by name. The filename is
     * whitelisted against the directory listing inside the manager
     * (no path traversal). Always returns 200 with a structured body
     * so the per-row UI button can render success / skipped / error
     * without a 500-page intermission.
     */
    public function applyMigrationOne(?PDO $pdo): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        if ($pdo === null) {
            return Response::json(['ok' => false, 'error' => 'no_database'], 503);
        }
        $body = $this->readJsonBody();
        $name = is_string($body['name'] ?? null) ? trim((string)$body['name']) : '';
        if ($name === '') {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_filename',
                'message' => 'Provide the migration filename in {"name": "001_initial.sql"}.',
            ], 400);
        }
        try {
            $mgr = new MigrationManager($pdo, $this->rootDir . '/db/migrations');
            $res = $mgr->applyOneByName($name);
            Logger::info('settings.migrations.apply_one', [
                'name'    => $res['name'],
                'status'  => $res['status'],
                'applied' => $res['applied'],
                'skipped' => $res['skipped'],
            ]);
            return Response::json([
                'ok'              => $res['ok'],
                'name'            => $res['name'],
                'status'          => $res['status'],
                'applied'         => $res['applied'],
                'skipped'         => $res['skipped'],
                'version_updates' => $res['version_updates'],
                'error'           => $res['error'],
                'migrations'      => $mgr->list(),
            ], 200);
        } catch (\Throwable $e) {
            Logger::warn('settings.migrations.apply_one.fatal', ['error' => $e->getMessage()]);
            return Response::json([
                'ok'      => false,
                'name'    => $name,
                'status'  => 'fatal',
                'error'   => ['message' => Logger::sanitizeScalar($e->getMessage())],
            ], 200);
        }
    }

    /**
     * v1.8.27: lightweight version-status endpoint used by the top
     * banner. Returns no admin secrets and never raises a hard error
     * — a missing app_config table simply reports "migration needed".
     * The banner only navigates the operator to the Settings tab;
     * destructive endpoints behind it stay admin-gated.
     */
    public function versionStatus(?PDO $pdo): string
    {
        if (!Config::isSettingsTabEnabled()) {
            return Response::json([
                'ok'                => true,
                'tab_enabled'       => false,
                'migration_needed'  => false,
                'database_ready'    => $pdo !== null,
                'admin_unlocked'    => AdminAuth::check(),
            ]);
        }
        if ($pdo === null) {
            return Response::json([
                'ok'                   => true,
                'tab_enabled'          => true,
                'migration_needed'     => false,
                'database_ready'       => false,
                'admin_unlocked'       => AdminAuth::check(),
                'config_table_present' => false,
            ]);
        }
        try {
            $schemaFile = $this->rootDir . '/db/schema.sql';
            $schemaTables = SchemaParser::parseFile($schemaFile);
            $expected = VersionParser::expectedFromSchema($schemaFile, $schemaTables);
            $config = new ConfigRepository($pdo);
            $configPresent = $config->tableExists();
            $live = $config->tableVersions();
            $liveSchemaVersion = $config->get(ConfigRepository::SCHEMA_KEY);
            $mismatches = [];
            if (!$configPresent) {
                $mismatches[] = [
                    'table'            => ConfigRepository::TABLE,
                    'current_version'  => null,
                    'expected_version' => $expected['tables'][ConfigRepository::TABLE] ?? $expected['schema'],
                    'reason'           => 'config_table_missing',
                ];
            } else {
                foreach ($expected['tables'] as $table => $expectedVer) {
                    $currentVer = $live[$table] ?? null;
                    if ($currentVer === null) {
                        $mismatches[] = [
                            'table'            => $table,
                            'current_version'  => null,
                            'expected_version' => $expectedVer,
                            'reason'           => 'no_config_entry',
                        ];
                        continue;
                    }
                    if (VersionParser::compare($currentVer, $expectedVer) !== 0) {
                        $mismatches[] = [
                            'table'            => $table,
                            'current_version'  => $currentVer,
                            'expected_version' => $expectedVer,
                            'reason'           => VersionParser::compare($currentVer, $expectedVer) < 0 ? 'outdated' : 'ahead',
                        ];
                    }
                }
            }
            // v1.9.x - the release's own schema version is part of the verdict.
            // Migrations 014+ carry no top-level @schema-version (a column addition
            // is versioned per table instead), so the recorded value moves only when
            // applyMigrations() records what db/schema.sql declares. While that lagged,
            // this endpoint answered "nothing pending" for a database whose schema
            // version was a whole release behind, and the History banner disagreed with
            // it about the same database.
            $expectedSchema = is_string($expected['schema'] ?? null) ? (string)$expected['schema'] : '';
            if ($expectedSchema !== '') {
                $liveSchema = is_string($liveSchemaVersion) ? trim($liveSchemaVersion) : '';
                if ($liveSchema === '') {
                    $mismatches[] = [
                        'table'            => ConfigRepository::SCHEMA_KEY,
                        'current_version'  => null,
                        'expected_version' => $expectedSchema,
                        'reason'           => 'no_config_entry',
                    ];
                } elseif (VersionParser::compare($liveSchema, $expectedSchema) !== 0) {
                    $mismatches[] = [
                        'table'            => ConfigRepository::SCHEMA_KEY,
                        'current_version'  => $liveSchema,
                        'expected_version' => $expectedSchema,
                        'reason'           => VersionParser::compare($liveSchema, $expectedSchema) < 0 ? 'outdated' : 'ahead',
                    ];
                }
            }

            return Response::json([
                'ok'                      => true,
                'tab_enabled'             => true,
                'migration_needed'        => $mismatches !== [],
                'database_ready'          => true,
                'admin_unlocked'          => AdminAuth::check(),
                'config_table_present'    => $configPresent,
                'expected_schema_version' => $expected['schema'],
                'live_schema_version'     => $liveSchemaVersion,
                'mismatches'              => $mismatches,
            ]);
        } catch (\Throwable $e) {
            // Never raise a 500 — the banner must always decide.
            return Response::json([
                'ok'               => true,
                'tab_enabled'      => true,
                'migration_needed' => false,
                'database_ready'   => false,
                'admin_unlocked'   => AdminAuth::check(),
                'error'            => Logger::sanitizeScalar($e->getMessage()),
            ]);
        }
    }

    /**
     * v1.8.25: Check live DB tables/columns against db/schema.sql.
     * Read-only — no DDL is executed.
     */
    public function checkDatabase(?PDO $pdo): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        if ($pdo === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'no_database',
                'message' => 'Database is not connected. Save and test the DSN first.',
            ], 503);
        }
        $inspector = new SchemaInspector($pdo, $this->rootDir . '/db/schema.sql');
        $report = $inspector->inspect();
        Logger::info('settings.database.check', [
            'schema_ready'    => (bool)($report['schema_ready'] ?? false),
            'missing_tables'  => count((array)($report['missing_tables'] ?? [])),
            'extra_tables'    => count((array)($report['extra_tables'] ?? [])),
            'table_diffs'     => count((array)($report['table_diffs'] ?? [])),
        ]);
        return Response::json([
            'ok'     => $report['errors'] === [],
            'report' => $report,
        ]);
    }

    /**
     * v1.8.25: drop RevoAp tables and recreate from db/schema.sql.
     * Admin-only, POST-only, requires {"confirm":"RECREATE"}.
     */
    public function recreateDatabase(?PDO $pdo): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        if ($pdo === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'no_database',
                'message' => 'Database is not connected.',
            ], 503);
        }
        $body = $this->readJsonBody();
        $confirm = is_string($body['confirm'] ?? null) ? (string)$body['confirm'] : '';
        if ($confirm !== 'RECREATE') {
            return Response::json([
                'ok'      => false,
                'error'   => 'confirmation_required',
                'message' => 'ReCreate DB requires {"confirm":"RECREATE"} in the request body.',
            ], 400);
        }
        $dropExtra = ($body['drop_extra'] ?? false) === true;
        try {
            $mgr = new SchemaManager(
                $pdo,
                $this->rootDir . '/db/schema.sql',
                $this->rootDir . '/db/migrations',
            );
            $report = $mgr->recreate($dropExtra);
        } catch (\Throwable $e) {
            Logger::warn('settings.database.recreate.fatal', ['error' => $e->getMessage()]);
            return Response::json([
                'ok'     => false,
                'report' => [
                    'errors' => [[
                        'phase'   => 'fatal',
                        'message' => Logger::sanitizeScalar($e->getMessage()),
                    ]],
                ],
            ], 200);
        }
        Logger::warn('settings.database.recreate', [
            'dropped'   => count((array)($report['dropped_tables'] ?? [])),
            'created'   => count((array)($report['created_tables'] ?? [])),
            'kept'      => count((array)($report['kept_tables'] ?? [])),
            'drop_extra'=> $dropExtra,
            'errors'    => count((array)($report['errors'] ?? [])),
        ]);
        $ok = ($report['errors'] ?? []) === [] && ($report['created_tables'] ?? []) !== [];
        return Response::json([
            'ok'     => $ok,
            'report' => $report,
        ], 200);
    }

    public function exportSchema(?PDO $pdo): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        if ($pdo === null) {
            return Response::json(['ok' => false, 'error' => 'no_database'], 503);
        }
        $mgr  = new MigrationManager($pdo, $this->rootDir . '/db/migrations');
        $body = $mgr->exportSchema();
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="revoap-schema.sql"');
        }
        return $body;
    }

    public function saveRuntime(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $this->readJsonBody();
        $updates = [];
        if (array_key_exists('debug', $body)) {
            $updates['app.debug'] = (bool)$body['debug'];
        }
        if (array_key_exists('app_url', $body) && is_string($body['app_url'])) {
            $updates['app.url'] = trim((string)$body['app_url']);
        }
        if (array_key_exists('timezone', $body) && is_string($body['timezone'])) {
            $tz = trim((string)$body['timezone']);
            if ($tz !== '' && @timezone_open($tz) !== false) {
                $updates['app.timezone'] = $tz;
            }
        }
        if (isset($body['cache']) && is_array($body['cache'])) {
            if (array_key_exists('enabled', $body['cache'])) {
                $updates['cache.model_responses.enabled'] = (bool)$body['cache']['enabled'];
            }
            if (array_key_exists('ttl_seconds', $body['cache'])) {
                $ttl = (int)$body['cache']['ttl_seconds'];
                if ($ttl >= 0) {
                    $updates['cache.model_responses.ttl_seconds'] = $ttl;
                }
            }
        }
        if (isset($body['pipeline']) && is_array($body['pipeline'])) {
            if (array_key_exists('parallel_l1', $body['pipeline'])) {
                $updates['pipeline.parallel_l1'] = (bool)$body['pipeline']['parallel_l1'];
            }
            if (array_key_exists('max_roles_per_skill', $body['pipeline'])) {
                $v = (int)$body['pipeline']['max_roles_per_skill'];
                if ($v >= 1 && $v <= 64) {
                    $updates['pipeline.max_roles_per_skill'] = $v;
                }
            }
        }
        $persist = SettingsPersist::runtime($updates, $this->rootDir, 'settings.runtime');
        if (!$persist['ok']) { return $persist['response']; }
        return Response::json([
            'ok'      => true,
            'updated' => array_keys($updates),
            'persisted' => SettingsPersist::persisted(),
        ]);
    }

    /**
     * v1.8.45 — POST /api/settings/prompt-cache
     *
     * Body (all fields optional; only the ones present are written):
     *   {"enabled": true, "ttl": "5m"|"1h", "max_breakpoints": 1,
     *    "min_prompt_tokens": 1024, "include_usage": true}
     *
     * Same admin gate and same write path as {@see self::saveRuntime()}
     * (`SettingsPersist::runtime()` → `RuntimeConfig::updateChecked()`), so
     * a write that does not land is reported as a failure rather than
     * silently kept in memory.
     *
     * Validation is deliberately narrow, because every one of these values
     * ends up in a request body a provider will parse:
     *
     *   - `ttl` accepts only the two documented values. Anything else is
     *     refused rather than written, so an operator cannot save `"2h"`
     *     and wonder later why the provider charged full price.
     *   - `max_breakpoints` is clamped to 1..4 (documented hard ceiling)
     *     instead of refused: the ceiling is a provider constraint, not an
     *     operator error, and the clamp is what the request builder would
     *     apply anyway.
     *   - `min_prompt_tokens` is clamped to 0..1_000_000 for the same
     *     reason; 0 keeps its meaning of "no size guard".
     *   - `provider` is not settable from the UI. Only the connector that
     *     documents caching may use it, and that is a code-level decision.
     *
     * @param array<string,mixed>|null $body Decoded body; injected by the
     *        offline API tests (the CLI SAPI has no `php://input`). Null
     *        means "read the request body" — the route lambda passes
     *        nothing, exactly like the `?PDO $pdo` dependencies above.
     */
    public function savePromptCache(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body ??= $this->readJsonBody();
        $updates = [];

        if (array_key_exists('enabled', $body)) {
            $updates['prompt_cache.enabled'] = (bool)$body['enabled'];
        }
        if (array_key_exists('include_usage', $body)) {
            $updates['prompt_cache.include_usage'] = (bool)$body['include_usage'];
        }
        if (array_key_exists('ttl', $body) && is_string($body['ttl'])) {
            $ttl = strtolower(trim($body['ttl']));
            if (in_array($ttl, [Policy::TTL_FIVE_MINUTES, Policy::TTL_ONE_HOUR], true)) {
                $updates['prompt_cache.ttl'] = $ttl;
            }
        }
        if (array_key_exists('max_breakpoints', $body)) {
            $v = (int)$body['max_breakpoints'];
            $updates['prompt_cache.max_breakpoints'] = max(1, min(Policy::MAX_BREAKPOINTS, $v));
        }
        if (array_key_exists('min_prompt_tokens', $body)) {
            $v = (int)$body['min_prompt_tokens'];
            $updates['prompt_cache.min_prompt_tokens'] = max(0, min(1_000_000, $v));
        }

        $persist = SettingsPersist::runtime($updates, $this->rootDir, 'settings.prompt_cache');
        if (!$persist['ok']) { return $persist['response']; }
        return Response::json([
            'ok'        => true,
            'updated'   => array_keys($updates),
            'persisted' => SettingsPersist::persisted(),
            // Echo the resolved policy so the card re-renders from what the
            // request builder will actually use, not from the raw input.
            'policy'    => Policy::fromConfig()->toArray(),
        ]);
    }

    /**
     * v1.8.47 — Settings → Cache. Read-only snapshot of the model
     * response cache: both backends, entry counts, bytes, oldest/newest
     * entry, hit counter and provider/model distribution.
     *
     * Same admin gate as every other /api/settings/* endpoint
     * ({@see self::requireAdmin()}). Nothing is deleted, no connection is
     * opened that the request did not already have, and a missing cache
     * directory is reported as "empty", never as a failure.
     *
     * The summary comes from the same methods the CLI uses
     * ({@see \App\Cache\ModelResponseCache::summary()},
     * {@see \App\Cache\ModelResponseCache::backendInfo()},
     * {@see \App\Cache\ModelResponseCache::isEnabled()}) so the card and
     * `php bin/cache-purge.php --status` can never disagree.
     */
    public function cacheStats(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $stats = (new CacheStatistics($this->responseCache(), $this->cacheConnection(), $this->rootDir))->report();
        return Response::json($stats, 200);
    }

    /**
     * v1.8.47 — Settings → Cache → Purge. Bulk invalidation of every cache
     * store, mirroring `php bin/cache-purge.php --older-than-days=N --yes`:
     * the database table, the hierarchical `cache/<provider>/<model>/` tree
     * and the legacy flat `var/cache/model-responses/` tree.
     *
     * The response's `deleted` object reports the legacy tree separately
     * (`filesystem_legacy` / `bytes_legacy`) and keeps `bytes`/`bytes_human`
     * scoped to the hierarchical tree, so the card's
     * `before.size_bytes - after.size_bytes` delta stays consistent with the
     * size it subtracts (see {@see CacheStatistics::purge()}).
     *
     * Body:
     *   {"confirm":"PURGE"}            required — same guard pattern as
     *                                 Database → ReCreate DB
     *   {"older_than_days": 0}         optional, 0 = everything (default)
     *
     * Refuses with 400 `confirmation_required` when the control word is
     * missing/wrong and with 400 `invalid_window` when the window is not
     * an integer in [0, MAX_OLDER_THAN_DAYS] — the window is validated
     * before anything is touched, so a typo can never become a full wipe.
     * Returns 503 `no_cache_backend` when neither backend is reachable
     * (the CLI exits 4 in the same situation).
     *
     * @param array<string,mixed>|null $body Decoded body; injected by the
     *        offline API tests (the CLI SAPI has no `php://input`). Null
     *        means "read the request body" — the route lambda passes
     *        nothing, exactly like the `?PDO $pdo` dependencies above.
     */
    public function purgeCache(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body ??= $this->readJsonBody();

        $confirm = is_string($body['confirm'] ?? null) ? trim((string)$body['confirm']) : '';
        if ($confirm !== self::PURGE_CONFIRM) {
            return Response::json([
                'ok'      => false,
                'error'   => 'confirmation_required',
                'message' => 'Purge cache requires {"confirm":"' . self::PURGE_CONFIRM . '"} in the request body.',
            ], 400);
        }

        $rawWindow = $body['older_than_days'] ?? 0;
        if (is_bool($rawWindow) || (!is_int($rawWindow) && !is_numeric($rawWindow))) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_window',
                'message' => 'older_than_days must be an integer: 0 (everything) or a number of days',
            ], 400);
        }
        $olderThanDays = (int)$rawWindow;
        if ($olderThanDays < 0 || $olderThanDays > CacheStatistics::MAX_OLDER_THAN_DAYS) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_window',
                'message' => 'older_than_days must be between 0 (everything) and '
                    . CacheStatistics::MAX_OLDER_THAN_DAYS . ' days',
                'value'   => $olderThanDays,
            ], 400);
        }

        $cache  = $this->responseCache();
        $info   = $cache->backendInfo();
        if (!$info['database'] && !$info['filesystem']) {
            return Response::json([
                'ok'       => false,
                'error'    => 'no_cache_backend',
                'message'  => 'neither the database table nor the filesystem root is reachable',
                'backends' => $info,
            ], 503);
        }

        return Response::json(
            (new CacheStatistics($cache, $this->cacheConnection(), $this->rootDir))->purge($olderThanDays),
            200,
        );
    }

    /**
     * The cache instance the pipeline itself uses, built from the live
     * connection when this request has one, and from the loaded config
     * otherwise.
     *
     * v1.8.47 — prefer the injected `Database` wrapper. Wrapping a bare
     * PDO with {@see Database::fromPdo()} yields a handle that cannot be
     * rebuilt (no credentials to rebuild it from), so the transparent
     * reconnect this class documents was unreachable for the cache card.
     */
    private function responseCache(): ModelResponseCache
    {
        $db = $this->db ?? ($this->pdo !== null ? Database::fromPdo($this->pdo) : null);
        return ModelResponseCache::fromConfig($db, $this->rootDir);
    }

    /**
     * The connection the read-only cache aggregates run on. Prefers the
     * resilient wrapper for the same reason as {@see responseCache()}.
     */
    private function cacheConnection(): null|PDO|Database
    {
        return $this->db ?? $this->pdo;
    }

    /**
     * @return array<string,mixed>
     */
    private function modelsState(): array
    {
        $all = (array)Config::get('models', []);
        $defaults = (array)Config::get('pipeline.default_selected_models', []);
        $models = [];
        foreach ($all as $m) {
            if (!is_array($m)) { continue; }
            $models[] = [
                'provider' => (string)($m['provider'] ?? ''),
                'model'    => (string)($m['model'] ?? ''),
                'active'   => (bool)($m['active'] ?? false),
            ];
        }
        $sel = [];
        foreach ($defaults as $m) {
            if (!is_array($m)) { continue; }
            $sel[] = [
                'provider' => (string)($m['provider'] ?? ''),
                'model'    => (string)($m['model'] ?? ''),
            ];
        }
        return ['list' => $models, 'default_selected' => $sel];
    }

    /**
     * @return array{has:bool,masked:string,source:string}
     */
    private function maskedProviderKey(string $provider): array
    {
        try {
            $key = Config::getConnectorKey($provider);
        } catch (\Throwable) {
            return ['has' => false, 'masked' => '', 'source' => 'none'];
        }
        $source = 'config';
        if (RuntimeConfig::secretFileExists($provider)) {
            $source = 'file';
        } elseif (getenv(strtoupper($provider) . '_API_KEY')) {
            $source = 'env';
        }
        return [
            'has'    => true,
            'masked' => AdminAuth::mask($key),
            'source' => $source,
        ];
    }
}
