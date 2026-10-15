<?php
/**
 * SchemaManager: drops & recreates RevoAp's tables from db/schema.sql.
 *
 * Safety constraints baked in:
 *   - Only drops tables that appear in db/schema.sql PLUS the
 *     `schema_migrations` tracker. Tables present in the live database
 *     that are NOT defined in schema.sql are left untouched by default.
 *   - Disables foreign-key checks briefly so the drop order does not
 *     matter on engines that enforce them.
 *   - Executes db/schema.sql verbatim, statement by statement, through
 *     {@see SqlExecutor::execScript()} so cursors are always closed
 *     between statements (MySQL otherwise rejects the next exec with
 *     SQLSTATE[HY000] 2014 — "Cannot execute queries while other
 *     unbuffered queries are active").
 *   - Re-applies every db/migrations/*.sql AND records them in
 *     schema_migrations so a freshly recreated DB lines up with the
 *     migration tracker that incremental upgrades use.
 *
 * Public API returns a structured report (dropped/created/skipped lists
 * plus any sanitized errors carrying phase / file / statement index /
 * SQLSTATE / driver code) so the caller can render an actionable UI
 * rather than a blank 500.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Settings;

use App\Core\ErrorDiagnostics;
use App\Core\Logger;
use PDO;
use PDOException;

final class SchemaManager
{
    private readonly SqlExecutor $sql;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $schemaFile,
        private readonly string $migrationsDir,
    ) {
        $this->sql = new SqlExecutor($pdo);
    }

    /**
     * Recreate the schema. By default only drops app-defined tables; if
     * $dropExtra is true, also drops any other table the live database
     * exposes (caller is responsible for confirming the user agreed).
     *
     * @return array<string,mixed>
     */
    public function recreate(bool $dropExtra = false): array
    {
        $report = [
            'schema_file'    => $this->schemaFile,
            'dropped_tables' => [],
            'kept_tables'    => [],
            'created_tables' => [],
            'migrations'     => [],
            'errors'         => [],
            'warnings'       => [],
        ];

        $schemaTables = SchemaParser::parseFile($this->schemaFile);
        if ($schemaTables === []) {
            $report['errors'][] = [
                'phase'   => 'parse',
                'message' => 'db/schema.sql could not be parsed (file missing, empty, or no CREATE TABLE blocks).',
            ];
            return $report;
        }

        $appTables = array_keys($schemaTables);
        $appTables[] = MigrationManager::MIGRATIONS_TABLE;
        $appTables = array_values(array_unique($appTables));

        try {
            $live = $this->listLiveTables();
        } catch (PDOException $e) {
            $report['errors'][] = $this->describeError('list_live_tables', $e, ['file' => 'schema.sql']);
            return $report;
        }
        $toDrop = [];
        $kept   = [];
        foreach ($live as $name) {
            if (in_array($name, $appTables, true)) {
                $toDrop[] = $name;
            } elseif ($dropExtra) {
                $toDrop[] = $name;
            } else {
                $kept[] = $name;
            }
        }

        $this->withFkChecksOff(function () use (&$report, $toDrop): void {
            foreach ($toDrop as $tbl) {
                try {
                    $safe = '`' . str_replace('`', '``', $tbl) . '`';
                    $this->sql->exec("DROP TABLE IF EXISTS {$safe}");
                    $report['dropped_tables'][] = $tbl;
                } catch (PDOException $e) {
                    $report['errors'][] = $this->describeError('drop', $e, ['table' => $tbl]);
                }
            }
        });

        $report['kept_tables'] = $kept;

        // Create from schema.sql via the safe executor so cursors close
        // between statements and the first sub-failure does not poison
        // the rest of the replay.
        $sql = (string)file_get_contents($this->schemaFile);
        if (trim($sql) === '') {
            $report['errors'][] = ['phase' => 'create', 'message' => 'schema.sql is empty'];
            return $report;
        }
        $lastIndex = 0;
        $createErrors = 0;
        foreach (MigrationManager::splitStatements($sql) as $rawStmt) {
            $trim = trim($rawStmt);
            if ($trim === '') {
                continue;
            }
            $effective = SqlExecutor::stripLeadingComments($trim);
            if ($effective === '') {
                continue;
            }
            $lastIndex++;
            try {
                $this->sql->exec($effective);
            } catch (PDOException $e) {
                $createErrors++;
                $report['errors'][] = $this->describeError('create', $e, [
                    'file'            => basename($this->schemaFile),
                    'statement_index' => $lastIndex,
                ]);
                // Continue — schema.sql is mostly idempotent (IF NOT
                // EXISTS) so a single failure should not abort the
                // whole replay. The caller still sees the error.
            }
        }

        try {
            $report['created_tables'] = $this->listLiveTables();
        } catch (PDOException $e) {
            $report['warnings'][] = 'list_created_tables_failed: ' . Logger::sanitizeScalar($e->getMessage());
        }

        // Baseline every existing migration so future "Apply Migrations"
        // does not try to rerun the file-level migrations against a DB
        // that already matches the consolidated schema.
        try {
            $mgr = new MigrationManager($this->pdo, $this->migrationsDir);
            $baseline = $mgr->baseline();
            $report['migrations'] = $baseline['baselined'] ?? [];
        } catch (\Throwable $e) {
            $report['warnings'][] = 'baseline_failed: ' . Logger::sanitizeScalar($e->getMessage());
        }

        // v1.8.27: seed app_config with the table versions advertised
        // by db/schema.sql so a freshly recreated DB immediately
        // matches the expected Check DB state. Falls back to the
        // top-of-file @schema-version for any table that lacks an
        // explicit @table-version annotation.
        try {
            $expected = VersionParser::expectedFromSchema($this->schemaFile, $schemaTables);
            $config = new ConfigRepository($this->pdo);
            $config->seedTableVersions($expected['tables'], 'schema');
            $config->setSchemaVersion($expected['schema']);
            $report['table_versions'] = $expected['tables'];
            $report['schema_version'] = $expected['schema'];
        } catch (\Throwable $e) {
            $report['warnings'][] = 'seed_versions_failed: ' . Logger::sanitizeScalar($e->getMessage());
        }

        if ($createErrors > 0) {
            $report['warnings'][] = "create_errors={$createErrors} — see report.errors for details";
        }

        return $report;
    }

    /**
     * @return array<int,string>
     */
    private function listLiveTables(): array
    {
        $driver = self::driverName($this->pdo);
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name",
            default  => 'SHOW TABLES',
        };
        $out = [];
        foreach ($this->sql->fetchColumnAll($sql) as $name) {
            $n = strtolower(trim((string)$name));
            if ($n !== '' && $n !== 'sqlite_sequence') {
                $out[] = $n;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    private function withFkChecksOff(callable $cb): void
    {
        $supportsFk = self::driverName($this->pdo) !== 'sqlite';
        if ($supportsFk) {
            try { $this->sql->exec('SET FOREIGN_KEY_CHECKS = 0'); } catch (\Throwable) {}
        }
        try {
            $cb();
        } finally {
            if ($supportsFk) {
                try { $this->sql->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable) {}
            }
        }
    }

    /**
     * @param  array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function describeError(string $phase, PDOException $e, array $extra = []): array
    {
        return array_merge([
            'phase'       => $phase,
            'sqlstate'    => is_string($e->errorInfo[0] ?? null) ? (string)$e->errorInfo[0] : '',
            'driver_code' => is_int($e->errorInfo[1] ?? null) ? (int)$e->errorInfo[1] : 0,
            'message'     => Logger::sanitizeScalar($e->getMessage()),
            'details'     => Logger::sanitize(ErrorDiagnostics::describeDatabaseError($e, array_merge(['operation' => 'recreate', 'phase' => $phase], $extra))),
        ], $extra);
    }

    private static function driverName(PDO $pdo): string
    {
        try {
            return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            return '';
        }
    }
}
