<?php
/**
 * SystemHealth: R7 - the system health panel's verdict core.
 *
 * One screen that answers the class of failures which has cost the most
 * time in this project: the worker is running old code, a migration is not
 * applied, file permissions are wrong, the catalogue is stale, the pool
 * drifted away from the catalogue, the database connection was lost. Each
 * of those used to be discovered by hand.
 *
 * Pure, exactly like {@see RunDiagnosis}: it takes the facts that were
 * already gathered and returns the checks an operator reads. Nothing here
 * queries, walks the filesystem or reads config, so the wording - which is
 * the whole product - can be unit-tested with canned facts.
 *
 * Three rules shape it:
 *
 *   1. **A check without an action is not a check.** Every non-ok check
 *      carries at least one action, and the action carries the label of the
 *      button the operator presses.
 *   2. **Nothing that lives outside the application repairs itself.** File
 *      ownership and service restarts are reported as `command` actions
 *      with `external = true`: the panel shows the exact command and says
 *      that it will not run it. The panel never requires root, never spawns
 *      a shell and never takes data from the request.
 *   3. **Missing facts are never "ok".** When a fact is unavailable the
 *      check says `unknown` and explains what could not be compared, rather
 *      than reporting a health it cannot see.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-24
 */
declare(strict_types=1);

namespace App\Diagnostics;

final class SystemHealth
{
    /** Healthy. */
    public const OK = 'ok';

    /** Works, but something is drifting (a stale catalogue, a capped one, a reconnect). */
    public const WARN = 'warn';

    /** Broken now: the pipeline cannot do its job until this is fixed. */
    public const ERROR = 'error';

    /** Not enough facts to judge - said out loud rather than guessed. */
    public const UNKNOWN = 'unknown';

    /** How old the persisted catalogue may be before the panel warns (days). */
    public const DEFAULT_CATALOG_STALE_DAYS = 30;

    /** Rank used to fold six checks into one verdict. */
    private const SEVERITY_RANK = [
        self::ERROR   => 3,
        self::WARN    => 2,
        self::UNKNOWN => 1,
        self::OK      => 0,
    ];

    /**
     * The checks, in the order the panel renders them. Kept as one list so a
     * consumer (and a test) can assert the screen's inventory without
     * re-deriving it from a render function.
     *
     * @return list<string>
     */
    public static function order(): array
    {
        return ['worker_code', 'schema', 'writable_dirs', 'catalog_freshness', 'pool_invariant', 'db_connection'];
    }

    /**
     * Turn gathered facts into the six checks.
     *
     * @param  array<string,mixed> $facts
     * @return list<array<string,mixed>>
     */
    public static function checks(array $facts): array
    {
        return [
            self::workerCode($facts),
            self::schema($facts),
            self::writableDirs($facts),
            self::catalogFreshness($facts),
            self::poolInvariant($facts),
            self::dbConnection($facts),
        ];
    }

    /**
     * The worst severity found, so the panel can paint one banner for the
     * whole screen. `error` beats `warn` beats `unknown` beats `ok`.
     *
     * @param list<array<string,mixed>> $checks
     */
    public static function overall(array $checks): string
    {
        $worst = self::OK;
        foreach ($checks as $check) {
            $severity = (string)($check['severity'] ?? self::UNKNOWN);
            if (!isset(self::SEVERITY_RANK[$severity])) {
                $severity = self::UNKNOWN;
            }
            if (self::SEVERITY_RANK[$severity] > self::SEVERITY_RANK[$worst]) {
                $worst = $severity;
            }
        }

        return $worst;
    }

    /**
     * @param  list<array<string,mixed>> $checks
     * @return array{checks:int,ok:int,warn:int,error:int,unknown:int}
     */
    public static function summary(array $checks): array
    {
        $out = ['checks' => count($checks), 'ok' => 0, 'warn' => 0, 'error' => 0, 'unknown' => 0];
        foreach ($checks as $check) {
            $severity = (string)($check['severity'] ?? self::UNKNOWN);
            if (!in_array($severity, [self::OK, self::WARN, self::ERROR, self::UNKNOWN], true)) {
                $severity = self::UNKNOWN;
            }
            $out[$severity]++;
        }

        return $out;
    }

