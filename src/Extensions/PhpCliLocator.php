<?php
/**
 * PhpCliLocator - find a PHP **CLI** interpreter for MD.Viewer's updater.
 *
 * Why this exists (defect, 2026-09-26): the Extensions tab downloaded
 * updater.php fine and then "Install" failed with
 *
 *     The installer has no usable API_KEY in .md.ini
 *
 * which points at the key while the real problem was that the installer
 * never ran. Measured on the production host (PHP-FPM / FastPanel):
 *
 *     SAPI       = fpm-fcgi
 *     PHP_BINARY = /opt/php85/sbin/php-fpm   <- not an interpreter, it is the FPM master
 *     PHP_BINDIR = /opt/php85/bin
 *     [PHP_BINARY,     '-r', 'echo PHP_SAPI;'] -> exit 64, "Usage: php-fpm [-n] ..."
 *     [PHP_BINDIR/php, '-r', 'echo PHP_SAPI;'] -> exit 0,  "cli"
 *
 * MdViewerInstaller::runUpdater() spawned `PHP_BINARY`, so under FPM it
 * started php-fpm instead of running the installer: no .md.ini was written,
 * readApiKey() found nothing, and the operator was sent looking for a key.
 * On any CLI host PHP_BINARY *is* the CLI binary, which is exactly why the
 * local suite never noticed.
 *
 * The interpreter is therefore resolved *with a probe* rather than trusted:
 * candidates are tried in order (explicit config, then PHP_BINDIR/php, then
 * the usual system paths, then PHP_BINARY) and a candidate is accepted only
 * when `[$bin, '-r', 'echo PHP_SAPI;']` exits 0 and answers `cli`. The
 * accepted answer is memoised for the process, so the check costs at most
 * one subprocess per request instead of one per call site.
 *
 * Deliberately free of database, network and request state, so the whole
 * resolution is covered offline (tests/ExtensionsPhpCliTest.php) with a
 * substituted probe.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-26
 */
declare(strict_types=1);

namespace App\Extensions;

use App\Core\Config;
use App\Core\Logger;

final class PhpCliLocator
{
    /** The probe a candidate must survive. */
    public const PROBE_CODE = 'echo PHP_SAPI;';

    /** The only accepted probe answer. */
    public const CLI_SAPI = 'cli';

    /** config.php / .config.php key that may pin the interpreter path. */
    public const CONFIG_KEY = 'MdViewerPhpCli';

    /** Environment variable that may pin the interpreter path (wins over config). */
    public const ENV_KEY = 'REVOAP_MDV_PHP_CLI';

    /** Probe timeout in seconds - a wedged candidate must not stall the request. */
    public const PROBE_TIMEOUT = 5;

    public const SOURCE_ENV        = 'env';
    public const SOURCE_CONFIG     = 'config';
    public const SOURCE_PHP_BINDIR = 'php_bindir';
    public const SOURCE_USR_BIN    = 'usr_bin';
    public const SOURCE_USR_LOCAL  = 'usr_local_bin';
    public const SOURCE_PHP_BINARY = 'php_binary';
    public const SOURCE_OTHER      = 'other';

    /**
     * Candidate slots, in the order they are probed.
     *
     * @var list<array{path:string,source:string}>
     */
    private array $slots;

    /** @var callable(string):bool */
    private $probe;

    private ?string $resolvedPath = null;
    private ?string $resolvedSource = null;
    private bool $resolvedDone = false;

    /** Process-wide probe memo, so one request never probes the same path twice. */
    /** @var array<string,bool> */
    private static array $probeCache = [];

    /**
     * @param list<string|array{path:string,source?:string}> $candidates Tried in order.
     * @param callable(string):bool|null $probe Override for the probe (tests).
     *        Receives a candidate path, returns true when it is a CLI PHP.
     */
    public function __construct(array $candidates, ?callable $probe = null)
    {
        $this->slots = self::normalise($candidates);
        $this->probe = $probe ?? $this->defaultProbe(...);
    }

    /** Locator with the documented candidate order, config/env aware. */
    public static function defaults(): self
    {
        return new self(self::defaultSlots(self::configOverride(), self::envOverride()));
    }

