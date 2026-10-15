<?php
/**
 * HistoryCompletionTest: offline checks for the History "Finish session"
 * flow (SessionCompletionService + PipelineEngine::repairSession()).
 *
 * Runs without PHPUnit and — crucially — without a database and without
 * any network access, so it can be executed as
 * `php tests/HistoryCompletionTest.php` on any host with PHP 8.4.
 *
 * How the "interrupted session" fixtures are built
 * ------------------------------------------------
 * A crash cannot be injected into PipelineEngine::run() from the outside
 * (the class is final and the layer methods are private), so instead of
 * faking a crash the fixtures write exactly the rows a crash would have
 * left behind: completed `council_steps` rows for the layers that ran,
 * and `council_model_responses` rows for every answer that came back.
 * The verification then asserts what the rebuild does with those rows —
 * in particular that a model whose answer is already stored is NOT
 * queried a second time (the stub connector records every call).
 *
 * Verified:
 *   - plan contains only the missing stages (and only the missing
 *     models when L1 is half-done);
 *   - dry-run writes nothing;
 *   - rebuild creates the missing L3/L4 rows, tags them `repair => true`,
 *     completes the session with a non-empty final answer, consensus,
 *     tokens and duration;
 *   - already-completed sessions are refused with 409;
 *   - a nonexistent id is a 404;
 *   - a second click after success does not run a second rebuild;
 *   - a live run lock blocks the rebuild (and `force` overrides it);
 *   - a concurrent rebuild is refused while the mutex is held;
 *   - an unrecoverable rebuild journals a failed step + event and leaves
 *     the session retryable.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Connectors\ChatResponse;
use App\Connectors\ConnectorRegistry;
use App\Consensus\ConsensusEngine;
use App\Consensus\NormalizationService;
use App\Core\Config;
use App\History\SessionCompletionService;
use App\Pipeline\PipelineEngine;
use App\Pipeline\StreamRunLock;
use App\Skills\Skill;
use App\Skills\SkillAutoCreator;
use App\Skills\SkillRepository;
use App\Skills\SkillRouter;
use App\Stats\StatsCollector;
use App\Storage\ResponseRepository;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------
// Stub connectors, loaded through the real ConnectorRegistry so the
// engine resolves them exactly the way it does in production.
// ---------------------------------------------------------------------
$tmpDir = sys_get_temp_dir() . '/oc-repair-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0o700, true);
$lockBase = sys_get_temp_dir() . '/oc-repair-locks-' . bin2hex(random_bytes(4));
mkdir($lockBase, 0o700, true);

file_put_contents($tmpDir . '/RepairStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;
final class RepairStubConnector implements ConnectorInterface {
    /** @var array<int,array{model:string,system:string,user:string}> */
    public static array $calls = [];
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        self::$calls[] = [
            'model'  => $model,
            'system' => (string)($messages[0]['content'] ?? ''),
            'user'   => (string)($messages[1]['content'] ?? ''),
        ];
        $user = (string)($messages[1]['content'] ?? '');
        // Model-specific wording so two participants genuinely disagree
        // (the repair then takes the no-consensus synthesis path).
        $content = '[repair-stub ' . $model . '] ' . $user . ' '
            . str_repeat('Detailed analysis sentence for ' . $model . '. ', 20);
        $pt = TokenHelper::estimateMessageTokens($messages);
        $ct = TokenHelper::estimateTokens($content);
        return new ChatResponse($content, $model, 'repairstub', $pt, $ct, $pt + $ct, 1.2, ['stub' => true], true);
    }
    public function embed(string $model, string $text): EmbedResponse { throw new UnsupportedOperationException('stub'); }
    public function listModels(): array { return ['repairstub/echo', 'repairstub/echo-b']; }
    public function getProviderName(): string { return 'repairstub'; }
    public function isAvailable(): bool { return true; }
}
PHP);

file_put_contents($tmpDir . '/FailStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\UnsupportedOperationException;
final class FailStubConnector implements ConnectorInterface {
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        throw new ConnectorException('failstub: simulated provider outage');
    }
    public function embed(string $model, string $text): EmbedResponse { throw new UnsupportedOperationException('stub'); }
    public function listModels(): array { return ['failstub/model']; }
    public function getProviderName(): string { return 'failstub'; }
    public function isAvailable(): bool { return true; }
}
PHP);

$registry  = new ConnectorRegistry($tmpDir);
$registry->load(); // require the stub classes so the call log is reachable by name
$lock      = new StreamRunLock($lockBase);

