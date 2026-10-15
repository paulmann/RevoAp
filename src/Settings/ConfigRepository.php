<?php
/**
 * ConfigRepository: read/write helpers for the `app_config` system table.
 *
 * The `app_config` table is a generic key/value store used by:
 *   - schema-versioning: keys `table_version.<table>` and
 *     `schema.version` storing the live, applied semver of every
 *     RevoAp table.
 *   - (future) feature flags, runtime tunables not stored in
 *     config.php / runtime.json.
 *
 * This repository hides the schema and exposes the small set of
 * operations the rest of the codebase needs: ensure-table, upsert
 * single key, bulk upsert from a map, fetch one, fetch all. Every PDO
 * call routes through {@see SqlExecutor} so cursors close between
 * statements (otherwise MySQL throws SQLSTATE 2014 the moment we call
 * `seedTableVersions()` and follow it with another query).
 *
 * Failures are intentionally swallowed in the read paths so a missing
 * table never breaks UI code that just wants "what versions do we
 * have?". The write paths fail loudly so callers can surface a useful
 * error in the migration / recreate report.
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

use PDO;
use PDOException;

final class ConfigRepository
{
    public const TABLE = 'app_config';
    public const VERSION_PREFIX = 'table_version.';
    public const SCHEMA_KEY = 'schema.version';

    private readonly SqlExecutor $sql;

    public function __construct(private readonly PDO $pdo)
    {
        $this->sql = new SqlExecutor($pdo);
    }

    /**
     * Create the table lazily. Safe to call repeatedly; if the live
     * DB already has the table the statement is a no-op.
     */
    public function ensureTable(): void
    {
        $driver = $this->driver();
        if ($driver === 'sqlite') {
            // SQLite test driver: simplified schema (no ON UPDATE,
            // no engine/charset). Functionally equivalent for our
            // read/upsert workload.
            $sql = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
                 . '  id INTEGER PRIMARY KEY AUTOINCREMENT,'
                 . '  config_key TEXT NOT NULL UNIQUE,'
                 . '  config_value TEXT NULL,'
                 . "  scope TEXT NOT NULL DEFAULT 'app',"
                 . '  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
                 . ')';
        } else {
            $sql = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
                 . '  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
                 . '  config_key VARCHAR(191) NOT NULL UNIQUE,'
                 . '  config_value TEXT NULL,'
                 . "  scope VARCHAR(64) NOT NULL DEFAULT 'app',"
                 . '  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                 . '  INDEX idx_app_config_scope (scope)'
                 . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        $this->sql->exec($sql);
    }

    /**
     * True when the table exists in the live database. Read paths use
     * this to decide whether to query at all — calling `SELECT FROM
     * app_config` on a host that has not yet applied migration 007
     * raises a PDOException and pollutes logs.
     */
    public function tableExists(): bool
    {
        $driver = $this->driver();
        try {
            if ($driver === 'sqlite') {
                $name = $this->sql->fetchScalar(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='" . self::TABLE . "'"
                );
                return $name !== null && $name !== '';
            }
            $row = $this->sql->runRead(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                [self::TABLE],
            );
            return $row !== [];
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Read a single configuration value or null if missing / unset.
     * Never throws — a missing table simply returns null so banner
     * checks can keep working on a host mid-migration.
     */
    public function get(string $key): ?string
    {
        if (!$this->tableExists()) {
            return null;
        }
        try {
            $rows = $this->sql->runRead(
                'SELECT config_value FROM `' . self::TABLE . '` WHERE config_key = ? LIMIT 1',
                [$key],
            );
            if ($rows === []) {
                return null;
            }
            $val = $rows[0]['config_value'] ?? null;
            return $val === null ? null : (string)$val;
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Upsert a single key/value/scope triple. Throws PDOException so
     * the caller can record a structured error in the migration
     * report.
     */
    public function set(string $key, string $value, string $scope = 'app'): void
    {
        $this->ensureTable();
        $driver = $this->driver();
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO `' . self::TABLE . '` (config_key, config_value, scope) VALUES (?, ?, ?) '
                 . 'ON CONFLICT(config_key) DO UPDATE SET config_value = excluded.config_value, scope = excluded.scope';
        } else {
            $sql = 'INSERT INTO `' . self::TABLE . '` (config_key, config_value, scope) VALUES (?, ?, ?) '
                 . 'ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), scope = VALUES(scope)';
        }
        // v1.8.29: route through SqlExecutor::runWrite() so the cursor
        // is closed in a try/finally even when the driver leaves the
        // statement in an "executed but unread" state after the upsert.
        $this->sql->runWrite($sql, [$key, $value, $scope]);
    }

    /**
     * Delete a single key. No-op when the table is absent or the key
     * was never written, so callers can retire obsolete keys safely on
     * a host mid-migration.
     */
    public function delete(string $key): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $this->sql->runWrite(
            'DELETE FROM `' . self::TABLE . '` WHERE config_key = ?',
            [$key],
        );
    }

    /**
     * Fetch every table-version entry as a map. Keys are bare table
     * names (the `table_version.` prefix is stripped). Empty array
     * when the table is absent or empty.
     *
     * @return array<string,string>
     */
    public function tableVersions(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        try {
            $rows = $this->sql->fetchAll(
                'SELECT config_key, config_value FROM `' . self::TABLE . '` '
                . "WHERE config_key LIKE '" . self::VERSION_PREFIX . "%'"
            );
        } catch (PDOException) {
            return [];
        }
        $prefixLen = strlen(self::VERSION_PREFIX);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $k = (string)($r['config_key'] ?? '');
            if (!str_starts_with($k, self::VERSION_PREFIX)) {
                continue;
            }
            $name = substr($k, $prefixLen);
            $out[strtolower($name)] = (string)($r['config_value'] ?? '');
        }
        return $out;
    }

    /**
     * Bulk-write a map of table name => version into the
     * `table_version.*` namespace. Idempotent. Used by the migration
     * apply paths after every successful migration, and by
     * SchemaManager::recreate() to seed the table on a freshly
     * recreated DB.
     *
     * @param array<string,string> $versions
     */
    public function seedTableVersions(array $versions, string $scope = 'schema'): void
    {
        if ($versions === []) {
            return;
        }
        $this->ensureTable();
        foreach ($versions as $name => $ver) {
            $name = strtolower(trim($name));
            $ver  = trim($ver);
            if ($name === '' || $ver === '') {
                continue;
            }
            $this->set(self::VERSION_PREFIX . $name, $ver, $scope);
        }
    }

    /**
     * Record the top-of-file `@schema-version` value. Stored under the
     * `schema.version` key. No-op when $version is null/empty.
     */
    public function setSchemaVersion(?string $version): void
    {
        if ($version === null || $version === '') {
            return;
        }
        $this->ensureTable();
        $this->set(self::SCHEMA_KEY, $version, 'schema');
    }

    /**
     * v1.8.33 — UI preference helpers. One key lives under the `ui`
     * scope and configures the default theme for both the main app and
     * the public shared page:
     *
     *   ui.theme  ∈ {light, dark, system}      default: system
     *
     * "system" preserves the pre-1.8.33 behaviour (prefers-color-scheme
     * driven). All reads are read-through (no caching) — the table is
     * already cached at the PDO level and writes are rare.
     *
     * The layout-width preference (`ui.width`, narrow/standard/wide)
     * that shipped alongside this key was retired: the content column
     * is now sized by the window, not by a stored preference, so the
     * key drives nothing. `RETIRED_UI_WIDTH_KEY` survives only so
     * `purgeRetiredUiKeys()` can delete rows written by 1.8.33–1.8.47
     * builds; nothing reads it.
     */
    public const UI_THEME_KEY = 'ui.theme';

    /** Retired v1.8.33 layout-width key. Write-only reference, for cleanup. */
    public const RETIRED_UI_WIDTH_KEY = 'ui.width';

    /** @return array{theme:string} */
    public function getUiPreferences(): array
    {
        return ['theme' => self::normaliseTheme($this->get(self::UI_THEME_KEY))];
    }

    public function setUiTheme(string $theme): void
    {
        $normalised = self::normaliseTheme($theme);
        $this->set(self::UI_THEME_KEY, $normalised, 'ui');
    }

    /**
     * Drop the retired `ui.width` row if an earlier release wrote one.
     *
     * Called on every interface save so an upgraded install self-heals
     * without a migration: the value is inert (see the class-level note
     * above), and leaving it behind would advertise a setting that the
     * UI, the templates and the CSS no longer honour. A no-op on a
     * fresh install and on hosts where the table does not exist yet.
     */
    public function purgeRetiredUiKeys(): void
    {
        $this->delete(self::RETIRED_UI_WIDTH_KEY);
    }

    public static function normaliseTheme(?string $value): string
    {
        $v = strtolower(trim((string)($value ?? '')));
        // Default is "system" — preserves the pre-1.8.33 behaviour
        // (prefers-color-scheme driven, no forced theme). Operators
        // who want a forced default explicitly pick "light" or "dark".
        return in_array($v, ['light', 'dark', 'system'], true) ? $v : 'system';
    }

    private function driver(): string
    {
        try {
            return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            return '';
        }
    }
}
