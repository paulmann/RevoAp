<?php
/**
 * SystemHealthService: gathers the facts the R7 health screen renders.
 *
 * Strictly read-only. It composes the services that already own each fact -
 * {@see WorkerHealthService} (worker code state, database resilience),
 * {@see ConfigRepository} + {@see MigrationManager} + {@see VersionParser}
 * (schema and migrations), {@see StorageProbe} (writability), the persisted
 * catalogue and {@see LinkedModels} / {@see DomainPresetRepository} (the
 * pool invariant) - instead of re-deriving any of them. The verdicts are
 * taken by the pure {@see \App\Diagnostics\SystemHealth}; this class only
 * answers "what is true right now".
 *
 * Two rules it must never break:
 *
 *   1. **No DDL, no writes.** `ModelsSnapshotStore::available()` and
 *      `ConfigRepository::ensureTable()` create tables, so they are not
 *      used here: the snapshot is read through `ConfigRepository::get()`
 *      (which reads `app_config` only when it already exists). A health
 *      probe that migrates the database it is inspecting is not a probe.
 *   2. **No fact may take the endpoint down.** Every gatherer catches its
 *      own failure and reports the absence as a fact, so the screen always
 *      renders and always says what it could not see.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-24
 */
declare(strict_types=1);

namespace App\Settings;

use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\Database;
use App\Core\StorageProbe;
use App\Diagnostics\SystemHealth;
use App\DomainPresets\DomainPresetRepository;
use App\Jobs\WorkerHealthService;
use App\Models\LinkedModels;
use App\Models\ModelCatalogDiff;
use App\Models\ModelsSnapshotStore;
use PDO;
use Throwable;

final class SystemHealthService
{
    /**
     * Directories the application writes to, with the file that proves it.
     * The labels are what the operator reads; the paths are root-relative.
     *
     * @var array<int,array{label:string,target:string,probe:string}>
     */
    private const WRITABLE_DIRS = [
        ['label' => 'Runtime overrides', 'target' => 'var/config', 'probe' => 'var/config/runtime.json'],
        ['label' => 'Runtime journal',   'target' => 'runtime',    'probe' => 'runtime/db-resilience.json'],
        ['label' => 'Response cache',    'target' => 'cache',      'probe' => 'cache/.health-probe'],
        ['label' => 'Logs',              'target' => 'logs',       'probe' => 'logs/revoap-debug.log'],
    ];

    public function __construct(
        private readonly string $rootDir,
        private readonly WorkerHealthService $workerHealth,
        private readonly ?PDO $pdo = null,
        private readonly ?Database $db = null,
        private readonly ?DomainPresetRepository $presets = null,
        private readonly ?ConfigRepository $config = null,
    ) {}

