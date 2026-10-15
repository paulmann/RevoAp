<?php
/**
 * ModelResponseCacheTest: offline checks for the model response cache
 * (Task 2). Runs without PHPUnit, without a database and without network
 * access, so it can be executed as
 * `php tests/ModelResponseCacheTest.php` on any host with PHP 8.4+.
 *
 * Verified:
 *   (a) an LLM error / empty answer is NEVER cached and never replayed —
 *       at the class boundary AND end to end through PipelineEngine;
 *   (b) the key separates models and every significant option, while
 *       transport-only options do not split the cache; message-boundary
 *       and role-boundary ambiguity is impossible;
 *   (c) an unreachable cache directory degrades instead of failing: the
 *       run completes, the write is refused, and the refusal is logged;
 *   (d) the cache is isolated in tests: everything is written under a
 *       private temp root, the working `cache/` directory is untouched,
 *       and a populated working cache cannot influence a temp-rooted
 *       instance;
 *   (e) TTL expiry invalidates the entry instead of only ignoring it, and
 *       purge()/dropKey() give the operator a real invalidation path for
 *       the shipped `ttl_seconds = 0` default.
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
use App\Core\Logger;
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

$tmpRoot = sys_get_temp_dir() . '/oc-cache-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/logs', 0o775, true);

// app.debug=false + isolated logging/noise-free config.
Config::set([
    'app'       => ['name' => 'RevoAp', 'version' => '1.0.0', 'debug' => false],
    'logging'   => ['path' => $tmpRoot . '/logs'],
    'connectors'=> ['stub' => ['active' => true, 'api_key' => 'x']],
    'models'    => [['provider' => 'stub', 'model' => 'stub/echo', 'active' => true]],
    'pipeline'  => [
        'synthesis_provider' => 'stub',
        'synthesis_model'    => 'stub/echo',
        'max_roles_per_skill'=> 5,
        'parallel_l1'        => false,
    ],
    'cache'     => ['model_responses' => ['enabled' => true, 'ttl_seconds' => 0]],
], $tmpRoot);
Logger::reset();

// =====================================================================
// (a) Errors and empty answers are never cached
// =====================================================================
echo "== (a) an LLM error is never cached or replayed ==\n";

$cacheRoot = $tmpRoot . '/cache';
$cache = new ModelResponseCache(db: null, fileRoot: $cacheRoot, enabled: true, ttlSeconds: 0);
$messages = [
    ['role' => 'system', 'content' => 'You are an expert.'],
    ['role' => 'user',   'content' => 'How should I evaluate three job offers?'],
];
$opts = ['temperature' => 0.5];

$empty = new ChatResponse('', 'stub/echo', 'stub', 0, 0, 0, 1.0, ['stub' => true], false);
check('empty answer is not cacheable', ModelResponseCache::isCacheable($empty) === false);
$storeEmpty = $cache->store('stub', 'stub/echo', $messages, $opts, $empty);
check('store() refuses an empty answer', ($storeEmpty['stored'] ?? true) === false);
check('store() reports why it refused', ($storeEmpty['refused'] ?? '') === 'empty_content');
check('an empty answer leaves no entry to replay', $cache->lookup('stub', 'stub/echo', $messages, $opts) === null);
check('an empty answer wrote no file', countJsonFiles($cacheRoot) === 0);

$errorEnvelope = new ChatResponse(
    content: 'Service temporarily unavailable', model: 'stub/echo', provider: 'stub',
    promptTokens: 1, completionTokens: 1, totalTokens: 2, latencyMs: 1.0,
    rawResponse: ['error' => ['message' => 'upstream 503', 'code' => 503]], tokensEstimated: false,
);
check('error envelope is not cacheable', ModelResponseCache::isCacheable($errorEnvelope) === false);
$storeErr = $cache->store('stub', 'stub/echo', $messages, $opts, $errorEnvelope);
check('store() refuses an error envelope', ($storeErr['stored'] ?? true) === false);
check('store() reports error_envelope', ($storeErr['refused'] ?? '') === 'error_envelope');
check('an error envelope is not replayed', $cache->lookup('stub', 'stub/echo', $messages, $opts) === null);

$okFalse = new ChatResponse(
    content: 'text', model: 'stub/echo', provider: 'stub', promptTokens: 1, completionTokens: 1,
    totalTokens: 2, latencyMs: 1.0, rawResponse: ['ok' => false], tokensEstimated: false,
);
check('ok:false is not cacheable', ModelResponseCache::isCacheable($okFalse) === false);
$filtered = new ChatResponse(
    content: 'text', model: 'stub/echo', provider: 'stub', promptTokens: 1, completionTokens: 1,
    totalTokens: 2, latencyMs: 1.0,
    rawResponse: ['choices' => [['finish_reason' => 'content_filter']]], tokensEstimated: false,
);
check('content_filter is not cacheable', ModelResponseCache::isCacheable($filtered) === false);

$good = new ChatResponse(
    content: 'A structured comparison of the three offers.', model: 'stub/echo', provider: 'stub',
    promptTokens: 5, completionTokens: 7, totalTokens: 12, latencyMs: 200.0,
    rawResponse: ['choices' => [['finish_reason' => 'stop']]], tokensEstimated: false,
);
check('a normal answer is cacheable', ModelResponseCache::isCacheable($good) === true);
$storeGood = $cache->store('stub', 'stub/echo', $messages, $opts, $good);
check('a normal answer is stored', ($storeGood['stored'] ?? false) === true);
$hitGood = $cache->lookup('stub', 'stub/echo', $messages, $opts);
check('a normal answer is served from the cache', is_array($hitGood) && $hitGood['response']->content === $good->content);

// End-to-end: a run whose provider only returns errors must leave the
// cache empty, and the following healthy run must repopulate it.
echo "\n== (a) end-to-end: a failing provider cannot poison the cache ==\n";

$stubDir = $tmpRoot . '/stubs';
mkdir($stubDir, 0o700, true);
file_put_contents($stubDir . '/CacheProbeConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;
final class CacheProbeConnector implements ConnectorInterface {
    /** When true, every chat() answers with an error envelope. */
    public static bool $broken = false;
    public static int  $calls  = 0;
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        self::$calls++;
        if (self::$broken) {
            return new ChatResponse('', $model, 'stub', 0, 0, 0, 1.0, ['error' => ['message' => 'upstream 503']], false);
        }
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

