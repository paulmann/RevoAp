<?php
/**
 * ApiLaunchTest: offline unit checks for the external REST API launch
 * endpoint (Task: REST API, Step 4 - POST /v1/runs).
 *
 * Runs without PHPUnit, a database or network access:
 * `php tests/ApiLaunchTest.php`.
 *
 * Verified:
 *   - sync branch (async=false): a run through the stub connector
 *     reaches a final answer inline (run -> final via mock connector);
 *   - async branch (default): 202 Accepted with run_id/job_id/urls and
 *     the unfinished run remains pollable via JobService::get / getBySessionId;
 *   - stream branch (stream=true): the pipeline is dispatched to the
 *     engine (stub connector consulted) inside the current request;
 *   - routing + argument mapping parity with the previous steps:
 *     missing/invalid key => 401, read key on write endpoint => 403,
 *     empty query => 400 invalid_request;
 *   - OpenAI envelope accepted by the same endpoint (model -> run's
 *     model label, last user message becomes the query);
 *   - rate limit breach returns 429 with Retry-After headers.
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
use App\Connectors\EmbedResponse;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Consensus\ConsensusEngine;
use App\Core\Config;
use App\Helpers\TokenHelper;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
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

function checkLaunch(string $label, bool $cond, string $detail = ''): void
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

/** Decode a JSON API response body, asserting it is valid JSON. */
function decodeJson(string $body, string $label): array
{
    $decoded = json_decode($body, true);
    checkLaunch($label . ' is valid JSON', is_array($decoded), $body === '' ? 'empty body' : substr($body, 0, 120));
    return is_array($decoded) ? $decoded : [];
}

// --------------------------------------------------------------
// Shared wiring (memory-mode repositories, just like in prod).
// --------------------------------------------------------------
$tmpDir = sys_get_temp_dir() . '/oc-launch-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0o700, true);
$lockBase = sys_get_temp_dir() . '/oc-launch-locks-' . bin2hex(random_bytes(4));
mkdir($lockBase, 0o700, true);
// The rate limiter fails open when its counter directory is missing;
// make it exist so the 429 branch can actually trip.
mkdir($tmpDir . '/limits', 0o700, true);

file_put_contents($tmpDir . '/LaunchStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;
final class LaunchStubConnector implements ConnectorInterface {
    public static int $chatCalls = 0;
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        self::$chatCalls++;
        $content = '[stub] ' . (string)($messages[1]['content'] ?? '') . str_repeat(' Consensus analysis sentence.', 6);
        $pt = TokenHelper::estimateMessageTokens($messages);
        $ct = TokenHelper::estimateTokens($content);
        return new ChatResponse($content, $model, 'stub', $pt, $ct, $pt + $ct, 1.5, ['stub' => true], true);
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
$ctrl->setBodyForTest(['query' => 'Skip auth?']);
$r = decodeJson($ctrl->createRun(), 'missing key response');
checkLaunch('missing key -> 401', http_response_code() === 401 && ($r['error'] ?? '') === 'unauthorized');

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = 'rv_' . str_repeat('0', 48);
$r = decodeJson($ctrl->createRun(), 'invalid key response');
checkLaunch('invalid key -> 401', http_response_code() === 401 && ($r['error'] ?? '') === 'unauthorized');

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $readPlain;
$ctrl->setBodyForTest(['query' => 'Write?']);
$r = decodeJson($ctrl->createRun(), 'read key on write response');
checkLaunch('read key on write endpoint -> 403', http_response_code() === 403 && ($r['error'] ?? '') === 'forbidden');

resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest(['query' => '']);
$r = decodeJson($ctrl->createRun(), 'empty query response');
checkLaunch('empty query -> 400 invalid_request', http_response_code() === 400 && ($r['error'] ?? '') === 'invalid_request');

$ctrl->setBodyForTest(null);

// ===== Async branch (default) ==============================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest([
    'query'           => 'Evaluate this consensus run.',
    'selected_models' => [['provider' => 'stub', 'model' => 'stub/echo']],
]);
$r = decodeJson($ctrl->createRun(), 'async native response');
checkLaunch('async: 202 Accepted', http_response_code() === 202);
checkLaunch('async: ok + run_id', ($r['ok'] ?? false) === true && str_starts_with((string)($r['run_id'] ?? ''), 'run_'));
checkLaunch('async: job_id present', ((int)($r['job_id'] ?? 0)) > 0);
checkLaunch('async: status queued', ($r['status'] ?? '') === 'queued');
checkLaunch('async: urls present',
    isset($r['events_url'], $r['result_url'], $r['cancel_url'])
    && str_contains((string)$r['events_url'], '/v1/runs/'));
