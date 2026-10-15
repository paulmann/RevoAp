<?php
/**
 * PdoCompat: driver-specific PDO attribute constants across PHP versions.
 *
 * PHP 8.5 deprecated the `PDO::MYSQL_ATTR_*` / `PDO::PGSQL_ATTR_*` /
 * `PDO::SQLITE_ATTR_*` class constants and moved them onto the new
 * `Pdo\Mysql` / `Pdo\Pgsql` / `Pdo\Sqlite` classes. Merely *reading* the old
 * constant emits `E_DEPRECATED`, so the usual defensive pattern
 *
 *     if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) { … }
 *
 * does not help: `defined()` is true, and the very next line that uses the
 * constant raises the notice. On a busy console that is thousands of
 * deprecation records per hour in the error log and a permanently red
 * `error_log` for the operator.
 *
 * This class resolves the constant **once** per process, from whichever
 * spelling the running PHP provides, and never touches the deprecated name
 * when the new one exists. Callers use the returned int as an opaque key —
 * the numeric values are identical in both spellings, which is what keeps a
 * `runtime.json` written by one PHP version readable by another.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Core;

final class PdoCompat
{
    /**
     * Numeric values of the driver constants, as published by the PHP sources
     * (`ext/pdo_mysql/php_pdo_mysql.h`).
     *
     * Used ONLY when neither the 8.5 `Pdo\Mysql::ATTR_*` class nor the legacy
     * `PDO::MYSQL_ATTR_*` constant is available — i.e. a build compiled without
     * pdo_mysql, where setting the attribute is meaningless anyway but must
     * still not fatal, and where a wrong number would be invisible.
     *
     * The values are asserted against the live constants whenever pdo_mysql IS
     * loaded (tests/PdoCompatTest.php), so this table cannot silently rot the
     * way a hand-copied one does. It was wrong once already: FOUND_ROWS and
     * LOCAL_INFILE had been transposed, which on a pdo_mysql-less build would
     * have turned "return matched rows" into "allow LOAD DATA LOCAL INFILE" —
     * a security-relevant attribute, enabled by a typo.
     */
    private const FALLBACK = [
        'MYSQL_ATTR_USE_BUFFERED_QUERY' => 1000,
        'MYSQL_ATTR_LOCAL_INFILE'       => 1001,
        'MYSQL_ATTR_INIT_COMMAND'       => 1002,
        'MYSQL_ATTR_COMPRESS'           => 1003,
        'MYSQL_ATTR_FOUND_ROWS'         => 1004,
        'MYSQL_ATTR_IGNORE_SPACE'       => 1005,
        'MYSQL_ATTR_MULTI_STATEMENTS'   => 1012,
        'MYSQL_ATTR_SSL_VERIFY_SERVER_CERT' => 1013,
        // PDO::ATTR_DIRECT_QUERY is a CORE attribute (not a MYSQL_ATTR one) and
        // its value is 20. Listed here because callers reach for it as if it
        // were a MySQL attribute, and a wrong number in an options array key
        // silently collides with a different attribute instead of failing.
        'MYSQL_ATTR_DIRECT_QUERY'       => 20,
    ];

    /** @var array<string,int> resolved cache */
    private static array $resolved = [];

    private function __construct()
    {
    }

    /**
     * `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` without the 8.5 deprecation.
     *
     * Buffered queries are what lets RevoAp run a second query on the same
     * connection while a result set from the first is still open — the job
     * repository and the statistics aggregator both rely on it. Turning it
     * off produces "Cannot execute queries while other unbuffered queries
     * are active", which is why this attribute is set explicitly rather
     * than left to the driver default.
     */
    public static function mysqlBufferedQuery(): int
    {
        return self::mysql('ATTR_USE_BUFFERED_QUERY', 'MYSQL_ATTR_USE_BUFFERED_QUERY');
    }

    /** `PDO::MYSQL_ATTR_FOUND_ROWS` without the 8.5 deprecation. */
    public static function mysqlFoundRows(): int
    {
        return self::mysql('ATTR_FOUND_ROWS', 'MYSQL_ATTR_FOUND_ROWS');
    }

    /** `PDO::MYSQL_ATTR_INIT_COMMAND` without the 8.5 deprecation. */
    public static function mysqlInitCommand(): int
    {
        return self::mysql('ATTR_INIT_COMMAND', 'MYSQL_ATTR_INIT_COMMAND');
    }

    /**
     * Resolve any MySQL driver attribute by its 8.5 name.
     *
     * @param string $newName Constant name on `Pdo\Mysql`, e.g. `ATTR_COMPRESS`.
     * @param string $oldName Constant name on `PDO`, e.g. `MYSQL_ATTR_COMPRESS`.
     */
    public static function mysql(string $newName, string $oldName): int
    {
        $key = 'mysql:' . $newName;
        if (isset(self::$resolved[$key])) {
            return self::$resolved[$key];
        }

        // 1. The 8.5+ home. `class_exists` on a namespaced internal class is
        //    cheap and, crucially, does not touch the deprecated constant.
        if (class_exists(\Pdo\Mysql::class) && defined('\\Pdo\\Mysql::' . $newName)) {
            $value = (int)constant('\\Pdo\\Mysql::' . $newName);
            return self::$resolved[$key] = $value;
        }

        // 2. A pre-8.5 runtime where only the PDO:: spelling exists. Suppress
        //    the deprecation for this one read: on 8.4 and earlier there is
        //    no alternative, and the value is required.
        $legacy = '\\PDO::' . $oldName;
        if (defined($legacy)) {
            $value = (int)@constant($legacy);
            return self::$resolved[$key] = $value;
        }

        // 3. pdo_mysql not compiled in. Return the documented numeric value so
        //    callers can still build an options array; PDO will reject an
        //    unknown driver attribute at connect time with a clear message,
        //    which is more useful than a null propagating into an array key.
        return self::$resolved[$key] = self::FALLBACK[$oldName] ?? 0;
    }

    /**
     * True when the running PHP has the `Pdo\*` attribute classes (8.5+).
     * Used by the diagnostics panel to explain which spelling is in effect.
     */
    public static function hasModernDriverConstants(): bool
    {
        return class_exists(\Pdo\Mysql::class);
    }

    /**
     * True when a DSN targets MySQL/MariaDB.
     *
     * The driver attribute constants are MySQL-ONLY. Injecting
     * `MYSQL_ATTR_USE_BUFFERED_QUERY` into a `sqlite:` or `pgsql:` options
     * array makes PDO throw "Driver does not support this attribute" (or
     * silently ignore it, depending on the driver), so the caller has to know
     * the driver before it adds the option.
     *
     * Recognised by the DSN scheme rather than by querying the handle, because
     * every call site that needs this is building the options array for a
     * connection that does not exist yet.
     */
    public static function isMysqlDsn(string $dsn): bool
    {
        $scheme = strtolower(trim((string)(explode(':', $dsn, 2)[0] ?? '')));
        // `mysql` covers MySQL and MariaDB (MariaDB uses the mysql driver).
        return $scheme === 'mysql';
    }

    /**
     * The MySQL connection options RevoAp needs, or [] for any other driver.
     *
     * Buffered result sets are not a preference here: a partially iterated
     * SELECT leaves the connection in a state where the NEXT statement fails
     * with SQLSTATE[HY000] 2014 "Cannot execute queries while other unbuffered
     * queries are active". The job repository and the statistics aggregator
     * both issue a second query while a result set is open.
     *
     * @return array<int,bool>
     */
    public static function mysqlConnectionOptions(string $dsn): array
    {
        if (!self::isMysqlDsn($dsn)) {
            return [];
        }
        $attr = self::mysqlBufferedQuery();
        return $attr > 0 ? [$attr => true] : [];
    }

    /** @return array<string,int> the resolved cache, for diagnostics */
    public static function debugState(): array
    {
        return self::$resolved;
    }
}