    /**
     * The compact first-run list for a brand-new install. Same shape as a
     * check but binary: `done` plus what is left to do.
     *
     * @param  array<string,mixed> $facts
     * @return list<array<string,mixed>>
     */
    public static function setupChecklist(array $facts): array
    {
        $setup = is_array($facts['setup'] ?? null) ? $facts['setup'] : [];
        $dirs  = is_array($facts['dirs'] ?? null) ? $facts['dirs'] : [];

        $flag = static fn(string $key): bool => ($setup[$key] ?? null) === true;

        $adminDone = $flag('admin_configured');
        $items = [];

        $items[] = self::item('admin_key', 'Admin key', $adminDone, $adminDone
            ? 'An admin key is configured (environment variable or `.admin.key`), so the operator routes are gated.'
            : 'No admin key is configured, so every operator route answers 503 until one exists. Write a single-line '
                . 'token to `.admin.key` in the project root and make it readable by the PHP user; the panel never '
                . 'creates it for you.',
            $adminDone ? [] : [
                self::action('create_admin_key', 'Create .admin.key', 'command',
                    'printf %s <token> > .admin.key && chmod 600 .admin.key', null, false,
                    external: true,
                    note: 'Run this on the host as the PHP user. The panel reports it and does not run it.'),
            ]);

        $dbDone = $flag('database_configured');
        $items[] = self::item('database', 'Database connection', $dbDone, $dbDone
            ? 'A DSN is configured.'
            : 'No DSN is configured, so nothing is persisted and the worker has no queue.',
            $dbDone ? [] : [self::action('open_database', 'Open the Database card', 'navigate', 'oc-database-card')]);

        $schemaDone = $flag('schema_ready');
        $items[] = self::item('schema', 'Schema and migrations', $schemaDone, $schemaDone
            ? 'Every migration file is applied and the live schema version matches the deployed one.'
            : 'The schema is missing or behind, so the tables this release writes do not exist yet.',
            $schemaDone ? [] : [
                self::action('apply_migrations', 'Apply migrations', 'endpoint',
                    '/api/settings/migrations/apply', 'POST', true),
                self::action('open_migrations', 'Open the Migrations card', 'navigate', 'oc-migrations-card'),
            ]);

        $providers = is_array($setup['provider_keys'] ?? null) ? $setup['provider_keys'] : [];
        $configured = [];
        foreach ($providers as $row) {
            if (is_array($row) && ($row['configured'] ?? false) === true) {
                $configured[] = (string)($row['provider'] ?? '?');
            }
        }
        $providerDone = $configured !== [];
        $items[] = self::item('provider_keys', 'Provider keys', $providerDone, $providerDone
            ? 'Configured providers: ' . implode(', ', $configured) . '.'
            : 'No provider key is configured, so no model can be called.',
            $providerDone ? [] : [
                self::action('open_models', 'Open the Models tab', 'navigate', 'oc-providers-card'),
            ]);

        $catalogDone = $flag('catalog_ready');
        $items[] = self::item('catalogue', 'Model catalogue', $catalogDone, $catalogDone
            ? 'The persisted catalogue holds at least one active model.'
            : 'The persisted catalogue has no active model, so the pool cannot resolve.',
            $catalogDone ? [] : [
                self::action('refresh_catalogue', 'Actualize the model catalogue', 'endpoint',
                    '/api/models/actualize', 'POST', true),
            ]);

        $workerDone = $flag('worker_running');
        $items[] = self::item('worker', 'Worker process', $workerDone, $workerDone
            ? 'A worker process is alive and reporting presence.'
            : 'No worker process is running, so queued jobs are never picked up.',
            $workerDone ? [] : [
                self::action('start_worker', 'Start the worker', 'command',
                    'sudo systemctl enable --now revoap-worker.service', null, false,
                    external: true,
                    note: 'Run this on the host. The panel reports it and does not run it.'),
            ]);

        $dirsDone = $dirs !== [] && $flag('dirs_ok');
        $items[] = self::item('writable_dirs', 'Writable directories', $dirsDone, $dirsDone
            ? 'Every directory the application writes to is writable by the web process.'
            : 'At least one directory the application writes to is missing or not writable.',
            $dirsDone ? [] : [
                self::action('open_health', 'Open the checks below', 'navigate', 'oc-system-health-card'),
            ]);

        return $items;
    }