$e2eRoot = $tmpRoot . '/e2e-cache';
$e2eCache = new ModelResponseCache(db: null, fileRoot: $e2eRoot, enabled: true, ttlSeconds: 0);
// Discovery is explicit here so the harness can talk to the stub's static
// call counter directly (the engine would otherwise load it lazily).
$stubRegistry = new ConnectorRegistry($stubDir);
$stubRegistry->load();
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

$makeEngine = static function () use ($stubRegistry, $skillRepo, $e2eCache): PipelineEngine {
    return new PipelineEngine(
        connectors:  $stubRegistry,
        sessions:    new SessionRepository(null),
        steps:       new StepRepository(null),
        responses:   new ResponseRepository(null),
        skills:      $skillRepo,
        router:      new SkillRouter($skillRepo),
        autoCreator: new SkillAutoCreator($skillRepo, $stubRegistry),
        normalizer:  new NormalizationService(),
        consensus:   new ConsensusEngine(),
        stats:       new StatsCollector(),
        cache:       $e2eCache,
    );
};

\App\Connectors\CacheProbeConnector::$broken = true;
\App\Connectors\CacheProbeConnector::$calls  = 0;
$brokenOut = $makeEngine()->run('Should I take the offer with the higher equity?');
check('the pipeline still returns a result while the provider errors',
    is_array($brokenOut) && array_key_exists('final_answer', $brokenOut));
check('a fully failing run wrote NOTHING to the cache', countJsonFiles($e2eRoot) === 0,
    'files=' . countJsonFiles($e2eRoot));
