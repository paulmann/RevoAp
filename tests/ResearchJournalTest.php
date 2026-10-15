<?php
declare(strict_types=1);
/**
 * ResearchJournalTest: the searcher's memory, against a real (SQLite) database.
 *
 * The journal is what makes "only new" and the history list honest, so the
 * checks run it end to end rather than picking at internals: a run round-trips
 * with its papers, proposals and plan state; the seen memory keeps the FIRST
 * sighting and survives the deletion of the run it was born from; statuses
 * and checkboxes persist; and an unavailable journal degrades to safe answers
 * instead of throwing.
 *
 *   php tests/ResearchJournalTest.php
 */
use App\Research\ResearchJournal;

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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE research_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'analyse',
    topic TEXT NOT NULL,
    query TEXT NOT NULL DEFAULT '',
    candidates INTEGER NOT NULL DEFAULT 0,
    papers_json TEXT NOT NULL,
    candidates_json TEXT NULL,
    provider TEXT NOT NULL DEFAULT '',
    model TEXT NOT NULL DEFAULT '',
    tokens INTEGER NOT NULL DEFAULT 0,
    analysis TEXT NULL,
    proposals_json TEXT NULL,
    plan_md TEXT NULL,
    plan_status TEXT NOT NULL DEFAULT 'draft',
    plan_checked_json TEXT NULL
)");
$pdo->exec("CREATE TABLE research_seen (
    base_id TEXT PRIMARY KEY,
    run_id INTEGER NULL,
    first_seen_at TEXT NOT NULL
)");

$journal = new ResearchJournal($pdo);
$empty = new PDO('sqlite::memory:');
$empty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$missing = new ResearchJournal($empty);
$nullJournal = new ResearchJournal(null);

function paper(string $id, string $title): array {
    return [
        'id' => $id,
        'url' => str_replace('http://', 'https://', $id),
        'title' => $title,
        'summary' => 'Abstract of ' . $title,
        'published' => '2026-01-15',
        'authors' => ['A. Author'],
    ];
}

// ---- the key -----------------------------------------------------------------
check('the journal is loadable', class_exists(ResearchJournal::class));
check('a key folds the scheme and the version',
    ResearchJournal::paperKey('http://arxiv.org/abs/2509.12345v2') === 'arxiv.org/abs/2509.12345',
    ResearchJournal::paperKey('http://arxiv.org/abs/2509.12345v2'));
check('and the case', ResearchJournal::paperKey('HTTPS://ARXIV.ORG/abs/2310.20151') === 'arxiv.org/abs/2310.20151');
check('a bare id stays itself', ResearchJournal::paperKey('2509.12345') === '2509.12345');
check('an empty id gives an empty key', ResearchJournal::paperKey('   ') === '');

// ---- availability ------------------------------------------------------------
check('a journal without a database is unavailable', $nullJournal->available() === false);
check('a journal without the tables is unavailable', $missing->available() === false);
check('a journal with both tables is available', $journal->available() === true);
check('recording without a database returns null', $nullJournal->record(['topic' => 'x']) === null);
check('recent without a database is empty', $nullJournal->recent() === []);
check('recording against missing tables returns null', $missing->record(['topic' => 'x']) === null);

// ---- a run round-trips -------------------------------------------------------
$first = $journal->record([
    'kind' => 'analyse',
    'topic' => 'consensus among language models',
    'query' => 'all:consensus',
    'candidates' => 15,
    'papers' => [paper('http://arxiv.org/abs/2310.20151v2', 'Multi-Agent Consensus'), paper('http://arxiv.org/abs/2502.19064v1', 'Poetry Evaluation')],
    'window' => [['id' => 'w1'], ['id' => 'w2'], ['id' => 'w3']],
    'provider' => 'bothub',
    'model' => 'gpt-6-luna-pro',
    'tokens' => 10393,
    'analysis' => "## Concrete changes\n\n- Adopt a panel [1]\n- Track the rates [2]\n",
    'proposals' => ['Adopt a panel [1]', 'Track the rates [2]'],
    'plan' => '# Change plan: consensus',
]);
check('a run records and returns its id', is_int($first) && $first > 0, var_export($first, true));
$second = $journal->record([
    'kind' => 'search',
    'topic' => 'topic memory',
    'papers' => [paper('http://arxiv.org/abs/2502.19064v1', 'Poetry Evaluation'), paper('http://arxiv.org/abs/2601.00001v1', 'A New One')],
]);
check('a second run records too', is_int($second) && $second > $first);

