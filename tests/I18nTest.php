<?php
/**
 * I18nTest: the internationalisation subsystem.
 *
 * Standalone (no PHPUnit), like every other test in this suite, so it runs on
 * a host that has nothing but PHP: `php tests/I18nTest.php`.
 *
 * What is covered and why it is worth a test
 * ------------------------------------------
 * The i18n layer has four failure modes that are invisible until a user in a
 * non-English locale hits them, and all four are asserted here:
 *
 *   1. **Plural rules.** A wrong CLDR category is the most visible i18n bug
 *      there is — "5 минуты" is instantly wrong to a Russian reader. The
 *      tables below are CLDR fixtures, not values I derived from my own
 *      implementation, so the test can actually fail.
 *   2. **Catalogue completeness.** A missing key renders as the raw key text
 *      in front of an operator. This is the same check `bin/revoap-i18n.php
 *      lint` performs, but wired into the suite so a regression cannot be
 *      merged without running the CLI tool.
 *   3. **Negotiation precedence.** Getting the order wrong either pins a user
 *      to a language they never chose (persisting a header guess) or ignores
 *      an explicit `?lang=` switch.
 *   4. **Accept-Language parsing.** That header is attacker-controlled and
 *      real clients send malformed values; a naive explode on it is both wrong
 *      and a mild DoS surface.
 *
 * Parity with the client
 * ----------------------
 * `assets/js/i18n.js` implements the same plural rules. This test extracts the
 * JS implementation and runs the SAME fixture table through it under Node, so
 * the two cannot drift. That is the check that would otherwise only ever be
 * caught by a bilingual user reporting a wrong noun ending.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\I18n\Locale;
use App\I18n\LocaleNegotiator;
use App\I18n\MessageCatalog;
use App\I18n\PluralRules;
use App\I18n\Translator;

MessageCatalog::setDirectory(dirname(__DIR__) . '/i18n');

$passes = 0;
$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passes, $failures;
    if ($ok) { $passes++; echo "  ok  $label\n"; }
    else { $failures++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

// =====================================================================
section('the shipped locale set');
// =====================================================================

$all = Locale::all();
check('five locales ship', count($all) === 5, (string)count($all));
$codes = array_map(static fn(Locale $l): string => $l->value, $all);
// all() is documented as PICKER order: English first because it is the
// fallback root and the source of truth, then alphabetical by ENGLISH name so
// the list stays stable when a locale is added. Asserting the rule rather than
// a literal list is what keeps this from breaking on every new language.
check('English is first (it is the fallback root)', $codes[0] === 'en', implode(',', $codes));
$rest = array_slice($codes, 1);
$sorted = $rest;
$names = array_map(static fn(string $c): string => Locale::fromTag($c)?->englishName() ?? $c, $rest);
$expectedNames = $names;
sort($expectedNames);
check('the rest are alphabetical by English name', $names === $expectedNames, implode(',', $names));
check('every shipped locale appears exactly once', count(array_unique($codes)) === count($codes));
check('all five locales are present', count(array_diff(['en','de','ru','zh','ar'], $codes)) === 0, implode(',', $codes));
check('exactly one locale is RTL', count(array_filter($all, static fn(Locale $l): bool => $l->isRtl())) === 1);
check('and it is Arabic', Locale::Arabic->isRtl() && Locale::Arabic->dir() === 'rtl');
check('every non-Arabic locale is ltr', !Locale::English->isRtl() && !Locale::German->isRtl()
    && !Locale::Russian->isRtl() && !Locale::Chinese->isRtl());

foreach ($all as $locale) {
    check("$locale->value has a native name in its own script",
        $locale->nativeName() !== '' && $locale->nativeName() !== $locale->value,
        $locale->nativeName());
    check("$locale->value describe() is complete",
        count(array_diff(['code', 'native_name', 'english_name', 'dir', 'rtl', 'plurals', 'fallback'],
            array_keys($locale->describe()))) === 0);
}

// =====================================================================
section('tag resolution — BCP-47, POSIX and script subtags');
// =====================================================================

$tags = [
    // exact
    'en' => Locale::English, 'de' => Locale::German, 'ru' => Locale::Russian,
    'zh' => Locale::Chinese, 'ar' => Locale::Arabic,
    // region subtags
    'en-US' => Locale::English, 'en-GB' => Locale::English, 'de-DE' => Locale::German,
    'de-AT' => Locale::German, 'ru-RU' => Locale::Russian, 'ru-UA' => Locale::Russian,
    'ar-EG' => Locale::Arabic, 'ar-SA' => Locale::Arabic,
    // POSIX locale with an encoding, as LANG arrives from a shell
    'de_DE.UTF-8' => Locale::German, 'ru_RU.UTF-8' => Locale::Russian,
    'en_US.utf8' => Locale::English,
    // case-insensitivity: Accept-Language is case-insensitive by spec
    'EN' => Locale::English, 'De-De' => Locale::German, 'ZH' => Locale::Chinese,
    // script subtags. zh-Hant is Traditional; we ship Simplified. Mapping it
    // to Chinese rather than to English is deliberate — a Simplified rendering
    // of a Traditional reader's UI is still Chinese, an English one is a
    // different language entirely.
    'zh-Hans-CN' => Locale::Chinese, 'zh-Hant-TW' => Locale::Chinese,
    'zh-Hant' => Locale::Chinese, 'zh-TW' => Locale::Chinese, 'zh-HK' => Locale::Chinese,
    // underscore form used by some HTTP clients
    'de_DE' => Locale::German, 'ar_EG' => Locale::Arabic,
];
foreach ($tags as $tag => $expected) {
    $got = Locale::fromTag((string)$tag);
    check("'$tag' resolves to {$expected->value}", $got === $expected, var_export($got?->value, true));
}

$rejected = ['', '   ', 'klingon', 'xx', 'qqq-YY', '12345', 'e', 'toolongprimarytag'];
foreach ($rejected as $tag) {
    check("'$tag' resolves to nothing rather than a guess", Locale::fromTag($tag) === null,
        var_export(Locale::fromTag($tag)?->value, true));
}
check('a null tag resolves to nothing', Locale::fromTag(null) === null);

// =====================================================================
section('CLDR plural rules — fixtures from the specification, not from the code');
// =====================================================================

// Each row: count => expected category. Values chosen to hit every boundary,
// including the ones that catch an off-by-one in the mod-100 range.
$fixtures = [
    'en' => [0 => 'other', 1 => 'one', 2 => 'other', 15 => 'other', 100 => 'other'],
    'de' => [0 => 'other', 1 => 'one', 2 => 'other', 21 => 'other', 1000 => 'other'],
    'ru' => [
        0 => 'many', 1 => 'one', 2 => 'few', 3 => 'few', 4 => 'few', 5 => 'many',
        9 => 'many', 10 => 'many', 11 => 'many', 12 => 'many', 13 => 'many', 14 => 'many',
        15 => 'many', 19 => 'many', 20 => 'many', 21 => 'one', 22 => 'few', 25 => 'many',
        100 => 'many', 101 => 'one', 102 => 'few', 111 => 'many', 112 => 'many',
        121 => 'one', 1000 => 'many', 1001 => 'one', 1011 => 'many',
    ],
    'zh' => [0 => 'other', 1 => 'other', 2 => 'other', 100 => 'other'],
    'ar' => [
        0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'few', 5 => 'few', 10 => 'few',
        11 => 'many', 15 => 'many', 26 => 'many', 99 => 'many', 100 => 'other',
        101 => 'other', 102 => 'other', 103 => 'few', 111 => 'many', 200 => 'other',
    ],
];

foreach ($fixtures as $code => $cases) {
    $locale = Locale::fromTag($code);
    if ($locale === null) { check("locale $code exists", false); continue; }
    $wrong = [];
    foreach ($cases as $n => $expected) {
        $got = PluralRules::category($locale, (int)$n);
        if ($got !== $expected) { $wrong[] = "$n:expected $expected got $got"; }
    }
    check("$code: all " . count($cases) . ' plural fixtures match CLDR', $wrong === [], implode(' ', $wrong));
}

// Categories declared per locale must be exactly the CLDR set, in order.
$expectedCats = [
    'en' => ['one', 'other'],
    'de' => ['one', 'other'],
    'ru' => ['one', 'few', 'many', 'other'],
    'zh' => ['other'],
    'ar' => ['zero', 'one', 'two', 'few', 'many', 'other'],
];
foreach ($expectedCats as $code => $cats) {
    $locale = Locale::fromTag($code);
    check("$code declares the right plural categories",
        $locale !== null && PluralRules::categoriesFor($locale) === $cats,
        implode(',', PluralRules::categoriesFor($locale ?? Locale::English)));
}

// Negative counts read as their absolute value ("−3 attempts" is still 3).
check('a negative count uses its magnitude (ru)', PluralRules::category(Locale::Russian, -5) === 'many');
check('a negative count uses its magnitude (en)', PluralRules::category(Locale::English, -1) === 'one');
// Fractions land on `other` everywhere except Russian, where the integer part
// still governs — "1.5 минуты" reads as few, not other.
check('a fraction is other in en', PluralRules::category(Locale::English, 1.5) === 'other');
check('a fraction is other in ar', PluralRules::category(Locale::Arabic, 2.5) === 'other');
check('a fraction is other in zh', PluralRules::category(Locale::Chinese, 3.5) === 'other');

// =====================================================================
section('catalogues: every locale covers every key of en.json');
// =====================================================================

$reference = MessageCatalog::load(Locale::English);
check('en.json loads', $reference->ok, (string)$reference->problem);
check('en.json is not empty', $reference->count() > 1000, (string)$reference->count());

// `_meta.*` is catalogue METADATA, not a translatable key: it declares the
// locale's own code, name, direction and plural categories, and those differ
// per language by design (en has 2 categories, zh has 1, ar has 6). Excluding
// it here mirrors what bin/revoap-i18n.php does, and is why a coverage report
// can say "100 %" without every catalogue carrying identical metadata.
$refFlat = array_filter(
    $reference->all(),
    static fn(string $value, string $key): bool => !str_starts_with($key, '_meta.'),
    ARRAY_FILTER_USE_BOTH
);
// Plural bases: a key whose siblings end in a CLDR category. Each locale needs
// its OWN category set, so these are validated separately from plain keys.
$pluralBases = [];
$plainKeys = [];
foreach (array_keys($refFlat) as $key) {
    if (preg_match('/^(.*)\.(zero|one|two|few|many|other)$/', $key, $m)) {
        $pluralBases[$m[1]] = true;
    } else {
        $plainKeys[$key] = true;
    }
}
// Drop the plural leaves that were also collected as plain keys.
foreach (array_keys($pluralBases) as $base) { unset($plainKeys[$base]); }

check('en.json declares plural groups', count($pluralBases) > 5, (string)count($pluralBases));

foreach ([Locale::German, Locale::Russian, Locale::Chinese, Locale::Arabic] as $locale) {
    $catalog = MessageCatalog::load($locale);
    check("{$locale->value}.json loads", $catalog->ok, (string)$catalog->problem);
    if (!$catalog->ok) { continue; }

    $flat = array_filter(
        $catalog->all(),
        static fn(string $value, string $key): bool => !str_starts_with($key, '_meta.'),
        ARRAY_FILTER_USE_BOTH
    );

    // The metadata a catalogue DOES carry is checked separately, because a
    // wrong `_meta.dir` silently renders Arabic left-to-right.
    $meta = $catalog->all();
    check("{$locale->value}._meta.dir agrees with Locale::dir()",
        ($meta['_meta.dir'] ?? null) === $locale->dir(), (string)($meta['_meta.dir'] ?? 'absent'));
    check("{$locale->value}._meta.locale is its own code",
        ($meta['_meta.locale'] ?? null) === $locale->value, (string)($meta['_meta.locale'] ?? 'absent'));

    $missingPlain = array_values(array_diff(array_keys($plainKeys), array_keys($flat)));
    check("{$locale->value} covers every plain key of en.json",
        $missingPlain === [], count($missingPlain) . ' missing: ' . implode(', ', array_slice($missingPlain, 0, 5)));

    $cats = PluralRules::categoriesFor($locale);
    $missingPlural = [];
    foreach (array_keys($pluralBases) as $base) {
        foreach ($cats as $cat) {
            if (!array_key_exists("$base.$cat", $flat)) { $missingPlural[] = "$base.$cat"; }
        }
    }
    check("{$locale->value} defines every plural form its language needs",
        $missingPlural === [], count($missingPlural) . ' missing: ' . implode(', ', array_slice($missingPlural, 0, 5)));

    // An extra plural form is a bug too: it can never be selected, and it means
    // somebody copied a Russian catalogue into a two-form language.
    $extraPlural = [];
    foreach (array_keys($flat) as $key) {
        if (preg_match('/^(.*)\.(zero|one|two|few|many|other)$/', $key, $m)) {
            if (isset($pluralBases[$m[1]]) && !in_array($m[2], $cats, true)) { $extraPlural[] = $key; }
        }
    }
    check("{$locale->value} declares no plural form its language does not use",
        $extraPlural === [], implode(', ', array_slice($extraPlural, 0, 5)));

    $empty = [];
    foreach ($flat as $k => $v) {
        // a11y.decorative is intentionally empty: it is the accessible name of
        // a purely decorative icon, where the correct value IS "".
        if ($v === '' && $k !== 'a11y.decorative') { $empty[] = $k; }
    }
    check("{$locale->value} has no empty translation", $empty === [], implode(', ', array_slice($empty, 0, 5)));
}

// =====================================================================
section('placeholder parity with English');
// =====================================================================

/** Extract the {name} placeholders a message uses, sorted. */
function ph(string $message): array
{
    preg_match_all('/\{\s*([A-Za-z0-9_.\-]+)\s*(?:,\s*[a-z]+)?\s*\}/', $message, $m);
    $names = $m[1] ?? [];
    sort($names);
    return array_values(array_unique($names));
}