    /**
     * The documented candidate list: an operator-supplied path first (env,
     * then config), then `PHP_BINDIR/php`, then the usual system locations,
     * and `PHP_BINARY` only as the last resort - under PHP-FPM that is
     * `/opt/php85/sbin/php-fpm`, which is the bug this class exists for.
     *
     * @return list<array{path:string,source:string}>
     */
    public static function defaultSlots(?string $configured = null, ?string $envValue = null): array
    {
        $slots = [];
        $env = $envValue ?? self::envOverride();
        if (is_string($env) && trim($env) !== '') {
            $slots[] = ['path' => trim($env), 'source' => self::SOURCE_ENV];
        }
        if (is_string($configured) && trim($configured) !== '') {
            $slots[] = ['path' => trim($configured), 'source' => self::SOURCE_CONFIG];
        }
        $bindir = defined('PHP_BINDIR') ? rtrim((string)PHP_BINDIR, "/\\") : '';
        if ($bindir !== '') {
            $slots[] = ['path' => $bindir . '/php', 'source' => self::SOURCE_PHP_BINDIR];
        }
        $slots[] = ['path' => '/usr/bin/php', 'source' => self::SOURCE_USR_BIN];
        $slots[] = ['path' => '/usr/local/bin/php', 'source' => self::SOURCE_USR_LOCAL];
        if (defined('PHP_BINARY') && (string)PHP_BINARY !== '') {
            $slots[] = ['path' => (string)PHP_BINARY, 'source' => self::SOURCE_PHP_BINARY];
        }
        return $slots;
    }

    /**
     * The first candidate that survived the probe, or null when this host has
     * no usable CLI interpreter at all. Memoised per instance.
     */
    public function resolve(): ?string
    {
        if ($this->resolvedDone) {
            return $this->resolvedPath;
        }
        $this->resolvedDone = true;
        foreach ($this->slots as $slot) {
            if (($this->probe)($slot['path'])) {
                $this->resolvedPath   = $slot['path'];
                $this->resolvedSource = $slot['source'];
                return $this->resolvedPath;
            }
        }
        return null;
    }

    /** Where the accepted candidate came from (one of the SOURCE_* constants). */
    public function source(): ?string
    {
        $this->resolve();
        return $this->resolvedSource;
    }

    /** Candidate paths, in probe order. */
    public function candidates(): array
    {
        $out = [];
        foreach ($this->slots as $slot) {
            $out[] = $slot['path'];
        }
        return $out;
    }

    /**
     * Probe one candidate. Uses the substituted probe when one was injected.
     */
    public function probe(string $binary): bool
    {
        return ($this->probe)($binary);
    }

    /**
     * The real probe: run `[$binary, '-r', 'echo PHP_SAPI;']` and accept the
     * candidate only when it exits 0 and answers `cli`. Memoised per process.
     */
    public function defaultProbe(string $binary): bool
    {
        $key = self::cacheKey($binary);
        if (array_key_exists($key, self::$probeCache)) {
            return self::$probeCache[$key];
        }
        $result = self::runProbe($binary);
        self::$probeCache[$key] = $result['ok'];
        return $result['ok'];
    }

    /**
     * Probe detail for diagnostics and error wording - what the candidate
     * actually answered. Not memoised beyond the boolean answer.
     *
     * @return array{ok:bool,exit:int,sapi:string,error:?string,timed_out:bool,ms:int,path:string}
     */
    public function inspect(string $binary): array
    {
        $result = self::runProbe($binary);
        $result['path'] = $binary;
        return $result;
    }

    /**
     * Non-secret snapshot for the install menu: is a CLI interpreter
     * available, where did it come from, and what is its basename. Absolute
     * paths are deliberately reduced to a basename - this payload is served
     * over the admin API and the operator only needs to recognise the file.
     *
     * @return array{available:bool,source:?string,binary:?string,candidates:int,error:?string}
     */
    public function summary(): array
    {
        $path = $this->resolve();
        $summary = [
            'available'  => $path !== null,
            'source'     => $path !== null ? $this->resolvedSource : null,
            'binary'     => $path !== null ? basename(str_replace('\\', '/', $path)) : null,
            'candidates' => count($this->slots),
            'error'      => null,
        ];
        if ($path === null) {
            $summary['error'] = MdViewerApi::errorLabel(MdViewerApi::ERR_NO_PHP_CLI);
        }
        return $summary;
    }

