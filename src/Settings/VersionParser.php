<?php
/**
 * VersionParser: extract @table-version / @schema-version annotations
 * from SQL files.
 *
 * The RevoAp schema-versioning convention (v1.8.27+):
 *
 *   -- @schema-version 1.8.27
 *       Top-of-file marker on db/schema.sql declaring the overall
 *       application version that produced the consolidated schema.
 *
 *   -- @table-version <table_name> <semver>
 *       One per affected table. Used both in db/schema.sql (recording
 *       the version every CREATE TABLE block represents) and inside
 *       db/migrations/*.sql (declaring the version a migration
 *       advances each touched table to).
 *
 * The parser is intentionally lenient:
 *   - Comments are line- or block-style; only `-- ` lines are scanned
 *     because every existing migration / schema uses that style.
 *   - Lines are matched anywhere in the file — multiple version lines
 *     in one migration are all returned in order so the caller can
 *     pick the highest-precedence version per table.
 *   - Versions are returned as raw strings (e.g. "1.0.4", "1.8.27").
 *     Callers compare with {@see compare()} which understands semver
 *     ordering with numeric components.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Settings;

final class VersionParser
{
    /**
     * Parse a single SQL file. Returns `['schema' => ?string,
     * 'tables' => [name => version]]`. Missing/unreadable file yields
     * an empty result; the caller surfaces that as a diagnostic.
     *
     * If the same table is declared multiple times within the file the
     * LAST declaration wins — migrations occasionally repeat a version
     * line for clarity in multi-statement files, and "last" matches
     * what an operator would expect when reading top-to-bottom.
     *
     * @return array{schema:?string, tables:array<string,string>}
     */
    public static function parseFile(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return ['schema' => null, 'tables' => []];
        }
        return self::parse((string)file_get_contents($path));
    }

    /**
     * Parse a raw SQL string for version annotations.
     *
     * @return array{schema:?string, tables:array<string,string>}
     */
    public static function parse(string $sql): array
    {
        $schema = null;
        $tables = [];
        $lines = preg_split('/\R/u', $sql) ?: [];
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            // Match `-- @schema-version 1.8.27` (allow surrounding whitespace).
            if (preg_match('/--\s*@schema-version\s+([A-Za-z0-9._\-+]+)\s*$/i', $line, $m) === 1) {
                $schema = trim($m[1]);
                continue;
            }
            // Match `-- @table-version <name> <version>`.
            if (preg_match('/--\s*@table-version\s+([A-Za-z_][A-Za-z0-9_]*)\s+([A-Za-z0-9._\-+]+)\s*$/i', $line, $m) === 1) {
                $name = strtolower(trim($m[1]));
                $ver  = trim($m[2]);
                if ($name !== '' && $ver !== '') {
                    $tables[$name] = $ver;
                }
            }
        }
        return ['schema' => $schema, 'tables' => $tables];
    }

    /**
     * Parse every migration file in the given directory in lexical
     * order and reduce to a map of "highest declared version per
     * table". Files without annotations contribute nothing.
     *
     * @return array{schema:?string, tables:array<string,string>, files:array<int,array{name:string,schema:?string,tables:array<string,string>}>}
     */
    public static function parseMigrationsDir(string $dir): array
    {
        $out = ['schema' => null, 'tables' => [], 'files' => []];
        if ($dir === '' || !is_dir($dir)) {
            return $out;
        }
        $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $f) {
            $parsed = self::parseFile($f);
            $out['files'][] = [
                'name'   => basename($f),
                'schema' => $parsed['schema'],
                'tables' => $parsed['tables'],
            ];
            if ($parsed['schema'] !== null && ($out['schema'] === null || self::compare($parsed['schema'], $out['schema']) > 0)) {
                $out['schema'] = $parsed['schema'];
            }
            foreach ($parsed['tables'] as $name => $ver) {
                if (!isset($out['tables'][$name]) || self::compare($ver, $out['tables'][$name]) > 0) {
                    $out['tables'][$name] = $ver;
                }
            }
        }
        return $out;
    }

    /**
     * Resolve the "expected" version map. Combines the explicit
     * `@table-version` annotations in db/schema.sql with the fallback
     * `@schema-version` marker so every table defined in schema.sql
     * has a known expected version even if its block is missing the
     * per-table annotation. Schema annotations always win over the
     * fallback.
     *
     * @param  array<string,mixed> $parsedTables Output of {@see SchemaParser::parseFile()}
     * @return array{schema:?string, tables:array<string,string>}
     */
    public static function expectedFromSchema(string $schemaFile, array $parsedTables): array
    {
        $annotations = self::parseFile($schemaFile);
        $schemaVersion = $annotations['schema'];
        $tables = $annotations['tables'];
        // Fill in any table defined in schema.sql but not explicitly
        // versioned, using the file-level schema version as fallback.
        foreach (array_keys($parsedTables) as $name) {
            $key = strtolower((string)$name);
            if (!isset($tables[$key]) && $schemaVersion !== null) {
                $tables[$key] = $schemaVersion;
            }
        }
        return ['schema' => $schemaVersion, 'tables' => $tables];
    }

    /**
     * Compare two semver-ish strings. Numeric components compare
     * numerically; non-numeric trailing labels (pre-release tags) sort
     * lexically and rank lower than a release of the same numeric
     * prefix — behaviour consistent with the relevant subset of SemVer
     * 2.0.0 that RevoAp versions exercise.
     *
     * Returns < 0 if $a < $b, 0 if equal, > 0 if $a > $b.
     */
    public static function compare(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $partsA = self::splitVersion($a);
        $partsB = self::splitVersion($b);
        $len = max(count($partsA), count($partsB));
        for ($i = 0; $i < $len; $i++) {
            $pa = $partsA[$i] ?? '0';
            $pb = $partsB[$i] ?? '0';
            if (ctype_digit($pa) && ctype_digit($pb)) {
                $da = (int)$pa;
                $db = (int)$pb;
                if ($da !== $db) {
                    return $da <=> $db;
                }
                continue;
            }
            $cmp = strcmp($pa, $pb);
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return 0;
    }

    /**
     * @return array<int,string>
     */
    private static function splitVersion(string $v): array
    {
        // Split on `.`, `-`, `+` so "1.2.3-rc.1+build5" yields
        // ["1","2","3","rc","1","build5"].
        $parts = preg_split('/[.\-+]/', $v) ?: [];
        return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
    }
}
