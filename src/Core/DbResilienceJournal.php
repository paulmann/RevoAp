<?php
/**
 * DbResilienceJournal: small, durable record of database connection
 * losses and reconnects.
 *
 * {@see Database} keeps authoritative per-instance counters, but an
 * instance is per-request: the FPM process that serves
 * `/api/health/worker` is never the long-lived worker that actually
 * survived a MySQL restart. Without a durable trace the health snapshot
 * could only ever report "0 reconnects" from the web SAPI — which is
 * precisely the blind spot that let the production incident run for
 * hours with a permanently green worker indicator.
 *
 * This journal closes that gap. It is a single JSON document under
 * `runtime/`, following the same layout convention as
 * {@see \App\Core\RuntimeConfig} (`{root}/var/config/runtime.json`) and
 * {@see \App\Pipeline\RunControl} (`{base}/runtime/run-control/`).
 *
 * Guarantees:
 *   * counters are cumulative across processes and monotonic;
 *   * read-modify-write is serialised with an exclusive `flock`, so the
 *     worker and an FPM request cannot lose each other's increments;
 *   * every method is fail-soft — a missing directory, a read-only
 *     filesystem or a corrupt document degrades to zeros. Bookkeeping
 *     must never be the reason a query path fails;
 *   * no secrets: only the sanitized driver message and the error
 *     class/state/code are stored.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class DbResilienceJournal
{
    /** Path relative to the project root. */
    public const FILE_RELATIVE = 'runtime/db-resilience.json';

    /**
     * Longest sanitized error message persisted. The journal is a
     * diagnostic breadcrumb, not a log sink — a bounded field keeps the
     * document small enough to rewrite on every transition.
     */
    private const MAX_MESSAGE_CHARS = 300;

    private static ?string $rootDir = null;

    /** In-process memo of the last document read, avoids a stat per call. */
    private static ?array $memo = null;

    /**
     * Pin the project root.
     *
     * Persistence is OPT-IN: until a root is pinned the journal keeps its
     * counters in memory only. {@see Application} pins it at boot, so the
     * worker and the web front controller both get a durable journal — and
     * an isolated unit test that merely loads some config does not
     * silently create files in the source tree.
     */
    public static function init(string $rootDir): void
    {
        $normalized = rtrim($rootDir, "\\/");
        if ($normalized === '' || self::$rootDir === $normalized) {
            return;
        }
        self::$rootDir = $normalized;
        self::$memo    = null;
    }

    /** Pinned project root, or '' when persistence is not enabled. */
    public static function rootDir(): string
    {
        return self::$rootDir ?? '';
    }

    public static function path(): string
    {
        $root = self::rootDir();
        return $root === '' ? '' : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::FILE_RELATIVE);
    }

    /**
     * Current cumulative counters. Every key of {@see empty()} is
     * always present, so callers never need to guard with `??`.
     *
     * @return array<string,mixed>
     */
    public static function read(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $path = self::path();
        if ($path === '' || !is_file($path)) {
            return self::empty();
        }
        return self::$memo = self::decode((string)@file_get_contents($path));
    }

    /**
     * Record one recognised connection loss. The cumulative counter is
     * owned by this document (incremented per event), so several
     * processes — a worker plus any FPM request — accumulate correctly
     * instead of overwriting each other.
     *
     * @param array<string,mixed> $context Envelope produced by Database::noteLoss().
     */
    public static function recordLoss(array $context): void
    {
        self::mutate(static function (array $state) use ($context): array {
            $state['losses']           = (int)($state['losses'] ?? 0) + 1;
            $state['last_error_at']    = self::sanitizeShort($context['lost_at'] ?? null, 32)
                ?? gmdate('Y-m-d\TH:i:s\Z');
            $state['last_error']       = self::sanitizeMessage($context['message'] ?? null);
            $state['last_error_class'] = self::sanitizeShort($context['class'] ?? null, 64);
            $state['last_sqlstate']    = self::sanitizeShort($context['sqlstate'] ?? null, 16);
            $state['last_driver_code'] = is_int($context['driver_code'] ?? null) ? (int)$context['driver_code'] : null;
            return $state;
        });
    }

    /** Record one successful reconnect. */
    public static function recordReconnect(string $at, ?string $lastError): void
    {
        self::mutate(static function (array $state) use ($at, $lastError): array {
            $state['reconnects']        = (int)($state['reconnects'] ?? 0) + 1;
            $state['last_reconnect_at'] = $at;
            if (is_string($lastError) && $lastError !== '') {
                $state['last_error'] = self::sanitizeMessage($lastError);
            }
            return $state;
        });
    }

    /** Test/utility: drop the memo and the root pin. */
    public static function reset(): void
    {
        self::$rootDir = null;
        self::$memo    = null;
    }

    /**
     * Locked read-modify-write of the document. Fail-soft throughout.
     *
     * @param  callable(array<string,mixed>):array<string,mixed> $mutator
     */
    private static function mutate(callable $mutator): void
    {
        $path = self::path();
        if ($path === '') {
            // No project root (unit tests that never loaded config):
            // keep the counters in memory so intra-process reads stay
            // coherent, and skip I/O entirely.
            self::$memo = $mutator(self::read());
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!@flock($handle, LOCK_EX)) {
                return;
            }
            $raw   = (string)stream_get_contents($handle);
            $state = self::decode($raw);
            $state['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $state = $mutator($state);
            $json  = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            @ftruncate($handle, 0);
            @rewind($handle);
            @fwrite($handle, $json);
            @fflush($handle);
            self::$memo = $state;
        } catch (Throwable) {
            // Bookkeeping is advisory only.
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** @return array{reconnects:int,losses:int,last_error_at:?string,last_error:?string,last_reconnect_at:?string,updated_at:?string} */
    private static function empty(): array
    {
        return [
            'reconnects'        => 0,
            'losses'            => 0,
            'last_error_at'     => null,
            'last_error'        => null,
            'last_error_class'  => null,
            'last_sqlstate'     => null,
            'last_driver_code'  => null,
            'last_reconnect_at' => null,
            'updated_at'        => null,
        ];
    }

    /**
     * @param  raw-string $raw
     * @return array<string,mixed>
     */
    private static function decode(string $raw): array
    {
        $base = self::empty();
        if ($raw === '') {
            return $base;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $base;
        }
        return array_merge($base, $decoded);
    }

    private static function sanitizeMessage(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = Logger::sanitizeScalar((string)$value);
        if ($text === '') {
            return null;
        }
        return mb_strlen($text) > self::MAX_MESSAGE_CHARS
            ? mb_substr($text, 0, self::MAX_MESSAGE_CHARS)
            : $text;
    }

    private static function sanitizeShort(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = Logger::sanitizeScalar(trim((string)$value));
        if ($text === '') {
            return null;
        }
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
