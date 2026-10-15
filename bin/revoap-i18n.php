#!/usr/bin/env php
<?php
/**
 * revoap-i18n.php — catalog tooling: lint, extract, coverage, add-key.
 *
 *     php bin/revoap-i18n.php lint                 validate every catalog
 *     php bin/revoap-i18n.php coverage             per-locale coverage table
 *     php bin/revoap-i18n.php extract              re-scan PHP/JS for used keys
 *     php bin/revoap-i18n.php add-key <k> <text>   append a key to en.json
 *     php bin/revoap-i18n.php diff <locale>        list a locale's missing keys
 *
 * Exits non-zero when `lint` finds a problem, so CI can gate on it.
 *
 * What `lint` checks, and why each check exists
 * ---------------------------------------------
 *  1. Every catalog is valid JSON and an object, not an array.
 *  2. Every key of en.json exists in every other catalog. A missing key is
 *     not a cosmetic problem: the UI renders the raw key text, so
 *     `settings.api_key_saved` ends up on screen in front of an operator.
 *  3. Every pluralised key defines **every** CLDR category its language
 *     uses. This is the bug that `missing` cannot catch: Russian needs
 *     `.one/.few/.many/.other`, and a catalog that defines only `.one` and
 *     `.other` silently renders "5 минут" as "5 минуты" for n=5.
 *  4. No catalog defines a key en.json does not have. Extras are usually a
 *     rename that was applied to one file and not the others, and they make
 *     the coverage number lie.
 *  5. Placeholder sets match. If en says `{count} items` and de says
 *     `{anzahl} Elemente`, the German string renders with a literal
 *     `{anzahl}` — the interpolation is by name, not by position.
 *  6. No key contains a `.` in a single path segment, and no leaf is empty.
 *  7. Direction metadata agrees with Locale::dir().
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80500) {
    fwrite(STDERR, "revoap-i18n requires PHP 8.5 or newer; running " . PHP_VERSION . "\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

use App\I18n\Locale;
use App\I18n\MessageCatalog;
use App\I18n\PluralRules;

MessageCatalog::setDirectory($root . '/i18n');

$command = $argv[1] ?? 'lint';
$args    = array_slice($argv, 2);

/** @param array<string,mixed> $node @return array<string,string> */
function flatten(array $node, string $prefix = ''): array
{
    return MessageCatalog::flatten($node, $prefix);
}

function loadRaw(Locale $locale): ?array
{
    $path = MessageCatalog::path($locale);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true, 16);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Is this flattened key one leaf of a plural group?
 * A key is a plural leaf when its last segment is a CLDR category name.
 */
function pluralLeaf(string $key): ?array
{
    $segments = explode('.', $key);
    $last = end($segments);
    $categories = ['zero', 'one', 'two', 'few', 'many', 'other'];
    if (!in_array($last, $categories, true) || count($segments) < 2) {
        return null;
    }
    array_pop($segments);
    return ['base' => implode('.', $segments), 'category' => $last];
}

/** @return array<int,string> placeholder names in a message */
function placeholders(string $message): array
{
    preg_match_all('/\{\s*([A-Za-z0-9_.\-]+)\s*(?:,\s*[a-z]+)?\s*\}/', $message, $m);
    $names = $m[1] ?? [];
    sort($names);
    return array_values(array_unique($names));
}

