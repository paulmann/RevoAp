<?php
/**
 * JsonHelper: Safe JSON encode/decode wrappers using JSON_THROW_ON_ERROR.
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

final class JsonHelper
{
    /**
     * Encode a value as JSON, throwing on failure.
     *
     * @param  mixed $value     Encodable value.
     * @param  bool  $pretty    Use JSON_PRETTY_PRINT.
     * @param  bool  $unescaped Use JSON_UNESCAPED_UNICODE | _SLASHES.
     * @return string Valid JSON.
     * @throws \JsonException
     */
    public static function encode(mixed $value, bool $pretty = false, bool $unescaped = true): string
    {
        $flags = JSON_THROW_ON_ERROR;
        if ($pretty)    { $flags |= JSON_PRETTY_PRINT; }
        if ($unescaped) { $flags |= JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES; }
        return json_encode($value, $flags);
    }

    /**
     * Decode a JSON string into an associative array (or scalar/null).
     *
     * @return mixed Parsed value (array|null|bool|int|float|string).
     * @throws \JsonException
     */
    public static function decode(string $json): mixed
    {
        if ($json === '') {
            return null;
        }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode without throwing — returns $default on failure.
     */
    public static function tryDecode(string $json, mixed $default = null): mixed
    {
        try {
            return self::decode($json);
        } catch (\JsonException) {
            return $default;
        }
    }

    /**
     * Try to extract the first top-level JSON object/array from arbitrary text
     * (useful when an LLM wraps output in prose or fences).
     */
    public static function extractFirstObject(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        // Strip common ```json fences
        if (preg_match('#```(?:json)?\s*(\{.*?\}|\[.*?\])\s*```#s', $text, $m) === 1) {
            $candidate = $m[1];
            $r         = self::tryDecode($candidate);
            if (is_array($r)) {
                return $r;
            }
        }
        // Direct
        $r = self::tryDecode($text);
        if (is_array($r)) {
            return $r;
        }
        // Find first balanced object using brace matching
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $len   = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $c = $text[$i];
            if ($c === '{') { $depth++; }
            if ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    $r = self::tryDecode($candidate);
                    return is_array($r) ? $r : null;
                }
            }
        }
        return null;
    }
}