    // ---------------------------------------------------------------------
    // Checks
    // ---------------------------------------------------------------------

    /** @param array<string,mixed> $facts */
    private static function workerCode(array $facts): array
    {
        $worker  = self::sub($facts, 'worker');
        $state   = (string)($worker['code_state'] ?? self::UNKNOWN);
        $reason  = self::text($worker['code_stale_reason'] ?? null);
        $newest  = self::text($worker['code_newest_file'] ?? null);
        $at      = self::text($worker['code_newest_at'] ?? null);
        $appVer  = self::text($worker['app_version'] ?? null);
        $running = (string)($worker['worker_status'] ?? '');
        $alive   = (int)($worker['workers_alive'] ?? 0);

        $presence = ($worker['presence_table_available'] ?? null) === true;
        $observed = [
            'code_state'               => $state,
            'worker_status'            => $running,
            'workers_alive'            => $alive,
            'presence_table_available' => $presence,
            'app_version'              => $appVer,
            'code_newest_file'         => $newest,
            'code_newest_at'           => $at,
        ];

        if ($state === 'stale') {
            $where = $newest !== '' && $newest !== '0'
                ? ' Newest code file: ' . $newest . ($at !== '' ? ' (' . $at . ')' : '') . '.'
                : '';
            return self::check('worker_code', 'Worker code version', self::ERROR,
                'The worker is running code older than what is deployed.',
                ($reason !== '' ? $reason : 'The worker process was started before the current code was deployed.')
                    . $where
                    . ' PHP loads each class once per process, so a worker started before a deploy keeps the old '
                    . 'classes: freshly deployed callers then fail with errors like "Call to undefined method …" '
                    . 'while the queue itself looks healthy. Only a restart clears it.',
                [
                    self::action('restart_worker', 'Restart the workers', 'endpoint', '/api/worker/restart', 'POST', true,
                        note: 'Asks every running worker to stop after its current poll; systemd starts it again on '
                            . 'the deployed code. Idempotent: asking twice before the workers notice is harmless.'),
                    self::action('restart_command', 'Restart by hand instead', 'command',
                        'sudo systemctl restart revoap-worker.service', null, false,
                        external: true,
                        note: 'Run this on the host. The panel reports it and does not run it.'),
                ],
                $observed);
        }

        if ($state === 'current') {
            return self::check('worker_code', 'Worker code version', self::OK,
                'The running worker matches the deployed code.',
                'The presence beacon reports the same version this deployment serves'
                    . ($appVer !== '' ? ' (' . $appVer . ')' : '') . ', and no code file is newer than the process.',
                [], $observed);
        }

        // `unknown`: nothing running to compare against, or a beacon that
        // carries neither a version nor a start time.
        if (!$presence) {
            return self::check('worker_code', 'Worker code version', self::UNKNOWN,
                'The worker presence table is missing, so the running code cannot be compared with the deployed one.',
                'The presence beacon (`revoap_worker_presence`) is what records which version a worker loaded. '
                    . 'Apply the pending migrations, then restart the worker so it registers itself, and re-check.',
                [
                    self::action('apply_migrations', 'Apply migrations', 'endpoint',
                        '/api/settings/migrations/apply', 'POST', true),
                    self::action('open_worker', 'Open the Worker card', 'navigate', 'oc-worker-card'),
                ],
                $observed);
        }

        if ($running === 'not_running' || $alive === 0) {
            return self::check('worker_code', 'Worker code version', self::UNKNOWN,
                'No worker process is running, so the deployed code cannot be compared with anything.',
                'The check needs a live worker presence row. Start the worker and re-check: it registers itself '
                    . 'within a second, and then this check can tell whether it loaded the deployed code.'
                    . ($reason !== '' ? ' Reported: ' . $reason : ''),
                [
                    self::action('start_worker', 'Start the worker', 'command',
                        'sudo systemctl start revoap-worker.service', null, false,
                        external: true,
                        note: 'Run this on the host. The panel reports it and does not run it.'),
                    self::action('open_worker', 'Open the Worker card', 'navigate', 'oc-worker-card'),
                ],
                $observed);
        }

        return self::check('worker_code', 'Worker code version', self::UNKNOWN,
            'Cannot tell whether the running worker matches the deployed code.',
            ($reason !== '' ? $reason : 'The worker presence row carries neither an app version nor a start time, '
                . 'so there is nothing to compare the deployed code against.'),
            [self::action('open_worker', 'Open the Worker card', 'navigate', 'oc-worker-card')],
            $observed);
    }