check('the failing run really did call the provider',
    \App\Connectors\CacheProbeConnector::$calls > 0, 'calls=' . \App\Connectors\CacheProbeConnector::$calls);

\App\Connectors\CacheProbeConnector::$broken = false;
\App\Connectors\CacheProbeConnector::$calls  = 0;
$healthyOut = $makeEngine()->run('Should I take the offer with the higher equity?');
check('the healthy run produced a final answer', is_string($healthyOut['final_answer']) && $healthyOut['final_answer'] !== '');
check('the healthy run populated the cache', countJsonFiles($e2eRoot) > 0, 'files=' . countJsonFiles($e2eRoot));

$callsBefore = \App\Connectors\CacheProbeConnector::$calls;
check('the healthy run did call the provider', $callsBefore > 0, 'calls=' . $callsBefore);
$makeEngine()->run('Should I take the offer with the higher equity?');
check('a repeat of the same run adds no provider calls (served from the cache)',
    \App\Connectors\CacheProbeConnector::$calls === $callsBefore,
    'before=' . $callsBefore . ' after=' . \App\Connectors\CacheProbeConnector::$calls);

// =====================================================================
// (b) Key derivation: identity + options + message framing
// =====================================================================
echo "\n== (b) the key separates models, options and message boundaries ==\n";

$k = static fn(string $provider, string $model, array $msgs, array $o = []): string
    => $cache->buildKey($provider, $model, $msgs, $o)['key'];

$msgOne = [['role' => 'user', 'content' => 'Explain the trade-off']];

check('same provider+model+messages -> same key', $k('stub', 'stub/echo', $msgOne) === $k('stub', 'stub/echo', $msgOne));
check('provider case does not split the cache', $k('STUB', 'stub/echo', $msgOne) === $k('stub', 'stub/echo', $msgOne));

// Identifiers must never be punctuation-normalized (pre-1.8.46 they were,
// which merged genuinely different models into one slot).
check('gpt-4.1 and gpt41 are different keys', $k('stub', 'gpt-4.1', $msgOne) !== $k('stub', 'gpt41', $msgOne));
check('claude-3.5-sonnet and claude35sonnet are different keys',
    $k('stub', 'claude-3.5-sonnet', $msgOne) !== $k('stub', 'claude35sonnet', $msgOne));
check('o3-mini and o3mini are different keys', $k('stub', 'o3-mini', $msgOne) !== $k('stub', 'o3mini', $msgOne));
check('namespaced vs bare model are different keys',
    $k('stub', 'anthropic/claude-sonnet-4.6', $msgOne) !== $k('stub', 'claude-sonnet-4.6', $msgOne));
check('provider punctuation is significant',
    $k('open-router', 'stub/echo', $msgOne) !== $k('openrouter', 'stub/echo', $msgOne));

// Every provider parameter must split the cache. The pre-1.8.46 whitelist
// silently ignored anything it did not list.
foreach ([
    'temperature'       => 0.9,
    'max_tokens'        => 4096,
    'top_p'             => 0.8,
    'top_k'             => 40,
    'frequency_penalty' => 0.5,
    'presence_penalty'  => 0.5,
    'seed'              => 12345,
    'stop'              => ['###'],
    'response_format'   => ['type' => 'json_object'],
    'reasoning'         => ['effort' => 'high'],
    'consensus_round'   => 2,
] as $optionKey => $optionValue) {
    check("option '{$optionKey}' changes the cache key",
        $k('stub', 'stub/echo', $msgOne, [$optionKey => $optionValue]) !== $k('stub', 'stub/echo', $msgOne),
        $optionKey);
}

// Transport-only / bookkeeping keys must NOT split the cache.
foreach (['timeout', 'stream', 'max_retries', 'request_id', 'client_run_id', 'session_id', 'job_id'] as $noiseKey) {
    check("transport key '{$noiseKey}' does not split the cache",
        $k('stub', 'stub/echo', $msgOne, [$noiseKey => 1]) === $k('stub', 'stub/echo', $msgOne),
        $noiseKey);
}

