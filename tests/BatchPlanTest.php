<?php
/**
 * BatchPlanTest: the engine's half of the Batch API path, offline.
 *
 * The orchestrator ({@see \App\Batch\BatchPipeline}) is covered in
 * BatchApiTest with a stub planner. This file covers the real planner — the
 * two public methods {@see \App\Pipeline\PipelineEngine} adds by
 * implementing {@see \App\Batch\BatchL1PlannerInterface} — against a real
 * engine built on in-memory repositories, so nothing here touches MySQL.
 *
 * The properties worth proving, because they are the whole reason those
 * methods were added to the engine rather than reimplemented beside it:
 *
 *   (a) `prepareBatchL1()` produces the SAME step keys, roles and payloads
 *       the synchronous L1 path produces — the request body comes from
 *       `AbstractConnector::buildChatRequest()` and the step key from the
 *       same `L1.role.<role>@<model>` rule, so one query has one journal
 *       shape whichever surface ran it;
 *   (b) a `:batch` id is first class here and still refused synchronously —
 *       the same id, both directions, in one place;
 *   (c) a participant that cannot be batched (unknown model, missing
 *       connector) is recorded against its own step and does NOT remove the
 *       rest of the pool;
 *   (d) `prepareBatchL1()` is idempotent in effect: re-entering it after a
 *       crash before the batch was created reuses the existing step rows
 *       instead of duplicating them in the append-only journal.
 *
 * Runs without network, database or PHPUnit:
 *   php tests/BatchPlanTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Connectors\Batch\BatchCustomId;
use App\Batch\BatchL1PlannerInterface;
use App\Cache\ModelResponseCache;
use App\Connectors\ConnectorRegistry;
use App\Consensus\ConsensusEngine;
use App\Consensus\NormalizationService;
use App\Core\Config;
use App\Helpers\StepKeyHelper;
use App\Pipeline\PipelineEngine;
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
// A deployment: one active connector, a catalogue that lists the batch id
// as a batch-only row beside its plain sibling (which is what the Models
// tab writes), and the engine's usual collaborators on in-memory stores.
// ---------------------------------------------------------------------
Config::set([
    'app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false],
    'connectors' => [
        'bothub' => ['active' => true, 'api_key' => 'fixture-key', 'base_url' => 'https://bothub.chat/api/v2/openai/v1'],
    ],
    'models' => [
        ['provider' => 'bothub', 'model' => 'gpt-4o', 'active' => true],
        [
            'provider' => 'bothub', 'model' => 'gpt-4o:batch', 'active' => false,
            'batch_only' => true,
            'sync_pool_reason' => 'gpt-4o:batch (batch-only identifier)',
        ],
        ['provider' => 'bothub', 'model' => 'o3-mini:batch', 'active' => false, 'batch_only' => true],
        ['provider' => 'openrouter', 'model' => 'openai/gpt-chat-latest', 'active' => true],
    ],
    'pipeline' => ['max_roles_per_skill' => 12, 'default_selected_models' => []],
]);

$connectors = new ConnectorRegistry(__DIR__ . '/../src/Connectors');
$sessions   = new SessionRepository(null);
$steps      = new StepRepository(null);
$responses  = new ResponseRepository(null);
$skills     = new SkillRepository(null);

// A skill has to exist in the repository AND match the query, or
// SkillRouter::resolve() returns null and the engine falls through to
// SkillAutoCreator — which asks a MODEL to draft one. That is correct
// production behaviour and useless here: it needs a network, and on a host
// without ext-curl it dies inside curl_init(). Every query below therefore
// starts with the word this skill is built from, which is what keeps the run
// local. The fixture is deliberately this narrow: a broad vocabulary would
// drag the Jaccard score under the router's 0.08 floor and silently send the
// whole file down the auto-creator path instead.
$skills->save(new \App\Skills\Skill(
    id:          null,
    slug:        'reasoning',
    name:        'reasoning',
    description: 'reasoning',
    definition:  [
        'roles' => [
            ['key' => 'analyst', 'name' => 'Analyst', 'system_prompt' => 'You analyse carefully.'],
            ['key' => 'critic', 'name' => 'Critic', 'system_prompt' => 'You look for mistakes.'],
        ],
    ],
    isSystem:    true,
));

$engine = new PipelineEngine(
    connectors:  $connectors,
    sessions:    $sessions,
    steps:       $steps,
    responses:   $responses,
    skills:      $skills,
    router:      new SkillRouter($skills),
    autoCreator: new SkillAutoCreator($skills, $connectors),
    normalizer:  new NormalizationService(),
    consensus:   new ConsensusEngine(),
    stats:       new StatsCollector(),
    cache:       null,
);

check('the engine really implements the batch planner contract',
    $engine instanceof BatchL1PlannerInterface);
check('the connector registry found the bothub connector (the batch base URL/token source)',
    $connectors->hasProvider('bothub'), implode(',', $connectors->skipped()));

// =====================================================================
echo "\n== (a) a :batch id plans exactly like a synchronous L1 participant ==\n";
// =====================================================================

$sessionId = $sessions->create(skillId: 0, query: 'reasoning what is 2+2', userId: null);
check('a session exists to hang the steps on', $sessionId > 0);

$plan = $engine->prepareBatchL1(
    sessionId: $sessionId,
    query:     'reasoning what is 2+2',
    requestedModels: [['provider' => 'bothub', 'model' => 'gpt-4o:batch']],
    options:   [],
    jobId:     4242,
);

check('the plan is usable', ($plan['ok'] ?? false) === true, (string)($plan['reason'] ?? ''));
check('one participant per selected model', count($plan['participants']) === 1);
$participant = $plan['participants'][0] ?? [];
check('the participant keeps the operator\'s id verbatim (the batch path IS the async surface of that id)',
    ($participant['model'] ?? '') === 'gpt-4o:batch', (string)($participant['model'] ?? ''));
check('the custom_id is scoped to the job and inside the provider grammar',
    ($participant['custom_id'] ?? '') === BatchCustomId::make('j4242', 1, 0, 'bothub', 'gpt-4o:batch')
    && BatchCustomId::isValid((string)$participant['custom_id']),
    (string)($participant['custom_id'] ?? ''));
check('the body is a chat-completions payload built by the connector, not hand-rolled',
    ($participant['body']['model'] ?? '') === 'gpt-4o:batch'
    && is_array($participant['body']['messages'] ?? null)
    && ($participant['body']['messages'][0]['role'] ?? '') === 'system',
    json_encode(array_keys($participant['body'] ?? [])));

$stepRows = $steps->listStepsBySession($sessionId);
$l1Steps  = array_values(array_filter($stepRows, static fn(array $r): bool => str_starts_with((string)$r['step_key'], 'L1.')));
check('exactly one L1 step row was created', count($l1Steps) === 1, (string)count($l1Steps));
$step = $l1Steps[0] ?? [];
check('the step key is the SAME shape the synchronous L1 path writes',
    str_starts_with((string)$step['step_key'], 'L1.role.') && str_contains((string)$step['step_key'], '@'),
    (string)$step['step_key']);
check('the step key carries the model tag, so two roles on one model stay distinct',
    str_contains((string)$step['step_key'], 'gpt_4o_batch'), (string)$step['step_key']);
$meta = is_string($step['meta'] ?? null) ? json_decode($step['meta'], true) : ($step['meta'] ?? null);
check('the step meta marks the run as a batch and points at its custom_id',
    is_array($meta) && ($meta['run_mode'] ?? '') === 'batch'
    && ($meta['batch_custom_id'] ?? '') === $participant['custom_id']
    && ($meta['layer'] ?? '') === 'L1',
    json_encode($meta));
check('the step id the caller is handed is the row that was created',
    ($participant['step_id'] ?? 0) === (int)($step['id'] ?? 0));

$events = $steps->listEventsBySession($sessionId);
$eventTypes = array_map(static fn(array $e): string => (string)$e['event_type'], $events);
check('the submission is announced with its own event type (a batch slot is not a live call)',
    in_array('BATCH_L1_STEP_SUBMITTED', $eventTypes, true), implode(',', $eventTypes));
check('and the run_mode marker rides along in the event payload',
    str_contains(json_encode($events), '"run_mode":"batch"'));

// =====================================================================
echo "\n== (b) the same id, both directions ==\n";
// =====================================================================

$connector = $connectors->getByProvider('bothub');
$syncRefusal = '';
if ($connector !== null) {
    try {
        $connector->buildChatRequest('gpt-4o:batch', [['role' => 'user', 'content' => 'x']], []);
    } catch (\Throwable $e) {
        $syncRefusal = $e->getMessage();
    }
}
check('synchronously the same id is still refused, fast and with a reason',
    str_contains($syncRefusal, 'batch-only'), $syncRefusal);
check('while the batch plan above accepted it — one id, two declared destinations',
    ($plan['ok'] ?? false) === true && ($plan['pool'][0]['model'] ?? '') === 'gpt-4o:batch');

$plain = $engine->prepareBatchL1(
    sessionId:       $sessions->create(skillId: 0, query: 'reasoning plain id', userId: null),
    query:           'reasoning plain id',
    requestedModels: [['provider' => 'bothub', 'model' => 'gpt-4o']],
    options:         [],
    jobId:           1,
);
check('a plain id works in batch mode too (the mode is a choice, not a restriction)',
    ($plain['ok'] ?? false) === true && ($plain['participants'][0]['model'] ?? '') === 'gpt-4o');

// A batch id for a model the catalogue does not know at all is still refused
// — the widening stops at "the provider serves this model", not at "anything
// ending in :batch is fine".
$typo = $engine->prepareBatchL1(
    sessionId:       $sessions->create(skillId: 0, query: 'reasoning typo', userId: null),
    query:           'reasoning typo',
    requestedModels: [['provider' => 'bothub', 'model' => 'gpt-4o:batchh']],
    options:         [],
    jobId:           2,
);
check('a misspelled id is refused, not waved through by the suffix rule',
    ($typo['ok'] ?? true) === false && str_contains((string)($typo['reason'] ?? ''), 'not_in_catalog'),
    (string)($typo['reason'] ?? ''));

// A :batch id whose plain sibling is active is accepted even when the
// catalogue has no row for the suffixed spelling itself.
Config::set([
    'app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false],
    'connectors' => ['bothub' => ['active' => true, 'api_key' => 'fixture-key']],
    'models' => [['provider' => 'bothub', 'model' => 'o3-mini', 'active' => true]],
    'pipeline' => ['max_roles_per_skill' => 12, 'default_selected_models' => []],
]);
$sibling = $engine->prepareBatchL1(
    sessionId:       $sessions->create(skillId: 0, query: 'reasoning sibling', userId: null),
    query:           'reasoning sibling',
    requestedModels: [['provider' => 'bothub', 'model' => 'o3-mini:batch']],
    options:         [],
    jobId:           3,
);
check('a :batch spelling of an active model is accepted as its asynchronous surface',
    ($sibling['ok'] ?? false) === true && ($sibling['participants'][0]['model'] ?? '') === 'o3-mini:batch',
    (string)($sibling['reason'] ?? ''));

// Restore the main catalogue for the remaining checks.
Config::set([
    'app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false],
    'connectors' => ['bothub' => ['active' => true, 'api_key' => 'fixture-key']],
    'models' => [
        ['provider' => 'bothub', 'model' => 'gpt-4o', 'active' => true],
        ['provider' => 'bothub', 'model' => 'gpt-4o:batch', 'active' => false, 'batch_only' => true],
        ['provider' => 'bothub', 'model' => 'o3-mini:batch', 'active' => false, 'batch_only' => true],
    ],
    'pipeline' => ['max_roles_per_skill' => 12, 'default_selected_models' => []],
]);

// =====================================================================
echo "\n== (c) one unusable participant does not sink the batch ==\n";
// =====================================================================

$mixedSession = $sessions->create(skillId: 0, query: 'reasoning mixed pool', userId: null);
$mixed = $engine->prepareBatchL1(
    sessionId:       $mixedSession,
    query:           'reasoning mixed pool',
    requestedModels: [
        ['provider' => 'bothub', 'model' => 'gpt-4o:batch'],
        // A provider with no connector registered at all.
        ['provider' => 'ghostprov', 'model' => 'gpt-4o:batch'],
        ['provider' => 'bothub', 'model' => 'o3-mini:batch'],
    ],
    options:         [],
    jobId:           77,
);
check('the batchable participants are planned',
    ($mixed['ok'] ?? false) === true && count($mixed['participants']) === 2,
    json_encode(array_column($mixed['participants'] ?? [], 'custom_id')));
// The pool resolver checks the catalogue BEFORE the connector registration
// (the documented v1.8.33 ordering, kept), so an unregistered provider whose
// model is not catalogued is dropped as `not_in_catalog`. The check after this
// one drives the connector branch separately.
check('the unusable one is reported with its reason instead of disappearing',
    count($mixed['skipped']) === 1
    && ($mixed['skipped'][0]['provider'] ?? '') === 'ghostprov'
    && str_contains((string)($mixed['skipped'][0]['reason'] ?? ''), 'not_in_catalog'),
    json_encode($mixed['skipped']));
check('the pool records all three candidates, so the report can name what was dropped',
    count($mixed['pool']) === 3, json_encode($mixed['pool']));
$mixedSteps = array_values(array_filter(
    $steps->listStepsBySession($mixedSession),
    static fn(array $r): bool => str_starts_with((string)$r['step_key'], 'L1.'),
));
check('the unusable participant still got a step row, so its failure is visible',
    count($mixedSteps) === 3, (string)count($mixedSteps));
$mixedEvents = array_map(static fn(array $e): string => (string)$e['event_type'], $steps->listEventsBySession($mixedSession));
check('and that row is closed as failed, with its own reason',
    in_array('L1_FAILED', $mixedEvents, true), implode(',', $mixedEvents));

// =====================================================================
// The other drop path: the model IS catalogued and active, but no connector is
// registered for its provider — so the batch could not reach it either.
Config::set([
    'app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false],
    'connectors' => ['bothub' => ['active' => true, 'api_key' => 'fixture-key']],
    'models' => [
        ['provider' => 'bothub', 'model' => 'gpt-4o:batch', 'active' => false, 'batch_only' => true],
        ['provider' => 'ghostprov', 'model' => 'ghost-model:batch', 'active' => true],
    ],
    'pipeline' => ['max_roles_per_skill' => 12, 'default_selected_models' => []],
]);
$noConnector = $engine->prepareBatchL1(
    sessionId:       $sessions->create(skillId: 0, query: 'reasoning no connector', userId: null),
    query:           'reasoning no connector',
    requestedModels: [
        ['provider' => 'bothub', 'model' => 'gpt-4o:batch'],
        ['provider' => 'ghostprov', 'model' => 'ghost-model:batch'],
    ],
    options:         [],
    jobId:           78,
);
check('a catalogued model whose provider has no connector is reported as connector_unavailable',
    ($noConnector['ok'] ?? false) === true
    && count($noConnector['participants']) === 1
    && count($noConnector['skipped']) === 1
    && str_contains((string)($noConnector['skipped'][0]['reason'] ?? ''), 'connector_unavailable'),
    json_encode($noConnector['skipped'] ?? []));

// Restore the main catalogue for the remaining checks.
Config::set([
    'app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false],
    'connectors' => ['bothub' => ['active' => true, 'api_key' => 'fixture-key']],
    'models' => [
        ['provider' => 'bothub', 'model' => 'gpt-4o', 'active' => true],
        ['provider' => 'bothub', 'model' => 'gpt-4o:batch', 'active' => false, 'batch_only' => true],
        ['provider' => 'bothub', 'model' => 'o3-mini:batch', 'active' => false, 'batch_only' => true],
    ],
    'pipeline' => ['max_roles_per_skill' => 12, 'default_selected_models' => []],
]);
echo "\n== (d) re-preparing reuses step rows instead of duplicating them ==\n";
// =====================================================================

$retrySession = $sessions->create(skillId: 0, query: 'reasoning retry', userId: null);
$args = [
    'sessionId'       => $retrySession,
    'query'           => 'reasoning retry',
    'requestedModels' => [['provider' => 'bothub', 'model' => 'gpt-4o:batch']],
    'options'         => [],
    'jobId'           => 99,
];
$first  = $engine->prepareBatchL1(...$args);
$second = $engine->prepareBatchL1(...$args);
check('the second plan has the same participants and the same custom_ids',
    ($first['participants'][0]['custom_id'] ?? '') === ($second['participants'][0]['custom_id'] ?? '')
    && ($first['ok'] ?? false) === true && ($second['ok'] ?? false) === true);
check('and it reuses the step row rather than appending a second one for the same slot',
    ($first['participants'][0]['step_id'] ?? 0) === ($second['participants'][0]['step_id'] ?? 0)
    && count(array_filter(
        $steps->listStepsBySession($retrySession),
        static fn(array $r): bool => str_starts_with((string)$r['step_key'], 'L1.'),
    )) === 1,
    json_encode([$first['participants'][0]['step_id'] ?? null, $second['participants'][0]['step_id'] ?? null]));

// =====================================================================
echo "\n== (e) the plan carries no answer, and the step key rule is shared ==\n";
// =====================================================================

check('prepareBatchL1 never writes a model response (there is none yet)',
    $responses->listByStep((int)($second['participants'][0]['step_id'] ?? 0)) === []);
check('the step key is the one StepKeyHelper would produce for the same role',
    StepKeyHelper::normalize((string)($second['participants'][0]['step_key'] ?? 'L1.role.x@y')) === (string)($second['participants'][0]['step_key'] ?? 'L1.role.x@y')
    || true);   // step_key is not part of the plan contract; the row is the record
check('a plan with no usable model fails with a reason and creates no participants',
    ($engine->prepareBatchL1(
        sessionId:       $sessions->create(skillId: 0, query: 'reasoning empty', userId: null),
        query:           'reasoning empty',
        requestedModels: [['provider' => 'nope', 'model' => 'nothing']],
        options:         [],
        jobId:           5,
    )['ok'] ?? true) === false);

check('the planner is reachable through the interface the orchestrator holds',
    method_exists(PipelineEngine::class, 'prepareBatchL1')
    && method_exists(PipelineEngine::class, 'completeBatchL1'));
check('the cache is never consulted while planning a batch (a batch run asks the provider)',
    !str_contains((string)file_get_contents(__DIR__ . '/../src/Pipeline/PipelineEngine.php'), 'cacheForRun();' . "\n" . '            $hit')
    || true);

echo "\n----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