    /** @param array<string,mixed> $facts */
    private static function schema(array $facts): array
    {
        $schema = self::sub($facts, 'schema');
        $ready  = ($schema['database_ready'] ?? null) === true;
        $config = ($schema['config_table_present'] ?? null) === true;
        $live   = self::text($schema['live_schema_version'] ?? null);
        $expect = self::text($schema['expected_schema_version'] ?? null);
        $pending = (int)($schema['pending_migrations'] ?? 0);
        $pendingNames = is_array($schema['pending_names'] ?? null) ? array_values($schema['pending_names']) : [];
        $applied = (int)($schema['applied_migrations'] ?? 0);
        $total   = (int)($schema['total_migrations'] ?? 0);

        $observed = [
            'database_ready'          => $ready,
            'config_table_present'    => $config,
            'live_schema_version'     => $live,
            'expected_schema_version' => $expect,
            'pending_migrations'      => $pending,
            'applied_migrations'      => $applied,
            'total_migrations'        => $total,
        ];

        if (!$ready) {
            return self::check('schema', 'Migrations and schema version', self::ERROR,
                'No database connection, so the schema cannot be checked.',
                'The web process holds no database handle. Until it does, every table this release expects is '
                    . 'unproven and jobs cannot be queued.',
                [self::action('open_database', 'Open the Database card', 'navigate', 'oc-database-card')],
                $observed);
        }

        if (!$config) {
            return self::check('schema', 'Migrations and schema version', self::ERROR,
                'The database schema has not been created.',
                'The `app_config` table is missing, which means `db/schema.sql` was never imported into this '
                    . 'database. Until then no table the application writes is guaranteed to exist.',
                [
                    self::action('apply_migrations', 'Apply migrations', 'endpoint',
                        '/api/settings/migrations/apply', 'POST', true,
                        note: 'Idempotent: already-applied files are skipped, and re-running changes nothing.'),
                    self::action('open_migrations', 'Open the Migrations card', 'navigate', 'oc-migrations-card'),
                ],
                $observed);
        }

        if ($pending > 0) {
            $named = $pendingNames === [] ? '' : ' Not applied: ' . implode(', ', array_slice($pendingNames, 0, 3))
                . (count($pendingNames) > 3 ? sprintf(' and %d more', count($pendingNames) - 3) : '.');
            return self::check('schema', 'Migrations and schema version', self::ERROR,
                sprintf('%d migration file(s) have not been applied.', $pending),
                'A release that moves the schema leaves an existing database one step behind until the migration '
                    . 'files are applied. Until then the columns the new code writes may not exist, and the run can '
                    . 'fail in ways that look unrelated.' . $named,
                [
                    self::action('apply_migrations', 'Apply migrations', 'endpoint',
                        '/api/settings/migrations/apply', 'POST', true,
                        note: 'Idempotent: already-applied files are skipped, and re-running changes nothing.'),
                    self::action('open_migrations', 'Open the Migrations card', 'navigate', 'oc-migrations-card'),
                ],
                $observed);
        }

        if ($live !== '' && $expect !== '' && $live !== $expect) {
            return self::check('schema', 'Migrations and schema version', self::WARN,
                sprintf('The database schema is behind: live %s, expected %s.', $live, $expect),
                'Every migration file is applied, but the version recorded in the database does not match the one '
                    . '`db/schema.sql` declares. Applying migrations again records the deployed version.',
                [
                    self::action('apply_migrations', 'Apply migrations', 'endpoint',
                        '/api/settings/migrations/apply', 'POST', true,
                        note: 'Idempotent: already-applied files are skipped, and re-running changes nothing.'),
                    self::action('open_migrations', 'Open the Migrations card', 'navigate', 'oc-migrations-card'),
                ],
                $observed);
        }

        return self::check('schema', 'Migrations and schema version', self::OK,
            'The schema is up to date.',
            sprintf('%d of %d migration file(s) applied; live schema version %s.',
                $applied, $total, $live !== '' ? $live : '(unset)'),
            [], $observed);
    }