// Message framing: the historical bare concatenation collided here.
$ambiguousA = [['role' => 'user', 'content' => 'ab'],    ['role' => 'user', 'content' => 'user']];
$ambiguousB = [['role' => 'user', 'content' => 'abuser'], ['role' => 'user', 'content' => '']];
check('different message splits never collide', $k('stub', 'stub/echo', $ambiguousA) !== $k('stub', 'stub/echo', $ambiguousB));
$roleSwapA = [['role' => 'system', 'content' => 'X'], ['role' => 'user', 'content' => 'Y']];
$roleSwapB = [['role' => 'user', 'content' => 'X'],   ['role' => 'system', 'content' => 'Y']];
check('role boundaries are significant', $k('stub', 'stub/echo', $roleSwapA) !== $k('stub', 'stub/echo', $roleSwapB));
check('message order is significant',
    $k('stub', 'stub/echo', $roleSwapA) !== $k('stub', 'stub/echo', array_reverse($roleSwapA)));

// Documented, tested prose behaviour is unchanged: cosmetic noise still
// maps to one entry (case IS preserved by design, so the variants below
// differ only in whitespace and punctuation).
check('prose whitespace/punctuation noise still shares one key',
    $k('stub', 'stub/echo', [['role' => 'user', 'content' => 'Explain the trade-off']])
    === $k('stub', 'stub/echo', [['role' => 'user', 'content' => "  Explain,   the trade off!!  "]]));
check('normalizeText contract intact', ModelResponseCache::normalizeText('Hello, world?') === 'Helloworld');

// =====================================================================
// (c) Unreachable cache directory degrades gracefully
// =====================================================================
echo "\n== (c) an unreachable cache directory does not break the run ==\n";

// The parent of the target is a FILE, so mkdir() can never succeed.
$blocker = $tmpRoot . '/blocker-file';
file_put_contents($blocker, 'not a directory');
$brokenCache = new ModelResponseCache(db: null, fileRoot: $blocker . '/cache', enabled: true, ttlSeconds: 0);

$threw = false;
try {
    $brokenStore = $brokenCache->store('stub', 'stub/echo', $messages, $opts, $good);
} catch (\Throwable $e) {
    $threw = true;
    $brokenStore = ['stored' => true];
}
check('store() into an unreachable root does not throw', $threw === false);
check('store() into an unreachable root reports stored=false', ($brokenStore['stored'] ?? true) === false);
check('lookup() against an unreachable root returns null',
    $brokenCache->lookup('stub', 'stub/echo', $messages, $opts) === null);
check('summary() survives an unreachable root', is_array($brokenCache->summary()));
check('purge() survives an unreachable root', is_array($brokenCache->purge(0)));
check('backendInfo() stays serialisable', is_array($brokenCache->backendInfo()));

$warnLog = $tmpRoot . '/logs/revoap-error.log';
$warnBody = is_file($warnLog) ? (string)file_get_contents($warnLog) : '';
check('the refused filesystem write is logged', str_contains($warnBody, 'cache.fs_store.mkdir_failed'));

// End to end: a pipeline whose cache is unreachable still finishes.
$degradedEngine = new PipelineEngine(
    connectors:  $stubRegistry,
    sessions:    new SessionRepository(null),
    steps:       new StepRepository(null),
    responses:   new ResponseRepository(null),
    skills:      $skillRepo,
    router:      new SkillRouter($skillRepo),
    autoCreator: new SkillAutoCreator($skillRepo, $stubRegistry),
    normalizer:  new NormalizationService(),
    consensus:   new ConsensusEngine(),
    stats:       new StatsCollector(),
    cache:       $brokenCache,
);
\App\Connectors\CacheProbeConnector::$broken = false;
$degradedOut = $degradedEngine->run('Is the degraded cache fatal?');
check('a run with an unreachable cache still produces a final answer',
    is_string($degradedOut['final_answer'] ?? null) && $degradedOut['final_answer'] !== '');