    /**
     * The complete envelope `GET /api/health/system` returns.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        try {
            $worker  = $this->workerFacts();
            $schema  = $this->schemaFacts();
            $dirs    = $this->dirFacts();
            $catalog = $this->catalogFacts();
            $pool    = $this->poolFacts($catalog['active_keys']);

            $facts = [
                'worker'  => $worker,
                'schema'  => $schema,
                'dirs'    => $dirs,
                'catalog' => $catalog['facts'],
                'pool'    => $pool,
                'setup'   => $this->setupFacts($worker, $schema, $dirs, $catalog['facts']),
            ];

            $checks = SystemHealth::checks($facts);

            return [
                'ok'               => true,
                'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
                'overall'          => SystemHealth::overall($checks),
                'summary'          => SystemHealth::summary($checks),
                'checks'           => $checks,
                'setup_checklist'  => SystemHealth::setupChecklist($facts),
                'commands'         => WorkerHealthService::commands(),
            ];
        } catch (Throwable $e) {
            // A probe that cannot probe must still answer - with the truth.
            return [
                'ok'           => false,
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'overall'      => SystemHealth::UNKNOWN,
                'summary'      => ['checks' => 0, 'ok' => 0, 'warn' => 0, 'error' => 0, 'unknown' => 0],
                'checks'       => [],
                'setup_checklist' => [],
                'commands'     => WorkerHealthService::commands(),
                'error'        => 'health_probe_failed',
                'message'      => 'The health probe itself failed: '
                    . \App\Core\Logger::sanitizeScalar($e->getMessage()),
            ];
        }
    }

    // ---------------------------------------------------------------------
    // Facts
    // ---------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function workerFacts(): array
    {
        $fallback = [
            'code_state'              => SystemHealth::UNKNOWN,
            'code_stale_reason'       => null,
            'code_newest_at'          => null,
            'code_newest_file'        => null,
            'app_version'             => '',
            'worker_status'           => 'unknown',
            'workers_alive'           => 0,
            'presence_table_available' => false,
            'jobs_table_available'    => false,
            'db_connected'            => false,
            'db_reconnects'           => 0,
            'db_losses'               => 0,
            'last_db_error_at'        => null,
            'last_db_error'           => null,
        ];

        try {
            $snap = $this->workerHealth->snapshot();
        } catch (Throwable) {
            return $fallback;
        }

        return [
            'code_state'              => (string)($snap['code_state'] ?? SystemHealth::UNKNOWN),
            'code_stale_reason'       => $snap['code_stale_reason'] ?? null,
            'code_newest_at'          => $snap['code_newest_at'] ?? null,
            'code_newest_file'        => $snap['code_newest_file'] ?? null,
            'app_version'             => (string)($snap['app_version'] ?? ''),
            'worker_status'           => (string)($snap['worker_status'] ?? 'unknown'),
            'workers_alive'           => (int)($snap['workers_alive'] ?? 0),
            'presence_table_available' => ($snap['presence_table_available'] ?? null) === true,
            'jobs_table_available'    => ($snap['jobs_table_available'] ?? null) === true,
            'db_connected'            => ($snap['db_connected'] ?? null) === true,
            'db_reconnects'           => (int)($snap['db_reconnects'] ?? 0),
            'db_losses'               => (int)($snap['db_losses'] ?? 0),
            'last_db_error_at'        => $snap['last_db_error_at'] ?? null,
            'last_db_error'           => $snap['last_db_error'] ?? null,
        ];
    }

    /**
     * Schema + migration facts, assembled exactly the way
     * {@see \App\Http\Controllers\SettingsController::versionStatus()} does
     * it, so the panel and the migration banner can never disagree.
     *
     * @return array<string,mixed>
     */
    private function schemaFacts(): array
    {
        $out = [
            'database_ready'          => false,
            'config_table_present'    => false,
            'expected_schema_version' => null,
            'live_schema_version'     => null,
            'applied_migrations'      => 0,
            'total_migrations'        => 0,
            'pending_migrations'      => 0,
            'pending_names'           => [],
        ];

        $schemaFile = $this->rootDir . '/db/schema.sql';
        try {
            $tables   = SchemaParser::parseFile($schemaFile);
            $expected = VersionParser::expectedFromSchema($schemaFile, $tables);
            $out['expected_schema_version'] = $expected['schema'] ?? null;
        } catch (Throwable) {
            // A missing schema.sql is reported as "unknown", not as a fault
            // of the database.
        }

        if ($this->pdo === null) {
            return $out;
        }
        $out['database_ready'] = true;

        try {
            $out['config_table_present'] = $this->config?->tableExists() ?? false;
            $out['live_schema_version']  = $this->config?->get(ConfigRepository::SCHEMA_KEY);
        } catch (Throwable) {
            $out['config_table_present'] = false;
        }

        try {
            $rows = (new MigrationManager($this->pdo, $this->rootDir . '/db/migrations'))->list();
            $pending = [];
            $applied = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (($row['applied'] ?? false) === true) {
                    $applied++;
                    continue;
                }
                $name = (string)($row['name'] ?? '');
                if ($name !== '') {
                    $pending[] = $name;
                }
            }
            $out['applied_migrations'] = $applied;
            $out['total_migrations']   = count($rows);
            $out['pending_migrations'] = count($pending);
            $out['pending_names']      = $pending;
        } catch (Throwable) {
            // Migration facts stay at zero; the check says what it could see.
        }