    /** Drop the process-wide probe memo (tests, and after a PHP upgrade). */
    public static function flushProbeCache(): void
    {
        self::$probeCache = [];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param  array<int,string|array{path?:string,source?:string}> $candidates
     * @return list<array{path:string,source:string}>
     */
    private static function normalise(array $candidates): array
    {
        $out  = [];
        $seen = [];
        foreach ($candidates as $entry) {
            $raw  = is_array($entry) ? ($entry['path'] ?? '') : $entry;
            $path = is_string($raw) ? trim($raw) : '';
            if ($path === '') {
                continue;
            }
            $key = self::cacheKey($path);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $source = is_array($entry) && is_string($entry['source'] ?? null) && $entry['source'] !== ''
                ? (string)$entry['source']
                : self::inferSource($path);
            $out[] = ['path' => $path, 'source' => $source];
        }
        return $out;
    }

    /**
     * Run the probe subprocess and report what came back.
     *
     * @return array{ok:bool,exit:int,sapi:string,error:?string,timed_out:bool,ms:int}
     */
    private static function runProbe(string $binary): array
    {
        $started     = microtime(true);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes       = [];
        $proc = @proc_open(
            [$binary, '-r', self::PROBE_CODE],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            return [
                'ok' => false, 'exit' => -1, 'sapi' => '',
                'error' => 'the candidate could not be started', 'timed_out' => false,
                'ms' => self::ms($started),
            ];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out      = '';
        $err      = '';
        $timedOut = false;
        $deadline = $started + self::PROBE_TIMEOUT;
        while (true) {
            $status = proc_get_status($proc);
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = @fread($pipe, 8192);
                    if (!is_string($chunk) || $chunk === '') {
                        continue;
                    }
                    if ($pipe === $pipes[1]) { $out .= $chunk; } else { $err .= $chunk; }
                }
            }
            if (!$status['running']) {
                $out .= (string)@stream_get_contents($pipes[1]);
                $err .= (string)@stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                @proc_terminate($proc, 9);
                break;
            }
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $exit = @proc_close($proc);

        $sapi = trim($out);
        $answer = self::display($sapi);
        return [
            // Compare the raw answer: sanitising must never make junk look
            // like a CLI answer.
            'ok'        => !$timedOut && $exit === 0 && $sapi === self::CLI_SAPI,
            'exit'      => is_int($exit) ? $exit : -1,
            'sapi'      => $answer === '' ? '' : $answer,
            'error'     => $err !== '' ? (self::display($err) ?: null) : null,
            'timed_out' => $timedOut,
            'ms'        => self::ms($started),
        ];
    }

    /** One-line, control-char-free, path-sanitised excerpt for the operator. */
    private static function display(string $raw): string
    {
        $flat = (string)preg_replace('/\s+/', ' ', $raw);
        $flat = Logger::sanitizeScalar(trim($flat));
        return substr($flat, 0, 200);
    }

    private static function ms(float $started): int
    {
        return (int)round((microtime(true) - $started) * 1000);
    }

    /** Memo key: Windows paths are case-insensitive. */
    private static function cacheKey(string $path): string
    {
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private static function samePath(string $a, string $b): bool
    {
        return self::cacheKey($a) === self::cacheKey($b);
    }

    /** Source label for a candidate that was passed as a bare string. */
    private static function inferSource(string $path): string
    {
        if (defined('PHP_BINARY') && self::samePath($path, (string)PHP_BINARY)) {
            return self::SOURCE_PHP_BINARY;
        }
        $bindir = defined('PHP_BINDIR') ? rtrim((string)PHP_BINDIR, "/\\") : '';
        if ($bindir !== '' && self::samePath($path, $bindir . '/php')) {
            return self::SOURCE_PHP_BINDIR;
        }
        if (self::samePath($path, '/usr/bin/php'))        { return self::SOURCE_USR_BIN; }
        if (self::samePath($path, '/usr/local/bin/php'))  { return self::SOURCE_USR_LOCAL; }
        return self::SOURCE_OTHER;
    }

    private static function envOverride(): ?string
    {
        $value = getenv(self::ENV_KEY);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    /**
     * `MdViewerPhpCli` from the loaded config tree (typically the gitignored
     * `.config.php` overlay). Returns null when Config is not loaded or the
     * key is absent - resolution must keep working without a config.
     */
    private static function configOverride(): ?string
    {
        try {
            $value = Config::get(self::CONFIG_KEY, null);
        } catch (\Throwable) {
            return null;
        }
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
