<?php
/**
 * SqlExecutor: PDO helpers that never leave an unbuffered cursor open.
 *
 * MySQL's PDO driver throws SQLSTATE[HY000] 2014 — "Cannot execute
 * queries while other unbuffered queries are active" — whenever a
 * previous result set has not been fully consumed before the next
 * statement runs. The classic triggers in this codebase:
 *
 *   1. `$pdo->query(...)` followed by partial iteration (a `while
 *      ($row = $stmt->fetch())` loop that never reaches the closing
 *      `false`, or an early `break` from such a loop).
 *   2. `$pdo->query('SHOW TABLES')` whose iterator is still live when
 *      `SHOW CREATE TABLE …` runs inside the loop body (export path).
 *   3. INFORMATION_SCHEMA queries reused across multiple tables without
 *      `closeCursor()` between iterations.
 *
 * This helper centralises the patterns so the schema / migration code
 * never has to remember them:
 *
 *  - {@see fetchAll()}  — `query()` + `fetchAll()` + `closeCursor()` in a
 *                        try/finally. Returns an array; throws on driver
 *                        errors so callers can wrap in their own handler.
 *  - {@see fetchColumnAll()} — same but returns a flat list of the first
 *                        column. Used to enumerate tables / migration
 *                        names cheaply.
 *  - {@see runRead()}   — bind a prepared SELECT with parameters; same
 *                        guarantees around cursor finalisation.
 *  - {@see exec()}      — wraps `$pdo->exec()` so the executor exposes
 *                        a single execution surface; identical to the
 *                        native call but documented to NOT be used for
 *                        result-producing statements.
 *  - {@see execScript()} — split a SQL blob via {@see MigrationManager::splitStatements()}
 *                        and `exec` each non-empty statement, yielding a
 *                        callback-driven progress signal so callers can
 *                        report failure index / file / SQLSTATE / message
 *                        to the UI without raw 500 spew.
 *
 * The class is intentionally stateless and final; instantiate per call
 * with the live PDO. All helpers are guarded against the SQLite test
 * driver (no MySQL-specific attributes are ever inspected here).
 *
 * v1.8.29 — additional defense-in-depth: on construction we attempt to
 * flip `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` to true via
 * `setAttribute()` on the live handle. This guarantees buffered result
 * sets even when the connection was opened with an option array that
 * omitted the attribute (e.g. an operator-supplied
 * `database.options` map, or a stale .sql.php override that pre-dates
 * the v1.8.26 fix). Failures are swallowed because SQLite (used in
 * tests) and non-MySQL drivers reject the attribute. We also expose
 * {@see prepareWrite()} so writers (INSERT/UPDATE/DELETE/DDL with
 * params) route through the same close-cursor discipline as readers.
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

use PDO;
use PDOException;
use PDOStatement;

final class SqlExecutor
{
    public function __construct(private readonly PDO $pdo)
    {
        // v1.8.29: enforce buffered result sets at runtime so a PDO
        // handle opened without the attribute cannot trigger
        // SQLSTATE[HY000] 2014 mid-migration. The attribute is
        // MySQL-only; setAttribute() raises on SQLite/PostgreSQL so we
        // swallow the failure rather than gating on the driver name —
        // cheaper than a round-trip to read PDO::ATTR_DRIVER_NAME.
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            try {
                // PHP 8.5 deprecates the PDO::MYSQL_ATTR_* constant; same fallback as config.php.
                $this->pdo->setAttribute(
                    class_exists(\Pdo\Mysql::class)
                        ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY
                        : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,
                    true,
                );
            } catch (\Throwable) {
                // Non-MySQL driver or older PDO build — ignore.
            }
        }
    }

    /**
     * Run a parameterless SELECT and return all rows. Closes the cursor
     * unconditionally so the next statement on the same handle cannot
     * trip MYSQL_ERR 2014.
     *
     * @param  int $fetchMode One of PDO::FETCH_* (default ASSOC).
     * @return array<int, array<string,mixed>|list<mixed>|scalar>
     */
    public function fetchAll(string $sql, int $fetchMode = PDO::FETCH_ASSOC): array
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        try {
            $rows = $stmt->fetchAll($fetchMode);
            return is_array($rows) ? $rows : [];
        } finally {
            $this->closeQuietly($stmt);
        }
    }

    /**
     * Run a parameterless SELECT and return only the first column from
     * every row as a flat list (strings). Empty / NULL values are
     * preserved as empty strings to keep the result type stable.
     *
     * @return array<int, string>
     */
    public function fetchColumnAll(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        try {
            $out = [];
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            if (!is_array($rows)) {
                return [];
            }
            foreach ($rows as $row) {
                if (is_array($row) && array_key_exists(0, $row)) {
                    $out[] = (string)$row[0];
                }
            }
            return $out;
        } finally {
            $this->closeQuietly($stmt);
        }
    }

    /**
     * Fetch a single scalar from a parameterless SELECT (e.g. SELECT
     * DATABASE()). Returns null when there is no row or the column is
     * NULL. Always closes the cursor.
     */
    public function fetchScalar(string $sql): ?string
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return null;
        }
        try {
            $val = $stmt->fetchColumn();
            if ($val === false || $val === null) {
                return null;
            }
            return (string)$val;
        } finally {
            $this->closeQuietly($stmt);
        }
    }

    /**
     * Run a prepared SELECT with bound parameters and return all rows.
     * Closes the cursor unconditionally.
     *
     * @param  array<int|string,mixed> $params
     * @param  int $fetchMode
     * @return array<int, array<string,mixed>|list<mixed>|scalar>
     */
    public function runRead(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): array
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        try {
            $stmt->execute($params);
            $rows = $stmt->fetchAll($fetchMode);
            return is_array($rows) ? $rows : [];
        } finally {
            $this->closeQuietly($stmt);
        }
    }

    /**
     * Single-statement DDL/DML execution. Identical to `$pdo->exec` —
     * exposed here so all schema/migration call sites route through
     * the same surface.
     */
    public function exec(string $sql): int
    {
        $rows = $this->pdo->exec($sql);
        return $rows === false ? 0 : (int)$rows;
    }

    /**
     * Run a parameterised write (INSERT / UPDATE / DELETE / parameterised
     * DDL) and always close the cursor. v1.8.29 helper — previously
     * writers used `$pdo->prepare()` + `execute()` and relied on PHP's
     * implicit cursor finalisation, which on MySQL's unbuffered path
     * leaves the statement live until the PDOStatement is GC'd. The next
     * query on the same handle then trips SQLSTATE 2014 even though the
     * writer itself returns no rows.
     *
     * Returns the affected-row count. Swallows zero rows when the
     * driver does not expose `rowCount()` for the statement type.
     *
     * @param array<int|string,mixed> $params
     */
    public function runWrite(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            return 0;
        }
        try {
            $stmt->execute($params);
            try {
                return $stmt->rowCount();
            } catch (\Throwable) {
                return 0;
            }
        } finally {
            $this->closeQuietly($stmt);
        }
    }

    /**
     * Execute a SQL script statement by statement. Each statement is
     * split via {@see MigrationManager::splitStatements} so semicolons
     * inside string literals or comments do not chop the body. Comment-
     * only and whitespace-only statements are skipped without touching
     * the database.
     *
     * The optional $onStatement callback is invoked with the 1-based
     * statement index and the trimmed statement BEFORE execution; this
     * lets callers build a structured failure report (file + index +
     * SQLSTATE + message) without re-parsing the SQL.
     *
     * Returns the number of statements actually sent to the driver.
     *
     * @param callable(int $index, string $statement): void|null $onStatement
     */
    public function execScript(string $sql, ?callable $onStatement = null): int
    {
        $count = 0;
        $idx   = 0;
        foreach (MigrationManager::splitStatements($sql) as $stmt) {
            $trim = trim($stmt);
            if ($trim === '') {
                continue;
            }
            // Strip leading line comments so a statement that is purely
            // a comment block does not reach the driver.
            $effective = self::stripLeadingComments($trim);
            if ($effective === '') {
                continue;
            }
            $idx++;
            if ($onStatement !== null) {
                $onStatement($idx, $effective);
            }
            $this->exec($effective);
            $count++;
        }
        return $count;
    }

    /**
     * Close a statement's cursor, swallowing any driver error. Used
     * exclusively in `finally` blocks so a failed close never masks
     * the real exception the caller is propagating.
     */
    public function closeQuietly(?PDOStatement $stmt): void
    {
        if ($stmt === null) {
            return;
        }
        try {
            $stmt->closeCursor();
        } catch (PDOException) {
            // intentionally swallowed — see method docblock.
        }
    }

    /**
     * Strip leading `--` and `#` line comments and `/* … * /` block
     * comments from the start of a statement so the executor never
     * sends a comment-only payload to the driver.
     */
    public static function stripLeadingComments(string $stmt): string
    {
        $s = ltrim($stmt);
        $changed = true;
        while ($changed && $s !== '') {
            $changed = false;
            if (str_starts_with($s, '--') || str_starts_with($s, '#')) {
                $nl = strpos($s, "\n");
                $s = $nl === false ? '' : ltrim(substr($s, $nl + 1));
                $changed = true;
                continue;
            }
            if (str_starts_with($s, '/*')) {
                $end = strpos($s, '*/', 2);
                $s = $end === false ? '' : ltrim(substr($s, $end + 2));
                $changed = true;
            }
        }
        return $s;
    }
}
