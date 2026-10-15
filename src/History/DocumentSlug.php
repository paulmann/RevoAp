<?php
/**
 * DocumentSlug: a block's topic, as a file name a document viewer can open.
 *
 * The Share button on every copyable block writes `share/<slug>.md`. The name
 * comes from the block's topic - its heading - which is user and model text:
 * a Russian question, a model identity, punctuation, an emoji. MD.Viewer opens
 * a document only when the `?file=` value matches ^[A-Za-z0-9._\-/]+$
 * (see its validateRequestedFile()), and that same whitelist is what makes the
 * write safe: no separator, no `..` and no control character can survive it.
 *
 * So this class is deliberately narrow:
 *
 *   - Cyrillic is transliterated to Latin rather than dropped, so a Russian
 *     topic still produces a readable name;
 *   - everything outside [a-z0-9._-] becomes '-', runs collapse, and both ends
 *     are trimmed of separators;
 *   - the result is capped, and an unusable topic returns '' so the caller can
 *     answer 400 instead of writing a nameless document.
 *
 * The output is pure ASCII by construction: there is no input for which this
 * returns a name carrying a path separator, a leading dot or a length a file
 * system would refuse.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-27
 */
declare(strict_types=1);

namespace App\History;

final class DocumentSlug
{
    /**
     * Longest base name produced. The viewer accepts 255 characters in
     * `?file=`; 90 keeps the name readable in a listing and leaves room for
     * the extension and for a timestamped backup copy someone may keep next
     * to it.
     */
    public const MAX_LENGTH = 90;

    /**
     * Cyrillic to Latin: Russian, plus the Ukrainian and Belarusian letters
     * that differ from it. Multi-character entries are resolved in one pass
     * by strtr(), which is why 'ж' is not turned into 'z' + 'h' by a second
     * mapping. Letters that carry no sound of their own (ъ, ь) map to ''.
     */
    private const TRANSLIT = [
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
        'е' => 'e',  'ё' => 'e',  'ж' => 'zh', 'з' => 'z',  'и' => 'i',
        'й' => 'y',  'к' => 'k',  'л' => 'l',  'м' => 'm',  'н' => 'n',
        'о' => 'o',  'п' => 'p',  'р' => 'r',  'с' => 's',  'т' => 't',
        'у' => 'u',  'ф' => 'f',  'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y',  'ь' => '',
        'э' => 'e',  'ю' => 'yu', 'я' => 'ya',
        'і' => 'i',  'ї' => 'i',  'є' => 'e',  'ґ' => 'g',  'ў' => 'u',

        // Latin accents fold to their base letter instead of becoming a
        // separator, so "Café Münster" stays a readable name rather than
        // 'caf-m-nster'. Input is lowercased before this runs, so only the
        // lowercase forms are listed.
        'à' => 'a',  'á' => 'a',  'â' => 'a',  'ã' => 'a',  'ä' => 'a',
        'å' => 'a',  'æ' => 'ae', 'ç' => 'c',  'è' => 'e',  'é' => 'e',
        'ê' => 'e',  'ë' => 'e',  'ì' => 'i',  'í' => 'i',  'î' => 'i',
        'ï' => 'i',  'ñ' => 'n',  'ò' => 'o',  'ó' => 'o',  'ô' => 'o',
        'õ' => 'o',  'ö' => 'o',  'ø' => 'o',  'œ' => 'oe', 'ù' => 'u',
        'ú' => 'u',  'û' => 'u',  'ü' => 'u',  'ý' => 'y',  'ÿ' => 'y',
        'ß' => 'ss',
    ];

    /**
     * Turn a topic into a safe base name (no extension).
     *
     * @param string $topic The block heading, as shown on screen.
     * @return string A name matching ^[a-z0-9._-]+$ and at most MAX_LENGTH
     *                characters, or '' when the topic holds nothing usable.
     */
    public static function make(string $topic): string
    {
        $topic = trim($topic);
        if ($topic === '') {
            return '';
        }

        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($topic, 'UTF-8')
            : strtolower($topic);
        $latin = strtr($lower, self::TRANSLIT);

        // Everything the whitelist does not allow is a separator. Note that
        // '/' is deliberately not in the allowed set here even though the
        // viewer's own pattern allows it for paths below the browse root: a
        // document published by the Share button is always a single file at
        // the root of share/.
        $mapped = preg_replace('/[^a-z0-9._-]+/', '-', $latin) ?? '';
        $mapped = preg_replace('/-{2,}/', '-', $mapped) ?? '';
        $mapped = preg_replace('/\.{2,}/', '.', $mapped) ?? '';
        $name   = trim($mapped, '-._');

        $name = function_exists('mb_substr')
            ? mb_substr($name, 0, self::MAX_LENGTH, 'UTF-8')
            : substr($name, 0, self::MAX_LENGTH);

        return trim($name, '-._');
    }
}