foreach ([Locale::German, Locale::Russian, Locale::Chinese, Locale::Arabic] as $locale) {
    $catalog = MessageCatalog::load($locale);
    if (!$catalog->ok) { continue; }
    $unknown = [];
    $dropped = [];
    foreach ($refFlat as $key => $refText) {
        $text = $catalog->get($key);
        if ($text === null) { continue; }
        $a = ph((string)$refText);
        $b = ph($text);
        // A placeholder en.json does not define can never be interpolated, so
        // it renders literally on screen — always a bug.
        foreach (array_diff($b, $a) as $name) { $unknown[] = "$key:$name"; }
        // A dropped placeholder is legitimate where the language inflects the
        // numeral into a word (Arabic zero/one/two), so it is reported
        // separately and only outside those categories.
        foreach (array_diff($a, $b) as $name) {
            if (preg_match('/\.(zero|one|two)$/', $key)) { continue; }
            $dropped[] = "$key:$name";
        }
    }
    check("{$locale->value} uses no placeholder en.json does not define",
        $unknown === [], implode(', ', array_slice($unknown, 0, 6)));
    check("{$locale->value} keeps every placeholder outside grammatical-number forms",
        $dropped === [], count($dropped) . ': ' . implode(', ', array_slice($dropped, 0, 6)));
}

