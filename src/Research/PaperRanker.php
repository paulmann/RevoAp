<?php
/**
 * PaperRanker: choose from the candidate window the papers that actually fit.
 *
 * arXiv answers with its own ordering - useful, but opaque: it cannot say why
 * one paper beat another, and it cannot weigh a title hit above an abstract
 * hit. So the client asks for a wider window and this class re-orders it by
 * how well each paper covers the topic's own terms, keeping the reasoning on
 * the row (score and matched) so the card can show why a paper was kept.
 *
 * Deterministic by design: equal scores fall back to the newer paper, then to
 * the id - the same search twice gives the same order twice.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Research;

class PaperRanker
{
    /** A term found in the title weighs more than one in the abstract. */
    private const TITLE_WEIGHT = 3;
    private const SUMMARY_WEIGHT = 1;

    /** Strip a trailing arXiv version suffix: ".../2509.12345v2" -> ".../2509.12345". */
    public static function baseId(string $id): string
    {
        return (string)preg_replace('/v\d+$/', '', $id);
    }

    /**
     * Re-rank papers by how well they cover the topic terms.
     *
     * @param array<int,array<string,mixed>> $papers rows shaped like the parser's output
     * @param array<int,string> $terms lowercase words or phrases: "consensus", "large language models"
     * @return array<int,array<string,mixed>> de-duplicated rows, each with int `score` and
     *   array `matched` (the terms that hit, in input order), sorted by score DESC, then
     *   published DESC, then id ASC; at most $limit rows
     */
    public static function rank(array $papers, array $terms, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        // One paper, one row: version suffixes are the same paper.
        $kept = [];
        $seen = [];
        foreach ($papers as $paper) {
            if (!is_array($paper)) {
                continue;
            }
            $id = (string)($paper['id'] ?? '');
            $base = $id === '' ? '' : self::baseId($id);
            if ($base !== '') {
                if (isset($seen[$base])) {
                    continue;
                }
                $seen[$base] = true;
            }
            $kept[] = $paper;
        }

        $out = [];
        foreach ($kept as $paper) {
            $title = self::fold((string)($paper['title'] ?? ''));
            $summary = self::fold((string)($paper['summary'] ?? ''));
            $score = 0;
            $matched = [];
            foreach ($terms as $term) {
                $term = (string)$term;
                if ($term === '') {
                    continue;
                }
                $inTitle = self::contains($title, $term);
                $inSummary = self::contains($summary, $term);
                if ($inTitle) {
                    $score += self::TITLE_WEIGHT;
                }
                if ($inSummary) {
                    $score += self::SUMMARY_WEIGHT;
                }
                if ($inTitle || $inSummary) {
                    $matched[] = $term;
                }
            }
            $paper['score'] = $score;
            $paper['matched'] = $matched;
            $out[] = $paper;
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $pa = (string)($a['published'] ?? '');
            $pb = (string)($b['published'] ?? '');
            if ($pa !== $pb) {
                return strcmp($pb, $pa);
            }
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        return array_slice($out, 0, $limit);
    }

    /** Lowercase and collapse whitespace: one shape for matching. */
    private static function fold(string $text): string
    {
        return mb_strtolower((string)preg_replace('/\s+/u', ' ', $text));
    }

    /** Whether a term is present: a phrase is a substring, a word respects boundaries. */
    private static function contains(string $text, string $term): bool
    {
        if ($text === '') {
            return false;
        }
        $term = mb_strtolower($term);
        if (str_contains($term, ' ')) {
            return str_contains($text, $term);
        }
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?:es|s)?(?![\p{L}\p{N}])/u';
        return preg_match($pattern, $text) === 1;
    }
}
