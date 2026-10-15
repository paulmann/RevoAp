<?php
/**
 * ApiQuotaGateTest: per-user budget enforcement on the launch paths
 * (v2.1.0, T5).
 *
 * Runs without PHPUnit, a database or network access:
 * `php tests/ApiQuotaGateTest.php` — memory-mode repositories and a stub
 * connector, the same harness ApiLaunchTest uses, plus stub
 * UserQuotaInterface implementations (the enforcer's own database logic is
 * covered by UserBudgetTest; this file tests the WIRING: where the gate
 * sits, what a refusal looks like on the wire, and what must NOT happen
 * when it fires).
 *
 * What is covered, and the failure each case exists to prevent
 * -----------------------------------------------------------
 *  - A blocking verdict on POST /v1/runs (sync) answers 429
 *    `budget_exceeded` in the caller's dialect and the engine is NEVER
 *    consulted: a refused run must not burn tokens on the way out.
 *  - The async branch is gated too, and so is the OpenAI envelope — all
 *    three shapes share one enforcement point, one refusal format.
 *  - The refusal payload carries the budget's own numbers (spent, limit,
 *    reset) and a Retry-After: "no" without the figures sends an
 *    integration debugging its code instead of its bill.
 *  - A 'warn' verdict lets the run through (the money decision belongs to
 *    the operator who set action_at_limit=warn).
 *  - A quota check that THROWS fails open: a budget subsystem outage must
 *    not become a run-surface outage.
 *  - JobService::createJob refuses BEFORE creating the session row — a
 *    blocked run leaves no orphan 'running' session in History.
 *  - The BudgetExceededException thrown from the service is rendered by
 *    enqueueRun as the same 429 (the race between the two checks resolves
 *    to one contract).
 *
 * NOTE: like ApiLaunchTest, this file prints nothing until the very end —
 * any earlier output makes PHP CLI consider headers sent and the
 * http_response_code() assertions silently stop working.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Admin\Budgets\BudgetExceededException;
use App\Admin\Budgets\UserQuotaInterface;
use App\Api\ApiAuth;
use App\Api\ApiKeyRepository;
use App\Api\ApiKeyService;
use App\Api\ApiRateLimiter;
use App\Api\ApiV1Controller;
use App\Api\FormatAdapter;
use App\Connectors\ConnectorRegistry;
use App\Consensus\ConsensusEngine;
use App\Core\Config;
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

function checkQuota(string $label, bool $cond, string $detail = ''): void
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

function sectionQ(string $title): void
{
    global $report;
    $report[] = "";
    $report[] = "== {$title} ==";
}

/** Configurable stub — the enforcer's internals are UserBudgetTest's job. */
final class StubQuota implements UserQuotaInterface
{
    public int $calls = 0;
    public ?int $lastUserId = null;

    /** @param array<string,mixed> $verdict */
    public function __construct(private array $verdict, private bool $throws = false)
    {
    }

    public function checkForRun(?int $userId): array
    {
        $this->calls++;
        $this->lastUserId = $userId;
        if ($this->throws) {
            throw new RuntimeException('quota subsystem on fire');
        }
        return $this->verdict;
    }
}

function blockVerdict(): array
{
    return [
        'action'  => 'block',
        'checked' => true,
        'budgets' => [[
            'budget_id' => 3, 'name' => 'Team cap', 'currency' => 'USD',
            'period' => 'daily', 'amount' => 25.0, 'spent' => 30.5,
            'percent' => 122.0, 'action_at_limit' => 'block', 'at_limit' => true,
            'resets_at' => time() + 3600,
        ]],
        'blocking' => [
            'budget_id' => 3, 'name' => 'Team cap', 'currency' => 'USD',
            'period' => 'daily', 'amount' => 25.0, 'spent' => 30.5,
            'percent' => 122.0, 'action_at_limit' => 'block', 'at_limit' => true,
            'resets_at' => time() + 3600,
        ],
        'retry_after'   => 3600,
        'cost_coverage' => 1.0,
        'warnings'      => [],
    ];
}