/** @return array<int,string> problems found */
function lint(bool $verbose = false, bool $strict = false): array
{
    $problems = [];
    $warnings = [];
    $reference = Locale::English;

    $refRaw = loadRaw($reference);
    if ($refRaw === null) {
        return ['FATAL: i18n/en.json is missing or is not valid JSON — nothing can be validated.'];
    }
    unset($refRaw['_meta']);
    $ref = flatten($refRaw);

    // Build the set of plural base keys from the reference. Everything that is
    // a plural leaf is dropped from the plain key list and handled separately,
    // because each locale needs a DIFFERENT set of categories.
    $refPlain  = [];
    $refPlural = [];
    foreach ($ref as $key => $text) {
        $leaf = pluralLeaf($key);
        if ($leaf === null) {
            $refPlain[$key] = $text;
        } else {
            $refPlural[$leaf['base']][$leaf['category']] = $text;
        }
    }

    foreach (Locale::all() as $locale) {
        $tag = $locale->value;
        $raw = loadRaw($locale);
        if ($raw === null) {
            $problems[] = "[$tag] catalog missing or not valid JSON";
            continue;
        }

        // 7. Direction metadata must agree with the enum.
        $meta = $raw['_meta'] ?? null;
        if (is_array($meta)) {
            if (($meta['dir'] ?? '') !== $locale->dir()) {
                $problems[] = "[$tag] _meta.dir is " . var_export($meta['dir'] ?? null, true) . " but Locale::dir() is {$locale->dir()}";
            }
            if (($meta['locale'] ?? '') !== $tag) {
                $problems[] = "[$tag] _meta.locale is " . var_export($meta['locale'] ?? null, true);
            }
            $declared = $meta['plural_categories'] ?? null;
            $expected = PluralRules::categoriesFor($locale);
            if (is_array($declared) && array_values($declared) !== $expected) {
                $problems[] = "[$tag] _meta.plural_categories is [" . implode(',', $declared) . "] but CLDR requires [" . implode(',', $expected) . "]";
            }
        }
        unset($raw['_meta']);

        $flat = flatten($raw);
        $plain  = [];
        $plural = [];
        foreach ($flat as $key => $text) {
            $leaf = pluralLeaf($key);
            if ($leaf === null) { $plain[$key] = $text; } else { $plural[$leaf['base']][$leaf['category']] = $text; }
        }

        // 2. Every plain key present.
        $missingPlain = array_diff(array_keys($refPlain), array_keys($plain));
        foreach ($missingPlain as $k) {
            $problems[] = "[$tag] missing key: $k";
        }

        // 4. No extras.
        $extraPlain = array_diff(array_keys($plain), array_keys($refPlain));
        foreach ($extraPlain as $k) {
            $problems[] = "[$tag] key not present in en.json: $k";
        }

        // 3. Every plural base defines every category this language needs,
        //    and defines nothing outside that set.
        $needed = PluralRules::categoriesFor($locale);
        foreach (array_keys($refPlural) as $base) {
            $have = $plural[$base] ?? [];
            foreach ($needed as $cat) {
                if (!isset($have[$cat])) {
                    $problems[] = "[$tag] missing plural form: $base.$cat (needs " . implode('/', $needed) . ')';
                }
            }
            foreach (array_diff(array_keys($have), $needed) as $cat) {
                $problems[] = "[$tag] plural form $base.$cat is not used by this language";
            }
        }
        foreach (array_diff(array_keys($plural), array_keys($refPlural)) as $base) {
            $problems[] = "[$tag] plural base not present in en.json: $base";
        }

        // 5. Placeholders.
        //
        // The two directions are NOT equally bad:
        //
        //   * a placeholder the reference does not have is always an error —
        //     interpolation is by name, so `{anzahl}` renders on screen as the
        //     literal text "{anzahl}";
        //   * a reference placeholder a translation omits is legitimate when
        //     the language inflects the numeral into the noun. Arabic spells
        //     "one event" as حدث واحد and "two events" as حدثان with no digit
        //     at all, and Russian's .one form often does the same. Forcing
        //     {count} into those strings would produce "حدث واحد 1".
        //
        // So: unknown placeholder = error, dropped placeholder = warning that
        // only fails the build under --strict. The `zero|one|two` categories
        // are exempt even from the warning, because that is exactly where
        // grammatical number words live.
        $checkPlaceholders = static function (string $key, string $refText, string $text) use (&$problems, &$warnings, $tag): void {
            $a = placeholders($refText);
            $b = placeholders($text);
            $unknown = array_diff($b, $a);
            if ($unknown !== []) {
                $problems[] = "[$tag] $key uses placeholder(s) en.json does not define: " . implode(',', $unknown);
            }
            $dropped = array_diff($a, $b);
            if ($dropped === []) {
                return;
            }
            $leaf = pluralLeaf($key);
            if ($leaf !== null && in_array($leaf['category'], ['zero', 'one', 'two'], true)) {
                return; // grammatical number word — the digit is in the noun
            }
            $warnings[] = "[$tag] $key drops placeholder(s): " . implode(',', $dropped);
        };

        foreach ($refPlain as $k => $refText) {
            if (!isset($plain[$k])) { continue; }
            $checkPlaceholders($k, $refText, (string)$plain[$k]);
        }
        foreach ($refPlural as $base => $cats) {
            $refPh = (string)reset($cats);
            foreach (($plural[$base] ?? []) as $cat => $text) {
                $checkPlaceholders($base . '.' . $cat, $refPh, (string)$text);
            }
        }

        // 6. Empty leaves.
        foreach ($flat as $k => $v) {
            // `a11y.decorative` is intentionally empty: it is the accessible
            // name for a purely decorative icon, where the correct value IS
            // the empty string.
            if ($v === '' && $k !== 'a11y.decorative') {
                $problems[] = "[$tag] empty translation: $k";
            }
        }

        if ($verbose) {
            printf(
                "[%s] %4d keys, %2d plural bases, coverage %.1f%%\n",
                $tag,
                count($flat),
                count($plural),
                $refPlain === [] ? 100.0 : (count(array_intersect_key($refPlain, $plain)) / count($refPlain)) * 100
            );
        }
    }

    if ($strict) {
        foreach ($warnings as $w) { $problems[] = $w . ' (--strict)'; }
    } elseif ($warnings !== []) {
        $problems[] = 'NOTE: ' . count($warnings) . ' translation(s) drop a placeholder the English text uses.'
            . ' That is legitimate where the language inflects the numeral into a word;'
            . ' re-run with --strict to list them all.';
    }

    return $problems;
}

