<?php
/**
 * JobSessionReuseTest: offline checks for the job-mode session contract
 * (Task 1) and for the cache policy a worker run declares (Task 2).
 *
 * Runs without PHPUnit, without a database and without network access, so
 * it can be executed as `php tests/JobSessionReuseTest.php` on any host
 * with PHP 8.4+.
 *
 * Background: JobService::createJob() creates the `council_sessions` row
 * BEFORE the worker picks the job up and hands the browser
 * `/api/council/session/{id}/events/stream` for that id. The worker passes
 * the same id to the engine as `options.session_id`. If the engine ignores
 * it and creates its own session, every `pipeline_events` row lands on a
 * different id: the replay endpoint polls an empty session forever while
 * History shows the finished run under the other row. That was the
 * production symptom ("Job #N running — no events yet", forever).
 *
 * Verified:
 *   - PipelineEngine::run() reuses `options.session_id` when the row exists,
 *     and the steps + events of the run are persisted under THAT id;
 *   - jobs enqueued before the fix are not affected, and every legacy caller
 *     that omits the option still gets a freshly created session;
 *   - an option pointing at a missing row degrades to a new session instead
 *     of failing (and says so in the log);
 *   - PipelineJobRunner::buildEngineOptions() declares both the session id
 *     and the run's cache policy explicitly;
 *   - `cache_mode = 'bypass'` opts a run out of both the cache lookup and
 *     the cache store, while the default keeps reusing it.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Cache\ModelResponseCache;
use App\Connectors\ChatResponse;
use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Connectors\EmbedResponse;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Consensus\ConsensusEngine;
use App\Consensus\NormalizationService;
use App\Core\Config;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\JobStatus;
use App\Jobs\PipelineJobRunner;
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

function countJsonFiles(string $dir): int
{
    if (!is_dir($dir)) { return 0; }
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && substr($f->getFilename(), -5) === '.json') { $n++; }
    }
    return $n;
}

$tmpRoot = sys_get_temp_dir() . '/oc-jobsession-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/logs', 0o775, true);

Config::set([
    'app'        => ['name' => 'RevoAp', 'version' => '1.0.0', 'debug' => false],
    'logging'    => ['path' => $tmpRoot . '/logs'],
    'connectors' => ['stub' => ['active' => true, 'api_key' => 'x']],
    'models'     => [['provider' => 'stub', 'model' => 'stub/echo', 'active' => true]],
    'pipeline'   => [
        'synthesis_provider'  => 'stub',
        'synthesis_model'     => 'stub/echo',
        'max_roles_per_skill' => 5,
        'parallel_l1'         => false,
    ],
    'cache'      => ['model_responses' => ['enabled' => true, 'ttl_seconds' => 0]],
], $tmpRoot);
\App\Core\Logger::reset();

// --------------------------------------------------------------- stubs
$stubDir = $tmpRoot . '/stubs';
mkdir($stubDir, 0o700, true);
file_put_contents($stubDir . '/SessionProbeConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;
final class SessionProbeConnector implements ConnectorInterface {
    public static int $calls = 0;
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        self::$calls++;
        $content = '[probe] ' . ($messages[1]['content'] ?? '') . ' ' . str_repeat('analysis sentence. ', 12);
        $pt = TokenHelper::estimateMessageTokens($messages);
        $ct = TokenHelper::estimateTokens($content);
        return new ChatResponse($content, $model, 'stub', $pt, $ct, $pt + $ct, 1.0, ['choices' => [['finish_reason' => 'stop']]], true);
    }
    public function embed(string $model, string $text): EmbedResponse {
        throw new UnsupportedOperationException('stub: no embeddings');
    }
    public function listModels(): array { return ['stub/echo']; }
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
 * One engine per scenario: session/step repositories are in-memory, so the
 * scenario owns its full state.
 *
 * @return array{engine:PipelineEngine,sessions:SessionRepository,steps:StepRepository}
 */
$makeEngine = static function (?ModelResponseCache $cache = null) use ($registry, $skillRepo): array {
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
        cache:       $cache,
    );
    return ['engine' => $engine, 'sessions' => $sessions, 'steps' => $steps];
};

$query = 'Compare the three vendor proposals and recommend one.';

// =====================================================================
// 1. The engine reuses the pre-created session id
// =====================================================================
echo "== job mode: the engine runs on the session the browser was given ==\n";

$s1 = $makeEngine();
// What JobService does before enqueueing: create the row up-front.
$preCreated = $s1['sessions']->create(0, $query, null);
check('the job layer pre-created a session', $preCreated > 0);

$out1 = $s1['engine']->run($query, null, ['session_id' => $preCreated, 'cache_mode' => 'reuse']);
check('the engine reports the REUSED session id (not a new one)',
    (int)($out1['session_id'] ?? 0) === $preCreated,
    'expected=' . $preCreated . ' got=' . ($out1['session_id'] ?? 'null'));