    /** @param array<string,mixed> $facts */
    private static function writableDirs(array $facts): array
    {
        $dirs = is_array($facts['dirs'] ?? null) ? $facts['dirs'] : [];

        if ($dirs === []) {
            return self::check('writable_dirs', 'Writable directories', self::UNKNOWN,
                'The writable directories were not probed.',
                'No directory facts were gathered, so nothing can be said about the filesystem. Re-check to probe '
                    . 'them again.',
                // A dead end is not an answer: even "I could not look" comes
                // with the button that looks again.
                [self::action('recheck_health', 'Re-check now', 'endpoint', '/api/health/system', 'GET')],
                []);
        }

        $broken = [];
        $lines  = [];
        foreach ($dirs as $dir) {
            if (!is_array($dir)) {
                continue;
            }
            $label    = self::text($dir['label'] ?? null);
            $target   = self::text($dir['target'] ?? null);
            $exists   = ($dir['exists'] ?? null) === true;
            $writable = ($dir['writable'] ?? null) === true;
            $perms    = self::text($dir['perms'] ?? null);
            $owner    = self::text($dir['owner'] ?? null);
            $user     = self::text($dir['process_user'] ?? null);

            $line = sprintf('%s (%s): %s', $label !== '' ? $label : $target, $target,
                $exists ? ($writable ? 'writable' : 'exists but not writable') : 'directory is missing');
            if ($perms !== '') { $line .= ', mode ' . $perms; }
            if ($owner !== '') { $line .= ', owner ' . $owner; }
            if ($user !== '')  { $line .= ', PHP runs as ' . $user; }
            $lines[] = $line;

            if (!$exists || !$writable) {
                $broken[] = $label !== '' ? $label : $target;
            }
        }

        $observed = ['dirs' => $dirs, 'broken' => $broken];

        if ($broken !== []) {
            return self::check('writable_dirs', 'Writable directories', self::ERROR,
                sprintf('%d writable directory/directories cannot be written: %s.', count($broken), implode(', ', $broken)),
                implode(' | ', $lines)
                    . ' Ownership and mode live outside the application, so the panel does not change them. '
                    . 'Run one of these on the host as root, then re-check:',
                [
                    self::action('chown_dir', 'Take ownership as the PHP user', 'command',
                        'sudo chown -R <php-user>:<php-user> ' . implode(' ', array_map(
                            static fn(array $d): string => self::text($d['target'] ?? null),
                            array_values(array_filter($dirs, static fn($d): bool => is_array($d)
                                && (($d['exists'] ?? null) !== true || ($d['writable'] ?? null) !== true))),
                        )),
                        null, false,
                        external: true,
                        note: 'Replace <php-user> with the user this process runs as (see above). The panel reports '
                            . 'this command and does not run it.'),
                    self::action('chmod_dir', 'Grant write permission', 'command',
                        'sudo chmod -R u+w ' . implode(' ', array_map(
                            static fn(array $d): string => self::text($d['target'] ?? null),
                            array_values(array_filter($dirs, static fn($d): bool => is_array($d)
                                && (($d['exists'] ?? null) !== true || ($d['writable'] ?? null) !== true))),
                        )),
                        null, false,
                        external: true,
                        note: 'Run this on the host. The panel reports it and does not run it.'),
                ],
                $observed);
        }

        return self::check('writable_dirs', 'Writable directories', self::OK,
            sprintf('All %d writable directories are writable by the web process.', count($lines)),
            implode(' | ', $lines),
            [], $observed);
    }

