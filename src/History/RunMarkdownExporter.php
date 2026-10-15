<?php
/**
 * RunMarkdownExporter: turn one stored run into a file you can keep.
 *
 * R8. The History tab shows a run; this writes it out. Two shapes come out of
 * the same data:
 *
 *   - Markdown, for the knowledge the run produced: the question, the final
 *     answer, every participant's answer and the events that explain the run.
 *   - A self-contained HTML page with a print stylesheet, which is how a PDF
 *     is produced without adding a PDF library: the operator opens it and
 *     prints. Same bytes, no dependency, nothing to keep patched.
 *
 * The class is pure: it takes the array `GET /api/history/session/{id}`
 * already returns and returns a string. No queries, no filesystem, no config -
 * which is what makes it testable and keeps the controller thin.
 *
 * Two things it deliberately does not do:
 *   - it never invents a field: anything missing from the detail is omitted
 *     rather than rendered as an empty heading;
 *   - it never puts run text into the file *name*. The name is built from the
 *     session id and its timestamp, so a query containing slashes, quotes or
 *     dots cannot steer the write anywhere (`share/` traversal).
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-25
 */
declare(strict_types=1);

namespace App\History;

final class RunMarkdownExporter
{
    /** Characters of an answer kept as the document title. */
    private const TITLE_CHARS = 90;

