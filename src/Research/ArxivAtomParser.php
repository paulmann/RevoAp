<?php
/**
 * ArxivAtomParser: the Atom feed arXiv returns, turned into papers.
 *
 * The API is public and needs no account - the documented endpoint is
 * `export.arxiv.org/api/query` and an answer is an Atom document with one
 * `<entry>` per paper. The fields worth having are the id (which carries the
 * canonical abs/ URL), the title and summary (both of which arrive with the
 * feed's line wrapping, so they are collapsed), the date and the author names.
 *
 * Deliberately forgiving: an entry without a title or an id is skipped rather
 * than thrown away with the whole feed, because a search that returns four good
 * papers and one odd one is still a useful search. The parser never touches the
 * network and never writes anything.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Research;

final class ArxivAtomParser
{
    /** Authors kept per paper; the rest become "et al.". */
    public const MAX_AUTHORS = 3;

    /**
     * @return array<int,array{id:string,url:string,title:string,summary:string,published:string,authors:array<int,string>}>
     */
    public static function parse(string $feed): array
    {
        $papers = [];
        if (preg_match_all('#<entry>(.*?)</entry>#s', $feed, $matches) === false || $matches[1] === []) {
            return [];
        }
        foreach ($matches[1] as $entry) {
            $id = self::text($entry, 'id');
            if ($id === '') {
                continue;
            }
            $title = self::text($entry, 'title');
            if ($title === '') {
                continue;
            }
            $authors = [];
            if (preg_match_all('#<author>\s*<name>(.*?)</name>#s', $entry, $names) !== false) {
                foreach ($names[1] as $name) {
                    $clean = self::collapse($name);
                    if ($clean !== '') {
                        $authors[] = $clean;
                    }
                }
            }
            $papers[] = [
                'id'        => $id,
                'url'       => self::idToUrl($id),
                'title'     => $title,
                'summary'   => self::text($entry, 'summary'),
                'published' => substr(self::text($entry, 'published'), 0, 10),
                'authors'   => self::trimAuthors($authors),
            ];
        }
        return $papers;
    }

    /** The canonical abstract page for a feed id. */
    public static function idToUrl(string $id): string
    {
        $id = trim($id);
        if (str_starts_with($id, 'http://') || str_starts_with($id, 'https://')) {
            return preg_replace('#^http://#', 'https://', $id) ?? $id;
        }
        return 'https://arxiv.org/abs/' . ltrim($id, '/');
    }

    /** @param array<int,string> $authors @return array<int,string> */
    public static function trimAuthors(array $authors): array
    {
        if (count($authors) <= self::MAX_AUTHORS) {
            return $authors;
        }
        return array_merge(array_slice($authors, 0, self::MAX_AUTHORS), ['et al.']);
    }

    /** One field's text, whitespace collapsed. */
    private static function text(string $entry, string $tag): string
    {
        if (preg_match('#<' . $tag . '>(.*?)</' . $tag . '>#s', $entry, $m) !== 1) {
            return '';
        }
        return self::collapse($m[1]);
    }

    private static function collapse(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('#\s+#u', ' ', $value) ?? $value);
    }
}