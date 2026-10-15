<?php
declare(strict_types=1);
/**
 * BatchAttachmentsTest: query attachments reach the Batch API items.
 *
 * The batch path shares the engine's message builders, so the property worth
 * proving is precise: when the job's batch options carry validated
 * attachments, EVERY L1 batch item carries the same [ATTACHED DOCUMENTS ...]
 * block the synchronous path renders, ahead of the query - and when they do
 * not, or when the options hold junk, no block appears and nothing crashes.
 * Runs offline on in-memory repositories, like BatchPlanTest.
 *
 *   php tests/BatchAttachmentsTest.php
 */
require __DIR__ . '/../src/autoload.php';

use App\Batch\BatchL1PlannerInterface;
use App\Cache\ModelResponseCache;
use App\Connectors\ConnectorRegistry;
use App\Consensus\ConsensusEngine;
use App\Consensus\NormalizationService;
use App\Core\Config;
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
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failures++; }
}

Config::set([
    'app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false],
    'connectors' => [
        'bothub' => ['active' => true, 'api_key' => 'fixture-key', 'base_url' => 'https://bothub.chat/api/v2/openai/v1'],
    ],
    'models' => [
        ['provider' => 'bothub', 'model' => 'gpt-4o:batch', 'active' => false, 'batch_only' => true],
    ],
    'pipeline' => ['max_roles_per_skill' => 12, 'default_selected_models' => []],
]);

$connectors = new ConnectorRegistry(__DIR__ . '/../src/Connectors');
$sessions   = new SessionRepository(null);
$steps      = new StepRepository(null);
$responses  = new ResponseRepository(null);
$skills     = new SkillRepository(null);

// The query below starts with the word this skill is built from, which keeps
// SkillRouter::resolve() local (see BatchPlanTest for the full reasoning).
$skills->save(new \App\Skills\Skill(
    id:          null,
    slug:        'reasoning',
    name:        'reasoning',
    description: 'reasoning',
    definition:  [
        'roles' => [
            ['key' => 'analyst', 'name' => 'Analyst', 'system_prompt' => 'You analyse carefully.'],
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

check('the engine implements the batch planner contract', $engine instanceof BatchL1PlannerInterface);
check('the bothub connector is available offline', $connectors->hasProvider('bothub'), implode(',', $connectors->skipped()));

/** The user message of a participant body, or ''. */
function userMessage(array $participant): string
{
    $messages = is_array($participant['body']['messages'] ?? null) ? $participant['body']['messages'] : [];
    foreach ($messages as $message) {
        if (($message['role'] ?? '') === 'user') {
            return (string)($message['content'] ?? '');
        }
    }
    return '';
}

$content = "# Harbour Brief\n\nThe internal code word is BLUE-HARBOUR-42.\n";
$items   = [['name' => 'brief.md', 'chars' => mb_strlen($content), 'content' => $content]];
$query   = 'reasoning check the attached document';

// ---- with attachments --------------------------------------------------------
$sessionAttached = $sessions->create(skillId: 0, query: $query, userId: null);
$planAttached = $engine->prepareBatchL1(
    sessionId:       $sessionAttached,
    query:           $query,
    requestedModels: [['provider' => 'bothub', 'model' => 'gpt-4o:batch']],
    options:         ['attachments' => $items],
    jobId:           5151,
);
check('a plan with attachments is usable', ($planAttached['ok'] ?? false) === true, (string)($planAttached['reason'] ?? ''));
check('one participant planned', count($planAttached['participants'] ?? []) === 1);
$userAttached = userMessage($planAttached['participants'][0] ?? []);
check('the user message carries the block', str_contains($userAttached, '[ATTACHED DOCUMENTS'));
check('and the document contents', str_contains($userAttached, 'BLUE-HARBOUR-42'));
check('and names the document', str_contains($userAttached, '--- document 1: brief.md'));
check('the block closes before the query', (function () use ($userAttached, $query): bool {
    $blockEnd = strpos($userAttached, '--- end of attachments ---');
    $queryAt  = strpos($userAttached, $query);
    return $blockEnd !== false && $queryAt !== false && $blockEnd < $queryAt;
})());
check('the system message stays first and untouched',
    (($planAttached['participants'][0]['body']['messages'][0]['role'] ?? '') === 'system'
    && !str_contains((string)($planAttached['participants'][0]['body']['messages'][0]['content'] ?? ''), 'ATTACHED DOCUMENTS')));

// ---- without attachments -----------------------------------------------------
$sessionPlain = $sessions->create(skillId: 0, query: $query, userId: null);
$planPlain = $engine->prepareBatchL1(
    sessionId:       $sessionPlain,
    query:           $query,
    requestedModels: [['provider' => 'bothub', 'model' => 'gpt-4o:batch']],
    options:         [],
    jobId:           5152,
);
check('a plan without attachments is usable', ($planPlain['ok'] ?? false) === true);
check('and carries no block', !str_contains(userMessage($planPlain['participants'][0] ?? []), 'ATTACHED DOCUMENTS'));

// ---- junk in the options is ignored safely -----------------------------------
$sessionJunk = $sessions->create(skillId: 0, query: $query, userId: null);
$planJunk = $engine->prepareBatchL1(
    sessionId:       $sessionJunk,
    query:           $query,
    requestedModels: [['provider' => 'bothub', 'model' => 'gpt-4o:batch']],
    options:         ['attachments' => [['name' => 'a.txt']]],
    jobId:           5153,
);
check('an attachment missing its shape neither crashes nor appears',
    ($planJunk['ok'] ?? false) === true
    && !str_contains(userMessage($planJunk['participants'][0] ?? []), 'ATTACHED DOCUMENTS'));
$sessionEnvelope = $sessions->create(skillId: 0, query: $query, userId: null);
$planEnvelope = $engine->prepareBatchL1(
    sessionId:       $sessionEnvelope,
    query:           $query,
    requestedModels: [['provider' => 'bothub', 'model' => 'gpt-4o:batch']],
    options:         ['attachments' => ['items' => $items, 'errors' => []]],
    jobId:           5154,
);
check('the validation envelope (items/errors) is not mistaken for items',
    ($planEnvelope['ok'] ?? false) === true
    && !str_contains(userMessage($planEnvelope['participants'][0] ?? []), 'ATTACHED DOCUMENTS'));

echo "\n----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
