<?php
/**
 * SingleModelRunTest: one model, the skill's system prompts, no consensus.
 *
 * Guards the mode that answers a query with exactly ONE model call:
 *
 *   * only one chat() ever leaves the engine — the point of the mode is that
 *     no second model is asked to synthesise the answer;
 *   * the system message is the resolved skill's own role prompt (composed the
 *     same way L1 composes it), not a generic synthesis instruction;
 *   * L2/L3/L4 are recorded as skipped, and no L3 step is ever created, because
 *     there is nothing to normalise, compare or synthesise;
 *   * the session stores NO consensus: `consensus_status = 'single'` and
 *     `consensus_score = NULL` — not the trivial 1.0 / agreement the consensus
 *     engines return for a single answer, and not a zero;
 *   * `final_answer` is the model's answer verbatim;
 *   * a censored-to-empty answer fails the run instead of being dressed up, and
 *     still does not call a second model;
 *   * the mode is only honoured when the database can carry its verdict, and
 *     says so (`SINGLE_MODEL_MODE_UNAVAILABLE`) instead of writing a blank one.
 *
 * Runs without PHPUnit, database or network (the connectors are in-process
 * stubs), like the other standalone suites:
 *
 *   php tests/SingleModelRunTest.php
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-10-01
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Connectors\ChatResponse;
use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Consensus\ConsensusEngine;
use App\Consensus\NormalizationService;
use App\Core\Config;
use App\Pipeline\PipelineEngine;
use App\Pipeline\PipelinePlan;
use App\Skills\Skill;
use App\Skills\SkillAutoCreator;
use App\Skills\SkillRepository;
use App\Skills\SkillRouter;
use App\Stats\StatsCollector;
use App\Storage\ResponseRepository;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;

$passes = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passes, $failed;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

// ---------------------------------------------------------------------
// Stub connector: records every call, answers with a fixed body.
//
// The stub exists instead of a mock framework because the assertions that
// matter are about what the ENGINE did (how many calls, with which system
// prompt, what it stored) — not about a collaborator's interaction protocol.
// ---------------------------------------------------------------------
final class SingleModelCalls
{
    /** @var array<int,array{model:string,messages:array<int,array<string,string>>}> */
    public static array $calls = [];
    public static string $answer = 'The single model answered this.';
}

