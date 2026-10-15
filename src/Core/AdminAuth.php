<?php
/**
 * AdminAuth: lightweight token gate for the Settings UI / API.
 *
 * The admin token is loaded from (in priority order):
 *   1. env REVOAP_ADMIN_TOKEN (canonical). The deprecated legacy alias
 *      OPEN_CORUM_ADMIN_TOKEN is still accepted with a one-time warn;
 *      the token value itself is never logged.
 *   2. {project_root}/.admin.key  (single-line file, 0600 recommended)
 *   3. Config key `admin.token` resolved from the loaded config tree.
 *      This is intended for the gitignored `.config.php` overlay (the
 *      tracked `config.php` MUST NOT carry secrets - see `config.php`
 *      header). It exists so deployments where shared-host permissions
 *      block PHP from reading `.admin.key` can still configure the
 *      token via private config without env-var access.
 *
 * `REVOAP_ADMIN_TOKEN` is the canonical *environment variable* name,
 * not a `config.php` key. `OPEN_CORUM_ADMIN_TOKEN` remains accepted as
 * a deprecated legacy alias. Storing the admin secret in `config.php`
 * would put it in version control by default, so the deliberate design
 * is:
 *   - The variable name lives in the env / process scope.
 *   - The token value lives either in the environment or in
 *     `.admin.key` at the project root (which is git-ignored).
 *   - `config.php` only mentions the *names* used for the lookup
 *     (`admin.token_env`, `admin.token_file`) so an operator browsing
 *     the file knows how to wire authentication without ever pasting
 *     the secret into a tracked file.
 *
 * The project root is resolved independently of Config so the gate keeps
 * working before Config::load() runs and from arbitrary CLI / test
 * working directories. Resolution scans, in order, the following
 * candidates and picks the first one that contains a readable
 * `.admin.key` (or, when no key file is present anywhere, the first
 * candidate that looks like a project root):
 *   a. self::$rootDir when an operator pinned it via setRootDir().
 *   b. {Config::rootDir()} when Config has been loaded.
 *   c. dirname(__DIR__, 2)  - the canonical layout (src/Core/AdminAuth.php → repo root).
 *   d. realpath() of (c)    - symlink-aware copy of the canonical root.
 *   e. $_SERVER['DOCUMENT_ROOT'] - common Apache/Nginx setup.
 *   f. Walk up from __DIR__ until a directory contains config.php / index.php.
 *   g. getcwd() as a last resort (PHP built-in server / unusual deployments).
 *
 * Contents read from `.admin.key` are normalized: a leading UTF-8 BOM
 * (`\xEF\xBB\xBF`) and surrounding whitespace (CR/LF/spaces) are
 * stripped before the value is treated as the token. Editors that save
 * files with a BOM are a common source of "exists but not detected"
 * support reports.
 *
 * If neither an env var nor a readable .admin.key file is present, every
 * protected endpoint refuses with 503 so the operator must explicitly
 * opt in. The token is compared with hash_equals to avoid timing leaks.
 * Submissions are accepted via:
 *   - Header  X-Admin-Token: <value>
 *   - Header  Authorization: Bearer <value>
 *   - Query   ?admin_token=<value>
 *   - Cookie  oc_admin (set after successful unlock)
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.3.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Core;

use App\Helpers\RootFiles;

final class AdminAuth
{
    public const COOKIE = 'oc_admin';
    /** Canonical env var name; config `admin.token_env` may override it. */
    public const ENV        = 'REVOAP_ADMIN_TOKEN';
    /** Deprecated legacy env name, still accepted with a warning. */
    public const LEGACY_ENV = 'OPEN_CORUM_ADMIN_TOKEN';
    /** Default key filename; can be overridden via config `admin.token_file`. */
    public const FILE   = '.admin.key';

    /**
     * Config key that decides whether the operator surface asks for the
     * admin token at all.
     *
     *   `Protect_Settings_by_Admin_Key = 1` — the historical behaviour:
     *       every guarded route requires the token from `.admin.key` /
     *       `REVOAP_ADMIN_TOKEN`, and the UI shows the unlock prompt.
     *   `Protect_Settings_by_Admin_Key = 0` — no key is requested. The
     *       guards pass without one, the UI shows no unlock prompt, and no
     *       response is treated as `unauthorized`.
     *
     * Read from the loaded config tree, so `.config.php` wins over
     * `config.php` (local override beats the tracked default). Accepted
     * spellings: `0`/`1`, `"0"`/`"1"`, `false`/`true`, `"false"`/`"true"`,
     * `off`/`on`, `no`/`yes`.
     *
     * When the key is ABSENT the protection stays ON: an install that has
     * not opted out keeps the gate it has always had, and a config tree
     * that fails to load cannot silently open the settings surface.
     */
    public const PROTECT_KEY = 'Protect_Settings_by_Admin_Key';

    /** UTF-8 byte-order mark - stripped from `.admin.key` contents. */
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /** Optional explicit project root, used by tests. */
    private static ?string $rootDir = null;

    /** Set once per process when the legacy env token name was used. */
    private static bool $legacyEnvWarned = false;

    /**
     * Pin the project root used for .admin.key lookup. Tests use this to
     * point at a temporary directory; production code should rely on the
     * automatic resolution via Config or the file-tree walk.
     */
    public static function setRootDir(?string $rootDir): void
    {
        self::$rootDir = $rootDir === null
            ? null
            : rtrim($rootDir, DIRECTORY_SEPARATOR);
        // Keep the shared RootFiles helper in sync so any other
        // resolver path (Config::getConnectorKey, RootFiles::resolveRoot)
        // sees the same pinned directory under test.
        RootFiles::pinRoot(self::$rootDir);
    }

    /**
     * Resolve the managed environment-variable name. Defaults to the
     * canonical `REVOAP_ADMIN_TOKEN` constant; `admin.token_env` in
     * config.php may point at a differently-named variable. A value
     * that names the deprecated legacy alias is mapped back to the
     * canonical name so callers never prefer the shadow variable.
     * Falls back silently when Config has not been loaded - a freshly-
     * bootstrapped CLI invocation must keep working with the default.
     */
    public static function envName(): string
    {
        try {
            $cfg = Config::get('admin.token_env', null);
            if (is_string($cfg) && trim($cfg) !== '') {
                $name = trim($cfg);
                if (strcasecmp($name, self::LEGACY_ENV) === 0) {
                    return self::ENV;
                }
                return $name;
            }
        } catch (\Throwable) {
            // Config not loaded yet - fall through to the default.
        }
        return self::ENV;
    }

    /**
     * Resolve the configured key filename. Defaults to `.admin.key`; can
     * be overridden by `admin.token_file` in config.php. The name is
     * always treated as relative to the project root and may not contain
     * a directory separator (so it cannot escape the root). Invalid
     * configured values fall back to the default and are reported via
     * diagnostics rather than silently honored.
     */
    public static function fileName(): string
    {
        try {
            $cfg = Config::get('admin.token_file', null);
            if (is_string($cfg)) {
                $name = trim(basename($cfg));
                if ($name !== '' && $name !== '.' && $name !== '..') {
                    return $name;
                }
            }
        } catch (\Throwable) {
            // Config not loaded yet - fall through to the default.
        }
        return self::FILE;
    }

    /** Whether an admin token is configured at all. */
    public static function isConfigured(): bool
    {
        return self::loadToken() !== null;
    }

    /**
     * Is the operator surface protected by the admin key?
     *
     * Reads {@see self::PROTECT_KEY} from the loaded config tree. Answers
     * `true` when the key is absent, unreadable (Config not loaded), or
     * carries an unusable value — the gate is opt-OUT, never opt-in by
     * accident. Only an explicit `0` / `false` / `off` / `no` turns it off.
     */
    public static function protectionEnabled(): bool
    {
        $raw = null;
        try {
            $raw = Config::get(self::PROTECT_KEY, null);
        } catch (\Throwable) {
            // Config not loaded (a CLI helper, a unit test): keep the gate.
            return true;
        }
        return self::coerceProtection($raw);
    }

    /**
     * Every flag the guards, the status probes and the UI need, resolved
     * once so no two call sites can disagree.
     *
     * `unlocked` is TRUE whenever the protection is off — that is what makes
     * every existing lock screen and "admin token required" banner disappear
     * when the operator sets the flag to 0, without a second code path in
     * the front end.
     *
     * @return array{protected:bool,configured:bool,unlocked:bool,allowed:bool,key:string}
     */
    public static function gateState(): array
    {
        $protected = self::protectionEnabled();
        $configured = self::isConfigured();
        $unlocked = $protected ? self::check() : true;
        return [
            // `protected` is the raw flag; `allowed` is the verdict a guard
            // acts on. They differ only when the flag is off.
            'protected'  => $protected,
            'configured' => $configured,
            'unlocked'   => $unlocked,
            'allowed'    => $unlocked && (!$protected || $configured),
            'key'        => self::PROTECT_KEY,
        ];
    }

    /**
     * Where the protection flag is set, for the diagnostics payload.
     *
     * `runtime` means `var/config/runtime.json` (or a `Config::set()` tree)
     * carries it, `base` means the flag comes from `config.php` /
     * `.config.php`, and `default` means nothing set it — in which case the
     * gate is ON, because the setting is opt-out.
     *
     * @return string 'runtime' | 'base' | 'default'
     */
    public static function protectionLocation(): string
    {
        if (RuntimeConfig::all() !== []) {
            $cursor = RuntimeConfig::all();
            foreach (explode('.', self::PROTECT_KEY) as $part) {
                if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$part];
            }
            if ($cursor !== null) {
                return 'runtime';
            }
        }
        try {
            if (Config::get(self::PROTECT_KEY, null) !== null) {
                // `Config::get` cannot say which file won — the tree only
                // merges, and both the tracked base and the `.config.php`
                // overlay land in it. What matters to an operator is that the
                // value came from a file and not from a default.
                return 'base';
            }
        } catch (\Throwable) {
            return 'default';
        }
        return 'default';
    }

    /**
     * Coerce any accepted spelling of the flag to a boolean.
     *
     * `null` (absent), an array, an object or an unrecognised string all
     * mean "protected": a typo must not open the settings surface, and the
     * operator who wanted the gate off can see exactly what to write in the
     * boot config.
     *
     * @param mixed $raw
     */
    public static function coerceProtection(mixed $raw): bool
    {
        if ($raw === null) {
            return true;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int)$raw !== 0;
        }
        if (is_string($raw)) {
            $value = strtolower(trim($raw));
            if ($value === '') {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no', 'n', 'disable', 'disabled'], true)) {
                return false;
            }
            if (in_array($value, ['1', 'true', 'on', 'yes', 'y', 'enable', 'enabled'], true)) {
                return true;
            }
            if (is_numeric($value)) {
                return (int)$value !== 0;
            }
        }
        return true;
    }

    /**
     * Read the configured admin token. Returns null if unset/empty.
     *
     * `is_readable()` is intentionally NOT used as a gate because many
     * shared hosts misreport it for hidden files that
     * `file_get_contents()` can still open. The RootFiles helper
     * attempts the read directly and reports a structured `read_error`
     * if the open genuinely fails, which we surface in diagnostics
     * instead of silently masking with `empty=true`.
     */
    public static function loadToken(): ?string
    {
        $env = self::envToken();
        if ($env !== null) {
            return $env;
        }
        $info = self::keyFileInfo();
        if (($info['read_ok'] ?? false) === true) {
            $normalized = (string)($info['contents'] ?? '');
            if ($normalized !== '') {
                return $normalized;
            }
        }
        $cfg = self::configToken();
        if ($cfg !== null) {
            return $cfg;
        }
        return null;
    }

    /**
     * Read the admin token from the loaded config tree (`admin.token`).
     * Returns null when Config has not been loaded yet or the key is
     * absent / empty. This source ranks BELOW `.admin.key` and the env
     * variable but ABOVE "not configured" - it is the documented
     * fallback for shared hosts where `.admin.key` cannot be read by
     * the PHP process. The value MUST live in private overlay
     * (`.config.php`); the tracked `config.php` ships with no token.
     */
    private static function configToken(): ?string
    {
        try {
            $cfg = Config::get('admin.token', null);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($cfg)) {
            return null;
        }
        $trimmed = trim($cfg);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Return a non-secret diagnostic snapshot for status endpoints. Safe
     * to expose publicly: never includes the token value or absolute
     * paths. The basename of the key file is fine because it is a fixed
     * convention (`.admin.key`); the project-root basename is included
     * so operators can sanity-check that AdminAuth resolved the right
     * directory without leaking the absolute filesystem layout.
     *
     * Shape:
     *   [
     *     'configured' => bool,
     *     'source'     => 'env' | 'file' | 'none',
     *     'env'        => bool,                // env var set + non-empty
     *     'env_name'   => 'REVOAP_ADMIN_TOKEN',
     *     'file'       => [
     *        'name'      => '.admin.key',      // basename only
     *        'exists'    => bool,
     *        'readable'  => bool,
     *        'empty'     => bool,              // exists but normalizes to ''
     *        'had_bom'   => bool,              // editor saved with UTF-8 BOM
     *        'size'      => int|null,          // bytes (null if unknown)
     *     ],
     *     'root'       => [
     *        'basename' => string,             // e.g. 'AI-RevoAp'
     *        'source'   => 'pinned' | 'config' | 'src-relative'
     *                    | 'src-relative-realpath' | 'document-root'
     *                    | 'walk' | 'cwd' | 'fallback',
     *     ],
     *   ]
     *
     * @return array<string,mixed>
     */
    public static function diagnostics(): array
    {
        $env = self::envToken();
        $info = self::keyFileInfo();
        $envHas = $env !== null;
        // "fileHas" is now driven by the actual read outcome: a file
        // that exists but cannot be read is NOT considered configured.
        // This is what the regression test guards against - the old
        // `readable && !empty` shape produced `empty=true` for read
        // failures which made `exists=true,size>0,empty=true` valid,
        // confusing operators staring at a 65-byte file on disk.
        $fileHas = ($info['read_ok'] ?? false) === true
            && (string)($info['contents'] ?? '') !== '';
        $cfgToken = self::configToken();
        $cfgHas = $cfgToken !== null;
        $source = $envHas
            ? 'env'
            : ($fileHas ? 'file' : ($cfgHas ? 'config' : 'none'));
        $rootInfo = self::rootInfo();
        // Surface every candidate that was inspected so operators can
        // confirm AdminAuth searched the directory they expected. Paths
        // are reduced to basenames; absolute filesystem layout never
        // leaves the host. The token length (env or file) is reported
        // because a non-zero length is a strong "configured" signal that
        // the operator can verify visually.
        $checked = [];
        $fileName = self::fileName();
        foreach (RootFiles::candidates() as $entry) {
            $path = $entry['path'];
            if ($path === '') { continue; }
            $key = $path . DIRECTORY_SEPARATOR . $fileName;
            $checked[] = [
                'source'    => $entry['source'],
                'basename'  => basename($path),
                'has_key'   => @is_file($key),
                'has_signature' => @is_file($path . '/config.php') || @is_file($path . '/index.php'),
            ];
        }
        $tokenLen = null;
        if ($envHas && $env !== null) {
            $tokenLen = strlen($env);
        } elseif ($fileHas) {
            $tokenLen = strlen((string)($info['contents'] ?? ''));
        } elseif ($cfgHas && $cfgToken !== null) {
            $tokenLen = strlen($cfgToken);
        }
        // empty=null when we could not read the file but it exists with
        // bytes on disk - we explicitly cannot say whether the contents
        // are empty in that case. This kills the misleading
        // exists=true,size>0,empty=true triplet from earlier versions.
        $readOk = (bool)($info['read_ok'] ?? false);
        if ($readOk) {
            $emptyField = (string)($info['contents'] ?? '') === '';
        } else {
            $emptyField = ($info['exists'] ?? false) ? null : true;
        }
        return [
            'configured' => $envHas || $fileHas || $cfgHas,
            'source'     => $source,
            // v1.8.60 — the gate's own state, first: "is a token configured"
            // is only half the answer, and an install with
            // `Protect_Settings_by_Admin_Key = 0` is not misconfigured when
            // `configured` is false — it is unprotected on purpose.
            'protection' => [
                'key'      => self::PROTECT_KEY,
                'enabled'  => self::protectionEnabled(),
                'location' => self::protectionLocation(),
            ],
            'env'        => $envHas,
            'env_name'   => self::envName(),
            'config'     => [
                // True when a non-empty `admin.token` is set in the
                // loaded config tree (typically supplied via the
                // gitignored `.config.php` overlay). Never reveals the
                // value - only whether one is present and its length.
                'has_token'    => $cfgHas,
                'token_length' => $cfgHas && $cfgToken !== null ? strlen($cfgToken) : null,
            ],
            'file'       => [
                'name'       => self::fileName(),
                'exists'     => (bool)($info['exists'] ?? false),
                'readable'   => (bool)($info['readable'] ?? false),
                'read_ok'    => $readOk,
                'empty'      => $emptyField,
                'had_bom'    => (bool)($info['had_bom'] ?? false),
                'size'       => $info['size'] ?? null,
                'read_error' => $info['read_error'] ?? null,
            ],
            'root'       => [
                'basename' => basename($rootInfo['path']),
                'source'   => $rootInfo['source'],
            ],
            'checked'    => $checked,
            'token_length' => $tokenLen,
        ];
    }

    /**
     * Pull the candidate admin token presented by the request, in
     * priority order. Empty when no candidate is found.
     */
    public static function presented(): string
    {
        $hdr = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
        if (is_string($hdr) && $hdr !== '') {
            return trim($hdr);
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($auth) && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        $q = $_GET['admin_token'] ?? '';
        if (is_string($q) && $q !== '') {
            return trim($q);
        }
        $c = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($c) && $c !== '') {
            return trim($c);
        }
        return '';
    }

    /** True when the presented token matches the configured admin token. */
    public static function check(): bool
    {
        $configured = self::loadToken();
        if ($configured === null || $configured === '') {
            return false;
        }
        $presented = self::presented();
        if ($presented === '') {
            return false;
        }
        return hash_equals($configured, $presented);
    }

    /**
     * Compare an explicit token value (CLI tools, cron jobs) with the
     * configured admin token. Unlike check() this does not read
     * $_SERVER / $_GET / cookies; it takes the presented value as a
     * parameter. Used by bin/memory-retention.php so CLI entry points
     * share the exact same token sources and comparison as the web UI.
     */
    public static function verifyToken(string $presented): bool
    {
        $configured = self::loadToken();
        if ($configured === null || $configured === '') {
            return false;
        }
        $token = trim($presented);
        if ($token === '') {
            return false;
        }
        return hash_equals($configured, $token);
    }

    /**
     * Issue an HttpOnly cookie pinning the admin token for this browser
     * session. Call only after check() succeeds via header/query, never
     * on a failed request.
     */
    public static function setSessionCookie(string $token, int $lifetimeSeconds = 7200): void
    {
        if (headers_sent()) {
            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + max(60, $lifetimeSeconds),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** Drop the session cookie (logout). */
    public static function clearSessionCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Mask a secret for display. Returns '' for empty input.
     */
    public static function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($value, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($value, -4);
    }

    /**
     * Resolve the project root for .admin.key lookup. Convenience
     * wrapper around rootInfo() that returns just the path.
     */
    public static function rootDir(): string
    {
        return self::rootInfo()['path'];
    }

    /**
     * Resolve the project root and report which strategy produced it.
     * Delegates to RootFiles so the same resolution is shared with
     * Config.php and any other resolver - `.admin.key`, `.url`,
     * `.sql.php`, and `.{provider}.key` all walk the identical
     * candidate list.
     *
     * @return array{path:string,source:string}
     */
    public static function rootInfo(): array
    {
        return RootFiles::resolveRootInfo(self::fileName());
    }

    /**
     * Read the admin token from the environment. Returns null when the
     * variable is unset or trims to empty. Both $_ENV and getenv() are
     * consulted because some SAPIs populate only one. Lookup order:
     * the managed name first, then the canonical constant, then the
     * deprecated legacy alias (accepted with a one-time warn; the
     * token value is never logged).
     */
    private static function envToken(): ?string
    {
        $names = [self::envName(), self::ENV, self::LEGACY_ENV];
        $names = array_values(array_unique(array_filter(
            $names,
            static fn(string $n): bool => $n !== ''
        )));
        foreach ($names as $name) {
            $value = null;
            $env = $_ENV[$name] ?? null;
            if (is_string($env) && trim($env) !== '') {
                $value = trim($env);
            }
            if ($value === null) {
                $g = getenv($name);
                if (is_string($g) && trim($g) !== '') {
                    $value = trim($g);
                }
            }
            if ($value === null && function_exists('apache_getenv')) {
                $apache = @apache_getenv($name);
                if (is_string($apache) && trim($apache) !== '') {
                    $value = trim($apache);
                }
            }
            if ($value === null) {
                continue;
            }
            if (strcasecmp($name, self::LEGACY_ENV) === 0 && !self::$legacyEnvWarned) {
                self::$legacyEnvWarned = true;
                Logger::warn('admin.auth.legacy_env', [
                    'hint' => self::LEGACY_ENV . ' is deprecated; use ' . self::ENV,
                ]);
            }
            return $value;
        }
        return null;
    }

    /**
     * Delegate to RootFiles so AdminAuth and the rest of the app share
     * a single resolver and reader. The returned shape is a superset
     * of the legacy `keyFileInfo()` output - callers in this class
     * read both `read_ok`/`contents`/`read_error` (new) and the
     * historical `readable`/`empty`/`size`/`had_bom` keys.
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
     *   read_error:string|null,
     *   empty:bool
     * }
     */
    private static function keyFileInfo(): array
    {
        $info = RootFiles::readDotFile(self::fileName());
        // Add the legacy `empty` boolean for any internal callers that
        // expect a non-null answer; diagnostics() recomputes its own
        // tri-state version directly from `read_ok`/`contents`.
        $info['empty'] = ($info['contents'] ?? '') === '';
        return $info;
    }
}
