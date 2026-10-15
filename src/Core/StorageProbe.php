<?php
/**
 * StorageProbe: filesystem facts about a path we just failed to write.
 *
 * A save that did not land is only actionable if the operator is told
 * *why*. "Could not write runtime.json" is not a diagnosis; "the directory
 * is not writable, the file is owned by root with mode 0644 and the PHP
 * process runs as www-data" is. Every fact here is read with the same
 * (suppressed) calls the writer itself makes, so the report describes the
 * failing process, not an idealised one.
 *
 * Deliberately dependency-free and side-effect-free: it is called from
 * error paths, including ones that run while the disk is misbehaving, so
 * it must never throw and never write.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Core;

final class StorageProbe
{
    /**
     * Describe a target file (and its directory) as seen by this process.
     *
     * Paths are reported relative to the project root when they are inside
     * it, and by basename otherwise — the full server layout is not useful
     * to a remote consumer of the API response, and the operator knows
     * where their own project sits.
     *
     * @return array<string,mixed>
     */
    public static function describe(string $file): array
    {
        $dir = dirname($file);
        $info = [
            'target'         => self::relative($file),
            'directory'      => self::relative($dir),
            'dir_exists'     => @is_dir($dir),
            'dir_writable'   => @is_dir($dir) ? @is_writable($dir) : false,
            'dir_perms'      => self::perms($dir),
            'file_exists'    => @is_file($file),
            'file_writable'  => @is_file($file) ? @is_writable($file) : null,
            'file_readable'  => @is_file($file) ? @is_readable($file) : null,
            'file_perms'     => self::perms($file),
            'file_size'      => @is_file($file) ? (@filesize($file) ?: 0) : null,
            'file_owner'     => self::owner($file),
            'dir_owner'      => self::owner($dir),
            'process_user'   => self::processUser(),
            'disk_free'      => self::diskFree($dir),
            'php_last_error' => self::lastError(),
        ];
        return $info;
    }

    /**
     * The message the filesystem itself produced for the last failed call,
     * when PHP still has it. `file_put_contents()`/`rename()` report
     * "Permission denied" here on a root-owned file even though the return
     * value alone says only `false`.
     */
    public static function lastError(): ?string
    {
        $error = error_get_last();
        if (!is_array($error) || !isset($error['message'])) {
            return null;
        }
        return Logger::sanitizeScalar((string)$error['message']);
    }

    /** Octal permission string (`0644`) or null when stat fails. */
    public static function perms(string $path): ?string
    {
        $perms = @fileperms($path);
        if ($perms === false) {
            return null;
        }
        return substr(sprintf('%o', $perms), -4);
    }

    /**
     * Owner of the path, when the host exposes an ownership lookup. On
     * Windows and on hardened shared hosts nothing resolves, and the
     * directory/file mode plus the process user still carry the diagnosis.
     */
    public static function owner(string $path): ?string
    {
        if (!function_exists('fileowner') || !function_exists('posix_getpwuid')) {
            return null;
        }
        $uid = @fileowner($path);
        if ($uid === false) {
            return null;
        }
        $pw = @posix_getpwuid($uid);
        return is_array($pw) && isset($pw['name']) ? (string)$pw['name'] : 'uid:' . $uid;
    }

    /** The user the PHP process runs as — the other half of a mismatch. */
    public static function processUser(): ?string
    {
        $user = get_current_user();
        if (is_string($user) && $user !== '') {
            return $user;
        }
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name'])) {
                return (string)$pw['name'];
            }
        }
        return null;
    }

    /** Free bytes on the volume holding `$dir`, or null when unknown. */
    public static function diskFree(string $dir): ?int
    {
        if (!function_exists('disk_free_space')) {
            return null;
        }
        $free = @disk_free_space($dir);
        return is_float($free) || is_int($free) ? (int)$free : null;
    }

    /** Project-root-relative path when possible, basename otherwise. */
    public static function relative(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $root = '';
        try {
            $root = str_replace('\\', '/', RuntimeConfig::rootDir());
        } catch (\Throwable) {
            $root = '';
        }
        if ($root !== '' && str_starts_with($normalized, rtrim($root, '/') . '/')) {
            return substr($normalized, strlen(rtrim($root, '/')) + 1);
        }
        return basename($normalized);
    }
}
