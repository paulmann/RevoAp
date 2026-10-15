<?php
/**
 * RootFiles: stateless project-root file resolver and reader.
 *
 * Centralises how the application finds its "deployment-specific"
 * dot-files (e.g. `.admin.key`, `.url`, `.sql.php`, `.openrouter.key`)
 * across deployment layouts where the front controller is not
 * necessarily the canonical project root — most notably shared hosting
 * that serves out of `public_html/` with `index.php` and `config.php`
 * sitting next to the dot-files in the same directory.
 *
 * Why a dedicated helper: in production we observed `.admin.key`
 * present in `public_html/` (size > 0) but reported by `/api/health`
 * as `exists=true, readable=false, empty=true, configured=false`. The
 * root cause: hosts often disable / sandbox `is_readable()` while
 * still allowing `file_get_contents()` of the same path. The fix is to
 * stop trusting `is_readable()` as a gate and to attempt a controlled
 * read directly, capturing errors safely and reporting them in
 * diagnostics. This helper does both.
 *
 * Resolution order (first directory that contains the requested file
 * wins). When `requireFile` is null we instead pick the first
 * directory that looks like a project root (has `config.php` or
 * `index.php`):
 *
 *   1. Pinned root (test override).
 *   2. Directory of the `Config` file passed to `Config::load()`.
 *   3. `dirname(__DIR__, 2)` — canonical layout (src/Helpers → repo root).
 *   4. `realpath()` of (3) — symlink-aware.
 *   5. `$_SERVER['DOCUMENT_ROOT']` — typical Apache/Nginx layout
 *      (`public_html/`, `htdocs/`, etc.).
 *   6. Walk up from the bootstrap file looking for the project signature.
 *   7. `getcwd()` — last resort.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\Helpers;

use App\Core\Config;

final class RootFiles
{
    /** UTF-8 byte-order mark — stripped from text contents. */
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /** Optional pinned root used by tests / explicit overrides. */
    private static ?string $pinnedRoot = null;

    /**
     * Pin the project root used for resolution. Tests use this to point
     * at a temporary directory; production should rely on the automatic
     * Config / file-tree / DOCUMENT_ROOT walk.
     */
    public static function pinRoot(?string $rootDir): void
    {
        self::$pinnedRoot = $rootDir === null
            ? null
            : rtrim($rootDir, DIRECTORY_SEPARATOR);
    }

    /**
     * Read the pinned root currently in effect (if any). Mostly useful
     * to AdminAuth which exposes its own setRootDir() shim.
     */
    public static function pinnedRoot(): ?string
    {
        return self::$pinnedRoot;
    }

    /**
     * Best-effort project root for a request: the first candidate that
     * looks like a project root (has `config.php` or `index.php`). Falls
     * back to the canonical src-relative root so callers always have a
     * non-empty string to use.
     */
    public static function resolveRoot(): string
    {
        return self::resolveRootInfo(null)['path'];
    }

    /**
     * Resolve the project root for a specific dot-file: returns the
     * directory in which the file already exists. If no candidate
     * carries the file, returns the first candidate that looks like a
     * project root (has `config.php`/`index.php`). The reported
     * `source` helps operators verify that resolution picked the
     * directory they expected.
     *
     * @return array{path:string,source:string}
     */
    public static function resolveRootInfo(?string $requireFile): array
    {
        $candidates = self::candidates();
        if ($requireFile !== null && $requireFile !== '') {
            foreach ($candidates as $entry) {
                $path = $entry['path'];
                if ($path === '') {
                    continue;
                }
                $target = $path . DIRECTORY_SEPARATOR . $requireFile;
                if (@is_file($target)) {
                    return ['path' => $path, 'source' => $entry['source']];
                }
            }
        }
        foreach ($candidates as $entry) {
            $path = $entry['path'];
            if ($path === '') {
                continue;
            }
            if (@is_file($path . '/config.php') || @is_file($path . '/index.php')) {
                return ['path' => $path, 'source' => $entry['source']];
            }
        }
        $fallback = dirname(__DIR__, 2);
        return ['path' => $fallback, 'source' => 'fallback'];
    }

    /**
     * Read a dot-file from the resolved project root. Does NOT trust
     * `is_readable()` — many shared hosts return false while
     * `file_get_contents()` happily succeeds on the same path. Instead,
     * `is_file()` is used as the existence gate and the read attempt is
     * always performed when the file is present.
     *
     * Returns a structured result so callers can render diagnostics
     * without re-reading the file:
     *
     *   path        — absolute path attempted
     *   source      — which root-resolution strategy produced the dir
     *   exists      — file is present
     *   readable    — is_readable() outcome (recorded for ops, NOT used as gate)
     *   read_ok     — file_get_contents() returned a string
     *   raw         — raw bytes (or '' when read failed)
     *   contents    — normalized text (BOM/NUL stripped, trimmed)
     *   size        — stat() size in bytes (or null if unavailable)
     *   had_bom     — leading UTF-8 BOM detected
     *   read_error  — last PHP error message captured during the read,
     *                 or null on success
     *
     * @return array{
     *   path:string,
     *   source:string,
     *   exists:bool,
     *   readable:bool,
     *   read_ok:bool,
     *   raw:string,
     *   contents:string,
     *   size:int|null,
     *   had_bom:bool,
     *   read_error:string|null
     * }
     */
    public static function readDotFile(string $name): array
    {
        $info = self::resolveRootInfo($name);
        $root = $info['path'];
        $path = $root === '' ? $name : $root . DIRECTORY_SEPARATOR . $name;
        $exists = @is_file($path);
        // is_readable() is recorded for operator visibility but is NOT
        // used as a gate: shared hosts (particularly cPanel + suEXEC)
        // routinely return false while file_get_contents() succeeds.
        $readable = $exists && @is_readable($path);
        $size = null;
        $hadBom = false;
        $readOk = false;
        $raw = '';
        $contents = '';
        $readError = null;
        if ($exists) {
            $stat = @stat($path);
            if (is_array($stat) && isset($stat['size']) && is_int($stat['size'])) {
                $size = $stat['size'];
            }
            // Reset the last-error slot so error_get_last() after the
            // read only reports an error produced by THIS call. Without
            // this we'd surface stale warnings from earlier work as
            // bogus `read_error` fields on a successful read.
            if (function_exists('error_clear_last')) {
                error_clear_last();
            }
            $bytes = @file_get_contents($path);
            if (is_string($bytes)) {
                $readOk = true;
                $raw = $bytes;
                $hadBom = str_starts_with($bytes, self::UTF8_BOM);
                $contents = self::normalize($bytes);
            } else {
                $err = error_get_last();
                if (is_array($err) && isset($err['message']) && is_string($err['message']) && $err['message'] !== '') {
                    $readError = $err['message'];
                } else {
                    $readError = 'file_get_contents returned false';
                }
            }
        }
        return [
            'path'       => $path,
            'source'     => $info['source'],
            'exists'     => $exists,
            'readable'   => $readable,
            'read_ok'    => $readOk,
            'raw'        => $raw,
            'contents'   => $contents,
            'size'       => $size,
            'had_bom'    => $hadBom,
            'read_error' => $readError,
        ];
    }

    /**
     * Normalize raw text contents: strip BOMs and embedded NULs, then
     * trim ASCII whitespace + CR/LF/form-feed/vertical-tab. This is the
     * canonical form used to decide "empty?" and to match against a
     * presented value.
     */
    public static function normalize(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        foreach ([self::UTF8_BOM, "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($raw, $bom)) {
                $raw = substr($raw, strlen($bom));
                break;
            }
        }
        $raw = str_replace("\0", '', $raw);
        return trim($raw, " \t\n\r\0\x0B\x0C");
    }

    /**
     * Build the ordered list of project-root candidates, deduplicated
     * while preserving insertion order so the higher-priority source
     * always wins.
     *
     * @return list<array{path:string,source:string}>
     */
    public static function candidates(): array
    {
        $candidates = [];

        if (self::$pinnedRoot !== null && self::$pinnedRoot !== '') {
            $candidates[] = ['path' => self::$pinnedRoot, 'source' => 'pinned'];
        }

        try {
            $cfg = Config::rootDir();
            if ($cfg !== '') {
                $candidates[] = ['path' => rtrim($cfg, DIRECTORY_SEPARATOR), 'source' => 'config'];
            }
        } catch (\Throwable) {
            // Config not loaded yet — ignore.
        }

        $srcRelative = dirname(__DIR__, 2);
        if ($srcRelative !== '') {
            $candidates[] = ['path' => $srcRelative, 'source' => 'src-relative'];
            $real = @realpath($srcRelative);
            if (is_string($real) && $real !== '' && $real !== $srcRelative) {
                $candidates[] = ['path' => $real, 'source' => 'src-relative-realpath'];
            }
        }

        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (is_string($docRoot) && $docRoot !== '') {
            $candidates[] = ['path' => rtrim($docRoot, DIRECTORY_SEPARATOR), 'source' => 'document-root'];
        }

        // Walk up from this file looking for the project signature.
        // Bounded to 6 hops to avoid runaway loops.
        $dir = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            if (@is_file($parent . '/config.php') || @is_file($parent . '/index.php')) {
                $candidates[] = ['path' => $parent, 'source' => 'walk'];
                break;
            }
            $dir = $parent;
        }

        $cwd = @getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = ['path' => rtrim($cwd, DIRECTORY_SEPARATOR), 'source' => 'cwd'];
        }

        $seen = [];
        $unique = [];
        foreach ($candidates as $entry) {
            $key = $entry['path'];
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $entry;
        }
        return $unique;
    }
}
