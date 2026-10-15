<?php
/**
 * MemoryPipelineGateTest: offline checks for the Task-5 pipeline memory
 * integration (recall/store gates and degradation).
 *
 * Runs without PHPUnit, a database or network access, so it is
 * executable as `php tests/MemoryPipelineGateTest.php` on any host with
 * PHP 8.4+.
 *
 * Verified:
 *   - pipeline.memory_top_k <= 0  -> EmbeddingMemory is wired but the
 *     pipeline performs NO recall and NO store calls (legacy behaviour);
 *   - pipeline.memory_top_k  > 0  -> after a successful run the spy
 *     vector store records recall(search) and store(upsert) calls, the
 *     degraded in-process repository holds the query + final answer,
 *     and the requested top_k is passed through verbatim;
 *   - a failing vector store (search/upsert throws) degrades to
 *     "no memory": the pipeline run still completes with a final
 *     answer and never crashes.
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
use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Connectors\EmbedResponse;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Consensus\ConsensusEngine;
use App\Core\Config;
use App\Helpers\TokenHelper;
use App\Memory\EmbeddingMemory;
use App\Memory\MemoryEmbedderInterface;
use App\Memory\PdoMemoryRepository;
use App\Pipeline\PipelineEngine;
use App\Skills\Skill;
use App\Skills\SkillAutoCreator;
use App\Skills\SkillRepository;
use App\Skills\SkillRouter;
use App\Stats\StatsCollector;
use App\Storage\ResponseRepository;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;
use App\Storage\VectorStore;
use App\Storage\VectorStoreInterface;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failures++;
    }
}

/** Deterministic 16-dim token-hash embedder (same shape as VectorMemoryTest). */
final class GateEmbedder implements MemoryEmbedderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function embed(string $text): array
    {
        $v = array_fill(0, 16, 0.0);
        $tokens = preg_split('/\s+/', mb_strtolower(trim($text))) ?: [];
        foreach ($tokens as $word) {
            if ($word === '') { continue; }
            $i = abs((int)crc32($word)) % 16;
            $v[$i] += 1.0;
        }
        return VectorStore::normalizeVector($v);
    }
}

/**
 * Recording vector store: captures every search/upsert call with its
 * arguments and returns scripted hits. Used to ASSERT interactions.
 */
final class SpyVectorStore implements VectorStoreInterface
{
    /** @var list<array{topK:int,query:list<float>}> */
    public array $searches = [];
    /** @var list<array{collection:string,id:int}> */
    public array $upserts = [];
    /** @var array{id:int,similarity:float,metadata:array<string,mixed>} */
    public array $hits = [];
    public bool $failOnSearch = false;
    public bool $failOnUpsert = false;
    public bool $available = true;

    public function upsert(string $collection, int $id, array $vector, array $metadata = []): bool
    {
        $this->upserts[] = ['collection' => $collection, 'id' => $id];
        if ($this->failOnUpsert) {
            throw new RuntimeException('spy upsert failure');
        }
        return true;
    }

    public function search(string $collection, array $queryVector, int $topK = 10): array
    {
        $this->searches[] = ['topK' => $topK, 'query' => $queryVector];
        if ($this->failOnSearch) {
            throw new RuntimeException('spy search failure');
        }
        return $this->hits;
    }

    public function delete(string $collection, int $id): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}

function buildEngine(ConnectorRegistry $registry, int $memoryTopK): array
{
    Config::set([
        'app'        => ['debug' => false, 'version' => '1.0.0', 'name' => 'RevoAp'],
        'connectors' => ['stub' => ['active' => true, 'api_key' => 'x']],
        'models'     => [['provider' => 'stub', 'model' => 'stub/echo', 'active' => true]],
        'pipeline'   => [
            'synthesis_provider' => 'stub',
            'synthesis_model'    => 'stub/echo',
            'max_roles_per_skill' => 5,
            'memory_top_k'       => $memoryTopK,
        ],
    ], __DIR__);

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

    $engine = new PipelineEngine(
        connectors:  $registry,
        sessions:    new SessionRepository(null),
        steps:       new StepRepository(null),
        responses:   new ResponseRepository(null),
        skills:      $skillRepo,
        router:      new SkillRouter($skillRepo),
        autoCreator: new SkillAutoCreator($skillRepo, $registry),
        normalizer:  new App\Consensus\NormalizationService(),
        consensus:   new ConsensusEngine(),
        stats:       new StatsCollector(),
    );
    return [$engine, $skillRepo];
}