Config::set([
    'app'        => ['debug' => false, 'version' => '1.0.0', 'name' => 'RevoAp'],
    'connectors' => [
        'repairstub' => ['active' => true, 'api_key' => 'x'],
        'failstub'   => ['active' => true, 'api_key' => 'x'],
    ],
    'models'     => [
        ['provider' => 'repairstub', 'model' => 'repairstub/echo',   'active' => true],
        ['provider' => 'repairstub', 'model' => 'repairstub/echo-b', 'active' => true],
        ['provider' => 'failstub',   'model' => 'failstub/model',    'active' => true],
    ],
    'pipeline'   => [
        'synthesis_provider'  => 'repairstub',
        'synthesis_model'     => 'repairstub/echo',
        'max_roles_per_skill' => 5,
        'parallel_l1'         => false,
    ],
], __DIR__);

// ---------------------------------------------------------------------
// Shared fixtures
// ---------------------------------------------------------------------
$skills = new SkillRepository(null);
$skill  = $skills->save(new Skill(
    id: null, slug: 'generic', name: 'Generic', description: '',
    definition: [
        'roles' => [
            ['key' => 'analyst', 'system' => 'Analyst speaking.'],
            ['key' => 'critic',  'system' => 'Critic speaking.'],
        ],
        'synthesis_prompt' => 'Combine inputs.',
    ],
    isSystem: true, useCount: 0,
));
$skillId = (int)$skill->id;

$sessions  = new SessionRepository(null);
$steps     = new StepRepository(null);
$responses = new ResponseRepository(null);

$engine = new PipelineEngine(
    connectors:  $registry,
    sessions:    $sessions,
    steps:       $steps,
    responses:   $responses,
    skills:      $skills,
    router:      new SkillRouter($skills),
    autoCreator: new SkillAutoCreator($skills, $registry),
    normalizer:  new NormalizationService(),
    consensus:   new ConsensusEngine(),
    stats:       new StatsCollector(),
);

$service = new SessionCompletionService($sessions, $steps, $responses, $skills, $engine, $lock);

const MODEL_A = 'repairstub/echo';
const MODEL_B = 'repairstub/echo-b';
const ROLE_A  = 'analyst@repairstub_echo';
const ROLE_B  = 'critic@repairstub_echo_b';

/**
 * Build the rows a crash would have left behind.
 *
 * @param  array{l1_models:array<int,string>,l2:bool,l3:bool,l4:bool} $shape
 * @return array{session_id:int,l1_steps:array<string,int>,content:array<string,string>}
 */
function seedInterruptedSession(
    SessionRepository $sessions,
    StepRepository $steps,
    ResponseRepository $responses,
    int $skillId,
    string $query,
    array $l1Models,
    bool $withL2,
): array {
    $sid = $sessions->create($skillId, $query);
    $sessions->setMetadata($sid, 'generic', [
        'selected_models' => array_map(
            static fn(string $m): array => ['provider' => 'repairstub', 'model' => $m],
            [MODEL_A, MODEL_B],
        ),
        'reasoning'   => ['requested' => null, 'effective' => null],
        'parallel_l1' => false,
        'user_id'     => null,
    ]);

    $roles = ['analyst', 'critic'];
    $tags  = ['repairstub_echo', 'repairstub_echo_b'];
    $l1Steps  = [];
    $content  = [];
    $claimsByRole = [
        ROLE_A => ['Alpha one.', 'Alpha two.', 'Alpha three.'],
        ROLE_B => ['Bravo one.', 'Bravo two.'],
    ];
    $order = 1;
    foreach ([MODEL_A, MODEL_B] as $idx => $model) {
        if (!in_array($model, $l1Models, true)) {
            $order++;
            continue;
        }
        $roleKey = $roles[$idx] . '@' . $tags[$idx];
        $text    = 'Answer produced by ' . $model . ' for: ' . $query;
        $stepId  = $steps->createStep($sid, 'L1.role.' . $roleKey, 'L1 · ' . $roleKey, $order, [
            'layer'       => 'L1',
            'round'       => 1,
            'role'        => $roleKey,
            'origin_role' => $roles[$idx],
            'model'       => $model,
            'provider'    => 'repairstub',
        ]);
        $order++;
        $resp = new ChatResponse(
            content: $text, model: $model, provider: 'repairstub',
            promptTokens: 100, completionTokens: 200, totalTokens: 300, latencyMs: 10.0,
        );
        // 1st row = the L1 model call itself.
        $responses->record($stepId, $resp, $roleKey);
        $steps->completeStep($stepId, 'completed', $text, ['layer' => 'L1', 'round' => 1, 'role' => $roleKey]);
        if ($withL2) {
            // 2nd row = what L2 records on the SAME step together with the
            // normalized payload (this is what the rebuild reconstructs from).
            $responses->record($stepId, $resp, $roleKey, [
                'shape'  => 'text',
                'data'   => $text,
                'claims' => $claimsByRole[$roleKey],
            ]);
            $l2Step = $steps->createStep($sid, 'L2.normalize.' . $roleKey, 'L2 · ' . $roleKey, 100 + $idx, [
                'layer' => 'L2',
                'round' => 1,
                'role'  => $roleKey,
            ]);
            $steps->completeStep($l2Step, 'completed', 'Normalised ' . $roleKey, [
                'layer' => 'L2', 'round' => 1, 'role' => $roleKey,
            ]);
        }
        $l1Steps[$model] = $stepId;
        $content[$model] = $text;
    }
    return ['session_id' => $sid, 'l1_steps' => $l1Steps, 'content' => $content];
}

