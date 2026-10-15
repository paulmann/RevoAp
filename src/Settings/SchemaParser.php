<?php
/**
 * SchemaParser: extracts a structural snapshot of db/schema.sql.
 *
 * Parses the subset of MySQL DDL RevoAp's schema.sql actually uses —
 * CREATE TABLE blocks — into table descriptors with columns, primary
 * keys, unique keys, plain indexes and the table-level engine/charset.
 * The parser is intentionally lossy: it does not understand views,
 * triggers, stored procedures, partitions or foreign-key references.
 * Limitations are documented at module level so callers do not assume
 * full DDL coverage. The output is consumed by SchemaInspector to
 * compare against the live database via INFORMATION_SCHEMA.
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

final class SchemaParser
{
    /**
     * Parse the schema.sql file at the given absolute path. Returns a map
     * of table name (lower-case) => descriptor. Missing/unreadable file
     * yields an empty array; the caller surfaces that as a diagnostic.
     *
     * @return array<string, array{
     *   name: string,
     *   columns: array<string, array{
     *     name:string,type:string,nullable:bool,default:?string,extra:string,position:int
     *   }>,
     *   primary_key: array<int,string>,
     *   unique_keys: array<string, array<int,string>>,
     *   indexes: array<string, array<int,string>>,
     *   engine: string,
     *   charset: string,
     * }>
     */
    public static function parseFile(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [];
        }
        $sql = (string)file_get_contents($path);
        if ($sql === '') {
            return [];
        }
        return self::parse($sql);
    }

    /**
     * Parse a raw DDL string. Public for testing.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function parse(string $sql): array
    {
        $clean = self::stripComments($sql);
        $statements = MigrationManager::splitStatements($clean);
        $tables = [];
        foreach ($statements as $stmt) {
            $trim = trim($stmt);
            if ($trim === '') {
                continue;
            }
            if (!preg_match('/^CREATE\s+TABLE\b/i', $trim)) {
                continue;
            }
            $desc = self::parseCreateTable($trim);
            if ($desc === null) {
                continue;
            }
            $tables[strtolower($desc['name'])] = $desc;
        }
        return $tables;
    }

    /**
     * Strip line and block comments while preserving string literals.
     * Recognises -- line comments, # line comments and slash-star block
     * comments. Idempotent.
     */
    public static function stripComments(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBack   = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if (!$inSingle && !$inDouble && !$inBack) {
                if (($c === '-' && $next === '-') || $c === '#') {
                    // Skip to end of line.
                    while ($i < $len && $sql[$i] !== "\n") { $i++; }
                    $out .= "\n";
                    continue;
                }
                if ($c === '/' && $next === '*') {
                    $i += 2;
                    while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) { $i++; }
                    $i++;
                    continue;
                }
            }
            if ($c === "'" && !$inDouble && !$inBack) {
                $inSingle = !$inSingle;
            } elseif ($c === '"' && !$inSingle && !$inBack) {
                $inDouble = !$inDouble;
            } elseif ($c === '`' && !$inSingle && !$inDouble) {
                $inBack = !$inBack;
            }
            $out .= $c;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function parseCreateTable(string $stmt): ?array
    {
        if (!preg_match(
            '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z_][A-Za-z0-9_]*)`?\s*\((.*)\)\s*([^)]*)$/is',
            $stmt,
            $m
        )) {
            return null;
        }
        $name = $m[1];
        $body = $m[2];
        $tail = $m[3];

        $columns     = [];
        $primaryKey  = [];
        $uniqueKeys  = [];
        $indexes     = [];

        $lines = self::splitTopLevelCommas($body);
        $pos = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $upper = strtoupper($line);
            if (str_starts_with($upper, 'PRIMARY KEY')) {
                $primaryKey = self::extractColumnList($line);
                continue;
            }
            if (preg_match('/^UNIQUE\s/', $upper)) {
                $kn  = self::extractKeyName($line) ?? ('uq_' . count($uniqueKeys));
                $col = self::extractColumnList($line);
                $uniqueKeys[$kn] = $col;
                continue;
            }
            if (preg_match('/^(?:KEY|INDEX|FULLTEXT(?:\s+(?:KEY|INDEX))?)\s/', $upper)) {
                $kn  = self::extractKeyName($line) ?? ('idx_' . count($indexes));
                $col = self::extractColumnList($line);
                $indexes[$kn] = $col;
                continue;
            }
            if (str_starts_with($upper, 'CONSTRAINT') || str_starts_with($upper, 'FOREIGN KEY')) {
                // Foreign keys are intentionally ignored — see module docblock.
                continue;
            }
            // Column definition.
            $col = self::parseColumn($line, $pos);
            if ($col !== null) {
                $columns[strtolower($col['name'])] = $col;
                $pos++;
            }
        }

        $engine  = '';
        $charset = '';
        if (preg_match('/ENGINE\s*=\s*([A-Za-z0-9_]+)/i', $tail, $em)) {
            $engine = strtolower($em[1]);
        }
        if (preg_match('/CHARSET\s*=\s*([A-Za-z0-9_]+)/i', $tail, $cm)) {
            $charset = strtolower($cm[1]);
        }

        return [
            'name'        => $name,
            'columns'     => $columns,
            'primary_key' => $primaryKey,
            'unique_keys' => $uniqueKeys,
            'indexes'     => $indexes,
            'engine'      => $engine,
            'charset'     => $charset,
        ];
    }

    /**
     * Parse a single column definition line.
     *
     * The type keeps its parenthesised argument list AND trailing type
     * modifiers (UNSIGNED / ZEROFILL / BINARY), so `INT UNSIGNED
     * AUTO_INCREMENT PRIMARY KEY` yields type `int unsigned`, and
     * `DECIMAL(5,4)` / `ENUM('a','b')` keep their full forms. Column
     * attributes (AUTO_INCREMENT, PRIMARY KEY, NOT NULL, NULL, DEFAULT,
     * COMMENT, COLLATE, REFERENCES, ON UPDATE, GENERATED, UNIQUE) are
     * never part of the type.
     *
     * nullable follows MySQL semantics: an explicit `NOT NULL` wins;
     * otherwise an inline `PRIMARY KEY` or `AUTO_INCREMENT` implies
     * NOT NULL even when the DDL omits the clause; an explicit `NULL`
     * keeps the column nullable.
     *
     * @return array<string,mixed>|null
     */
    private static function parseColumn(string $line, int $pos): ?array
    {
        // Strip leading backticks around name.
        if (!preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s+(.*)$/s', $line, $m)) {
            return null;
        }
        $name = $m[1];
        $rest = trim($m[2]);

        // Inline PRIMARY KEY on the column (e.g. `id INT ... PRIMARY KEY`).
        $hasInlinePk = (bool)preg_match('/\bPRIMARY\s+KEY\b/i', $rest);
        $hasAutoIncrement = (bool)preg_match('/\bAUTO_INCREMENT\b/i', $rest);

        // Type: base name + optional parenthesised args + trailing type
        // modifiers. Everything after that is a column attribute.
        [$type, $remainder] = self::splitType($rest);

        $upper = strtoupper($remainder);
        $nullable = true;
        if (str_contains($upper, 'NOT NULL')) {
            $nullable = false;
        } elseif ($hasInlinePk || $hasAutoIncrement) {
            // MySQL semantics: PRIMARY KEY / AUTO_INCREMENT imply NOT
            // NULL even when the DDL omits the explicit clause.
            $nullable = false;
        } elseif (str_contains($upper, ' NULL') || str_starts_with($upper, 'NULL')) {
            $nullable = true;
        }

        $default = null;
        if (preg_match('/\bDEFAULT\s+((?:\'(?:[^\']|\'\')*\')|(?:CURRENT_TIMESTAMP(?:\([0-9]*\))?)|[A-Za-z0-9_.\-]+)/i', $remainder, $dm)) {
            $default = $dm[1];
            if (str_starts_with($default, "'") && str_ends_with($default, "'")) {
                $default = substr($default, 1, -1);
            }
        }

        $extra = '';
        if (preg_match('/\bAUTO_INCREMENT\b/i', $remainder)) { $extra .= 'auto_increment '; }
        if (preg_match('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', $remainder)) { $extra .= 'on_update_current_timestamp '; }
        $extra = trim($extra);

        return [
            'name'     => $name,
            'type'     => $type,
            'nullable' => $nullable,
            'default'  => $default === null ? null : (string)$default,
            'extra'    => $extra,
            'position' => $pos,
            'inline_primary_key' => $hasInlinePk,
        ];
    }

    /**
     * Split the leading type off a column definition.
     *
     * @return array{0:string,1:string} [type, remainder]
     */
    private static function splitType(string $rest): array
    {
        $len = strlen($rest);
        $i = 0;
        // Base type identifier.
        while ($i < $len && (ctype_alnum($rest[$i]) || $rest[$i] === '_')) {
            $i++;
        }
        if ($i === 0) {
            return ['', $rest];
        }
        $type = substr($rest, 0, $i);
        // Optional parenthesised argument list — quote-aware so ENUM/SET
        // string values containing parens or doubled quotes are not
        // miscounted as type-group delimiters.
        if ($i < $len && $rest[$i] === '(') {
            $depth = 0;
            $start = $i;
            $inSingle = false;
            while ($i < $len) {
                $c = $rest[$i];
                if ($c === "'") {
                    if ($inSingle && $i + 1 < $len && $rest[$i + 1] === "'") {
                        $i += 2;
                        continue;
                    }
                    $inSingle = !$inSingle;
                } elseif (!$inSingle) {
                    if ($c === '(') {
                        $depth++;
                    } elseif ($c === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $i++;
                            break;
                        }
                    }
                }
                $i++;
            }
            $type .= substr($rest, $start, $i - $start);
        }
        // Trailing type modifiers: UNSIGNED / ZEROFILL / BINARY extend
        // the type itself (ZEROFILL implies UNSIGNED and may follow it).
        while (true) {
            $j = $i;
            while ($j < $len && $rest[$j] === ' ') {
                $j++;
            }
            if (preg_match('/^(UNSIGNED|ZEROFILL|BINARY)\b/i', substr($rest, $j), $m)) {
                $type .= ' ' . strtolower($m[1]);
                $i = $j + strlen($m[1]);
                continue;
            }
            break;
        }
        return [strtolower($type), trim(substr($rest, $i))];
    }

    /**
     * Split a comma-separated list while respecting parentheses depth.
     *
     * @return array<int,string>
     */
    public static function splitTopLevelCommas(string $s): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $len = strlen($s);
        $inSingle = false;
        $inDouble = false;
        $inBack   = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === "'" && !$inDouble && !$inBack) { $inSingle = !$inSingle; }
            elseif ($c === '"' && !$inSingle && !$inBack) { $inDouble = !$inDouble; }
            elseif ($c === '`' && !$inSingle && !$inDouble) { $inBack = !$inBack; }
            elseif (!$inSingle && !$inDouble && !$inBack) {
                if ($c === '(') { $depth++; }
                elseif ($c === ')') { $depth--; }
                elseif ($c === ',' && $depth === 0) {
                    $out[] = $buf;
                    $buf = '';
                    continue;
                }
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = $buf;
        }
        return $out;
    }

    /**
     * @return array<int,string>
     */
    private static function extractColumnList(string $line): array
    {
        if (!preg_match('/\(([^)]*)\)/', $line, $m)) {
            return [];
        }
        $out = [];
        foreach (explode(',', $m[1]) as $part) {
            $part = trim($part);
            // Drop optional length (`(123)`) and ASC/DESC modifiers.
            $part = (string)preg_replace('/\([0-9]+\)/', '', $part);
            $part = (string)preg_replace('/\s+(ASC|DESC)\b/i', '', $part);
            $part = trim($part, " `\t\n");
            if ($part !== '') {
                $out[] = strtolower($part);
            }
        }
        return $out;
    }

    private static function extractKeyName(string $line): ?string
    {
        if (preg_match('/^(?:UNIQUE\s+KEY|UNIQUE\s+INDEX|UNIQUE|KEY|INDEX|FULLTEXT\s+KEY|FULLTEXT\s+INDEX)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $line, $m)) {
            return $m[1];
        }
        return null;
    }
}