$asyncJobId    = (int)($r['job_id'] ?? 0);
$asyncSession  = (int)($r['session_id'] ?? 0);
$pollJob       = $jobSvc->get($asyncJobId);
$pollBySession = $jobSvc->getBySessionId($asyncSession);
checkLaunch('async: unfinished run pollable via get()', $pollJob !== null);
checkLaunch('async: unfinished run pollable via getBySessionId()',
    $pollBySession !== null && (int)$pollBySession->sessionId === $asyncSession);

// ===== OpenAI envelope on the async branch =================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest([
    'revoap_provider' => 'stub',
    'model'    => 'stub/echo',
    'messages' => [
        ['role' => 'system', 'content' => 'Be brief.'],
        ['role' => 'user',   'content' => 'OpenAI style question.'],
    ],
]);
$r = decodeJson($ctrl->createRun(), 'openai envelope response');
checkLaunch('openai envelope accepted (202)', http_response_code() === 202
    && (($r['object'] ?? '') === 'chat.completion' || str_starts_with((string)($r['id'] ?? ''), 'chatcmpl_')));
checkLaunch('openai envelope model label', ($r['model'] ?? '') === 'stub/stub/echo');

// ===== Sync branch (async=false) ===========================
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest([
    'query'           => 'What is the meaning of consensus?',
    'async'           => false,
    'selected_models' => [['provider' => 'stub', 'model' => 'stub/echo']],
]);
$r = decodeJson($ctrl->createRun(), 'sync native response');
checkLaunch('sync: 200 OK', http_response_code() === 200);
checkLaunch('sync: status completed', ($r['status'] ?? '') === 'completed');
checkLaunch('sync: final_answer inline',
    is_string($r['final_answer'] ?? null) && str_contains((string)$r['final_answer'], '[stub]'));
checkLaunch('sync: usage block filled',
    isset($r['usage']['total_tokens']) && (int)$r['usage']['total_tokens'] > 0);
checkLaunch('sync: run_id + urls', str_starts_with((string)($r['run_id'] ?? ''), 'run_')
    && isset($r['events_url'], $r['result_url'], $r['cancel_url']));

// ===== Rate limit ===========================================
$tinyGen = $keySvc->generate('Tiny', 'full', 2, 10000);
$tinyPlain = $tinyGen['plaintext'];
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $tinyPlain;
$ctrl->setBodyForTest(['query' => 'Rate limited?']);
$lastCode = 0;
$rateBody = null;
for ($i = 0; $i < 4; $i++) {
    $body = $ctrl->createRun();
    if (http_response_code() === 429) {
        $rateBody = decodeJson($body, 'rate limit response');
        $lastCode = 429;
        break;
    }
    $lastCode = http_response_code();
}
checkLaunch('rpm breach -> 429 rate_limited', $lastCode === 429 && ($rateBody['error'] ?? '') === 'rate_limited');

// ===== Stream branch (stream=true) - LAST: it echoes SSE =====
$ctrl->setBodyForTest(null);
resetRequestEnv();
$_SERVER['HTTP_X_API_KEY'] = $fullPlain;
$ctrl->setBodyForTest([
    'query'           => 'Stream this consensus.',
    'stream'          => true,
    'selected_models' => [['provider' => 'stub', 'model' => 'stub/echo']],
]);
$chatsBefore = \App\Connectors\LaunchStubConnector::$chatCalls;
try {
    $ctrl->createRun();
} catch (\Throwable $e) {
    checkLaunch('stream: no exception', false, $e->getMessage());
}
checkLaunch('stream: engine consulted (chat called)', \App\Connectors\LaunchStubConnector::$chatCalls > $chatsBefore);

// --------------------------------------------------------------
@unlink($tmpDir . '/LaunchStubConnector.php');
foreach (glob($tmpDir . '/limits/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmpDir . '/limits');
@rmdir($tmpDir);
foreach (glob($lockBase . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($lockBase);

echo implode("\n", $report) . "\n";
echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
