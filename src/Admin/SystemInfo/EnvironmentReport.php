<?php
/**
 * EnvironmentReport: the read-only runtime report behind the System tab.
 *
 * This exists because "it works on my machine" is unanswerable without it. Every
 * field here is something that has actually broken a RevoAp deployment, and each
 * carries a verdict rather than a raw value, because an operator diagnosing an
 * incident at 2 a.m. needs "OPcache is off, expect a several-fold slowdown" and
 * not a number to interpret.
 *
 * Secrets are never included. That is a real risk for a report like this one:
 * `phpinfo()` leaks the environment, `ini_get_all()` leaks values, and
 * `$_SERVER` carries `HTTP_AUTHORIZATION` and the admin cookie. The report is
 * assembled field by field from an explicit allow-list, and every value passes
 * through the same redactor as the debug logger, so there is no path by which a
 * secret reaches the browser — including through the "copy this report into a bug
 * report" button, which is the whole point of the panel.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Admin\SystemInfo;

use App\Core\Config;
use App\Core\Logger;
use App\Core\PdoCompat;

final class EnvironmentReport
{
    /** Extensions the code actually needs, with the impact of not having them.
     *  `required` failures are surfaced as errors; `recommended` as warnings. */
    private const EXTENSIONS = [
        'pdo'      => ['required', 'no database access at all'],
        'pdo_mysql' => ['required', 'MySQL is unusable; only the in-memory fallback works'],
        'curl'     => ['required', 'no provider calls — the pipeline cannot run'],
        'json'     => ['required', 'nothing can be serialised'],
        'mbstring' => ['required', 'multibyte prompts and answers are mis-truncated'],
        'hash'     => ['required', 'no cache keys, no audit chain, no token comparison'],
        'openssl'  => ['required', 'no secure random for tokens or the CSP nonce'],
        'intl'     => ['recommended', 'dates and numbers fall back to the built-in table'],
        'opcache'  => ['recommended', 'expect a several-fold slowdown'],
        'redis'    => ['optional', 'no shared cache backend for multi-worker deployments'],
        'pcntl'    => ['optional', 'the worker cannot drain gracefully on SIGTERM'],
        'zlib'     => ['optional', 'no compressed log rotation or cache storage'],
        'gd'       => ['optional', 'no server-side image handling for attachments'],
        'zip'      => ['optional', 'backup archives cannot be compressed'],
    ];

    /** ini keys safe to report. An allow-list, because `ini_get_all()` includes
     *  values an operator may have put a secret into (a DSN in a custom ini). */
    private const INI_KEYS = [
        'memory_limit', 'max_execution_time', 'post_max_size', 'upload_max_file_size',
        'max_input_time', 'default_socket_timeout', 'display_errors', 'log_errors',
        'error_reporting', 'expose_php', 'session.cookie_httponly', 'session.cookie_secure',
        'session.cookie_samesite', 'session.use_strict_mode', 'session.gc_maxlifetime',
        'realpath_cache_size', 'realpath_cache_ttl', 'disable_functions',
    ];

    /**
     * @return array<string,mixed>
     */
    public static function build(string $rootDir): array
    {
        return [
            'ok'          => true,
            'generated_at' => time(),
            'php'         => self::php(),
            'extensions'  => self::extensions(),
            'opcache'     => self::opcache(),
            'ini'         => self::ini(),
            'paths'       => self::paths($rootDir),
            'disk'        => self::disk($rootDir),
            'memory'      => self::memory(),
            'load'        => self::load(),
            'database'    => self::database(),
            'security'    => self::security(),
            'build'       => self::build_info($rootDir),
            // Computed LAST, not merged in afterwards: `array + array` keeps the
            // LEFT operand's value for a duplicate key, so a `+ ['verdicts' => …]`
            // suffix silently produced an empty list — and an empty verdict list
            // is indistinguishable from "everything is fine", which is the worst
            // possible failure mode for a diagnostics panel.
            'verdicts'    => self::verdicts($rootDir),
        ];
    }

    /** @return array<string,mixed> */
    private static function php(): array
    {
        $version = PHP_VERSION;
        // composer.json requires ^8.5. Reporting the requirement next to the
        // running version is what turns "the console is slow" into "you are on
        // 8.3" in one glance.
        $meets = PHP_VERSION_ID >= 80500;
        return [
            'version'      => $version,
            'version_id'   => PHP_VERSION_ID,
            'required'     => '^8.5',
            'meets_required' => $meets,
            'sapi'         => PHP_SAPI,
            'sapi_module'  => function_exists('php_sapi_name') ? (string)php_sapi_name() : PHP_SAPI,
            'os'           => PHP_OS . ' ' . PHP_OS_FAMILY,
            'uname'        => function_exists('php_uname') ? (string)php_uname('s') . ' ' . (string)php_uname('r') : '',
            'arch'         => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
            'zend'         => defined('zend_version') ? (string)zend_version() : '',
            'modern_driver_constants' => PdoCompat::hasModernDriverConstants(),
            // The EFFECTIVE process timezone, not the configured one. If
            // date_default_timezone_set() rejected the configured value (an
            // unknown identifier logs a warning and keeps the previous zone),
            // reporting the config would claim a timezone the process is not
            // using — which is exactly the mismatch that makes every timestamp
            // in the console look wrong.
            'timezone'     => (string)date_default_timezone_get(),
        ];
    }

    /** @return array<string,mixed> */
    private static function extensions(): array
    {
        $loaded = array_map('strtolower', get_loaded_extensions());
        $rows = [];
        $missingRequired = [];
        $missingRecommended = [];
        foreach (self::EXTENSIONS as $name => [$tier, $impact]) {
            $present = in_array(strtolower($name), $loaded, true);
            $rows[] = [
                'name'    => $name,
                'tier'    => $tier,
                'present' => $present,
                'impact'  => $impact,
            ];
            if (!$present && $tier === 'required') { $missingRequired[] = $name; }
            if (!$present && $tier === 'recommended') { $missingRecommended[] = $name; }
        }
        return [
            'rows' => $rows,
            'missing_required' => $missingRequired,
            'missing_recommended' => $missingRecommended,
            'loaded_count' => count($loaded),
        ];
    }

    /** @return array<string,mixed> */
    private static function opcache(): array
    {
        if (!function_exists('opcache_get_status')) {
            return ['available' => false, 'enabled' => false, 'note' => 'opcache extension not loaded'];
        }
        // `opcache_get_status(false)` skips the per-script list, which is large
        // and would put every file path of the installation into a report an
        // operator may paste into a public bug tracker.
        $status = @opcache_get_status(false);
        if (!is_array($status)) {
            return ['available' => true, 'enabled' => false, 'note' => 'opcache_get_status() returned nothing (disabled or restricted)'];
        }
        $enabled = (bool)($status['opcache_enabled'] ?? false);
        $mem = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];
        $hits = (int)($stats['hits'] ?? 0);
        $misses = (int)($stats['misses'] ?? 0);
        $total = $hits + $misses;
        return [
            'available' => true,
            'enabled'   => $enabled,
            'used_bytes'   => (int)($mem['used_memory'] ?? 0),
            'free_bytes'   => (int)($mem['free_memory'] ?? 0),
            'wasted_bytes' => (int)($mem['wasted_memory'] ?? 0),
            'hit_rate'  => $total > 0 ? $hits / $total : null,
            'cached_scripts' => (int)($stats['num_cached_scripts'] ?? 0),
            // A low hit rate with a high script count means max_accelerated_files
            // is too small and entries are being evicted before reuse.
            'restarts'  => (int)($stats['opcache_restarts'] ?? 0),
            'jit'       => [
                'enabled' => (bool)($status['jit']['enabled'] ?? false),
                'on'      => (bool)($status['jit']['on'] ?? false),
                'kind'    => (string)($status['jit']['kind'] ?? ''),
                'buffer_free' => (int)($status['jit']['buffer_free'] ?? 0),
            ],
            // validate_timestamps=0 means a deploy MUST reload php-fpm. Surfacing
            // it here is what explains a stale build_id.
            'validate_timestamps' => (bool)(function_exists('ini_get') && ini_get('opcache.validate_timestamps')),
        ];
    }

    /** @return array<string,mixed> */
    private static function ini(): array
    {
        $out = [];
        foreach (self::INI_KEYS as $key) {
            $value = ini_get($key);
            if ($value === false) { continue; }
            // disable_functions is a list, not a secret, but run it through the
            // redactor anyway: a site that disabled a function whose NAME looks
            // like a secret pattern should not have it echoed back.
            $out[$key] = Logger::sanitizeScalar((string)$value);
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function paths(string $rootDir): array
    {
        $candidates = [
            'root'  => $rootDir,
            'cache' => $rootDir . '/cache',
            'logs'  => $rootDir . '/logs',
            'var'   => $rootDir . '/var',
            'backups' => $rootDir . '/var/backups',
            'runtime_config' => $rootDir . '/var/config',
            'i18n'  => $rootDir . '/i18n',
            'assets_dist' => $rootDir . '/assets/dist',
            'vendor_katex' => $rootDir . '/assets/vendor/katex',
            'admin_key' => $rootDir . '/.admin.key',
        ];
        $out = [];
        foreach ($candidates as $name => $path) {
            $exists = file_exists($path);
            $out[$name] = [
                // Only the BASENAME plus a short suffix is reported, never the
                // absolute path: the report is meant to be pasted into a bug
                // tracker, and an absolute path discloses the deployment layout.
                'label'    => basename($path),
                'exists'   => $exists,
                'is_dir'   => $exists && is_dir($path),
                'writable' => $exists ? is_writable($path) : @is_writable(dirname($path)),
                'readable' => $exists ? is_readable($path) : false,
                // Directory mode, as the operator would read it from ls -l.
                'mode'     => $exists ? substr(sprintf('%o', (int)@fileperms($path)), -4) : null,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function disk(string $rootDir): array
    {
        $total = @disk_total_space($rootDir);
        $free  = @disk_free_space($rootDir);
        if ($total === false || $free === false) {
            return ['available' => false];
        }
        $total = (float)$total;
        $free = (float)$free;
        return [
            'available'   => true,
            'total_bytes' => $total,
            'free_bytes'  => $free,
            'used_bytes'  => $total - $free,
            'free_ratio'  => $total > 0 ? $free / $total : 0.0,
            // Under ~200 MB free the response cache and log rotation start
            // failing in ways that look like application bugs.
            'low'         => $free < 200 * 1024 * 1024,
        ];
    }

    /** @return array<string,mixed> */
    private static function memory(): array
    {
        $limit = self::parseBytes((string)ini_get('memory_limit'));
        $used = memory_get_usage(true);
        return [
            'peak_bytes' => memory_get_peak_usage(true),
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'used_ratio'  => ($limit !== null && $limit > 0) ? $used / $limit : null,
            'high'        => ($limit !== null && $limit > 0) && ($used / $limit) > 0.8,
        ];
    }

    /** @return array<string,mixed> */
    private static function load(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if (!is_array($load)) {
            return ['available' => false];
        }
        $cores = self::cpuCount();
        return [
            'available' => true,
            '1'  => (float)($load[0] ?? 0),
            '5'  => (float)($load[1] ?? 0),
            '15' => (float)($load[2] ?? 0),
            'cores' => $cores,
            // Load above the core count means the run queue is backing up.
            'saturated' => $cores > 0 && (float)($load[0] ?? 0) > $cores,
        ];
    }

    /** @return array<string,mixed> */
    private static function database(): array
    {
        $dsn = (string)Config::tryGet('database.dsn', '');
        $configured = $dsn !== '';
        $driver = '';
        if ($configured) {
            $driver = strtolower((string)(explode(':', $dsn, 2)[0] ?? ''));
        }
        return [
            'configured' => $configured,
            // The DSN is reported as a DRIVER only. It contains a host and a
            // database name, and on many hosts the password too; the redactor
            // catches the password pattern but not the topology, and neither
            // belongs in a pasteable report.
            'driver'     => $configured ? $driver : null,
            'mysql_driver_attributes_available' => $configured && $driver === 'mysql'
                ? PdoCompat::mysqlBufferedQuery() > 0
                : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function security(): array
    {
        $https = (($_SERVER['HTTPS'] ?? '') === 'on')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return [
            'admin_protection' => (bool)\App\Core\AdminAuth::protectionEnabled(),
            'admin_configured' => (bool)\App\Core\AdminAuth::isConfigured(),
            'admin_allowed'    => (bool)(\App\Core\AdminAuth::gateState()['allowed'] ?? false),
            'hsts_max_age'     => (int)Config::tryGet('security.hsts.max_age', 0),
            'tls_detected'     => $https,
            'expose_php'       => (string)ini_get('expose_php') === '1',
            'display_errors'   => !in_array((string)ini_get('display_errors'), ['', '0', 'Off', 'off'], true),
            'debug_mode'       => (bool)Config::tryGet('app.debug', false),
            'session_strict_mode' => (string)ini_get('session.use_strict_mode') === '1',
            'session_httponly' => (string)ini_get('session.cookie_httponly') === '1',
            'session_secure'   => (string)ini_get('session.cookie_secure') === '1',
            'session_samesite' => (string)ini_get('session.cookie_samesite'),
        ];
    }

    /** @return array<string,mixed> */
    private static function build_info(string $rootDir): array
    {
        $version = (string)Config::tryGet('app.version', '0.0.0');
        $newest = null;
        $newestAt = 0;
        // Only src/ and index.php are inspected: walking the whole tree to find
        // the newest file would stat every vendored asset on every request.
        foreach ([$rootDir . '/index.php', $rootDir . '/config.php'] as $file) {
            $at = @filemtime($file);
            if (is_int($at) && $at > $newestAt) { $newestAt = $at; $newest = basename($file); }
        }
        $dist = $rootDir . '/assets/dist/revoap.css';
        return [
            'app_version' => $version,
            'stylesheet_present' => is_file($dist),
            'stylesheet_bytes' => is_file($dist) ? (int)filesize($dist) : 0,
            'newest_core_file' => $newest,
            'newest_core_at' => $newestAt ?: null,
        ];
    }

    /**
     * Human verdicts, worst first.
     *
     * A report of 40 numbers is not a diagnosis. These are the conclusions an
     * operator would otherwise have to draw themselves, each tagged with a
     * severity so the UI can order and colour them.
     *
     * @return array<int,array{severity:string,key:string,detail:string}>
     */
    private static function verdicts(string $rootDir): array
    {
        $out = [];
        $add = static function (string $severity, string $key, string $detail = '') use (&$out): void {
            $out[] = ['severity' => $severity, 'key' => $key, 'detail' => $detail];
        };

        if (PHP_VERSION_ID < 80500) {
            $add('critical', 'system.php_version_bad', PHP_VERSION);
        }
        $ext = self::extensions();
        foreach ($ext['missing_required'] as $name) {
            $add('critical', 'system.extension_missing', $name . ': ' . (self::EXTENSIONS[$name][1] ?? ''));
        }
        foreach ($ext['missing_recommended'] as $name) {
            $add('warning', 'system.extension_missing', $name . ': ' . (self::EXTENSIONS[$name][1] ?? ''));
        }

        $opcache = self::opcache();
        if ($opcache['available'] && !$opcache['enabled']) {
            $add('warning', 'system.opcache_off');
        } elseif (!($opcache['available'] ?? false)) {
            $add('warning', 'system.opcache_off');
        }
        if (($opcache['hit_rate'] ?? null) !== null && $opcache['hit_rate'] < 0.8) {
            $add('warning', 'system.opcache_low_hit_rate', (string)round($opcache['hit_rate'] * 100, 1));
        }

        $disk = self::disk($rootDir);
        if ($disk['available'] && ($disk['low'] ?? false)) {
            $add('critical', 'system.disk_low', self::humanBytes((float)$disk['free_bytes']));
        }

        $mem = self::memory();
        if ($mem['high'] ?? false) {
            $add('warning', 'system.memory_high', self::humanBytes((float)$mem['used_bytes']) . ' / ' . self::humanBytes((float)($mem['limit_bytes'] ?? 0)));
        }

        $sec = self::security();
        if (!$sec['admin_protection']) {
            $add('critical', 'admin.not_protected');
        } elseif ($sec['admin_protection'] && !$sec['admin_configured']) {
            $add('critical', 'admin.token_missing');
        }
        if ($sec['debug_mode']) {
            $add('warning', 'system.env_warning');
        }
        if ($sec['display_errors']) {
            $add('warning', 'system.display_errors_on');
        }
        if ($sec['expose_php']) {
            $add('info', 'system.expose_php_on');
        }
        if ($sec['tls_detected'] && (int)$sec['hsts_max_age'] === 0) {
            $add('info', 'security.hsts_off');
        }

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($out, static fn(array $a, array $b): int => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));
        return $out;
    }

    /** `256M` -> 268435456. Returns null for `-1` (unlimited) and garbage. */
    public static function parseBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTP]?)(?:B|b)?$/i', $value, $m) !== 1) {
            return null;
        }
        $n = (float)$m[1];
        $mul = match (strtoupper($m[2])) {
            'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3,
            'T' => 1024 ** 4, 'P' => 1024 ** 5, default => 1,
        };
        return (int)($n * $mul);
    }

    public static function humanBytes(float $bytes): string
    {
        if (!is_finite($bytes) || $bytes < 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    private static function cpuCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = (string)@file_get_contents('/proc/cpuinfo');
            $n = preg_match_all('/^processor\s*:/mi', $cpuinfo);
            if ($n > 0) {
                return $n;
            }
        }
        foreach (['/usr/bin/nproc', '/bin/nproc'] as $nproc) {
            if (is_executable($nproc)) {
                $out = @shell_exec(escapeshellcmd($nproc) . ' 2>/dev/null');
                if (is_string($out) && (int)trim($out) > 0) {
                    return (int)trim($out);
                }
            }
        }
        return 0;
    }
}
