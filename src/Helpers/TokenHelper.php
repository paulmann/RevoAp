<?php
/**
 * TokenHelper: Approximate token counting fallback for providers that do
 * not return token usage. Heuristic only — production stats should always
 * prefer values reported by the LLM API.
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

final class TokenHelper
{
    /**
     * Approximate the token count for a given UTF-8 string. The estimator
     * follows OpenAI's rule-of-thumb (~4 chars per token for English) but
     * accommodates non-Latin scripts by counting on a per-character basis
     * with a slightly higher density.
     *
     * @param  string $text Arbitrary UTF-8 text.
     * @return int Estimated token count (>= 0).
     */
    public static function estimateTokens(string $text): int
    {
        $text = Utf8Helper::toUtf8($text);
        $len  = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($len === 0) {
            return 0;
        }
        // Mixed heuristic: avg 4 characters per token for English-like text;
        // add a small safety margin so estimates are not understated.
        $byChars = (int)ceil($len / 4);
        $byWords = (int)ceil(str_word_count($text) * 1.3);
        return max($byChars, $byWords, 1);
    }

    /**
     * Estimate prompt tokens for a chat-completions style messages array.
     *
     * @param  array<int,array{role?:string,content?:string}> $messages
     * @return int
     */
    public static function estimateMessageTokens(array $messages): int
    {
        $sum = 0;
        foreach ($messages as $m) {
            $sum += self::estimateTokens((string)($m['role'] ?? ''));
            $sum += self::estimateTokens((string)($m['content'] ?? ''));
            $sum += 4; // per-message overhead
        }
        return $sum + 2; // priming
    }
}
