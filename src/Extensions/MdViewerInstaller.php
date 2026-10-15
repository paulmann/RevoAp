<?php
/**
 * MdViewerInstaller - server side of the Extensions tab.
 *
 * Owns the three things the browser must never do itself:
 *
 *   1. DOWNLOAD. The installer is fetched (curl when available, the https
 *      stream wrapper otherwise) with a timeout and written atomically into
 *      extensions/md-viewer/. The browser never talks to GitHub.
 *   2. KEY. API_KEY lives in extensions/md-viewer/.md.ini and is read only
 *      here, only on the server. It is never echoed, never put in client
 *      JSON and never written to a log line.
 *   3. CALL. The installer's machine API is executed as a local PHP-CLI
 *      subprocess with $_GET synthesised from the environment. The key
 *      travels in the child's environment, never on the command line, so it
 *      cannot appear in a process listing or a stack trace.
 *   4. CLI. `PHP_BINARY` is NOT trusted: under PHP-FPM that constant is
 *      php-fpm itself (it printed its usage and exited 64), so the updater
 *      never ran and the operator was told the API key was missing. The
 *      interpreter is resolved by probing candidates (config/env, then
 *      PHP_BINDIR/php, then the system paths, then PHP_BINARY) and only a
 *      candidate that answers `cli` is used - see PhpCliLocator.
 *   5. INI. .md.ini is written here - the six boolean keys plus the
 *      BROWSE_DIR string setting (the folder MD.Viewer lists), atomically,
 *      0600, and with a machine API key that is generated once and then
 *      preserved. No method in this class returns, logs or echoes that key.
 *
 * Every method returns a shaped array and never throws for an expected
 * failure - the Extensions tab has to be able to say "no network", "the
 * installer is older than 3.9.0" or "the installer answered junk" instead of
 * showing an empty panel.
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

use App\Core\Logger;

final class MdViewerInstaller
{
    /** Install directory, relative to the project root. */
    public const DIR = 'extensions/md-viewer';

    /** Default timeouts (seconds) - kept small so a dead network fails fast. */
    public const DOWNLOAD_TIMEOUT = 20;
    public const API_TIMEOUT      = 30;
    public const INSTALL_TIMEOUT  = 90;
    public const PROBE_TIMEOUT    = 8;

    /**
     * Resolved interpreter for the updater subprocess. Injectable so the
     * offline suite can drive the "PHP_BINARY is not a CLI" regression
     * without a PHP-FPM host.
     */
    private PhpCliLocator $phpCli;

    public function __construct(
        private readonly string $rootDir,
        ?PhpCliLocator $phpCli = null,
    ) {
        $this->phpCli = $phpCli ?? PhpCliLocator::defaults();
    }

    public function rootDir(): string    { return $this->rootDir; }
    public function dir(): string        { return $this->rootDir . '/' . self::DIR; }
    public function installerPath(): string { return $this->dir() . '/updater.php'; }
    public function iniPath(): string    { return $this->dir() . '/.md.ini'; }
    public function phpCli(): PhpCliLocator { return $this->phpCli; }

    /**
     * The PHP **CLI** binary the updater is run with, or null when this host
     * has none. Never `PHP_BINARY` on trust - see PhpCliLocator.
     */
    public function phpCliBinary(): ?string { return $this->phpCli->resolve(); }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    public function ensureDir(): bool
    {
        $dir = $this->dir();
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, 0775, true) || is_dir($dir);
    }

    public function installerPresent(): bool
    {
        return is_file($this->installerPath());
    }

    /** Version banner of the local installer copy (null when absent/unreadable). */
    public function installedVersion(): ?string
    {
        return $this->fileVersion($this->installerPath());
    }

    public function iniPresent(): bool
    {
        return is_file($this->iniPath());
    }

    /** Is MD.Viewer itself installed next to the installer? */
    public function instancePresent(): bool
    {
        return is_file($this->dir() . '/md.php');
    }

    public function instanceVersion(): ?string
    {
        return $this->fileVersion($this->dir() . '/md.php');
    }

    /** The local install state the status card renders. @return array<string,mixed> */
    public function state(): array
    {
        $present = $this->installerPresent();
        $version = $present ? $this->installedVersion() : null;
        return [
            'directory'  => self::DIR,
            'transport'  => self::transport(),
            'installer'  => [
                'present'       => $present,
                'path'          => self::DIR . '/updater.php',
                'version'       => $version,
                'api_supported' => MdViewerApi::supportsMachineApi($version),
            ],
            'instance'   => [
                'installed' => $this->instancePresent(),
                'version'   => $this->instancePresent() ? $this->instanceVersion() : null,
                'path'      => self::DIR . '/md.php',
            ],
            'ini_present' => $this->iniPresent(),
        ];
    }

    /**
     * Which outbound transport exists on this host. curl is preferred (it is
     * what the connectors use); the https stream wrapper is the fallback.
     *
     * @return array{available:bool,kind:string,reason:string}
     */
    public static function transport(): array
    {
        if (extension_loaded('curl') && function_exists('curl_init')) {
            return ['available' => true, 'kind' => 'curl', 'reason' => ''];
        }
        if (in_array('https', stream_get_wrappers(), true)) {
            return ['available' => true, 'kind' => 'stream', 'reason' => ''];
        }
        return [
            'available' => false,
            'kind'      => 'none',
            'reason'    => MdViewerApi::errorLabel(MdViewerApi::ERR_NO_TRANSPORT),
        ];
    }

    // -----------------------------------------------------------------
    // Download
    // -----------------------------------------------------------------

    /**
     * Fetch the installer into extensions/md-viewer/updater.php.
     *
     * @return array{ok:bool,error:?string,message:string,bytes:int,sha256:string,version:?string,api_supported:bool,warning:?string,http_status:int,ms:int,path:string}
     */
    public function download(): array
    {
        if (!$this->ensureDir()) {
            return $this->fail('dir_unwritable', 'Could not create ' . self::DIR . ' - check filesystem permissions.');
        }
        $fetch = $this->fetchRemote(self::DOWNLOAD_TIMEOUT);
        if (!$fetch['ok']) {
            $failure = $this->fail($fetch['error'] ?? MdViewerApi::ERR_NETWORK, (string)($fetch['message'] ?? ''));
            $failure['http_status'] = $fetch['http_status'];
            $failure['ms']          = $fetch['ms'];
            return $failure;
        }
        $body = $fetch['body'];
        if (!str_contains($body, '<?php')) {
            $failure = $this->fail('not_php', 'The downloaded file does not look like a PHP installer.');
            $failure['http_status'] = $fetch['http_status'];
            $failure['ms']          = $fetch['ms'];
            return $failure;
        }

        $version = MdViewerApi::extractVersion($body);
        $tmp     = $this->installerPath() . '.tmp';
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            return $this->fail('write_failed', 'Could not write ' . self::DIR . '/updater.php - check filesystem permissions.');
        }
        if (!@rename($tmp, $this->installerPath())) {
            @unlink($tmp);
            return $this->fail('write_failed', 'Could not move the installer into place.');
        }

        $apiSupported = MdViewerApi::supportsMachineApi($version);
        $warning = $apiSupported
            ? null
            : 'The remote installer is older than ' . MdViewerApi::MIN_VERSION . ' - the machine API is unavailable.';

        return [
            'ok'            => true,
            'error'         => null,
            'message'       => 'Installer saved to ' . self::DIR . '/updater.php.',
            'bytes'         => strlen($body),
            'sha256'        => hash('sha256', $body),
            'version'       => $version,
            'api_supported' => $apiSupported,
            'warning'       => $warning,
            'http_status'   => $fetch['http_status'],
            'ms'            => $fetch['ms'],
            'path'          => self::DIR . '/updater.php',
        ];
    }

    /**
     * Reachability probe for the state card. Never throws; a missing
     * transport and a dead network are both reported as data.
     *
     * @return array{checked:bool,reachable:bool,transport:string,error:?string,message:string,http_status:int,bytes:int,ms:int}
     */
    public function probeNetwork(int $timeoutSec = self::PROBE_TIMEOUT): array
    {
        $t = self::transport();
        if (!$t['available']) {
            return ['checked' => true, 'reachable' => false, 'transport' => $t['kind'],
                    'error' => MdViewerApi::ERR_NO_TRANSPORT, 'message' => $t['reason'],
                    'http_status' => 0, 'bytes' => 0, 'ms' => 0];
        }
        $r = $this->fetchRemote($timeoutSec);
        return [
            'checked'     => true,
            'reachable'   => $r['ok'],
            'transport'   => $t['kind'],
            'error'       => $r['error'],
            'message'     => $r['message'] ?? '',
            'http_status' => $r['http_status'],
            'bytes'       => $r['bytes'],
            'ms'          => $r['ms'],
        ];
    }

    // -----------------------------------------------------------------
    // Machine API
    // -----------------------------------------------------------------

    /**
     * Read API_KEY from .md.ini. With `$bootstrap` the installer is run once
     * (no key) so it creates the file and its key, then the file is re-read.
     * Returns null when there is no usable key - never a partial guess.
     */
    public function readApiKey(bool $bootstrap = false): ?string
    {
        $key = $this->parseIniKey();
        if ($key !== null) {
            return $key;
        }
        // The bootstrap run is the historical way of getting a key: the
        // installer writes .md.ini on its first execution. It is attempted only
        // when an interpreter exists - without one nothing can run, and the
        // honest answer is "no key file yet", not a subprocess.
        if ($bootstrap && $this->installerPresent() && $this->phpCliBinary() !== null) {
            // The installer generates .md.ini (and API_KEY) on its first run.
            $this->runUpdater('status', [], 'GET', '', 15);
            $key = $this->parseIniKey();
        }
        return $key;
    }

    /**
     * Call the installer's machine API.
     *
     * @param  array<string,mixed> $params dry_run / force
     * @return array{ok:bool,format_valid:bool,api_ok:bool,error:?string,json:?array<string,mixed>,raw:string,excerpt:string,bytes:int,exit:int,ms:int,timed_out:bool,command_error:?string}
     */
    public function callApi(string $action, array $params = [], string $method = 'GET', int $timeoutSec = self::API_TIMEOUT): array
    {
        if (!$this->installerPresent()) {
            return $this->apiFailure(MdViewerApi::ERR_INSTALLER_MISSING);
        }
        $version = $this->installedVersion();
        if (!MdViewerApi::supportsMachineApi($version)) {
            return $this->apiFailure(MdViewerApi::ERR_OLD_INSTALLER);
        }
        // A missing interpreter is NOT a key problem. Reporting it as one (the
        // behaviour that produced this bug report) sent the operator looking
        // for a key while the installer could never have run at all.
        if ($this->phpCliBinary() === null) {
            return $this->apiFailure(MdViewerApi::ERR_NO_PHP_CLI);
        }
        $key = $this->readApiKey(true);
        if ($key === null) {
            return $this->apiFailure(MdViewerApi::ERR_API_KEY_UNAVAILABLE);
        }

        $run    = $this->runUpdater($action, $params, $method, $key, $timeoutSec);
        $parsed = MdViewerApi::parseResponse($run['out']);

        $error = $parsed['error'];
        if ($parsed['format_valid'] && !MdViewerApi::isOk((array)$parsed['json'])) {
            $error = MdViewerApi::errorOf((array)$parsed['json']);
        }
        if ($run['timed_out'] && $parsed['error'] === MdViewerApi::ERR_EMPTY) {
            $error = 'timeout';
        }

        return [
            'ok'            => $parsed['format_valid'] && MdViewerApi::isOk((array)$parsed['json']),
            'format_valid'  => $parsed['format_valid'],
            'api_ok'        => $parsed['format_valid'] && MdViewerApi::isOk((array)$parsed['json']),
            'error'         => $error,
            'json'          => $parsed['json'],
            'raw'           => $parsed['format_valid'] ? '' : $run['out'],
            'excerpt'       => $parsed['excerpt'],
            'bytes'         => $parsed['bytes'],
            'exit'          => $run['exit'],
            'ms'            => $run['ms'],
            'timed_out'     => $run['timed_out'],
            'command_error' => $run['err'] !== '' ? Logger::sanitizeScalar(trim($run['err'])) : null,
        ];
    }

    // -----------------------------------------------------------------
    // .md.ini (server-side configuration + machine API key)
    // -----------------------------------------------------------------

    /**
     * Everything the install menu may know about .md.ini - the six booleans
     * plus the BROWSE_DIR string setting - and never the key itself:
     * `key_present` and a `mdv_....` mask only. `missing_keys` counts the
     * string setting too, so a file written before it existed is completed
     * by the next write.
     *
     * @return array<string,mixed>
     */
    public function settingsState(): array
    {
        $path    = $this->iniPath();
        $present = is_file($path);
        $raw     = $present ? (string)@file_get_contents($path) : '';
        $parsed  = MdViewerIni::parse($raw);
        $key     = $present ? MdViewerIni::extractKey($raw) : null;

        return [
            'path'            => self::DIR . '/' . MdViewerIni::FILE,
            'present'         => $present,
            'values'          => $parsed['values'],
            'defaults'        => MdViewerIni::DEFAULTS,
            'string_defaults' => MdViewerIni::STRING_DEFAULTS,
            'keys'            => MdViewerIni::allKeys(),
            'missing_keys'    => array_values(array_diff(MdViewerIni::allKeys(), $parsed['present'])),
            'invalid_keys'    => $parsed['invalid'],
            'unknown_keys'    => $parsed['unknown'],
            'key_present'     => $key !== null,
            'key_mask'        => $key !== null ? MdViewerIni::maskKey($key) : null,
            'key_generated'   => $key !== null && MdViewerIni::looksGenerated($key),
            'writable'        => $this->iniWritable(),
        ];
    }

    /**
     * Runtime facts for the install menu. This one probes the CLI locator,
     * so it is only served from admin-gated endpoints - an anonymous page
     * view must never cost a subprocess.
     *
     * @return array{php_cli:array<string,mixed>}
     */
    public function runtimeState(): array
    {
        return ['php_cli' => $this->phpCli->summary()];
    }

    /**
     * Create or update .md.ini, atomically and with all seven keys spelled out.
     *
     * Two guarantees the operator asked for explicitly:
     *   - an existing, usable API_KEY is NEVER regenerated on a write, so
     *     clients already configured with it keep working; rotation is a
     *     separate, explicit action ({@see rotateIniKey()});
     *   - values that are not supplied keep what the file (or the defaults)
     *     says, so a partial update cannot silently flip a switch.
     *
     * The key is never returned, logged or echoed - only its presence and a
     * mask.
     *
     * @param  array<string,bool|string> $overrides
     * @return array<string,mixed>
     */
    public function writeIni(array $overrides = [], bool $rotate = false): array
    {
        if (!$this->ensureDir()) {
            return $this->iniFailure('dir_unwritable', 'Could not create ' . self::DIR . ' - check filesystem permissions.');
        }
        $state  = $this->settingsState();
        $values = $state['values'];
        foreach ($overrides as $name => $value) {
            $key = strtoupper(trim((string)$name));
            if (in_array($key, MdViewerIni::KEYS, true) && is_bool($value)) {
                $values[$key] = $value;
                continue;
            }
            // The string setting (BROWSE_DIR): applied only when it is safe
            // for a single .md.ini line. Anything else leaves what the file
            // already says in place, exactly like an unsupplied boolean.
            if (is_string($value) && in_array($key, MdViewerIni::STRING_KEYS, true)) {
                $path = MdViewerIni::toPath($value);
                if ($path !== null) {
                    $values[$key] = $path;
                }
            }
        }

        $path     = $this->iniPath();
        $current  = $state['present'] ? (string)@file_get_contents($path) : null;
        $existing = $current !== null ? MdViewerIni::extractKey($current) : null;
        $key      = ($rotate || $existing === null) ? MdViewerIni::generateKey() : $existing;
        $body     = MdViewerIni::render($values, $key);

        if ($current === $body) {
            // Idempotent: the exact bytes are already in place.
            return $this->iniResult($state, ['created' => false, 'changed' => false, 'rotated' => false],
                'Nothing to change - ' . self::DIR . '/' . MdViewerIni::FILE . ' already matches.');
        }

        $error = $this->atomicWrite($path, $body);
        if ($error !== null) {
            return $this->iniFailure($error, 'Could not write ' . self::DIR . '/' . MdViewerIni::FILE . ' - check filesystem permissions.');
        }
        // Verified write: read the bytes back and confirm the file now says
        // what we meant to say (settings and key alike). A "saved" that did
        // not land is worse than an error.
        $check   = is_file($path) ? (string)@file_get_contents($path) : '';
        $back    = MdViewerIni::parse($check);
        $backKey = MdViewerIni::extractKey($check);
        if ($back['values'] !== $values || $backKey === null || !hash_equals($key, $backKey)) {
            return $this->iniFailure('verify_failed',
                'The written ' . self::DIR . '/' . MdViewerIni::FILE . ' does not read back as written - the file did not land.');
        }

        return $this->iniResult($this->settingsState(),
            ['created' => $current === null, 'changed' => true, 'rotated' => $rotate],
            ($rotate ? 'A new API key was written to ' : 'Written to ') . self::DIR . '/' . MdViewerIni::FILE . '.');
    }

    /**
     * The confirmed "Rotate key" action: a new key, unconditionally, reported
     * only as a mask - the operator copies it from the file.
     *
     * @return array<string,mixed>
     */
    public function rotateIniKey(): array
    {
        return $this->writeIni([], true);
    }

    /**
     * Make sure .md.ini exists and declares all seven keys before updater.php
     * is asked to do anything. Without it the installer's own readIni()
     * applies ITS defaults - ALLOW_UPDATE=false among them - and the install
     * menu the operator just filled in would be ignored.
     *
     * Never rotates: an existing usable key is preserved.
     *
     * @return array<string,mixed>
     */
    public function ensureIni(): array
    {
        $state = $this->settingsState();
        if ($state['present'] && $state['missing_keys'] === [] && $state['key_present']) {
            return $this->iniResult($state, ['created' => false, 'changed' => false, 'rotated' => false],
                'Using the existing ' . self::DIR . '/' . MdViewerIni::FILE . '.');
        }
        return $this->writeIni();
    }

    /** Can the file (or, when it does not exist yet, its directory) be written? */
    private function iniWritable(): bool
    {
        $path = $this->iniPath();
        if (is_file($path)) {
            return is_writable($path);
        }
        $dir = $this->dir();
        return is_dir($dir) ? is_writable($dir) : is_writable($this->rootDir);
    }

    /**
     * Write through a temp file in the same directory, 0600, then rename - a
     * reader never sees a half-written .md.ini.
     *
     * @return string|null Error code, or null on success.
     */
    private function atomicWrite(string $path, string $body): ?string
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            @unlink($tmp);
            return 'write_failed';
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return 'write_failed';
        }
        @chmod($path, 0600);
        return null;
    }

    /**
     * Shared success shape for the .md.ini writers. Carries the file state as
     * it is on disk after the call - and never the key.
     *
     * @param  array<string,mixed> $state
     * @param  array<string,bool>  $flags
     * @return array<string,mixed>
     */
    private function iniResult(array $state, array $flags, string $message): array
    {
        return [
            'ok'          => true,
            'error'       => null,
            'message'     => $message,
            'created'     => (bool)($flags['created'] ?? false),
            'changed'     => (bool)($flags['changed'] ?? false),
            'rotated'     => (bool)($flags['rotated'] ?? false),
            'values'      => $state['values'] ?? MdViewerIni::defaults(),
            'key_present' => ($state['key_present'] ?? false) === true,
            'key_mask'    => $state['key_mask'] ?? null,
            'state'       => $state,
        ];
    }

    /** @return array<string,mixed> */
    private function iniFailure(string $error, string $message): array
    {
        return [
            'ok'          => false,
            'error'       => $error,
            'message'     => $message,
            'created'     => false,
            'changed'     => false,
            'rotated'     => false,
            'values'      => MdViewerIni::defaults(),
            'key_present' => false,
            'key_mask'    => null,
            'state'       => $this->settingsState(),
        ];
    }
    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * One outbound GET of the upstream installer. curl first, stream second.
     *
     * @return array{ok:bool,error:?string,message:string,http_status:int,bytes:int,body:string,ms:int}
     */
    private function fetchRemote(int $timeoutSec): array
    {
        $t = self::transport();
        if (!$t['available']) {
            return ['ok' => false, 'error' => MdViewerApi::ERR_NO_TRANSPORT, 'message' => $t['reason'],
                    'http_status' => 0, 'bytes' => 0, 'body' => '', 'ms' => 0];
        }
        $started = microtime(true);
        $url     = MdViewerApi::INSTALLER_URL;
        $ua      = 'RevoAp/1.9.2 (+extensions; MD.Viewer installer fetch)';

        if ($t['kind'] === 'curl') {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'error' => MdViewerApi::ERR_NETWORK, 'message' => 'curl_init() failed',
                        'http_status' => 0, 'bytes' => 0, 'body' => '', 'ms' => 0];
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => $timeoutSec,
                CURLOPT_TIMEOUT        => $timeoutSec,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Accept: text/plain'],
            ]);
            $body   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err    = (string)curl_error($ch);
            curl_close($ch);
            $ms = (int)round((microtime(true) - $started) * 1000);
            if ($body === false) {
                return ['ok' => false, 'error' => MdViewerApi::ERR_NETWORK,
                        'message' => Logger::sanitizeScalar($err) ?: MdViewerApi::errorLabel(MdViewerApi::ERR_NETWORK),
                        'http_status' => $status, 'bytes' => 0, 'body' => '', 'ms' => $ms];
            }
            if ($status !== 200) {
                return ['ok' => false, 'error' => 'http_' . $status,
                        'message' => 'GitHub answered HTTP ' . $status . '.',
                        'http_status' => $status, 'bytes' => strlen((string)$body), 'body' => '', 'ms' => $ms];
            }
            return ['ok' => true, 'error' => null, 'message' => '', 'http_status' => $status,
                    'bytes' => strlen((string)$body), 'body' => (string)$body, 'ms' => $ms];
        }

        // Stream wrapper fallback.
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $timeoutSec,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'ignore_errors'   => true,
                'header'          => "User-Agent: {$ua}\r\nAccept: text/plain\r\n",
            ],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body   = @file_get_contents($url, false, $ctx);
        $ms     = (int)round((microtime(true) - $started) * 1000);
        $status = 0;
        /** @var list<string>|null $http_response_header */
        $headers = $http_response_header ?? null;
        if (is_array($headers)) {
            foreach ($headers as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int)$m[1];
                }
            }
        }
        if ($body === false) {
            $err = error_get_last();
            return ['ok' => false, 'error' => MdViewerApi::ERR_NETWORK,
                    'message' => Logger::sanitizeScalar((string)($err['message'] ?? '')) ?: MdViewerApi::errorLabel(MdViewerApi::ERR_NETWORK),
                    'http_status' => $status, 'bytes' => 0, 'body' => '', 'ms' => $ms];
        }
        if ($status !== 0 && $status !== 200) {
            return ['ok' => false, 'error' => 'http_' . $status,
                    'message' => 'GitHub answered HTTP ' . $status . '.',
                    'http_status' => $status, 'bytes' => strlen((string)$body), 'body' => '', 'ms' => $ms];
        }
        return ['ok' => true, 'error' => null, 'message' => '', 'http_status' => $status === 0 ? 200 : $status,
                'bytes' => strlen((string)$body), 'body' => (string)$body, 'ms' => $ms];
    }

    /**
     * Run updater.php as a PHP-CLI subprocess and return its stdout.
     *
     * $_GET is synthesised from the environment (format=json, action,
     * api_key, dry_run, force); the key is passed only in the child's
     * environment - never as an argv element - so it cannot be read out of a
     * process listing. Pipes are drained non-blockingly so a hung child is
     * killed at the deadline instead of hanging the web request.
     *
     * The interpreter is the one PhpCliLocator accepted (`cli` probe), not
     * `PHP_BINARY` - under PHP-FPM that constant is php-fpm itself.
     *
     * @param  array<string,mixed> $params
     * @return array{out:string,err:string,exit:int,ms:int,timed_out:bool,cli:?string}
     */
    private function runUpdater(string $action, array $params, string $method, string $key, int $timeoutSec): array
    {
        $dir     = $this->dir();
        $updater = $this->installerPath();
        // Resolved by probe, never taken from PHP_BINARY on trust: under
        // PHP-FPM that constant is the FPM master, which printed its usage and
        // exited 64 while the installer never ran (2026-09-26).
        $binary = $this->phpCliBinary();
        if ($binary === null) {
            return ['out' => '', 'err' => 'no PHP CLI interpreter available', 'exit' => -1,
                    'ms' => 0, 'timed_out' => false, 'cli' => null];
        }
        $code = 'chdir(' . var_export($dir, true) . ');'
            // updater.php locates .md.ini through `DOCUMENT_ROOT ?: dirname(__FILE__)`,
            // so pin DOCUMENT_ROOT at the extension directory. Leaving it empty
            // (as it was) made that lookup depend on how the child was started.
            . '$_SERVER["DOCUMENT_ROOT"]=' . var_export($dir, true) . ';'
            . '$_SERVER["REQUEST_METHOD"]=getenv("MDV_METHOD")?:"GET";'
            . '$_GET=["format"=>"json","action"=>getenv("MDV_ACTION"),'
            . '"api_key"=>getenv("MDV_API_KEY"),'
            . '"dry_run"=>getenv("MDV_DRY")?:"0",'
            . '"force"=>getenv("MDV_FORCE")?:"0"];'
            . '$_POST=[];$_REQUEST=$_GET;'
            . 'require ' . var_export($updater, true) . ';';

        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['MDV_ACTION']  = $action;
        $env['MDV_API_KEY'] = $key;
        $env['MDV_METHOD']  = $method;
        $env['MDV_DRY']     = !empty($params['dry_run']) ? '1' : '0';
        $env['MDV_FORCE']   = !empty($params['force']) ? '1' : '0';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes       = [];
        $started     = microtime(true);
        $proc = @proc_open(
            [$binary, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', $code],
            $descriptors,
            $pipes,
            $dir,
            $env,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            return ['out' => '', 'err' => 'proc_open() failed', 'exit' => -1,
                    'ms' => (int)round((microtime(true) - $started) * 1000), 'timed_out' => false,
                    'cli' => $binary];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $timedOut = false;
        $deadline = $started + $timeoutSec;
        while (true) {
            $status = proc_get_status($proc);
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = @fread($pipe, 65536);
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
        return [
            'out'       => $out,
            'err'       => $err,
            'exit'      => is_int($exit) ? $exit : -1,
            'ms'        => (int)round((microtime(true) - $started) * 1000),
            'timed_out' => $timedOut,
            'cli'       => $binary,
        ];
    }

    /**
     * API_KEY out of .md.ini. The parsing lives in MdViewerIni - one parser
     * for reading and writing - where a commented-out key and the doc lines
     * that merely mention `api_key=` are ignored.
     */
    private function parseIniKey(): ?string
    {
        $path = $this->iniPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        $key = is_string($raw) ? MdViewerIni::extractKey($raw) : null;
        if ($key !== null) {
            return $key;
        }
        // A file written by an older install may carry a value our scanner
        // does not accept but parse_ini_file does (quotes, another generator).
        // Accept it rather than reporting "no key" for a file that has one.
        $ini = @parse_ini_file($path, false, INI_SCANNER_TYPED);
        if (is_array($ini) && is_string($ini['API_KEY'] ?? null) && $ini['API_KEY'] !== '') {
            return (string)$ini['API_KEY'];
        }
        return null;
    }

    /** Version banner of one local file, read one kilobyte at a time. */
    private function fileVersion(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string)fread($fh, 1000);
        fclose($fh);
        return MdViewerApi::extractVersion($head);
    }

    /** @return array{ok:bool,format_valid:bool,api_ok:bool,error:?string,json:?array<string,mixed>,raw:string,excerpt:string,bytes:int,exit:int,ms:int,timed_out:bool,command_error:?string} */
    private function apiFailure(string $error): array
    {
        return [
            'ok' => false, 'format_valid' => false, 'api_ok' => false, 'error' => $error,
            'json' => null, 'raw' => '', 'excerpt' => '', 'bytes' => 0,
            'exit' => -1, 'ms' => 0, 'timed_out' => false, 'command_error' => null,
        ];
    }

    /** @return array{ok:bool,error:?string,message:string,bytes:int,sha256:string,version:?string,api_supported:bool,warning:?string,http_status:int,ms:int,path:string} */
    private function fail(string $error, string $message): array
    {
        return [
            'ok' => false, 'error' => $error, 'message' => $message, 'bytes' => 0, 'sha256' => '',
            'version' => null, 'api_supported' => false, 'warning' => null,
            'http_status' => 0, 'ms' => 0, 'path' => self::DIR . '/updater.php',
        ];
    }
}