check('the pre-existing human-readable warning is kept',
    str_contains($warnBody, 'cache.fs_store.mkdir_failed'));

// =====================================================================
// (d) Test isolation — the working cache/ is never touched
// =====================================================================
echo "\n== (d) the cache is isolated in tests ==\n";

$repoRoot     = dirname(__DIR__);
$repoCacheDir = $repoRoot . '/cache';
$repoBefore   = countJsonFiles($repoCacheDir);

$isolated = new ModelResponseCache(db: null, fileRoot: $tmpRoot . '/isolated/cache', enabled: true, ttlSeconds: 0);
$isolated->store('stub', 'stub/echo', $messages, $opts, $good);
$isolated->lookup('stub', 'stub/echo', $messages, $opts);
$isolated->purge(0);
check('a temp-rooted instance wrote only under its own root',
    countJsonFiles($tmpRoot . '/isolated/cache') === 0 && countJsonFiles($repoCacheDir) === $repoBefore,
    'repo before=' . $repoBefore . ' after=' . countJsonFiles($repoCacheDir));

// A populated working cache must not leak into a temp-rooted instance.
$warm = new ModelResponseCache(db: null, fileRoot: $tmpRoot . '/warm/cache', enabled: true, ttlSeconds: 0);
$warm->store('stub', 'stub/echo', $messages, $opts, $good);
$cold = new ModelResponseCache(db: null, fileRoot: $tmpRoot . '/cold/cache', enabled: true, ttlSeconds: 0);
check('a warm sibling cache does not leak into a cold instance',
    $cold->lookup('stub', 'stub/echo', $messages, $opts) === null);

check('fromConfig() roots the cache at <root>/cache', (static function (string $root): bool {
    $c = ModelResponseCache::fromConfig(null, $root);
    return ($c->backendInfo()['file_root'] ?? '') === $root . DIRECTORY_SEPARATOR . 'cache';
})($tmpRoot . '/fromconfig') === true);
check('fromConfig() never creates the directory as a side effect',
    !is_dir($tmpRoot . '/fromconfig/cache'));
check('the working cache/ tree is unchanged by this whole test file',
    countJsonFiles($repoCacheDir) === $repoBefore,
    'before=' . $repoBefore . ' after=' . countJsonFiles($repoCacheDir));

// =====================================================================
// (e) TTL expiry invalidates, and purge() gives an escape hatch
// =====================================================================
echo "\n== (e) TTL expiry invalidates instead of lingering ==\n";

$ttlRoot = $tmpRoot . '/ttl/cache';
$ttlCache = new ModelResponseCache(db: null, fileRoot: $ttlRoot, enabled: true, ttlSeconds: 60);
$ttlCache->store('stub', 'stub/echo', $messages, $opts, $good);
check('a fresh entry hits before the TTL elapses',
    $ttlCache->lookup('stub', 'stub/echo', $messages, $opts) !== null);

// Age the entry past the TTL by rewriting its created_at marker.
$hitPath = (static function () use ($ttlRoot, $ttlCache, $messages, $opts): string {
    $info = $ttlCache->lookup('stub', 'stub/echo', $messages, $opts);
    return (string)($info['path'] ?? '');
})();
if ($hitPath !== '' && is_file($hitPath)) {
    $envelope = json_decode((string)file_get_contents($hitPath), true);
    $envelope['created_at'] = date('c', time() - 3600);
    file_put_contents($hitPath, json_encode($envelope));
}
check('an expired entry is a miss', $ttlCache->lookup('stub', 'stub/echo', $messages, $opts) === null);
check('the expired entry is removed, not just ignored',
    $hitPath === '' || !is_file($hitPath), $hitPath);

$purgeRoot = $tmpRoot . '/purge/cache';
$purgeCache = new ModelResponseCache(db: null, fileRoot: $purgeRoot, enabled: true, ttlSeconds: 0);
$purgeCache->store('stub', 'stub/echo', $messages, $opts, $good);
$purgeCache->store('stub', 'stub/echo', [['role' => 'user', 'content' => 'second question']], $opts, $good);
check('two entries are on disk before the purge', countJsonFiles($purgeRoot) === 2,
    'files=' . countJsonFiles($purgeRoot));