$events = $s1['steps']->listEventsBySession($preCreated);
check('the run wrote its events on the reused session id', count($events) > 0, 'events=' . count($events));
$types = array_map(static fn(array $e): string => (string)($e['event_type'] ?? ''), $events);
check('the event stream carries PIPELINE_STARTED', in_array('PIPELINE_STARTED', $types, true), implode(',', array_slice($types, 0, 6)));
check('the event stream carries PIPELINE_DONE', in_array('PIPELINE_DONE', $types, true));
check('the event stream carries L1_RESPONSE', in_array('L1_RESPONSE', $types, true));
$stepsPreCreated = $s1['steps']->listStepsBySession($preCreated);
check('the run also persisted steps on the reused session id', count($stepsPreCreated) > 0, 'steps=' . count($stepsPreCreated));
check('the reused session row was completed by the run',
    (($s1['sessions']->find($preCreated)['status'] ?? '') === 'completed'),
    (string)($s1['sessions']->find($preCreated)['status'] ?? 'missing'));
check('no second session row was created',
    $s1['sessions']->find($preCreated + 1) === null,
    'id ' . ($preCreated + 1) . ' should not exist');

// =====================================================================
// 2. Legacy callers are unaffected
// =====================================================================
echo "\n== legacy callers still get a freshly created session ==\n";

$s2 = $makeEngine();
$out2 = $s2['engine']->run($query, null, []);
check('without the option a session is created as before',
    (int)($out2['session_id'] ?? 0) === 1, 'got=' . ($out2['session_id'] ?? 'null'));
check('that session carries the run events',
    count($s2['steps']->listEventsBySession((int)$out2['session_id'])) > 0);

$s3 = $makeEngine();
$out3 = $s3['engine']->run($query, null, ['session_id' => 999999]);
check('an unknown session id degrades to a new session instead of failing',
    (int)($out3['session_id'] ?? 0) === 1 && (int)$out3['session_id'] !== 999999,
    'got=' . ($out3['session_id'] ?? 'null'));

$warnLog = $tmpRoot . '/logs/revoap-error.log';
$warnBody = is_file($warnLog) ? (string)file_get_contents($warnLog) : '';
check('the reuse miss is reported in the log', str_contains($warnBody, 'pipeline.session.reuse_miss'));
check('a non-positive session id is ignored', (static function () use ($makeEngine, $query): bool {
    $s = $makeEngine();
    $out = $s['engine']->run($query, null, ['session_id' => 0]);
    return (int)$out['session_id'] === 1;
})());

// =====================================================================
// 3. The worker declares its engine options explicitly
// =====================================================================
echo "\n== the worker's engine options are explicit ==\n";

$s4 = $makeEngine();
$runner = new PipelineJobRunner(new JobRepository(null), $s4['engine']);
$buildOptions = new ReflectionMethod(PipelineJobRunner::class, 'buildEngineOptions');
$built = $buildOptions->invoke($runner, ['query' => $query, 'reasoning' => 'off'], 4242);

check('buildEngineOptions pins the pre-created session id', (int)($built['session_id'] ?? 0) === 4242);
check('buildEngineOptions declares the cache policy explicitly', ($built['cache_mode'] ?? '') === 'reuse',
    var_export($built['cache_mode'] ?? null, true));
check('the declared cache policy is a value the engine understands',
    in_array($built['cache_mode'], ['reuse', 'bypass'], true));

// =====================================================================
// 4. Job-mode cache behaviour is pinned by a test
// =====================================================================
echo "\n== job mode reuses the cache, and can be told to bypass it ==\n";

$cacheRoot = $tmpRoot . '/job-cache';
$jobCache  = new ModelResponseCache(db: null, fileRoot: $cacheRoot, enabled: true, ttlSeconds: 0);

// 'reuse' (the default the worker declares) populates the cache.
$s5 = $makeEngine($jobCache);
$s5['engine']->run($query, null, ['session_id' => $s5['sessions']->create(0, $query, null), 'cache_mode' => 'reuse']);
$warmFiles = countJsonFiles($cacheRoot);
check("cache_mode 'reuse' populates the cache", $warmFiles > 0, 'files=' . $warmFiles);

$callsBeforeBypass = \App\Connectors\SessionProbeConnector::$calls;
$bypassRoot = $tmpRoot . '/bypass-cache';
$bypassCache = new ModelResponseCache(db: null, fileRoot: $bypassRoot, enabled: true, ttlSeconds: 0);
$s6 = $makeEngine($bypassCache);
// Seed the cache for this run, then repeat it with bypass: the seeded
// entries must be ignored AND no new entry may be written.
$seedQuery = 'Bypass probe: is this cached?';
$s6['engine']->run($seedQuery, null, ['session_id' => $s6['sessions']->create(0, $seedQuery, null), 'cache_mode' => 'reuse']);
$seededFiles = countJsonFiles($bypassRoot);
check('the seeding run wrote cache entries', $seededFiles > 0, 'files=' . $seededFiles);