    /**
     * @param array<string,mixed> $detail the payload of GET /api/history/session/{id}
     */
    public static function render(array $detail): string
    {
        $session = self::asArray($detail['session'] ?? null);
        $steps   = self::asList($detail['steps'] ?? null);
        $events  = self::asList($detail['events'] ?? null);

        $out = [];
        $out[] = '# ' . self::title($session);
        $out[] = '';
        $out[] = self::metaTable($session, $steps, $detail);
        $out[] = '';

        $query = self::text($session['query_text'] ?? '');
        if ($query !== '') {
            $out[] = '## Question';
            $out[] = '';
            $out[] = $query;
            $out[] = '';
        }

        $answer = self::text($session['final_answer'] ?? '');
        if ($answer !== '') {
            $out[] = '## Final answer';
            $out[] = '';
            $out[] = $answer;
            $out[] = '';
        }

        $pipeline = self::pipeline($steps);
        if ($pipeline !== []) {
            $out[] = '## Pipeline';
            $out[] = '';
            foreach ($pipeline as $block) {
                $out[] = $block;
                $out[] = '';
            }
        }

        $eventLines = self::eventLines($events);
        if ($eventLines !== []) {
            $out[] = '## Events';
            $out[] = '';
            $out[] = '| # | Event | At |';
            $out[] = '|---|-------|----|';
            foreach ($eventLines as $line) {
                $out[] = $line;
            }
            $out[] = '';
        }

        $out[] = '---';
        $out[] = '';
        $out[] = '_Exported from RevoAp History. The run above is stored in the journal; '
            . 'this file is a copy, not a new run._';
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * A self-contained, print-ready page. Same content, so the "PDF" the
     * operator gets is the document they just read - not a second rendering
     * that can disagree with it.
     *
     * @param array<string,mixed> $detail
     */
    public static function renderHtml(array $detail): string
    {
        $markdown = self::render($detail);
        $title    = htmlspecialchars(self::title(self::asArray($detail['session'] ?? null)), ENT_QUOTES, 'UTF-8');
        $body     = self::markdownToHtml($markdown);

        return "<!doctype html>\n"
            . "<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . $title . "</title>\n"
            . "<style>\n"
            . "  :root { color-scheme: light; }\n"
            . "  body { margin: 0; padding: 32px 24px 64px; background: #f7f7f5; color: #16181d;\n"
            . "         font: 15px/1.62 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; }\n"
            . "  main { max-width: 900px; margin: 0 auto; background: #fff; border: 1px solid #e3e3e0;\n"
            . "         border-radius: 10px; padding: 40px 44px; }\n"
            . "  h1 { font-size: 26px; line-height: 1.3; margin: 0 0 18px; }\n"
            . "  h2 { font-size: 19px; margin: 32px 0 10px; padding-top: 16px; border-top: 1px solid #ececea; }\n"
            . "  h3 { font-size: 16px; margin: 22px 0 8px; }\n"
            . "  h4 { font-size: 14px; margin: 16px 0 6px; color: #4a4f57; }\n"
            . "  table { border-collapse: collapse; width: 100%; margin: 10px 0 18px; font-size: 14px; }\n"
            . "  th, td { border: 1px solid #e3e3e0; padding: 6px 10px; text-align: left; vertical-align: top; }\n"
            . "  th { background: #faf9f7; font-weight: 600; }\n"
            . "  pre { background: #f6f6f4; border: 1px solid #e6e6e3; border-radius: 8px; padding: 12px 14px;\n"
            . "        overflow-x: auto; font-size: 13px; line-height: 1.5; }\n"
            . "  code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }\n"
            . "  blockquote { margin: 12px 0; padding: 2px 0 2px 14px; border-left: 3px solid #d8d8d4; color: #4a4f57; }\n"
            . "  hr { border: 0; border-top: 1px solid #ececea; margin: 28px 0 14px; }\n"
            . "  em { color: #6b7078; }\n"
            . "  @media print {\n"
            . "    body { background: #fff; padding: 0; }\n"
            . "    main { border: 0; border-radius: 0; padding: 0; max-width: none; }\n"
            . "    h2 { break-after: avoid; }\n"
            . "    pre, table, blockquote { break-inside: avoid; }\n"
            . "  }\n"
            . "</style>\n</head>\n<body>\n<main>\n"
            . $body
            . "</main>\n</body>\n</html>\n";
    }

    /**
     * `run-26-20260925-162000.md` - id and timestamp only, never run text.
     *
     * @param array<string,mixed> $detail
     */
    public static function fileName(array $detail): string
    {
        $session = self::asArray($detail['session'] ?? null);
        $id      = (int)($session['id'] ?? 0);
        $stamp   = self::stamp(self::text($session['created_at'] ?? ''));

        return 'run-' . $id . '-' . $stamp . '.md';
    }

    /** The same name with a different extension. */
    public static function fileNameFor(array $detail, string $extension): string
    {
        $base = preg_replace('/\.md$/', '', self::fileName($detail));

        return $base . '.' . preg_replace('/[^a-z0-9]/i', '', $extension);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param array<string,mixed> $session
     * @param list<array<string,mixed>> $steps
     * @param array<string,mixed> $detail
     */
    private static function metaTable(array $session, array $steps, array $detail): string
    {
        $responses = 0;
        foreach ($steps as $step) {
            $responses += count(self::asList($step['responses'] ?? null));
        }

        $rows = [
            'Session'   => '#' . (int)($session['id'] ?? 0),
            'Created'   => self::text($session['created_at'] ?? ''),
            'Completed' => self::text($session['completed_at'] ?? ''),
            'Status'    => self::text($session['status'] ?? ''),
            'Consensus' => trim(self::text($session['consensus_status'] ?? '') . ' ' . self::score($session)),
            'Skill'     => self::text($session['skill_name'] ?? '') !== ''
                ? self::text($session['skill_name']) . ' (' . self::text($session['skill_slug'] ?? '') . ')'
                : self::text($session['skill_slug'] ?? ''),
            'Steps'     => (string)count($steps),
            'Answers'   => (string)$responses,
            'Tokens'    => (string)(int)($session['total_tokens'] ?? 0),
            'Duration'  => self::duration((int)($session['duration_ms'] ?? 0)),
        ];

        $cache = self::asArray($session['cache'] ?? null);
        if ($cache !== []) {
            $rows['Cache'] = self::cacheLine($cache);
        }

        $lines = ['| | |', '|---|---|'];
        foreach ($rows as $label => $value) {
            if (trim((string)$value) === '' || $value === '#0') {
                continue;
            }
            $lines[] = '| **' . $label . '** | ' . self::cell((string)$value) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string,mixed>> $steps
     * @return list<string>
     */
    private static function pipeline(array $steps): array
    {
        $blocks = [];
        foreach ($steps as $step) {
            $key    = self::text($step['step_key'] ?? '');
            $label  = self::text($step['label'] ?? '');
            $status = self::text($step['status'] ?? '');

            $facts = [];

            $blocks[] = '### ' . ($label !== '' ? $label : $key)
                . ($status !== '' ? '  —  ' . $status : '')
                . ($facts === [] ? '' : ' (' . implode(', ', $facts) . ')');

            $summary = self::text($step['summary'] ?? '');
            if ($summary !== '') {
                $blocks[] = '';
                $blocks[] = $summary;
            }

            foreach (self::asList($step['responses'] ?? null) as $response) {
                $model = self::text($response['model'] ?? '');
                $prov  = self::text($response['provider'] ?? '');
                $hrs   = self::duration((int)($response['latency_ms'] ?? 0));
                $head  = '#### ' . ($prov !== '' ? $prov . '/' : '') . $model;
                if ($hrs !== '') {
                    $head .= '  —  ' . $hrs;
                }
                $blocks[] = '';
                $blocks[] = $head;
                $body = self::text($response['raw_content'] ?? '');
                if ($body !== '') {
                    $blocks[] = '';
                    $blocks[] = $body;
                }
            }
        }

        return $blocks;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<string>
     */
    private static function eventLines(array $events): array
    {
        $lines = [];
        foreach ($events as $i => $event) {
            $type = self::text($event['event_type'] ?? '');
            if ($type === '') {
                continue;
            }
            $at = self::text($event['created_at'] ?? '');
            $lines[] = '| ' . ($i + 1) . ' | `' . $type . '` | ' . self::cell($at) . ' |';
        }

        return $lines;
    }

    /** @param array<string,mixed> $session */
    private static function title(array $session): string
    {
        $raw = self::text($session['query_text'] ?? '');
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($raw === '') {
            $raw = 'Run ' . (int)($session['id'] ?? 0);
        }
        if (mb_strlen($raw, 'UTF-8') > self::TITLE_CHARS) {
            $raw = rtrim(mb_substr($raw, 0, self::TITLE_CHARS, 'UTF-8')) . '…';
        }

        return $raw;
    }

    /** @param array<string,mixed> $session */
    private static function score(array $session): string
    {
        $score = $session['consensus_score'] ?? null;
        if ($score === null || $score === '' || !is_numeric($score)) {
            return '';
        }

        return '(' . number_format((float)$score, 3, '.', '') . ')';
    }

    private static function duration(int $ms): string
    {
        if ($ms <= 0) {
            return '';
        }
        if ($ms < 1000) {
            return $ms . ' ms';
        }

        return number_format($ms / 1000, 1, '.', '') . ' s';
    }

    /** @param array<string,mixed> $cache */
    private static function cacheLine(array $cache): string
    {
        $parts = [];
        foreach (['calls' => 'calls', 'hits' => 'cache reads', 'writes' => 'cache writes', 'saved' => 'saved'] as $key => $label) {
            if (isset($cache[$key]) && is_numeric($cache[$key])) {
                $parts[] = $label . ' ' . (string)$cache[$key];
            }
        }

        return implode(', ', $parts);
    }

    /** Table cells must not contain a raw pipe or a newline. */
    private static function cell(string $value): string
    {
        $value = str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], $value);

        return trim($value);
    }

    private static function stamp(string $createdAt): string
    {
        $digits = preg_replace('/[^0-9]/', '', $createdAt) ?? '';
        if (strlen($digits) >= 14) {
            // 20260925-162000: sortable and readable when a folder fills up.
            return substr($digits, 0, 8) . '-' . substr($digits, 8, 6);
        }

        return date('Ymd-His');
    }

    /** A deliberately small Markdown subset - enough for what render() emits. */
    private static function markdownToHtml(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $html  = [];
        $inPre = false;
        $inTable = false;

        foreach ($lines as $line) {
            $isFence = str_starts_with(trim($line), '```');
            if ($isFence) {
                if ($inPre) {
                    $html[] = '</code></pre>';
                    $inPre = false;
                } else {
                    $html[] = '<pre><code>';
                    $inPre = true;
                }
                continue;
            }
            if ($inPre) {
                $html[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
                continue;
            }

            $trimmed = trim($line);
            if (str_starts_with($trimmed, '|')) {
                $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                $isDivider = $cells !== [] && preg_match('/^-{2,}$/', $cells[0]) === 1;
                if (!$inTable) {
                    $html[] = '<table>';
                    $inTable = true;
                }
                if ($isDivider) {
                    continue;
                }
                $tag = 'td';
                $html[] = '<tr>' . implode('', array_map(
                    static fn(string $c): string => '<' . $tag . '>' . self::inline($c) . '</' . $tag . '>',
                    $cells
                )) . '</tr>';
                continue;
            }
            if ($inTable) {
                $html[] = '</table>';
                $inTable = false;
            }

            if ($trimmed === '') {
                continue;
            }
            if ($trimmed === '---') {
                $html[] = '<hr>';
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
                $level = strlen($m[1]);
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }
            $html[] = '<p>' . self::inline($trimmed) . '</p>';
        }
        if ($inTable) {
            $html[] = '</table>';
        }
        if ($inPre) {
            $html[] = '</code></pre>';
        }

        return implode("\n", $html) . "\n";
    }

    /** Inline code, emphasis and escaping - nothing else is emitted. */
    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text) ?? $text;

        return $text;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string)$value : '');
    }

    /** @return array<string,mixed> */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private static function asList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