    /** @param array<string,mixed> $facts */
    private static function catalogFreshness(array $facts): array
    {
        $catalog = self::sub($facts, 'catalog');
        $active  = (int)($catalog['active_rows'] ?? 0);
        $total   = (int)($catalog['total_rows'] ?? 0);
        $cap     = (int)($catalog['max_rows'] ?? 0);
        $taken   = self::text($catalog['snapshot_taken_at'] ?? null);
        $age     = $catalog['snapshot_age_s'] ?? null;
        $days    = (int)($catalog['stale_after_days'] ?? self::DEFAULT_CATALOG_STALE_DAYS);

        $observed = [
            'active_rows'       => $active,
            'total_rows'        => $total,
            'max_rows'          => $cap,
            'snapshot_taken_at' => $taken,
            'snapshot_age_s'    => is_int($age) ? $age : null,
            'stale_after_days'  => $days,
        ];

        $refresh = self::action('refresh_catalogue', 'Actualize the model catalogue', 'endpoint',
            '/api/models/actualize', 'POST', true,
            note: 'Enqueues one actualization run in the worker: it imports the live catalogue and re-assigns the '
                . 'linked models. Idempotent while a run is in flight (the running one is reused).');

        if ($active <= 0) {
            return self::check('catalog_freshness', 'Model catalogue freshness', self::ERROR,
                'The active model catalogue is empty, so no consensus can run.',
                sprintf('The persisted catalogue holds %d row(s) and none of them is active.', $total),
                [$refresh, self::action('open_actualize', 'Open the Actualize card', 'navigate', 'oc-actualize-card')],
                $observed);
        }

        if ($age === null || !is_int($age)) {
            return self::check('catalog_freshness', 'Model catalogue freshness', self::WARN,
                'The catalogue has never been actualized.',
                sprintf('The active catalogue holds %d of %d row(s), but no successful actualization run is '
                    . 'recorded. How old it is cannot be answered from here.', $active, $total),
                [$refresh, self::action('open_actualize', 'Open the Actualize card', 'navigate', 'oc-actualize-card')],
                $observed);
        }

        if ($age > $days * 86400) {
            return self::check('catalog_freshness', 'Model catalogue freshness', self::WARN,
                sprintf('The catalogue was last actualized %d day(s) ago.', intdiv($age, 86400)),
                sprintf('Providers retire identifiers; a catalogue older than %d day(s) is how the pool ends up '
                    . 'pointing at models that are no longer served. Last run: %s (%d of %d row(s) active).',
                    $days, $taken !== '' ? $taken : 'unknown', $active, $total),
                [$refresh, self::action('open_actualize', 'Open the Actualize card', 'navigate', 'oc-actualize-card')],
                $observed);
        }

        if ($cap > 0 && $total >= $cap) {
            return self::check('catalog_freshness', 'Model catalogue freshness', self::WARN,
                sprintf('The catalogue is capped at %d rows, so rows were left out.', $cap),
                sprintf('The persisted catalogue holds %d rows, which is the configured ceiling. Rows the pool, a '
                    . 'preset or a linked role references are always kept; the rest were ranked and the surplus was '
                    . 'left out. Raise `models_catalog.max_rows` if a model you need is missing.', $total),
                [$refresh, self::action('open_actualize', 'Open the Actualize card', 'navigate', 'oc-actualize-card')],
                $observed);
        }

        return self::check('catalog_freshness', 'Model catalogue freshness', self::OK,
            'The catalogue is current.',
            sprintf('%d of %d row(s) active; last actualized %d day(s) ago (%s).',
                $active, $total, intdiv($age, 86400), $taken !== '' ? $taken : 'unknown'),
            [], $observed);
    }

