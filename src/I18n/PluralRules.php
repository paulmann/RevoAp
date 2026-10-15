<?php
/**
 * PluralRules: CLDR cardinal plural selection for the shipped locales.
 *
 * The five languages RevoAp ships need four genuinely different plural
 * systems, and getting them wrong is the most visible i18n bug there is —
 * "1 модели" / "2 моделей" is instantly wrong to a Russian reader, and
 * Arabic additionally distinguishes zero, one, two, few (3-10), many
 * (11-99) and other.
 *
 * Rules are the CLDR cardinal rules verbatim (unicode.org/cldr, v45),
 * reduced to what each language needs. Only integer counts are handled —
 * a decimal count ("2.5 seconds") falls back to `other` in every shipped
 * language, which is also what CLDR prescribes for `zh` and what the UI
 * wants for the rare fractional case.
 *
 * The same table is exported to the browser through
 * `Locale::describe()['plurals']` + `/api/i18n/{locale}`, so the JS
 * `t()` implementation and PHP can never disagree about which suffix to
 * pick. Tests/I18nPluralTest.php asserts the two against CLDR fixtures.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\I18n;

final class PluralRules
{
    /**
     * CLDR plural categories a locale can produce, in canonical order.
     *
     * The order matters: a catalog that defines only some of the forms
     * falls back down this list, and `other` is the guaranteed last entry
     * for every language.
     *
     * @return array<int,string>
     */
    public static function categoriesFor(Locale $locale): array
    {
        return match ($locale) {
            Locale::English, Locale::German => ['one', 'other'],
            Locale::Russian                 => ['one', 'few', 'many', 'other'],
            Locale::Chinese                 => ['other'],
            Locale::Arabic                  => ['zero', 'one', 'two', 'few', 'many', 'other'],
        };
    }

    /**
     * Select the CLDR category for a count in the given locale.
     *
     * @param int|float $n The count. Negatives are treated as their absolute
     *                     value, which is what CLDR prescribes for cardinal
     *                     numbers and what "−3 attempts" reads as anyway.
     */
    public static function category(Locale $locale, int|float $n): string
    {
        $n = abs($n);
        $i = (int)floor($n);

        // Fractions only matter for the languages whose rules inspect `v`
        // (visible fraction digits). None of the shipped ones do for
        // cardinal numbers, so a non-integral value goes to `other`.
        if (!is_int($n) && abs($n - (float)$i) > 1e-9) {
            return match ($locale) {
                Locale::Russian => self::russianCardinal($i),
                Locale::Arabic  => 'other',
                Locale::Chinese => 'other',
                default         => 'other',
            };
        }

        return match ($locale) {
            Locale::English, Locale::German => $i === 1 ? 'one' : 'other',
            Locale::Russian                 => self::russianCardinal($i),
            Locale::Chinese                 => 'other',
            Locale::Arabic                  => self::arabicCardinal($i),
        };
    }

    /**
     * Russian / Ukrainian-style three-way rule.
     *
     *   one  : v = 0 and i % 10 = 1 and i % 100 != 11      → 1, 21, 101
     *   few  : v = 0 and i % 10 = 2..4 and i % 100 != 12..14 → 2, 3, 4, 22
     *   many : v = 0 and i % 10 = 0, or v = 0 and i % 10 = 5..9,
     *          or v = 0 and i % 100 = 11..14                → 0, 5, 11, 14
     *   other: everything else (fractions)
     */
    private static function russianCardinal(int $i): string
    {
        $mod10  = $i % 10;
        $mod100 = $i % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'one';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'few';
        }
        if ($mod10 === 0 || ($mod10 >= 5 && $mod10 <= 9) || ($mod100 >= 11 && $mod100 <= 14)) {
            return 'many';
        }
        return 'other';
    }

    /**
     * Arabic six-way rule (CLDR).
     *
     *   zero : n = 0
     *   one  : n = 1
     *   two  : n = 2
     *   few  : n % 100 = 3..10
     *   many : n % 100 = 11..99
     *   other: everything else (100, 101, 102, …)
     */
    private static function arabicCardinal(int $i): string
    {
        if ($i === 0) { return 'zero'; }
        if ($i === 1) { return 'one'; }
        if ($i === 2) { return 'two'; }
        $mod100 = $i % 100;
        if ($mod100 >= 3 && $mod100 <= 10)  { return 'few'; }
        if ($mod100 >= 11 && $mod100 <= 99) { return 'many'; }
        return 'other';
    }

    /**
     * Build every message key a catalog needs for a pluralised string.
     *
     * `key('models.selected', 3, 'ru')` → `models.selected.few`. Used by the
     * validator so a catalog cannot define `_one` and forget `_many`.
     */
    public static function key(string $base, Locale $locale, int|float $n): string
    {
        return $base . '.' . self::category($locale, $n);
    }

    /**
     * All suffixed keys a plural base must define, in canonical order.
     *
     * @return array<int,string>
     */
    public static function allKeys(string $base, Locale $locale): array
    {
        return array_map(
            static fn(string $c): string => $base . '.' . $c,
            self::categoriesFor($locale)
        );
    }
}