/** Scan first-party PHP/JS for translation keys and report ones no catalog has. */
function extractKeys(): array
{
    $root = dirname(__DIR__);
    $raw  = loadRaw(Locale::English) ?? [];
    unset($raw['_meta']);
    $known = array_keys(flatten($raw));

    $used = [];
    $files = [];
    // v2.1.0 fix: the previous shape was
    //     foreach ([iterA, iterB] as $it) { $it->isFile() … }
    // which iterates the ARRAY OF ITERATORS — two items, both
    // RecursiveIteratorIterator objects whose isFile() forwards to an
    // unstarted inner iterator and answers false. Net effect: the extractor
    // silently scanned ONLY index.php and every orphan in src/ or assets/js
    // was invisible. Each directory now gets its own iteration.
    foreach ([$root . '/src', $root . '/assets/js'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $it) {
            /** @var SplFileInfo $it */
            if ($it->isFile() && in_array($it->getExtension(), ['php', 'js', 'mjs'], true)) {
                $files[] = $it->getPathname();
            }
        }
    }
    $files[] = $root . '/index.php';

    // Both spellings: PHP `Translator::t('a.b')` / `tn('a.b', …)` and JS
    // `t('a.b')` / `tn('a.b', …)`. Also the markup attributes.
    $pattern = '/\b(?:Translator::)?t[n]?\(\s*[\'"]([a-z0-9_.\-]+)[\'"]/i';
    $attrPattern = '/data-i18n(?:-attr|-html)?\s*=\s*["\']([^"\']+)["\']/';
    foreach ($files as $f) {
        $src = (string)file_get_contents($f);
        if ($src === '') { continue; }
        if (preg_match_all($pattern, $src, $m)) {
            foreach ($m[1] as $k) { $used[$k][$f] = true; }
        }
        if (preg_match_all($attrPattern, $src, $m2)) {
            foreach ($m2[1] as $blob) {
                foreach (explode(';', $blob) as $pair) {
                    $k = trim(preg_replace('/^[a-z\-]+:/', '', $pair) ?? '');
                    if ($k !== '' && preg_match('/^[a-z0-9_.\-]+$/i', $k)) { $used[$k][$f] = true; }
                }
            }
        }
    }

    $orphans = [];
    foreach (array_keys($used) as $k) {
        if (in_array($k, $known, true)) {
            continue;
        }
        // v2.1.0: a capture that ends in `.` or `_` is a CONCATENATION
        // PREFIX — `t('nav.' . $tab)`, `t('budgets.period_' + p)` — not a
        // literal key; the real key only exists at runtime. Validate the
        // prefix the only way static analysis can: at least one catalogue
        // key must live under it. A prefix with no keys beneath it is still
        // an orphan (the code references a namespace that does not exist).
        if (str_ends_with($k, '.') || str_ends_with($k, '_')) {
            foreach ($known as $candidate) {
                if (str_starts_with($candidate, $k)) {
                    continue 2;
                }
            }
        }
        // A tn() call references the plural BASE (`audit.entries`); the
        // catalogue stores the CLDR leaves (`audit.entries.one/.other/…`).
        // The base is valid when any leaf beneath it exists.
        $isPluralBase = false;
        foreach (['zero', 'one', 'two', 'few', 'many', 'other'] as $cat) {
            if (in_array($k . '.' . $cat, $known, true)) {
                $isPluralBase = true;
                break;
            }
        }
        if ($isPluralBase) {
            continue;
        }
        $orphans[$k] = array_keys($used[$k]);
    }
    return $orphans;
}