// One throwaway stub connector on disk per process, mirroring SmokeTest.
$tmpDir = sys_get_temp_dir() . '/oc-gate-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0o700, true);
file_put_contents($tmpDir . '/GateStubConnector.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Connectors;
use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;
final class GateStubConnector implements ConnectorInterface {
    public function chat(string $model, array $messages, array $options = []): ChatResponse {
        $base = '[stub] ' . ($messages[1]['content'] ?? '');
        $content = $base . str_repeat(' Short.', 8);
        $pt = TokenHelper::estimateMessageTokens($messages);
        $ct = TokenHelper::estimateTokens($content);
        return new ChatResponse($content, $model, 'stub', $pt, $ct, $pt + $ct, 1.5, ['stub' => true], true);
    }
    public function embed(string $model, string $text): EmbedResponse {
        throw new UnsupportedOperationException('stub: no embeddings');
    }
    public function listModels(): array { return ['stub/echo']; }
    public function getProviderName(): string { return 'stub'; }
    public function isAvailable(): bool { return true; }
}
PHP);
$registry = new ConnectorRegistry($tmpDir);

// ===== Case 1: memory_top_k = 0 -> gates fully disengaged. =====
echo "== Case 1: memory_top_k=0 keeps legacy behaviour ==\n";
[$engine1, $repo1] = buildEngine($registry, 0);
$spy1    = new SpyVectorStore();
$memRepo = new PdoMemoryRepository(null);
$engine1->setMemory(new EmbeddingMemory($spy1, new GateEmbedder(), $memRepo));
$out1 = $engine1->run('Evaluate job offers');
check('run completes with final answer', is_string($out1['final_answer'] ?? null) && $out1['final_answer'] !== '');
check('gate-off: no recall (search) calls', $spy1->searches === []);
check('gate-off: no store (upsert) calls', $spy1->upserts === []);
check('gate-off: degraded repository stays empty', $memRepo->count() === 0);

// ===== Case 2: memory_top_k = 5 -> recall + store engaged. =====
echo "== Case 2: memory_top_k=5 engages store/recall ==\n";
[$engine2, $repo2] = buildEngine($registry, 5);
$spy2    = new SpyVectorStore();
$spy2->hits = [[
    'id'         => 1,
    'similarity' => 0.94,
    'metadata'   => [
        'content'    => 'remembered fact about taxes',
        'session_id' => 0,
        'type'       => 'text',
    ],
]];
$memRepo2 = new PdoMemoryRepository(null);
$engine2->setMemory(new EmbeddingMemory($spy2, new GateEmbedder(), $memRepo2));
$out2 = $engine2->run('Evaluate job offers');
check('run completes with final answer', is_string($out2['final_answer'] ?? null) && $out2['final_answer'] !== '');
check('gate-on: recall (search) was called', count($spy2->searches) >= 1);
check('gate-on: requested top_k passed verbatim',
    count($spy2->searches) >= 1 && $spy2->searches[0]['topK'] === 5);
check('gate-on: store (upsert) recorded', count($spy2->upserts) >= 2);
check('gate-on: query + final answer persisted', $memRepo2->count() >= 2);
check('gate-on: upserts target the memory collection',
    count($spy2->upserts) >= 2 && $spy2->upserts[0]['collection'] === EmbeddingMemory::COLLECTION);

// ===== Case 3: failing vector store degrades to "no memory". =====
echo "== Case 3: failing vector store degrades safely ==\n";
[$engine3, $repo3] = buildEngine($registry, 5);
$spy3 = new SpyVectorStore();
$spy3->failOnSearch = true;
$spy3->failOnUpsert = true;
$memRepo3 = new PdoMemoryRepository(null);
$engine3->setMemory(new EmbeddingMemory($spy3, new GateEmbedder(), $memRepo3));
$out3 = $engine3->run('Evaluate job offers');
check('failing store: run still completes', is_string($out3['final_answer'] ?? null) && $out3['final_answer'] !== '');
check('failing store: recall attempted (search called)', count($spy3->searches) >= 1);
check('failing store: store attempted (upsert called)', count($spy3->upserts) >= 2);
check('failing store: no memory row was lost to the vector layer',
    $memRepo3->count() >= 2); // raw rows persisted in the degraded repo

@unlink($tmpDir . '/GateStubConnector.php');
@rmdir($tmpDir);

echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
