<?php
/**
 * ModelsActualizeReportTest: the Final answer an actualization run leaves behind.
 *
 * A models-actualization run used to write nothing into its council session, so
 * History showed it as `running` for ever, with no steps and no answer. The
 * answer now comes from the envelope the run already persists; this suite pins
 * what that answer must and must not say:
 *
 *   1. the numbers an operator asks for are all there - downloaded, already
 *      current, new, stale - and they come from the envelope, never from a
 *      guess;
 *   2. "which model replaced which" is rendered from the linked-role changes,
 *      and the report says so plainly when there were none;
 *   3. a run that ended in `problems` says that first and quotes the error;
 *   4. a section whose data is absent is omitted rather than printed empty, and
 *      an empty envelope still produces a readable document;
 *   5. the runner and the worker are wired so the session is actually closed.
 *
 * Runs without PHPUnit, a database or a network:
 *
 *   php tests/ModelsActualizeReportTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-27
 */
declare(strict_types=1);

use App\Models\ModelsActualizeReport;

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

check('the report class is loadable', class_exists(ModelsActualizeReport::class));
if (!class_exists(ModelsActualizeReport::class)) {
    echo "FAILED: {$passes} passed, {$failed} failed\n";
    exit(1);
}

// =====================================================================
echo "== a successful run reports what it synchronized ==\n";
// =====================================================================
// Trimmed from a real run (job 27 on the production host): the shape is what
// matters, including the warnings the pool-removal line is parsed out of.
$success = [
    'kind'        => 'models_actualize',
    'ok'          => true,
    'stage'       => 'success',
    'job_id'      => 27,
    'message'     => 'Imported 546 model(s) from bothub (29 new row(s)); actualized 3 consensus preset(s).',
    'warnings'    => [
        '9 imported row(s) are batch-only identifiers and stay inactive: they are served through the asynchronous Batch API.',
        'Removed 1 pool entry(ies) that the catalogue does not hold as an active model — the provider no longer serves them: bothub/qwen3.7-plus. Pick replacements in the sidebar.',
        '9 catalogue row(s) are no longer served by their provider and have been marked stale and inactive.',
    ],
    'assigned'    => [
        'default'     => ['bothub/gpt-6-luna-pro', 'bothub/gemini-3.1-pro-preview'],
        'physics'     => ['bothub/gpt-6-luna-pro', 'bothub/gpt-6-astra-pro'],
        'mathematics' => ['bothub/gpt-6-luna-pro', 'bothub/deepseek-r1-0528'],
    ],
    'selection'   => [['provider' => 'bothub', 'model' => 'gpt-6-astra-pro']],
    'linked'      => [
        [
            'role' => 'analyzer', 'label' => 'Analyzer',
            'provider' => 'bothub', 'model' => 'gpt-6-astra-pro',
            'previous_provider' => 'bothub', 'previous_model' => 'gpt-6-astra',
        ],
    ],
    'import'      => [
        'source' => 'bothub', 'fetched' => 546, 'present' => 542, 'added' => ['bothub|gpt-6-sol', 'bothub|claude-opus-5.5'],
        'batch' => ['bothub|gpt-6-sol:batch'], 'limit' => 0, 'unusable' => 0, 'truncated' => 0, 'duplicates' => 517,
    ],
    'analyzer'    => ['provider' => 'bothub', 'model' => 'gpt-6-astra-pro', 'source' => 'inherited', 'attempts' => 3, 'requests' => 3, 'corrections' => 0, 'note' => null],
    'reasons'     => ['default' => 'Five capable general-purpose models from distinct vendor families.'],
    'catalog'     => ['kept' => 571, 'families' => 23, 'stale' => 9, 'limit' => 1000, 'dropped' => 0, 'over_cap' => 0],
    'invariant'   => ['ok' => true, 'checked' => 30, 'violations' => []],
    'diff'        => [
        'live_count' => 546, 'catalog_count' => 571,
        'added'      => ['bothub/gpt-6-sol'],
        'stale'      => ['bothub/muse-glimmer-30b:batch', 'bothub/kat-coder-pro-v2'],
        'unavailable' => [],
    ],
    'started_at'  => '2026-09-25T13:48:56Z',
    'finished_at' => '2026-09-25T13:49:29Z',
    'duration_ms' => 33602,
];

$md = ModelsActualizeReport::markdown($success);