$recent = $journal->recent();
check('recent lists the newest first', count($recent) === 2 && $recent[0]['id'] === $second && $recent[1]['id'] === $first);
check('summaries carry decoded counts', $recent[1]['papers'] === 2 && $recent[1]['proposals'] === 2);
check('and the metadata', $recent[1]['kind'] === 'analyse' && $recent[1]['model'] === 'gpt-6-luna-pro' && $recent[1]['tokens'] === 10393);
check('a search run keeps its kind', $recent[0]['kind'] === 'search');

$found = $journal->find($first);
check('find returns the full run', is_array($found) && ($found['id'] ?? 0) === $first);
check('with its papers decoded', count($found['papers'] ?? []) === 2 && ($found['papers'][0]['title'] ?? '') === 'Multi-Agent Consensus');
check('its proposals decoded', ($found['proposals'] ?? []) === ['Adopt a panel [1]', 'Track the rates [2]']);
check('its analysis intact', str_contains((string)($found['analysis'] ?? ''), 'Adopt a panel'));
check('its plan intact', ($found['plan'] ?? '') === '# Change plan: consensus');
check('and a fresh checkbox list, all unchecked', ($found['plan_checked'] ?? []) === [false, false] && $found['plan_status'] === 'draft');
check('an unknown id finds nothing', $journal->find(9999) === null);
check('the ranked window round-trips', ($found['candidates'] ?? []) === [['id' => 'w1'], ['id' => 'w2'], ['id' => 'w3']]);
check('summaries count what the run found', $journal->recent()[1]['found'] === 3);

// ---- the seen memory ---------------------------------------------------------
$seen = $journal->seenAt(['http://arxiv.org/abs/2310.20151', 'http://arxiv.org/abs/2601.00001']);
check('a shown paper is remembered with a date', $seen['arxiv.org/abs/2310.20151'] === date('Y-m-d'), json_encode($seen));
check('the version-folded key matches', isset($seen['arxiv.org/abs/2601.00001']));
check('an unseen paper is absent', !isset($seen['arxiv.org/abs/9999.99999']));
check('empty input gives an empty answer', $journal->seenAt([]) === []);

$before = $journal->seenAt(['http://arxiv.org/abs/2502.19064v1']);
$third = $journal->record(['topic' => 'again', 'papers' => [paper('http://arxiv.org/abs/2502.19064v3', 'Poetry Evaluation')]]);
check('a run without a window has none', ($journal->find((int)$third)['candidates'] ?? ['x']) === []);
$after = $journal->seenAt(['http://arxiv.org/abs/2502.19064v1']);
check('re-seeing a paper keeps the FIRST date', $after === $before, json_encode([$before, $after]));

// ---- deletion and clearing ---------------------------------------------------
check('deleting a run works', $journal->delete($second) === true);
check('the deleted run is gone', $journal->find($second) === null);
check('and the seen memory it created survives', isset($journal->seenAt(['http://arxiv.org/abs/2601.00001'])['arxiv.org/abs/2601.00001']));
check('deleting a missing run is false', $journal->delete(9999) === false);

// ---- plan state --------------------------------------------------------------
check('a valid status persists', $journal->setPlanStatus($first, 'active') === true);
check('which find reflects', ($journal->find($first)['plan_status'] ?? '') === 'active');
check('an invalid status is refused', $journal->setPlanStatus($first, 'someday') === false);
check('and leaves the value alone', ($journal->find($first)['plan_status'] ?? '') === 'active');
check('a status for a missing run is false', $journal->setPlanStatus(9999, 'done') === false);
check('checkboxes persist', $journal->setPlanChecked($first, [true, false]) === true);
check('and find reflects them', ($journal->find($first)['plan_checked'] ?? []) === [true, false]);
$journal->setPlanChecked($first, ['1', '', 0, 'x']);
check('junk is sanitized to booleans', ($journal->find($first)['plan_checked'] ?? []) === [true, false, false, true]);
$journal->setPlanChecked($first, array_fill(0, 100, true));
check('the checkbox list is capped', count($journal->find($first)['plan_checked'] ?? []) === 64);
check('setters refuse the unavailable journal', $missing->setPlanStatus(1, 'done') === false && $missing->setPlanChecked(1, [true]) === false);
check('delete and clear refuse the unavailable journal', $missing->delete(1) === false && $missing->clear() === 0);

$deleted = $journal->clear();
check('clear removes every run', $deleted === 2 && $journal->recent() === []);
check('and forgets the seen memory too', $journal->seenAt(['http://arxiv.org/abs/2310.20151']) === []);

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
