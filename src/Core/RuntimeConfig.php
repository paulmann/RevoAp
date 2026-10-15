<?php
/**
 * RuntimeConfig: persistent runtime overrides for config.php.
 *
 * Stores admin-editable settings in {root}/var/config/runtime.json so
 * config.php remains immutable and free of secrets. Loaded once during
 * Application boot and merged on top of the static config tree, with
 * connector API keys still resolved from .{provider}.key files when
 * present (those take priority over the JSON value).
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

final class RuntimeConfig
{
    public const FILE_RELATIVE = 'var/config/runtime.json';

    /** @var array<string,mixed>|null */
    private static ?array $data = null;
    private static ?string $rootDir = null;

    /**
     * Last failed write/read, in the shape the Settings API reports.
     *
     * Kept on the class rather than returned from every legacy call site
     * because `write()`/`update()`/`forget()` keep their old signatures for
     * the (many) callers that cannot act on a failure. Anything that CAN
     * act on one — the save endpoints, the actualization run — calls the
     * `*Checked()`/`*OrFail()` variants instead and never has to look here.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $lastWriteError = null;

    /** @var array<string,mixed>|null */
    private static ?array $lastReadError = null;

    /**
     * Load the overlay for `$rootDir`.
     *
     * A file that exists but cannot be read (permissions, a half-written
     * file, a corrupt JSON body) must NOT be treated as "no overrides":
     * doing so silently reverts every admin-saved setting to its
     * `config.php` default — the exact symptom of the reported "Save Models
     * resets the list to the shipped catalogue". The previous in-memory
     * state therefore survives a failed re-read inside one request, and
     * the failure is recorded so a save endpoint can refuse to claim
     * success.
     */
    public static function init(string $rootDir): void
    {
        self::$rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $result = self::readChecked();
        if ($result['ok']) {
            self::$data = $result['data'];
            self::$lastReadError = null;
            return;
        }
        self::$lastReadError = $result['error'];
        self::$data ??= [];
    }

    public static function rootDir(): string
    {
        return self::$rootDir ?? Config::rootDir();
    }

    public static function path(): string
    {
        return self::rootDir() . DIRECTORY_SEPARATOR . self::FILE_RELATIVE;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$data === null) {
            self::$data = self::read();
        }
        return self::$data;
    }

    /** Read from disk; never throws. Returns [] if missing/unreadable. */
    /** @return array<string,mixed> */
    public static function read(): array
    {
        $result = self::readChecked();
        if (!$result['ok']) {
            self::$lastReadError = $result['error'];
        }
        return $result['data'];
    }

    /**
     * Read the overlay and say whether the bytes on disk were actually
     * understood.
     *
     * Distinguishes the three states the old `read()` conflated:
     *   - `missing`           : no file yet — a valid empty overlay;
     *   - `unreadable`        : the file is there but this process cannot
     *                           get its bytes (the root-owned-file case);
     *   - `invalid_json`      : bytes present, no usable array.
     * Only the first is a reason to fall back to the shipped defaults.
     *
     * @return array{ok:bool,data:array<string,mixed>,error:array<string,mixed>|null,state:string}
     */
    public static function readChecked(): array
    {
        $file = self::path();
        $state = 'missing';
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                return self::readFailure('unreadable', $file, 'The file exists but this process cannot read it.');
            }
            if ($raw === '') {
                // An empty file is an empty overlay, not a broken one.
                return ['ok' => true, 'data' => [], 'error' => null, 'state' => 'empty'];
            }
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return self::readFailure('invalid_json', $file, 'The file is not valid JSON: ' . $e->getMessage());
            }
            if (!is_array($decoded)) {
                return self::readFailure('invalid_json', $file, 'The file must contain a JSON object.');
            }
            return ['ok' => true, 'data' => $decoded, 'error' => null, 'state' => 'ok'];
        }
        if (@file_exists($file)) {
            // exists but is_file() is false: a directory, a dangling link,
            // or a stat failure. Either way we cannot read an overlay.
            return self::readFailure('unreadable', $file, 'The path exists but is not a readable regular file.');
        }
        return ['ok' => true, 'data' => [], 'error' => null, 'state' => $state];
    }

    /**
     * @return array{ok:bool,data:array<string,mixed>,error:array<string,mixed>,state:string}
     */
    private static function readFailure(string $state, string $file, string $message): array
    {
        return [
            'ok'    => false,
            'data'  => [],
            'state' => $state,
            'error' => [
                'error'   => 'config_read_failed',
                'state'   => $state,
                'message' => $message,
                'target'  => StorageProbe::relative($file),
                'fs'      => StorageProbe::describe($file),
            ],
        ];
    }

    /** The last failed read, or null. @return array<string,mixed>|null */
    public static function lastReadError(): ?array
    {
        return self::$lastReadError;
    }

    /** The last failed write, or null. @return array<string,mixed>|null */
    public static function lastWriteError(): ?array
    {
        return self::$lastWriteError;
    }

    /** Forget the recorded write failure (tests, and a fresh save attempt). */
    public static function clearLastWriteError(): void
    {
        self::$lastWriteError = null;
    }

    /** Replace in-memory state; mostly for tests. @param array<string,mixed> $data */
    public static function replace(array $data): void
    {
        self::$data = $data;
    }

    /**
     * Persist state to disk with 0600 permissions. Returns true on success.
     *
     * Kept for the callers that cannot act on a failure; it records the
     * detail in {@see self::lastWriteError()}. New code should prefer
     * {@see self::writeChecked()}.
     *
     * @param array<string,mixed> $data
     */
    public static function write(array $data): bool
    {
        return self::writeChecked($data)['ok'];
    }

    /**
     * Persist state to disk and prove the bytes landed.
     *
     * "Proved" is literal: after the atomic rename the file is read back
     * and compared with the payload that was meant to be written. A rename
     * over a root-owned file, a full disk, a read-only mount or an
     * opendir/stat cache that hides the new inode all end up as `false`
     * here instead of the silent success the UI used to report as "Saved."
     *
     * On failure the in-memory state is deliberately left at its previous
     * value: a save that did not happen must not look saved to the rest of
     * the request either.
     *
     * @param  array<string,mixed> $data
     * @return array{ok:bool,error:array<string,mixed>|null,path:string,bytes:int}
     */
    public static function writeChecked(array $data): array
    {
        $file = self::path();
        $dir  = dirname($file);
        self::$lastWriteError = null;

        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            return self::writeFailure('mkdir', $file, 'The directory for the settings file could not be created.');
        }

        $payload = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            return self::writeFailure(
                'encode',
                $file,
                'The settings could not be encoded as JSON: ' . json_last_error_msg(),
            );
        }

        // Whatever the filesystem said a moment ago, say nothing now: the
        // verify step below has to see the file this write produced.
        @clearstatcache(true, $file);

        $tmp = $file . '.tmp';
        $written = @file_put_contents($tmp, $payload, LOCK_EX);
        if ($written === false || $written !== strlen($payload)) {
            @unlink($tmp);
            return self::writeFailure(
                'tmp_write',
                $file,
                'The settings could not be written to ' . StorageProbe::relative($tmp) . '.',
                ['bytes_expected' => strlen($payload), 'bytes_written' => is_int($written) ? $written : null],
            );
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return self::writeFailure('rename', $file, 'The temporary file could not be moved into place.');
        }
        @chmod($file, 0o600);

        // Verify: read the file back and compare byte-for-byte. This is the
        // difference between "we called a write function" and "the settings
        // are on disk".
        @clearstatcache(true, $file);
        $readBack = @file_get_contents($file);
        if ($readBack === false || $readBack !== $payload) {
            return self::writeFailure(
                'verify',
                $file,
                'The settings file was written but reading it back returned different bytes.',
                [
                    'bytes_expected' => strlen($payload),
                    'bytes_read'     => is_string($readBack) ? strlen($readBack) : null,
                    'sha_expected'   => hash('sha256', $payload),
                    'sha_read'       => is_string($readBack) ? hash('sha256', $readBack) : null,
                ],
            );
        }

        self::$data = $data;
        self::$lastReadError = null;
        return ['ok' => true, 'error' => null, 'path' => StorageProbe::relative($file), 'bytes' => strlen($payload)];
    }

    /**
     * Build (and remember) the structured failure for a write stage.
     *
     * @param  array<string,mixed> $extra
     * @return array{ok:bool,error:array<string,mixed>,path:string,bytes:int}
     */
    private static function writeFailure(string $stage, string $file, string $message, array $extra = []): array
    {
        $error = [
            'error'   => 'config_write_failed',
            'stage'   => $stage,
            'message' => $message,
            'target'  => StorageProbe::relative($file),
            'fs'      => StorageProbe::describe($file),
            'hint'    => 'Check that the PHP process owns (or can write) the settings file and its directory — '
                . 'a file left owned by root after a manual edit cannot be replaced by php-fpm.',
        ] + $extra;
        self::$lastWriteError = $error;
        return ['ok' => false, 'error' => $error, 'path' => $error['target'], 'bytes' => 0];
    }

    /**
     * Merge runtime overrides on top of the static config tree.
     *
     * @param  array<string,mixed> $base
     * @return array<string,mixed>
     */
    public static function merge(array $base): array
    {
        $overrides = self::all();
        if ($overrides === []) {
            return $base;
        }
        return self::deepMerge($base, $overrides);
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private static function deepMerge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k]) && self::isAssoc($a[$k]) && self::isAssoc($v)) {
                $a[$k] = self::deepMerge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    /** @param array<int|string,mixed> $a */
    private static function isAssoc(array $a): bool
    {
        if ($a === []) {
            return false;
        }
        return array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * Apply a set of fields (dot-notation keys allowed) on top of the
     * current overrides and persist. Returns the merged overrides.
     *
     * A failed write leaves the previous overrides untouched and the
     * failure on record ({@see self::lastWriteError()}); callers that must
     * not report success use {@see self::updateChecked()} instead.
     *
     * @param  array<string,mixed> $fields Dot-notation key => value pairs.
     * @return array<string,mixed>
     */
    public static function update(array $fields): array
    {
        return self::updateChecked($fields)['data'];
    }

    /**
     * {@see self::update()} with the outcome spelled out.
     *
     * @param  array<string,mixed> $fields
     * @return array{ok:bool,data:array<string,mixed>,error:array<string,mixed>|null}
     */
    public static function updateChecked(array $fields): array
    {
        $current = self::all();
        $next    = $current;
        foreach ($fields as $key => $value) {
            $next = self::setDot($next, (string)$key, $value);
        }
        $result = self::writeChecked($next);
        return [
            'ok'    => $result['ok'],
            // Not persisted => not in effect. Reporting $next here would be
            // the same lie the HTTP layer used to tell.
            'data'  => $result['ok'] ? $next : $current,
            'error' => $result['error'],
        ];
    }

    /**
     * {@see self::update()}, but a failure is an exception rather than a
     * silent no-op. Used where a half-applied write would corrupt state
     * (the model-actualization run writes the catalogue, the presets and
     * the pool in sequence and rolls back on failure).
     *
     * @param  array<string,mixed> $fields
     * @return array<string,mixed> The persisted overrides.
     * @throws ConfigWriteException
     */
    public static function updateOrFail(array $fields): array
    {
        $result = self::updateChecked($fields);
        if (!$result['ok']) {
            throw new ConfigWriteException($result['error'] ?? [
                'error'   => 'config_write_failed',
                'message' => 'The configuration file could not be written.',
            ]);
        }
        return $result['data'];
    }

    /**
     * @param  array<string,mixed> $tree
     * @return array<string,mixed>
     */
    public static function setDot(array $tree, string $key, mixed $value): array
    {
        $parts = explode('.', $key);
        $ref   = &$tree;
        foreach ($parts as $i => $p) {
            if ($i === count($parts) - 1) {
                $ref[$p] = $value;
                break;
            }
            if (!isset($ref[$p]) || !is_array($ref[$p])) {
                $ref[$p] = [];
            }
            $ref = &$ref[$p];
        }
        return $tree;
    }

    /**
     * Drop dot-notation keys from the overrides and persist.
     *
     * The counterpart of {@see self::update()}, and the only way to put a
     * value *back* to its `config.php` default rather than pin it to the
     * value it had at some point in the past. A rollback needs exactly
     * that: a key the run created an override for must stop being an
     * override, or the shipped default could never take effect again.
     *
     * A key that is not overridden is ignored, and a parent that ends up
     * empty is pruned — an empty `analyzer => []` left in the JSON would
     * shadow the whole `analyzer` block of `config.php` on merge, which is
     * worse than the override it replaced.
     *
     * @param array<int,string> $keys Dot-notation keys.
     */
    public static function forget(array $keys): void
    {
        self::forgetChecked($keys);
    }

    /**
     * {@see self::forget()} with the outcome spelled out. A no-op (no key
     * actually changed) counts as success — there was nothing to write.
     *
     * @param  array<int,string> $keys
     * @return array{ok:bool,data:array<string,mixed>,error:array<string,mixed>|null,changed:bool}
     */
    public static function forgetChecked(array $keys): array
    {
        $current = self::all();
        $next = $current;
        $changed = false;
        foreach ($keys as $key) {
            $before = $next;
            $next = self::unsetDot($next, (string)$key);
            if ($next !== $before) {
                $changed = true;
            }
        }
        if (!$changed) {
            return ['ok' => true, 'data' => $current, 'error' => null, 'changed' => false];
        }
        $result = self::writeChecked($next);
        return [
            'ok'      => $result['ok'],
            'data'    => $result['ok'] ? $next : $current,
            'error'   => $result['error'],
            'changed' => true,
        ];
    }

    /**
     * {@see self::forget()}, but a failure is an exception. Used by the
     * rollback path, where dropping an override and silently keeping it
     * are two very different states.
     *
     * @param  array<int,string> $keys
     * @throws ConfigWriteException
     */
    public static function forgetOrFail(array $keys): void
    {
        $result = self::forgetChecked($keys);
        if (!$result['ok']) {
            throw new ConfigWriteException($result['error'] ?? [
                'error'   => 'config_write_failed',
                'message' => 'The configuration file could not be written.',
            ]);
        }
    }

    /**
     * @param  array<string,mixed> $tree
     * @return array<string,mixed>
     */
    public static function unsetDot(array $tree, string $key): array
    {
        return self::removePath($tree, explode('.', $key));
    }

    /**
     * @param  array<string,mixed> $tree
     * @param  array<int,string>   $parts
     * @return array<string,mixed>
     */
    private static function removePath(array $tree, array $parts): array
    {
        $head = array_shift($parts);
        if ($head === null || !array_key_exists($head, $tree)) {
            return $tree;
        }
        if ($parts === []) {
            unset($tree[$head]);
            return $tree;
        }
        if (!is_array($tree[$head])) {
            return $tree;
        }
        $child = self::removePath($tree[$head], $parts);
        if ($child === []) {
            unset($tree[$head]);
            return $tree;
        }
        $tree[$head] = $child;
        return $tree;
    }

    /**
     * Best-effort secret file writer. Used for .{provider}.key files so
     * keys never end up in the JSON config nor in git. 0600 permissions
     * are applied; the failure detail is recorded and available from
     * {@see self::writeSecretFileChecked()} — a dropped API key reported as
     * "Saved" is the same class of bug as a dropped setting.
     */
    public static function writeSecretFile(string $name, string $value): bool
    {
        return self::writeSecretFileChecked($name, $value)['ok'];
    }

    /**
     * {@see self::writeSecretFile()} with the outcome spelled out.
     *
     * Reads the file back after writing for the same reason the settings
     * file is verified: the operator is about to be told their key is in
     * place, and the next request will authenticate with it.
     *
     * @return array{ok:bool,target:string,error:array<string,mixed>|null}
     */
    public static function writeSecretFileChecked(string $name, string $value): array
    {
        $value = trim($value);
        $file  = self::rootDir() . DIRECTORY_SEPARATOR . $name;
        if ($value === '') {
            // empty value = clear the secret
            if (is_file($file) && !@unlink($file)) {
                return ['ok' => false, 'target' => $name, 'error' => self::secretFailure(
                    $file,
                    'The key file could not be removed.',
                )];
            }
            return ['ok' => true, 'target' => $name, 'error' => null];
        }
        $payload = $value . "\n";
        @clearstatcache(true, $file);
        $written = @file_put_contents($file, $payload, LOCK_EX);
        if ($written === false || $written !== strlen($payload)) {
            return ['ok' => false, 'target' => $name, 'error' => self::secretFailure(
                $file,
                'The key file could not be written.',
            )];
        }
        @chmod($file, 0o600);
        $readBack = @file_get_contents($file);
        if ($readBack !== $payload) {
            return ['ok' => false, 'target' => $name, 'error' => self::secretFailure(
                $file,
                'The key file was written but reading it back returned different bytes.',
            )];
        }
        return ['ok' => true, 'target' => $name, 'error' => null];
    }

    /**
     * @return array<string,mixed>
     */
    private static function secretFailure(string $file, string $message): array
    {
        return [
            'error'   => 'config_write_failed',
            'stage'   => 'secret_file',
            'message' => $message,
            'target'  => StorageProbe::relative($file),
            'fs'      => StorageProbe::describe($file),
            'hint'    => 'The project root must be writable by the PHP process for .{provider}.key files. '
                . 'Alternatively export the key as {PROVIDER}_API_KEY in the environment.',
        ];
    }

    /**
     * Whether a secret file exists for the given provider slug.
     */
    public static function secretFileExists(string $provider): bool
    {
        $file = self::rootDir() . DIRECTORY_SEPARATOR . '.' . strtolower($provider) . '.key';
        return is_file($file) && filesize($file) > 0;
    }

    /** Test/utility: clear loaded state. */
    public static function reset(): void
    {
        self::$data    = null;
        self::$rootDir = null;
    }
}