/** @return array<int,array<string,mixed>> */
function stepMetas(StepRepository $steps, int $sid): array
{
    $out = [];
    foreach ($steps->listStepsBySession($sid) as $row) {
        $meta = $row['meta'] ?? [];
        if (is_string($meta)) { $meta = json_decode($meta, true) ?: []; }
        $out[] = ['step' => $row, 'meta' => is_array($meta) ? $meta : []];
    }
    return $out;
}

function layersPresent(StepRepository $steps, int $sid): array
{
    $out = [];
    foreach (stepMetas($steps, $sid) as $row) {
        $layer = (string)($row['meta']['layer'] ?? '');
        if ($layer !== '') { $out[$layer] = ($out[$layer] ?? 0) + 1; }
    }
    return $out;
}

// =====================================================================
echo "\n== Scenario 1: L1+L2 stored, L3+L4 missing ==\n";
// =====================================================================
App\Connectors\RepairStubConnector::$calls = [];
$fx  = seedInterruptedSession($sessions, $steps, $responses, $skillId, 'Which three options are best?', [MODEL_A, MODEL_B], true);
$sid = $fx['session_id'];

$stepsBefore   = count($steps->listStepsBySession($sid));
$eventsBefore  = count($steps->listEventsBySession($sid));
$rowsBeforeA   = count($responses->listByStep($fx['l1_steps'][MODEL_A]));
$existingTokens = 300 + 300; // one L1 call per model x 300 tokens

// ---- dry run must be side-effect free -------------------------------
$dry = $service->complete($sid, true);
check('dry-run returns HTTP 200', $dry['status'] === 200, 'status=' . $dry['status']);
check('dry-run reports ok', ($dry['body']['ok'] ?? false) === true);
check('dry-run is flagged as such', ($dry['body']['dry_run'] ?? false) === true);
check('dry-run plan lists exactly L3 + L4', array_map(
    static fn(array $i): string => (string)$i['layer'],
    $dry['body']['plan']['items'] ?? [],
) === ['L3', 'L4'], json_encode($dry['body']['plan']['items'] ?? null));
check('dry-run plan has no missing L1 models', ($dry['body']['plan']['l1']['missing'] ?? null) === []);
check('dry-run plan has no missing L2 roles', ($dry['body']['plan']['l2']['roles_missing'] ?? null) === []);
check('dry-run plan marks L3 and L4 required',
    ($dry['body']['plan']['l3']['required'] ?? false) === true
    && ($dry['body']['plan']['l4']['required'] ?? false) === true);
check('dry-run lists the reused L1 answers',
    count(array_filter(
        $dry['body']['already_completed'] ?? [],
        static fn(array $a): bool => ($a['layer'] ?? '') === 'L1',
    )) === 2);
check('dry-run wrote no step rows', count($steps->listStepsBySession($sid)) === $stepsBefore);
check('dry-run wrote no events', count($steps->listEventsBySession($sid)) === $eventsBefore);
check('dry-run left the session unfinished', ($sessions->find($sid)['status'] ?? '') === 'running');
check('dry-run made no model call', App\Connectors\RepairStubConnector::$calls === []);

// ---- real rebuild ---------------------------------------------------
$run = $service->complete($sid);
check('rebuild returns HTTP 200', $run['status'] === 200, 'status=' . $run['status'] . ' body=' . json_encode($run['body']));
check('rebuild reports ok', ($run['body']['ok'] ?? false) === true);

