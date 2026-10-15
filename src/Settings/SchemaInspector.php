<?php
/**
 * SchemaInspector: compares the live database against db/schema.sql.
 *
 * Reads the parsed schema (SchemaParser) and reflects the live database
 * via INFORMATION_SCHEMA (MySQL) or PRAGMA (SQLite, for tests). Returns a
 * structured diff: missing tables, extra tables, missing columns,
 * incompatible columns. The check is intentionally lenient — it
 * normalises type names (e.g. `int unsigned` vs `int(10) unsigned`) so
 * the operator sees real divergences, not cosmetic noise.
 *
 * v1.8.26: every PDO read path is routed through {@see SqlExecutor},
 * which always closes the cursor. Previously the inspector mixed
 * `query()` + `fetchAll()` with `prepare()/execute()/fetchAll()` and
 * `query('SELECT DATABASE()')->fetchColumn()` without explicitly
 * closing — that mix triggers MySQL's "Cannot execute queries while
 * other unbuffered queries are active" once any code path partially
 * iterates a result set.
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

use App\Core\Logger;
use PDO;
use PDOException;

final class SchemaInspector
{
    private readonly SqlExecutor $sql;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $schemaFile,
    ) {
        $this->sql = new SqlExecutor($pdo);
    }

    /**
     * Build the diff. Never throws — errors are returned in the payload.
     *
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $schemaTables = SchemaParser::parseFile($this->schemaFile);
        $result = [
            'schema_file'         => self::projectRelative($this->schemaFile),
            'schema_readable'     => is_file($this->schemaFile) && is_readable($this->schemaFile),
            'schema_tables'       => array_keys($schemaTables),
            'live_tables'         => [],
            'missing_tables'      => [],
            'extra_tables'        => [],
            'table_diffs'         => [],
            'schema_ready'        => false,
            'driver'              => '',
            'database'            => '',
            'migrations'          => [],
            'errors'              => [],
            // v1.8.27 schema-version comparison (see VersionParser).
            'expected_versions'   => [],
            'live_versions'       => [],
            'version_mismatches'  => [],
            'expected_schema_version' => null,
            'live_schema_version'     => null,
            'config_table_present'    => false,
            'migration_needed'        => false,
        ];

        if ($schemaTables === []) {
            $result['errors'][] = 'db/schema.sql could not be parsed (file missing, empty, or no CREATE TABLE blocks).';
        }

        try {
            $result['driver'] = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            // best-effort
        }

        $liveTables = [];
        try {
            $liveTables = $this->listLiveTables();
        } catch (PDOException $e) {
            $result['errors'][] = 'list_tables_failed: ' . Logger::sanitizeScalar($e->getMessage());
            return $result;
        }
        $result['live_tables'] = $liveTables;

        try {
            $db = $this->sql->fetchScalar('SELECT DATABASE()');
            $result['database'] = $db ?? '';
        } catch (\Throwable) {
            // SQLite or insufficient privs — leave blank.
        }

        $schemaSet = array_fill_keys(array_keys($schemaTables), true);
        $liveSet   = array_fill_keys($liveTables, true);

        foreach ($schemaTables as $name => $desc) {
            if (!isset($liveSet[$name])) {
                $result['missing_tables'][] = $name;
                continue;
            }
            $diff = $this->diffTable($name, $desc);
            if ($diff !== null) {
                $result['table_diffs'][$name] = $diff;
            }
        }
        foreach ($liveTables as $name) {
            if (!isset($schemaSet[$name]) && $name !== MigrationManager::MIGRATIONS_TABLE) {
                $result['extra_tables'][] = $name;
            }
        }

        // v1.8.27: compare @table-version annotations from schema.sql
        // against the live versions persisted in app_config. Reported
        // separately from structural drift so the operator sees both:
        // "table exists but is at an older version" and "table missing".
        try {
            $expected = VersionParser::expectedFromSchema($this->schemaFile, $schemaTables);
            $result['expected_versions'] = $expected['tables'];
            $result['expected_schema_version'] = $expected['schema'];
            $config = new ConfigRepository($this->pdo);
            $result['config_table_present'] = $config->tableExists();
            $live = $config->tableVersions();
            $result['live_versions'] = $live;
            $result['live_schema_version'] = $config->get(ConfigRepository::SCHEMA_KEY);
            $mismatches = [];
            foreach ($expected['tables'] as $table => $expectedVer) {
                if (!isset($liveSet[$table])) {
                    // Already reported as missing_tables above; skip
                    // so the operator does not see the same problem
                    // twice in the UI.
                    continue;
                }
                $currentVer = $live[$table] ?? null;
                if ($currentVer === null) {
                    $mismatches[] = [
                        'table'             => $table,
                        'current_version'   => null,
                        'expected_version'  => $expectedVer,
                        'reason'            => 'no_config_entry',
                    ];
                    continue;
                }
                if (VersionParser::compare($currentVer, $expectedVer) !== 0) {
                    $mismatches[] = [
                        'table'             => $table,
                        'current_version'   => $currentVer,
                        'expected_version'  => $expectedVer,
                        'reason'            => VersionParser::compare($currentVer, $expectedVer) < 0 ? 'outdated' : 'ahead',
                    ];
                }
            }
            $result['version_mismatches'] = $mismatches;
        } catch (\Throwable $e) {
            $result['errors'][] = 'version_check_failed: ' . Logger::sanitizeScalar($e->getMessage());
        }

        $result['schema_ready'] = $result['missing_tables'] === []
            && $result['table_diffs'] === []
            && $result['version_mismatches'] === [];
        $result['migration_needed'] = !$result['schema_ready'];

        // Migrations status (best-effort).
        try {
            $migrationsDir = dirname($this->schemaFile) . '/migrations';
            $mgr = new MigrationManager($this->pdo, $migrationsDir);
            $result['migrations'] = $mgr->list();
        } catch (\Throwable $e) {
            $result['errors'][] = 'migrations_list_failed: ' . Logger::sanitizeScalar($e->getMessage());
        }

        return $result;
    }

    /**
     * @return array<int,string>
     */
    private function listLiveTables(): array
    {
        $driver = '';
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            // ignore
        }
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name",
            default  => 'SHOW TABLES',
        };
        $out = [];
        foreach ($this->sql->fetchColumnAll($sql) as $name) {
            $n = strtolower(trim((string)$name));
            if ($n !== '') {
                $out[] = $n;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param array<string,mixed> $desc
     * @return array<string,mixed>|null Returns null when table matches.
     */
    private function diffTable(string $table, array $desc): ?array
    {
        $liveCols = $this->liveColumns($table);
        if ($liveCols === null) {
            // Reflection unavailable (e.g. SQLite vs MySQL schema mismatch);
            // existence already verified, so treat as match.
            return null;
        }
        $missing = [];
        $incompatible = [];
        /** @var array<string, array<string,mixed>> $schemaCols */
        $schemaCols = $desc['columns'] ?? [];
        foreach ($schemaCols as $colName => $colDef) {
            if (!isset($liveCols[$colName])) {
                $missing[] = [
                    'column' => $colName,
                    'type'   => (string)($colDef['type'] ?? ''),
                ];
                continue;
            }
            $live = $liveCols[$colName];
            $issues = [];
            $expectedType = self::normaliseType((string)$colDef['type']);
            $liveType     = self::normaliseType((string)($live['type'] ?? ''));
            if ($expectedType !== $liveType && $expectedType !== '' && $liveType !== '') {
                $issues[] = "type expected={$expectedType} live={$liveType}";
            }
            $expectedNull = (bool)($colDef['nullable'] ?? true);
            $liveNull     = (bool)($live['nullable'] ?? true);
            if ($expectedNull !== $liveNull) {
                $issues[] = 'nullable expected=' . ($expectedNull ? 'YES' : 'NO')
                          . ' live=' . ($liveNull ? 'YES' : 'NO');
            }
            if ($issues !== []) {
                $incompatible[] = [
                    'column' => $colName,
                    'issues' => $issues,
                ];
            }
        }
        $extra = [];
        foreach (array_keys($liveCols) as $name) {
            if (!isset($schemaCols[$name])) {
                $extra[] = $name;
            }
        }
        if ($missing === [] && $incompatible === [] && $extra === []) {
            return null;
        }
        return [
            'missing_columns'      => $missing,
            'incompatible_columns' => $incompatible,
            'extra_columns'        => $extra,
        ];
    }

    /**
     * @return array<string, array<string,mixed>>|null
     */
    private function liveColumns(string $table): ?array
    {
        $driver = '';
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            // ignore
        }
        if ($driver === 'sqlite') {
            // SQLite stores everything as TEXT/INTEGER — skip type diffing
            // (return columns with empty type so only NULL/missing checks fire).
            try {
                $rows = $this->sql->fetchAll("PRAGMA table_info(`{$table}`)", PDO::FETCH_ASSOC);
                $cols = [];
                foreach ($rows as $row) {
                    if (!is_array($row) || !isset($row['name'])) { continue; }
                    $name = strtolower((string)$row['name']);
                    $cols[$name] = [
                        'type'     => strtolower((string)($row['type'] ?? '')),
                        'nullable' => ((int)($row['notnull'] ?? 0)) === 0,
                    ];
                }
                return $cols;
            } catch (\Throwable) {
                return null;
            }
        }
        // MySQL via INFORMATION_SCHEMA — fully materialise then close
        // before the next iteration runs another prepared SELECT.
        try {
            $rows = $this->sql->runRead(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE '
                . 'FROM INFORMATION_SCHEMA.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . 'ORDER BY ORDINAL_POSITION',
                [$table],
            );
            $cols = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['COLUMN_NAME'])) { continue; }
                $name = strtolower((string)$row['COLUMN_NAME']);
                $cols[$name] = [
                    'type'     => strtolower((string)($row['COLUMN_TYPE'] ?? '')),
                    'nullable' => strtoupper((string)($row['IS_NULLABLE'] ?? 'YES')) === 'YES',
                ];
            }
            return $cols;
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Normalise a MySQL column type so cosmetic differences ("int" vs
     * "int(10)") don't show up as schema drift. Lowercases, strips
     * column-display widths from integer types, collapses whitespace and
     * normalises comma spacing inside parenthesised groups (so
     * `enum('a', 'b')` compares equal to MySQL's canonical
     * `enum('a','b')`). Quote-aware — commas inside quoted ENUM/SET
     * values are never touched.
     */
    public static function normaliseType(string $type): string
    {
        $t = strtolower(trim($type));
        // Strip display widths from integer types: int(10) -> int.
        $t = (string)preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\s*\d+\s*\)/', '$1', $t);
        // Collapse whitespace runs.
        $t = (string)preg_replace('/\s+/', ' ', $t);
        return self::normaliseParenCommas($t);
    }

    /**
     * Remove spaces immediately after commas inside parentheses, keeping
     * string literals intact: `enum('pending', 'conflict')` becomes
     * `enum('pending','conflict')`, while a quoted value containing a
     * comma+space is preserved byte-for-byte.
     */
    private static function normaliseParenCommas(string $t): string
    {
        $out = '';
        $len = strlen($t);
        $depth = 0;
        $inSingle = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $t[$i];
            if ($inSingle) {
                $out .= $c;
                if ($c === "'") {
                    // Doubled '' stays inside the literal.
                    if ($i + 1 < $len && $t[$i + 1] === "'") {
                        $out .= $t[++$i];
                    } else {
                        $inSingle = false;
                    }
                }
                continue;
            }
            if ($c === "'") {
                $inSingle = true;
                $out .= $c;
                continue;
            }
            if ($c === '(') {
                $depth++;
                $out .= $c;
                continue;
            }
            if ($c === ')') {
                $depth = max(0, $depth - 1);
                $out .= $c;
                continue;
            }
            if ($depth > 0 && $c === ',') {
                $out .= ',';
                while ($i + 1 < $len && $t[$i + 1] === ' ') {
                    $i++;
                }
                continue;
            }
            $out .= $c;
        }
        return $out;
    }

    /**
     * Trim absolute paths down to the project-relative segment so they
     * don't leak filesystem layout.
     */
    private static function projectRelative(string $path): string
    {
        $root = \App\Core\Config::rootDir();
        if ($root !== '' && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/\\');
        }
        return basename($path);
    }
}