$purged = $purgeCache->purge(0);
check('purge() reports what it removed', ($purged['filesystem'] ?? 0) === 2, json_encode($purged));
check('purge() empties the tree', countJsonFiles($purgeRoot) === 0);
check('dropKey() on an unknown key is a harmless no-op',
    $purgeCache->dropKey(str_repeat('0', 64), 'stub', 'stub/echo') === false);
check('backendInfo() documents the key contract', (static function () use ($purgeCache): bool {
    $info = $purgeCache->backendInfo();
    return ($info['cache_version'] ?? 0) === ModelResponseCache::VERSION
        && ($info['error_responses'] ?? '') !== ''
        && ($info['key_options'] ?? '') !== '';
})());

// =====================================================================
// (f) purge() reaches the legacy flat tree as well (v1.8.47)
// =====================================================================
// The legacy tree is only addressable through fromConfig(), which is the
// one thing that populates legacyFlatRoot — a directly constructed instance
// has it null and must keep behaving exactly as before. So this block builds
// a real <root>/var/cache/model-responses tree and drives it through
// fromConfig(), then proves the null-root case is still inert.
echo "\n== (f) purge() also clears the legacy flat layout ==\n";

$legacyTop = $tmpRoot . '/legacy';
$legacyRoot = $legacyTop . '/var/cache/model-responses';
$hierRoot = $legacyTop . '/cache';
@mkdir($hierRoot . '/stub/echo', 0o775, true);
@mkdir($legacyRoot, 0o775, true);

$now = time();
$write = static function (string $path, string $bytes, int $mtime): string {
    file_put_contents($path, $bytes);
    @touch($path, $mtime);
    return $path;
};
$hierStale = $write($hierRoot . '/stub/echo/' . str_repeat('1', 64) . '.json', str_repeat('h', 60), $now - 30 * 86400);
$hierFresh = $write($hierRoot . '/stub/echo/' . str_repeat('2', 64) . '.json', str_repeat('h', 70), $now - 3600);
// A ".tmp" staging file, as fileStore() writes before renaming: never purged.
$hierTmp   = $write($hierRoot . '/stub/echo/' . str_repeat('3', 64) . '.json.tmp', str_repeat('h', 80), $now - 30 * 86400);

$legacyStale = $write($legacyRoot . '/' . str_repeat('4', 64) . '.json', str_repeat('l', 33), $now - 30 * 86400);
$legacyFresh = $write($legacyRoot . '/' . str_repeat('5', 64) . '.json', str_repeat('l', 44), $now - 3600);
$legacyTmp   = $write($legacyRoot . '/' . str_repeat('6', 64) . '.json.tmp', str_repeat('l', 55), $now - 30 * 86400);
// Modelled with a future mtime: it exercises the same branch as a file whose
// mtime is exactly the cutoff, which purge() must KEEP (`mtime >= cutoff`).
$legacyAtCutoff = $write($legacyRoot . '/' . str_repeat('7', 64) . '.json', str_repeat('l', 12), $now + 3600);
// A nested legacy directory, to prove the walk recurses rather than assuming
// the v1.8.20 layout was strictly flat.
@mkdir($legacyRoot . '/nested', 0o775, true);
$legacyNested = $write($legacyRoot . '/nested/' . str_repeat('8', 64) . '.json', str_repeat('l', 21), $now - 30 * 86400);

$legacyCache = ModelResponseCache::fromConfig(null, $legacyTop);
check('fromConfig() points the legacy root at <root>/var/cache/model-responses',
    str_replace('\\', '/', (string)($legacyCache->backendInfo()['legacy_flat_root'] ?? 'null'))
        === str_replace('\\', '/', $legacyRoot),
    (string)($legacyCache->backendInfo()['legacy_flat_root'] ?? 'null'));

