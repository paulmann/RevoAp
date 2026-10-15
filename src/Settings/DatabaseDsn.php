<?php
/**
 * DatabaseDsn: parse / build a MySQL PDO DSN.
 *
 * The Settings UI exposes individual fields (host, port, dbname, charset,
 * unix_socket) and the controller serializes them through here so users
 * never have to hand-craft a DSN. Test connection builds an ephemeral
 * PDO and reports a sanitized diagnostic.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Settings;

use App\Core\ErrorDiagnostics;
use App\Core\Logger;
use PDO;
use PDOException;

final class DatabaseDsn
{
    /**
     * Parse `mysql:host=...;port=...;dbname=...;charset=...` into fields.
     * Unrecognized DSNs return defaults so the form can still render.
     *
     * @return array{driver:string,host:string,port:int,dbname:string,charset:string,unix_socket:string}
     */
    public static function parse(string $dsn): array
    {
        $out = [
            'driver'      => 'mysql',
            'host'        => 'localhost',
            'port'        => 3306,
            'dbname'      => '',
            'charset'     => 'utf8mb4',
            'unix_socket' => '',
        ];
        $colon = strpos($dsn, ':');
        if ($colon === false) {
            return $out;
        }
        $out['driver'] = strtolower(substr($dsn, 0, $colon));
        $rest = substr($dsn, $colon + 1);
        if ($rest === '') {
            return $out;
        }
        foreach (explode(';', $rest) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $k = strtolower(trim($k));
            $v = trim($v);
            switch ($k) {
                case 'host':
                    $out['host'] = $v;
                    break;
                case 'port':
                    $out['port'] = (int)$v;
                    break;
                case 'dbname':
                    $out['dbname'] = $v;
                    break;
                case 'charset':
                    $out['charset'] = $v;
                    break;
                case 'unix_socket':
                    $out['unix_socket'] = $v;
                    break;
            }
        }
        return $out;
    }

    /**
     * Build a DSN from a component map. Empty fields are omitted.
     *
     * @param array{host?:string,port?:int|string,dbname?:string,charset?:string,unix_socket?:string,driver?:string} $parts
     */
    public static function build(array $parts): string
    {
        $driver = strtolower((string)($parts['driver'] ?? 'mysql'));
        $segments = [];
        $socket = trim((string)($parts['unix_socket'] ?? ''));
        if ($socket !== '') {
            $segments[] = 'unix_socket=' . $socket;
        } else {
            $host = trim((string)($parts['host'] ?? 'localhost'));
            if ($host !== '') {
                $segments[] = 'host=' . $host;
            }
            $port = (int)($parts['port'] ?? 0);
            if ($port > 0) {
                $segments[] = 'port=' . $port;
            }
        }
        $dbname  = trim((string)($parts['dbname'] ?? ''));
        $charset = trim((string)($parts['charset'] ?? ''));
        if ($dbname !== '') {
            $segments[] = 'dbname=' . $dbname;
        }
        if ($charset !== '') {
            $segments[] = 'charset=' . $charset;
        }
        return $driver . ':' . implode(';', $segments);
    }

    /**
     * Merge the settings form with the configured target: an empty form
     * field means "leave as configured" - which is exactly what the
     * password field's "(unchanged)" placeholder promises, and what the
     * rest of the form needs when the operator clears a field to have the
     * configured value tested instead.
     *
     * @param array<string,mixed> $form host/port/dbname/charset/unix_socket/user/password
     * @param array{dsn?:string,user?:string,password?:string} $config the already-merged configuration
     * @return array{dsn:string,user:string,password:string,sources:array{dsn:string,user:string,password:string}}
     */
    public static function mergeForm(array $form, array $config): array
    {
        $configured = self::parse((string)($config['dsn'] ?? ''));

        $text = static function (mixed $value): string {
            return is_string($value) ? trim($value) : '';
        };

        $socket = $text($form['unix_socket'] ?? '');
        if ($socket === '') {
            $socket = (string)$configured['unix_socket'];
        }
        $host = $text($form['host'] ?? '');
        $hostFromConfig = $host === '';
        if ($hostFromConfig) {
            $host = (string)$configured['host'];
        }

        $portRaw = $text($form['port'] ?? '');
        $port = $portRaw !== '' ? (int)$portRaw : (int)$configured['port'];
        if ($port <= 0) {
            $port = (int)$configured['port'];
        }

        $dbname = $text($form['dbname'] ?? '');
        $dbnameFromConfig = $dbname === '';
        if ($dbnameFromConfig) {
            $dbname = (string)$configured['dbname'];
        }

        $charset = $text($form['charset'] ?? '');
        if ($charset === '') {
            $charset = (string)$configured['charset'];
        }

        $user = $text($form['user'] ?? '');
        $userSource = 'form';
        if ($user === '') {
            $user = (string)($config['user'] ?? '');
            $userSource = 'config';
        }

        $password = (string)($form['password'] ?? '');
        $passwordSource = 'form';
        if ($password === '' || preg_match('/^[\*•]+$/u', $password) === 1) {
            $password = (string)($config['password'] ?? '');
            $passwordSource = $password === '' ? 'none' : 'config';
        }

        return [
            'dsn'      => self::build([
                'driver'      => 'mysql',
                'host'        => $host,
                'port'        => $port,
                'dbname'      => $dbname,
                'charset'     => $charset,
                'unix_socket' => $socket,
            ]),
            'user'     => $user,
            'password' => $password,
            'sources'  => [
                'dsn'      => ($hostFromConfig && $dbnameFromConfig) ? 'config' : 'form',
                'user'     => $userSource,
                'password' => $passwordSource,
            ],
        ];
    }

    /**
     * Test a candidate DSN by opening a short-lived connection and running
     * `SELECT 1`. Returns a structured diagnostic the controller forwards
     * to the UI.
     *
     * @return array{ok:bool,message:string,server_version?:string,details?:array<string,mixed>}
     */
    public static function test(string $dsn, string $user, string $password): array
    {
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 6,
        ];
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $opts[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }
        try {
            $pdo  = new PDO($dsn, $user, $password, $opts);
            $stmt = $pdo->query('SELECT 1');
            $row  = null;
            if ($stmt !== false) {
                try {
                    $row = $stmt->fetchColumn();
                } finally {
                    try { $stmt->closeCursor(); } catch (\Throwable) {}
                }
            }
            $ok   = (string)$row === '1';
            $version = '';
            try {
                $version = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            } catch (\Throwable) {
                // ignore
            }
            return [
                'ok'             => $ok,
                'message'        => $ok ? 'Connection OK' : 'Connection succeeded but SELECT 1 returned unexpected value',
                'server_version' => $version,
            ];
        } catch (PDOException $e) {
            $ctx = ErrorDiagnostics::describeDatabaseError($e, ['operation' => 'test_connection']);
            return [
                'ok'      => false,
                'message' => Logger::sanitizeScalar($e->getMessage()),
                'details' => Logger::sanitize($ctx),
            ];
        }
    }
}