        return $out;
    }

    /**
     * One entry per writable directory. The probe file is only stat-ed, so a
     * missing file is a normal answer ("the directory is what matters").
     *
     * @return list<array<string,mixed>>
     */
    private function dirFacts(): array
    {
        $dirs = [];
        foreach (self::WRITABLE_DIRS as $spec) {
            $absolute = $this->rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $spec['probe']);
            try {
                $probe = StorageProbe::describe($absolute);
            } catch (Throwable) {
                $probe = [];
            }
            $dirs[] = [
                'label'        => $spec['label'],
                'target'       => $spec['target'],
                'exists'       => ($probe['dir_exists'] ?? null) === true,
                'writable'     => ($probe['dir_writable'] ?? null) === true,
                'perms'        => $probe['dir_perms'] ?? null,
                'owner'        => $probe['dir_owner'] ?? null,
                'process_user' => $probe['process_user'] ?? null,
                'disk_free'    => $probe['disk_free'] ?? null,
            ];
        }

        // `logging.path` is configurable and may point outside the project
        // root; when it is not the shipped `logs/` the entry says so instead
        // of reporting a directory nobody writes to.
        try {
            $configured = (string)Config::get('logging.path', '');
            if ($configured !== '') {
                $expected = $this->rootDir . DIRECTORY_SEPARATOR . 'logs';
                if (rtrim(str_replace('\\', '/', $configured), '/') !== rtrim(str_replace('\\', '/', $expected), '/')) {
                    foreach ($dirs as $i => $dir) {
                        if ($dir['target'] === 'logs') {
                            $dirs[$i]['target'] = 'logs (configured: ' . $configured . ')';
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Config not loaded: the shipped default is what the probe used.
        }

        return $dirs;
    }

    /**
     * Catalogue facts plus the active key set the pool invariant needs.
     *
     * @return array{facts:array<string,mixed>,active_keys:list<string>}
     */
    private function catalogFacts(): array
    {
        $rows = [];
        try {
            $configured = Config::get('models', []);
            $rows = is_array($configured) ? $configured : [];
        } catch (Throwable) {
            $rows = [];
        }

        $activeKeys = [];
        $activeRows = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['active'] ?? false) !== true) {
                continue;
            }
            $activeRows++;
            $key = ModelCatalogDiff::key((string)($row['provider'] ?? ''), (string)($row['model'] ?? ''));
            if ($key !== '') {
                $activeKeys[$key] = true;
            }
        }

        $cap = 0;
        try {
            $cap = Config::catalogMaxRows();
        } catch (Throwable) {
            $cap = 0;
        }

        $staleAfterDays = SystemHealth::DEFAULT_CATALOG_STALE_DAYS;
        try {
            $configured = Config::get('models_catalog.stale_after_days', null);
            if ($configured !== null && is_numeric($configured) && (int)$configured > 0) {
                $staleAfterDays = (int)$configured;
            }
        } catch (Throwable) {
            // Keep the class default.
        }

        $takenAt = null;
        $ageS    = null;
        try {
            $snapshot = $this->config !== null ? (new ModelsSnapshotStore($this->config))->loadSnapshot() : null;
            if (is_array($snapshot) && is_string($snapshot['taken_at'] ?? null) && $snapshot['taken_at'] !== '') {
                $takenAt = (string)$snapshot['taken_at'];
                $stamp   = strtotime($takenAt);
                if ($stamp !== false) {
                    // Never a negative age: a clock skew must not turn into
                    // "the catalogue is younger than zero days".
                    $ageS = max(0, time() - $stamp);
                }
            }
        } catch (Throwable) {
            // No snapshot is a fact of its own; the check words it.
        }

        return [
            'facts' => [
                'active_rows'       => $activeRows,
                'total_rows'        => count($rows),
                'max_rows'          => $cap,
                'snapshot_taken_at' => $takenAt,
                'snapshot_age_s'    => $ageS,
                'stale_after_days'  => $staleAfterDays,
            ],
            'active_keys' => array_keys($activeKeys),
        ];
    }

    /**
     * The catalogue invariant: every row the pool, the domain presets and
     * the three linked roles name has to exist among the *active* rows of
     * the persisted catalogue - that list is what L1 can dispatch to.
     *
     * @param  list<string> $activeKeys
     * @return array<string,mixed>
     */
    private function poolFacts(array $activeKeys): array
    {
        $active = [];
        foreach ($activeKeys as $key) {
            $active[(string)$key] = true;
        }

        $referenced = [];
        $missing    = [];

        $consider = static function (string $provider, string $model, string $source) use (&$referenced, &$missing, $active): void {
            // An unconfigured role ("") is not a dangling reference: skip it
            // before the key is built, because ModelCatalogDiff::key() maps
            // two empty strings to the literal "|".
            $provider = strtolower(trim($provider));
            $model    = trim($model);
            if ($provider === '' || $model === '') {
                return;
            }
            $key = ModelCatalogDiff::key($provider, $model);
            if ($key === '' || isset($referenced[$key])) {
                return;
            }
            $referenced[$key] = ['key' => $key, 'provider' => strtolower(trim($provider)), 'model' => trim($model), 'source' => $source];
            if (!isset($active[$key])) {
                $missing[] = $referenced[$key];
            }
        };

        // 1. The pool.
        try {
            $pool = Config::get('pipeline.default_selected_models', []);
            foreach (is_array($pool) ? $pool : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $consider((string)($row['provider'] ?? ''), (string)($row['model'] ?? ''), 'pool');
            }
        } catch (Throwable) {
            // Config not loaded: the pool simply contributes nothing.
        }

        // 2. The domain presets (their selected models and their arbiter).
        try {
            foreach ($this->presets?->all() ?? [] as $preset) {
                if (!is_object($preset) || !method_exists($preset, 'selectedModels')) {
                    continue;
                }
                $slug = (string)($preset->slug ?? '?');
                foreach ($preset->selectedModels() as $row) {
                    if (is_array($row)) {
                        $consider((string)($row['provider'] ?? ''), (string)($row['model'] ?? ''), 'preset:' . $slug);
                    }
                }
                $arbiter = $preset->arbiter ?? null;
                if (is_array($arbiter)) {
                    $consider(
                        (string)($arbiter['provider'] ?? ''),
                        (string)($arbiter['model'] ?? ''),
                        'preset:' . $slug . ' (arbiter)',
                    );
                }
            }
        } catch (Throwable) {
            // A preset table mid-migration contributes nothing.
        }

        // 3. The three linked roles.
        try {
            foreach (LinkedModels::current() as $role => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $consider((string)($row['provider'] ?? ''), (string)($row['model'] ?? ''), 'role:' . (string)$role);
            }
        } catch (Throwable) {
            // Roles unreadable: reported as "not referenced".
        }

        return [
            'referenced'  => array_values($referenced),
            'missing'     => $missing,
            'active_keys' => array_keys($active),
        ];
    }

    /**
     * The binary facts the first-run checklist needs.
     *
     * @param  array<string,mixed> $worker
     * @param  array<string,mixed> $schema
     * @param  list<array<string,mixed>> $dirs
     * @param  array<string,mixed> $catalog
     * @return array<string,mixed>
     */
    private function setupFacts(array $worker, array $schema, array $dirs, array $catalog): array
    {
        $adminConfigured = false;
        try {
            $adminConfigured = AdminAuth::isConfigured();
        } catch (Throwable) {
            $adminConfigured = false;
        }

        $dsnConfigured = false;
        try {
            $dsnConfigured = trim((string)Config::get('database.dsn', '')) !== '';
        } catch (Throwable) {
            $dsnConfigured = false;
        }

        $providerKeys = [];
        foreach (['openrouter', 'bothub'] as $provider) {
            $configured = false;
            try {
                $configured = trim(Config::getConnectorKey($provider)) !== '';
            } catch (Throwable) {
                $configured = false;
            }
            $providerKeys[] = ['provider' => $provider, 'configured' => $configured];
        }

        $dirsOk = $dirs !== [];
        foreach ($dirs as $dir) {
            if (!is_array($dir) || ($dir['exists'] ?? null) !== true || ($dir['writable'] ?? null) !== true) {
                $dirsOk = false;
                break;
            }
        }

        // `idle` alone does NOT prove a worker exists: WorkerHealthService::
        // classify() returns it both for "alive and polling" and for "never
        // started, nothing queued". Only a live presence row counts, which is
        // what `workers_alive` counts.
        $workerStatus = (string)($worker['worker_status'] ?? '');
        $workerRunning = ((int)($worker['workers_alive'] ?? 0)) > 0
            || $workerStatus === 'healthy';

        return [
            'admin_configured'    => $adminConfigured,
            'database_configured' => $dsnConfigured,
            'database_connected'  => ($worker['db_connected'] ?? null) === true,
            'schema_ready'        => ($schema['config_table_present'] ?? null) === true
                && (int)($schema['pending_migrations'] ?? 0) === 0,
            'provider_keys'       => $providerKeys,
            'catalog_ready'       => (int)($catalog['active_rows'] ?? 0) > 0,
            'worker_running'      => $workerRunning,
            'dirs_ok'             => $dirsOk,
        ];
    }
}
