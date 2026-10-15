<?php
declare(strict_types=1);
/**
 * PipelinePlanTest: the step count a run publishes with its first event.
 *
 * The interface counter needs a plan, and the plan has a subtlety worth a test:
 * the first cycle is K answers plus the question, L2, L3 and L4, while later
 * cycles repeat L1-L4 without a second question card. A counter that ignored
 * that would show "over 100%" on any run that cycles.
 *
 *   php tests/PipelinePlanTest.php
 */
use App\Pipeline\PipelinePlan;

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $c) {
    if (is_file($root . $c)) { require $root . $c; }
}

$passes = 0; $failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passes, $failed;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failed++; }
}

check('the plan helper is loadable', class_exists(PipelinePlan::class));
check('four steps are not L1 answers', PipelinePlan::NON_L1_STEPS === 4);

check('five models make nine steps in the first cycle', PipelinePlan::firstCycleSteps(5) === 9, (string)PipelinePlan::firstCycleSteps(5));
check('three models make seven steps', PipelinePlan::firstCycleSteps(3) === 7, (string)PipelinePlan::firstCycleSteps(3));
check('one model makes five steps', PipelinePlan::firstCycleSteps(1) === 5, (string)PipelinePlan::firstCycleSteps(1));
check('no models still counts the four fixed steps', PipelinePlan::firstCycleSteps(0) === 4, (string)PipelinePlan::firstCycleSteps(0));
check('a negative count is treated as none', PipelinePlan::firstCycleSteps(-3) === 4);

check('a later cycle repeats L1-L4 without the question card', PipelinePlan::nextCycleSteps(5) === 8, (string)PipelinePlan::nextCycleSteps(5));
check('and it matches the first cycle minus the question', PipelinePlan::nextCycleSteps(5) === PipelinePlan::firstCycleSteps(5) - 1);
check('a later cycle with no models still adds L2-L4', PipelinePlan::nextCycleSteps(0) === 3);

$engineSrc = (string)file_get_contents($root . '/src/Pipeline/PipelineEngine.php');
check('the run publishes its plan with the first event', str_contains($engineSrc, "'plan'       => ["));
check('the plan carries the first cycle and the ceiling',
    str_contains($engineSrc, "PipelinePlan::firstCycleSteps(\$planModels)") && str_contains($engineSrc, "'cycles_max'"));
check('the plan is published on PIPELINE_STARTED', str_contains($engineSrc, "'plan'       => [") && str_contains($engineSrc, 'PIPELINE_STARTED'));

// The role on the step card, and the Research card in the Models tab.
$engineSrc = (string)file_get_contents($root . '/src/Pipeline/PipelineEngine.php');
check('the L1 payload carries the role label',
    str_contains($engineSrc, "'role_label'  => (string)(\$p['role']['label'] ?? \$roleKey),"));
$pipe = (string)file_get_contents($root . '/assets/js/pipeline.js');
check('the step card shows the role when there is one',
    str_contains($pipe, "[['Role', meta.role_label ?? meta.role]]"));
$modelsSrc = (string)file_get_contents($root . '/assets/js/models.js');
check('the Models tab carries the Research card', str_contains($modelsSrc, "title: 'Research'"));
check('with the research model picker', str_contains($modelsSrc, "'oc-research-picker'"));
check('and both actions', str_contains($modelsSrc, "'/api/research/search'") && str_contains($modelsSrc, "'/api/research/analyse'"));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
