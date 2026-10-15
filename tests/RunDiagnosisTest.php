<?php
/**
 * RunDiagnosisTest - one verdict and one next step for every way a run can end (R2).
 *
 * The rules this suite pins are the ones that make the screen worth having:
 *
 *   - a system cause outranks a run cause: stale worker code or a schema that
 *     is behind explains a failure better than any per-model reason, and that is
 *     the order an operator should be told about them in;
 *   - every failure class ends with at least one action, so the screen never
 *     tells someone that something is broken and stops there;
 *   - a blocked run that names its timeouts points at the timeout budget rather
 *     than at the provider key;
 *   - a run that merely dropped a model mid-way is a warning, not a failure.
 *
 * Run: php tests/RunDiagnosisTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-23
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Diagnostics\RunDiagnosis;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failures++;
    }
}

$actionIds = static function (array $verdict): array {
    $ids = [];
    foreach ($verdict['actions'] ?? [] as $action) {
        $ids[] = (string)($action['id'] ?? '');
    }

    return $ids;
};

// =====================================================================
echo "\n== (a) a run that did its job needs no explanation ==\n";
// =====================================================================
$ok = RunDiagnosis::fromFacts(['status' => 'completed']);
check('a completed run is reported as fine', ($ok['severity'] ?? '') === 'ok' && ($ok['cause'] ?? '') === RunDiagnosis::OK);
check('and it has no actions to offer', ($ok['actions'] ?? ['x']) === []);
check('an empty status is treated as fine rather than as a mystery',
    (RunDiagnosis::fromFacts([])['severity'] ?? '') === 'ok');

// =====================================================================
echo "\n== (b) system causes outrank run causes ==\n";
// =====================================================================
$both = RunDiagnosis::fromFacts([
    'status'        => 'failed',
    'status_reason' => 'l4_synthesis_failed',
    'code_state'    => 'stale',
    'code_newest_file' => 'src/Models/ModelMetrics.php',
]);
check('stale worker code wins over a synthesis failure', ($both['cause'] ?? '') === 'stale_worker_code', json_encode($both));
check('and it says which file is newer than the process',
    str_contains((string)($both['detail'] ?? ''), 'ModelMetrics.php'));
check('the restart it recommends asks for confirmation first',
    in_array('restart_worker', $actionIds($both), true)
    && ($both['actions'][0]['needs_confirmation'] ?? false) === true,
    json_encode($both['actions']));
check('the restart is a button on the application endpoint, not a shell command from PHP',
    ($both['actions'][0]['kind'] ?? '') === 'endpoint'
    && ($both['actions'][0]['target'] ?? '') === '/api/worker/restart',
    json_encode($both['actions'][0] ?? null));

$schema = RunDiagnosis::fromFacts([
    'status'          => 'failed',
    'status_reason'   => 'l4_synthesis_failed',
    'schema_live'     => '1.8.44',
    'schema_expected' => '1.9.0',
]);
check('a schema that is behind also wins over the run reason', ($schema['cause'] ?? '') === 'schema_out_of_date', json_encode($schema));
check('and applying migrations is the offered step',
    in_array('apply_migrations', $actionIds($schema), true), json_encode($schema['actions']));
check('the versions are named in the headline',
    str_contains((string)($schema['headline'] ?? ''), '1.8.44') && str_contains((string)($schema['headline'] ?? ''), '1.9.0'));

// =====================================================================
echo "\n== (c) run causes, each with a way forward ==\n";
// =====================================================================
$pool = RunDiagnosis::fromFacts(['status' => 'failed', 'status_reason' => 'pool_no_usable_models', 'status_detail' => 'bothub/gpt-4o is not served']);
check('an empty resolved pool points at the catalogue',
    ($pool['cause'] ?? '') === 'pool_no_usable_models' && in_array('refresh_catalogue', $actionIds($pool), true),
    json_encode($pool));
check('and it repeats what the engine said', str_contains((string)($pool['detail'] ?? ''), 'bothub/gpt-4o'));

$synth = RunDiagnosis::fromFacts(['status' => 'failed', 'status_reason' => 'l4_synthesis_failed']);
check('a failed synthesis offers the re-run that already exists',
    in_array('rerun_synthesis', $actionIds($synth), true)
    && str_contains((string)($synth['actions'][0]['target'] ?? ''), '/complete'), json_encode($synth['actions']));

$l1 = RunDiagnosis::fromFacts([
    'status'        => 'failed',
    'status_reason' => 'l1_no_results',
    'missing'       => [
        ['model' => 'claude-sonnet-4.6', 'reason' => 'cURL error: Operation timed out after 120000 ms'],
        ['model' => 'deepseek-r1', 'reason' => 'cURL error: Operation timed out after 120000 ms'],
    ],
]);
check('a blocked fan-out names the models and the reasons',
    str_contains((string)($l1['detail'] ?? ''), 'claude-sonnet-4.6') && str_contains((string)($l1['detail'] ?? ''), 'timed out'),
    (string)($l1['detail'] ?? ''));
check('and when they timed out it points at the timeout budget, not the key',
    str_contains((string)($l1['detail'] ?? ''), 'timeout_chat'), (string)($l1['detail'] ?? ''));

$key = RunDiagnosis::fromFacts(['status' => 'failed', 'status_reason' => 'connector_unavailable']);
check('a refused connector sends the operator to the connectors screen',
    in_array('open_connector_settings', $actionIds($key), true), json_encode($key['actions']));

$unknown = RunDiagnosis::fromFacts(['status' => 'failed', 'status_reason' => 'something_new', 'status_detail' => 'raw engine text']);
check('an unfamiliar cause still names itself and offers the raw record',
    ($unknown['cause'] ?? '') === 'something_new'
    && in_array('open_raw_event', $actionIds($unknown), true)
    && str_contains((string)($unknown['detail'] ?? ''), 'raw engine text'), json_encode($unknown));

// =====================================================================
echo "\n== (d) a warning is not a failure ==\n";
// =====================================================================
$dropped = RunDiagnosis::fromFacts([
    'status'  => 'completed',
    'missing' => [['model' => 'deepseek-r1', 'reason' => 'timed out']],
    'breaker' => [['model' => 'deepseek-r1', 'reason' => '2 consecutive failures in this run']],
]);
check('a completed run that dropped a model is a warning',
    ($dropped['severity'] ?? '') === 'warn' && ($dropped['cause'] ?? '') === 'breaker_dropped_models', json_encode($dropped));
check('and it names the model that was dropped',
    str_contains((string)($dropped['detail'] ?? ''), 'deepseek-r1'));

// =====================================================================
// The case that taught this: the record says completed, the steps say otherwise.
$legacy = RunDiagnosis::fromFacts([
    'status'       => 'completed',
    'failed_steps' => [['step_key' => 'L4.synthesis', 'summary' => 'bothub HTTP 404']],
]);
check('a record that says completed while a step failed is told as it is',
    ($legacy['cause'] ?? '') === 'recorded_completed_but_failed'
    && str_contains((string)($legacy['headline'] ?? ''), 'L4.synthesis')
    && in_array('rerun_synthesis', $actionIds($legacy), true),
    json_encode($legacy));
check('and a completed record whose steps are all fine stays ok',
    (RunDiagnosis::fromFacts(['status' => 'completed', 'failed_steps' => []])['severity'] ?? '') === 'ok');

// =====================================================================
echo "\n== (e) no verdict leaves the operator without a step ==\n";
// =====================================================================
$cases = [
    ['status' => 'failed', 'status_reason' => 'pool_no_usable_models'],
    ['status' => 'failed', 'status_reason' => 'l4_synthesis_failed'],
    ['status' => 'failed', 'status_reason' => 'l1_no_results'],
    ['status' => 'failed', 'status_reason' => 'connector_unavailable'],
    ['status' => 'failed', 'status_reason' => 'unheard_of'],
    ['status' => 'failed'],
    ['status' => 'cancelled'],
    ['code_state' => 'stale'],
    ['schema_live' => '1.0.0', 'schema_expected' => '1.1.0'],
];
$bare = [];
foreach ($cases as $i => $case) {
    $verdict = RunDiagnosis::fromFacts($case);
    if (($verdict['actions'] ?? []) === []) {
        $bare[] = $i . ':' . ($verdict['cause'] ?? '?');
    }
}
check('every non-ok verdict carries at least one action', $bare === [], implode(', ', $bare));
check('and every headline is a sentence, not a code', (function () use ($cases): bool {
    foreach ($cases as $case) {
        if (!str_contains((string)(RunDiagnosis::fromFacts($case)['headline'] ?? ''), ' ')) {
            return false;
        }
    }

    return true;
})());

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);