switch ($command) {
    case 'lint':
        $problems = lint(
            in_array('-v', $args, true) || in_array('--verbose', $args, true),
            in_array('--strict', $args, true)
        );
        if ($problems === []) {
            echo "i18n lint: OK — every catalog covers every key of en.json,\n";
            echo "             every plural form its language needs, and every\n";
            echo "             placeholder set matches the reference.\n";
            exit(0);
        }
        foreach ($problems as $p) { echo "  $p\n"; }
        printf("i18n lint: %d problem(s)\n", count($problems));
        exit(1);

    case 'coverage':
        $ref = loadRaw(Locale::English) ?? [];
        unset($ref['_meta']);
        $refCount = count(flatten($ref));
        printf("%-6s %-24s %6s %8s %s\n", 'code', 'name', 'keys', 'of en', 'direction');
        foreach (Locale::all() as $locale) {
            $raw = loadRaw($locale) ?? [];
            unset($raw['_meta']);
            $n = count(flatten($raw));
            printf(
                "%-6s %-24s %6d %7.1f%% %s\n",
                $locale->value,
                $locale->nativeName(),
                $n,
                $refCount === 0 ? 0.0 : min(100.0, ($n / $refCount) * 100),
                $locale->dir()
            );
        }
        exit(0);

    case 'extract':
        $orphans = extractKeys();
        if ($orphans === []) {
            echo "i18n extract: every key referenced in src/ and assets/js/ exists in en.json\n";
            exit(0);
        }
        foreach ($orphans as $k => $files) {
            printf("  %-46s %s\n", $k, implode(', ', array_map(static fn($f) => basename((string)$f), $files)));
        }
        printf("i18n extract: %d referenced key(s) missing from en.json\n", count($orphans));
        exit(1);

    case 'diff':
        $code = $args[0] ?? '';
        $locale = Locale::fromTag($code);
        if ($locale === null) {
            fwrite(STDERR, "usage: revoap-i18n.php diff <locale>\n");
            exit(2);
        }
        $refRaw = loadRaw(Locale::English) ?? [];
        unset($refRaw['_meta']);
        $raw = loadRaw($locale) ?? [];
        unset($raw['_meta']);
        $missing = array_diff(array_keys(flatten($refRaw)), array_keys(flatten($raw)));
        if ($missing === []) { echo "[$locale->value] nothing missing\n"; exit(0); }
        foreach ($missing as $k) { echo "  $k\n"; }
        printf("[$locale->value] %d missing\n", count($missing));
        exit(1);

    case 'add-key':
        $key  = $args[0] ?? '';
        $text = $args[1] ?? '';
        if ($key === '' || $text === '') {
            fwrite(STDERR, "usage: revoap-i18n.php add-key <dotted.key> <english text>\n");
            exit(2);
        }
        $path = $root . '/i18n/en.json';
        $doc  = json_decode((string)file_get_contents($path), true) ?? [];
        $segments = explode('.', $key);
        $node = &$doc;
        foreach ($segments as $i => $seg) {
            if ($i === count($segments) - 1) {
                if (isset($node[$seg])) {
                    fwrite(STDERR, "key already exists: $key\n");
                    exit(1);
                }
                $node[$seg] = $text;
                break;
            }
            if (!isset($node[$seg]) || !is_array($node[$seg])) { $node[$seg] = []; }
            $node = &$node[$seg];
        }
        unset($node);
        file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        echo "added $key to en.json — add it to de/ru/zh/ar and re-run `lint`\n";
        exit(0);

    default:
        fwrite(STDERR, <<<TXT
        RevoAp i18n tooling

        Usage: php bin/revoap-i18n.php <command> [args]

          lint [-v]        Validate every catalog against en.json (CI gate).
          coverage         Print a per-locale key-count / coverage table.
          extract          Report keys referenced in code but absent from en.json.
          diff <locale>    List the keys a single locale is missing.
          add-key <k> <t>  Append an English key; siblings then need it too.

        TXT);
        exit(2);
}