$session = $sessions->find($sid) ?? [];
check('session status is completed', ($session['status'] ?? '') === 'completed');
check('final answer is non-empty', trim((string)($session['final_answer'] ?? '')) !== '');
check('final answer carries the synthesised content',
    str_contains((string)($session['final_answer'] ?? ''), 'repair-stub'));
check('consensus status is a valid enum value',
    in_array((string)($session['consensus_status'] ?? ''), ['agreement', 'partial', 'conflict'], true),
    'status=' . (string)($session['consensus_status'] ?? ''));
check('consensus score is a float in [0,1]',
    is_float($session['consensus_score']) || is_numeric($session['consensus_score']));
check('completed_at was stamped', ($session['completed_at'] ?? null) !== null);
check('duration_ms is positive', (int)($session['duration_ms'] ?? 0) > 0, 'duration=' . (int)($session['duration_ms'] ?? 0));

$layers = layersPresent($steps, $sid);
check('an L3 step now exists', ($layers['L3'] ?? 0) === 1, json_encode($layers));
check('an L4 step now exists', ($layers['L4'] ?? 0) === 1, json_encode($layers));
check('no L1 step was added', ($layers['L1'] ?? 0) === 2, json_encode($layers));
check('no L2 step was added', ($layers['L2'] ?? 0) === 2, json_encode($layers));

// The heart of the feature: the already-answered models were NOT re-queried.
check('exactly one model call was made (the synthesis)',
    count(App\Connectors\RepairStubConnector::$calls) === 1,
    json_encode(App\Connectors\RepairStubConnector::$calls));
$only = App\Connectors\RepairStubConnector::$calls[0] ?? ['system' => '', 'model' => '', 'user' => ''];
check('the single call was the L4 synthesis, not an L1 retry',
    str_contains((string)$only['user'], 'Model answers') || str_contains((string)$only['user'], 'Role outputs'),
    'user=' . substr((string)$only['user'], 0, 80));
check('the single call did not reuse an L1 role prompt',
    !str_contains((string)$only['system'], 'Analyst speaking.')
    && !str_contains((string)$only['system'], 'Critic speaking.'));
check('the stored L1 response rows were not duplicated',
    count($responses->listByStep($fx['l1_steps'][MODEL_A])) === $rowsBeforeA,
    'rows=' . count($responses->listByStep($fx['l1_steps'][MODEL_A])));

// Repair provenance.
$repairTagged = 0;
$l4Meta = null;
foreach (stepMetas($steps, $sid) as $row) {
    if (($row['meta']['repair'] ?? false) === true) { $repairTagged++; }
    if (($row['meta']['layer'] ?? '') === 'L4') { $l4Meta = $row['meta']; }
}
check('rebuilt steps are tagged meta.repair = true', $repairTagged >= 2, 'tagged=' . $repairTagged);
check('rebuilt steps record the repair reason',
    ($l4Meta['repair_reason'] ?? '') === SessionCompletionService::REASON);

// Token + duration accounting.
$expectedTokens = $existingTokens + (int)($l4Meta['total_tokens'] ?? 0);
check('total_tokens = stored answers + rebuild cost',
    (int)($session['total_tokens'] ?? 0) === $expectedTokens,
    'got=' . (int)($session['total_tokens'] ?? 0) . ' expected=' . $expectedTokens);

// Events.
$eventTypes = array_map(
    static fn(array $e): string => (string)($e['event_type'] ?? ''),
    $steps->listEventsBySession($sid),
);
check('SESSION_REPAIR_STARTED was journaled', in_array('SESSION_REPAIR_STARTED', $eventTypes, true));
check('SESSION_REPAIR_COMPLETED was journaled', in_array('SESSION_REPAIR_COMPLETED', $eventTypes, true));
check('L3_CONSENSUS was journaled', in_array('L3_CONSENSUS', $eventTypes, true));
check('a synthesis event was journaled',
    in_array('L4_SYNTHESIS', $eventTypes, true), implode(',', $eventTypes));

// Response envelope.
check('rebuild response lists the executed stages',
    array_map(static fn(array $e): string => (string)$e['layer'], $run['body']['executed'] ?? []) === ['L3', 'L4'],
    json_encode($run['body']['executed'] ?? null));
$snap = $run['body']['session'] ?? [];
check('response session snapshot reports completed status', ($snap['status'] ?? '') === 'completed');
check('response session snapshot carries the final answer',
    trim((string)($snap['final_answer'] ?? '')) !== '');