function warnVerdict(): array
{
    $v = blockVerdict();
    $v['action'] = 'warn';
    $v['blocking'] = null;
    $v['budgets'][0]['action_at_limit'] = 'warn';
    return $v;
}

function okVerdict(): array
{
    return ['action' => 'ok', 'checked' => true, 'budgets' => [], 'blocking' => null,
            'retry_after' => null, 'cost_coverage' => null, 'warnings' => []];
}

// --------------------------------------------------------------
// Harness (memory-mode repositories, stub connector — ApiLaunchTest's shape)
// --------------------------------------------------------------
$tmpDir = sys_get_temp_dir() . '/oc-quota-' . bin2hex(random_bytes(4));
mkdir($tmpDir . '/limits', 0o700, true);
$lockBase = sys_get_temp_dir() . '/oc-quota-locks-' . bin2hex(random_bytes(4));
mkdir($lockBase, 0o700, true);

file_put_contents($tmpDir . '/QuotaStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;
final class QuotaStubConnector implements ConnectorInterface {
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
    'app'        => ['debug' => false, 'version' => '2.1.0', 'name' => 'RevoAp'],
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

// sqlite SessionRepository for the "no orphan session" assertions.
$sessionPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sessionPdo->exec('CREATE TABLE council_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL, skill_id INTEGER NOT NULL DEFAULT 0, skill_slug TEXT NULL,
    query_text TEXT NOT NULL DEFAULT \'\', final_answer TEXT NULL,
    consensus_score REAL NULL, consensus_status TEXT DEFAULT \'pending\',
    status TEXT DEFAULT \'running\', total_tokens INTEGER DEFAULT 0,
    duration_ms INTEGER DEFAULT 0, options_json TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), completed_at TEXT NULL
)');
$sessions = new SessionRepository($sessionPdo);

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

$keyRepo = new ApiKeyRepository(null);
$keySvc  = new ApiKeyService($keyRepo);
$limiter = new ApiRateLimiter($tmpDir . '/limits');
$auth    = new ApiAuth($keySvc, $limiter);
$gen     = $keySvc->generate('Quota', 'full', 5000, 100000);
$plain   = $gen['plaintext'];

function sessionCount(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM council_sessions')->fetchColumn();
}

function resetEnv(string $key): void
{
    $_SERVER = ['HTTP_X_API_KEY' => $key];
    $_GET    = [];
    if (function_exists('http_response_code')) {
        http_response_code(200);
    }
}

function makeCtrl(?UserQuotaInterface $ctrlQuota, JobService $jobs, PipelineEngine $engine, SessionRepository $sessions, StepRepository $steps, ApiAuth $auth, string $lockBase): ApiV1Controller
{
    return new ApiV1Controller(
        $auth,
        new FormatAdapter(),
        $jobs,
        $engine,
        $sessions,
        $steps,
        null,
        new StreamRunLock($lockBase),
        null,
        $ctrlQuota,
    );
}

/* ====================================================================== */

sectionQ('POST /v1/runs (sync): block refuses BEFORE the engine');

$blocker = new StubQuota(blockVerdict());
$jobs = new JobService(new JobRepository(null), $sessions);
$ctrl = makeCtrl($blocker, $jobs, $engine, $sessions, $steps, $auth, $lockBase);

$callsBefore = App\Connectors\QuotaStubConnector::$chatCalls;
resetEnv($plain);
$ctrl->setBodyForTest(['query' => 'Explain the sky.', 'async' => false, 'user_id' => 7]);
$body = json_decode($ctrl->createRun(), true);
checkQuota('blocked sync run answers 429', http_response_code() === 429, 'code=' . http_response_code());
checkQuota('native error code is budget_exceeded', ($body['error'] ?? '') === 'budget_exceeded', json_encode($body));
checkQuota('the engine was never consulted', App\Connectors\QuotaStubConnector::$chatCalls === $callsBefore);
checkQuota('the quota saw the run\'s user id', $blocker->lastUserId === 7 && $blocker->calls === 1, json_encode([$blocker->lastUserId, $blocker->calls]));
checkQuota('refusal names the budget and its numbers', is_array($body['budget'] ?? null)
    && ($body['budget']['name'] ?? '') === 'Team cap'
    && (float)($body['budget']['spent'] ?? 0) === 30.5
    && (float)($body['budget']['amount'] ?? 0) === 25.0, json_encode($body['budget'] ?? null));
checkQuota('message tells the caller when it resets', str_contains((string)($body['message'] ?? ''), 'resets'), json_encode($body['message'] ?? null));
checkQuota('no session row was created for the refused run', sessionCount($sessionPdo) === 0, 'count=' . sessionCount($sessionPdo));

sectionQ('async + OpenAI envelope share the same gate');

resetEnv($plain);
$ctrl->setBodyForTest(['query' => 'Explain the sea.', 'user_id' => 7]);   // async default
$body = json_decode($ctrl->createRun(), true);
checkQuota('blocked async run answers 429 too', http_response_code() === 429 && ($body['error'] ?? '') === 'budget_exceeded', json_encode($body['error'] ?? null));

resetEnv($plain);
$_GET['format'] = 'openai';
$ctrl->setBodyForTest([
    'model'    => 'stub/echo',
    'messages' => [['role' => 'user', 'content' => 'Explain the tide.']],
]);
$body = json_decode($ctrl->createRun(), true);
checkQuota('OpenAI envelope refusal is a dialect-correct rate_limit_error',
    http_response_code() === 429
    && ($body['error']['code'] ?? '') === 'budget_exceeded'
    && ($body['error']['type'] ?? '') === 'rate_limit_error',
    json_encode($body));
$_GET = [];

sectionQ('warn proceeds; a throwing check fails open');

$warner = new StubQuota(warnVerdict());
$ctrlWarn = makeCtrl($warner, $jobs, $engine, $sessions, $steps, $auth, $lockBase);
resetEnv($plain);
$ctrlWarn->setBodyForTest(['query' => 'Explain the clouds.', 'async' => false, 'user_id' => 8]);
$callsBefore = App\Connectors\QuotaStubConnector::$chatCalls;
$body = json_decode($ctrlWarn->createRun(), true);
checkQuota('warn verdict lets the run complete', http_response_code() === 200
    && ($body['status'] ?? '') === 'completed'
    && App\Connectors\QuotaStubConnector::$chatCalls > $callsBefore,
    json_encode($body['status'] ?? null));

$burner = new StubQuota(okVerdict(), true);
$ctrlThrow = makeCtrl($burner, $jobs, $engine, $sessions, $steps, $auth, $lockBase);
resetEnv($plain);
$ctrlThrow->setBodyForTest(['query' => 'Explain the rain.', 'async' => false, 'user_id' => 9]);
$body = json_decode($ctrlThrow->createRun(), true);
checkQuota('a throwing quota check never refuses a run', http_response_code() === 200 && ($body['status'] ?? '') === 'completed', json_encode($body['error'] ?? $body['status'] ?? null));

sectionQ('JobService: the enqueue funnel refuses before any row exists');

$svcBlocker = new StubQuota(blockVerdict());
$jobsGated = new JobService(new JobRepository(null), $sessions, null, $svcBlocker);
// The successful sync runs above legitimately created sessions; what a
// refusal must not do is ADD one.
$sessionsBefore = sessionCount($sessionPdo);
$thrown = null;
try {
    $jobsGated->createJob(['query' => 'Explain the snow.', 'user_id' => 11]);
} catch (BudgetExceededException $e) {
    $thrown = $e;
}
checkQuota('createJob throws BudgetExceededException on block', $thrown !== null);
checkQuota('exception carries the user and verdict', $thrown !== null && $thrown->userId === 11 && ($thrown->verdict['action'] ?? '') === 'block');
checkQuota('exception status is 429 with Retry-After', $thrown !== null && $thrown->status() === 429 && $thrown->retryAfter() === 3600);
checkQuota('NO session row was created before the refusal', sessionCount($sessionPdo) === $sessionsBefore, 'before=' . $sessionsBefore . ' after=' . sessionCount($sessionPdo));

$created = null;
try {
    $created = $jobsGated->createJob(['query' => 'Explain the hail.', 'user_id' => null]);
} catch (\Throwable $e) {
    $created = $e;
}
checkQuota('a run without a user id is not per-user budgeted', is_array($created) && ($created['job_id'] ?? 0) > 0, json_encode($created instanceof \Throwable ? get_class($created) : $created));

sectionQ('enqueueRun renders a service-level refusal as the same 429');

// Controller-level gate says ok; the service says block. The two checks can
// race in production when spend crosses the limit between them — the
// contract is that the caller sees ONE shape either way.
$raceCtrl = makeCtrl(new StubQuota(okVerdict()), $jobsGated, $engine, $sessions, $steps, $auth, $lockBase);
$sessionsBeforeRace = sessionCount($sessionPdo);
resetEnv($plain);
$raceCtrl->setBodyForTest(['query' => 'Explain the fog.', 'user_id' => 12]);   // async default
$body = json_decode($raceCtrl->createRun(), true);
checkQuota('service-level block surfaces as 429 budget_exceeded', http_response_code() === 429 && ($body['error'] ?? '') === 'budget_exceeded', json_encode($body));
checkQuota('the raced refusal still left no session behind', sessionCount($sessionPdo) === $sessionsBeforeRace, 'before=' . $sessionsBeforeRace . ' after=' . sessionCount($sessionPdo));

sectionQ('attribution: absent user_id resolves, explicit null does not');

// The web console enqueues WITHOUT the key (default resolver = the local
// profile user); /v1 enqueues with an EXPLICIT null for callers that named
// no identity (unattributed bucket — anonymous API spend must not adopt
// the operator's local user, or the operator's own budget would govern
// strangers' runs). Same funnel, two meanings, decided by array_key_exists.
$resolverJobs = new JobService(new JobRepository(null), $sessions, static fn(): ?int => 77);
$resolverJobs->createJob(['query' => 'web-style enqueue, no user_id key']);
$uid = $sessionPdo->query('SELECT user_id FROM council_sessions ORDER BY id DESC LIMIT 1')->fetchColumn();
checkQuota('absent user_id → default resolver (web attribution)', (int)$uid === 77, var_export($uid, true));
$resolverJobs->createJob(['query' => 'v1-style enqueue, explicit null', 'user_id' => null]);
$uid = $sessionPdo->query('SELECT user_id FROM council_sessions ORDER BY id DESC LIMIT 1')->fetchColumn();
checkQuota('explicit null → unattributed (external API caller)', $uid === null, var_export($uid, true));

sectionQ('no quota wired: the launch path is byte-for-byte the old one');

$ctrlPlain = makeCtrl(null, new JobService(new JobRepository(null), $sessions), $engine, $sessions, $steps, $auth, $lockBase);
resetEnv($plain);
$ctrlPlain->setBodyForTest(['query' => 'Explain the dawn.', 'async' => false]);
$callsBefore = App\Connectors\QuotaStubConnector::$chatCalls;
$body = json_decode($ctrlPlain->createRun(), true);
checkQuota('unwired installs run exactly as before', http_response_code() === 200
    && ($body['status'] ?? '') === 'completed'
    && App\Connectors\QuotaStubConnector::$chatCalls > $callsBefore);

/* ====================================================================== */

echo implode("\n", $report), "\n";
echo "\n==================================\n";
echo "ApiQuotaGateTest: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
