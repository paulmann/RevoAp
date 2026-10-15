<?php
declare(strict_types=1);
/**
 * PaperRankerTest: which papers come out of the candidate window, and why.
 *
 * arXiv returns a window in its own order; PaperRanker re-orders it by how
 * well each paper covers the topic's terms. The checks pin the weights (title
 * above abstract), the word matching (whole words, plural-aware), version
 * de-duplication, the three-step ordering, and that no input row mutates - a
 * silent change to any of those quietly reorders every search.
 *
 *   php tests/PaperRankerTest.php
 */
use App\Research\PaperRanker;

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

/** A paper row shaped like the parser's output. */
function paper(array $over = []): array {
    return array_merge([
        'id'        => 'http://arxiv.org/abs/2509.00001v1',
        'url'       => 'https://arxiv.org/abs/2509.00001',
        'title'     => '',
        'summary'   => '',
        'published' => '2026-01-01',
        'authors'   => ['A'],
    ], $over);
}
function titles(array $papers): array {
    return array_map(static fn(array $p): string => (string)$p['title'], $papers);
}

check('the ranker is loadable', class_exists(PaperRanker::class));
check('a version suffix is stripped', PaperRanker::baseId('http://arxiv.org/abs/2509.12345v2') === 'http://arxiv.org/abs/2509.12345');
check('a plain id is left alone', PaperRanker::baseId('http://arxiv.org/abs/cs/0701001') === 'http://arxiv.org/abs/cs/0701001');
check('an empty id is left alone', PaperRanker::baseId('') === '');

// ---- the weights -------------------------------------------------------------
$ranked = PaperRanker::rank([
    paper(['id' => 'a', 'title' => 'Consensus among models']),
    paper(['id' => 'b', 'title' => 'Something else', 'summary' => 'We study consensus deeply']),
    paper(['id' => 'c', 'title' => 'Nothing relevant here']),
], ['consensus'], 5);
check('the title match leads', titles($ranked) === ['Consensus among models', 'Something else', 'Nothing relevant here'],
    implode(' | ', titles($ranked)));
check('a title hit scores three', ($ranked[0]['score'] ?? -1) === 3);
check('an abstract hit scores one', ($ranked[1]['score'] ?? -1) === 1);
check('no hit scores zero', ($ranked[2]['score'] ?? -1) === 0);
check('and the matched terms are named', ($ranked[0]['matched'] ?? []) === ['consensus'] && ($ranked[2]['matched'] ?? ['x']) === []);
check('title and abstract add up', (function (): bool {
    $one = PaperRanker::rank([paper(['title' => 'Consensus now', 'summary' => 'we find consensus again'])], ['consensus'], 5);
    return ($one[0]['score'] ?? 0) === 4;
})());
check('the plural matches its singular', (function (): bool {
    $one = PaperRanker::rank([paper(['title' => 'Large Models in practice'])], ['model'], 5);
    return ($one[0]['score'] ?? 0) === 3;
})());
check('a word does not match inside another word', (function (): bool {
    $one = PaperRanker::rank([paper(['title' => 'A study of reagents'])], ['agent'], 5);
    return ($one[0]['score'] ?? -1) === 0;
})());
check('a phrase matches as a phrase', (function (): bool {
    $one = PaperRanker::rank([paper(['summary' => 'We look at large   language models.'])], ['large language models'], 5);
    return ($one[0]['score'] ?? 0) === 1;
})());
check('matching ignores case', (function (): bool {
    $one = PaperRanker::rank([paper(['title' => 'CONSENSUS'])], ['consensus'], 5);
    return ($one[0]['score'] ?? 0) === 3;
})());

// ---- the order ---------------------------------------------------------------
$order = PaperRanker::rank([
    paper(['id' => 'old', 'title' => 'Consensus old', 'published' => '2024-01-01']),
    paper(['id' => 'new', 'title' => 'Consensus new', 'published' => '2026-05-01']),
], ['consensus'], 5);
check('equal scores prefer the newer paper', titles($order) === ['Consensus new', 'Consensus old'], implode(' | ', titles($order)));
$tie = PaperRanker::rank([
    paper(['id' => 'b', 'title' => 'Same', 'published' => '2026-05-01']),
    paper(['id' => 'a', 'title' => 'Same', 'published' => '2026-05-01']),
], ['x'], 5);
check('full ties fall to the id', array_map(static fn(array $p): string => (string)$p['id'], $tie) === ['a', 'b']);
$dated = PaperRanker::rank([
    paper(['id' => 'a', 'title' => 'Mix', 'published' => '']),
    paper(['id' => 'b', 'title' => 'Mix', 'published' => '2026-01-01']),
], [], 5);
check('without terms the dated paper leads', array_map(static fn(array $p): string => (string)$p['id'], $dated) === ['b', 'a']);
check('and every score is zero with no matched terms',
    ($dated[0]['score'] ?? -1) === 0 && ($dated[0]['matched'] ?? ['x']) === []);

// ---- the window mechanics ----------------------------------------------------
$versions = PaperRanker::rank([
    paper(['id' => 'http://arxiv.org/abs/2501.00001v2', 'title' => 'Kept one']),
    paper(['id' => 'http://arxiv.org/abs/2501.00001v1', 'title' => 'Dropped one']),
], ['kept'], 5);
check('two versions collapse to one paper', count($versions) === 1, (string)count($versions));
check('and the first occurrence is kept', ($versions[0]['title'] ?? '') === 'Kept one');
$limited = PaperRanker::rank([
    paper(['id' => 'a', 'title' => 'One consensus']),
    paper(['id' => 'b', 'title' => 'Two consensus']),
    paper(['id' => 'c', 'title' => 'Three consensus']),
], ['consensus'], 2);
check('the limit slices the end', count($limited) === 2);
check('a limit of zero is empty', PaperRanker::rank([paper()], ['x'], 0) === []);
$before = [paper(['id' => 'a', 'title' => 'Consensus', 'extra' => 'kept'])];
$snapshot = json_encode($before);
$after = PaperRanker::rank($before, ['consensus'], 5);
check('the input rows are not mutated', json_encode($before) === $snapshot);
check('extra keys survive', ($after[0]['extra'] ?? '') === 'kept');
check('and the original still has no score', !array_key_exists('score', $before[0]));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