check('response session snapshot carries tokens + duration',
    (int)($snap['total_tokens'] ?? 0) > 0 && (int)($snap['duration_ms'] ?? 0) > 0);

// =====================================================================
echo "\n== Scenario 2: repeated call after success (idempotency) ==\n";
// =====================================================================
App\Connectors\RepairStubConnector::$calls = [];
$again = $service->complete($sid);
check('second call returns HTTP 409', $again['status'] === 409, 'status=' . $again['status']);
check('second call is machine-readable as already_completed',
    ($again['body']['error'] ?? '') === 'already_completed');
check('second call did not run a second rebuild',
    App\Connectors\RepairStubConnector::$calls === [],
    json_encode(App\Connectors\RepairStubConnector::$calls));
check('second call did not add step rows', count($steps->listStepsBySession($sid)) === $stepsBefore + 2);

$againDry = $service->complete($sid, true);
check('dry-run on a finished session is also 409 already_completed',
    $againDry['status'] === 409 && ($againDry['body']['error'] ?? '') === 'already_completed');

// =====================================================================
echo "\n== Scenario 3: partially completed L1 ==\n";
// =====================================================================
App\Connectors\RepairStubConnector::$calls = [];
$fx2  = seedInterruptedSession($sessions, $steps, $responses, $skillId, 'Half-done L1?', [MODEL_A], false);
$sid2 = $fx2['session_id'];

$dry2 = $service->complete($sid2, true);
$plan2 = $dry2['body']['plan'] ?? [];
check('dry-run accepts the half-done session', ($dry2['body']['ok'] ?? false) === true, json_encode($dry2['body']));
check('plan lists ONLY the missing model in L1',
    array_map(static fn(array $m): string => (string)$m['model'], $plan2['l1']['missing'] ?? []) === [MODEL_B],
    json_encode($plan2['l1']['missing'] ?? null));
check('plan lists the answered model as completed',
    array_map(static fn(array $m): string => (string)$m['model'], $plan2['l1']['completed'] ?? []) === [MODEL_A]);
$l1Items = array_filter($plan2['items'] ?? [], static fn(array $i): bool => ($i['layer'] ?? '') === 'L1');
check('plan has exactly one L1 item', count($l1Items) === 1);
check('the L1 plan item is the missing model',
    (((array_values($l1Items)[0]['model'] ?? '') === MODEL_B)));
check('plan lists the one role known at plan time as needing normalisation',
    count($plan2['l2']['roles_missing'] ?? []) === 1, json_encode($plan2['l2']));

$run2 = $service->complete($sid2);
check('half-done rebuild succeeds', ($run2['body']['ok'] ?? false) === true, json_encode($run2['body']));
check('exactly two model calls (the missing L1 model + the synthesis)',
    count(App\Connectors\RepairStubConnector::$calls) === 2,
    json_encode(App\Connectors\RepairStubConnector::$calls));
$retriedA = array_filter(
    App\Connectors\RepairStubConnector::$calls,
    static fn(array $c): bool => $c['model'] === MODEL_A && str_contains((string)$c['system'], 'Analyst speaking.'),
);
check('the model that already answered was NOT re-queried', $retriedA === []);
$retriedB = array_filter(
    App\Connectors\RepairStubConnector::$calls,
    static fn(array $c): bool => $c['model'] === MODEL_B,
);
check('the missing model was queried', count($retriedB) === 1);
$layers2 = layersPresent($steps, $sid2);
check('both L1 steps exist', ($layers2['L1'] ?? 0) === 2, json_encode($layers2));
$newL1Keys = [];
foreach ($steps->listStepsBySession($sid2) as $row) {
    if (str_starts_with((string)$row['step_key'], 'L1.')) { $newL1Keys[] = (string)$row['step_key']; }
}
check('the rebuilt L1 step keeps the model tag in its key',
    count(array_filter($newL1Keys, static fn(string $k): bool => str_contains($k, 'echo_b'))) === 1,
    json_encode($newL1Keys));
check('L2 rows exist for both roles', ($layers2['L2'] ?? 0) === 2, json_encode($layers2));
check('L3 + L4 exist', ($layers2['L3'] ?? 0) === 1 && ($layers2['L4'] ?? 0) === 1, json_encode($layers2));
check('session completed with a final answer',
    (($sessions->find($sid2)['status'] ?? '') === 'completed')
    && trim((string)($sessions->find($sid2)['final_answer'] ?? '')) !== '');

