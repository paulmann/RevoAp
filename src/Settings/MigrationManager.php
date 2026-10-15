<?php
/**
 * MigrationManager: list, apply, and report SQL migrations under db/migrations.
 *
 * Tracks applied migrations in a `schema_migrations` table to keep the apply
 * step idempotent. Existing migrations may not be idempotent themselves; we
 * cope by detecting common "already exists" / "duplicate column" errors and
 * recording the migration as applied so a re-run never hard-fails when the
 * schema is already in place.
 *
 * v1.8.26: every PDO read path goes through {@see SqlExecutor} so cursors
 * are always finalised. The previous implementation iterated SELECT
 * results with a `while ($stmt->fetch())` loop and then immediately
 * issued the next statement, which on MySQL with emulate-prepares off
 * triggers SQLSTATE[HY000] 2014 "Cannot execute queries while other
 * unbuffered queries are active". Splitting + executing migration
 * scripts is delegated to the executor so apply / recreate share one
 * audited code path.
 *
 * v1.8.27 additions:
 *   - {@see applyOneByName()} — per-file apply driven by the new
 *     per-row "Apply" buttons in the Settings Migrations UI. The
 *     filename is whitelisted against the directory listing so the
 *     endpoint cannot be coerced into opening arbitrary files.
 *   - After every successful apply (single OR pending-batch), the
 *     `@table-version` annotations parsed from the migration via
 *     {@see VersionParser} are written to the `app_config` table
 *     through {@see ConfigRepository} so the live DB always advertises
 *     the table versions it actually has. The Check DB endpoint then
 *     compares those entries against db/schema.sql to surface drift.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.2.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Settings;

use App\Core\ErrorDiagnostics;
use App\Core\Logger;
use PDO;
use PDOException;

final class MigrationManager
{
    public const MIGRATIONS_TABLE = 'schema_migrations';

    private readonly SqlExecutor $sql;
    private readonly ConfigRepository $config;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
        $this->sql = new SqlExecutor($pdo);
        $this->config = new ConfigRepository($pdo);
    }

    /**
     * Enumerate every migration file with its applied state and the
     * `@table-version` annotations parsed from the file header. The
     * UI uses `tables` to display which tables a file advances and
     * `applied` to render the per-row Apply / Re-apply button.
     *
     * @return array<int,array{name:string,size:int,applied:bool,tables:array<string,string>,schema_version:?string}>
     */
    public function list(): array
    {
        $files = glob(rtrim($this->migrationsDir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = $this->appliedSet();
        $rows = [];
        foreach ($files as $f) {
            $name = basename($f);
            $parsed = VersionParser::parseFile($f);
            $rows[] = [
                'name'            => $name,
                'size'            => (int)@filesize($f),
                'applied'         => isset($applied[$name]),
                'tables'          => $parsed['tables'],
                'schema_version'  => $parsed['schema'],
            ];
        }
        return $rows;
    }

    /**
     * Apply every pending migration, recording each in schema_migrations.
     * After every successful (or benign-skipped) file, the file's
     * `@table-version` annotations are written to app_config so the
     * live DB advertises the same versions schema.sql declares.
     *
     * @return array{applied:array<int,string>,skipped:array<int,string>,failed:array<int,array<string,mixed>>,version_updates:array<string,array<string,string>>}
     */
    public function applyPending(): array
    {
        $this->ensureTable();
        $applied = $this->appliedSet();
        $files = glob(rtrim($this->migrationsDir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $resApplied = [];
        $resSkipped = [];
        $resFailed  = [];
        $versionUpdates = [];
        foreach ($files as $f) {
            $name = basename($f);
            if (isset($applied[$name])) {
                $resSkipped[] = $name;
                continue;
            }
            $stmtIndex = 0;
            try {
                $this->applyOne($f, $stmtIndex);
                $this->recordApplied($name);
                $resApplied[] = $name;
                $updates = $this->writeVersionsFromFile($f);
                if ($updates !== []) {
                    $versionUpdates[$name] = $updates;
                }
            } catch (PDOException $e) {
                if ($this->isBenignSchemaError($e)) {
                    // Record as applied so future runs don't trip again.
                    $this->recordApplied($name);
                    $resSkipped[] = $name;
                    $updates = $this->writeVersionsFromFile($f);
                    if ($updates !== []) {
                        $versionUpdates[$name] = $updates;
                    }
                    continue;
                }
                $resFailed[] = [
                    'name'      => $name,
                    'statement' => $stmtIndex,
                    'sqlstate'  => is_string($e->errorInfo[0] ?? null) ? (string)$e->errorInfo[0] : '',
                    'driver_code' => is_int($e->errorInfo[1] ?? null) ? (int)$e->errorInfo[1] : 0,
                    'message'   => Logger::sanitizeScalar($e->getMessage()),
                    'details'   => Logger::sanitize(ErrorDiagnostics::describeDatabaseError($e, ['operation' => 'migrate', 'file' => $name, 'statement_index' => $stmtIndex])),
                ];
                break; // halt on first hard failure
            }
        }
        return [
            'applied'         => $resApplied,
            'skipped'         => $resSkipped,
            'failed'          => $resFailed,
            'version_updates' => $versionUpdates,
        ];
    }

    /**
     * Apply a single migration file by name. Validates the filename
     * against the migrations directory listing (no path traversal,
     * no arbitrary file reads) and applies it through the same
     * SqlExecutor pipeline as {@see applyPending()}. Records the file
     * in schema_migrations and writes the parsed `@table-version`
     * annotations to app_config so a per-row Apply mirrors the side
     * effects of an Apply All.
     *
     * Returns a structured report; the caller decides whether to
     * surface it as ok/skip/error to the UI without ever raising a
     * 500.
     *
     * @return array{
     *   ok:bool,
     *   name:string,
     *   status:string,
     *   applied:bool,
     *   skipped:bool,
     *   version_updates:array<string,string>,
     *   error:?array<string,mixed>
     * }
     */
    public function applyOneByName(string $filename): array
    {
        $this->ensureTable();
        $resolved = $this->resolveMigrationFile($filename);
        if ($resolved === null) {
            return [
                'ok'              => false,
                'name'            => $filename,
                'status'          => 'invalid_filename',
                'applied'         => false,
                'skipped'         => false,
                'version_updates' => [],
                'error'           => [
                    'message' => 'Unknown migration file. Filenames must match db/migrations/*.sql exactly.',
                ],
            ];
        }
        $name = basename($resolved);
        $applied = $this->appliedSet();
        if (isset($applied[$name])) {
            // Per-row Apply on an already-applied file is a no-op that
            // still refreshes the app_config version row — keeping the
            // UI's "Apply" affordance useful as a "sync version" button.
            $updates = $this->writeVersionsFromFile($resolved);
            return [
                'ok'              => true,
                'name'            => $name,
                'status'          => 'already_applied',
                'applied'         => false,
                'skipped'         => true,
                'version_updates' => $updates,
                'error'           => null,
            ];
        }
        $stmtIndex = 0;
        try {
            $this->applyOne($resolved, $stmtIndex);
            $this->recordApplied($name);
            $updates = $this->writeVersionsFromFile($resolved);
            return [
                'ok'              => true,
                'name'            => $name,
                'status'          => 'applied',
                'applied'         => true,
                'skipped'         => false,
                'version_updates' => $updates,
                'error'           => null,
            ];
        } catch (PDOException $e) {
            if ($this->isBenignSchemaError($e)) {
                $this->recordApplied($name);
                $updates = $this->writeVersionsFromFile($resolved);
                return [
                    'ok'              => true,
                    'name'            => $name,
                    'status'          => 'skipped_benign',
                    'applied'         => false,
                    'skipped'         => true,
                    'version_updates' => $updates,
                    'error'           => null,
                ];
            }
            return [
                'ok'              => false,
                'name'            => $name,
                'status'          => 'failed',
                'applied'         => false,
                'skipped'         => false,
                'version_updates' => [],
                'error'           => [
                    'statement'   => $stmtIndex,
                    'sqlstate'    => is_string($e->errorInfo[0] ?? null) ? (string)$e->errorInfo[0] : '',
                    'driver_code' => is_int($e->errorInfo[1] ?? null) ? (int)$e->errorInfo[1] : 0,
                    'message'     => Logger::sanitizeScalar($e->getMessage()),
                    'details'     => Logger::sanitize(ErrorDiagnostics::describeDatabaseError($e, [
                        'operation'       => 'migrate_one',
                        'file'            => $name,
                        'statement_index' => $stmtIndex,
                    ])),
                ],
            ];
        }
    }

    /**
     * Whitelist a user-supplied migration filename against the actual
     * directory contents. Returns the absolute path on success, null
     * when the file is not part of the directory. Prevents both path
     * traversal (`../etc/passwd`) and arbitrary `.sql` reads outside
     * the migrations directory.
     */
    private function resolveMigrationFile(string $filename): ?string
    {
        $clean = trim($filename);
        if ($clean === '' || str_contains($clean, '/') || str_contains($clean, '\\')) {
            return null;
        }
        // Require lowercase ASCII filename with a .sql extension.
        if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $clean)) {
            return null;
        }
        $files = glob(rtrim($this->migrationsDir, '/\\') . '/*.sql') ?: [];
        foreach ($files as $f) {
            if (basename($f) === $clean) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Parse @table-version + @schema-version annotations from a
     * migration file and persist them through ConfigRepository.
     * Returns the map of versions actually written so the response
     * payload can show "council_steps -> 1.0.5" next to the applied
     * file. Failures are swallowed and surfaced as an empty map: the
     * migration itself already succeeded, so we never raise a hard
     * error just because the bookkeeping table is unreachable.
     *
     * @return array<string,string>
     */
    private function writeVersionsFromFile(string $file): array
    {
        $parsed = VersionParser::parseFile($file);
        try {
            $this->config->seedTableVersions($parsed['tables'], 'schema');
            $this->config->setSchemaVersion($parsed['schema']);
        } catch (PDOException $e) {
            Logger::warn('settings.migrations.version_write_failed', [
                'file'  => basename($file),
                'error' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
        return $parsed['tables'];
    }

    /**
     * Mark all currently-pending migrations as applied without running
     * them. Useful when the schema was already created by hand from
     * db/schema.sql and the operator just wants to baseline the tracker.
     *
     * @return array{baselined:array<int,string>}
     */
    public function baseline(): array
    {
        $this->ensureTable();
        $applied = $this->appliedSet();
        $files = glob(rtrim($this->migrationsDir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $marked = [];
        foreach ($files as $f) {
            $name = basename($f);
            if (isset($applied[$name])) {
                continue;
            }
            $this->recordApplied($name);
            $marked[] = $name;
        }
        return ['baselined' => $marked];
    }

    /**
     * Best-effort schema dump — uses INFORMATION_SCHEMA to enumerate tables
     * and SHOW CREATE TABLE for the DDL. Avoids shelling out to mysqldump
     * (which is rarely available on shared hosts).
     *
     * Tables are fully enumerated (and the cursor closed) before any
     * SHOW CREATE TABLE runs — otherwise MySQL throws "unbuffered
     * queries are active" the moment we issue the second query.
     */
    public function exportSchema(): string
    {
        $out  = "-- RevoAp schema export (generated)\n";
        $out .= "-- " . gmdate('Y-m-d\TH:i:s\Z') . "\n\n";
        $out .= "SET NAMES utf8mb4;\n\n";
        try {
            $tables = $this->sql->fetchColumnAll('SHOW TABLES');
            if ($tables === []) {
                return $out . "-- (no tables)\n";
            }
            sort($tables, SORT_STRING);
            foreach ($tables as $t) {
                $safe = '`' . str_replace('`', '``', $t) . '`';
                try {
                    $rows = $this->sql->fetchAll("SHOW CREATE TABLE {$safe}", PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $out .= "-- {$t}: " . Logger::sanitizeScalar($e->getMessage()) . "\n";
                    continue;
                }
                $row = $rows[0] ?? null;
                if (!is_array($row)) {
                    continue;
                }
                $ddl = $row['Create Table'] ?? $row['Create View'] ?? '';
                if (!is_string($ddl) || $ddl === '') {
                    continue;
                }
                $out .= "-- ----- {$t} -----\n";
                $out .= $ddl . ";\n\n";
            }
        } catch (PDOException $e) {
            $out .= "-- export failed: " . Logger::sanitizeScalar($e->getMessage()) . "\n";
        }
        return $out;
    }

    private function ensureTable(): void
    {
        // v1.8.27: driver-aware DDL. The MySQL-specific INT UNSIGNED
        // AUTO_INCREMENT + ENGINE=InnoDB syntax is invalid on SQLite
        // (used by the test suite); the SQLite branch ships an
        // equivalent INTEGER PRIMARY KEY AUTOINCREMENT shape. The two
        // are functionally interchangeable for the tracker's tiny
        // (id, migration, applied_at) workload.
        $driver = '';
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            // best-effort — default to MySQL
        }
        if ($driver === 'sqlite') {
            $sql = 'CREATE TABLE IF NOT EXISTS `' . self::MIGRATIONS_TABLE . '` ('
                 . '  `id` INTEGER PRIMARY KEY AUTOINCREMENT,'
                 . '  `migration` TEXT NOT NULL UNIQUE,'
                 . '  `applied_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
                 . ')';
        } else {
            $sql = 'CREATE TABLE IF NOT EXISTS `' . self::MIGRATIONS_TABLE . '` ('
                 . '  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
                 . '  `migration` VARCHAR(255) NOT NULL UNIQUE,'
                 . '  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                 . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }
        $this->sql->exec($sql);
    }

    /** @return array<string,bool> */
    private function appliedSet(): array
    {
        $this->ensureTable();
        $out = [];
        try {
            // fetchColumnAll() consumes & closes the cursor in a single
            // call — required to avoid SQLSTATE 2014 on the next exec().
            foreach ($this->sql->fetchColumnAll('SELECT migration FROM `' . self::MIGRATIONS_TABLE . '`') as $name) {
                if ($name !== '') {
                    $out[$name] = true;
                }
            }
        } catch (PDOException) {
            // Table missing on a host where ensureTable was vetoed by perms.
        }
        return $out;
    }

    private function recordApplied(string $name): void
    {
        // v1.8.27: driver-aware INSERT-OR-IGNORE. `INSERT IGNORE` is
        // MySQL-only; SQLite uses `INSERT OR IGNORE`.
        $driver = '';
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            // best-effort — default to MySQL
        }
        $sql = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO `' . self::MIGRATIONS_TABLE . '` (migration) VALUES (?)'
            : 'INSERT IGNORE INTO `' . self::MIGRATIONS_TABLE . '` (migration) VALUES (?)';
        // v1.8.29: route through the executor so the cursor is closed
        // unconditionally — direct `prepare()`+`execute()` left the
        // statement live on hosts whose PDO build did not honour
        // MYSQL_ATTR_USE_BUFFERED_QUERY from the option array,
        // tripping SQLSTATE 2014 on the very next migration step.
        try {
            $this->sql->runWrite($sql, [$name]);
        } catch (PDOException) {
            // Bookkeeping failure must not abort the apply path.
        }
    }

    /**
     * Apply a single migration file. $stmtIndex is set (by reference)
     * to the 1-based statement index that failed so the caller can
     * report a precise location in the JSON error payload.
     */
    private function applyOne(string $file, int &$stmtIndex): void
    {
        $sql = (string)file_get_contents($file);
        if (trim($sql) === '') {
            return;
        }
        $this->sql->execScript($sql, static function (int $idx) use (&$stmtIndex): void {
            $stmtIndex = $idx;
        });
    }

    /**
     * Statement splitter. Public for SchemaParser, SchemaManager and
     * SqlExecutor — all schema/migration paths share this one
     * implementation so quoted semicolons, line comments and block
     * comments are handled identically everywhere.
     *
     * @return array<int,string>
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($inLineComment) {
                $buf .= $c;
                if ($c === "\n") { $inLineComment = false; }
                continue;
            }
            if ($inBlockComment) {
                $buf .= $c;
                if ($c === '*' && $next === '/') {
                    $buf .= $next;
                    $i++;
                    $inBlockComment = false;
                }
                continue;
            }
            if (!$inSingle && !$inDouble && !$inBacktick) {
                if (($c === '-' && $next === '-') || $c === '#') {
                    $inLineComment = true;
                    $buf .= $c;
                    continue;
                }
                if ($c === '/' && $next === '*') {
                    $inBlockComment = true;
                    $buf .= $c;
                    continue;
                }
            }
            if ($c === "'" && !$inDouble && !$inBacktick) {
                // Handle SQL string escape '' (doubled single quote).
                if ($inSingle && $next === "'") {
                    $buf .= $c;
                    $buf .= $next;
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
                $buf .= $c;
                continue;
            }
            if ($c === '"' && !$inSingle && !$inBacktick) {
                if ($inDouble && $next === '"') {
                    $buf .= $c;
                    $buf .= $next;
                    $i++;
                    continue;
                }
                $inDouble = !$inDouble;
                $buf .= $c;
                continue;
            }
            if ($c === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
                $buf .= $c;
                continue;
            }
            if ($c === '\\' && ($inSingle || $inDouble) && $next !== '') {
                // Escape sequence inside a string literal — keep both bytes
                // so the next iteration cannot misread `\'` as a quote close.
                $buf .= $c;
                $buf .= $next;
                $i++;
                continue;
            }
            if ($c === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = $buf;
        }
        return $out;
    }

    private function isBenignSchemaError(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'already exists')
            || str_contains($msg, 'duplicate column')
            || str_contains($msg, 'duplicate key name')
            || str_contains($msg, 'duplicate entry')
            || str_contains($msg, 'check that column/key exists');
    }
}