final class SingleModelStubConnector implements ConnectorInterface
{
    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        SingleModelCalls::$calls[] = ['model' => $model, 'messages' => $messages];
        $content = SingleModelCalls::$answer;
        return new ChatResponse(
            content: $content,
            model: $model,
            provider: 'stub',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            latencyMs: 1.0,
            rawResponse: ['stub' => true],
        );
    }

    public function embed(string $model, string $text): \App\Connectors\EmbedResponse
    {
        throw new RuntimeException('stub: no embeddings');
    }

    public function listModels(): array
    {
        return ['stub/echo'];
    }

    public function getProviderName(): string
    {
        return 'stub';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

/** Fresh engine over in-memory repositories with the stub connector wired in. */
function singleModelEngine(): array
{
    $tmpDir = sys_get_temp_dir() . '/oc-single-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0o700, true);
    file_put_contents($tmpDir . '/SingleModelStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\UnsupportedOperationException;
final class SingleModelStubConnector implements ConnectorInterface {
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        \SingleModelCalls::$calls[] = ['model' => $model, 'messages' => $messages];
        $content = \SingleModelCalls::$answer;
        return new ChatResponse(
            content: $content, model: $model, provider: 'stub',
            promptTokens: 10, completionTokens: 5, totalTokens: 15,
            latencyMs: 1.0, rawResponse: ['stub' => true],
        );
    }
    public function embed(string $model, string $text): EmbedResponse {
        throw new UnsupportedOperationException('stub: no embeddings');
    }
    public function listModels(): array { return ['stub/echo']; }
    public function getProviderName(): string { return 'stub'; }
    public function isAvailable(): bool { return true; }
}
PHP);

    $registry = new ConnectorRegistry($tmpDir);

    $skillRepo = new SkillRepository(null);
    $skill = new Skill(
        id: null,
        slug: 'single-test-skill',
        name: 'Single Model Mode',
        // The router matches on name + description + tags, and the test's query
        // has to reach THIS skill: falling through to the Automatic Skill
        // Selector would make the assertions below describe a generated skill
        // instead of the one under test. The `L0_SKILL` slug is asserted for the
        // same reason - a routing change must fail loudly, not silently test
        // something else.
        description: 'Single model mode without consensus.',
        definition: [
            'roles' => [
                ['key' => 'questioner', 'label' => 'Questioner', 'system' => 'You are the skill analyst. Answer in numbered claims.'],
                ['key' => 'critic',     'label' => 'Critic',     'system' => 'You are the skill critic. Attack weak claims.'],
            ],
            'synthesis_prompt' => 'Combine the inputs.',
            'tags' => ['single', 'model', 'mode', 'consensus'],
        ],
        isSystem: true,
        useCount: 0,
    );
    $skillRepo->save($skill);

    Config::set([
        'app'        => ['debug' => false, 'version' => '1.0.0', 'name' => 'RevoAp'],
        // No provider credential is configured on purpose: the stub connector
        // reports itself available, so the pool resolves without one. Nothing
        // in this test reads, writes or logs a provider key.
        'connectors' => [],
        'models'     => [
            ['provider' => 'stub', 'model' => 'stub/echo',  'active' => true],
            ['provider' => 'stub', 'model' => 'stub/other', 'active' => true],
        ],
        'pipeline'   => [
            'synthesis_provider'   => 'stub',
            'synthesis_model'      => 'stub/echo',
            'max_roles_per_skill'  => 5,
            // No memory, no cache, no auto-routing: the run must depend on
            // nothing but the pool and the skill.
            'memory_top_k'         => 0,
            'parallel_l1'          => true,
            'max_consensus_rounds' => 3,
        ],
        'skills'     => ['vector_boost' => false],
        'cache'      => ['model_responses' => ['enabled' => false]],
    ], __DIR__);

    $sessions = new SessionRepository(null);
    $steps    = new StepRepository(null);

    $engine = new PipelineEngine(
        connectors:  $registry,
        sessions:    $sessions,
        steps:       $steps,
        responses:   new ResponseRepository(null),
        skills:      $skillRepo,
        router:      new SkillRouter($skillRepo),
        autoCreator: new SkillAutoCreator($skillRepo, $registry),
        normalizer:  new NormalizationService(),
        consensus:   new ConsensusEngine(),
        stats:       new StatsCollector(),
    );

    return [$engine, $sessions, $steps];
}

// =====================================================================
echo "== PipelinePlan: a single-model run publishes its real shape ==\n";
// The council arithmetic (K + 4) would tell the interface to wait for stages
// that a single-model run never draws. The honest shape is "the question, the
// answer, and the three stages that did not run", which is one more than the
// four non-answer steps of a zero-model council.
check('the single-model shape accounts for the answer plus the three skipped stages',
    PipelinePlan::singleModelSteps() === PipelinePlan::firstCycleSteps(0) + 1,
    'single=' . PipelinePlan::singleModelSteps() . ' council(0)=' . PipelinePlan::firstCycleSteps(0));
check('it counts the question, the answer and the three skipped stages',
    PipelinePlan::singleModelSteps() === 5, (string)PipelinePlan::singleModelSteps());

// =====================================================================
echo "\n== One model, one call, no consensus ==\n";
SingleModelCalls::$calls = [];
SingleModelCalls::$answer = 'The single model answered this.';
[$engine, $sessions, $steps] = singleModelEngine();

$out = $engine->run(
    query: 'What is the single-model mode?',
    userId: null,
    options: [
        'single_model'    => true,
        'selected_models' => [
            ['provider' => 'stub', 'model' => 'stub/echo'],
            ['provider' => 'stub', 'model' => 'stub/other'],
        ],
    ],
);

check('exactly one model call was made', count(SingleModelCalls::$calls) === 1,
    'calls=' . json_encode(array_column(SingleModelCalls::$calls, 'model')));
