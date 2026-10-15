<?php
/**
 * LogsService: read-only access to whitelisted log files.
 *
 * Only files explicitly registered in the whitelist (under the project
 * `logs/` directory by default) are exposed. Path traversal is rejected
 * at multiple layers:
 *   1. The requested name is matched against the whitelist (slug → path).
 *   2. The resolved real path MUST start with the project logs root.
 *
 * Returned lines are passed through `Logger::sanitizeScalar()` so API
 * keys, passwords, DSN credentials, Bearer tokens and absolute paths
 * never leave the server unredacted.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Logs;

use App\Core\Config;
use App\Core\Logger;

final class LogsService
{
    /** Maximum tail size the API accepts. Hard cap to bound memory + bandwidth. */
    public const TAIL_MAX_LINES   = 5000;
    public const TAIL_DEFAULT     = 500;

    /**
     * Legacy URL slugs accepted by the API for backward compatibility.
     * They are the pre-rename (OpenCorum era) names of the debug and
     * error logs and are mapped to the canonical RevoAp slugs below so
     * old bookmarks keep working. New code must use the canonical
     * slugs only; these aliases are documented legacy aliases.
     */
    private const LEGACY_SLUGS = [
        'opencorum-debug' => 'revoap-debug',
        'opencorum-error' => 'revoap-error',
    ];

    /**
     * Map a client-supplied slug to its canonical whitelist slug.
     * Returns null when the slug is neither whitelisted nor a known
     * legacy alias.
     */
    private function canonicalSlug(string $slug): ?string
    {
        if (isset($this->whitelist()[$slug])) {
            return $slug;
        }
        $legacy = self::LEGACY_SLUGS[$slug] ?? null;
        if ($legacy !== null && isset($this->whitelist()[$legacy])) {
            return $legacy;
        }
        return null;
    }

    public function __construct(
        private readonly string $rootDir,
    ) {}

    /**
     * Resolve the project logs directory. Falls back to {root}/logs when
     * `logging.path` is not set.
     */
    public function logsDir(): string
    {
        $configured = (string)Config::get('logging.path', '');
        $dir = $configured !== '' ? $configured : ($this->rootDir . DIRECTORY_SEPARATOR . 'logs');
        return rtrim($dir, '/\\');
    }

    /**
     * Whitelist of exposed log files. Each entry maps a stable slug
     * (used in URLs) to a relative filename inside the logs directory.
     * Adding a new file here is the ONLY way to expose it through the
     * API. The slug is what the client must send; it is not a path.
     *
     * @return array<string,array{slug:string,filename:string,label:string}>
     */
    public function whitelist(): array
    {
        return [
            'revoap-debug' => [
                'slug'     => 'revoap-debug',
                'filename' => 'revoap-debug.log',
                'label'    => 'RevoAp debug log',
            ],
            'revoap-error' => [
                'slug'     => 'revoap-error',
                'filename' => 'revoap-error.log',
                'label'    => 'RevoAp error log (always on)',
            ],
            'php-error' => [
                'slug'     => 'php-error',
                'filename' => 'php-error.log',
                'label'    => 'PHP error log',
            ],
            'access' => [
                'slug'     => 'access',
                'filename' => 'access.log',
                'label'    => 'Access log',
            ],
        ];
    }

    /**
     * @return list<array{slug:string,label:string,filename:string,exists:bool,size:int,mtime:?int,writable:bool}>
     */
    public function list(): array
    {
        $dir = $this->logsDir();
        $out = [];
        foreach ($this->whitelist() as $entry) {
            $path  = $dir . DIRECTORY_SEPARATOR . $entry['filename'];
            $real  = is_file($path) ? realpath($path) : false;
            $exists = $real !== false && $this->withinLogsDir($real, $dir);
            $size  = $exists ? (int)@filesize($real) : 0;
            $mtime = $exists ? (int)@filemtime($real) : null;
            $writable = $exists ? is_writable($real) : is_writable($dir);
            $out[] = [
                'slug'     => $entry['slug'],
                'label'    => $entry['label'],
                'filename' => $entry['filename'],
                'exists'   => $exists,
                'size'     => $size,
                'mtime'    => $mtime,
                'writable' => $writable,
            ];
        }
        return $out;
    }

    /**
     * Resolve a slug to its absolute file path. Returns null when the
     * slug is not whitelisted. The file may not exist on disk; callers
     * should branch on that explicitly. Path traversal is impossible:
     * the slug is the only client-supplied input and it is matched
     * exactly against the whitelist keys.
     */
    public function resolve(string $slug): ?string
    {
        $canonical = $this->canonicalSlug($slug);
        if ($canonical === null) {
            return null;
        }
        $entries = $this->whitelist();
        $dir  = $this->logsDir();
        $path = $dir . DIRECTORY_SEPARATOR . $entries[$canonical]['filename'];
        // Defensive: even though slug is whitelisted, ensure the resolved
        // path stays inside the logs directory (handles the rare case of
        // a symlinked filename pointing elsewhere).
        if (is_file($path)) {
            $real = realpath($path);
            if ($real === false || !$this->withinLogsDir($real, $dir)) {
                return null;
            }
            return $real;
        }
        return $path;
    }

    /**
     * Read the last $lines lines of a whitelisted log file. Output is
     * sanitized through Logger::sanitizeScalar so secrets are never
     * exposed.
     *
     * @return array{slug:string,filename:string,label:string,exists:bool,size:int,mtime:?int,lines:int,truncated:bool,content:string}|null
     */
    public function tail(string $slug, int $lines = self::TAIL_DEFAULT): ?array
    {
        $canonical = $this->canonicalSlug($slug);
        if ($canonical === null) {
            return null;
        }
        $entries = $this->whitelist();
        $entry = $entries[$canonical];
        $path  = $this->resolve($slug);
        if ($path === null) {
            return null;
        }
        $exists = is_file($path);
        $size   = $exists ? (int)@filesize($path) : 0;
        $mtime  = $exists ? (int)@filemtime($path) : null;
        $lines  = max(1, min(self::TAIL_MAX_LINES, $lines));

        $content   = '';
        $lineCount = 0;
        $truncated = false;
        if ($exists && $size > 0) {
            $rawLines = $this->tailLines($path, $lines);
            $truncated = count($rawLines) >= $lines && $size > 0;
            $sanitized = [];
            foreach ($rawLines as $line) {
                $sanitized[] = Logger::sanitizeScalar($line);
            }
            $content   = implode("\n", $sanitized);
            $lineCount = count($sanitized);
        }
        return [
            'slug'      => $entry['slug'],
            'filename'  => $entry['filename'],
            'label'     => $entry['label'],
            'exists'    => $exists,
            'size'      => $size,
            'mtime'     => $mtime,
            'lines'     => $lineCount,
            'truncated' => $truncated,
            'content'   => $content,
        ];
    }

    /**
     * Truncate (clear) a whitelisted log file. Returns true on success,
     * false when the slug is unknown OR the file cannot be written.
     * The file is created empty (with 0640 perms) when it did not exist
     * before — operators expect "Clear" to leave a fresh file.
     */
    public function clear(string $slug): bool
    {
        $path = $this->resolve($slug);
        if ($path === null) {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return false;
        }
        $bytes = @file_put_contents($path, '');
        if ($bytes === false) {
            return false;
        }
        @chmod($path, 0o640);
        return true;
    }

    /**
     * Read the last $lines lines of a file efficiently. Reads from the
     * end backwards in 8 KiB chunks so we never pull a multi-megabyte
     * file fully into memory.
     *
     * @return list<string>
     */
    private function tailLines(string $path, int $lines): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        try {
            $chunkSize = 8192;
            $position  = 0;
            $buffer    = '';
            $found     = 0;

            if (fseek($fh, 0, SEEK_END) !== 0) {
                return [];
            }
            $position = (int)ftell($fh);
            if ($position <= 0) {
                return [];
            }
            // We aim for $lines + 1 newlines so the trailing fragment
            // before the first newline can be discarded if needed.
            while ($position > 0 && $found <= $lines) {
                $read = (int)min($chunkSize, $position);
                $position -= $read;
                if (fseek($fh, $position, SEEK_SET) !== 0) {
                    break;
                }
                $chunk  = (string)fread($fh, $read);
                $buffer = $chunk . $buffer;
                $found  = substr_count($buffer, "\n");
            }
        } finally {
            fclose($fh);
        }
        // Normalize line endings (logs may have CRLF on some hosts).
        $buffer = str_replace("\r\n", "\n", $buffer);
        $buffer = rtrim($buffer, "\n");
        $all    = $buffer === '' ? [] : explode("\n", $buffer);
        if (count($all) > $lines) {
            $all = array_slice($all, -$lines);
        }
        return array_values($all);
    }

    private function withinLogsDir(string $real, string $dir): bool
    {
        $realDir = realpath($dir);
        if ($realDir === false) {
            return false;
        }
        return str_starts_with($real, rtrim($realDir, '/\\') . DIRECTORY_SEPARATOR)
            || $real === $realDir;
    }
}