check('the title names the source', str_contains($md, '# Models actualization - bothub'), $md);
check('the verdict is success', str_contains($md, '**Result: success.**'));
check('downloaded count is reported', str_contains($md, '| Downloaded from the provider | 546 model(s) |'));
check('already-current count is reported', str_contains($md, '| Already in the catalogue | 542 row(s) |'));
check('new rows are counted', str_contains($md, '| New rows added | 2 |'));
check('duplicates are reported', str_contains($md, '| Duplicate rows collapsed | 517 |'));
check('what the provider serves now is reported', str_contains($md, '| Models the provider serves now | 546 |'));
check('the catalogue size is reported', str_contains($md, '| Rows in the catalogue after the run | 571 |'));
check('stale rows are counted', str_contains($md, '| Marked stale (no longer served) | 2 |'));
check('vendor families are reported', str_contains($md, '| Vendor families represented | 23 |'));
check('the presets that were actualized are named', str_contains($md, 'default, physics, mathematics'));
check('the new rows are listed', str_contains($md, '## New in the catalogue (2)') && str_contains($md, '- `bothub/gpt-6-sol`'));
check('batch-only ids are called out', str_contains($md, 'Batch-only identifiers among them (1)'));
check('the stale rows are listed', str_contains($md, '## No longer served (2)') && str_contains($md, '- `bothub/kat-coder-pro-v2`'));
check('a replaced model names both sides',
    str_contains($md, '**Analyzer**: `bothub/gpt-6-astra` -> `bothub/gpt-6-astra-pro`'), $md);
check('the role label is used, not the raw key', !str_contains($md, '**analyzer**:'));
check('the pool removal is quoted from the warning',
    str_contains($md, 'Removed from the active pool') && str_contains($md, '- `bothub/qwen3.7-plus`'));
check('each preset lists its models and its reason',
    str_contains($md, '### default (2)') && str_contains($md, '- `bothub/gpt-6-luna-pro`')
    && str_contains($md, '> Five capable general-purpose models from distinct vendor families.'));
check('the analyzer is described', str_contains($md, '## Analyzer') && str_contains($md, 'model `bothub/gpt-6-astra-pro`'));
check('the invariant is summarised', str_contains($md, '30 reference(s) checked - every model'));
check('warnings are listed', str_contains($md, '## Warnings (3)'));
check('the timing closes the document', str_contains($md, '## Timing') && str_contains($md, '(33.6 s)'));
check('no section is printed empty', !str_contains($md, '()') && !str_contains($md, '| | |'), $md);

// =====================================================================
echo "== no linked-role change says so in words ==\n";
// =====================================================================
$noChanges = $success;
$noChanges['linked'] = [];
$noChanges['warnings'] = [];
$mdNoChanges = ModelsActualizeReport::markdown($noChanges);
check('the section is still present', str_contains($mdNoChanges, '## Updated to another model'));
check('and it says nothing was switched', str_contains($mdNoChanges, 'None. Every linked role is still served'));
check('a pool-removal section with no removal is omitted', !str_contains($mdNoChanges, 'Removed from the active pool'));
check('a warnings section with no warnings is omitted', !str_contains($mdNoChanges, '## Warnings'));

// =====================================================================
echo "== a run with problems leads with the failure ==\n";
// =====================================================================
$problems = [
    'kind'        => 'models_actualize',
    'ok'          => false,
    'stage'       => 'problems',
    'failed_stage' => 'importing_models',
    'job_id'      => 30,
    'message'     => 'Actualization failed and nothing was written: the catalogue fetch returned HTTP 503.',
    'warnings'    => ['the provider catalogue could not be fetched'],
    'error'       => ['class' => 'ConnectorException', 'message' => 'the catalogue fetch returned HTTP 503'],
    'assigned'    => [],
    'diff'        => [],
    'import'      => [],
    'catalog'     => [],
    'invariant'   => [],
    'duration_ms' => 1200,
    'started_at'  => '2026-09-27T10:00:00Z',
    'finished_at' => '2026-09-27T10:00:01Z',
];
$mdProblems = ModelsActualizeReport::markdown($problems);
check('the verdict is problems', str_contains($mdProblems, '**Result: problems.**'));
check('the reason is quoted', str_contains($mdProblems, 'the catalogue fetch returned HTTP 503'));
check('the stage it stopped in is named', str_contains($mdProblems, 'stopped in stage `importing_models`'));
check('no synchronization table is invented', !str_contains($mdProblems, '## Synchronization'));
check('no preset section is invented', !str_contains($mdProblems, '## Presets'));