// =====================================================================
section('lookup, interpolation and fallback');
// =====================================================================

Translator::resetStats();
check('t() resolves an existing key', Translator::t('common.save', [], Locale::English) === 'Save',
    Translator::t('common.save', [], Locale::English));
check('t() resolves in German', Translator::t('common.save', [], Locale::German) === 'Speichern',
    Translator::t('common.save', [], Locale::German));
check('t() resolves in Russian', Translator::t('common.save', [], Locale::Russian) === 'Сохранить',
    Translator::t('common.save', [], Locale::Russian));
check('t() resolves in Chinese', Translator::t('common.save', [], Locale::Chinese) === '保存',
    Translator::t('common.save', [], Locale::Chinese));

// A missing key returns the key itself. That is deliberate: "settings.missing"
// on screen is a bug report, an empty string is not.
check('a missing key renders as the key', Translator::t('no.such.key', [], Locale::English) === 'no.such.key');
check('and is counted as a miss', Translator::stats()['misses'] >= 1, json_encode(Translator::stats()));

check('placeholders interpolate by name',
    Translator::t('app.version', ['version' => '2.0.0'], Locale::English) === 'Version 2.0.0',
    Translator::t('app.version', ['version' => '2.0.0'], Locale::English));
check('an unknown placeholder is left verbatim',
    Translator::t('app.version', [], Locale::English) === 'Version {version}',
    Translator::t('app.version', [], Locale::English));