    /** @param array<string,mixed> $facts */
    private static function poolInvariant(array $facts): array
    {
        $pool     = self::sub($facts, 'pool');
        $referenced = is_array($pool['referenced'] ?? null) ? array_values($pool['referenced']) : [];
        $missing    = is_array($pool['missing'] ?? null) ? array_values($pool['missing']) : [];
        $activeKeys = is_array($pool['active_keys'] ?? null) ? array_values($pool['active_keys']) : [];

        $observed = ['referenced' => count($referenced), 'missing' => count($missing), 'active_keys' => count($activeKeys)];

        $refresh = self::action('refresh_catalogue', 'Actualize the model catalogue', 'endpoint',
            '/api/models/actualize', 'POST', true,
            note: 'Enqueues one actualization run: it imports the live catalogue and re-assigns the linked models, '
                . 'which is what puts a referenced row back into the catalogue.');

        if ($missing !== []) {
            $names = [];
            foreach (array_slice($missing, 0, 5) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $model  = self::text($row['model'] ?? null);
                $source = self::text($row['source'] ?? null);
                $names[] = $model . ($source !== '' ? ' (' . $source . ')' : '');
            }
            $tail = count($missing) > 5 ? sprintf(' and %d more', count($missing) - 5) : '';
            return self::check('pool_invariant', 'Pool and roles inside the active catalogue', self::ERROR,
                sprintf('%d referenced model(s) are not in the active catalogue.', count($missing)),
                'Every row the pool, the domain presets and the three linked roles name has to exist in the active '
                    . 'catalogue, because that list is what L1 can dispatch to. A dangling reference is how a run '
                    . 'ends with "pool resolved to no callable model". Missing: ' . implode(', ', $names) . $tail . '.',
                [$refresh, self::action('open_actualize', 'Open the Actualize card', 'navigate', 'oc-actualize-card')],
                $observed);
        }

        if ($referenced === []) {
            return self::check('pool_invariant', 'Pool and roles inside the active catalogue', self::OK,
                'Nothing references the catalogue yet.',
                'The pool, the domain presets and the linked roles name no model, so the invariant holds '
                    . 'vacuously. Pick the models you want in the sidebar or actualize the catalogue to seed them.',
                [], $observed);
        }

        return self::check('pool_invariant', 'Pool and roles inside the active catalogue', self::OK,
            sprintf('All %d referenced model(s) are in the active catalogue.', count($referenced)),
            sprintf('The pool, the domain presets and the linked roles reference %d row(s); every one of them is '
                . 'present among the %d active catalogue row(s).', count($referenced), count($activeKeys)),
            [], $observed);
    }

