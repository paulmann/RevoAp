<?php
/**
 * Config: Centralized configuration loader with dot-notation access.
 *
 * Loads config.php once, then resolves connector keys from the following
 * sources, in priority order:
 *   1. config.php → connectors.{provider}.api_key (when active=true)
 *   2. {project_root}/.{provider}.key file
 *   3. $_ENV[strtoupper(provider) . '_API_KEY']
 * Throws ConnectorKeyNotFoundException if none of the above yield a key.
 *
 * Root-file overrides applied during load() (deployment-specific data
 * kept out of git). Precedence — earlier wins over later (top-down
 * application order, latest applied wins):
 *
 *   base config.php
 *     < .config.php   (recursive leaf-merge of any section)
 *     < .url          (targeted scalar override of app.url)
 *     < .sql.php      (full database section replace — emergency override)
 *     < runtime.json  (admin-edited persistent overrides; deep-merge)
 *
 * The order is intentional: `.sql.php` is a database-only emergency
 * override and must beat anything in `.config.php`. `runtime.json` is
 * admin-edited via the Settings UI and therefore wins over deployment
 * files; the `database_runtime_shadow` diagnostic flags when this is
 * happening so operators can see why their deployment file appears
 * masked.
 *
 * Files honored (relative to project root, all optional):
 *   - `.config.php` PHP file returning a partial config tree. Recursively
 *                   merged into the base config — only leaves you set
 *                   override; sibling keys at any depth are preserved.
 *                   Numeric/list arrays are treated as leaf values and
 *                   replaced wholesale (e.g. `models`, `pipeline.default_selected_models`).
 *   - `.url`        plain-text public URL → overrides `app.url`.
 *   - `.sql.php`    PHP file returning either ['database' => [...]] or
 *                   the database fields directly → REPLACES `database`.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.3.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Core;

use App\Connectors\Exceptions\ConnectorKeyNotFoundException;
use App\Helpers\RootFiles;

final class Config
{
    /**
     * Default ceiling for the persisted model catalogue, used when neither
     * `models_catalog.max_rows` nor `models.catalog.max_rows` is set.
     *
     * Deliberately far above the 64 rows v1.8.59 hard-coded: that cap was a
     * UI-editing convenience (the Models tab renders one `<tr>` per row)
     * and it silently truncated a provider catalogue of several hundred
     * ids, which is how the pool ended up pointing at models the catalogue
     * did not hold. {@see self::catalogMaxRows()} reads the configured
     * value; `0` means no ceiling.
     */
    public const CATALOG_MAX_ROWS_DEFAULT = 1000;

    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @var string|null Project root directory. */
    private static ?string $rootDir = null;

    /**
     * Load the configuration from the given file. Idempotent.
     *
     * @param  string $file    Absolute path to config.php
     * @param  string $rootDir Absolute path to the project root (for .key files)
     * @return void
     */
    public static function load(string $file, string $rootDir): void
    {
        if (self::$data !== null) {
            return;
        }
        if (!is_file($file)) {
            throw new \RuntimeException("Config file not found: {$file}");
        }
        /** @var array<string,mixed> $data */
        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException("Config file must return an array");
        }
        self::$rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        // v1.8.60 — lift the catalogue *settings* subtree (`models.catalog`)
        // out of the catalogue *data* key before anything reads `models`.
        // See liftCatalogSettings(): without this, `models` would be
        // non-empty the moment an operator added `models.catalog.max_rows`
        // to config.php, which suppresses the shipped-catalogue expansion
        // and leaves the install with zero models.
        $data = self::liftCatalogSettings($data);
        // Expand shipped model-file catalogues into the runtime `models`
        // list BEFORE any operator override is applied. This gives
        // `.config.php` and `runtime.json` the final say while still
        // letting an empty `models` key in the shipped tree pick up the
        // curated defaults.
        $data = self::expandModelFiles($data, self::$rootDir);
        // Root-file overrides (`.url`, `.sql.php`) are applied to the
        // static tree before runtime.json so admin-edited runtime values
        // still win. See applyRootFileOverrides() for the file format.
        $data = self::applyRootFileOverrides($data, self::$rootDir);
        // Runtime overrides (var/config/runtime.json) are merged on top of
        // the static config tree. Failures are silent so a missing file
        // never blocks the request — the static defaults remain in force.
        RuntimeConfig::init(self::$rootDir);
        $data = self::recordRuntimeShadow($data);
        self::$data = RuntimeConfig::merge($data);
        // Synthesise pipeline.default_selected_models from any
        // `default=true` rows in the catalogue if the operator has not
        // supplied an explicit selection.
        self::$data = self::synthesiseDefaultSelection(self::$data);
    }

    /**
     * Reload the static config from disk and re-apply runtime overrides.
     * Used by the Settings API after a save so subsequent calls within
     * the same request see the new values.
     */
    public static function reload(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        $data = require $file;
        if (!is_array($data) || self::$rootDir === null) {
            return;
        }
        $data = self::liftCatalogSettings($data);
        $data = self::expandModelFiles($data, self::$rootDir);
        $data = self::applyRootFileOverrides($data, self::$rootDir);
        RuntimeConfig::init(self::$rootDir);
        $data = self::recordRuntimeShadow($data);
        self::$data = RuntimeConfig::merge($data);
        self::$data = self::synthesiseDefaultSelection(self::$data);
    }

    /**
     * Move the catalogue-settings subtree out of the catalogue data key.
     *
     * v1.8.60 — `models` is a *list of rows* (`[{provider, model, active}]`)
     * and the loader treats a non-empty value as "the operator supplied the
     * catalogue, do not expand the shipped files". That makes
     * `models.catalog.max_rows` a booby trap: writing it in config.php
     * would make `models` non-empty, suppress {@see self::expandModelFiles()},
     * and leave the install with a catalogue that holds one setting array
     * instead of rows — i.e. zero dispatchable models, silently.
     *
     * So the settings subtree is lifted into its own top-level key
     * (`models_catalog`) before anything reads `models`, and
     * {@see self::catalogMaxRows()} reads it from there. Catalogue rows in
     * the same array are untouched, so an operator may still write both.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function liftCatalogSettings(array $data): array
    {
        $models = $data['models'] ?? null;
        if (!is_array($models) || !isset($models['catalog']) || !is_array($models['catalog'])) {
            return $data;
        }
        $catalog = $models['catalog'];
        unset($models['catalog']);
        $data['models_catalog'] = array_replace(
            is_array($data['models_catalog'] ?? null) ? $data['models_catalog'] : [],
            $catalog,
        );
        // A `models` array that held nothing but the settings subtree must
        // read as "no operator catalogue", so the shipped files still
        // expand into it.
        $data['models'] = array_values(array_filter(
            $models,
            static fn(mixed $row): bool => is_array($row),
        ));
        return $data;
    }

    /**
     * How many rows the persisted model catalogue may hold.
     *
     * Read from `models_catalog.max_rows` — the key
     * {@see self::liftCatalogSettings()} produces when an operator writes
     * `models.catalog.max_rows` in `config.php` or `.config.php`. The
     * spelling under `models` is honoured as a second source so a caller
     * that injected the tree directly (`Config::set()` in tests) reads the
     * same value.
     *
     * Returns 0 for "no ceiling at all". A negative value means the same
     * thing: a cap that cannot hold a row is indistinguishable from "do not
     * cap", and treating it as "keep nothing" would empty the catalogue.
     *
     * @return int 0 = unlimited, otherwise the row ceiling
     */
    public static function catalogMaxRows(): int
    {
        try {
            $raw = self::get('models_catalog.max_rows', null);
            if ($raw === null) {
                $raw = self::get('models.catalog.max_rows', null);
            }
        } catch (\Throwable) {
            // Config not loaded yet (a CLI helper, a unit test that only
            // exercises the merge): fall through to the default rather than
            // letting a cap lookup abort the caller.
            return self::CATALOG_MAX_ROWS_DEFAULT;
        }
        if ($raw === null) {
            return self::CATALOG_MAX_ROWS_DEFAULT;
        }
        if (is_bool($raw)) {
            // `true` reads as "the default ceiling", `false` as "off".
            return $raw ? self::CATALOG_MAX_ROWS_DEFAULT : 0;
        }
        if (is_int($raw) || is_float($raw)) {
            return max(0, (int)$raw);
        }
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return self::CATALOG_MAX_ROWS_DEFAULT;
            }
            if (is_numeric($trimmed)) {
                return max(0, (int)$trimmed);
            }
            if (in_array(strtolower($trimmed), ['off', 'false', 'no', 'none', 'unlimited'], true)) {
                return 0;
            }
            if (in_array(strtolower($trimmed), ['on', 'true', 'yes'], true)) {
                return self::CATALOG_MAX_ROWS_DEFAULT;
            }
        }
        // An unusable value (an array, an object, a typo) falls back to the
        // default rather than to 0: a mistyped cap must not silently become
        // "unlimited", and it must not empty the catalogue either.
        return self::CATALOG_MAX_ROWS_DEFAULT;
    }

    /**
     * Expand any catalogue files referenced under `model_files` into
     * the runtime `models` array. The shipped configuration leaves
     * `models` as an empty list and lists each catalogue file under
     * `model_files`; an operator can still supply a populated `models`
     * list (either in this file, in `.config.php`, or via
     * `runtime.json`) and that list wins unchanged.
     *
     * Diagnostics are recorded under `app.diagnostics.model_files`
     * (per-file status: path / loaded / count / error) so
     * /api/health can surface which catalogues were merged.
     *
     * @param  array<string,mixed> $data
     * @param  string              $rootDir
     * @return array<string,mixed>
     */
    private static function expandModelFiles(array $data, string $rootDir): array
    {
        $existing = $data['models'] ?? null;
        $hasExisting = is_array($existing) && $existing !== [];
        $files = $data['model_files'] ?? [];
        if (!is_array($files)) {
            $files = [];
        }
        $rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $loaded = [];
        $diag = [];
        foreach ($files as $rel) {
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $abs = $rel;
            if ($rel[0] !== DIRECTORY_SEPARATOR && !preg_match('#^[A-Za-z]:[\\\\/]#', $rel)) {
                $abs = $rootDir . DIRECTORY_SEPARATOR . $rel;
            }
            $entry = ['path' => $rel, 'loaded' => false, 'count' => 0, 'error' => null];
            if (!@is_file($abs)) {
                $entry['error'] = 'missing';
                $diag[] = $entry;
                continue;
            }
            try {
                /** @var mixed $rows */
                $rows = (static function (string $f): mixed {
                    return require $f;
                })($abs);
            } catch (\Throwable $e) {
                $entry['error'] = 'parse error: ' . $e->getMessage();
                $diag[] = $entry;
                continue;
            }
            if (!is_array($rows)) {
                $entry['error'] = 'must return array, got ' . gettype($rows);
                $diag[] = $entry;
                continue;
            }
            $valid = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $provider = strtolower(trim((string)($row['provider'] ?? '')));
                $model    = trim((string)($row['model'] ?? ''));
                if ($provider === '' || $model === '') {
                    continue;
                }
                $loaded[] = [
                    'provider' => $provider,
                    'model'    => $model,
                    'active'   => (bool)($row['active'] ?? true),
                    'default'  => (bool)($row['default'] ?? false),
                    'label'    => isset($row['label']) ? (string)$row['label'] : '',
                    'rank'     => isset($row['rank']) ? (int)$row['rank'] : 0,
                    'category' => isset($row['category']) ? (string)$row['category'] : '',
                ];
                $valid++;
            }
            $entry['loaded'] = true;
            $entry['count']  = $valid;
            $diag[] = $entry;
        }
        if (!$hasExisting && $loaded !== []) {
            $data['models'] = $loaded;
        }
        if (!isset($data['app']) || !is_array($data['app'])) {
            $data['app'] = [];
        }
        $appDiag = $data['app']['diagnostics'] ?? [];
        if (!is_array($appDiag)) {
            $appDiag = [];
        }
        $appDiag['model_files'] = [
            'used'  => !$hasExisting,
            'files' => $diag,
        ];
        $data['app']['diagnostics'] = $appDiag;
        return $data;
    }

    /**
     * Pick `pipeline.default_selected_models` from the catalogue when
     * the operator has not specified one. We honour any row in the
     * runtime `models` list that carries `default=true` AND is also
     * `active=true`, capped at four rows for a sensible fresh-install
     * selection. An operator-supplied selection wins unchanged.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function synthesiseDefaultSelection(array $data): array
    {
        $pipeline = $data['pipeline'] ?? [];
        if (!is_array($pipeline)) {
            $pipeline = [];
        }
        $existing = $pipeline['default_selected_models'] ?? null;
        if (is_array($existing) && $existing !== []) {
            return $data;
        }
        $models = $data['models'] ?? [];
        if (!is_array($models)) {
            return $data;
        }
        $picks = [];
        foreach ($models as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['default'] ?? false) !== true) {
                continue;
            }
            if (($row['active'] ?? true) !== true) {
                continue;
            }
            $provider = strtolower(trim((string)($row['provider'] ?? '')));
            $model    = trim((string)($row['model'] ?? ''));
            if ($provider === '' || $model === '') {
                continue;
            }
            $picks[] = ['provider' => $provider, 'model' => $model];
            if (count($picks) >= 4) {
                break;
            }
        }
        if ($picks !== []) {
            $pipeline['default_selected_models'] = $picks;
            $data['pipeline'] = $pipeline;
        }
        return $data;
    }

    /**
     * Detect whether `var/config/runtime.json` shadows the database
     * section (i.e. an admin-saved DSN/user/password is overriding
     * either the base config.php block or a `.sql.php` override). The
     * fact is recorded under `app.diagnostics.database_runtime_shadow`
     * so /api/health/database can warn operators when their
     * deployment file is being silently masked.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function recordRuntimeShadow(array $data): array
    {
        $overrides = RuntimeConfig::all();
        $shadow = isset($overrides['database']) && is_array($overrides['database']) && $overrides['database'] !== [];
        $sections = [];
        foreach ($overrides as $k => $v) {
            if (is_array($v) && $v !== []) {
                $sections[] = (string)$k;
            }
        }
        sort($sections);
        if (!isset($data['app']) || !is_array($data['app'])) {
            $data['app'] = [];
        }
        $appDiag = $data['app']['diagnostics'] ?? [];
        if (!is_array($appDiag)) {
            $appDiag = [];
        }
        $appDiag['database_runtime_shadow']   = $shadow;
        $appDiag['runtime_shadow_sections']   = $sections;
        $data['app']['diagnostics']           = $appDiag;
        return $data;
    }

    /**
     * Apply deployment-specific root-file overrides on top of the static
     * config tree. All overrides are optional; missing or unreadable
     * files leave the tree untouched. Invalid contents fall back
     * silently so a malformed file never blocks boot — every file's
     * status (existence, read_ok, loaded, error, mtime, size) is
     * recorded under `app.diagnostics.config_sources` so operators can
     * see exactly why an override did or did not take effect, and the
     * human-readable summary lives at `app.diagnostics.config_overrides`.
     *
     * Application order (later overrides win):
     *   1. base config.php (already in $data)
     *   2. .config.php — recursive leaf-merge of any section
     *   3. .url        — scalar override of app.url
     *   4. .sql.php    — full replace of `database` (emergency DB override)
     *
     * @param  array<string,mixed> $data    Loaded base config tree.
     * @param  string              $rootDir Project root.
     * @return array<string,mixed>
     */
    private static function applyRootFileOverrides(array $data, string $rootDir): array
    {
        $diagnostics = [];
        $sources = [];
        // Pin RootFiles to the same root we were just handed so the
        // helper's own resolution stays consistent with whatever Config
        // was loaded against (tests use a temp directory; production
        // code uses the canonical project root).
        RootFiles::pinRoot($rootDir);
        $databaseSource = 'config.php';

        // 1. .config.php — recursive overlay over the base tree. Loaded
        //    BEFORE .url and .sql.php so those targeted overrides still
        //    win when both files set the same field.
        $configRoot = RootFiles::resolveRootInfo('.config.php');
        $configFile = $configRoot['path'] . DIRECTORY_SEPARATOR . '.config.php';
        $configSource = self::sourceMeta($configFile, $configRoot['source']);
        if ($configSource['exists']) {
            $loaded = self::loadConfigOverlay($configFile);
            if ($loaded['ok']) {
                // v1.8.60 — the overlay is a leaf-merge, and `models` is a
                // list, so `models.catalog.max_rows` in `.config.php` would
                // REPLACE the expanded catalogue instead of merging beside
                // it. Lifting the settings subtree first keeps the spelling
                // the operator was told to use from eating the catalogue.
                $loaded['data'] = self::liftCatalogSettings($loaded['data']);
                $data = self::recursiveOverlay($data, $loaded['data']);
                $configSource['loaded'] = true;
                $configSource['sections'] = array_keys($loaded['data']);
                $diagnostics[] = '.config.php applied';
                if (isset($loaded['data']['database']) && is_array($loaded['data']['database'])) {
                    $databaseSource = '.config.php';
                }
            } else {
                $configSource['loaded'] = false;
                $configSource['error'] = $loaded['error'];
                $diagnostics[] = '.config.php invalid — ' . $loaded['error'];
            }
        }
        $sources['.config.php'] = $configSource;

        // 2. .url — public URL override. We intentionally do NOT trust
        // `is_readable()`: shared hosts (cPanel + suEXEC, some PHP-FPM
        // chroot setups) routinely return false from `is_readable()`
        // while `file_get_contents()` succeeds on the same path. The
        // RootFiles helper attempts the read directly and reports a
        // structured `read_error` when it fails, so a genuine
        // permission problem still produces actionable diagnostics
        // instead of being silently misreported.
        $urlInfo = RootFiles::readDotFile('.url');
        $urlSource = self::sourceMetaFromInfo($urlInfo);
        if ($urlInfo['exists']) {
            if ($urlInfo['read_ok']) {
                $url = $urlInfo['contents'];
                if ($url !== '') {
                    if (!isset($data['app']) || !is_array($data['app'])) {
                        $data['app'] = [];
                    }
                    $data['app']['url'] = $url;
                    $diagnostics[] = '.url applied';
                    $urlSource['loaded'] = true;
                } else {
                    $diagnostics[] = '.url present but empty — ignored';
                    $urlSource['loaded'] = false;
                    $urlSource['error'] = 'empty';
                }
            } else {
                $diagnostics[] = '.url present but unreadable — ignored';
                $urlSource['loaded'] = false;
                $urlSource['error'] = $urlInfo['read_error'] ?? 'unreadable';
            }
        }
        $sources['.url'] = $urlSource;

        // 3. .sql.php — database section override. The file is treated
        // as a TRUSTED root deployment artefact (it sits next to
        // config.php on the host filesystem and is never web-writable).
        // We accept three shapes:
        //   - return_file:        `<?php return [...];`  (canonical)
        //   - fragment_database:  `'database' => [...]`  (no <?php return)
        //   - fragment_fields:    `'dsn' => '...', ...` (no <?php return)
        // For the two fragment shapes we wrap the trusted content into
        // a synthetic `<?php return [{fragment}];` payload after a
        // strict pre-validation pass that rejects anything that would
        // smuggle executable code (PHP open tags, backticks,
        // function-call syntax). See loadSqlOverride() for the gate.
        $sqlRoot = RootFiles::resolveRootInfo('.sql.php');
        $sqlFile = $sqlRoot['path'] . DIRECTORY_SEPARATOR . '.sql.php';
        $sqlSource = self::sourceMeta($sqlFile, $sqlRoot['source']);
        if ($sqlSource['exists']) {
            $override = self::loadSqlOverride($sqlFile);
            $sqlSource['format'] = $override['format'];
            if ($override['ok']) {
                /** @var array<string,mixed> $db */
                $db = $override['data'];
                $data['database'] = $db;
                $databaseSource = '.sql.php';
                $diagnostics[] = '.sql.php applied (' . $override['format'] . ')';
                $sqlSource['loaded'] = true;
            } else {
                $diagnostics[] = '.sql.php invalid — base database config kept';
                if ($override['error'] !== '' && $override['error'] !== 'no usable dsn returned') {
                    $diagnostics[] = '.sql.php detail: ' . $override['error'];
                }
                $sqlSource['loaded'] = false;
                $sqlSource['error'] = $override['error'] !== '' ? $override['error'] : 'no usable dsn returned';
            }
        }
        $sources['.sql.php'] = $sqlSource;

        // 4. .admin.key — recorded only (never modifies $data; the auth
        //    layer reads it directly). Surfaced here so /api/health can
        //    answer "is the admin token file in the directory we expect"
        //    using the same resolver as everything else.
        $adminInfo = RootFiles::readDotFile('.admin.key');
        $adminSource = self::sourceMetaFromInfo($adminInfo);
        // never expose the token contents/length here — AdminAuth has
        // its own diagnostic that already does that safely.
        $adminSource['loaded'] = $adminInfo['read_ok']
            && (string)($adminInfo['contents'] ?? '') !== '';
        $sources['.admin.key'] = $adminSource;

        // Always ensure app.diagnostics exists.
        if (!isset($data['app']) || !is_array($data['app'])) {
            $data['app'] = [];
        }
        $appDiag = $data['app']['diagnostics'] ?? [];
        if (!is_array($appDiag)) {
            $appDiag = [];
        }
        if ($diagnostics !== []) {
            $appDiag['config_overrides'] = $diagnostics;
        }
        $appDiag['config_sources']  = $sources;
        $appDiag['database_source'] = $databaseSource;
        $appDiag['root']            = [
            'basename' => basename($rootDir),
            'source'   => $configRoot['source'] ?? 'unknown',
        ];
        $data['app']['diagnostics'] = $appDiag;

        return $data;
    }

    /**
     * Build a sources-diagnostic entry for a path that is not text
     * (executable PHP — `.config.php`, `.sql.php`). Captures
     * existence, size, mtime, source-strategy without including
     * file contents.
     *
     * @return array{path:string,source:string,exists:bool,size:int|null,mtime:int|null,loaded:bool|null,error:string|null,sections?:array<int,string>}
     */
    private static function sourceMeta(string $file, string $source): array
    {
        $exists = @is_file($file);
        $size = null;
        $mtime = null;
        if ($exists) {
            $stat = @stat($file);
            if (is_array($stat)) {
                if (isset($stat['size']) && is_int($stat['size'])) {
                    $size = $stat['size'];
                }
                if (isset($stat['mtime']) && is_int($stat['mtime'])) {
                    $mtime = $stat['mtime'];
                }
            }
        }
        return [
            'path'   => self::redactPath($file),
            'source' => $source,
            'exists' => $exists,
            'size'   => $size,
            'mtime'  => $mtime,
            'loaded' => null,
            'error'  => null,
        ];
    }

    /**
     * Build a sources-diagnostic entry from a RootFiles::readDotFile()
     * result so text dot-files share the same shape as PHP overlays.
     *
     * @param array<string,mixed> $info
     * @return array{path:string,source:string,exists:bool,size:int|null,mtime:int|null,loaded:bool|null,error:string|null,had_bom?:bool,read_ok?:bool}
     */
    private static function sourceMetaFromInfo(array $info): array
    {
        $path = (string)($info['path'] ?? '');
        $mtime = null;
        if (!empty($info['exists']) && $path !== '') {
            $stat = @stat($path);
            if (is_array($stat) && isset($stat['mtime']) && is_int($stat['mtime'])) {
                $mtime = $stat['mtime'];
            }
        }
        return [
            'path'    => self::redactPath($path),
            'source'  => (string)($info['source'] ?? 'unknown'),
            'exists'  => (bool)($info['exists'] ?? false),
            'size'    => $info['size'] ?? null,
            'mtime'   => $mtime,
            'read_ok' => (bool)($info['read_ok'] ?? false),
            'had_bom' => (bool)($info['had_bom'] ?? false),
            'loaded'  => null,
            'error'   => $info['read_error'] ?? null,
        ];
    }

    /**
     * Redact the absolute filesystem path so diagnostic output reveals
     * only the basename relative to the project root. The full
     * filesystem layout is never useful to a remote consumer and may
     * leak the host's directory naming.
     */
    private static function redactPath(string $absolute): string
    {
        if ($absolute === '') {
            return '';
        }
        $base = basename(dirname($absolute));
        return $base === '' ? basename($absolute) : $base . '/' . basename($absolute);
    }

    /**
     * Recursively overlay $overlay into $base. Associative arrays at
     * matching keys are merged depth-first. List (numeric-keyed)
     * arrays are treated as leaves and replace the corresponding base
     * value wholesale — this is the safe behavior for catalogues such
     * as `models` or `pipeline.default_selected_models` where a
     * deployment-supplied list is meant to be authoritative, not
     * appended.
     *
     * @param array<int|string,mixed> $base
     * @param array<int|string,mixed> $overlay
     * @return array<int|string,mixed>
     */
    private static function recursiveOverlay(array $base, array $overlay): array
    {
        foreach ($overlay as $k => $v) {
            if (is_array($v)
                && isset($base[$k])
                && is_array($base[$k])
                && self::isAssoc($base[$k])
                && self::isAssoc($v)
            ) {
                /** @var array<int|string,mixed> $baseChild */
                $baseChild = $base[$k];
                /** @var array<int|string,mixed> $vChild */
                $vChild = $v;
                $base[$k] = self::recursiveOverlay($baseChild, $vChild);
            } else {
                // List arrays, scalars and overlays where the base does
                // not have an associative array all replace the base
                // value entirely.
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /** @param array<int|string,mixed> $a */
    private static function isAssoc(array $a): bool
    {
        if ($a === []) {
            return false;
        }
        return array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * Safely include `.config.php` and validate it returns an array.
     * Returns a structured result so the caller can distinguish "file
     * not present" from "file invalid" and emit a useful diagnostic.
     *
     * @return array{ok:bool,data:array<string,mixed>,error:string}
     */
    private static function loadConfigOverlay(string $file): array
    {
        try {
            /** @var mixed $loaded */
            $loaded = (static function (string $f): mixed {
                return require $f;
            })($file);
        } catch (\Throwable $e) {
            return ['ok' => false, 'data' => [], 'error' => 'parse error: ' . $e->getMessage()];
        }
        if (!is_array($loaded)) {
            return ['ok' => false, 'data' => [], 'error' => 'must return array, got ' . gettype($loaded)];
        }
        return ['ok' => true, 'data' => $loaded, 'error' => ''];
    }

    /**
     * Safely load `.sql.php` and normalize its return value into the
     * canonical `database` array shape.
     *
     * Returns a structured result so the caller can record exactly
     * which shape was detected (`return_file`, `fragment_database`,
     * `fragment_fields`, `unknown`) and surface a precise error when
     * the contents could not be turned into a usable database
     * descriptor.
     *
     * Accepted shapes:
     *   1. return_file        — `<?php return [ 'database' => [...] ];`
     *                           or `<?php return [ /* db fields *\/ ];`
     *   2. fragment_database  — content is a bare PHP fragment whose
     *                           top level is `'database' => [...]`,
     *                           e.g. the literal user pasted into the
     *                           production `.sql.php`. No `<?php`,
     *                           no `return`. Wrapped into a synthetic
     *                           `<?php return [{fragment}];` payload.
     *   3. fragment_fields    — content is a bare PHP fragment whose
     *                           top level is `'dsn' => '...', ...`.
     *                           Wrapped the same way.
     *
     * Fragment evaluation is gated by isFragmentSafe(): the file must
     * not reopen PHP tags, contain backticks, or look like a function
     * call. `.sql.php` is a TRUSTED, root-only deployment artefact —
     * it lives next to `config.php` on the server filesystem and the
     * application never accepts web-supplied content here. Wrapping
     * trusted PHP fragments into a synthetic return statement and
     * eval-ing them is therefore acceptable; we still pre-validate so
     * a copy/paste accident or stray tag is rejected with a useful
     * diagnostic instead of producing executable code.
     *
     * @param  string $file Absolute path to `.sql.php`.
     * @return array{ok:bool,format:string,data:array<string,mixed>,error:string}
     */
    private static function loadSqlOverride(string $file): array
    {
        $bytes = @file_get_contents($file);
        if (!is_string($bytes)) {
            return ['ok' => false, 'format' => 'unknown', 'data' => [], 'error' => 'unreadable'];
        }
        // Strip BOM/whitespace before format detection so an editor's
        // "preserve encoding" save does not flip the shape decision.
        $clean = $bytes;
        if (str_starts_with($clean, "\xEF\xBB\xBF")) {
            $clean = substr($clean, 3);
        }
        $clean = ltrim($clean);
        $hasOpenTag = str_starts_with($clean, '<?php') || str_starts_with($clean, '<?=');

        if ($hasOpenTag) {
            // Canonical shape — `require` it as before.
            try {
                /** @var mixed $loaded */
                $loaded = (static function (string $f): mixed {
                    return require $f;
                })($file);
            } catch (\Throwable $e) {
                return ['ok' => false, 'format' => 'return_file', 'data' => [], 'error' => 'parse error: ' . $e->getMessage()];
            }
            if (!is_array($loaded)) {
                return ['ok' => false, 'format' => 'return_file', 'data' => [], 'error' => 'must return array, got ' . gettype($loaded)];
            }
            $normalized = self::normalizeDatabaseDescriptor($loaded);
            if ($normalized === null) {
                return ['ok' => false, 'format' => 'return_file', 'data' => [], 'error' => 'no usable dsn returned'];
            }
            return ['ok' => true, 'format' => 'return_file', 'data' => $normalized, 'error' => ''];
        }

        // Fragment path. Strip a stray trailing `;` so wrapping is
        // forgiving: pasting `'database' => [...],` or `...]` both work.
        $fragment = rtrim($clean, " \t\r\n;");
        if ($fragment === '') {
            return ['ok' => false, 'format' => 'unknown', 'data' => [], 'error' => 'empty fragment'];
        }
        if (!self::isFragmentSafe($fragment)) {
            return ['ok' => false, 'format' => 'unknown', 'data' => [], 'error' => 'fragment contains disallowed syntax'];
        }
        // Detect the shape from the fragment itself so we can label
        // diagnostics correctly even when the wrapped result is the
        // same canonical descriptor.
        $isDatabasePair = (bool)preg_match('/^\s*[\'"]database[\'"]\s*=>/u', $fragment);
        $format = $isDatabasePair ? 'fragment_database' : 'fragment_fields';
        $wrapped = '<?php return [' . $fragment . '];';
        try {
            /** @var mixed $loaded */
            $loaded = self::evalTrustedReturn($wrapped);
        } catch (\Throwable $e) {
            return ['ok' => false, 'format' => $format, 'data' => [], 'error' => 'fragment parse error: ' . $e->getMessage()];
        }
        if (!is_array($loaded)) {
            return ['ok' => false, 'format' => $format, 'data' => [], 'error' => 'fragment did not produce array'];
        }
        $normalized = self::normalizeDatabaseDescriptor($loaded);
        if ($normalized === null) {
            return ['ok' => false, 'format' => $format, 'data' => [], 'error' => 'no usable dsn returned'];
        }
        return ['ok' => true, 'format' => $format, 'data' => $normalized, 'error' => ''];
    }

    /**
     * Reject fragments that try to do anything other than declare an
     * associative-array literal. We veto any reopened PHP tags,
     * backticks (shell exec), and function-call-like patterns —
     * `name(` where `name` is not part of a `::CONST` expression.
     * PDO::ATTR_* constants are bare identifiers without trailing
     * parens, so they pass.
     */
    private static function isFragmentSafe(string $fragment): bool
    {
        if ($fragment === '') {
            return false;
        }
        if (str_contains($fragment, '<?') || str_contains($fragment, '?>')) {
            return false;
        }
        if (str_contains($fragment, '`')) {
            return false;
        }
        // Block name(  patterns. Class constants like PDO::ATTR_ERRMODE
        // and array literals are allowed; function calls are not.
        if (preg_match('/(^|[^\w:])([A-Za-z_]\w*)\s*\(/', $fragment) === 1) {
            return false;
        }
        return true;
    }

    /**
     * Eval a trusted, fully-controlled `<?php return [...];` payload.
     * Confined to root-only files (`.sql.php`/`.config.php`); never
     * accepts web input. Kept as a one-liner so future readers can see
     * exactly what is evaluated and how.
     *
     * @return mixed
     */
    private static function evalTrustedReturn(string $payload): mixed
    {
        // Strip the leading `<?php` so eval treats the body as code.
        $code = preg_replace('/^\s*<\?php\s*/', '', $payload, 1) ?? $payload;
        return eval($code);
    }

    /**
     * Normalize an array into the canonical `database` descriptor:
     *   - accept either `['database' => [...]]` or the fields directly,
     *   - require a non-empty `dsn`,
     *   - default `user`, `password`, and `options` when absent.
     *
     * @param  array<int|string,mixed> $loaded
     * @return array<string,mixed>|null
     */
    private static function normalizeDatabaseDescriptor(array $loaded): ?array
    {
        $candidate = (isset($loaded['database']) && is_array($loaded['database']))
            ? $loaded['database']
            : $loaded;
        $dsn = $candidate['dsn'] ?? null;
        if (!is_string($dsn) || trim($dsn) === '') {
            return null;
        }
        if (!array_key_exists('user', $candidate)) {
            $candidate['user'] = '';
        }
        if (!array_key_exists('password', $candidate)) {
            $candidate['password'] = '';
        }
        if (!isset($candidate['options']) || !is_array($candidate['options'])) {
            $candidate['options'] = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            // v1.8.26: ensure buffered result sets on MySQL so partially
            // iterated SELECTs cannot block subsequent statements.
            foreach (\App\Core\PdoCompat::mysqlConnectionOptions((string)($candidate['dsn'] ?? '')) as $attr => $value) {
                $candidate['options'][$attr] = $value;
            }
        } else {
            // Operator-supplied options stay authoritative — only inject the
            // buffered-query attribute when it has not been set, and only for a
            // MySQL DSN (the attribute is driver-specific and PDO rejects an
            // unknown one at connect time).
            $buffered = \App\Core\PdoCompat::mysqlBufferedQuery();
            if (\App\Core\PdoCompat::isMysqlDsn((string)($candidate['dsn'] ?? ''))
                && $buffered > 0
                && !array_key_exists($buffered, $candidate['options'])
            ) {
                $candidate['options'][$buffered] = true;
            }
        }
        return $candidate;
    }

    /**
     * Replace currently loaded config (test/utility only).
     * @param  array<string,mixed> $data
     * @return void
     */
    public static function set(array $data, string $rootDir = ''): void
    {
        self::$data    = $data;
        self::$rootDir = $rootDir !== '' ? rtrim($rootDir, DIRECTORY_SEPARATOR) : (self::$rootDir ?? __DIR__);
    }

    /**
     * Read a config value via dot-notation key.
     *
     * @param  string $key     Example: 'app.debug'
     * @param  mixed  $default Returned if the key is missing
     * @return mixed
     */
    /**
     * True once {@see self::load()} has populated the tree.
     *
     * Added in v2.0.0 because library classes that read optional settings
     * (App\I18n\LocaleNegotiator, App\Models\Fallback\FallbackPolicy) must
     * not fatal when they are constructed outside a booted Application — a CLI
     * tool, a unit test, or a worker that has not loaded config yet. Their
     * previous options were to catch a RuntimeException (which hides real
     * config errors) or to require a booted Application (which makes them
     * untestable in isolation). A predicate gives them a third option.
     */
    public static function isLoaded(): bool
    {
        return self::$data !== null;
    }

    /**
     * {@see self::get()} without the "not loaded" exception.
     *
     * For reads where "no configuration" and "this key is absent" mean the
     * same thing — an optional setting with a usable default. Deliberately a
     * separate method rather than a flag on get(): a caller that wants the
     * exception should keep getting it, and `get('x', null, true)` reads like a
     * default value rather than like "please do not throw".
     */
    public static function tryGet(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            return $default;
        }
        return self::get($key, $default);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            throw new \RuntimeException('Config not loaded; call Config::load() first.');
        }
        $parts = explode('.', $key);
        $cur   = self::$data;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return $default;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }

    /**
     * Resolve the API key for a given provider, applying the documented
     * priority:
     *   1. `connectors.{provider}.api_key` in config (when active=true)
     *   2. `.{provider}.key` at the project root
     *   3. Any aliases listed under `connectors.{provider}.key_files`
     *      (e.g. `.claude.key` for `anthropic`, `.gemini.key` for
     *      `gemini`, `.grok.key` for `xai`)
     *   4. If the optional `$model` argument is supplied, the matching
     *      `.{normalised-model}.key` file (e.g. `.gpt-5.5.key`,
     *      `.claude-sonnet-4.6.key`)
     *   5. `{PROVIDER}_API_KEY` env var (also tolerates `$_ENV`)
     *
     * @param  string      $provider Provider slug (e.g. 'bothub', 'openai')
     * @param  string|null $model    Optional model id used to derive an
     *                               additional `.{model}.key` lookup. The
     *                               vendor prefix and any non-filename
     *                               characters are stripped to keep the
     *                               filename predictable.
     * @return string Non-empty key
     * @throws ConnectorKeyNotFoundException When no key resolved.
     */
    public static function getConnectorKey(string $provider, ?string $model = null): string
    {
        $provider = strtolower(trim($provider));
        $cfg      = self::get("connectors.{$provider}", []);
        if (is_array($cfg) && ($cfg['active'] ?? false) === true) {
            $k = (string)($cfg['api_key'] ?? '');
            if ($k !== '') {
                return $k;
            }
        }
        // Canonical `.{provider}.key` dot-file lookup. Uses RootFiles so
        // a `public_html/` deployment finds the key file next to
        // config.php/index.php even when the canonical src-relative root
        // is a parent of DOCUMENT_ROOT. Read errors are tolerated
        // silently — later fallbacks still have a chance to satisfy.
        $candidates = ['.' . $provider . '.key'];
        // Operator-supplied aliases (`connectors.{p}.key_files`).
        if (is_array($cfg) && isset($cfg['key_files']) && is_array($cfg['key_files'])) {
            foreach ($cfg['key_files'] as $alias) {
                if (is_string($alias) && $alias !== '' && !in_array($alias, $candidates, true)) {
                    $candidates[] = $alias;
                }
            }
        }
        // Per-model file `.{normalised-model}.key`. The slug is sanitised
        // so vendor prefixes and disallowed filename characters never
        // surprise the operator (e.g. `openai/gpt-5.5` → `.gpt-5.5.key`).
        if (is_string($model) && $model !== '') {
            $modelFile = self::modelKeyFileName($model);
            if ($modelFile !== '' && !in_array($modelFile, $candidates, true)) {
                $candidates[] = $modelFile;
            }
        }
        foreach ($candidates as $candidate) {
            $info = RootFiles::readDotFile($candidate);
            if ($info['exists'] && $info['read_ok']) {
                $k = $info['contents'];
                if ($k !== '') {
                    return $k;
                }
            }
        }
        $envKey = strtoupper($provider) . '_API_KEY';
        if (!empty($_ENV[$envKey])) {
            return (string)$_ENV[$envKey];
        }
        $envFromGetenv = getenv($envKey);
        if (is_string($envFromGetenv) && $envFromGetenv !== '') {
            return $envFromGetenv;
        }
        throw new ConnectorKeyNotFoundException(
            "API key for provider '{$provider}' not found in config, .key file, or environment."
        );
    }

    /**
     * Normalise a model id into a safe `.{name}.key` filename. Drops
     * the vendor prefix, collapses non-filename characters into dashes,
     * and lowercases everything. Returns '' when the input has no
     * usable characters.
     */
    private static function modelKeyFileName(string $model): string
    {
        $slug = strtolower(trim($model));
        if (str_contains($slug, '/')) {
            $slug = substr($slug, strrpos($slug, '/') + 1);
        }
        $slug = ltrim($slug, '~');
        // Allow letters, digits, dot, dash, underscore. Everything else
        // collapses to a single dash so the resulting filename remains
        // predictable across shells.
        $slug = preg_replace('/[^a-z0-9._-]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-.');
        return $slug === '' ? '' : '.' . $slug . '.key';
    }

    /**
     * @return array<int,array{provider:string,model:string,active:bool}>
     */
    public static function getActiveModels(): array
    {
        $models = self::get('models', []);
        $out    = [];
        if (is_array($models)) {
            foreach ($models as $m) {
                if (is_array($m) && ($m['active'] ?? false) === true) {
                    $out[] = [
                        'provider' => (string)($m['provider'] ?? ''),
                        'model'    => (string)($m['model'] ?? ''),
                        'active'   => true,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * @return bool True if app.debug is truthy in config.
     */
    public static function isDebug(): bool
    {
        return (bool)self::get('app.debug', false);
    }

    /**
     * v1.8.22: whether the frontend should emit structured Chrome
     * console logs (api fetch start/end/error, sse open/event/error,
     * pipeline event facts, control actions). Reads `app.log_to_console`
     * with `LogToConsole` as a top-level alias for operator convenience
     * (mirrors the SettingsTab/LogsTab pattern). Default TRUE so a
     * fresh install is debuggable out of the box. Operators flip it to
     * false in `.config.php` to silence the console on production hosts.
     */
    public static function isLogToConsoleEnabled(): bool
    {
        $top = self::get('LogToConsole', null);
        if ($top !== null) {
            return $top === true;
        }
        $nested = self::get('app.log_to_console', null);
        if ($nested !== null) {
            return $nested === true;
        }
        return true;
    }

    /**
     * Resolve a UI tab visibility flag with documented precedence:
     *   1. Top-level `<Name>` (e.g. `SettingsTab`) — operator-friendly form
     *   2. `app.<Name>` (nested form)
     *   3. $default — preserves prior behavior when neither is set
     *
     * Comparison is strict (=== true) so accidental truthy values like
     * the string "false" do not enable a tab. The flag MUST be the PHP
     * boolean true to count as enabled.
     *
     * @param  string $name    Flag name, e.g. 'SettingsTab' or 'LogsTab'
     * @param  bool   $default Default when no flag is set anywhere
     * @return bool
     */
    public static function tabFlag(string $name, bool $default): bool
    {
        $top = self::get($name, null);
        if ($top !== null) {
            return $top === true;
        }
        $nested = self::get('app.' . $name, null);
        if ($nested !== null) {
            return $nested === true;
        }
        return $default;
    }

    /**
     * @return bool True when the Models tab is enabled in config.
     *              Default is TRUE — it is the operator surface for
     *              provider keys, the model catalogue and the consensus
     *              presets, and its body is still gated by the admin
     *              token (shared lock screen) exactly like Settings.
     */
    public static function isModelsTabEnabled(): bool
    {
        return self::tabFlag('ModelsTab', true);
    }

    /** @return bool True when the Settings tab is enabled in config. */
    public static function isSettingsTabEnabled(): bool
    {
        return self::tabFlag('SettingsTab', true);
    }

    /** @return bool True when the Logs tab is enabled in config. */
    public static function isLogsTabEnabled(): bool
    {
        return self::tabFlag('LogsTab', false);
    }

    /**
     * @return bool True when the Security tab is enabled in config.
     *              Default is FALSE — the IP firewall is opt-in because
     *              an over-eager whitelist can lock the operator out.
     */
    public static function isSecurityTabEnabled(): bool
    {
        return self::tabFlag('SecurityTab', false);
    }

    /**
     * @return bool True when the API tab is enabled in config.
     *              Default is TRUE - managing the external REST API
     *              keys is part of the operator surface; the tab body
     *              is still gated by the admin token.
     */
    public static function isApiTabEnabled(): bool
    {
        return self::tabFlag('ApiTab', true);
    }

    /**
     * @return bool True when the Extensions tab is enabled in config.php.
     *              Default is TRUE - installing/updating the MD.Viewer
     *              extension is part of the operator surface and the whole
     *              tab body is gated by the admin token exactly like
     *              Settings / Logs. Set ExtensionsTab=false to hide the tab
     *              and disable the matching API endpoints (404).
     */
    public static function isExtensionsTabEnabled(): bool
    {
        return self::tabFlag('ExtensionsTab', true);
    }

    /**
     * @return bool True when the History tab is enabled in config.
     *              Default is FALSE — the tab surfaces past prompts and
     *              model outputs in plaintext, so it is opt-in and
     *              additionally gated by the admin token.
     */
    public static function isHistoryTabEnabled(): bool
    {
        return self::tabFlag('HistoryTab', false);
    }

    /**
     * @return bool True when the Statistic tab is enabled in config.
     *              Default is FALSE — the tab surfaces operational and
     *              user-usage metrics (prompt/completion counts, costs,
     *              per-LLM breakdowns) so it is opt-in and additionally
     *              gated by the admin token. Persistent statistics
     *              require a configured database.
     */
    public static function isStatisticTabEnabled(): bool
    {
        return self::tabFlag('StatisticTab', false);
    }

    /**
     * v1.8.37 — Resolve the pipeline execution mode the main UI should use
     * for new runs. Defaults to 'job' (durable worker dispatch via
     * POST /api/council/jobs + replay SSE). Operators flip back to
     * 'legacy' temporarily by setting `pipeline.execution_mode = 'legacy'`
     * in `.config.php` or `runtime.json`. Unknown values fall back to
     * 'job' so a typo never silently downgrades the UI to the legacy
     * web-request stream. Reads the same merged tree as get(), so every
     * override layer (.config.php, runtime.json) applies automatically.
     *
     * @return string Either 'job' or 'legacy'.
     */
    public static function pipelineExecutionMode(): string
    {
        $raw   = self::get('pipeline.execution_mode', 'job');
        $value = is_string($raw) ? strtolower(trim($raw)) : '';
        return $value === 'legacy' ? 'legacy' : 'job';
    }

    /**
     * v1.8.37 — UI hint timeout (seconds) before warning that the worker
     * may not be running after a job is enqueued. Clamped to a sane range
     * so a hostile `.config.php` cannot stall the UI forever (a huge value
     * would hide a dead worker) or flash the warning before the worker can
     * possibly answer. A non-numeric value falls back to the shipped
     * default of 15.
     *
     * @return int Seconds, always within [3, 120].
     */
    public static function jobWorkerWarningSeconds(): int
    {
        $raw   = self::get('pipeline.job_worker_warning_seconds', 15);
        $value = is_numeric($raw) ? (int)$raw : 15;
        if ($value < 3)   { $value = 3; }
        if ($value > 120) { $value = 120; }
        return $value;
    }

    /**
     * Compatibility alias for jobWorkerWarningSeconds().
     *
     * The restoration task named this accessor
     * `pipelineJobWorkerWarningSeconds()`, but every caller in the codebase
     * — the front controller's `/api/boot-config` block, which is the only
     * consumer of `pipeline.job_worker_warning_seconds` — and the v1.8.37
     * test suite call `jobWorkerWarningSeconds()`. Both names resolve to
     * the same clamped value; the un-prefixed name is the canonical one and
     * new code should use it.
     *
     * @return int Seconds, always within [3, 120].
     */
    public static function pipelineJobWorkerWarningSeconds(): int
    {
        return self::jobWorkerWarningSeconds();
    }

    /**
     * v1.8.39 — Resolved stale-job auto-recovery policy.
     *
     * Reads `pipeline.jobs.recovery.*` and clamps every leaf to a sane
     * range so a hostile `.config.php` cannot make the worker loop thrash
     * (e.g. retries every 0 seconds) or never retry at all (`max_attempts`
     * negative). The returned shape is always fully populated and always of
     * the documented type, so callers never have to re-validate a leaf:
     * bin/revoap-worker.php drives the sweep with it,
     * JobService::resolveMaxAttempts() stamps new rows from it, and
     * WorkerHealthService::resolveRetryPolicy() surfaces it to the
     * Settings UI.
     *
     * Clamps applied (defaults per docs/WorkerArchitecture.md):
     *   auto_recovery_enabled  bool   (default true)
     *   max_attempts           [1, 20]              (default 3)
     *   stale_after_seconds    [30, 86400]          (default 300)
     *   backoff_max_seconds    [1, 86400]           (default 900)
     *   backoff_base_seconds   [1, backoff_max]     (default 30)
     *   backoff_seconds        each entry [0, backoff_max], non-numeric
     *                          entries dropped, empty → [backoff_base]
     *                                       (default [30, 120, 300])
     *
     * `stale_after_seconds` is deliberately NOT routed through
     * WorkerHealthService::clampThreshold(): that helper clamps a query
     * string override for a single diagnostic snapshot, while this method
     * normalises the persistent policy the recovery sweep runs with. Both
     * use the [30, 86400] range documented for `stale_threshold_sec`, so a
     * threshold read from either side means the same thing.
     *
     * @return array{
     *     auto_recovery_enabled:bool,
     *     max_attempts:int,
     *     stale_after_seconds:int,
     *     backoff_seconds:list<int>,
     *     backoff_base_seconds:int,
     *     backoff_max_seconds:int
     * }
     */
    public static function jobsRecoveryPolicy(): array
    {
        $enabled = self::boolLeaf('pipeline.jobs.recovery.auto_recovery_enabled', true);

        $maxAttempts = self::clampInt(
            self::get('pipeline.jobs.recovery.max_attempts', 3), 3, 1, 20
        );
        $staleAfter = self::clampInt(
            self::get('pipeline.jobs.recovery.stale_after_seconds', 300), 300, 30, 86400
        );
        $backoffMax = self::clampInt(
            self::get('pipeline.jobs.recovery.backoff_max_seconds', 900), 900, 1, 86400
        );
        // The base is clamped against the (already clamped) ceiling so a
        // typo cannot describe a ramp that shrinks below its own floor.
        $backoffBase = self::clampInt(
            self::get('pipeline.jobs.recovery.backoff_base_seconds', 30), 30, 1, $backoffMax
        );

        $backoffRaw = self::get('pipeline.jobs.recovery.backoff_seconds', [30, 120, 300]);
        $backoff    = [];
        if (is_array($backoffRaw)) {
            foreach ($backoffRaw as $entry) {
                if (!is_numeric($entry)) { continue; }
                $delay = (int)$entry;
                if ($delay < 0)            { $delay = 0; }
                if ($delay > $backoffMax)  { $delay = $backoffMax; }
                $backoff[] = $delay;
            }
        }
        if ($backoff === []) {
            // An explicitly empty/garbage schedule still has to produce a
            // usable ramp — recoverStale() walks it with an index, so an
            // empty list would mean "retry immediately, forever".
            $backoff = [$backoffBase];
        }

        return [
            'auto_recovery_enabled' => $enabled,
            'max_attempts'          => $maxAttempts,
            'stale_after_seconds'   => $staleAfter,
            'backoff_seconds'       => $backoff,
            'backoff_base_seconds'  => $backoffBase,
            'backoff_max_seconds'   => $backoffMax,
        ];
    }

    /**
     * Clamp helper used by jobsRecoveryPolicy(). A non-numeric value
     * (including an array or object, which is_numeric() never warns about)
     * falls back to $default; everything numeric is clamped into
     * [$min, $max].
     */
    private static function clampInt(mixed $raw, int $default, int $min, int $max): int
    {
        $value = is_numeric($raw) ? (int)$raw : $default;
        if ($value < $min) { $value = $min; }
        if ($value > $max) { $value = $max; }
        return $value;
    }

    /**
     * Read a boolean leaf of the recovery policy. A real bool always wins,
     * and the documented string/number spellings follow PHP's own
     * truthiness for them ('0', 'false', 'no', 'off' and '' are false).
     * An array or object falls back to $default instead of being coerced:
     * a naive `(string)` cast would emit an "Array to string conversion"
     * warning (and throw for an object), and a hostile `.config.php` must
     * not be able to switch auto-recovery on with a value a human reads as
     * "off".
     */
    private static function boolLeaf(string $key, bool $default): bool
    {
        $value = self::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off', ''], true);
        }
        if ($value === null) {
            return false;
        }
        return $default;
    }

    /**
     * @return string Project root previously passed to load().
     */
    public static function rootDir(): string
    {
        return self::$rootDir ?? '';
    }

    /**
     * Test/utility: clear loaded state.
     * @return void
     */
    public static function reset(): void
    {
        self::$data    = null;
        self::$rootDir = null;
    }
}