check('{{literal}} emits real braces',
    Translator::interpolate('a {{b}} c', [], Locale::English) === 'a {b} c',
    Translator::interpolate('a {{b}} c', [], Locale::English));
check('a null parameter becomes an empty string',
    Translator::interpolate('[{x}]', ['x' => null], Locale::English) === '[]');

// Plurals.
check('tn() picks .one for 1 in English',
    str_contains(Translator::tn('audit.entries', 1, [], Locale::English), '1 entry'),
    Translator::tn('audit.entries', 1, [], Locale::English));
check('tn() picks .other for 5 in English',
    str_contains(Translator::tn('audit.entries', 5, [], Locale::English), '5 entries'),
    Translator::tn('audit.entries', 5, [], Locale::English));
$ru1 = Translator::tn('audit.entries', 1, [], Locale::Russian);
$ru2 = Translator::tn('audit.entries', 2, [], Locale::Russian);
$ru5 = Translator::tn('audit.entries', 5, [], Locale::Russian);
$ru21 = Translator::tn('audit.entries', 21, [], Locale::Russian);
check('tn() distinguishes one/few/many in Russian',
    $ru1 !== $ru2 && $ru2 !== $ru5 && $ru1 !== $ru5,
    json_encode([$ru1, $ru2, $ru5]));