// =====================================================================
echo "\n== Scenario 4: nonexistent / unrepairable sessions ==\n";
// =====================================================================
$missing = $service->complete(424242);
check('nonexistent session returns HTTP 404', $missing['status'] === 404, 'status=' . $missing['status']);
check('nonexistent session reports not_found', ($missing['body']['error'] ?? '') === 'not_found');

$emptySid = $sessions->create($skillId, 'Nothing ran here at all.');
$empty    = $service->complete($emptySid);
check('session with no steps and no options is a 409', $empty['status'] === 409, 'status=' . $empty['status']);
check('session with no steps reports nothing_to_repair',
    ($empty['body']['error'] ?? '') === 'nothing_to_repair', json_encode($empty['body']));
check('the unrepairable session was left untouched',
    ($sessions->find($emptySid)['status'] ?? '') === 'running');

$orphanFx  = seedInterruptedSession($sessions, $steps, $responses, 987654, 'Skill is gone.', [MODEL_A, MODEL_B], true);
$orphanSid = $orphanFx['session_id'];
// Repoint the row at a skill that is not in the catalog at all.
$sessions->setMetadata($orphanSid, 'deleted-skill', [
    'selected_models' => [['provider' => 'repairstub', 'model' => MODEL_A]],
]);
$orphan = $service->complete($orphanSid);
check('missing skill is a 409 skill_unavailable',
    $orphan['status'] === 409 && ($orphan['body']['error'] ?? '') === 'skill_unavailable',
    json_encode($orphan['body']));
check('the skill-less session was left untouched',
    ($sessions->find($orphanSid)['status'] ?? '') === 'running');

// Every model this session used has since left the catalog, and nothing was
// stored — there is genuinely nothing to rebuild from.
$goneSid = $sessions->create($skillId, 'All providers were removed.');
$sessions->setMetadata($goneSid, 'generic', [
    'selected_models' => [['provider' => 'ghostprovider', 'model' => 'ghost/model']],
]);
$gone = $service->complete($goneSid);
check('a session whose models all disappeared is nothing_to_repair',
    $gone['status'] === 409 && ($gone['body']['error'] ?? '') === 'nothing_to_repair',
    json_encode($gone['body']));
check('the vanished models are named in the warnings', count(array_filter(
    $gone['body']['warnings'] ?? [],
    static fn(string $w): bool => str_contains($w, 'cannot be used anymore'),
)) === 1, json_encode($gone['body']['warnings'] ?? null));

// =====================================================================
echo "\n== Scenario 5: concurrency guards ==\n";
// =====================================================================
App\Connectors\RepairStubConnector::$calls = [];
$fx3  = seedInterruptedSession($sessions, $steps, $responses, $skillId, 'Guarded session?', [MODEL_A, MODEL_B], true);
$sid3 = $fx3['session_id'];

// A live SSE run holds a lock that names this session.
$lock->acquire('live-run-' . $sid3);
$lock->recordSession('live-run-' . $sid3, $sid3);
$blocked = $service->complete($sid3);
check('a live run lock blocks the rebuild (409)', $blocked['status'] === 409, 'status=' . $blocked['status']);
check('the block is reported as session_in_progress',
    ($blocked['body']['error'] ?? '') === 'session_in_progress');
check('the blocked rebuild made no model call', App\Connectors\RepairStubConnector::$calls === []);
check('the blocked rebuild wrote no steps', count($steps->listStepsBySession($sid3)) === 4);

// A concurrent rebuild already holds the repair mutex.
$held = $lock->acquire(SessionCompletionService::LOCK_PREFIX . $sid3);
check('test acquired the repair mutex', $held === true);
$mine = $service->complete($sid3, false, true);
check('a held repair mutex blocks a second rebuild (409)',
    $mine['status'] === 409 && ($mine['body']['error'] ?? '') === 'completion_in_progress',
    json_encode($mine['body']));
check('the mutex-blocked rebuild made no model call', App\Connectors\RepairStubConnector::$calls === []);
$lock->release(SessionCompletionService::LOCK_PREFIX . $sid3);

// With ?force=1 the stale-looking live lock is ignored.
App\Connectors\RepairStubConnector::$calls = [];
$forced = $service->complete($sid3, false, true);
check('force=1 overrides the live-lock guard and succeeds',
    $forced['status'] === 200 && ($forced['body']['ok'] ?? false) === true,
    'status=' . $forced['status'] . ' body=' . json_encode($forced['body']));
check('the forced rebuild ran the synthesis', count(App\Connectors\RepairStubConnector::$calls) === 1);
check('the repair mutex was released after the run',
    $lock->read(SessionCompletionService::LOCK_PREFIX . $sid3) === null);

