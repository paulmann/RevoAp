<?php
/**
 * MdViewerIni - the `.md.ini` file MD.Viewer reads for hard overrides.
 *
 * Why this exists: the operator's report was "I pressed Install and it says
 * the installer has no usable API_KEY in .md.ini". The key is only one half
 * of the story - the file itself was never written, because the install
 * relied on "open the extension once in a browser and it will create one".
 * An install that has to be completed by hand is not an install.
 *
 * So the server writes the file itself, and this class owns the format:
 *
 *   - {@see render()} produces the exact file, byte for byte, with the
 *     comments the upstream project ships, the six boolean keys and the
 *     BROWSE_DIR string setting spelled out. That matters: MD.Viewer's
 *     readIni() fills in its own
 *     defaults for keys a file omits, and those defaults are NOT what the
 *     operator asks for here (ALLOW_UPDATE would come back false and
 *     ALLOW_CREATE_INDEX_PHP_LINK true).
 *   - {@see parse()} reads it back, filling missing keys with our defaults
 *     and reporting anything it had to ignore.
 *   - {@see extractKey()} reads the machine API key, ignoring comments - so
 *     the doc lines that *mention* `api_key=` are never mistaken for it.
 *
 * Two validation levels, on purpose:
 *   - the write path (the Extensions install menu, a JSON API) is STRICT:
 *     only true/false is accepted, anything else is a 400 ({@see toBool()});
 *     the string settings carry their own check ({@see toPath()});
 *   - the read path is LENIENT: hand-edited files using the on/off/yes/no/1/0
 *     spellings MD.Viewer itself accepts are reported as the value the
 *     installer will actually use, never as "default applied" ({@see parseValue()}).
 *
 * The API key is not a setting: it is generated once
 * (`mdv_` . bin2hex(random_bytes(24)), 52 characters, compared with
 * hash_equals by the installer) and then preserved across writes. It is
 * only ever read and written by the server; nothing in this class returns it
 * into a UI payload.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-09-26
 */
declare(strict_types=1);

namespace App\Extensions;

final class MdViewerIni
{
    /** File name inside extensions/md-viewer/. */
    public const FILE = '.md.ini';

    /** The boolean keys, in the order the file writes them. */
    public const KEYS = [
        'DISABLE_UPLOAD',
        'DISABLE_CLIPBOARD',
        'DISABLE_SAVE_CLIPBOARD_TO_FILE',
        'ALLOW_UPDATE',
        'ALLOW_RESTORE',
        'ALLOW_CREATE_INDEX_PHP_LINK',
    ];

    /**
     * The string settings - the seventh key. BROWSE_DIR is the folder whose
     * documents the viewer lists and opens; MD.Viewer resolves a relative
     * value (the default `/share/`) against DOCUMENT_ROOT. It is NOT a
     * boolean, so it never travels through toBool()/parseValue().
     */
    public const STRING_KEYS = ['BROWSE_DIR'];

    /** The string settings' defaults, keyed by name. */
    public const STRING_DEFAULTS = ['BROWSE_DIR' => '/share/'];

