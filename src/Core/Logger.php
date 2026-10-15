<?php
/**
 * Logger: lightweight, sanitizing append-only logger.
 *
 * Writes one JSON-line per record:
 *   - `info`/`debug` levels go to {project_root}/logs/revoap-debug.log,
 *     gated by `app.debug` as before;
 *   - `warn`/`error` levels ALWAYS go to
 *     {project_root}/logs/revoap-error.log, independent of
 *     `app.debug`, so production failures stay visible even while
 *     verbose debug logging is off.
 * All values are sanitized through Logger::sanitize() so API keys, DSNs
 * with embedded credentials and absolute filesystem paths never reach disk.
 *
 * Failures are silent: logging must never become a fatal application error.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private static ?string $logFile = null;
    private static ?string $errorLogFile = null;
    private static ?bool   $enabled = null;

    /**
     * Per-request deduplication for noisy log lines. Some modules (the
     * resilient Database wrapper, the connector chain) emit the same
     * "connection failed" warning several times per request when each
     * dependent call retries. We collapse identical (level + message)
     * pairs within the lifetime of one PHP process so a single
     * connection problem produces ONE log line instead of dozens. The
     * original signal is preserved (first occurrence is written); the
     * suppressed count is appended to the first line's context the
     * first time we suppress a duplicate so operators don't lose
     * frequency information.
     *
     * @var array<string,int>
     */
    private static array $dedup = [];

    /**
     * Force-reset internal state. Test/utility only.
     */
    public static function reset(): void
    {
        self::$logFile      = null;
        self::$errorLogFile = null;
        self::$enabled      = null;
        self::$dedup        = [];
    }

    /**
     * Whether the debug (info/debug levels) logger will write to disk for
     * the current request. True iff app.debug is on AND the logs directory
     * is writable. The always-on error log (warn/error) does not use this
     * gate and resolves its own path lazily on first use.
     */
    public static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }
        if (!Config::isDebug()) {
            self::$enabled = false;
            return false;
        }
        $dir = self::resolvedLogDir();
        if ($dir === null) {
            self::$enabled = false;
            return false;
        }
        self::$logFile = $dir . DIRECTORY_SEPARATOR . 'revoap-debug.log';
        self::$enabled = true;
        return true;
    }

    /**
     * Resolve the logs directory (creating it when missing). Returns null
     * when the project root is unknown or the directory cannot be made
     * writable - every caller then degrades to silent logging.
     */
    private static function resolvedLogDir(): ?string
    {
        $root = Config::rootDir();
        if ($root === '') {
            return null;
        }
        $dir = (string)Config::get('logging.path', $root . '/logs');
        if (!is_string($dir) || $dir === '') {
            $dir = $root . '/logs';
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        return rtrim($dir, '/\\');
    }

    /**
     * Resolve the always-on error log path (warn/error levels). Lives in
     * the same logs directory as the debug log: {logs}/revoap-error.log.
     */
    private static function errorLogPath(): ?string
    {
        if (self::$errorLogFile !== null) {
            return self::$errorLogFile;
        }
        $dir = self::resolvedLogDir();
        if ($dir === null) {
            return null;
        }
        self::$errorLogFile = $dir . DIRECTORY_SEPARATOR . 'revoap-error.log';
        return self::$errorLogFile;
    }

    /**
     * Append a sanitized record. Never throws.
     *
     * @param array<string,mixed> $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $isErrorLevel = $level === 'warn' || $level === 'error';
        if ($isErrorLevel) {
            $target = self::errorLogPath();
            if ($target === null) {
                return;
            }
        } else {
            if (!self::enabled() || self::$logFile === null) {
                return;
            }
            $target = self::$logFile;
        }
        // Per-process deduplication: emit the first occurrence of a
        // (level + message) pair, count subsequent identical lines and
        // suppress them. The duplicate count is folded into the first
        // line's context only when the first duplicate is observed —
        // that keeps frequency visibility without spamming the file.
        $dedupKey = $level . '|' . $message;
        $count = (self::$dedup[$dedupKey] ?? 0) + 1;
        self::$dedup[$dedupKey] = $count;
        if ($count > 1) {
            return;
        }
        $record = [
            'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
            'level'   => $level,
            'message' => self::sanitizeScalar($message),
            'context' => self::sanitizeLogContext($context),
        ];
        try {
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        } catch (\Throwable) {
            return;
        }
        @file_put_contents($target, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Strict scrub for values that reach a LOG FILE.
     *
     * Free-form content keys (request/response bodies, raw model input or
     * output) are redacted wholesale: user or model text must never land
     * on disk, not even in sanitized form. Secret keys follow the same
     * redaction as sanitize(). All other scalars (error messages, class
     * names, ids, counters) keep the pattern-scrub so operators retain
     * actionable details in revoap-error.log.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function sanitizeLogContext(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            $key = (string)$k;
            if (self::isSecretKey($key) || self::isFreeTextKey($key)) {
                $out[$k] = '***redacted***';
                continue;
            }
            $out[$k] = is_array($v) ? self::sanitizeLogContext($v) : self::sanitize($v);
        }
        return $out;
    }

    /** Free-text content keys redacted wholesale in log context. */
    private static function isFreeTextKey(string $key): bool
    {
        $k = strtolower($key);
        return $k === 'query' || $k === 'answer' || $k === 'text'
            || $k === 'prompt' || $k === 'input' || $k === 'output'
            || $k === 'content' || $k === 'raw_content' || $k === 'payload'
            || $k === 'final_answer' || $k === 'request' || $k === 'response'
            || $k === 'statement' || $k === 'note';
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void  { self::log('info',  $message, $context); }
    /** @param array<string,mixed> $context */
    public static function warn(string $message, array $context = []): void  { self::log('warn',  $message, $context); }
    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void { self::log('error', $message, $context); }

    /**
     * Recursively sanitize a value before exposure or persistence.
     *
     * Removes anything that looks like an API key, password, DSN or absolute
     * filesystem path. The sanitizer is conservative — it prefers redaction
     * over leakage. Suitable for both log lines and HTTP debug payloads.
     *
     * @param  mixed $value
     * @return mixed
     */
    public static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (self::isSecretKey((string)$k)) {
                    $out[$k] = '***redacted***';
                    continue;
                }
                $out[$k] = self::sanitize($v);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::sanitizeScalar($value);
        }
        return $value;
    }

    private static function isSecretKey(string $key): bool
    {
        $k = strtolower($key);
        return $k === 'api_key'
            || $k === 'apikey'
            || $k === 'password'
            || $k === 'pass'
            || $k === 'pwd'
            || $k === 'secret'
            || $k === 'token'
            || $k === 'access_token'
            || $k === 'refresh_token'
            || $k === 'private_key'
            || $k === 'authorization'
            || $k === 'dsn';
    }

    /**
     * Sanitize a scalar string. Strips DSN passwords, absolute project paths,
     * and Bearer tokens. Idempotent and safe for repeated application.
     */
    public static function sanitizeScalar(string $s): string
    {
        // Bearer tokens
        $s = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ***redacted***', $s) ?? $s;

        // mysql:host=... ;password=secret -> ;password=***redacted***
        $s = preg_replace('/(password|pass|pwd)=([^;\s]+)/i', '$1=***redacted***', $s) ?? $s;

        // ?api_key=…  &token=…  query-string secrets in URLs.
        $s = preg_replace('/([?&](?:api_?key|access_token|token|secret|key))=([^&\s#]+)/i', '$1=***redacted***', $s) ?? $s;

        // sk-… style API keys appearing standalone in messages.
        $s = preg_replace('/\bsk-[A-Za-z0-9_\-]{16,}\b/', 'sk-***redacted***', $s) ?? $s;

        // Strip absolute filesystem paths down to a project-relative segment.
        $root = Config::rootDir();
        if ($root !== '') {
            $s = str_replace($root, '<project>', $s);
        }
        // Generic /home/<user>/... -> <home>/... fallback when root unknown.
        $s = preg_replace('#/home/[^/\s]+#', '<home>', $s) ?? $s;

        return $s;
    }
}