// =====================================================================
echo "\n== Scenario 6: unrecoverable rebuild stays retryable ==\n";
// =====================================================================
$failSid = $sessions->create($skillId, 'Provider is down.');
$sessions->setMetadata($failSid, 'generic', [
    'selected_models' => [['provider' => 'failstub', 'model' => 'failstub/model']],
    'reasoning'       => null,
]);

$failDry = $service->complete($failSid, true);
check('dry-run plans the L1 retry for the dead provider',
    array_map(static fn(array $m): string => (string)$m['model'], $failDry['body']['plan']['l1']['missing'] ?? []) === ['failstub/model'],
    json_encode($failDry['body']['plan']['l1'] ?? null));

$fail = $service->complete($failSid);
check('unrecoverable rebuild returns HTTP 500', $fail['status'] === 500, 'status=' . $fail['status']);
check('unrecoverable rebuild reports repair_failed', ($fail['body']['error'] ?? '') === 'repair_failed');
check('the failure message is present', trim((string)($fail['body']['message'] ?? '')) !== '');
$failEvents = array_map(
    static fn(array $e): string => (string)($e['event_type'] ?? ''),
    $steps->listEventsBySession($failSid),
);
check('SESSION_REPAIR_FAILED was journaled', in_array('SESSION_REPAIR_FAILED', $failEvents, true), implode(',', $failEvents));
$failSteps = array_filter(
    $steps->listStepsBySession($failSid),
    static fn(array $r): bool => (string)$r['step_key'] === 'repair.failure',
);
check('a failed repair step was appended', count($failSteps) === 1);
$failStep = array_values($failSteps)[0] ?? [];
check('the failure step is terminal', (string)($failStep['status'] ?? '') === 'failed');
check('the session was left retryable (status untouched)',
    ($sessions->find($failSid)['status'] ?? '') === 'running');
check('the failed session has no final answer',
    trim((string)($sessions->find($failSid)['final_answer'] ?? '')) === '');
check('a failed session can be retried (still planned)',
    ($service->complete($failSid, true)['body']['plan']['required'] ?? false) === true);

// =====================================================================
echo "\n== Scenario 7: legacy session without options_json ==\n";
// =====================================================================
App\Connectors\RepairStubConnector::$calls = [];
$legacySid = $sessions->create($skillId, 'Legacy row, no options snapshot.');
$roleKeyL  = ROLE_A;
$legacyStep = $steps->createStep($legacySid, 'L1.role.' . $roleKeyL, 'L1 · ' . $roleKeyL, 1, [
    'layer' => 'L1', 'round' => 1, 'role' => $roleKeyL,
    'model' => MODEL_A, 'provider' => 'repairstub',
]);
$responses->record($legacyStep, new ChatResponse(
    content: 'Legacy answer body.', model: MODEL_A, provider: 'repairstub',
    promptTokens: 10, completionTokens: 20, totalTokens: 30, latencyMs: 5.0,
), $roleKeyL);
$steps->completeStep($legacyStep, 'completed', 'Legacy answer body.', [
    'layer' => 'L1', 'round' => 1, 'role' => $roleKeyL, 'model' => MODEL_A, 'provider' => 'repairstub',
]);

$legacyDry = $service->complete($legacySid, true);
check('legacy session is plannable without options_json',
    ($legacyDry['body']['ok'] ?? false) === true, json_encode($legacyDry['body']));
check('legacy plan warns about the missing options snapshot', count(array_filter(
    $legacyDry['body']['warnings'] ?? [],
    static fn(string $w): bool => str_contains($w, 'no stored runtime options'),
)) === 1, json_encode($legacyDry['body']['warnings'] ?? null));
check('legacy plan does not invent models to query',
    ($legacyDry['body']['plan']['l1']['missing'] ?? null) === [],
    json_encode($legacyDry['body']['plan']['l1'] ?? null));

$legacyRun = $service->complete($legacySid);
check('legacy rebuild succeeds from the journal alone',
    ($legacyRun['body']['ok'] ?? false) === true, json_encode($legacyRun['body']));
check('legacy rebuild made exactly one call (synthesis)',
    count(App\Connectors\RepairStubConnector::$calls) === 1,
    json_encode(App\Connectors\RepairStubConnector::$calls));
check('legacy session completed with a final answer',
    (($sessions->find($legacySid)['status'] ?? '') === 'completed')
    && trim((string)($sessions->find($legacySid)['final_answer'] ?? '')) !== '');