    /**
     * Installation-level keys MD.Scribe 3 manages in the SAME .md.ini
     * (v2.1.0, T4). RevoAp never writes them — Scribe appends them with
     * safe defaults on first load and its admin panel edits them — but the
     * parser must recognise them, for two reasons:
     *   - an operator's Scribe install would otherwise show a wall of
     *     "unknown keys" in the Extensions panel for keys that are exactly
     *     where they belong; and
     *   - mergeInto() (below) preserves every line it does not own, and
     *     this list documents WHAT those lines are.
     */
    public const SCRIBE_MANAGED_KEYS = [
        'SITE_NAME', 'HOME_DOC', 'MAX_UPLOAD_MB',
        'ADMIN_ENABLED', 'ADMIN_USERNAME', 'ADMIN_PASSWORD', 'ADMIN_PASSWORD_HASH',
        'ADMIN_RATE_LIMIT', 'ALLOW_RAW_HTML', 'ALLOW_IFRAMES', 'ALLOW_EXTERNAL_IMAGES',
        'SECURITY_HEADERS', 'SECURITY_CSP', 'TRUSTED_PROXIES',
        'CACHE_ENABLED', 'CACHE_TTL', 'MAX_SCAN_DEPTH', 'MAX_FILES_SCAN',
        'SEARCH_MAX_RESULTS', 'SITEMAP_ENABLED', 'RSS_ENABLED',
        'THEME_DEFAULT', 'MODE_DEFAULT', 'SORT_DEFAULT_KEY', 'SORT_DEFAULT_DIR',
        'APP_ENV', 'APP_DEBUG', 'LOG_LEVEL', 'LOG_RETENTION_DAYS',
    ];

    /** Longest string-setting value that may be written (a path, not a payload). */
    public const STRING_MAX_LENGTH = 255;

    /**
     * The values the install menu is pre-filled with when there is no file -
     * and the values the file is created with. ALLOW_UPDATE must be true,
     * otherwise the machine API could never install anything.
     */
    public const DEFAULTS = [
        'DISABLE_UPLOAD'                  => true,
        'DISABLE_CLIPBOARD'               => false,
        'DISABLE_SAVE_CLIPBOARD_TO_FILE'  => true,
        'ALLOW_UPDATE'                    => true,
        'ALLOW_RESTORE'                   => false,
        'ALLOW_CREATE_INDEX_PHP_LINK'     => false,
    ];

    /** Openers the panel labels as "recommended" (see render()). */
    public const RECOMMENDED = [
        'DISABLE_UPLOAD' => true,
    ];

    /** One human label per key, for the install menu. */
    public const LABELS = [
        'DISABLE_UPLOAD'                 => 'Disable the "Upload .md" button in the file browser',
        'DISABLE_CLIPBOARD'              => 'Disable the "Preview Clipboard" button in the file browser',
        'DISABLE_SAVE_CLIPBOARD_TO_FILE' => 'Disable the "Save to File" button in clipboard preview (saved into the BROWSE_DIR folder)',
        'ALLOW_UPDATE'                   => 'Allow self-update (updater.php?action=install)',
        'ALLOW_RESTORE'                  => 'Allow restoring a backup via updater.php?restore=latest',
        'ALLOW_CREATE_INDEX_PHP_LINK'    => 'Allow creating/removing the index.php hard link from the Settings panel',
        'BROWSE_DIR'                     => 'Folder whose documents the viewer lists and opens (default /share/)',
    ];

    /** The name of the machine API key line (not one of the seven settings). */
    public const KEY_NAME = 'API_KEY';

    /** API_KEY shape generated here. */
    public const KEY_PREFIX = 'mdv_';
    public const KEY_BYTES  = 24;
    public const KEY_LENGTH = 52;

    /** Longest value a key line may carry - a hostile file must not bloat the write. */
    private const KEY_MAX_LENGTH = 200;

    /**
     * A fresh machine API key: `mdv_` + 24 random bytes in hex = 52 chars.
     * Uses random_bytes (CSPRNG), never rand()/uniqid().
     */
    public static function generateKey(): string
    {
        return self::KEY_PREFIX . bin2hex(random_bytes(self::KEY_BYTES));
    }

    /** True when `$key` has the exact shape this install generates. */
    public static function looksGenerated(string $key): bool
    {
        $pattern = '/^' . preg_quote(self::KEY_PREFIX, '/') . '[0-9a-f]{' . (self::KEY_BYTES * 2) . '}$/';
        return preg_match($pattern, $key) === 1;
    }