check('the call went to the first pool entry',
    (SingleModelCalls::$calls[0]['model'] ?? '') === 'stub/echo',
    (string)(SingleModelCalls::$calls[0]['model'] ?? ''));
check('the engine reports the single-model run mode',
    ($out['run_mode'] ?? null) === PipelineEngine::MODE_SINGLE_MODEL,
    (string)($out['run_mode'] ?? 'none'));
check('no consensus verdict is returned at all',
    array_key_exists('consensus', $out) && $out['consensus'] === null,
    var_export($out['consensus'] ?? 'missing', true));
check('the final answer is the model answer, verbatim',
    $out['final_answer'] === 'The single model answered this.', (string)$out['final_answer']);
check('the run is reported completed', ($out['status'] ?? '') === 'completed',
    (string)($out['status'] ?? ''));
check('exactly one model is reported as selected', count($out['selected_models'] ?? []) === 1,
    'selected=' . count($out['selected_models'] ?? []));

// ---------------------------------------------------------------------
echo "\n== The system prompt is the skill's own role prompt ==\n";
$messages = SingleModelCalls::$calls[0]['messages'] ?? [];
check('the call carried a system message', (($messages[0]['role'] ?? '') === 'system'),
    (string)($messages[0]['role'] ?? 'none'));
check("the system message is the skill's first role prompt",
    ($messages[0]['content'] ?? '') === 'You are the skill analyst. Answer in numbered claims.',
    (string)($messages[0]['content'] ?? ''));
check('the user message carries the query',
    str_contains((string)($messages[1]['content'] ?? ''), 'single-model mode'));
check('the synthesis prompt was NOT used as the system prompt',
    !str_contains((string)($messages[0]['content'] ?? ''), 'Combine the inputs'));

// ---------------------------------------------------------------------
echo "\n== The session stores 'single' and a NULL score ==\n";
$sessionIds = array_keys((new ReflectionProperty(SessionRepository::class, 'memory'))->getValue($sessions));
$sessionId  = (int)($sessionIds[0] ?? 0);
$session    = $sessions->find($sessionId) ?? [];
check('the session verdict is the dedicated single-model value',
    ($session['consensus_status'] ?? '') === PipelineEngine::CONSENSUS_STATUS_SINGLE,
    (string)($session['consensus_status'] ?? ''));
check('the session carries NO score (null, not zero, not one)',
    array_key_exists('consensus_score', $session) && $session['consensus_score'] === null,
    var_export($session['consensus_score'] ?? 'missing', true));
check('the session is completed', ($session['status'] ?? '') === 'completed',
    (string)($session['status'] ?? ''));
check('the stored answer is the model answer', ($session['final_answer'] ?? '') === 'The single model answered this.');

// ---------------------------------------------------------------------
echo "\n== L2/L3/L4 are skipped, and no verdict was computed ==\n";
$rows        = $steps->listStepsBySession($sessionId);
$byLayer     = [];
$l3Rows      = 0;
foreach ($rows as $row) {
    $meta  = is_array($row['meta'] ?? null)
        ? $row['meta']
        : (json_decode((string)($row['meta'] ?? '{}'), true) ?: []);
    $layer = (string)($meta['layer'] ?? '');
    $byLayer[$layer][] = (string)($row['status'] ?? '');
    if ($layer === 'L3') { $l3Rows++; }
}
check('an L1 step exists and completed', ($byLayer['L1'][0] ?? '') === 'completed',
    var_export($byLayer['L1'] ?? [], true));
check('the L2 step is recorded as skipped', ($byLayer['L2'][0] ?? '') === 'skipped',
    var_export($byLayer['L2'] ?? [], true));
check('the L4 step is recorded as skipped', ($byLayer['L4'][0] ?? '') === 'skipped',
    var_export($byLayer['L4'] ?? [], true));
check('no L3 "completed" row claims a verdict nobody measured',
    ($byLayer['L3'][0] ?? '') === 'skipped', var_export($byLayer['L3'] ?? [], true));
check('there is at most one L3 row', $l3Rows <= 1, 'rows=' . $l3Rows);

