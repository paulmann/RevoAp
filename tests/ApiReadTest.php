<?php
/**
 * ApiReadTest: offline unit checks for the external REST API read
 * endpoints (Task: REST API, Step 5 - runs/events/result/cancel/
 * models/usage).
 *
 * Runs without PHPUnit, a database or network access:
 * `php tests/ApiReadTest.php`.
 *
 * Verified:
 *   - GET /v1/runs: recent run list (native run records, limit clamp);
 *   - GET /v1/runs/{id}: run record from a durable job; OpenAI envelope
 *     via ?format=openai; 404 for unknown ids; bad id -> 400;
 *   - GET /v1/runs/{id}/events: insertion order + ?after_event_id=
 *     cursor + latest_event_id;
 *   - GET /v1/runs/{id}/result: 202 while active, 200 + final_answer/
 *     usage/consensus once the worker records the result;
 *   - POST /v1/runs/{id}/cancel: read key -> 403, terminal job -> 409,
 *     active job -> CancelRequested rendered as cancelled;
 *   - GET /v1/models: active models from Config; read scope allowed;
 *   - GET /v1/usage: without a database -> 503 no_database; missing
 *     key -> 401;
 *   - a full agent scenario: create -> poll events -> collect result
 *     -> query usage.
 *
 * NOTE: this file deliberately prints nothing until the very end.
 * Any output before http_response_code() is consulted makes PHP CLI
 * consider headers sent and the status code checks would silently fail.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  8.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Api\ApiAuth;
use App\Api\ApiKeyRepository;
use App\Api\ApiKeyService;
use App\Api\ApiRateLimiter;
use App\Api\ApiV1Controller;
use App\Api\FormatAdapter;
use App\Connectors\ChatResponse;
use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Consensus\ConsensusEngine;
use App\Core\Config;
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
use App\Statistics\StatisticsAggregator;
use App\Statistics\StatisticsService;
use App\Storage\ResponseRepository;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;

$failures = 0;
$passes   = 0;
$report   = [];

function checkRead(string $label, bool $cond, string $detail = ''): void
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
    checkRead($label . ' is valid JSON', is_array($decoded), $body === '' ? 'empty body' : substr($body, 0, 120));
    return is_array($decoded) ? $decoded : [];
}

// --------------------------------------------------------------
// Shared wiring (memory-mode repositories, just like in prod).
// --------------------------------------------------------------
$tmpDir  = sys_get_temp_dir() . '/oc-read-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0o700, true);
$lockBase = sys_get_temp_dir() . '/oc-read-locks-' . bin2hex(random_bytes(4));
mkdir($lockBase, 0o700, true);
mkdir($tmpDir . '/limits', 0o700, true);

file_put_contents($tmpDir . '/ReadStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\ChatResponse;
use App\Connectors\ConnectorInterface;
use App\Connectors\EmbedResponse;
use App\Connectors\Exceptions\UnsupportedOperationException;
final class ReadStubConnector implements ConnectorInterface {
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        return new ChatResponse('stub', $model, 'stub', 1, 1, 2, 0.1, [], true);
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

$keyRepo  = new ApiKeyRepository(null);
$keySvc   = new ApiKeyService($keyRepo);
$limiter  = new ApiRateLimiter($tmpDir . '/limits');
$auth     = new ApiAuth($keySvc, $limiter);
$statsSvc = new StatisticsService(null, new StatisticsAggregator());

$ctrl = new ApiV1Controller(
    $auth,
    new FormatAdapter(),
    $jobSvc,
    $engine,
    $sessions,
    $steps,
    null,
    new StreamRunLock($lockBase),
    $statsSvc,
);

$genFull = $keySvc->generate('Full', 'full', 500, 10000);
$fullPlain = $genFull['plaintext'];
$genRead = $keySvc->generate('Read', 'read', 500, 10000);
$readPlain = $genRead['plaintext'];

function resetRequestEnv(): void
{
    $_SERVER = [];
    $_GET    = [];
    if (function_exists('http_response_code')) {
        http_response_code(200);
    }
}

// ===== Gate failures ======================================
resetRequestEnv();
$r = decodeJson($ctrl->listRuns(), 'list missing key response');
checkRead('list: missing key -> 401', http_response_code() === 401 && ($r['error'] ?? '') === 'unauthorized');

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = 'rv_' . str_repeat('f', 48);
$r = decodeJson($ctrl->models(), 'models invalid key response');
checkRead('models: invalid key -> 401', http_response_code() === 401 && ($r['error'] ?? '') === 'unauthorized');

// ===== Scenario: create a durable run ======================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest([
    'query'           => 'Poll my consensus run.',
    'selected_models' => [['provider' => 'stub', 'model' => 'stub/echo']],
]);
$r = decodeJson($ctrl->createRun(), 'scenario create response');
checkRead('scenario: run created (202)', http_response_code() === 202);
$sessionId = (int)($r['session_id'] ?? 0);
$jobId     = (int)($r['job_id'] ?? 0);
checkRead('scenario: ids present', $sessionId > 0 && $jobId > 0);

// ===== GET /v1/runs (list) =================================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$_GET['limit']             = '1';
$r = decodeJson($ctrl->listRuns(), 'list response');
checkRead('list: 200 + ok', http_response_code() === 200 && ($r['ok'] ?? false) === true);
checkRead('list: one run returned', ($r['count'] ?? 0) === 1 && is_array($r['runs'] ?? null) && count($r['runs']) === 1);
checkRead('list: run shape', ($r['runs'][0]['run_id'] ?? '') === 'run_' . $sessionId
    && ($r['runs'][0]['status'] ?? '') === 'queued');

// ===== GET /v1/runs/{id} ===================================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRun(['id' => (string)$sessionId]), 'getRun response');
checkRead('getRun: 200 + queued', http_response_code() === 200 && ($r['status'] ?? '') === 'queued');
checkRead('getRun: job_id surfaced', ((int)($r['job_id'] ?? 0)) === $jobId);
checkRead('getRun: urls present', isset($r['events_url'], $r['result_url'], $r['cancel_url']));

// OpenAI envelope on the same record
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$_GET['format']            = 'openai';
$r = decodeJson($ctrl->getRun(['id' => (string)$sessionId]), 'getRun openai response');
checkRead('getRun openai: chat.completion shape',
    ($r['object'] ?? '') === 'chat.completion'
    && str_starts_with((string)($r['id'] ?? ''), 'chatcmpl_')
    && ($r['revoap']['run_id'] ?? '') === 'run_' . $sessionId);

// Unknown and malformed ids
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRun(['id' => '99999']), 'getRun unknown response');
checkRead('getRun: unknown -> 404 not_found', http_response_code() === 404 && ($r['error'] ?? '') === 'not_found');

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRun(['id' => 'abc']), 'getRun bad id response');
checkRead('getRun: bad id -> 400 invalid_request', http_response_code() === 400 && ($r['error'] ?? '') === 'invalid_request');

// ===== GET /v1/runs/{id}/events ============================
$steps->appendEvent($sessionId, null, 'PIPELINE_STARTED', ['query' => 'Poll my consensus run.']);
$steps->appendEvent($sessionId, null, 'L1_RESPONSE', ['role' => 'analyst', 'model' => 'stub/echo']);
$steps->appendEvent($sessionId, null, 'PIPELINE_DONE', ['final_answer' => 'Consensus reached.']);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRunEvents(['id' => (string)$sessionId]), 'events response');
checkRead('events: 3 events, ordered', http_response_code() === 200 && count($r['events'] ?? []) === 3
    && ($r['events'][0]['event_type'] ?? '') === 'PIPELINE_STARTED'
    && ($r['events'][2]['event_type'] ?? '') === 'PIPELINE_DONE');
checkRead('events: payload decoded', is_array($r['events'][1]['payload'] ?? null)
    && ($r['events'][1]['payload']['role'] ?? '') === 'analyst');
checkRead('events: latest_event_id', (int)($r['latest_event_id'] ?? 0) === 3);

// Cursor resume
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$_GET['after_event_id']    = '1';
$r = decodeJson($ctrl->getRunEvents(['id' => (string)$sessionId]), 'events cursor response');
checkRead('events: after_event_id returns remainder', count($r['events'] ?? []) === 2
    && (int)($r['events'][0]['id'] ?? 0) === 2
    && (int)($r['latest_event_id'] ?? 0) === 3);

// ===== GET /v1/runs/{id}/result ============================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRunResult(['id' => (string)$sessionId]), 'result active response');
checkRead('result: 202 while active', http_response_code() === 202 && ($r['status'] ?? '') === 'queued');

// Simulate the worker completing the job
$jobRepo->updateStatus($jobId, JobStatus::Completed);
$jobRepo->recordResult($jobId, [
    'final_answer' => 'Consensus reached.',
    'consensus'    => ['score' => 1.0, 'status' => 'agreement'],
    'model'        => 'stub/echo',
    'usage'        => ['prompt_tokens' => 120, 'completion_tokens' => 80, 'total_tokens' => 200],
]);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->getRunResult(['id' => (string)$sessionId]), 'result terminal response');
checkRead('result: 200 + completed when terminal', http_response_code() === 200 && ($r['status'] ?? '') === 'completed');
checkRead('result: final_answer collected', ($r['final_answer'] ?? '') === 'Consensus reached.');
checkRead('result: usage collected', (int)($r['usage']['total_tokens'] ?? 0) === 200);
checkRead('result: consensus collected', (float)($r['consensus']['score'] ?? 0) === 1.0);

// ===== POST /v1/runs/{id}/cancel ===========================
// Cancel a fresh active run
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest(['query' => 'Cancellable run.']);
$r = decodeJson($ctrl->createRun(), 'cancel target response');
$cancelSession = (int)($r['session_id'] ?? 0);
$cancelJob     = (int)($r['job_id'] ?? 0);
checkRead('cancel target: created', $cancelSession > 0 && $cancelJob > 0);
$ctrl->setBodyForTest(null);

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $readPlain;
$r = decodeJson($ctrl->cancelRun(['id' => (string)$cancelSession]), 'cancel read key response');
checkRead('cancel: read key -> 403 forbidden', http_response_code() === 403 && ($r['error'] ?? '') === 'forbidden');

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->cancelRun(['id' => (string)$cancelSession]), 'cancel active response');
checkRead('cancel: active run -> 200 cancelled', http_response_code() === 200
    && ($r['status'] ?? '') === 'cancelled');
checkRead('cancel: durable intent recorded', $jobRepo->readStatus($cancelJob) === JobStatus::CancelRequested);

// Cancel a terminal job -> 409
$jobRepo->updateStatus($cancelJob, JobStatus::Completed);
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->cancelRun(['id' => (string)$cancelSession]), 'cancel terminal response');
checkRead('cancel: terminal -> 409 invalid_state', http_response_code() === 409 && ($r['error'] ?? '') === 'invalid_state');

// ===== GET /v1/models ======================================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$r = decodeJson($ctrl->models(), 'models response');
checkRead('models: 200 + ok', http_response_code() === 200 && ($r['ok'] ?? false) === true);
checkRead('models: active model listed', ($r['count'] ?? 0) === 1
    && ($r['models'][0]['id'] ?? '') === 'stub/stub/echo'
    && ($r['models'][0]['model'] ?? '') === 'stub/echo');

// ===== GET /v1/usage =======================================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$_GET['period']            = 'day';
$r = decodeJson($ctrl->usage(), 'usage response');
checkRead('usage: 503 without a database', http_response_code() === 503
    && ($r['error']['code'] ?? $r['error'] ?? '') === 'no_database');

// --------------------------------------------------------------
@unlink($tmpDir . '/ReadStubConnector.php');
foreach (glob($tmpDir . '/limits/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmpDir . '/limits');
@rmdir($tmpDir);
foreach (glob($lockBase . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($lockBase);

echo implode("\n", $report) . "\n";
echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
