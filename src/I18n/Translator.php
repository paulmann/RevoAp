<?php
/**
 * Translator: message lookup, interpolation, plurals and locale-aware
 * number/date formatting.
 *
 * This is the only class application code should touch for user-facing
 * text. It deliberately does **not** escape its output: the caller decides
 * whether the result goes into HTML, a JSON payload, a CSV cell or a log
 * line, and each of those needs different escaping. `App\Helpers\TemplateHelper`
 * escapes by default and offers `|raw` for the rare trusted case; the JS
 * twin (`assets/js/i18n.js`) uses `textContent`/attribute assignment by
 * default and never `innerHTML` with an unescaped translation.
 *
 * Interpolation syntax
 *   `{name}`           — replaced by `params['name']`
 *   `{count}`          — always available inside `tn()` even if not passed
 *   `{name, number}`   — locale-formatted number
 *   `{name, percent}`  — 0.421 → "42.1 %" (locale decimal separator)
 *   `{name, date}`     — locale short date
 *   `{name, datetime}` — locale date + time
 *   `{{literal}}`      — emits `{literal}`, so a message can show braces
 *
 * Unknown placeholders are left verbatim. That is intentional: a missing
 * parameter is a programming bug that must stay visible in the UI during
 * development rather than rendering as an empty hole.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\I18n;

final class Translator
{
    /** Process-wide active locale. Overridable per call via `withLocale()`. */
    private static Locale $active = Locale::English;

    /** @var array<string,self>|null per-locale instance memo */
    private static ?array $instances = null;

    /** Counts lookups that fell through to English, then to the key itself. */
    private static int $fallbackCount = 0;
    private static int $missCount     = 0;
    private static int $hitCount      = 0;

    private function __construct(public readonly Locale $locale)
    {
    }

    /* ------------------------------------------------------------------
     * Locale management
     * ---------------------------------------------------------------- */

    public static function setLocale(Locale $locale): void
    {
        self::$active = $locale;
    }

    public static function locale(): Locale
    {
        return self::$active;
    }

    public static function instance(Locale $locale): self
    {
        self::$instances ??= [];
        return self::$instances[$locale->value] ??= new self($locale);
    }

    /** The translator for the active locale. */
    public static function current(): self
    {
        return self::instance(self::$active);
    }

    /* ------------------------------------------------------------------
     * Lookup
     * ---------------------------------------------------------------- */

    /**
     * Translate a key.
     *
     * @param string               $key    Dotted catalog key, e.g. `chat.send`.
     * @param array<string,mixed>  $params Interpolation values.
     * @param Locale|null          $locale Override the active locale.
     */
    public static function t(string $key, array $params = [], ?Locale $locale = null): string
    {
        $loc   = $locale ?? self::$active;
        $value = self::instance($loc)->resolve($key);
        return self::interpolate($value ?? $key, $params, $loc);
    }

    /**
     * Translate a pluralised key.
     *
     * The catalog holds one entry per CLDR category (`models.selected.one`,
     * `.few`, `.many`, `.other`). Missing categories degrade down the
     * canonical list and finally to `other`, then to the base key, then to
     * the key text — so an incomplete catalog produces ugly-but-readable
     * output instead of a blank label.
     *
     * `count` is injected into the parameter list automatically because
     * virtually every plural message wants to print the number.
     */
    public static function tn(string $key, int|float $count, array $params = [], ?Locale $locale = null): string
    {
        $loc  = $locale ?? self::$active;
        $self = self::instance($loc);
        $cat  = PluralRules::category($loc, $count);

        $params += ['count' => $count, 'n' => $count];

        $value = $self->resolve($key . '.' . $cat)
            ?? $self->resolveWithCategoryFallback($key, $loc)
            ?? $self->resolve($key);

        // Fall back through the locale's chain (normally en) before giving up.
        if ($value === null) {
            foreach ($loc->fallbackChain() as $fb) {
                if ($fb === $loc) { continue; }
                $fbSelf = self::instance($fb);
                $fbCat  = PluralRules::category($fb, $count);
                $value  = $fbSelf->resolve($key . '.' . $fbCat)
                       ?? $fbSelf->resolveWithCategoryFallback($key, $fb)
                       ?? $fbSelf->resolve($key);
                if ($value !== null) { self::$fallbackCount++; break; }
            }
        }

        return self::interpolate($value ?? $key, $params, $loc);
    }

    /**
     * True when the key resolves in the active locale without touching the
     * English fallback. Used by the catalog-lint tooling and by tests that
     * assert translation completeness.
     */
    public static function has(string $key, ?Locale $locale = null): bool
    {
        return self::instance($locale ?? self::$active)->resolveOwn($key) !== null;
    }

    /** @return array{hits:int,fallbacks:int,misses:int} */
    public static function stats(): array
    {
        return [
            'hits'      => self::$hitCount,
            'fallbacks' => self::$fallbackCount,
            'misses'    => self::$missCount,
        ];
    }

    public static function resetStats(): void
    {
        self::$fallbackCount = 0;
        self::$missCount = 0;
        self::$hitCount = 0;
    }

    /**
     * The whole active catalog, flat, for `/api/i18n/{locale}`.
     *
     * Served to the SPA so the client can translate without a round-trip
     * per string. The payload is public by design — it contains UI labels
     * and no configuration, secrets or user data.
     *
     * @return array<string,string>
     */
    public static function export(?Locale $locale = null): array
    {
        $loc = $locale ?? self::$active;
        $merged = [];
        // Least specific first so the target locale overrides English; a
        // half-translated catalog still yields a complete, if mixed, map.
        foreach (array_reverse($loc->fallbackChain()) as $fb) {
            foreach (MessageCatalog::load($fb)->all() as $k => $v) {
                $merged[$k] = $v;
            }
        }
        ksort($merged);
        return $merged;
    }

    /* ------------------------------------------------------------------
     * Formatting helpers — locale-aware without requiring ext-intl.
     *
     * ext-intl is not a hard dependency of RevoAp (shared hosts routinely
     * lack it), so the small subset the UI needs is implemented directly.
     * When intl IS loaded it is used, because its CLDR data is more
     * complete than this table.
     * ---------------------------------------------------------------- */

    public static function number(int|float $n, int $decimals = 0, ?Locale $locale = null): string
    {
        $loc = $locale ?? self::$active;
        if (class_exists(\NumberFormatter::class)) {
            $fmt = new \NumberFormatter(self::bcp47($loc), \NumberFormatter::DECIMAL);
            $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $out = $fmt->format($n);
            if (is_string($out)) { return $out; }
        }
        [$dec, $sep] = self::separators($loc);
        return number_format((float)$n, $decimals, $dec, $sep);
    }

    public static function percent(float $fraction, int $decimals = 1, ?Locale $locale = null): string
    {
        $loc = $locale ?? self::$active;
        if (class_exists(\NumberFormatter::class)) {
            $fmt = new \NumberFormatter(self::bcp47($loc), \NumberFormatter::PERCENT);
            $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $out = $fmt->format($fraction);
            if (is_string($out)) { return $out; }
        }
        [$dec, $sep] = self::separators($loc);
        $body = number_format($fraction * 100, $decimals, $dec, $sep);
        // Non-breaking space before the sign is the CLDR convention for
        // every shipped locale except English and Chinese.
        $glue = match ($loc) {
            Locale::English, Locale::Chinese => '',
            default => "\u{00A0}",
        };
        return match ($loc) {
            Locale::Arabic  => $body . $glue . '٪',
            Locale::Chinese => $body . '%',
            Locale::English => $body . '%',
            default         => $body . $glue . '%',
        };
    }

    public static function dateTime(int $timestamp, ?Locale $locale = null, bool $withTime = true): string
    {
        $loc = $locale ?? self::$active;
        if (class_exists(\IntlDateFormatter::class)) {
            $fmt = new \IntlDateFormatter(
                self::bcp47($loc),
                \IntlDateFormatter::MEDIUM,
                $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE
            );
            if ($fmt !== false) {
                $fmt->setPattern($withTime ? self::datePattern($loc) : self::dateOnlyPattern($loc));
                $out = $fmt->format($timestamp);
                if (is_string($out) && $out !== '') { return $out; }
            }
        }
        $d = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(
            new \DateTimeZone((string)\App\Core\Config::tryGet('app.timezone', 'UTC'))
        );
        return $withTime
            ? $d->format(self::fallbackDateTimeFormat($loc))
            : $d->format(self::fallbackDateFormat($loc));
    }

    public static function date(int $timestamp, ?Locale $locale = null): string
    {
        return self::dateTime($timestamp, $locale, false);
    }

    /**
     * Relative time in words, locale-aware and CLDR-ordered.
     *
     * Used by the History list, the worker chip and the job queue, where
     * "3 minutes ago" is far more useful than a timestamp.
     */
    public static function timeAgo(int $timestamp, ?Locale $locale = null): string
    {
        $loc  = $locale ?? self::$active;
        $diff = time() - $timestamp;
        $future = $diff < 0;
        $diff = abs($diff);

        $units = [
            ['year',   31_536_000],
            ['month',  2_592_000],
            ['day',    86_400],
            ['hour',   3_600],
            ['minute', 60],
        ];
        foreach ($units as [$unit, $size]) {
            if ($diff >= $size) {
                $n = intdiv($diff, $size);
                $key = 'time.' . $unit . ($future ? '.from_now' : '.ago');
                return self::tn($key, $n, ['n' => $n], $loc);
            }
        }
        // Sub-minute. Past reads "just now"; future reads "in a moment", because
        // an event 20 s away has not happened yet and saying "just now" about it
        // is wrong in a queue or scheduler view where the distinction matters.
        return self::t($future ? 'time.in_a_moment' : 'time.just_now', [], $loc);
    }

    /* ------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    private function resolveOwn(string $key): ?string
    {
        return MessageCatalog::load($this->locale)->get($key);
    }

    private function resolve(string $key): ?string
    {
        foreach ($this->locale->fallbackChain() as $fb) {
            $v = MessageCatalog::load($fb)->get($key);
            if ($v !== null) {
                if ($fb === $this->locale) { self::$hitCount++; } else { self::$fallbackCount++; }
                return $v;
            }
        }
        self::$missCount++;
        return null;
    }

    /** Try the remaining CLDR categories, ending at `other`. */
    private function resolveWithCategoryFallback(string $base, Locale $loc): ?string
    {
        foreach (PluralRules::categoriesFor($loc) as $cat) {
            $v = $this->resolve($base . '.' . $cat);
            if ($v !== null) { return $v; }
        }
        return null;
    }

    /**
     * Replace `{placeholders}` in a message.
     *
     * @param array<string,mixed> $params
     */
    public static function interpolate(string $message, array $params, Locale $locale): string
    {
        if ($message === '' || !str_contains($message, '{')) {
            return $message;
        }
        // `{{literal}}` → placeholder-free output, handled after substitution
        // via a sentinel so a parameter named `literal` cannot collide.
        $sentinel = "\x00BRACE\x00";
        $message = str_replace(['{{', '}}'], [$sentinel . 'L', $sentinel . 'R'], $message);

        $message = preg_replace_callback(
            '/\{\s*([A-Za-z0-9_.\-]+)\s*(?:,\s*(number|percent|date|datetime|integer))?\s*\}/',
            static function (array $m) use ($params, $locale): string {
                $name = $m[1];
                if (!array_key_exists($name, $params)) {
                    return $m[0]; // leave verbatim: a visible bug beats a silent hole
                }
                $value = $params[$name];
                $kind  = $m[2] ?? '';
                return match ($kind) {
                    'number', 'integer' => self::number(is_numeric($value) ? $value + 0 : 0, $kind === 'integer' ? 0 : (is_float($value) ? 2 : 0), $locale),
                    'percent'           => self::percent(is_numeric($value) ? (float)$value : 0.0, 1, $locale),
                    'date'              => self::dateTime(is_numeric($value) ? (int)$value : time(), $locale, false),
                    'datetime'          => self::dateTime(is_numeric($value) ? (int)$value : time(), $locale, true),
                    default             => is_scalar($value) || $value === null
                                            ? (string)($value ?? '')
                                            : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$value),
                };
            },
            $message
        ) ?? $message;

        return str_replace([$sentinel . 'L', $sentinel . 'R'], ['{', '}'], $message);
    }

    /**
     * Decimal and thousands separators per locale, used by the non-intl
     * fallback path. Values are the CLDR defaults for each language.
     *
     * @return array{0:string,1:string}
     */
    private static function separators(Locale $locale): array
    {
        return match ($locale) {
            Locale::English => ['.', ','],
            Locale::German  => [',', '.'],
            Locale::Russian => [',', "\u{00A0}"],
            Locale::Chinese => ['.', ','],
            // Arabic-Indic digits are the CLDR default for `ar`, but the
            // console shows model/token counts that operators compare against
            // provider dashboards, which use Latin digits. Latin digits with
            // Arabic separators is the standard "ar" latin-numbering variant.
            Locale::Arabic  => ['.', ','],
        };
    }

    private static function datePattern(Locale $locale): string
    {
        return match ($locale) {
            Locale::English => 'MMM d, y, h:mm a',
            Locale::German  => 'dd.MM.y, HH:mm',
            Locale::Russian => 'd MMM y г., HH:mm',
            Locale::Chinese => 'y年M月d日 HH:mm',
            Locale::Arabic  => 'd MMM y، h:mm a',
        };
    }

    private static function dateOnlyPattern(Locale $locale): string
    {
        return match ($locale) {
            Locale::English => 'MMM d, y',
            Locale::German  => 'dd.MM.y',
            Locale::Russian => 'd MMM y г.',
            Locale::Chinese => 'y年M月d日',
            Locale::Arabic  => 'd MMM y',
        };
    }

    /**
     * `date()` format used when ext-intl is absent.
     *
     * ext-intl is optional — shared hosts routinely lack it, and RevoAp does
     * not require it — so this fallback is what a large fraction of installs
     * actually render. It must therefore be locale-shaped, not merely
     * unambiguous: `Y-m-d` is correct ISO-8601 and wrong Chinese. The CJK and
     * Arabic patterns below match what intl produces for those locales, so
     * installing the extension does not visibly change the console.
     */
    private static function fallbackDateFormat(Locale $locale): string
    {
        return match ($locale) {
            Locale::German  => 'd.m.Y',
            Locale::Russian => 'd.m.Y',
            Locale::Chinese => 'Y\年n\月j\日',
            // Arabic uses the Gregorian calendar with day/month order and an
            // Arabic comma. Digits stay Latin — see separators() for why.
            Locale::Arabic  => 'j/n/Y',
            default         => 'M j, Y',
        };
    }

    private static function fallbackDateTimeFormat(Locale $locale): string
    {
        return self::fallbackDateFormat($locale) . ' H:i';
    }

    private static function bcp47(Locale $locale): string
    {
        return match ($locale) {
            Locale::English => 'en_US',
            Locale::German  => 'de_DE',
            Locale::Russian => 'ru_RU',
            Locale::Chinese => 'zh_CN',
            Locale::Arabic  => 'ar_EG',
        };
    }
}
