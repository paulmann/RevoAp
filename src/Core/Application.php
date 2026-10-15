<?php
/**
 * Application: Bootstrap container for RevoAp.
 *
 * Wires Config, Router, Logger, and lazily provides Database (resilient
 * PDO) + ConnectorRegistry. Acts as a tiny service locator; the public
 * API is read-only after boot.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.2.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Core;

use App\Connectors\ConnectorRegistry;
use PDO;

final class Application
{
    private readonly Router $router;
    private ?Database $db = null;
    private bool $dbAttempted = false;
    private ?ConnectorRegistry $connectors = null;

    /**
     * @param string $rootDir Project root absolute path.
     */
    public function __construct(public readonly string $rootDir)
    {
        Config::load($rootDir . '/config.php', $rootDir);
        // v1.8.47 — enable the durable database-resilience journal. Pinning
        // the root here (rather than letting the wrapper read ambient
        // config) is what keeps the counters off the filesystem in an
        // isolated unit test that never boots a full Application.
        DbResilienceJournal::init($rootDir);
        $tz = (string)Config::get('app.timezone', 'UTC');
        @date_default_timezone_set($tz);
        $this->router = new Router();
    }

    /** @return Router Public router instance — register routes here. */
    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Resilient Database wrapper. Returns null when database is not
     * configured or the initial connection fails. Long-running
     * pipeline runs use the wrapper's run() helper to survive idle
     * disconnects (MySQL "server has gone away").
     */
    public function database(): ?Database
    {
        if ($this->dbAttempted) {
            return $this->db;
        }
        $this->dbAttempted = true;
        $dsn  = (string)Config::get('database.dsn', '');
        $user = (string)Config::get('database.user', '');
        $pass = (string)Config::get('database.password', '');
        $opts = Config::get('database.options', []);
        if ($dsn === '') {
            Diagnostics::recordDatabaseError(null);
            return null;
        }
        $database = new Database($dsn, $user, $pass, is_array($opts) ? $opts : []);
        try {
            // Force a connection attempt so the diagnostic report
            // surfaces auth/host failures up front. If it fails we
            // log + return null so callers fall back to memory mode.
            $pdo = $database->pdo();
            // v1.8.29: belt-and-braces — ensure MYSQL_ATTR_USE_BUFFERED_QUERY
            // is on the live handle even if the connection option array
            // dropped it (operator override, stale .sql.php overlay,
            // PHP build that ignores the option-array entry). This is
            // the same SQLSTATE 2014 class of fault we hardened against
            // in v1.8.26 but applied at the bootstrap layer so every
            // PDO consumer benefits, not just the Settings paths.
            {
                // v2.0.0: PHP 8.5 deprecated PDO::MYSQL_ATTR_*; merely READING
                // the old constant emits E_DEPRECATED, so a `defined()` guard
                // does not help (it is true, and the next line raises).
                // PdoCompat resolves the constant once from whichever spelling
                // the runtime provides. setAttribute() raises on a driver that
                // does not support it, hence the catch.
                try {
                    $pdo->setAttribute(PdoCompat::mysqlBufferedQuery(), true);
                } catch (\Throwable) {
                    // Non-MySQL driver — attribute does not apply.
                }
            }
            $this->db = $database;
            return $this->db;
        } catch (\PDOException $e) {
            Diagnostics::recordDatabaseError($e);
            Logger::warn('Database connection failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Lazy PDO factory. Returns the live PDO from the resilient
     * Database wrapper (or null when no database is configured).
     */
    public function pdo(): ?PDO
    {
        $db = $this->database();
        if ($db === null) {
            return null;
        }
        try {
            return $db->pdo();
        } catch (\PDOException $e) {
            Diagnostics::recordDatabaseError($e);
            return null;
        }
    }

    /**
     * Connector registry, lazily constructed and cached.
     */
    public function connectors(): ConnectorRegistry
    {
        return $this->connectors ??= new ConnectorRegistry($this->rootDir . '/src/Connectors');
    }

    /**
     * Whether the request includes a `?creator` query parameter.
     */
    public function isCreatorRequest(): bool
    {
        return array_key_exists('creator', $_GET);
    }

    /**
     * Whether the request asks for the diagnostic report. Accepted forms:
     *   ?debug   ?debug=1   ?DEBUG=1
     * Only truthy non-zero values are honoured to keep the URL surface small.
     */
    public function isDebugRequest(): bool
    {
        foreach ($_GET as $key => $value) {
            if (strcasecmp((string)$key, 'debug') !== 0) {
                continue;
            }
            $v = is_scalar($value) ? trim((string)$value) : '';
        if ($v === '' || $v === '1' || strcasecmp($v, 'true') === 0 || strcasecmp($v, 'on') === 0 || strcasecmp($v, 'report') === 0 || strcasecmp($v, 'json') === 0) {
                return true;
            }
        }
        return false;
    }
}
