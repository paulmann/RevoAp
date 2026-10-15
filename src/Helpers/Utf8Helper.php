<?php
/**
 * Utf8Helper: UTF-8 normalization and safe string conversion.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Helpers;

final class Utf8Helper
{
    /**
     * Convert any input to valid UTF-8, replacing malformed bytes.
     *
     * @param  string $input Possibly mixed-encoding text.
     * @return string Valid UTF-8 string.
     */
    public static function toUtf8(string $input): string
    {
        if ($input === '') {
            return '';
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($input, 'UTF-8')) {
            return $input;
        }
        if (function_exists('mb_convert_encoding')) {
            $detected = mb_detect_encoding($input, ['UTF-8', 'Windows-1251', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true);
            $from     = $detected !== false ? $detected : 'UTF-8';
            $r = @mb_convert_encoding($input, 'UTF-8', $from);
            return is_string($r) ? $r : $input;
        }
        return $input;
    }

    /**
     * Strip BOM and normalize line endings to "\n".
     */
    public static function clean(string $input): string
    {
        $input = self::toUtf8($input);
        $input = preg_replace("/^\xEF\xBB\xBF/", '', $input) ?? $input;
        $input = str_replace(["\r\n", "\r"], "\n", $input);
        return $input;
    }

    /**
     * HTML-escape a value for safe insertion into HTML text contexts.
     */
    public static function escape(string $input): string
    {
        return htmlspecialchars(self::toUtf8($input), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
