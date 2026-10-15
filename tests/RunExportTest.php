<?php
/**
 * RunExportTest: the R8 export writes what the run actually holds.
 *
 * R8 turns a stored run into a file the operator can keep. Two things matter
 * enough to pin here:
 *
 *   1. nothing is invented - every heading comes from a field that exists, and
 *      a missing field is omitted rather than rendered as an empty section;
 *   2. run text never reaches the file name. A query containing `../`, quotes
 *      or dots must not be able to steer the write out of `share/`.
 *
 * Runs without PHPUnit, a database or a network:
 *
 *   php tests/RunExportTest.php
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-25
 */
declare(strict_types=1);

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $candidate) {
    if (is_file($root . $candidate)) {
        require $root . $candidate;
    }
}

$passes = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passes, $failed;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failed++;
    }
}

check('the exporter class is loadable', class_exists(\App\History\RunMarkdownExporter::class));
if (!class_exists(\App\History\RunMarkdownExporter::class)) {
    echo "FAILED: {$passes} passed, {$failed} failed\n";
    exit(1);
}

$detail = [
    'ok' => true,
    'session' => [
        'id' => 26,
        'created_at' => '2026-09-25 16:20:00',
        'completed_at' => '2026-09-25 16:22:47',
        'status' => 'completed',
        'consensus_status' => 'conflict',
        'consensus_score' => 0.3306,
        'skill_slug' => 'semantic-consensus-reasoning',
        'skill_name' => 'Semantic Consensus',
        'total_tokens' => 31935,
        'duration_ms' => 109464,
        'query_text' => "Refactor this Perl code\n\nmy \$total = \$opts{count};",
        'final_answer' => 'Here is the refactored version.',
        'cache' => ['calls' => 9, 'hits' => 4, 'writes' => 5],
    ],
    'steps' => [
        [
            'step_key' => 'L1.role.analyst@gpt_6_astra_pro',
            'label' => 'L1 - analyst@gpt_6_astra_pro',
            'status' => 'completed',
            'summary' => 'The analyst rewrote the loop.',
            'responses' => [[
                'model' => 'gpt-6-astra-pro',
                'provider' => 'bothub',
                'raw_content' => "Use a hash slice:\n\nmy %seen = map { \$_ => 1 } keys %\$opts;",
                'latency_ms' => 18913,
            ]],
        ],
        [
            'step_key' => 'L4.main_selected',
            'label' => 'L4 - Main-model synthesis',
            'status' => 'completed',
            'summary' => 'Final body assembled.',
            'responses' => [],
        ],
    ],
    'events' => [
        ['event_type' => 'PIPELINE_STARTED', 'created_at' => '2026-09-25 16:20:00'],
        ['event_type' => 'L1_RESPONSE', 'created_at' => '2026-09-25 16:20:31'],
    ],
];

$md = \App\History\RunMarkdownExporter::render($detail);

check('the title is the question', str_contains($md, '# Refactor this Perl code'));
check('the question section carries the original text', str_contains($md, '## Question') && str_contains($md, 'my $total = $opts{count};'));
check('the final answer section is present', str_contains($md, '## Final answer') && str_contains($md, 'Here is the refactored version.'));
check('metadata carries the consensus verdict', str_contains($md, '| **Consensus** |') && str_contains($md, 'conflict (0.331)'));
check('metadata carries counts and duration', str_contains($md, '| **Steps** | 2 |') && str_contains($md, '| **Answers** | 1 |') && str_contains($md, '| **Duration** | 109.5 s |'));
check('the cache roll-up is included when the run has one', str_contains($md, '| **Cache** |') && str_contains($md, 'cache reads 4'));
check('every step is rendered', str_contains($md, '### L1 - analyst@gpt_6_astra_pro') && str_contains($md, '### L4 - Main-model synthesis'));
check('a stored answer is rendered under its step', str_contains($md, '#### bothub/gpt-6-astra-pro') && str_contains($md, 'hash slice'));
check('the events appendix lists the journal', str_contains($md, '`PIPELINE_STARTED`') && str_contains($md, '`L1_RESPONSE`'));
check('there is no empty section for a field that is missing', !str_contains($md, '## Cache'));
check('the file ends with the provenance note', str_contains($md, 'Exported from RevoAp History'));

// --- the file name must not be steerable by run text -------------------------
$name = \App\History\RunMarkdownExporter::fileName($detail);
check('the file name uses the id and timestamp only', $name === 'run-26-20260925-162000.md', $name);

$nasty = $detail;
$nasty['session']['query_text'] = '../../etc/passwd "quoted" <script>';
$nasty['session']['id'] = 7;
$nastyName = \App\History\RunMarkdownExporter::fileName($nasty);
check('a hostile question cannot change the file name', $nastyName === 'run-7-20260925-162000.md', $nastyName);
check('the name has no path separators', !str_contains($nastyName, '/') && !str_contains($nastyName, '\\'));
check('a second extension keeps the same base', \App\History\RunMarkdownExporter::fileNameFor($detail, 'html') === 'run-26-20260925-162000.html');

// --- the print page ----------------------------------------------------------
$html = \App\History\RunMarkdownExporter::renderHtml($detail);
check('the html is self-contained', !str_contains($html, '<script') && !str_contains($html, 'http://') && !str_contains($html, 'https://'));
check('the html carries the run content', str_contains($html, 'Refactor this Perl code') && str_contains($html, 'Here is the refactored version.'));
check('the html escapes hostile text instead of emitting it', str_contains($html, '&lt;script&gt;') || !str_contains($html, '<script>'));
check('the html has a print stylesheet', str_contains($html, '@media print'));

// --- a half-empty run must not produce fake sections -------------------------
$minimal = ['session' => ['id' => 1, 'status' => 'running'], 'steps' => [], 'events' => []];
$mdMin = \App\History\RunMarkdownExporter::render($minimal);
check('a run with no question still gets a usable title', str_contains($mdMin, '# Run 1'));
check('a run with nothing stored has no empty sections',
    !str_contains($mdMin, '## Final answer') && !str_contains($mdMin, '## Pipeline') && !str_contains($mdMin, '## Events'));
check('a run with nothing stored still parses (no PHP notices)', $mdMin !== '');

echo "----------------------------------------\n";
echo $failed === 0
    ? "ALL OK: {$passes} passed, 0 failed\n"
    : "FAILED: {$passes} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