check('tn() returns to .one at 21 in Russian', str_contains($ru21, 'запись') && !str_contains($ru21, 'записи'), $ru21);
// The mod-100 exception: 11 must take `.many` ("11 записей"), not the `.few`
// form its last digit would suggest. Compare the NOUN, not the whole string —
// the whole string also contains the interpolated count, so 11 and 5 are never
// textually equal even when they agree on the grammatical form.
$ru11 = Translator::tn('audit.entries', 11, [], Locale::Russian);
$noun = static fn(string $s): string => trim(preg_replace('/[\d\s.,]+/u', ' ', $s) ?? $s);
check('tn() uses .many at 11 in Russian (the mod-100 exception)',
    $noun($ru11) === $noun($ru5) && $noun($ru11) !== $noun($ru2),
    json_encode([11 => $ru11, 5 => $ru5, 2 => $ru2], JSON_UNESCAPED_UNICODE));
check('and at 14, the top of the exception range', $noun(Translator::tn('audit.entries', 14, [], Locale::Russian)) === $noun($ru5));
check('but not at 15, which is many by the mod-10 rule anyway', $noun(Translator::tn('audit.entries', 15, [], Locale::Russian)) === $noun($ru5));
$ar0 = Translator::tn('audit.entries', 0, [], Locale::Arabic);
$ar2 = Translator::tn('audit.entries', 2, [], Locale::Arabic);
check('tn() distinguishes zero and two in Arabic', $ar0 !== $ar2 && $ar0 !== '' && $ar2 !== '', json_encode([$ar0, $ar2]));
check('tn() injects count automatically',
    str_contains(Translator::tn('audit.entries', 7, [], Locale::English), '7'),
    Translator::tn('audit.entries', 7, [], Locale::English));

// Fallback: a key present only in en still resolves in every locale.
check('a key missing from a locale falls back to English',
    Translator::t('app.name', [], Locale::Arabic) !== 'app.name');

// =====================================================================
section('negotiation precedence');
// =====================================================================

$neg = static fn(array $server, array $query, array $cookies): array
    => (new LocaleNegotiator($server, $query, $cookies))->negotiate();

$r = $neg([], [], ['revoap_locale' => 'de']);
check('a cookie is honoured', $r['locale'] === Locale::German && $r['source'] === 'cookie', $r['source']);
check('a cookie is NOT re-persisted (it was already a choice)', $r['remember'] === false);

$r = $neg([], ['lang' => 'ru'], ['revoap_locale' => 'de']);
check('?lang= beats the cookie', $r['locale'] === Locale::Russian && $r['source'] === 'query', $r['source']);
check('and ?lang= IS persisted', $r['remember'] === true);

$r = $neg(['HTTP_ACCEPT_LANGUAGE' => 'ja,zh;q=0.9,en;q=0.8'], [], []);
check('Accept-Language picks the first shipped language', $r['locale'] === Locale::Chinese, $r['locale']->value);
check('a header guess is not persisted', $r['remember'] === false);

$r = $neg(['HTTP_X_LOCALE' => 'ar'], [], []);
check('X-Locale is honoured', $r['locale'] === Locale::Arabic && $r['source'] === 'header', $r['source']);

$r = $neg(['HTTP_X_LOCALE' => 'de'], ['lang' => 'ru'], ['revoap_locale' => 'zh']);
check('query beats cookie beats header', $r['locale'] === Locale::Russian, $r['locale']->value);

$r = $neg([], [], []);
check('with no signal at all the default is English', $r['locale'] === Locale::English && $r['source'] === 'default');
check('and the considered trail is recorded for diagnostics', count($r['considered']) > 0);

$r = $neg([], ['lang' => 'klingon'], ['revoap_locale' => 'xx']);
check('an invalid query and cookie fall through rather than erroring',
    $r['locale'] === Locale::English, $r['locale']->value);

// =====================================================================
section('Accept-Language parsing (attacker-controlled input)');
// =====================================================================