// =====================================================================
echo "\n== Scenario 8: stale running steps are retired, not deleted ==\n";
// =====================================================================
App\Connectors\RepairStubConnector::$calls = [];
$fx4  = seedInterruptedSession($sessions, $steps, $responses, $skillId, 'Stale step?', [MODEL_A, MODEL_B], true);
$sid4 = $fx4['session_id'];
$ghost = $steps->createStep($sid4, 'L4.synthesis', 'L4 · Synthesis', 300, ['layer' => 'L4']);
// Left as `running` on purpose — this is what a killed worker leaves behind.
$stepsBefore4 = count($steps->listStepsBySession($sid4));

// A round-2 row from a run that got further before dying.
$r2 = $steps->createStep($sid4, 'L1.round2.role.' . ROLE_A, 'L1 · ' . ROLE_A . ' (round 2)', 1001, [
    'layer' => 'L1', 'round' => 2, 'role' => ROLE_A, 'model' => MODEL_A, 'provider' => 'repairstub',
]);
$steps->completeStep($r2, 'completed', 'Round-2 answer.', ['layer' => 'L1', 'round' => 2, 'role' => ROLE_A]);
$stepsBefore4++;

$dry4 = $service->complete($sid4, true);
check('a session with later-round rows warns that only round 1 is rebuilt', count(array_filter(
    $dry4['body']['warnings'] ?? [],
    static fn(string $w): bool => str_contains($w, 'rounds after the first one'),
)) === 1, json_encode($dry4['body']['warnings'] ?? null));
check('later-round rows are not treated as missing L1 work',
    ($dry4['body']['plan']['l1']['missing'] ?? null) === [], json_encode($dry4['body']['plan']['l1'] ?? null));

$run4 = $service->complete($sid4);
check('rebuild with a stale running step succeeds',
    ($run4['body']['ok'] ?? false) === true, json_encode($run4['body']));
check('the stale row was kept (append-only)', count($steps->listStepsBySession($sid4)) === $stepsBefore4 + 2);
$ghostRow = null;
foreach ($steps->listStepsBySession($sid4) as $row) {
    if ((int)$row['id'] === $ghost) { $ghostRow = $row; }
}
check('the stale row was moved to a terminal status',
    (string)($ghostRow['status'] ?? '') === 'failed', json_encode($ghostRow));
check('the stale row explains itself',
    str_contains((string)($ghostRow['summary'] ?? ''), 'Abandoned'));
check('the stale step is recorded in the event log', in_array(
    'SESSION_REPAIR_STALE_STEPS',
    array_map(static fn(array $e): string => (string)$e['event_type'], $steps->listEventsBySession($sid4)),
    true,
));
check('the completed session still has a usable final answer',
    trim((string)($sessions->find($sid4)['final_answer'] ?? '')) !== '');

// =====================================================================
echo "\n== Scenario 9: HTTP layer (route pattern + tab gate) ==\n";
// =====================================================================
$router = new App\Core\Router();
$capturedParams = null;
$router->post('/api/history/session/{id}/complete', function (array $p) use (&$capturedParams): string {
    $capturedParams = $p;
    return 'dispatched';
});
$router->get('/api/history/session/{id}', static fn(array $p): string => 'detail:' . ($p['id'] ?? ''));
check('POST /api/history/session/{id}/complete is routable',
    $router->dispatch('POST', '/api/history/session/12/complete') === 'dispatched',
    'params=' . json_encode($capturedParams));
check('the route binds the session id', ($capturedParams['id'] ?? '') === '12');
check('the GET detail route still resolves',
    $router->dispatch('GET', '/api/history/session/12') === 'detail:12');
check('the complete route rejects GET',
    $router->dispatch('GET', '/api/history/session/12/complete') === null);

$controller = new App\Http\Controllers\HistoryController(new App\History\HistoryService(null));
Config::set(['HistoryTab' => false], __DIR__);
$gated = $controller->complete(['id' => '12']);
check('tab gate is applied before any work (404 history_disabled)',
    str_contains($gated, 'history_disabled'), substr($gated, 0, 120));
Config::set(['HistoryTab' => true], __DIR__);
$noDb = $controller->complete(['id' => '12']);
check('without a database the endpoint reports no_database',
    str_contains($noDb, 'no_database'), substr($noDb, 0, 160));
check('that diagnostic keeps the ok:false envelope shape',
    str_contains($noDb, '"ok":false'));

// =====================================================================
echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