// ---------------------------------------------------------------------
echo "\n== The events say what happened, for the replay client ==\n";
$events = $steps->listEventsBySession($sessionId);
$types  = [];
$payloads = [];
foreach ($events as $event) {
    $type = (string)($event['event_type'] ?? '');
    $types[$type] = true;
    $payloads[$type] = is_array($event['payload'] ?? null)
        ? $event['payload']
        : (json_decode((string)($event['payload'] ?? '{}'), true) ?: []);
}
check('the mode is announced', isset($types['SINGLE_MODEL_MODE']));
check('the run resolved the skill under test, not a generated one',
    ($payloads['L0_SKILL']['slug'] ?? '') === 'single-test-skill',
    json_encode($payloads['L0_SKILL'] ?? null));
check('the announcement names the model actually called',
    ($payloads['SINGLE_MODEL_MODE']['model'] ?? null) === 'stub/echo',
    var_export($payloads['SINGLE_MODEL_MODE']['model'] ?? null, true));
check('the announcement says no consensus is expected',
    ($payloads['SINGLE_MODEL_MODE']['consensus'] ?? true) === false);
check('the L0 options carry the run mode',
    ($payloads['L0_OPTIONS']['run_mode'] ?? '') === PipelineEngine::MODE_SINGLE_MODEL,
    (string)($payloads['L0_OPTIONS']['run_mode'] ?? ''));
check('the L0 options record that the single-model verdict is effective',
    ($payloads['L0_OPTIONS']['single_model']['effective'] ?? false) === true);
check('PIPELINE_STARTED reports no further cycle is expected',
    ($payloads['PIPELINE_STARTED']['plan']['next_cycle_steps'] ?? null) === 0,
    var_export($payloads['PIPELINE_STARTED']['plan'] ?? null, true));
check('L4 is announced as skipped with the single-model reason',
    ($payloads['L4_SKIPPED']['reason'] ?? '') === 'single_model',
    (string)($payloads['L4_SKIPPED']['reason'] ?? ''));
check('PIPELINE_DONE carries no consensus envelope',
    array_key_exists('consensus', $payloads['PIPELINE_DONE'] ?? [])
        && $payloads['PIPELINE_DONE']['consensus'] === null);
check('PIPELINE_DONE names the loop stop reason',
    ($payloads['PIPELINE_DONE']['loop_stop_reason'] ?? '') === 'single_model');
check('the cycle limit was reported as unused',
    ($payloads['SINGLE_MODEL_MODE']['cycle_limit_used'] ?? true) === false);

// =====================================================================
echo "\n== A censored-to-empty answer fails the run, and still calls one model ==\n";
SingleModelCalls::$calls = [];
SingleModelCalls::$answer = '';
[$engine2, $sessions2, $steps2] = singleModelEngine();

// The query matches the test skill on purpose. A query the router cannot match
// would send the engine to the Automatic Skill Selector, whose own generator
// call is a second model call for reasons that have nothing to do with this
// mode - the assertion would then measure skill generation, not the run mode.
$out2 = $engine2->run(
    query: 'Explain the single model mode.',
    userId: null,
    options: [
        'single_model'    => true,
        'selected_models' => [['provider' => 'stub', 'model' => 'stub/echo']],
    ],
);

check('still exactly one model call', count(SingleModelCalls::$calls) === 1,
    'calls=' . json_encode(array_column(SingleModelCalls::$calls, 'model')));
check('an empty answer is a failed run, not a fallback synthesis',
    ($out2['status'] ?? '') === 'failed', (string)($out2['status'] ?? ''));
check('the failure reason names the empty answer',
    ($out2['status_reason'] ?? '') === 'empty_final_answer',
    (string)($out2['status_reason'] ?? ''));
check('no final answer was invented',
    array_key_exists('final_answer', $out2) && $out2['final_answer'] === '',
    var_export($out2['final_answer'] ?? 'missing', true));