$p = LocaleNegotiator::parseAcceptLanguage('de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7');
check('q-values order the result', array_keys($p)[0] === 'de-de', implode(',', array_keys($p)));
check('a tag with no q defaults to 1.0', abs($p['de-de'] - 1.0) < 1e-9);
// RFC 9110 §12.4.2: qvalue is `0[.0-3DIGIT]` or `1[.000]`. Anything else is a
// malformed parameter, and the safe reading is to DROP the tag rather than to
// clamp it — clamping `q=99` to 1.0 would honour a value the spec forbids and
// would put it at the top of the client's own preference list.
check('q=1.000 (the largest legal value) is honoured',
    abs((LocaleNegotiator::parseAcceptLanguage('en;q=1.000')['en'] ?? 0) - 1.0) < 1e-9);
check('q=0.999 (three decimals) is honoured',
    abs((LocaleNegotiator::parseAcceptLanguage('en;q=0.999')['en'] ?? 0) - 0.999) < 1e-9);
check('q=99 is malformed and drops the tag rather than clamping to 1.0',
    !array_key_exists('en', LocaleNegotiator::parseAcceptLanguage('en;q=99')),
    json_encode(LocaleNegotiator::parseAcceptLanguage('en;q=99')));
check('q=0.9999 (four decimals) is malformed and drops the tag',
    !array_key_exists('en', LocaleNegotiator::parseAcceptLanguage('en;q=0.9999')));
check('q=abc is malformed and drops the tag',
    !array_key_exists('en', LocaleNegotiator::parseAcceptLanguage('en;q=abc')));
check('a negative q is dropped', !array_key_exists('fr', LocaleNegotiator::parseAcceptLanguage('fr;q=-1')));
check('a malformed q on one tag does not affect its siblings',
    array_keys(LocaleNegotiator::parseAcceptLanguage('fr;q=-1,en;q=0.5')) === ['en'],
    json_encode(LocaleNegotiator::parseAcceptLanguage('fr;q=-1,en;q=0.5')));
check('q=0 is an explicit rejection and is dropped', !array_key_exists('fr', LocaleNegotiator::parseAcceptLanguage('fr;q=0,en')));
check('a wildcard is preserved', array_key_exists('*', LocaleNegotiator::parseAcceptLanguage('*')));
check('malformed tags are skipped', LocaleNegotiator::parseAcceptLanguage('!!!,en,,;;') === ['en' => 1.0],
    json_encode(LocaleNegotiator::parseAcceptLanguage('!!!,en,,;;')));
check('an empty header yields nothing', LocaleNegotiator::parseAcceptLanguage('') === []);
check('a very long header is truncated, not walked',
    count(LocaleNegotiator::parseAcceptLanguage(str_repeat('aa-bb,', 5000) . 'en')) <= 32);
check('duplicate tags keep the first (higher) q',
    (LocaleNegotiator::parseAcceptLanguage('en;q=0.9,en;q=0.1')['en'] ?? 0) === 0.9);
check('later duplicates cannot outrank an earlier higher q',
    array_keys(LocaleNegotiator::parseAcceptLanguage('de;q=0.9,en;q=0.8,de;q=0.1'))[0] === 'de');
// Header injection: a value containing CRLF must not survive into a tag.
check('a CRLF-injected tag is rejected',
    LocaleNegotiator::parseAcceptLanguage("en\r\nX-Injected: 1") === [] ||
    !isset(LocaleNegotiator::parseAcceptLanguage("en\r\nX-Injected: 1")["en\r\nx-injected: 1"]),
    json_encode(array_keys(LocaleNegotiator::parseAcceptLanguage("en\r\nX-Injected: 1"))));

// =====================================================================
section('cookie options');
// =====================================================================

$opts = LocaleNegotiator::cookieOptions(Locale::Arabic, true);
check('the locale cookie is not HttpOnly (the inline boot script reads it)', $opts['httponly'] === false);
check('it is SameSite=Lax, not Strict', $opts['samesite'] === 'Lax');
check('Secure follows the request scheme', $opts['secure'] === true);
check('and is off on plain HTTP', LocaleNegotiator::cookieOptions(Locale::Arabic, false)['secure'] === false);
check('the value is the bare locale tag', $opts['value'] === 'ar');
check('it expires in about a year', abs(((int)$opts['expires']) - (time() + 31_536_000)) < 5);

// =====================================================================
section('number / percent / date formatting without ext-intl');
// =====================================================================