// =====================================================================
echo "== an envelope with nothing in it still renders ==\n";
// =====================================================================
$empty = ModelsActualizeReport::markdown([]);
check('the document has a title', str_contains($empty, '# Models actualization'));
check('it does not claim success', !str_contains($empty, '**Result: success.**'));
check('it still closes with its timing section', str_contains($empty, '## Timing'));
check('it invents no counts', !preg_match('/\| \w[^|]* \| \d+/', $empty));

$failure = ModelsActualizeReport::failure('worker died mid-run', 'RuntimeException');
check('the failure answer names the class case', str_contains($failure, '`RuntimeException`: worker died mid-run'));
check('the failure answer says nothing was written', str_contains($failure, 'Nothing was written'));
check('the failure answer is not reported as a success', !str_contains($failure, '**Result: success.**'));

// =====================================================================
echo "== the run is actually closed ==\n";
// =====================================================================
$runnerSrc = (string)@file_get_contents($root . '/src/Jobs/PipelineJobRunner.php');
$workerSrc = (string)@file_get_contents($root . '/bin/revoap-worker.php');
check('the runner writes the session', str_contains($runnerSrc, 'private function finishActualizeSession('));
check('the completion writes the Final answer', str_contains($runnerSrc, 'ModelsActualizeReport::markdown($envelope)'));
check('a failed actualize closes its session too', str_contains($runnerSrc, 'ModelsActualizeReport::failure('));
check('the session write is best-effort', str_contains($runnerSrc, "worker.models_actualize.session_not_closed"));
check('the verdict marks the run as having no consensus',
    str_contains($runnerSrc, 'consensusStatus: ModelsActualizeReport::CONSENSUS_STATUS'));
// The value set is DERIVED from db/schema.sql instead of hardcoded here:
// this pin once listed ['', 'pending', …] — a set that both predates
// migration 022 ('single') and contains '' which a strict-mode server has
// NEVER accepted (T6 finding #7). Deriving it keeps the check honest when
// the enum next grows (025 added 'none').
$schemaForEnum = (string)@file_get_contents($root . '/db/schema.sql');
$enumValues = [];
if (preg_match("/consensus_status\s+ENUM\(([^)]+)\)/i", $schemaForEnum, $em) === 1) {
    preg_match_all("/'([^']+)'/", $em[1], $vm);
    $enumValues = $vm[1];
}
check('and that verdict is one the schema actually accepts',
    $enumValues !== [] && in_array(ModelsActualizeReport::CONSENSUS_STATUS, $enumValues, true)
        && ModelsActualizeReport::CONSENSUS_STATUS !== '',
    var_export(ModelsActualizeReport::CONSENSUS_STATUS, true) . ' vs ' . json_encode($enumValues));
check('the worker passes the session repository',
    (bool)preg_match('/new \\\\App\\\\Jobs\\\\PipelineJobRunner\([^;]*\$sessions\);/s', $workerSrc));
check('the worker builds that repository', str_contains($workerSrc, 'new \\App\\Storage\\SessionRepository('));
// The type hint alone was not enough: without the import PHP resolved
// `?SessionRepository` inside App\Jobs and every worker refused to start.
check('the runner imports the storage repository it asks for',
    str_contains($runnerSrc, 'use App\\Storage\\SessionRepository;'));
$ctor = new ReflectionMethod(\App\Jobs\PipelineJobRunner::class, '__construct');
$sessionParam = $ctor->getParameters()[6] ?? null;
check('the seventh parameter is typed as the storage repository',
    $sessionParam !== null && (string)$sessionParam->getType() === '?' . \App\Storage\SessionRepository::class,
    $sessionParam !== null ? (string)$sessionParam->getType() : 'no parameter 7');

// =====================================================================
echo "== the heal command exists for runs that predate the fix ==\n";
// =====================================================================
$heal = $root . '/bin/models-actualize-heal.php';
check('bin/models-actualize-heal.php is present', is_file($heal));
if (is_file($heal)) {
    $healSrc = (string)file_get_contents($heal);
    check('it defaults to a dry run', str_contains($healSrc, '--yes'));
    check('it reads the stored job result', str_contains($healSrc, 'result_json'));
    check('it uses the same report', str_contains($healSrc, 'ModelsActualizeReport::markdown('));
    check('it only touches runs still marked running', str_contains($healSrc, "status = 'running'"));
}

echo "----------------------------------------\n";
echo $failed === 0
    ? "ALL OK: {$passes} passed, 0 failed\n"
    : "FAILED: {$passes} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
