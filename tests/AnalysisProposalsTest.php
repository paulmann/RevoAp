<?php
declare(strict_types=1);
/**
 * AnalysisProposalsTest: pulling the change list out of an analysis, and
 * building the plan the operator keeps.
 *
 * The analysis is prose; the changes at its end are the point. These checks
 * pin how they are found (any heading depth, bullets or numbers, continuation
 * lines, a citation fallback), what is refused (a section cut at the next
 * heading, twelve items at most), and the exact shape of the plan markdown.
 *
 *   php tests/AnalysisProposalsTest.php
 */
use App\Research\AnalysisProposals;

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $candidate) {
    if (is_file($root . $candidate)) { require $root . $candidate; }
}
$passes = 0;
$failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passes, $failed;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failed++; }
}

const SAMPLE = "# Brief\n\n## 1. What the papers agree on\n\n- Consensus helps [1]\n- But is not correctness [2]\n\n## 2. Concrete changes\n\n- Adopt a panel of three models [1]\n- Track disagreement rates [2]\n  and alert when they spike\n\n## 3. What is still unknown\n\n- The cost [3]\n";

check('the class is loadable', class_exists(AnalysisProposals::class));

// ---- finding the section -----------------------------------------------------
$items = AnalysisProposals::extract(SAMPLE);
check('the changes section is found', count($items) === 2, (string)count($items));
check('its first item is kept whole', ($items[0] ?? '') === 'Adopt a panel of three models [1]', (string)($items[0] ?? ''));
check('a continuation line is glued on', ($items[1] ?? '') === 'Track disagreement rates [2] and alert when they spike', (string)($items[1] ?? ''));
check('bullets before the section are not items', !in_array('- Consensus helps [1]', $items, true));
check('nothing after the next heading leaks in', !in_array('- The cost [3]', $items, true));

$ru = AnalysisProposals::extract("## Предлагаемые изменения\n\n1. Ввести ревью кода [1]\n2) Считать метрики [2]\n");
check('a russian heading works', count($ru) === 2, (string)count($ru));
check('numbered items are items', ($ru[0] ?? '') === 'Ввести ревью кода [1]', (string)($ru[0] ?? ''));
check('and so are the )-numbered ones', ($ru[1] ?? '') === 'Считать метрики [2]', (string)($ru[1] ?? ''));

$bold = AnalysisProposals::extract("**Changes to consider**\n\n- One fix [1]\n- Two fix [2]\n");
check('a bold heading is a heading', count($bold) === 2, (string)count($bold));

check('a look-alike heading is not a changes heading',
    AnalysisProposals::extract("## Interactions\n\n- something unrelated\n") === []);

// ---- the fallback, the cap, the cleanup --------------------------------------
$fallback = AnalysisProposals::extract("# Notes\n\n- fix the parser [3]\n- an unrelated line\n");
check('without a section, cited bullets are the fallback', $fallback === ['fix the parser [3]'], json_encode($fallback));

$many = "## Changes\n\n" . implode("\n", array_map(static fn(int $i): string => '- item ' . $i . ' [1]', range(1, 15)));
check('the list is capped at twelve', count(AnalysisProposals::extract($many)) === 12, (string)count(AnalysisProposals::extract($many)));

$dupes = AnalysisProposals::extract("## Changes\n\n- same [1]\n- same [1]\n- other [2]\n");
check('duplicates collapse', $dupes === ['same [1]', 'other [2]'], json_encode($dupes));
check('an empty analysis gives nothing', AnalysisProposals::extract('') === []);

// ---- the plan ----------------------------------------------------------------
$papers = [
    ['title' => 'Multi-Agent Consensus', 'url' => 'https://arxiv.org/abs/2310.20151'],
    ['title' => 'Poetry Evaluation', 'url' => 'https://arxiv.org/abs/2502.19064'],
];
$plan = AnalysisProposals::planMarkdown('consensus', 'bothub', 'gpt-6-luna-pro', $papers, ['Adopt a panel [1]', 'Track the rates [2]'], '2026-09-29');
$expected = "# Change plan: consensus\n\n"
    . "Created: 2026-09-29 - analysed by bothub / gpt-6-luna-pro over 2 arXiv paper(s).\n\n"
    . "## Papers\n\n"
    . "1. Multi-Agent Consensus - https://arxiv.org/abs/2310.20151\n"
    . "2. Poetry Evaluation - https://arxiv.org/abs/2502.19064\n\n"
    . "## Changes to consider\n\n"
    . "- [ ] Adopt a panel [1]\n"
    . "- [ ] Track the rates [2]\n";
check('the plan is exactly the expected text', $plan === $expected, $plan);

$empty = AnalysisProposals::planMarkdown('t', 'p', 'm', [], [], '2026-09-29');
check('an empty plan names both absences',
    str_contains($empty, '_(no papers were selected)_')
    && str_contains($empty, '_(no separate list of changes was detected in the analysis)_'));
check('the plan ends with exactly one newline', substr($plan, -2) !== "\n\n" && str_ends_with($plan, "\n"));
$today = AnalysisProposals::planMarkdown('t', 'p', 'm', []);
check('without a date the plan carries today', str_contains($today, date('Y-m-d')));
check('a missing url leaves just the title',
    str_contains(AnalysisProposals::planMarkdown('t', 'p', 'm', [['title' => 'Only']], [], '2026-01-01'), "1. Only\n"));

$checked = AnalysisProposals::planMarkdown('t', 'p', 'm', [['title' => 'X', 'url' => 'u']], ['one [1]', 'two [2]'], '2026-01-02', [true, false]);
check('checked items carry the x', str_contains($checked, "- [x] one [1]") && str_contains($checked, "- [ ] two [2]"), $checked);
$defaulted = AnalysisProposals::planMarkdown('t', 'p', 'm', [], ['one']);
check('without state the boxes stay empty', str_contains($defaulted, '- [ ] one'));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