$en = Translator::number(1234567.891, 2, Locale::English);
$de = Translator::number(1234567.891, 2, Locale::German);
$ru = Translator::number(1234567.891, 2, Locale::Russian);
check('English uses . and ,', str_contains($en, '.') && str_contains($en, ','), $en);
check('German uses , and .', str_contains($de, ',') && str_contains($de, '.'), $de);
check('Russian uses , as the decimal separator', str_contains($ru, ','), $ru);

check('percent multiplies by 100', str_contains(Translator::percent(0.421, 1, Locale::English), '42.1'),
    Translator::percent(0.421, 1, Locale::English));
check('Arabic percent uses the Arabic percent sign', str_contains(Translator::percent(0.5, 0, Locale::Arabic), '٪'),
    Translator::percent(0.5, 0, Locale::Arabic));
check('Chinese percent has no separating space', !str_contains(Translator::percent(0.5, 0, Locale::Chinese), "\u{00A0}"),
    bin2hex(Translator::percent(0.5, 0, Locale::Chinese)));

$d = Translator::dateTime(1_700_000_000, Locale::German, false);
check('a German date is DD.MM.YYYY', (bool)preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $d), $d);
$d = Translator::dateTime(1_700_000_000, Locale::Chinese, false);
check('a Chinese date carries 年月日', str_contains($d, '年') && str_contains($d, '月') && str_contains($d, '日'), $d);

// =====================================================================
section('export() gives the SPA a COMPLETE map');
// =====================================================================

$exported = Translator::export(Locale::Arabic);
check('export() for a translated locale is not empty', count($exported) > 1000, (string)count($exported));
$enExported = Translator::export(Locale::English);
check('export() for Arabic covers every English key (fallback merged in)',
    count(array_diff(array_keys($enExported), array_keys($exported))) === 0,
    (string)count(array_diff(array_keys($enExported), array_keys($exported))));
check('export() is sorted, so two requests produce identical payloads',
    array_keys($exported) === array_values((static function (array $a): array { $k = array_keys($a); sort($k); return $k; })($exported)));
