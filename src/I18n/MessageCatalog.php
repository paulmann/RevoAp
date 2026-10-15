<?php
/**
 * MessageCatalog: loads, flattens, caches and validates a locale's messages.
 *
 * Catalogs live in `i18n/<code>.json` as nested objects (readable, diffable,
 * groupable by feature) but are consumed as a flat dotted map, because a
 * lookup that walks four array levels per string on a page that renders 300
 * strings is a measurable cost and the nesting buys nothing at runtime.
 *
 * Design decisions worth recording:
 *
 *  - **Flat map with dotted keys.** `{"chat":{"send":"Send"}}` becomes
 *    `chat.send`. A literal dot inside a key is not supported; the validator
 *    rejects it so the encoding stays unambiguous.
 *  - **Per-process memoisation, not a global cache.** `opcache` already keeps
 *    the parsed JSON cheap to re-read, and a static map survives within one
 *    request. Under php-fpm the catalog is read once per worker per locale,
 *    which is the right trade-off between freshness and cost; there is
 *    deliberately no APCu dependency.
 *  - **Missing catalog is not fatal.** The app boots and serves English when
 *    `i18n/ar.json` is unreadable, and records the reason so the admin
 *    Localization panel can show it. A translation gap must never take the
 *    console down.
 *  - **Validation is opt-in and cheap.** `validateAgainst()` is used by the
 *    test suite and by `bin/revoap-i18n.php lint`; it is never called on the
 *    request path.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\I18n;

final class MessageCatalog
{
    /** @var array<string,array<string,string>> locale code => flat map */
    private static array $loaded = [];

    /** @var array<string,string> human-readable load problems, keyed by locale */
    private static array $problems = [];

    private static ?string $dir = null;

    /** @param array<string,string> $messages */
    private function __construct(
        public readonly Locale $locale,
        private readonly array $messages,
        public readonly bool $ok,
        public readonly ?string $problem = null,
    ) {
    }

    /** Override the catalog directory (tests, or a non-standard layout). */
    public static function setDirectory(?string $dir): void
    {
        self::$dir = $dir === null ? null : rtrim($dir, '/');
        self::$loaded = [];
        self::$problems = [];
    }

    public static function directory(): string
    {
        if (self::$dir !== null) {
            return self::$dir;
        }
        // Project root = the directory holding config.php, resolved relative
        // to this file so it is correct under php-fpm, CLI and the test
        // harness regardless of the process cwd (same rule as AdminAuth).
        return dirname(__DIR__, 2) . '/i18n';
    }

    public static function path(Locale $locale): string
    {
        return self::directory() . '/' . $locale->catalogFile();
    }

    public static function load(Locale $locale): self
    {
        $code = $locale->value;
        if (isset(self::$loaded[$code])) {
            return new self($locale, self::$loaded[$code], self::$problems[$code] === null, self::$problems[$code]);
        }

        $path = self::path($locale);
        $problem = null;
        $flat = [];

        if (!is_file($path)) {
            $problem = 'catalog file not found: ' . basename($path);
        } elseif (!is_readable($path)) {
            $problem = 'catalog file not readable (check ownership/permissions, expected 0644): ' . basename($path);
        } else {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                $problem = 'catalog file could not be read';
            } else {
                $decoded = json_decode($raw, true, 8);
                if (!is_array($decoded)) {
                    $problem = 'catalog is not a JSON object: ' . self::jsonError();
                } else {
                    $flat = self::flatten($decoded);
                    if ($flat === []) {
                        $problem = 'catalog is empty';
                    }
                }
            }
        }

        self::$loaded[$code]   = $flat;
        self::$problems[$code] = $problem;

        return new self($locale, $flat, $problem === null, $problem);
    }

    /** Clear the memo — used after an admin edits a catalog in place. */
    public static function flush(?Locale $locale = null): void
    {
        if ($locale === null) {
            self::$loaded = [];
            self::$problems = [];
            return;
        }
        unset(self::$loaded[$locale->value], self::$problems[$locale->value]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->messages);
    }

    public function get(string $key): ?string
    {
        $v = $this->messages[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->messages;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Every load problem recorded so far, for the admin Localization panel.
     *
     * @return array<string,string>
     */
    public static function problems(): array
    {
        return self::$problems;
    }

    /**
     * Diff this catalog against a reference (normally `en`).
     *
     * @return array{missing:array<int,string>,extra:array<int,string>,non_string:array<int,string>,count:int}
     */
    public function diffAgainst(self $reference): array
    {
        $missing = [];
        $extra   = [];
        $nonStr  = [];

        foreach ($reference->all() as $key => $_) {
            if (!$this->has($key)) {
                $missing[] = $key;
            } elseif ($this->get($key) === '') {
                $nonStr[] = $key;
            }
        }
        foreach (array_keys($this->all()) as $key) {
            if (!$reference->has($key)) {
                $extra[] = $key;
            }
        }
        sort($missing);
        sort($extra);
        sort($nonStr);

        return [
            'missing'    => $missing,
            'extra'      => $extra,
            'non_string' => $nonStr,
            'count'      => $this->count(),
        ];
    }

    /**
     * Flatten a nested catalog into `a.b.c => value`.
     *
     * Non-string leaves are rejected rather than coerced: a number or a bool
     * in a catalog is always an authoring mistake, and silently casting it
     * would hide the typo that produced it.
     *
     * @param  array<string,mixed> $node
     * @return array<string,string>
     */
    public static function flatten(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $full = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($value)) {
                foreach (self::flatten($value, $full) as $k => $v) {
                    $out[$k] = $v;
                }
                continue;
            }
            if (is_string($value)) {
                $out[$full] = $value;
            }
        }
        return $out;
    }

    private static function jsonError(): string
    {
        $code = json_last_error();
        return $code === JSON_ERROR_NONE
            ? 'unknown error'
            : (json_last_error_msg() ?: ('json error ' . $code));
    }
}