// Reading the cache must not remove anything (report/statistics are read-only).
$legacyCache->backendInfo();
$legacyCache->summary();
check('inspecting the cache deletes no legacy entry',
    is_file($legacyStale) && is_file($legacyFresh) && is_file($legacyNested));

$purgedLegacy = $legacyCache->purge(7 * 86400);
check('purge(days) clears the stale hierarchical entry', ($purgedLegacy['filesystem'] ?? null) === 1, json_encode($purgedLegacy));
check('purge(days) clears the stale legacy entries', ($purgedLegacy['filesystem_legacy'] ?? null) === 2, json_encode($purgedLegacy));
check('the legacy counter is separate from the hierarchical one',
    array_key_exists('filesystem_legacy', $purgedLegacy) && array_key_exists('bytes_legacy', $purgedLegacy));
check('legacy bytes are reported but kept out of bytes', ($purgedLegacy['bytes'] ?? null) === 60
    && ($purgedLegacy['bytes_legacy'] ?? null) === 33 + 21, json_encode($purgedLegacy));
check('purge(days) respects the window for the legacy tree',
    !is_file($legacyStale) && !is_file($legacyNested), 'stale legacy entries should be gone');
check('a legacy entry at or after the cutoff survives', is_file($legacyAtCutoff));
check('a fresh legacy entry survives', is_file($legacyFresh));
check('a legacy .tmp staging file is never purged', is_file($legacyTmp));
check('a hierarchical .tmp staging file is never purged', is_file($hierTmp));
check('a fresh hierarchical entry survives', is_file($hierFresh));
check('the emptied legacy subdirectory is removed', !is_dir($legacyRoot . '/nested'));

$purgedAll = $legacyCache->purge(0);
check('purge(0) clears the rest of both trees',
    ($purgedAll['filesystem'] ?? null) === 1 && ($purgedAll['filesystem_legacy'] ?? null) === 2,
    json_encode($purgedAll));
check('purge(0) leaves the .tmp files alone in both trees', is_file($hierTmp) && is_file($legacyTmp));
check('purge(0) empties the legacy root of json entries', countJsonFiles($legacyRoot) === 0,
    'json files left: ' . countJsonFiles($legacyRoot));

// The null-root case: an instance built without fromConfig() has no legacy
// root at all (the property stays null), so it must report zero for the
// legacy counters and must not touch a legacy tree that happens to exist.
$legacyUntouched = $write($legacyRoot . '/' . str_repeat('9', 64) . '.json', str_repeat('l', 66), $now - 30 * 86400);
$direct = new ModelResponseCache(db: null, fileRoot: $hierRoot, enabled: true, ttlSeconds: 0);
// backendInfo() always publishes the key; without fromConfig() its value is
// null, which is what makes purge() inert for the legacy tree.
check('an instance built without fromConfig() has no legacy root',
    array_key_exists('legacy_flat_root', $direct->backendInfo())
    && $direct->backendInfo()['legacy_flat_root'] === null);
$directPurge = $direct->purge(0);
check('that instance reports a legacy count of 0',
    ($directPurge['filesystem_legacy'] ?? -1) === 0 && ($directPurge['bytes_legacy'] ?? -1) === 0,
    json_encode($directPurge));
check('that instance leaves an existing legacy tree untouched', is_file($legacyUntouched));

check('purge() always returns every counter as an int', (static function () use ($purgedAll): bool {
    foreach (['database', 'filesystem', 'filesystem_legacy', 'bytes', 'bytes_legacy'] as $k) {
        if (!array_key_exists($k, $purgedAll) || !is_int($purgedAll[$k])) {
            return false;
        }
    }
    return true;
})());
check('purge() on a nonexistent legacy root is a no-op, not an error', (static function () use ($tmpRoot): bool {
    $c = ModelResponseCache::fromConfig(null, $tmpRoot . '/never-created');
    $r = $c->purge(0);
    return ($r['filesystem_legacy'] ?? -1) === 0;
})());

// =====================================================================
// Cleanup
// =====================================================================
Logger::reset();
rmTree($tmpRoot);

echo "----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