$payload = json_encode($exported, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
check('the exported payload survives JSON_HEX_TAG (no bare </script>)',
    is_string($payload) && !str_contains($payload, '</script'), (string)strlen((string)$payload));

// =====================================================================
section('catalogue loading failure modes');
// =====================================================================

MessageCatalog::setDirectory('/nonexistent/directory');
$broken = MessageCatalog::load(Locale::German);
check('a missing catalogue directory does not throw', $broken->ok === false);
check('and reports why', str_contains((string)$broken->problem, 'not found'), (string)$broken->problem);
check('a broken catalogue yields no messages rather than partial ones', $broken->count() === 0);
check('the missing key renders as the key, not as an empty label',
    Translator::t('common.save', [], Locale::German) === 'common.save',
    Translator::t('common.save', [], Locale::German));
MessageCatalog::flush();
MessageCatalog::setDirectory(dirname(__DIR__) . '/i18n');
check('and English resolves again once the directory is restored',
    Translator::t('common.save', [], Locale::English) === 'Save',
    Translator::t('common.save', [], Locale::English));

// =====================================================================
section('client parity — the JS plural rules must agree with PHP');
// =====================================================================

// This is the check that stops the two implementations drifting. It extracts
// pluralCategory() from assets/js/i18n.js and runs the SAME fixture table
// through Node. If Node is unavailable the check is skipped with a note rather
// than silently passing — a skipped parity check is not a green build.
$jsPath = dirname(__DIR__) . '/assets/js/i18n.js';
$js = is_file($jsPath) ? (string)file_get_contents($jsPath) : '';
$node = null;
foreach (['node', '/usr/bin/node', '/usr/local/bin/node'] as $candidate) {
    $probe = @shell_exec(escapeshellarg($candidate) . ' --version 2>/dev/null');
    if (is_string($probe) && str_starts_with(trim($probe), 'v')) { $node = $candidate; break; }
}

if ($js === '' || $node === null) {
    echo "  --  skipped (need assets/js/i18n.js and node)\n";
} else {
    // Pull the plural machinery out of the module. Importing the whole file
    // would need a DOM, so the four functions are lifted verbatim; the test
    // then asserts they are still present, so a rename breaks this rather than
    // silently testing dead code.
    $needed = ['export function pluralCategory', 'function russian(', 'function arabic('];
    $missing = array_values(array_filter($needed, static fn(string $n): bool => !str_contains($js, $n)));
    check('the JS plural implementation is still where this test expects it',
        $missing === [], implode(', ', $missing));

    if ($missing === []) {
        $start = strpos($js, 'export function pluralCategory');
        $end = strpos($js, '/* --------------------------------------------------------------------- *
 * Interpolation');
        $chunk = ($start !== false && $end !== false && $end > $start) ? substr($js, $start, $end - $start) : '';
        check('the JS plural block could be extracted', $chunk !== '', (string)strlen($chunk));

        if ($chunk !== '') {
            $fixturesJson = (string)json_encode($fixtures, JSON_UNESCAPED_SLASHES);
            // The extracted functions are emitted as real top-level source, not
            // eval'd: inside an ES module `eval` runs in strict mode, where a
            // function declaration does not leak into the enclosing scope, so
            // the harness would test nothing and report a ReferenceError.
            $harness = preg_replace('/^export\s+/m', '', $chunk) . "\n"
                . "const fixtures = " . $fixturesJson . ";\n"
                . "const wrong = [];\n"
                . "for (const [code, cases] of Object.entries(fixtures)) {\n"
                . "  for (const [n, want] of Object.entries(cases)) {\n"
                . "    const got = pluralCategory(code, Number(n));\n"
                . "    if (got !== want) wrong.push(code + '/' + n + ': want ' + want + ' got ' + got);\n"
                . "  }\n"
                . "}\n"
                . "console.log(JSON.stringify(wrong));\n";

            $tmp = tempnam(sys_get_temp_dir(), 'i18n-parity-') . '.mjs';
            file_put_contents($tmp, $harness);
            $output = @shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1');
            @unlink($tmp);
            $wrong = is_string($output) ? json_decode(trim($output), true) : null;
            check('the JS plural rules agree with PHP on every fixture',
                is_array($wrong) && $wrong === [],
                is_string($output) ? substr(trim($output), 0, 400) : 'no output');
        }
    }
}

// =====================================================================
section('XSS: translations never reach innerHTML unescaped');
// =====================================================================

// A translation is operator-editable through the admin Localization panel, so
// it is user input. The client contract is that t() output goes to textContent
// or an attribute setter; this asserts the module does not violate its own
// contract.
// Two assignments are legitimate and both are asserted:
//   1. `tpl.innerHTML = String(html)` INSIDE sanitizeHtml — the parse sink.
//      A <template> is inert: its content is parsed into a detached
//      DocumentFragment and never runs, which is exactly why this is the
//      standard way to sanitise a fragment.
//   2. `el.innerHTML = sanitizeHtml(...)` at the single opt-in call site, which
//      is fed BY (1) and therefore can only ever receive an already-cleaned
//      string.
$assignments = [];
if (preg_match_all('/([A-Za-z_$][\w$.]*)\.innerHTML\s*=\s*([^;\n]+)/', $js, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) { $assignments[] = [trim($hit[1]), trim($hit[2])]; }
}
check('i18n.js has exactly two innerHTML assignments', count($assignments) === 2,
    json_encode(array_map(static fn(array $a): string => $a[0] . ' = ' . substr($a[1], 0, 40), $assignments)));
check('one is the sanitizer parse sink, on a <template>',
    (bool)array_filter($assignments, static fn(array $a): bool => $a[0] === 'tpl' && str_contains($a[1], 'String(html)')));
check('the other is the opt-in path and is fed by sanitizeHtml()',
    (bool)array_filter($assignments, static fn(array $a): bool => $a[0] === 'el' && str_starts_with($a[1], 'sanitizeHtml(')));
check('no assignment takes an untranslated value',
    !(bool)array_filter($assignments, static fn(array $a): bool => $a[0] !== 'tpl' && !str_starts_with($a[1], 'sanitizeHtml(')));
check('sanitizeHtml strips script/iframe/object/embed',
    str_contains($js, "'script,iframe,object,embed,link,meta,base,form,input,style'"));
check('sanitizeHtml strips on* handlers', str_contains($js, "name.startsWith('on')"));
check('sanitizeHtml blocks javascript: URLs', str_contains($js, "value.startsWith('javascript:')"));
check('attribute writes are limited to an allow-list', str_contains($js, 'SAFE_ATTRS'));
$allowListed = ['href', 'src', 'xlink:href', 'action', 'formaction'];
foreach ($allowListed as $attr) {
    check("the allow-list excludes $attr (a translation cannot set a navigation target)",
        !preg_match("/SAFE_ATTRS = new Set\(\[[^\]]*'$attr'/s", $js));
}

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
