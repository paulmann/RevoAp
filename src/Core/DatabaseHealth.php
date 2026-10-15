<?php
/**
 * DatabaseHealth: produces a sanitized snapshot of the database state for
 * the History / Statistic / health endpoints.
 *
 * The History and Statistic tabs both render generic "Open Settings to
 * configure the DSN" messages even when Settings is admin-disabled and
 * the operator cannot actually open it. This helper centralises the
 * diagnostic so callers can surface accurate, actionable information:
 *   - Whether the database is configured (DSN present).
 *   - Whether the connection succeeded (PDO is non-null).
 *   - Whether the schema is ready (every table this build expects exists).
 *   - The list of missing tables, basenames only (never the secret).
 *   - A sanitized DSN host/dbname so operators can confirm where the
 *     server is pointing without leaking the password.
 *
 * No password, token, query string, or absolute filesystem path is ever
 * included in the snapshot. The caller is free to surface the snapshot
 * verbatim to a public endpoint.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.2.0
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\Core;

use App\Settings\DatabaseDsn;
use PDO;

final class DatabaseHealth
{
    /** Tables required by the History / Statistic / Share readers. */
    public const REQUIRED_TABLES = [
        'skills',
        'council_sessions',
        'council_steps',
        'council_model_responses',
        'pipeline_events',
        // v1.8.24: shared_sessions (migration 006) is required for the
        // chat Share button. When absent, /api/share/session returns
        // HTTP 409 migration_required so operators get an actionable
        // diagnostic instead of an opaque 500.
        'shared_sessions',
    ];

    /**
     * Build a sanitized snapshot.
     *
     * @param  null|PDO|Database $db The active database wrapper or PDO.
     * @return array{
     *   configured: bool,
     *   connected:  bool,
     *   schema_ready: bool,
     *   missing_tables: array<int,string>,
     *   present_tables: array<int,string>,
     *   dsn: array{driver:string,host:string,port:int,dbname:string,charset:string,unix_socket:string},
     *   user_set: bool,
     *   password_set: bool,
     *   error: array{class:string,message:string}|null,
     *   message: string,
     * }
     */
    public static function snapshot(null|PDO|Database $db): array
    {
        $dsnRaw = '';
        try {
            $dsnRaw = (string)Config::get('database.dsn', '');
        } catch (\Throwable) {
            // Config not loaded — treat as unconfigured.
        }
        $configured = $dsnRaw !== '';
        $parsed = DatabaseDsn::parse($dsnRaw);
        $userSet = false;
        $pwSet = false;
        // Active database block source. `config.php` is the baseline.
        // `.sql.php` overrides it when a valid root-file is present.
        // `runtime.json` (admin Settings save) shadows everything and
        // is flagged separately so operators can see when their
        // deployment file is being silently masked.
        $source = 'config.php';
        $runtimeShadow = false;
        try {
            $userSet = trim((string)Config::get('database.user', '')) !== '';
            $pwSet   = (string)Config::get('database.password', '') !== '';
            $recorded = Config::get('app.diagnostics.database_source', null);
            if (is_string($recorded) && $recorded !== '') {
                $source = $recorded;
            }
            $shadowFlag = Config::get('app.diagnostics.database_runtime_shadow', null);
            if ($shadowFlag === true) {
                $runtimeShadow = true;
                $source = 'runtime.json';
            }
        } catch (\Throwable) {
            // Defaults already false.
        }

        $connected = false;
        $tables = [];
        $error = null;
        if ($db !== null) {
            try {
                $pdo = self::resolvePdo($db);
                if ($pdo instanceof PDO) {
                    $connected = true;
                    $tables = self::listTables($pdo);
                }
            } catch (\Throwable $e) {
                $error = [
                    'class'   => self::shortClass($e),
                    'message' => Logger::sanitizeScalar($e->getMessage()),
                ];
            }
        }

        $missing = [];
        foreach (self::REQUIRED_TABLES as $tbl) {
            if (!in_array($tbl, $tables, true)) {
                $missing[] = $tbl;
            }
        }

        $schemaReady = $connected && $missing === [];

        // Compose an actionable message — the caller may use it directly
        // when the DB is broken instead of falling back to generic
        // "Open Settings" wording.
        $message = self::composeMessage($configured, $connected, $missing, $error);

        // Surface the per-file overlay diagnostic so /api/health can
        // answer "why is the database source still config.php?". When
        // `.sql.php` was found and applied, `config_sources['.sql.php'].loaded`
        // is true; when missing, `exists` is false; when malformed,
        // `error` carries a concrete reason. Operators can tell at a
        // glance which file the runtime saw and why it was or was not
        // honored.
        $configSources = [];
        $runtimeShadowSections = [];
        $rootInfo = null;
        try {
            $cs = Config::get('app.diagnostics.config_sources', null);
            if (is_array($cs)) {
                $configSources = $cs;
            }
            $rt = Config::get('app.diagnostics.runtime_shadow_sections', null);
            if (is_array($rt)) {
                /** @var array<int,string> $rt */
                $runtimeShadowSections = $rt;
            }
            $r = Config::get('app.diagnostics.root', null);
            if (is_array($r)) {
                $rootInfo = $r;
            }
        } catch (\Throwable) {
            // Defaults already empty.
        }

        return [
            'configured'             => $configured,
            'connected'              => $connected,
            'schema_ready'           => $schemaReady,
            'missing_tables'         => $missing,
            'present_tables'         => array_values($tables),
            'dsn'                    => $parsed,
            'user_set'               => $userSet,
            'password_set'           => $pwSet,
            'source'                 => $source,
            'runtime_shadow'         => $runtimeShadow,
            'runtime_shadow_sections'=> $runtimeShadowSections,
            'config_sources'         => $configSources,
            'root'                   => $rootInfo,
            'error'                  => $error,
            'message'                => $message,
        ];
    }

    /**
     * Compose the diagnostic message surfaced to the History / Statistic
     * tabs when the database is not ready. Avoids generic "Open Settings"
     * text when Settings is admin-disabled — instead points the operator
     * at the config.php / .sql.php / migration steps that they CAN take
     * without unlocking Settings.
     *
     * @param array<int,string>                          $missing
     * @param array{class:string,message:string}|null    $error
     */
    private static function composeMessage(
        bool $configured,
        bool $connected,
        array $missing,
        ?array $error,
    ): string {
        if (!$configured) {
            return 'Database is not configured. Set the `database` block in config.php (host/dbname/user/password) '
                . 'or drop a `.sql.php` file in the project root that returns the database fields.';
        }
        if (!$connected) {
            $hint = $error !== null
                ? ' (' . $error['class'] . ': ' . $error['message'] . ')'
                : '';
            return 'Database is configured but the connection failed' . $hint . '. '
                . 'Verify the DSN host/port and credentials, then reload.';
        }
        if ($missing !== []) {
            return 'Database is connected but the schema is incomplete. Missing tables: '
                . implode(', ', $missing) . '. Import db/schema.sql or apply every file '
                . 'under db/migrations/ in order, then reload.';
        }
        return 'Database connection healthy.';
    }

    /**
     * Resolve a live PDO from the supported wrapper types.
     *
     * @param  PDO|Database $db
     */
    private static function resolvePdo(PDO|Database $db): ?PDO
    {
        if ($db instanceof PDO) {
            return $db;
        }
        return $db->pdo();
    }

    /**
     * Enumerate the user tables visible to this connection. Engine-agnostic:
     * `SHOW TABLES` on MySQL, `SELECT name FROM sqlite_master WHERE type='table'`
     * on SQLite (used by the test suite). Returns a lower-cased list so the
     * required-table comparison is case-insensitive.
     *
     * @return array<int,string>
     */
    private static function listTables(PDO $pdo): array
    {
        $driver = '';
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            // Some drivers don't expose this; fall through to MySQL path.
        }
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table'",
            default  => 'SHOW TABLES',
        };
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            if (!is_array($row) || !isset($row[0])) { continue; }
            $name = strtolower((string)$row[0]);
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    private static function shortClass(\Throwable $e): string
    {
        $parts = explode('\\', get_class($e));
        return (string)end($parts);
    }
}