    /**
     * Display value for the panel: the prefix and four dots, nothing else.
     * Never derived from the key body, so no length or tail leaks.
     */
    public static function maskKey(string $key): string
    {
        if ($key === '') {
            return '';
        }
        return (str_starts_with($key, self::KEY_PREFIX) ? self::KEY_PREFIX : '') . '••••';
    }

    /**
     * Is this value safe to write on an `API_KEY = ...` line? The bar is
     * structural, not stylistic: non-empty, no control characters, no CR/LF,
     * and short enough that the line stays a line.
     */
    public static function isSafeKey(string $key): bool
    {
        if ($key === '' || strlen($key) > self::KEY_MAX_LENGTH) {
            return false;
        }
        return preg_match('/[\x00-\x1F\x7F]/', $key) !== 1;
    }

    /**
     * STRICT coercion for the write path: booleans and the strings
     * 'true'/'false' only. '1', 'yes', 'on', an int or anything else is
     * rejected with null - the caller answers 400.
     */
    public static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === 'true')  { return true; }
            if ($v === 'false') { return false; }
        }
        return null;
    }

    /**
     * LENIENT coercion for reading a file: the spellings MD.Viewer's own
     * readIni() accepts. Returns null for anything unrecognised.
     */
    public static function parseValue(string $value): ?bool
    {
        $v = strtolower(trim($value, " \t\"'"));
        if (in_array($v, ['1', 'true', 'on', 'yes'], true))  { return true; }
        if (in_array($v, ['0', 'false', 'off', 'no'], true)) { return false; }
        return null;
    }

    /**
     * STRICT coercion for a string setting (BROWSE_DIR). Trimmed first, then
     * checked structurally: it has to stay on one `NAME = value` line, so a
     * CR/LF or a NUL byte - which would let the value append a line to
     * .md.ini - is rejected with null. An EMPTY value is VALID and means
     * "the folder md.php itself is in"; it is returned as an empty string
     * and never silently replaced by the default (filling a gap is
     * {@see withDefaults()}'s job, on the write path).
     */
    public static function toPath(string $value): ?string
    {
        $v = trim($value);
        if (strlen($v) > self::STRING_MAX_LENGTH) {
            return null;
        }
        if (str_contains($v, "\0") || str_contains($v, "\n") || str_contains($v, "\r")) {
            return null;
        }
        return $v;
    }

    /**
     * Validate a settings payload from the panel. Strict: unknown keys and
     * non-boolean values are collected as errors rather than dropped, so the
     * endpoint can answer 400 with a reason instead of quietly doing half
     * the job.
     *
     * @param  array<string,mixed> $input
     * @return array{ok:bool,values:array<string,bool|string>,errors:list<string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $values = [];
        foreach ($input as $name => $value) {
            $key = is_string($name) ? strtoupper(trim($name)) : '';
            if ($key === '' || !in_array($key, self::allKeys(), true)) {
                $errors[] = 'unknown setting "' . (is_string($name) ? $name : gettype($name)) . '"';
                continue;
            }
            if (self::isStringKey($key)) {
                if (!is_string($value)) {
                    $errors[] = $key . ' must be a string';
                    continue;
                }
                $path = self::toPath($value);
                if ($path === null) {
                    // The reason, not just the name: a line break here is what
                    // would have appended a line to .md.ini.
                    $errors[] = $key . ' must be a single-line value of at most '
                        . self::STRING_MAX_LENGTH . ' characters (no line breaks, no NUL bytes)';
                    continue;
                }
                $values[$key] = $path;
                continue;
            }
            $bool = self::toBool($value);
            if ($bool === null) {
                $errors[] = $key . ' must be true or false';
                continue;
            }
            $values[$key] = $bool;
        }
        return ['ok' => $errors === [], 'values' => $values, 'errors' => $errors];
    }

    /**
     * Read a file's declarations. `values` always carries all seven keys -
     * the six booleans plus BROWSE_DIR - with the defaults filling the gaps,
     * `present` lists what the file actually said, `invalid` lists keys whose
     * value was unreadable (defaults applied) and `unknown` lists names we do
     * not manage.
     *
     * @return array{values:array<string,bool|string>,present:list<string>,invalid:list<string>,unknown:list<string>}
     */
    public static function parse(string $raw): array
    {
        $values  = self::defaults();
        $present = [];
        $invalid = [];
        $unknown = [];
        $scribeManaged = [];
        foreach (self::lines($raw) as $line) {
            $name = $line['name'];
            if ($name === self::KEY_NAME) {
                // The machine key is not a setting: it is neither
                // reported as unknown nor listed among the seven.
                continue;
            }
            if (self::isStringKey($name)) {
                $path = self::toPath($line['value']);
                if ($path === null) {
                    $invalid[] = $name;
                    continue;
                }
                $values[$name] = $path;
                $present[]     = $name;
                continue;
            }
            if (!in_array($name, self::KEYS, true)) {
                // A key MD.Scribe manages in the shared .md.ini is not an
                // "unknown key" — reporting it as one would tell the
                // operator their Scribe install is misconfigured when it is
                // configured exactly as shipped.
                if (in_array($name, self::SCRIBE_MANAGED_KEYS, true)) {
                    $scribeManaged[] = $name;
                } else {
                    $unknown[] = $name;
                }
                continue;
            }
            $bool = self::parseValue($line['value']);
            if ($bool === null) {
                $invalid[] = $name;
                continue;
            }
            $values[$name] = $bool;
            $present[]     = $name;
        }
        return ['values' => $values, 'present' => $present, 'invalid' => $invalid,
                'unknown' => $unknown, 'scribe_managed' => $scribeManaged];
    }

    /**
     * Surgical merge for a .md.ini that ANOTHER product also writes
     * (MD.Scribe mode, v2.1.0 T4).
     *
     * render() rewrites the whole file and is correct for a legacy
     * MD.Viewer install, where the seven RevoAp keys are the whole
     * contract. On a Scribe install the same rewrite would drop
     * ADMIN_PASSWORD_HASH (locking the operator out of the Scribe admin
     * panel), SITE_NAME, cache and feed settings — every key Scribe
     * appended on its first load. So in Scribe mode RevoAp only touches
     * the lines it owns: each of the seven keys is replaced in place (or
     * appended once, under its own header, when the file lacks it), and
     * every other line — comments included — survives byte-for-byte.
     *
     * @param array<string,bool|string> $values the seven, defaults folded in
     */
    public static function mergeInto(string $existingRaw, array $values, string $apiKey): string
    {
        if (!self::isSafeKey($apiKey)) {
            throw new \InvalidArgumentException('Refusing to merge .md.ini with an unusable API key.');
        }
        $v = self::withDefaults($values);
        $b = static fn(bool $on): string => $on ? 'true' : 'false';
        $canonical = [];
        foreach (self::KEYS as $key) {
            $canonical[$key] = $key . ' = ' . $b((bool)$v[$key]);
        }
        foreach (self::STRING_KEYS as $key) {
            $canonical[$key] = $key . ' = ' . (string)$v[$key];
        }
        $canonical[self::KEY_NAME] = self::KEY_NAME . ' = ' . $apiKey;

        $seen  = [];
        $lines = explode("\n", $existingRaw);
        $out   = [];
        foreach ($lines as $line) {
            $trimmed = rtrim($line, "\r");
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $trimmed, $m) === 1) {
                $name = strtoupper($m[1]);
                if (isset($canonical[$name])) {
                    // Last occurrence wins upstream (parse_ini semantics),
                    // and so does it here: an earlier line for the same key
                    // is kept verbatim (it is the operator's history) and
                    // the LAST one is replaced with the canonical value.
                    $seen[$name] = true;
                    $out[] = "\x00REPLACE:" . $name . "\x00";
                    continue;
                }
            }
            $out[] = $line;
        }
        // Replace markers resolve to the canonical line for that key; with
        // duplicates in the file only the final marker carries the value and
        // earlier ones are commented out so a hand-edited duplicate cannot
        // silently override the write (parse_ini takes the LAST line).
        $lastIndex = [];
        foreach ($out as $i => $line) {
            if (str_starts_with($line, "\x00REPLACE:")) {
                $name = substr($line, 9, -1);
                $lastIndex[$name] = $i;
            }
        }
        foreach ($out as $i => $line) {
            if (str_starts_with($line, "\x00REPLACE:")) {
                $name = substr($line, 9, -1);
                $out[$i] = ($lastIndex[$name] ?? -1) === $i
                    ? $canonical[$name]
                    : '; superseded duplicate: ' . $canonical[$name];
            }
        }
        // Keys the file does not carry yet are appended under one header —
        // appended, not interleaved: Scribe's own "missing keys are appended
        // on load" rule means the tail of the file is already shared ground.
        $missing = array_values(array_filter(
            array_keys($canonical),
            static fn(string $k): bool => !isset($seen[$k])
        ));
        if (count($missing) > 0) {
            // (explode() never yields an empty list, so $out always has a
            // last element to look at here.)
            if (trim((string)end($out)) !== '') {
                $out[] = '';
            }
            $out[] = '; Managed by RevoAp (Extensions tab). MD.Scribe appends its own keys below on load.';
            foreach ($missing as $name) {
                $out[] = $canonical[$name];
            }
        }
        $merged = implode("\n", $out);
        // Keep the file's trailing-newline convention exactly as render()
        // produces it, so the idempotency check in writeIni() compares like
        // with like on the second save.
        if (!str_ends_with($merged, "\n")) {
            $merged .= "\n";
        }
        return $merged;
    }

    /**
     * The API_KEY the installer would read, or null. Comment lines are
     * skipped, so the documentation line that mentions `api_key=` and a
     * commented-out key are both ignored - the failure mode the Extensions
     * tab reported ("no usable API_KEY") must not be produced by our own
     * template.
     */
    public static function extractKey(string $raw): ?string
    {
        foreach (self::lines($raw) as $line) {
            if ($line['name'] !== self::KEY_NAME) {
                continue;
            }
            // PHP's ini parser unescapes a surrounding quote pair, so a
            // hand-edited "mdv_..." has to read the same here.
            return self::unquoted($line['value']);
        }
        return null;
    }

    /**
     * Strip one surrounding pair of quotes (double or single) and the
     * whitespace around it; null when nothing usable is left.
     */
    private static function unquoted(string $value): ?string
    {
        $out = trim($value);
        $len = strlen($out);
        if ($len >= 2) {
            $first = $out[0];
            $last  = $out[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $out = trim(substr($out, 1, -1));
            }
        }
        return self::isSafeKey($out) ? $out : null;
    }

    /**
     * Every `NAME = value` line, comments / blanks / sections / junk skipped.
     *
     * @return list<array{name:string,value:string}>
     */
    private static function lines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#' || $line[0] === '[') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($line, 0, $eq));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;
            }
            $out[] = ['name' => $name, 'value' => trim(substr($line, $eq + 1))];
        }
        return $out;
    }

    /**
     * The exact file MD.Viewer expects, with every key present.
     *
     * @param array<string,bool|string> $values Missing keys fall back to the defaults.
     * @throws \InvalidArgumentException when `$apiKey` cannot be written safely.
     */
    public static function render(array $values, string $apiKey): string
    {
        if (!self::isSafeKey($apiKey)) {
            throw new \InvalidArgumentException('Refusing to render .md.ini with an unusable API key.');
        }
        $v = self::withDefaults($values);
        $b = static fn(bool $on): string => $on ? 'true' : 'false';
        // The string setting is written as it is; withDefaults() has already
        // guaranteed that it stays on one line.
        $dir = (string)$v['BROWSE_DIR'];

        return "; MD.Viewer server-side configuration\n"
            . "; Edit this file to hard-override settings.\n"
            . "; Changes here cannot be undone from the Settings panel.\n"
            . "\n"
            . "; Disable the \"Upload .md\" button in the file browser (recommended: true)\n"
            . 'DISABLE_UPLOAD = ' . $b($v['DISABLE_UPLOAD']) . "\n"
            . "\n"
            . "; Disable the \"Preview Clipboard\" button in the file browser\n"
            . 'DISABLE_CLIPBOARD = ' . $b($v['DISABLE_CLIPBOARD']) . "\n"
            . "\n"
            . "; Disable the \"Save to File\" button in clipboard preview (saved into the folder\n; set by BROWSE_DIR, otherwise uploads.md/)\n"
            . 'DISABLE_SAVE_CLIPBOARD_TO_FILE = ' . $b($v['DISABLE_SAVE_CLIPBOARD_TO_FILE']) . "\n"
            . "\n"
            . 'ALLOW_UPDATE = ' . $b($v['ALLOW_UPDATE']) . "\n"
            . "; Allow restoring a backup via updater.php?restore=latest or ?restore=[version]\n"
            . 'ALLOW_RESTORE = ' . $b($v['ALLOW_RESTORE']) . "\n"
            . "\n"
            . "; Allow creating/removing the index.php hard link from the Settings panel\n"
            . 'ALLOW_CREATE_INDEX_PHP_LINK = ' . $b($v['ALLOW_CREATE_INDEX_PHP_LINK']) . "\n"
            . "\n"
            . "; Folder whose documents the viewer lists and opens. A relative value\n"
            . "; is resolved against DOCUMENT_ROOT; leave it empty for the folder\n"
            . "; md.php itself lives in (default: /share/, where RevoAp stores runs)\n"
            . 'BROWSE_DIR = ' . $dir . "\n"
            . "\n"
            . "; Machine API key - used by external clients (e.g. RevoAp) for\n"
            . "; updater.php?api_key=<value>&action=status|conflicts|install&format=json\n"
            . "; Keep it secret: it authorises file installs. Rotate by editing this line.\n"
            . 'API_KEY = ' . $apiKey . "\n";
    }

    /** Every key the file carries: the six booleans, then the string keys. */
    public static function allKeys(): array
    {
        return array_merge(self::KEYS, self::STRING_KEYS);
    }

    /** Every key with its default value, booleans and strings alike. */
    public static function defaults(): array
    {
        return array_merge(self::DEFAULTS, self::STRING_DEFAULTS);
    }

    /** True for a setting whose value is a string rather than a boolean. */
    public static function isStringKey(string $key): bool
    {
        return in_array($key, self::STRING_KEYS, true);
    }

    /**
     * All seven keys, defaults for anything missing or unusable. The six
     * booleans behave exactly as before; the string settings are trimmed and
     * validated ({@see toPath()}) and fall back to their own default when a
     * value could not be written on a single line.
     *
     * @param  array<string,mixed> $values
     * @return array<string,bool|string>
     */
    public static function withDefaults(array $values): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $bool = null;
            if (array_key_exists($key, $values)) {
                $raw  = $values[$key];
                $bool = self::toBool($raw);
                // A value read back from a file may use the lenient
                // on/off/yes/no/1/0 spellings; treat it as the value the
                // installer will use instead of silently defaulting.
                if ($bool === null && is_string($raw)) {
                    $bool = self::parseValue($raw);
                }
            }
            $out[$key] = $bool ?? self::DEFAULTS[$key];
        }
        foreach (self::STRING_KEYS as $key) {
            $raw = $values[$key] ?? null;
            $out[$key] = is_string($raw)
                ? (self::toPath($raw) ?? self::STRING_DEFAULTS[$key])
                : self::STRING_DEFAULTS[$key];
        }
        return $out;
    }
}
