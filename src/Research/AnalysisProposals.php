<?php
/**
 * AnalysisProposals: the searcher's output, turned into something to act on.
 *
 * The analysis itself is prose on purpose, but its point is the list of
 * changes at the end. This class pulls that list out - a "changes" heading
 * and its bullets, or any bullet that cites a paper when the model skipped
 * the heading - and builds the ready change plan: the topic, where it came
 * from, the papers it cites, and the changes as checkboxes an operator can
 * carry into whatever tracks their work.
 *
 * Parsing is optimistic and cheap: headings of any depth, bullets or numbered
 * items, continuation lines glued back on. A model that renames the heading
 * still produces a plan, and a model that omits it entirely is handled by the
 * citation fallback rather than an empty plan.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Research;

class AnalysisProposals
{
    /** Stop here: a plan longer than this is not a plan. */
    public const MAX_ITEMS = 12;

    /** A bullet ("- ", "* ", "• ", "1. ", "2) ") and its text. */
    private const ITEM_PATTERN = '/^\s{0,6}(?:[-*\x{2022}]|\d{1,2}[.)])\s+(.+)$/u';

    /** Heading text that marks the changes section; Russian included. */
    private const CHANGES_HEADING = '/\b(changes?|recommendations?|proposals?|actions?|next steps?)\b|изменени|рекомендац|предложен/iu';

    /**
     * Pull the concrete changes out of an analysis.
     *
     * @return array<int,string> 0..MAX_ITEMS items, cleaned, in document order
     */
    public static function extract(string $analysis): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $analysis);
        if (!is_array($lines)) {
            return [];
        }
        $lines = array_map(static fn(string $line): string => rtrim($line), $lines);

        $items = self::fromChangesSection($lines);
        if ($items === []) {
            $items = self::fromCitedBullets($lines);
        }
        return $items;
    }

    /**
     * A ready change plan in markdown: the topic, where it came from, the
     * papers, and the changes as checkboxes.
     *
     * @param array<int,array<string,mixed>> $papers
     * @param array<int,string> $proposals
     * @param array<int,bool>|null $checked the operator's checkbox state, when the plan has one
     */
    public static function planMarkdown(
        string $topic,
        string $provider,
        string $model,
        array $papers = [],
        array $proposals = [],
        ?string $date = null,
        ?array $checked = null
    ): string {
        $date = $date ?? date('Y-m-d');
        $lines = [];
        $lines[] = '# Change plan: ' . trim($topic);
        $lines[] = '';
        $lines[] = sprintf('Created: %s - analysed by %s / %s over %d arXiv paper(s).', $date, $provider, $model, count($papers));
        $lines[] = '';
        $lines[] = '## Papers';
        $lines[] = '';
        if ($papers === []) {
            $lines[] = '_(no papers were selected)_';
        } else {
            $number = 1;
            foreach ($papers as $paper) {
                $title = trim((string)($paper['title'] ?? ''));
                $url = trim((string)($paper['url'] ?? ''));
                if ($title === '') {
                    $title = '(untitled)';
                }
                $lines[] = $number . '. ' . $title . ($url !== '' ? ' - ' . $url : '');
                $number++;
            }
        }
        $lines[] = '';
        $lines[] = '## Changes to consider';
        $lines[] = '';
        if ($proposals === []) {
            $lines[] = '_(no separate list of changes was detected in the analysis)_';
        } else {
            foreach (array_values($proposals) as $index => $proposal) {
                $done = is_array($checked) && !empty($checked[$index]);
                $lines[] = '- [' . ($done ? 'x' : ' ') . '] ' . (string)$proposal;
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Items under the first changes-like heading, up to the next heading.
     *
     * @param array<int,string> $lines
     * @return array<int,string>
     */
    private static function fromChangesSection(array $lines): array
    {
        $items = [];
        $inSection = false;
        $current = -1;
        foreach ($lines as $line) {
            $heading = self::headingText($line);
            if ($heading !== null) {
                if ($inSection) {
                    break; // the section ended at the next heading
                }
                $inSection = preg_match(self::CHANGES_HEADING, $heading) === 1;
                continue;
            }
            if (!$inSection) {
                continue;
            }
            if (preg_match(self::ITEM_PATTERN, $line, $match) === 1) {
                if (count($items) >= self::MAX_ITEMS) {
                    break;
                }
                $items[] = self::clean($match[1]);
                $current = count($items) - 1;
                continue;
            }
            $text = trim($line);
            if ($text !== '' && $current >= 0) {
                $items[$current] = self::clean($items[$current] . ' ' . $text);
            }
        }
        return self::dedupe($items);
    }

    /**
     * The fallback: bullets anywhere that cite a paper.
     *
     * @param array<int,string> $lines
     * @return array<int,string>
     */
    private static function fromCitedBullets(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (preg_match(self::ITEM_PATTERN, $line, $match) !== 1) {
                continue;
            }
            if (preg_match('/\[\d+\]/', $match[1]) !== 1) {
                continue;
            }
            $items[] = self::clean($match[1]);
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }
        return self::dedupe($items);
    }

    /** A heading line's text, or null when the line is not a heading. */
    private static function headingText(string $line): ?string
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        if (preg_match('/^#{1,4}\s+(.+)$/u', $line, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/^\*\*(.+?)\*\*:?$/u', $line, $match) === 1) {
            return $match[1];
        }
        return null;
    }

    /** Collapse whitespace: one shape for comparing and for display. */
    private static function clean(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<int,string> $items
     * @return array<int,string>
     */
    private static function dedupe(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if ($item === '' || isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $out[] = $item;
        }
        return $out;
    }
}
