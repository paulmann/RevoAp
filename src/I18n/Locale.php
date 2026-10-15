<?php
/**
 * Locale: the closed set of UI languages RevoAp ships.
 *
 * A backed enum rather than a string constant bag because the locale has to
 * travel through four different representations and every one of them is
 * derived here instead of being re-spelled at each call site:
 *
 *   - `value`        the canonical BCP-47 tag written into `<html lang>`,
 *                    the `revoap_locale` cookie and the `?lang=` query;
 *   - `dir()`        text direction, `rtl` for Arabic — the shell flips its
 *                    whole layout from this one value, so it can never be
 *                    forgotten in a new template;
 *   - `catalogFile()` the JSON file name on disk;
 *   - `nativeName()`  the label the language picker shows, in the target
 *                    language ("Deutsch", not "German") because a user who
 *                    cannot read the current UI must still be able to find
 *                    their own language;
 *   - `fallback()`    the chain consulted when a key is missing. Every
 *                    locale falls back to English, never to a sibling —
 *                    a half-translated German string is worse than an
 *                    honest English one.
 *
 * Adding a language is one new case + one new `i18n/<code>.json`. The
 * `LocaleCatalogTest` asserts the file exists and covers every key of
 * `en.json`, so an incomplete translation fails CI instead of shipping.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\I18n;

enum Locale: string
{
    case English = 'en';
    case German  = 'de';
    case Russian = 'ru';
    case Chinese = 'zh';
    case Arabic  = 'ar';

    /** Text direction for `<html dir>`. Arabic is the only RTL locale shipped. */
    public function dir(): string
    {
        return $this === self::Arabic ? 'rtl' : 'ltr';
    }

    /** True when the whole shell must be mirrored. */
    public function isRtl(): bool
    {
        return $this === self::Arabic;
    }

    /**
     * Language picker label, written in the language itself.
     *
     * The English gloss is returned separately by {@see englishName()} for
     * the operator-facing documentation and the `Accept-Language` debug
     * output, never for the picker.
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::German  => 'Deutsch',
            self::Russian => 'Русский',
            self::Chinese => '简体中文',
            self::Arabic  => 'العربية',
        };
    }

    public function englishName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::German  => 'German',
            self::Russian => 'Russian',
            self::Chinese => 'Chinese (Simplified)',
            self::Arabic  => 'Arabic',
        };
    }

    /** CLDR language subtag used for `IntlDateFormatter`-style hints. */
    public function catalogFile(): string
    {
        return $this->value . '.json';
    }

    /**
     * Fallback chain, most specific first, English always last.
     *
     * `en` appears exactly once even when it is the active locale, so the
     * catalog loader can iterate the list unconditionally.
     *
     * @return array<int,self>
     */
    public function fallbackChain(): array
    {
        return $this === self::English
            ? [self::English]
            : [$this, self::English];
    }

    /**
     * Resolve an arbitrary BCP-47-ish tag to a shipped locale.
     *
     * Accepts `de`, `de-DE`, `de_DE.UTF-8`, `zh-Hans-CN`, `zh-TW` and even
     * `ar-EG`, matching case-insensitively and progressively dropping
     * subtags. Traditional Chinese (`zh-TW` / `zh-Hant`) is deliberately
     * mapped to the shipped Simplified catalog rather than to English —
     * a Simplified rendering of a Traditional reader's UI is still Chinese,
     * whereas an English one is a different language entirely. The mapping
     * is documented in docs/I18N.md.
     *
     * Returns null when nothing matches, so the caller can apply the
     * configured default instead of silently guessing.
     */
    public static function fromTag(?string $tag): ?self
    {
        if ($tag === null) {
            return null;
        }

        // Control characters are checked on the RAW value, before any trimming.
        // PHP's trim() strips NUL (it is in the default charlist), so trimming
        // first would silently launder `"en\0"` into `"en"` — and a NUL inside
        // an identifier is the classic truncation primitive. Ordinary
        // surrounding whitespace is fine and is removed after this check.
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $tag) === 1) {
            return null;
        }
        $normalized = strtolower(trim($tag, " \t\n\r"));
        if ($normalized === '' || strlen($normalized) > 64) {
            return null;
        }

        // Strip a POSIX encoding or modifier suffix — `de_DE.UTF-8`,
        // `ru_RU.utf8`, `ar_EG@euro`.
        //
        // The two suffix kinds are matched SEPARATELY, and the encoding list is
        // closed. A single `[.@][a-z]{1,8}$` branch would look simpler and is
        // wrong: it accepts `.json`, `.php`, `.sql` and `.shtml`, so a filename
        // in a URL segment would resolve to a locale. `@` is unambiguously a
        // POSIX modifier (`.` never introduces one) and `.` is only stripped
        // when what follows is an encoding POSIX actually names.
        $normalized = (string)(preg_replace(
            '/\.(?:utf-?8|iso-?8859-\d{1,2}|cp\d{3,4}|koi8-?[ru]|armscii-8|big5|euc(?:jp|kr|tw)|gb(?:2312|18030)|shift[-_]?jis|tis-620|ascii|ansi_x3\.4-1968)$/',
            '',
            $normalized
        ) ?? $normalized);
        $normalized = (string)(preg_replace('/@[a-z][a-z0-9._-]{0,15}$/', '', $normalized) ?? $normalized);
        $normalized = str_replace('_', '-', $normalized);

        // Exact match first (handles `en`, `de`, `ru`, `zh`, `ar`).
        $exact = self::tryFrom($normalized);
        if ($exact !== null) {
            return $exact;
        }

        // A well-formed tag is 1-8 alphanumeric primary subtags separated by
        // hyphens. Anything else — a path, a filename, a URL — is rejected
        // rather than having its first segment guessed at.
        if (preg_match('/^[a-z0-9]{1,8}(?:-[a-z0-9]{1,8})*$/', $normalized) !== 1) {
            return null;
        }

        // Script-only tags such as `zh-hans` / `zh-hant` both -> zh.
        $parts = explode('-', $normalized);
        $primary = $parts[0];
        if ($primary === 'zh') {
            return self::Chinese;
        }
        $byPrimary = self::tryFrom($primary);
        if ($byPrimary !== null) {
            return $byPrimary;
        }

        // Two-letter primary that we do not ship: no useful guess exists.
        return null;
    }

    /**
     * All shipped locales in picker order.
     *
     * English first (it is the fallback and the source of truth), then
     * alphabetical by English name so the list stays stable when locales
     * are added.
     *
     * @return array<int,self>
     */
    public static function all(): array
    {
        return [
            self::English,
            self::Arabic,
            self::Chinese,
            self::German,
            self::Russian,
        ];
    }

    /**
     * Machine-readable description for `/api/i18n/locales`.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        return [
            'code'         => $this->value,
            'native_name'  => $this->nativeName(),
            'english_name' => $this->englishName(),
            'dir'          => $this->dir(),
            'rtl'          => $this->isRtl(),
            // CLDR plural categories the language actually uses. The
            // front-end `t()` picks the right `_one/_few/_many/_other`
            // suffix from this without re-implementing CLDR in JS.
            'plurals'      => PluralRules::categoriesFor($this),
            'fallback'     => array_map(static fn(self $l): string => $l->value, $this->fallbackChain()),
        ];
    }
}