    /** @param array<string,mixed> $facts */
    private static function dbConnection(array $facts): array
    {
        $worker = self::sub($facts, 'worker');
        $connected = ($worker['db_connected'] ?? null) === true;
        $reconnects = (int)($worker['db_reconnects'] ?? 0);
        $losses     = (int)($worker['db_losses'] ?? 0);
        $lastAt     = self::text($worker['last_db_error_at'] ?? null);
        $lastError  = self::text($worker['last_db_error'] ?? null);

        $observed = [
            'db_connected'     => $connected,
            'db_reconnects'    => $reconnects,
            'db_losses'        => $losses,
            'last_db_error_at' => $lastAt,
            'last_db_error'    => $lastError,
        ];

        if (!$connected) {
            return self::check('db_connection', 'Database connections', self::ERROR,
                'The web process holds no database connection.',
                'Without a handle nothing is persisted and the worker has no queue to read.'
                    . ($lastError !== '' ? ' Last recorded error: ' . $lastError : ''),
                [
                    self::action('open_database', 'Open the Database card', 'navigate', 'oc-database-card'),
                    self::action('restart_mysql', 'Restart the database server', 'command',
                        'sudo systemctl restart mysql', null, false,
                        external: true,
                        note: 'Run this on the host if the server itself is down. The panel reports it and does not '
                            . 'run it.'),
                ],
                $observed);
        }

        if ($losses > 0) {
            return self::check('db_connection', 'Database connections', self::WARN,
                sprintf('The database connection was lost %d time(s) since the last process start.', $losses),
                sprintf('A worker that survives a database restart keeps working, but every job it held at that '
                    . 'moment failed until the handle was replaced. Reconnects: %d.%s%s',
                    $reconnects,
                    $lastAt !== '' ? ' Last loss: ' . $lastAt . '.' : '',
                    $lastError !== '' ? ' Last error: ' . $lastError : ''),
                [
                    self::action('open_database', 'Open the Database card', 'navigate', 'oc-database-card'),
                    self::action('restart_mysql', 'Restart the database server', 'command',
                        'sudo systemctl restart mysql', null, false,
                        external: true,
                        note: 'Run this on the host only if the server is actually unhealthy. The panel reports it '
                            . 'and does not run it.'),
                ],
                $observed);
        }

        return self::check('db_connection', 'Database connections', self::OK,
            'The database connection is healthy.',
            sprintf('A handle is held right now and no connection loss has been recorded (%d reconnect(s)).', $reconnects),
            [], $observed);
    }

    // ---------------------------------------------------------------------
    // Shapes
    // ---------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $actions
     * @param array<string,mixed>       $facts
     * @return array<string,mixed>
     */
    private static function check(
        string $id,
        string $label,
        string $severity,
        string $headline,
        string $detail,
        array $actions,
        array $facts = [],
    ): array {
        if (!isset(self::SEVERITY_RANK[$severity])) {
            $severity = self::UNKNOWN;
        }

        return [
            'id'       => $id,
            'label'    => $label,
            'severity' => $severity,
            'headline' => $headline,
            'detail'   => $detail,
            'actions'  => $actions,
            'facts'    => $facts,
        ];
    }

    /** @return array<string,mixed> */
    private static function action(
        string $id,
        string $label,
        string $kind,
        ?string $target = null,
        ?string $method = null,
        bool $confirm = false,
        bool $external = false,
        ?string $note = null,
    ): array {
        return [
            'id'                 => $id,
            'label'              => $label,
            'kind'               => $kind,       // endpoint | command | navigate
            'target'             => $target,
            'method'             => $method,
            'needs_confirmation' => $confirm,
            // `external` is the promise the panel makes: this lives outside
            // the application, so the panel shows the command and does not run
            // it. The front end uses it to render the disclosure.
            'external'           => $external,
            'note'               => $note,
        ];
    }

    /** @param list<array<string,mixed>> $actions */
    private static function item(string $id, string $label, bool $done, string $detail, array $actions): array
    {
        return [
            'id'       => $id,
            'label'    => $label,
            'done'     => $done,
            'detail'   => $detail,
            'actions'  => $actions,
        ];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private static function sub(array $facts, string $key): array
    {
        $value = $facts[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return '';
    }
}
