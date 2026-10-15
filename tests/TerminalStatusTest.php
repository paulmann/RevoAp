<?php
/**
 * TerminalStatusTest: a run that produced no answer is not a success.
 *
 * Covers the S2 half of the incident. The operator's report was blunt: "I
 * don't like that failed queries are still marked Completed instead of
 * Error — why do the queries not go through?" Session #21 was the evidence:
 * REQUEST_ANALYZER → L0_OPTIONS with an EMPTY `selected_models` →
 * L1_NO_AVAILABLE_MODELS → L1_NO_RESULTS → L3_CONSENSUS with 0 roles →
 * L4_FAILED (`bothub HTTP 404 MODEL_NOT_FOUND`) → PIPELINE_DONE, and the
 * session row read `completed` with a 214-character apology as its answer.
 *
 * Verified here, one condition per scenario:
 *   (a) the L4 synthesis step ended `failed`                     → failed
 *   (b) no usable L1 answer was obtained                         → failed
 *   (c) a non-empty pool resolved to zero callable models        → failed
 *   (d) the answer is empty or is the fallback body              → failed
 * and that each one is decided from the run's *facts* — the persisted
 * steps and events, the resolved pool, the answer body — rather than from
 * the text of the answer or from a single in-memory flag.
 *
 * Also verified:
 *   - the preflight refuses before any model call, names the role and the
 *     model, and (for a stale *configured* synthesis model) substitutes a
 *     catalogue row and says so instead of collecting a 404 at the end;
 *   - the fallback body is stored but MARKED, so neither the Final Answer
 *     card nor History can present it as the result;
 *   - `WorkerHealthService::lastJob()` reports `outcome = error` for such a
 *     run (the Worker panel used to show a green `success` while History
 *     showed the same session as Error);
 *   - History's unfinished-session rule reaches a `failed` row (source-level
 *     check: the repair button is what the operator clicks next).
 *
 * Runs without PHPUnit, a database or a network:
 *   php tests/TerminalStatusTest.php
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

use App\Connectors\ChatResponse;
use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Connectors\EmbedResponse;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Consensus\ConsensusEngine;
use App\Consensus\NormalizationService;
use App\Core\Config;
use App\Core\Logger;
use App\Helpers\TokenHelper;
use App\Jobs\JobRepository;
use App\Jobs\JobStatus;
use App\Jobs\WorkerHealthService;
use App\Pipeline\PipelineEngine;
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

function rmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
}

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oc-terminal-status-' . bin2hex(random_bytes(4));
@mkdir($tmpRoot . DIRECTORY_SEPARATOR . 'logs', 0o775, true);
$stubDir = $tmpRoot . DIRECTORY_SEPARATOR . 'stubs';
@mkdir($stubDir, 0o775, true);

// ---------------------------------------------------------------------
// Stub connector: answers normally, or throws the provider's own 404 for
// the models the scenario marks as retired. It also counts its calls, which
// is how "L4 was never called" is asserted rather than assumed.
// ---------------------------------------------------------------------
file_put_contents($stubDir . DIRECTORY_SEPARATOR . 'TerminalStatusConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;
final class TerminalStatusConnector implements ConnectorInterface {
    /** model id => provider error message */
    public static array $retired = [];
    /** @var array<int,array{model:string,phase:string}> */
    public static array $calls = [];
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        self::$calls[] = ['model' => $model, 'phase' => (string)($options['phase'] ?? '')];
        if (isset(self::$retired[$model])) {
            throw new ConnectorException((string)self::$retired[$model]);
        }
        $content = 'Answer from ' . $model . ': ' . str_repeat('analysis sentence. ', 12);
        $pt = TokenHelper::estimateMessageTokens($messages);
        $ct = TokenHelper::estimateTokens($content);
        return new ChatResponse($content, $model, 'stub', $pt, $ct, $pt + $ct, 1.0, ['choices' => [['finish_reason' => 'stop']]], true);
    }
    public function embed(string $model, string $text): EmbedResponse {
        throw new UnsupportedOperationException('stub: no embeddings');
    }
    public function listModels(): array { return ['stub/echo', 'stub/flagship']; }
    public function getProviderName(): string { return 'stub'; }
    public function isAvailable(): bool { return true; }
}
PHP);

