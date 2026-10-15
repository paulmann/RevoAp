<?php
/**
 * ArxivClient: one search against the public arXiv API.
 *
 * No account is involved and none is needed: the endpoint is documented as
 * open, and arXiv asks only for a polite User-Agent and a modest request rate.
 * This client makes one request per search, caps the result count, and times
 * out rather than holding a page open.
 *
 * A search asks for a wider window of candidates than it returns: choosing
 * among them is PaperRanker's job, where the choice can be explained.
 *
 * @project  RevoAp
 * @version  1.1.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Research;

final class ArxivClient
{
    /** The documented endpoint. */
    public const ENDPOINT = 'https://export.arxiv.org/api/query';

    /** Papers pulled per search by default, and the ceiling. */
    public const DEFAULT_MAX = 5;
    public const MAX_RESULTS = 10;

    /** Candidates pulled for local ranking; more than shown, so ranking has a choice. */
    public const CANDIDATE_MAX = 30;

    /** Transport budget for one search. */
    private const TIMEOUT_SECONDS = 30;

    /**
     * Turn a topic into `all:word AND all:word`, the API's own query syntax.
     * Quoted phrases survive as phrases.
     */
    public static function searchQuery(string $topic): string
    {
        $topic = trim($topic);
        if ($topic === '') {
            return '';
        }
        // A quoted phrase is kept whole; the rest becomes AND-ed terms.
        $parts = [];
        foreach (self::parseTopic($topic) as $part) {
            $parts[] = 'all:' . ($part['phrase'] ? '"' . $part['text'] . '"' : $part['text']);
        }
        return implode(' AND ', $parts);
    }

    /**
     * The topic's terms, lowercased, in the same order and with the same cap as
     * the query builder: the ranker scores papers against exactly the terms the
     * search asked for.
     *
     * @return array<int,string>
     */
    public static function topicTerms(string $topic): array
    {
        $terms = [];
        foreach (self::parseTopic($topic) as $part) {
            $terms[] = mb_strtolower($part['text']);
        }
        return $terms;
    }

    /** How many candidates a search pulls for a caller that wants $max papers. */
    public static function candidatesFor(int $max): int
    {
        $max = max(1, $max);
        return min(self::CANDIDATE_MAX, max(10, $max * 5));
    }

    /**
     * The topic split into terms: a quoted phrase is one term, the rest are
     * words. Shared by the query builder and the ranker so both agree on what
     * the topic is made of.
     *
     * @return array<int,array{text:string,phrase:bool}>
     */
    private static function parseTopic(string $topic): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return [];
        }
        $parts = [];
        if (preg_match_all('/"([^"]{2,80})"|([^\s"]+)/u', $topic, $m, PREG_SET_ORDER) !== false) {
            foreach ($m as $match) {
                $phrase = $match[1] !== '';
                $text = trim($phrase ? $match[1] : $match[2]);
                if ($text === '' || (!$phrase && mb_strlen($text) < 2)) {
                    continue;
                }
                $parts[] = ['text' => $text, 'phrase' => $phrase];
                if (count($parts) >= 8) {
                    break;
                }
            }
        }
        return $parts;
    }

    /**
     * @return array{papers:array<int,array<string,mixed>>,error:?string,query:string}
     */
    public static function search(string $topic, int $max = self::DEFAULT_MAX): array
    {
        $query = self::searchQuery($topic);
        if ($query === '') {
            return ['papers' => [], 'error' => 'empty_topic', 'query' => ''];
        }
        $max = max(1, min(self::MAX_RESULTS, $max));
        // Ask for a window, return a rank: the caller ranks before answering.
        $window = self::candidatesFor($max);
        $url = self::ENDPOINT . '?search_query=' . rawurlencode($query)
            . '&start=0&max_results=' . $window . '&sortBy=relevance&sortOrder=descending';

        $ch = curl_init();
        if ($ch === false) {
            return ['papers' => [], 'error' => 'curl_init_failed', 'query' => $query];
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // arXiv asks for a contact that can be reached if a script misbehaves.
            CURLOPT_USERAGENT      => 'RevoAp/1.0 (research; +https://revoap.com)',
            CURLOPT_HTTPHEADER     => ['Accept: application/atom+xml'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            return ['papers' => [], 'error' => $err !== '' ? $err : ('HTTP ' . $code), 'query' => $query];
        }
        if ($code >= 400) {
            return ['papers' => [], 'error' => 'HTTP ' . $code, 'query' => $query];
        }
        return ['papers' => ArxivAtomParser::parse($body), 'error' => null, 'query' => $query];
    }
}