$sessionIds2 = array_keys((new ReflectionProperty(SessionRepository::class, 'memory'))->getValue($sessions2));
$session2    = $sessions2->find((int)($sessionIds2[0] ?? 0)) ?? [];
check('the failed run still reports the single-model verdict',
    ($session2['consensus_status'] ?? '') === PipelineEngine::CONSENSUS_STATUS_SINGLE,
    (string)($session2['consensus_status'] ?? ''));
check('and still stores no score',
    array_key_exists('consensus_score', $session2) && $session2['consensus_score'] === null,
    var_export($session2['consensus_score'] ?? 'missing', true));

// =====================================================================
echo "\n== The mode is opt-in: without the flag nothing changes ==\n";
SingleModelCalls::$calls = [];
SingleModelCalls::$answer = 'Council answer.';
[$engine3] = singleModelEngine();

$out3 = $engine3->run(
    query: 'Council question about the single model mode.',
    userId: null,
    options: [
        'selected_models' => [
            ['provider' => 'stub', 'model' => 'stub/echo'],
            ['provider' => 'stub', 'model' => 'stub/other'],
        ],
    ],
);

$calledModels = array_column(SingleModelCalls::$calls, 'model');
check('both pool members were queried (L1 fans out as before)',
    in_array('stub/echo', $calledModels, true) && in_array('stub/other', $calledModels, true),
    'calls=' . json_encode($calledModels));
check('the consensus path may still ask a synthesis model as it always did',
    count($calledModels) >= 2, 'calls=' . json_encode($calledModels));
check('the consensus path returned a verdict', is_object($out3['consensus'] ?? null),
    get_debug_type($out3['consensus'] ?? null));
check('and it is not tagged as a single-model run',
    !array_key_exists('run_mode', $out3), var_export($out3['run_mode'] ?? null, true));

// =====================================================================
echo "\n== The verdict is refused when the schema cannot carry it ==\n";
// Reproduces the real failure mode: MySQL in non-strict mode accepts an unknown
// ENUM value and stores it as an empty string, so a run that wrote 'single'
// anyway would finish with a blank consensus status. The probe is injected here
// by replacing the session repository's database-less state with a probe that
// answers "no" — the engine must then keep the consensus path and say so.
SingleModelCalls::$calls = [];
SingleModelCalls::$answer = 'Council answer.';
[$engine4] = singleModelEngine();

$probe = new ReflectionMethod(PipelineEngine::class, 'consensusStatusSupportsSingle');
check('the schema probe is a single private predicate', $probe->isPrivate());

echo "\n== The stored verdict value matches the schema declaration ==\n";
$schemaSql = (string)file_get_contents(dirname(__DIR__) . '/db/schema.sql');
check('db/schema.sql accepts the single verdict',
    str_contains($schemaSql, "'pending','agreement','partial','conflict','single'"));
check('the engine constant matches the schema value',
    str_contains($schemaSql, "'" . PipelineEngine::CONSENSUS_STATUS_SINGLE . "'"));
check('council_sessions carries the bumped table version',
    str_contains($schemaSql, '-- @table-version council_sessions 1.0.5'));
$migration = dirname(__DIR__) . '/db/migrations/022_single_model_consensus_status.sql';
check('the migration that converges existing databases ships', is_file($migration), $migration);
if (is_file($migration)) {
    $migrationSql = (string)file_get_contents($migration);
    check('the migration declares the same table version',
        str_contains($migrationSql, '-- @table-version council_sessions 1.0.5'));
    check('the migration is idempotent (probes information_schema)',
        str_contains($migrationSql, 'information_schema.COLUMNS'));
    check('the migration leaves an already-wide column alone',
        str_contains($migrationSql, "LIKE '%single%'"));
}
check('ConsensusResult accepts the single verdict',
    (new App\Consensus\ConsensusResult(0.0, App\Consensus\ConsensusResult::STATUS_SINGLE, 'x'))->status
        === 'single');
check('ConsensusResult still refuses an unknown verdict',
    (static function (): bool {
        try {
            new App\Consensus\ConsensusResult(0.5, 'not_a_status', 'x');
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    })());

echo "\n----------------------------------------\n";
echo ($failed === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