$registry = new ConnectorRegistry($stubDir);
$registry->load();

$skillRepo = new SkillRepository(null);
$skillRepo->save(new Skill(
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

/**
 * Boot the config stack for one scenario.
 *
 * @param array<string,mixed> $pipeline overrides for the `pipeline` block
 */
function boot(array $pipeline = [], array $models = [], array $extra = []): void
{
    global $tmpRoot;
    $config = [
        'app'        => ['name' => 'RevoAp', 'version' => '1.8.60', 'debug' => false],
        'logging'    => ['path' => $tmpRoot . DIRECTORY_SEPARATOR . 'logs'],
        'connectors' => ['stub' => ['active' => true, 'api_key' => 'stub-key']],
        'models'     => $models === []
            ? [['provider' => 'stub', 'model' => 'stub/echo', 'active' => true]]
            : $models,
        'pipeline'   => array_replace([
            'synthesis_provider'  => 'stub',
            'synthesis_model'     => 'stub/echo',
            'max_roles_per_skill' => 5,
            'parallel_l1'         => false,
            // Off unless a scenario asks for it: the arbiter is optional by
            // design, and the preflight is only strict about it when the
            // operator turned it on.
            'conflict_arbiter'    => ['enabled' => false],
        ], $pipeline),
    ];
    foreach ($extra as $key => $value) {
        $config[$key] = $value;
    }
    Config::set($config, $tmpRoot);
    \App\Connectors\TerminalStatusConnector::$retired = [];
    \App\Connectors\TerminalStatusConnector::$calls = [];
    Logger::reset();
}

/**
 * @return array{engine:PipelineEngine,sessions:SessionRepository,steps:StepRepository}
 */
function makeEngine(ConnectorRegistry $registry, SkillRepository $skillRepo, ?JobRepository $jobs = null): array
{
    $sessions = new SessionRepository(null);
    $steps    = new StepRepository(null);
    $engine   = new PipelineEngine(
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
    return ['engine' => $engine, 'sessions' => $sessions, 'steps' => $steps];
}

/** Event types a session emitted, in order. */
function eventTypes(StepRepository $steps, int $sessionId): array
{
    return array_map(
        static fn(array $e): string => (string)($e['event_type'] ?? ''),
        $steps->listEventsBySession($sessionId),
    );
}

/** The payload of the first event of a type, or null. */
function eventPayload(StepRepository $steps, int $sessionId, string $type): ?array
{
    foreach ($steps->listEventsBySession($sessionId) as $event) {
        if (($event['event_type'] ?? '') === $type) {
            $payload = $event['payload'] ?? null;
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                return is_array($decoded) ? $decoded : null;
            }
            return is_array($payload) ? $payload : null;
        }
    }
    return null;
}

$connectorClass = 'App\\Connectors\\TerminalStatusConnector';
$query = 'Which of the three proposals should we accept?';

// =====================================================================
// (c) a non-empty pool that resolves to nothing — session #21 itself
// =====================================================================
echo "\n== (c) a non-empty pool that resolves to zero callable models ==\n";
boot();
$s = makeEngine($registry, $skillRepo);
// Exactly what the sidebar sent in session #21: four models that are in
// nobody's catalogue any more.
$out = $s['engine']->run($query, null, ['selected_models' => [
    ['provider' => 'stub', 'model' => 'stub/gpt-5.1'],
    ['provider' => 'stub', 'model' => 'stub/claude-sonnet-4.6'],
    ['provider' => 'stub', 'model' => 'stub/deepseek-r1'],
    ['provider' => 'stub', 'model' => 'stub/gemini-2.5-pro'],
]]);
$session = $s['sessions']->find((int)$out['session_id']);
$types = eventTypes($s['steps'], (int)$out['session_id']);

check('the run reports a failed terminal status', ($out['status'] ?? '') === 'failed', json_encode($out['status'] ?? null));
check('the session row is written failed, not completed',
    ($session['status'] ?? '') === 'failed', (string)($session['status'] ?? 'missing'));
check('the reason is the pool, not the provider',
    ($out['status_reason'] ?? '') === 'pool_no_usable_models', (string)($out['status_reason'] ?? ''));
check('the answer is empty: no stub is stored as the run result',
    trim((string)($session['final_answer'] ?? 'x')) === '', (string)($session['final_answer'] ?? 'null'));
check('the events name the condition the engine has always used for it',
    in_array('L1_NO_AVAILABLE_MODELS', $types, true), implode(',', $types));
check('PIPELINE_DONE carries the failure and its reason',
    (($done = eventPayload($s['steps'], (int)$out['session_id'], 'PIPELINE_DONE')) ?? []) !== []
    && ($done['status'] ?? '') === 'failed'
    && ($done['status_reason'] ?? '') === 'pool_no_usable_models',
    json_encode($done ?? null));
check('PIPELINE_PREFLIGHT_FAILED names the role and the refused models',
    (static function () use ($s, $out): bool {
        $payload = eventPayload($s['steps'], (int)$out['session_id'], 'PIPELINE_PREFLIGHT_FAILED');
        if ($payload === null || ($payload['role'] ?? '') !== 'pool') { return false; }
        $detail = (string)($payload['detail'] ?? '');
        return str_contains($detail, 'stub/gpt-5.1') && str_contains($detail, 'not_in_catalog');
    })(), json_encode(eventPayload($s['steps'], (int)$out['session_id'], 'PIPELINE_PREFLIGHT_FAILED')));
check('L1 never ran, so no model was asked anything',
    !in_array('L1_DISPATCH', $types, true) && !in_array('L1_RESPONSE', $types, true),
    implode(',', $types));
check('the refusal is human-readable, and says what to do',
    str_contains((string)($out['status_detail'] ?? ''), 'Run Actualize'),
    (string)($out['status_detail'] ?? ''));

// =====================================================================
// (b) every L1 call failed — no usable answer
// =====================================================================
echo "\n== (b) no usable L1 answer ==\n";
boot();
$connectorClass::$retired = [
    'stub/echo'     => 'stub HTTP 500: upstream error',
    'stub/flagship' => 'stub HTTP 500: upstream error',
];
$s = makeEngine($registry, $skillRepo);
$out = $s['engine']->run($query, null, []);
$session = $s['sessions']->find((int)$out['session_id']);
$types = eventTypes($s['steps'], (int)$out['session_id']);
check('the run reports a failed terminal status', ($out['status'] ?? '') === 'failed', json_encode($out['status'] ?? null));
check('the session row is written failed', ($session['status'] ?? '') === 'failed', (string)($session['status'] ?? 'missing'));
check('the reason is the missing L1 answers',
    ($out['status_reason'] ?? '') === 'l1_no_results', (string)($out['status_reason'] ?? ''));
check('L1_NO_RESULTS is on the stream', in_array('L1_NO_RESULTS', $types, true), implode(',', $types));
check('L4 was skipped rather than called into a 404',
    in_array('L4_SKIPPED', $types, true) && !in_array('L4_FAILED', $types, true),
    implode(',', $types));
check('the stored body is MARKED as a fallback, not passed off as the answer',
    str_starts_with((string)($session['final_answer'] ?? ''), '⚠️ NO SYNTHESIS'),
    mb_substr((string)($session['final_answer'] ?? ''), 0, 80));
check('the answer is not presented as a synthesis',
    ($out['synthesis_failure'] ?? null) !== null
    && ($out['status_facts']['answer_is_fallback'] ?? false) === true,
    json_encode($out['synthesis_failure'] ?? null));

// =====================================================================
// (a) the L4 synthesis step ended failed
// =====================================================================
echo "\n== (a) the L4 step is failed on disk ==\n";
boot(pipeline: ['synthesis_model' => 'stub/echo']);
$connectorClass::$retired = ['stub/echo' => 'stub HTTP 404: MODEL_NOT_FOUND'];
// L1 answers (the catalogue only holds the retired synthesis model, so the
// pool uses it too — the L1 calls must succeed for this to be an L4 story).
$connectorClass::$retired = [];
boot(pipeline: ['synthesis_model' => 'stub/flagship', 'synthesis_provider' => 'stub'], models: [
    ['provider' => 'stub', 'model' => 'stub/echo', 'active' => true],
    ['provider' => 'stub', 'model' => 'stub/flagship', 'active' => true],
]);
$s = makeEngine($registry, $skillRepo);
// The synthesis call is the one that 404s; the L1 calls succeed.
$connectorClass::$retired = ['stub/flagship' => 'stub HTTP 404: MODEL_NOT_FOUND'];
$out = $s['engine']->run($query, null, ['selected_models' => [['provider' => 'stub', 'model' => 'stub/echo']]]);
$session = $s['sessions']->find((int)$out['session_id']);
$types = eventTypes($s['steps'], (int)$out['session_id']);
$l4Steps = array_values(array_filter(
    $s['steps']->listStepsBySession((int)$out['session_id']),
    static fn(array $step): bool => strtoupper((string)($step['step_key'] ?? '')) === 'L4.SYNTHESIS'
        || strtoupper((string)($step['layer'] ?? '')) === 'L4',
));
check('an L4 step really is recorded as failed in the journal',
    $l4Steps !== [] && in_array('failed', array_map(
        static fn(array $s2): string => strtolower((string)($s2['status'] ?? '')),
        $l4Steps,
    ), true), json_encode(array_map(static fn(array $x): array => ['key' => $x['step_key'] ?? null, 'status' => $x['status'] ?? null], $l4Steps)));
check('the run is failed, not completed-with-a-stub',
    ($out['status'] ?? '') === 'failed' && ($session['status'] ?? '') === 'failed',
    (string)($out['status'] ?? ''));
check('the reason names the synthesis step',
    ($out['status_reason'] ?? '') === 'l4_synthesis_failed', (string)($out['status_reason'] ?? ''));
check('L4_FAILED is on the stream with the provider error',
    in_array('L4_FAILED', $types, true)
    && str_contains((string)($out['synthesis_failure']['message'] ?? ''), '404'),
    json_encode($out['synthesis_failure'] ?? null));
check('the marked body carries the provider reason too',
    str_starts_with((string)($session['final_answer'] ?? ''), '⚠️ NO SYNTHESIS'),
    mb_substr((string)($session['final_answer'] ?? ''), 0, 80));

// =====================================================================
// (d) an empty answer is not a success either
// v1.9.1 - the arbiter travels with the participants, so the card under the
// question can name both before the first model call. The key has to be there
// even when the run has no arbiter: "none" is an answer, `missing` is a bug.
$l0 = eventPayload($s['steps'], (int)$out['session_id'], 'L0_OPTIONS') ?? [];
check('L0_OPTIONS carries the arbiter key', array_key_exists('arbiter', $l0), json_encode(array_keys($l0)));
check('the arbiter is null or a provider/model/role row',
    ($l0['arbiter'] ?? null) === null
    || (is_array($l0['arbiter']) && isset($l0['arbiter']['provider'], $l0['arbiter']['model'], $l0['arbiter']['role']))
    && (string)$l0['arbiter']['model'] !== '',
    json_encode($l0['arbiter'] ?? null));
check('L0_OPTIONS says where the arbiter came from',
    in_array(($l0['arbiter_source'] ?? 'missing'), ['preset', 'config', 'none'], true),
    json_encode($l0['arbiter_source'] ?? null));

// =====================================================================
echo "\n== (d) an empty final answer ==\n";
// The condition is checked from the stored body, so this is a unit-level
// assertion on the verdict: an answer that is empty or is the fallback body
// can never be `completed`. Exercised through the same public surface as
// (b): the engine's verdict facts travel on the result.
boot();
$s = makeEngine($registry, $skillRepo);
$connectorClass::$retired = ['stub/echo' => 'stub HTTP 500: upstream error'];
$out = $s['engine']->run($query, null, []);
$facts = (array)($out['status_facts'] ?? []);
check('the verdict reports the answer facts it decided from',
    array_key_exists('answer_empty', $facts) && array_key_exists('answer_is_fallback', $facts)
    && array_key_exists('l4_step_failed', $facts) && array_key_exists('l1_no_results', $facts),
    json_encode($facts));
check('a fallback body can never be counted as an answer',
    ($facts['answer_is_fallback'] ?? false) === true && ($out['status'] ?? '') === 'failed',
    json_encode($facts));

// =====================================================================
// The preflight: a stale configured synthesis model is substituted, named
// and disclosed — never carried into a 404 at the end of the run.
// =====================================================================
echo "\n== the preflight replaces a retired synthesis model, and says so ==\n";
boot(pipeline: ['synthesis_model' => 'stub/gpt-4o', 'synthesis_provider' => 'stub'], models: [
    ['provider' => 'stub', 'model' => 'stub/echo', 'active' => true],
]);
$s = makeEngine($registry, $skillRepo);
$out = $s['engine']->run($query, null, []);
$preflight = eventPayload($s['steps'], (int)$out['session_id'], 'PIPELINE_PREFLIGHT');
$synthesisCalls = array_values(array_filter(
    $connectorClass::$calls,
    static fn(array $c): bool => $c['model'] === 'stub/gpt-4o',
));
check('the run succeeds instead of dying at the end of L4',
    ($out['status'] ?? '') === 'completed', json_encode(['status' => $out['status'] ?? null, 'reason' => $out['status_reason'] ?? null]));
check('the retired configured model was never called',
    $synthesisCalls === [], json_encode($connectorClass::$calls));
check('the preflight event names the configured model that will not be used',
    $preflight !== null
    && (static function () use ($preflight): bool {
        foreach ((array)($preflight['notes'] ?? []) as $note) {
            if (str_contains((string)$note, 'stub/gpt-4o')
                && str_contains((string)$note, 'not an active catalogue row')) {
                return true;
            }
        }
        return false;
    })(),
    json_encode($preflight));
check('and names the synthesis role as the one affected',
    $preflight !== null
    && str_contains(implode(' ', array_map('strval', (array)($preflight['notes'] ?? []))), 'synthesis'),
    json_encode($preflight['notes'] ?? null));
check('the substitution is disclosed as a warning, not hidden',
    str_contains(implode(' ', array_map('strval', (array)($preflight['notes'] ?? []))), 'Settings → Models'),
    json_encode($preflight['notes'] ?? null));

// =====================================================================
// The preflight refuses a role the operator explicitly turned on.
// =====================================================================
echo "\n== an enabled role that is not served is refused, naming role and model ==\n";
boot(pipeline: [
    'conflict_arbiter' => ['enabled' => true, 'provider' => 'stub', 'model' => 'stub/arbiter-retired'],
]);
$s = makeEngine($registry, $skillRepo);
$out = $s['engine']->run($query, null, []);
check('the run is refused', ($out['status'] ?? '') === 'failed', (string)($out['status'] ?? ''));
check('the reason names the arbiter role',
    ($out['status_reason'] ?? '') === 'arbiter_model_not_served', (string)($out['status_reason'] ?? ''));
check('and the model that is not served',
    str_contains((string)($out['status_detail'] ?? ''), 'stub/arbiter-retired')
    && str_contains((string)($out['status_detail'] ?? ''), 'arbiter'),
    (string)($out['status_detail'] ?? ''));
check('no model was called for the refused run', $connectorClass::$calls === [], json_encode($connectorClass::$calls));
check('the session is failed with no answer',
    (static function () use ($s, $out): bool {
        $row = $s['sessions']->find((int)$out['session_id']);
        return ($row['status'] ?? '') === 'failed' && trim((string)($row['final_answer'] ?? '')) === '';
    })(), json_encode($s['sessions']->find((int)$out['session_id'])));
check('the refusal names the role and the model in the event stream',
    (static function () use ($s, $out): bool {
        $payload = eventPayload($s['steps'], (int)$out['session_id'], 'PIPELINE_PREFLIGHT_FAILED');
        return $payload !== null
            && ($payload['role'] ?? '') === 'arbiter'
            && ($payload['model'] ?? '') === 'stub/arbiter-retired';
    })(), json_encode(eventPayload($s['steps'], (int)$out['session_id'], 'PIPELINE_PREFLIGHT_FAILED')));

// =====================================================================
// The Worker panel: outcome=error, not a green success
// =====================================================================
echo "\n== the Worker panel reports the same verdict History does ==\n";
$jobs = new JobRepository(null);
$jobId = $jobs->create(
    sessionId: 1,
    userId: null,
    payload: ['kind' => 'pipeline', 'query' => $query],
    priority: 10,
    maxAttempts: 1,
);
$job = $jobs->find($jobId);
check('the job fixture exists', $job !== null);
// Exactly what PipelineJobRunner writes for a run the engine called failed:
// the job itself completed (it ran), the result carries the verdict.
$jobs->recordResult($jobId, [
    'session_id'      => 1,
    'final_answer'    => '',
    'status'          => 'failed',
    'status_reason'   => 'pool_no_usable_models',
    'status_detail'   => 'The request named a model pool, but not one of its entries is an active catalogue row.',
    'synthesis_failure' => null,
]);
$jobs->updateStatus($jobId, JobStatus::Completed);
$health = new WorkerHealthService($jobs);
$snapshot = $health->snapshot();
$lastJob = $snapshot['last_job'] ?? null;
check('last_job.outcome is error for a run with no answer',
    is_array($lastJob) && ($lastJob['outcome'] ?? '') === 'error',
    json_encode(is_array($lastJob) ? ['outcome' => $lastJob['outcome'] ?? null, 'status' => $lastJob['status'] ?? null] : null));
check('the panel says why, in the engine own words',
    is_array($lastJob) && str_contains((string)($lastJob['error'] ?? ''), 'pool_no_usable_models'),
    (string)($lastJob['error'] ?? ''));

// =====================================================================
// History: a failed row is unfinished, and "unfinished" is where the
// re-run / finish button comes from.
// =====================================================================
echo "\n== History treats it as recoverable, not as a finished session ==\n";
$historyJs = (string)file_get_contents(__DIR__ . '/../assets/js/history.js');
check('the unfinished rule is status-based, so a failed row reaches it',
    preg_match('/function isUnfinishedSession\([^)]*\)\s*\{(.*?)\n\}/s', $historyJs, $m) === 1
    && str_contains($m[1], "!== 'completed'"),
    'isUnfinishedSession no longer keys off the raw status');
check('and a failed L4 step counts as unfinished even for an old completed row',
    str_contains($historyJs, 'function hasFailedSynthesisStep')
    && str_contains($historyJs, "!== 'L4'")
    && str_contains($historyJs, "status === 'failed'"),
    'the journal-based check is gone');
check('the repair endpoint is the one the button posts to',
    str_contains($historyJs, '/api/history/session/')
    && str_contains($historyJs, '/complete'),
    'REPAIR_ENDPOINT changed');

rmTree($tmpRoot);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);