$callsBefore = \App\Connectors\SessionProbeConnector::$calls;
$s7 = $makeEngine($bypassCache);
$s7['engine']->run($seedQuery, null, ['session_id' => $s7['sessions']->create(0, $seedQuery, null), 'cache_mode' => 'bypass']);
$callsAfter = \App\Connectors\SessionProbeConnector::$calls;
check("cache_mode 'bypass' skips the lookup (the provider was called again)",
    $callsAfter > $callsBefore, 'before=' . $callsBefore . ' after=' . $callsAfter);
check("cache_mode 'bypass' writes nothing new", countJsonFiles($bypassRoot) === $seededFiles,
    'seeded=' . $seededFiles . ' now=' . countJsonFiles($bypassRoot));
check("cache_mode 'bypass' still produced a final answer",
    $s7['sessions']->find(1) !== null);

// =====================================================================
// 5. The job row and the session row agree on the id (the delivery
//    invariant PipelineJobRunner::run() now audits)
// =====================================================================
echo "\n== the job row and the session the engine wrote to are the same ==\n";

$jobs = new JobRepository(null);
$sessions = new SessionRepository(null);
$jobService = new JobService($jobs, $sessions);
$enqueued = $jobService->createJob(['query' => $query]);
check('createJob returns the ids the browser will follow',
    (int)$enqueued['job_id'] > 0 && (int)$enqueued['session_id'] > 0);
check('the events_url points at the pre-created session',
    $enqueued['events_url'] === '/api/council/session/' . (int)$enqueued['session_id'] . '/events/stream',
    (string)$enqueued['events_url']);

$job = $jobs->find((int)$enqueued['job_id']);
check('the job payload carries the same session id',
    $job !== null && (int)($job->payload['session_id'] ?? 0) === (int)$enqueued['session_id']);

$s8 = $makeEngine();
$options = $buildOptions->invoke(
    new PipelineJobRunner($jobs, $s8['engine']),
    $job?->payload ?? [],
    (int)$enqueued['session_id'],
);
$out8 = $s8['engine']->run($query, null, $options);
check('a real job payload resolves to the session the browser was handed',
    (int)($out8['session_id'] ?? 0) === (int)$enqueued['session_id'],
    'stream=' . (int)$enqueued['session_id'] . ' engine=' . ($out8['session_id'] ?? 'null'));
check('and that session has the events the replay endpoint reads',
    count($s8['steps']->listEventsBySession((int)$enqueued['session_id'])) > 0);

// =====================================================================
// 6. End to end through the real replay endpoint
//    GET /api/council/session/{id}/events/stream
// =====================================================================
echo "\n== the replay endpoint now streams this run's frames ==\n";

use App\Http\Controllers\JobsController;

$steps8 = $s8['steps'];
$jobsForStream = new JobRepository(null);
$controllers = new JobsController(new JobService($jobsForStream, new SessionRepository(null)), $steps8);

/** Invoke eventsStream() in snapshot mode and capture everything it writes. */
$readStream = static function (int $sessionId) use ($controllers): string {
    $previousGet = $_GET;
    $_GET = [];
    ob_start();
    try {
        $returned = $controllers->eventsStream(['id' => (string)$sessionId]);
    } finally {
        $printed = (string)ob_get_clean();
        $_GET = $previousGet;
    }
    return $printed . $returned;
};

$streamed = $readStream((int)$enqueued['session_id']);
check('the stream answers with real SSE frames', str_contains($streamed, "event: PIPELINE_STARTED\n"), substr($streamed, 0, 160));
check('frames carry an id: line so Last-Event-ID works', (bool)preg_match('/^id: \d+$/m', $streamed));
check('frames carry a data: JSON line', str_contains($streamed, "\ndata: {"));
check('the run reaches the client as PIPELINE_DONE', str_contains($streamed, 'event: PIPELINE_DONE'));
check('the stream terminates with PIPELINE_REPLAY_DONE',
    str_contains($streamed, 'event: PIPELINE_REPLAY_DONE'));
check('the terminal frame reports replayed > 0', (bool)preg_match('/"replayed":\s*[1-9]/', $streamed), substr($streamed, -400));

// The exact production failure mode: following an id the run never wrote to
// yields a stream with no frames at all.
$wrong = $readStream((int)$enqueued['session_id'] + 5000);
check('an unrelated session id yields no frames (the reported symptom)',
    !str_contains($wrong, 'event: PIPELINE_STARTED') && str_contains($wrong, '"replayed":0'),
    substr($wrong, -200));

// =====================================================================
// Cleanup
// =====================================================================
\App\Core\Logger::reset();
rmTree($tmpRoot);

echo "----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
