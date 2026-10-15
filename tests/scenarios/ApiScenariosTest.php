<?php
/**
 * ApiScenariosTest: Stage-3 scenario tests for the external REST API v1
 * (Task: REST API, Step 10).
 *
 * Runs without PHPUnit, a database or network access:
 * `php tests/scenarios/ApiScenariosTest.php`.
 *
 * Each scenario walks the app like an agent would: through the public
 * controller entry points and the delivery layer, not private helpers.
 *
 *   Scenario 1 - Native synchronous run: full key, async=false -> 200
 *                with completed status, inline final_answer and usage.
 *   Scenario 2 - Async launch -> events polling -> result: default 202
 *                enqueue, event cursor resume, 202-while-queued result
 *                poll, terminal 200 after the worker records the result.
 *   Scenario 3 - OpenAI dialect round-trip: chat.completion-shaped
 *                launch and read responses, SDK-style errors.
 *   Scenario 4 - Error states: 401/403/404 deny-list/429 with
 *                Retry-After through the public ApiAuth gate.
 *   Scenario 5 - Webhook delivery and retry: HMAC-signed delivery,
 *                signature verification, idempotent retry replay.
 *
 * NOTE: this file deliberately prints nothing until the very end.
 * Any output before http_response_code() is consulted makes PHP CLI
 * consider headers sent and the status code checks silently fail.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  8.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

require __DIR__ . '/../../src/autoload.php';

use App\Api\ApiAuth;
use App\Api\ApiKeyRepository;
use App\Api\ApiKeyService;
use App\Api\ApiRateLimiter;
use App\Api\ApiV1Controller;
use App\Api\FormatAdapter;
use App\Api\WebhookDelivery;
use App\Api\WebhookDeliveryRepository;
use App\Api\WebhookDispatcher;
use App\Api\WebhookRepository;
use App\Api\WebhookService;
use App\Connectors\ChatResponse;
use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Connectors\EmbedResponse;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Consensus\ConsensusEngine;
use App\Core\Config;
use App\Helpers\TokenHelper;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\JobStatus;
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
$report   = [];

function checkSc(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes, $report;
    if ($cond) {
        $report[] = "  ok  {$label}";
        $passes++;
    } else {
        $report[] = "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '');
        $failures++;
    }
}

function decodeJson(string $body, string $label): array
{
    $decoded = json_decode($body, true);
    checkSc($label . ' is valid JSON', is_array($decoded), $body === '' ? 'empty body' : substr($body, 0, 160));
    return is_array($decoded) ? $decoded : [];
}

function resetRequestEnv(): void
{
    $_SERVER = [];
    $_GET    = [];
    if (function_exists('http_response_code')) {
        http_response_code(200);
    }
}

// --------------------------------------------------------------
// Shared wiring (memory-mode repositories, just like in prod).
// --------------------------------------------------------------
$tmpDir  = sys_get_temp_dir() . '/oc-scen-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0o700, true);
$lockBase = sys_get_temp_dir() . '/oc-scen-locks-' . bin2hex(random_bytes(4));
mkdir($lockBase, 0o700, true);
mkdir($tmpDir . '/limits', 0o700, true);

file_put_contents($tmpDir . '/ScenarioStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\ChatResponse;
use App\Connectors\ConnectorInterface;
use App\Connectors\EmbedResponse;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;
final class ScenarioStubConnector implements ConnectorInterface {
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        $question = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') { $question = (string)($m['content'] ?? ''); break; }
        }
        $content = '[stub] ' . $question . ' Consensus reached by the scenario council.';
        $pt = TokenHelper::estimateMessageTokens($messages);
        $ct = TokenHelper::estimateTokens($content);
        return new ChatResponse($content, $model, 'stub', $pt, $ct, $pt + $ct, 1.0, ['stub' => true], true);
    }
    public function embed(string $model, string $text): EmbedResponse { throw new UnsupportedOperationException('stub'); }
    public function listModels(): array { return ['stub/echo']; }
    public function getProviderName(): string { return 'stub'; }
    public function isAvailable(): bool { return true; }
}
PHP);

$registry = new ConnectorRegistry($tmpDir);
$registry->load();

Config::set([
    'app'        => ['debug' => false, 'version' => '1.0.0', 'name' => 'RevoAp'],
    'connectors' => ['stub' => ['active' => true, 'api_key' => 'x']],
    'models'     => [['provider' => 'stub', 'model' => 'stub/echo', 'active' => true]],
    'pipeline'   => [
        'synthesis_provider'  => 'stub',
        'synthesis_model'     => 'stub/echo',
        'max_roles_per_skill' => 5,
        'parallel_l1'         => false,
    ],
    'webhooks'   => [
        'retry_backoff_seconds' => [1],
        'max_attempts'          => 3,
        'timeout_seconds'       => 1,
        'allow_private_urls'    => false,
    ],
], __DIR__);

$skills = new SkillRepository(null);
$skills->save(new Skill(
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
    normalizer:  new App\Consensus\NormalizationService(),
    consensus:   new ConsensusEngine(),
    stats:       new StatsCollector(),
);

$jobRepo = new JobRepository(null);
$jobSvc  = new JobService($jobRepo, $sessions);

$keyRepo = new ApiKeyRepository(null);
$keySvc  = new ApiKeyService($keyRepo);
$limiter = new ApiRateLimiter($tmpDir . '/limits');
$auth    = new ApiAuth($keySvc, $limiter);

$ctrl = new ApiV1Controller(
    $auth,
    new FormatAdapter(),
    $jobSvc,
    $engine,
    $sessions,
    $steps,
    null,
    new StreamRunLock($lockBase),
);

$genFull = $keySvc->generate('Scenario Full', 'full', 500, 10000);
$fullPlain = $genFull['plaintext'];
$genRead = $keySvc->generate('Scenario Read', 'read', 500, 10000);
$readPlain = $genRead['plaintext'];

// ==============================================================
// Scenario 1 - Native synchronous run
//   Given a full-scope key and a native body with async=false,
//   When the agent posts /v1/runs,
//   Then the pipeline runs inline and the record carries the final
//   answer, consensus, usage and polling urls.
// ==============================================================
$report[] = 'Scenario 1 - Native synchronous run';
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest([
    'query'           => 'Which consensus wins this sync run?',
    'async'           => false,
    'selected_models' => [['provider' => 'stub', 'model' => 'stub/echo']],
]);
$r = decodeJson($ctrl->createRun(), 's1 response');
checkSc('s1: 200 OK', http_response_code() === 200);
checkSc('s1: status completed', ($r['status'] ?? '') === 'completed');
checkSc('s1: final_answer inline', is_string($r['final_answer'] ?? null)
    && str_contains((string)$r['final_answer'], '[stub]'));
checkSc('s1: usage filled', (int)($r['usage']['total_tokens'] ?? 0) > 0);
checkSc('s1: polling urls', isset($r['events_url'], $r['result_url'], $r['cancel_url']));
$s1Session = (int)($r['session_id'] ?? 0);
checkSc('s1: run_id matches session', ($r['run_id'] ?? '') === 'run_' . $s1Session);
$ctrl->setBodyForTest(null);

// ==============================================================
// Scenario 2 - Async launch -> events polling -> result
//   Given a full-scope key and an async (default) body,
//   When the agent enqueues, lists runs, polls events with a cursor
//   and then the result endpoint,
//   Then 202 yields queued + ids; events arrive in order with cursor
//   resume; result poll answers 202 until the worker records the
//   result, then 200 completed with final_answer/usage/consensus.
// ==============================================================
$report[] = 'Scenario 2 - Async launch -> events polling -> result';
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest([
    'query'           => 'Poll this async run to completion.',
    'selected_models' => [['provider' => 'stub', 'model' => 'stub/echo']],
]);
$r = decodeJson($ctrl->createRun(), 's2 launch response');
checkSc('s2: 202 Accepted', http_response_code() === 202);
checkSc('s2: status queued + ids', ($r['status'] ?? '') === 'queued'
    && (int)($r['session_id'] ?? 0) > 0 && (int)($r['job_id'] ?? 0) > 0);
$s2Session = (int)($r['session_id'] ?? 0);
$s2Job     = (int)($r['job_id'] ?? 0);
$ctrl->setBodyForTest(null);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRun(['id' => (string)$s2Session]), 's2 getRun queued');
checkSc('s2: run record queued', http_response_code() === 200
    && ($r['status'] ?? '') === 'queued' && (int)($r['job_id'] ?? 0) === $s2Job);

$steps->appendEvent($s2Session, null, 'PIPELINE_STARTED', ['query' => 'Poll this async run to completion.']);
$steps->appendEvent($s2Session, null, 'L1_RESPONSE', ['role' => 'analyst', 'model' => 'stub/echo']);
$steps->appendEvent($s2Session, null, 'PIPELINE_DONE', ['final_answer' => 'All models agreed.']);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRunEvents(['id' => (string)$s2Session]), 's2 events full');
checkSc('s2: three ordered events', count($r['events'] ?? []) === 3
    && ($r['events'][0]['event_type'] ?? '') === 'PIPELINE_STARTED'
    && ($r['events'][2]['event_type'] ?? '') === 'PIPELINE_DONE');
$firstEventId = (int)($r['events'][0]['id'] ?? 0);
checkSc('s2: latest_event_id reported', (int)($r['latest_event_id'] ?? 0) > $firstEventId);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$_GET['after_event_id']    = (string)$firstEventId;
$r = decodeJson($ctrl->getRunEvents(['id' => (string)$s2Session]), 's2 events cursor');
checkSc('s2: cursor resume skips seen events', count($r['events'] ?? []) === 2
    && (int)($r['events'][0]['id'] ?? 0) > $firstEventId);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRunResult(['id' => (string)$s2Session]), 's2 result while queued');
checkSc('s2: result poll 202 while active', http_response_code() === 202
    && ($r['status'] ?? '') === 'queued');

// The durable worker finishes the job (revoap_jobs result envelope).
$jobRepo->updateStatus($s2Job, JobStatus::Completed);
$jobRepo->recordResult($s2Job, [
    'final_answer' => 'All models agreed.',
    'consensus'    => ['score' => 0.95, 'status' => 'agreement'],
    'model'        => 'stub/echo',
    'usage'        => ['prompt_tokens' => 50, 'completion_tokens' => 30, 'total_tokens' => 80],
]);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRunResult(['id' => (string)$s2Session]), 's2 result terminal');
checkSc('s2: terminal result 200 completed', http_response_code() === 200
    && ($r['status'] ?? '') === 'completed');
checkSc('s2: final_answer collected', ($r['final_answer'] ?? '') === 'All models agreed.');
checkSc('s2: usage collected', (int)($r['usage']['total_tokens'] ?? 0) === 80);
checkSc('s2: consensus collected', (float)($r['consensus']['score'] ?? 0) === 0.95);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->listRuns(), 's2 list');
checkSc('s2: run listed as completed', http_response_code() === 200
    && ($r['ok'] ?? false) === true && ($r['count'] ?? 0) === 1
    && ($r['runs'][0]['run_id'] ?? '') === 'run_' . $s2Session
    && ($r['runs'][0]['status'] ?? '') === 'completed');

// ==============================================================
// Scenario 3 - OpenAI dialect round-trip
//   Given a full-scope key and an OpenAI-shaped messages envelope,
//   When the agent launches synchronously with ?format=openai and
//   then reads the run back in the same dialect,
//   Then responses are SDK-shaped (chat.completion / chatcmpl_) with
//   the revoap extension block; errors use the SDK error shape.
// ==============================================================
$report[] = 'Scenario 3 - OpenAI dialect round-trip';
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$_GET['format']            = 'openai';
$ctrl->setBodyForTest([
    'provider'   => 'stub',
    'model'      => 'stub/echo',
    'messages'   => [
        ['role' => 'system', 'content' => 'Be crisp.'],
        ['role' => 'user',   'content' => 'OpenAI dialect question.'],
    ],
    'async'      => false,
    'temperature' => 0.2,
]);
$r = decodeJson($ctrl->createRun(), 's3 openai sync response');
checkSc('s3: chat.completion shape', http_response_code() === 200
    && ($r['object'] ?? '') === 'chat.completion'
    && str_starts_with((string)($r['id'] ?? ''), 'chatcmpl_'));
checkSc('s3: choices carry the answer', is_array($r['choices'] ?? null)
    && ($r['choices'][0]['message']['role'] ?? '') === 'assistant'
    && str_contains((string)($r['choices'][0]['message']['content'] ?? ''), '[stub]'));
checkSc('s3: revoap extension block', ($r['revoap']['run_id'] ?? '') === 'run_' . (int)($r['revoap']['session_id'] ?? 0)
    && (int)($r['revoap']['session_id'] ?? 0) > 0);
$s3Session = (int)($r['revoap']['session_id'] ?? 0);
$ctrl->setBodyForTest(null);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$_GET['format']            = 'openai';
$r = decodeJson($ctrl->getRun(['id' => (string)$s3Session]), 's3 openai read');
checkSc('s3: read-back same dialect', ($r['object'] ?? '') === 'chat.completion'
    && ($r['revoap']['run_id'] ?? '') === 'run_' . $s3Session);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$_GET['format']            = 'openai';
$ctrl->setBodyForTest(['model' => 'stub/echo', 'messages' => [], 'async' => false]);
$r = decodeJson($ctrl->createRun(), 's3 openai error');
checkSc('s3: SDK-shaped 400 error', http_response_code() === 400
    && ($r['error']['type'] ?? '') === 'invalid_request_error'
    && is_string($r['error']['message'] ?? null) && $r['error']['message'] !== '');
$ctrl->setBodyForTest(null);

// ==============================================================
// Scenario 4 - Error states through the public auth gate
//   Given a fresh client with no/wrong key and a read-scope key,
//   When requests hit protected and operator-only paths,
//   Then the gate rejects with 401/403/404 and rate-limits with 429
//   + Retry-After.
// ==============================================================
$report[] = 'Scenario 4 - Error states';
resetRequestEnv();
$gate = $auth->check($_SERVER, $_GET, '/v1/runs', true);
checkSc('s4: no key -> 401 unauthorized', $gate['ok'] === false
    && (int)($gate['status'] ?? 0) === 401 && ($gate['error'] ?? '') === 'unauthorized');

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = 'rv_' . str_repeat('a', 48);
$gate = $auth->check($_SERVER, $_GET, '/v1/models', false);
checkSc('s4: wrong key -> 401', $gate['ok'] === false && (int)($gate['status'] ?? 0) === 401);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $readPlain;
$gate = $auth->check($_SERVER, $_GET, '/v1/runs', true);
checkSc('s4: read key on write -> 403 forbidden', $gate['ok'] === false
    && (int)($gate['status'] ?? 0) === 403 && ($gate['error'] ?? '') === 'forbidden');

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$gate = $auth->check($_SERVER, $_GET, '/api/skills', false);
checkSc('s4: operator path deny-listed -> 404', $gate['ok'] === false
    && (int)($gate['status'] ?? 0) === 404);

$tightGen = $keySvc->generate('Tight rpm', 'full', 2, 10000);
$tightPlain = $tightGen['plaintext'];
$lastGate = null;
for ($i = 0; $i < 3; $i++) {
    resetRequestEnv();
    $_SERVER['HTTP_X_API_KEY'] = $tightPlain;
    $lastGate = $auth->check($_SERVER, $_GET, '/v1/models', false);
    if ($lastGate['ok'] === false) { break; }
}
checkSc('s4: rpm breach -> 429 rate_limited', $lastGate['ok'] === false
    && (int)($lastGate['status'] ?? 0) === 429 && ($lastGate['error'] ?? '') === 'rate_limited');
checkSc('s4: 429 carries Retry-After', isset($lastGate['headers']['Retry-After'])
    && (int)$lastGate['headers']['Retry-After'] > 0);

// ==============================================================
// Scenario 5 - Webhook delivery and retry with HMAC
//   Given a webhook subscription with a signing secret,
//   When a run lifecycle event is dispatched to a receiver,
//   Then the delivery carries a valid X-RevoAp-HMAC-SHA256 signature
//   and an idempotent event id; a failed send retries the SAME
//   timestamped payload with a fresh signature.
// ==============================================================
$report[] = 'Scenario 5 - Webhook delivery and retry with HMAC';
$hookRepo = new WebhookRepository(null);
$hookSvc  = new WebhookService($hookRepo);
$deliveryRepo = new WebhookDeliveryRepository(null);

$s5Hook = $hookSvc->create(
    label:  'Scenario receiver',
    url:    'https://hooks.example.com/cb',
    secret: 'wh_scenario_secret',
    events: ['run.completed'],
);
$dispatcher = new WebhookDispatcher($hookRepo, $deliveryRepo);
$dispatcher->setUuid(static fn(): string => '11111111-2222-4333-8444-555555555555');
$dispatcher->setNow(static fn(): int => 1_800_000_000);

$sentHeaders = [];
$sentBodies  = [];
$dispatcher->setSender(static function (string $url, array $headers, string $body) use (&$sentHeaders, &$sentBodies): array {
    $sentHeaders[] = $headers;
    $sentBodies[]  = $body;
    return ['code' => 200, 'body' => 'ok'];
});

$summary = $dispatcher->dispatch('run.completed', [
    'run_id' => 'run_7', 'session_id' => 7, 'status' => 'completed', 'final_answer' => 'done',
]);
checkSc('s5: dispatch sent one delivery', $summary === ['sent' => 1, 'queued' => 0, 'failed' => 0]);
checkSc('s5: exactly one POST to the subscribed url', count($sentHeaders) === 1 && count($sentBodies) === 1);

$signatureHeader = '';
foreach ($sentHeaders[0] ?? [] as $headerLine) {
    if (str_starts_with($headerLine, WebhookDispatcher::SIGNATURE_HEADER . ':')) {
        $signatureHeader = trim(substr($headerLine, strlen(WebhookDispatcher::SIGNATURE_HEADER) + 1));
    }
}
checkSc('s5: signature header present', $signatureHeader !== '');
// Simulated receiver verification: recompute the HMAC deterministically
// (dispatch() stamps attempt time = 1800000000 via the setNow seam).
$t1 = '1800000000';
$expectedSig1 = 't=' . $t1 . ';v1=' . strtolower(hash_hmac('sha256', $t1 . '.' . $sentBodies[0], 'wh_scenario_secret'));
checkSc('s5: signature verifies over the raw body', $signatureHeader === $expectedSig1);
$s5Payload = json_decode($sentBodies[0], true);
checkSc('s5: envelope carries event + event_id + data',
    ($s5Payload['event'] ?? '') === 'run.completed'
    && ($s5Payload['event_id'] ?? '') === '11111111-2222-4333-8444-555555555555'
    && ($s5Payload['data']['run_id'] ?? '') === 'run_7');
$s5Delivery = $deliveryRepo->findByEvent($s5Hook->id, $s5Payload['event_id']);
checkSc('s5: delivery recorded success', $s5Delivery !== null
    && $s5Delivery->status === WebhookDelivery::STATUS_SUCCESS && $s5Delivery->responseCode === 200);

// Retry path: the receiver starts failing, then recovers. Deactivate
// the first hook so the second dispatch targets only the flaky one.
$hookRepo->toggleActive($s5Hook->id, false);
$s5Hook2 = $hookSvc->create(
    label:  'Flaky receiver',
    url:    'https://hooks.example.com/flaky',
    secret: 'wh_scenario_secret',
    events: ['run.completed'],
);
$sentHeaders = [];
$sentBodies  = [];
$recovered   = false;
$dispatcher->setSender(static function (string $url, array $headers, string $body) use (&$sentHeaders, &$sentBodies, &$recovered): array {
    $sentHeaders[] = $headers;
    $sentBodies[]  = $body;
    if ($recovered) {
        return ['code' => 200, 'body' => 'ok'];
    }
    $recovered = true; // fail exactly the first delivery attempt
    return ['code' => 500, 'body' => 'boom'];
});

$summary = $dispatcher->dispatch('run.completed', [
    'run_id' => 'run_8', 'session_id' => 8, 'status' => 'completed', 'final_answer' => 'done',
]);
checkSc('s5: first send failed -> queued for retry', $summary === ['sent' => 0, 'queued' => 1, 'failed' => 0]);
$s5Delivery2 = $deliveryRepo->findByEvent($s5Hook2->id, '11111111-2222-4333-8444-555555555555');
checkSc('s5: retry scheduled', $s5Delivery2 !== null
    && $s5Delivery2->status === WebhookDelivery::STATUS_PENDING && $s5Delivery2->nextRetryAt !== null);

// Advance past the backoff window and pump due deliveries.
$dispatcher->setNow(static fn(): int => 1_800_000_002);
$processed = $dispatcher->pumpDue();
checkSc('s5: pump re-sent the pending delivery', $processed === 1 && count($sentBodies) === 2);
checkSc('s5: retry replayed the SAME payload', $sentBodies[0] === $sentBodies[1]);
$signatureHeader1 = '';
$signatureHeader2 = '';
foreach ($sentHeaders[0] as $h) {
    if (str_starts_with($h, WebhookDispatcher::SIGNATURE_HEADER . ':')) {
        $signatureHeader1 = trim(substr($h, strlen(WebhookDispatcher::SIGNATURE_HEADER) + 1));
    }
}
foreach ($sentHeaders[1] as $h) {
    if (str_starts_with($h, WebhookDispatcher::SIGNATURE_HEADER . ':')) {
        $signatureHeader2 = trim(substr($h, strlen(WebhookDispatcher::SIGNATURE_HEADER) + 1));
    }
}
checkSc('s5: retry signed with a fresh signature', $signatureHeader1 !== '' && $signatureHeader2 !== ''
    && $signatureHeader1 !== $signatureHeader2);
// Retrieve the t= from the retry signature and verify the HMAC manually.
$retryT = '';
foreach (explode(';', $signatureHeader2) as $retryPart) {
    $retryPart = trim($retryPart);
    if (str_starts_with($retryPart, 't=')) {
        $retryT = substr($retryPart, 2);
    }
}
$expectedSig2 = 't=' . $retryT . ';v1=' . strtolower(hash_hmac('sha256', $retryT . '.' . $sentBodies[1], 'wh_scenario_secret'));
checkSc('s5: retry signature verifies too', $signatureHeader2 === $expectedSig2);
$s5Delivery2 = $deliveryRepo->findByEvent($s5Hook2->id, '11111111-2222-4333-8444-555555555555');
checkSc('s5: retry finally recorded success', $s5Delivery2 !== null
    && $s5Delivery2->status === WebhookDelivery::STATUS_SUCCESS && $s5Delivery2->responseCode === 200);

// --------------------------------------------------------------
@unlink($tmpDir . '/ScenarioStubConnector.php');
foreach (glob($tmpDir . '/limits/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmpDir . '/limits');
@rmdir($tmpDir);
foreach (glob($lockBase . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($lockBase);

echo implode("\n", $report) . "\n";
echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
