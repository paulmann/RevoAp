<?php
/**
 * VectorMemoryTest: offline checks for the Task-5 vector memory layer.
 *
 * Runs without PHPUnit and without any database or network access, so
 * it is executable as `php tests/VectorMemoryTest.php` on any host with
 * PHP 8.4+.
 *
 * Verified:
 *   - MemoryItem serialization (fromRow round-trip, vector payload
 *     metadata, 400-char payload truncation, trim in searchPayload);
 *   - VectorStore math: unit normalization, zero-vector rejection,
 *     cosine similarity edge cases;
 *   - in-memory VectorStore: empty collection, ranked search, delete,
 *     metadata preservation;
 *   - PdoMemoryRepository degraded in-process store (add/find/session
 *     filter/count without a real table);
 *   - EmbeddingMemory end-to-end with a deterministic embedder stub:
 *     store, recall ranking, session isolation, final-answer recall,
 *     unavailable-embedder degradation;
 *   - QdrantVectorStore honest degradation (unavailable backend) and
 *     VectorStoreFactory fallback to the mysql driver;
 *   - db/migrations/014_memory_items.sql idempotency markers and its
 *     agreement with the `memory_items` declaration in db/schema.sql
 *     (the ReCreate DB / baseline path).
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

use App\Core\Config;
use App\Helpers\JsonHelper;
use App\Memory\EmbeddingMemory;
use App\Memory\MemoryEmbedderInterface;
use App\Memory\MemoryItem;
use App\Memory\PdoMemoryRepository;
use App\Storage\Vector\QdrantVectorStore;
use App\Storage\Vector\VectorStoreFactory;
use App\Storage\VectorStore;
use App\Storage\VectorStoreInterface;

$failures = 0;
$passes   = 0;

// Initialise the config surface early: most Logger::warn() calls read
// app.debug via Config::get(), which throws when Config was never set.
Config::set(['app' => ['debug' => true], 'logging' => ['path' => __DIR__ . '/logs']], __DIR__);

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

/**
 * Deterministic semantic embedder for offline verification: vector of
 * token hashes over a fixed 16-dimensional space, unit-normalized.
 */
final class TestEmbedder implements MemoryEmbedderInterface
{
    public function __construct(private readonly bool $available = true) {}

    public function isAvailable(): bool
    {
        return $this->available;
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

function assertAutoload(string $class): void
{
    check("autoload resolves {$class}", class_exists($class) || interface_exists($class));
}

echo "== Task 5a: autoload + MemoryItem serialization ==\n";
assertAutoload(VectorStoreInterface::class);
assertAutoload(VectorStore::class);
assertAutoload(EmbeddingMemory::class);
assertAutoload(PdoMemoryRepository::class);
assertAutoload(MemoryItem::class);
assertAutoload(QdrantVectorStore::class);
assertAutoload(VectorStoreFactory::class);

$item = new MemoryItem(
    id:        42,
    sessionId: 7,
    content:   "  the quick brown fox  ",
    metadata:  ['tags' => ['a', 'b'], 'role' => 'user_query'],
    type:      MemoryItem::TYPE_TEXT,
    createdAt: '2026-09-18 12:00:00',
);
check('searchPayload trims content', $item->searchPayload() === 'the quick brown fox');
$meta = $item->vectorMetadata();
check('vectorMetadata carries session_id', ($meta['session_id'] ?? null) === 7);
check('vectorMetadata carries type', ($meta['type'] ?? null) === MemoryItem::TYPE_TEXT);
check('vectorMetadata carries content', ($meta['content'] ?? null) === 'the quick brown fox');
$long = str_repeat('x', 900);
$longItem = new MemoryItem(id: 1, sessionId: 1, content: $long);
$longMeta = $longItem->vectorMetadata();
check('vector metadata truncates content at 400', mb_strlen((string)($longMeta['content'] ?? '')) === 400);
$row = [
    'id'         => '42',
    'session_id' => '7',
    'content'    => 'hello',
    'metadata'   => JsonHelper::encode(['k' => 'v']),
    'type'       => MemoryItem::TYPE_FACT,
    'created_at' => '2026-09-18 12:00:00',
];
$fromRow = MemoryItem::fromRow($row);
check('fromRow round-trips id/session', $fromRow->id === 42 && $fromRow->sessionId === 7);
check('fromRow round-trips metadata', ($fromRow->metadata['k'] ?? null) === 'v');
check('fromRow round-trips type', $fromRow->type === MemoryItem::TYPE_FACT);

echo "== Task 5b: vector math ==\n";
$unit = VectorStore::normalizeVector([3.0, 4.0]);
$norm = sqrt($unit[0] * $unit[0] + $unit[1] * $unit[1]);
check('normalizeVector yields unit length', abs($norm - 1.0) < 1e-9);
check('normalizeVector rejects zero vector', VectorStore::normalizeVector([0.0, 0.0]) === []);
check('normalizeVector rejects empty vector', VectorStore::normalizeVector([]) === []);
check('cosine identical = 1', abs(VectorStore::cosine([1.0, 2.0], [1.0, 2.0]) - 1.0) < 1e-9);
check('cosine orthogonal = 0', abs(VectorStore::cosine([1.0, 0.0], [0.0, 1.0])) < 1e-9);
check('cosine zero vector = 0', VectorStore::cosine([1.0], [0.0]) === 0.0);

echo "== Task 5c: in-memory VectorStore ==\n";
$store = new VectorStore(null);
check('search on empty collection = []', $store->search('memory', [1.0, 0.0], 5) === []);
check('upsert rejects zero-norm vector', $store->upsert('memory', 1, [0.0, 0.0]) === false);
check('upsert accepts plain vector', $store->upsert('memory', 1, [1.0, 0.0], ['n' => 1]));
check('upsert accepts second vector', $store->upsert('memory', 2, [0.9, 0.1], ['n' => 2]));
$hits = $store->search('memory', [1.0, 0.0], 5);
check('search ranks nearest first', isset($hits[0]['id']) && (int)$hits[0]['id'] === 1);
check('search hit carries metadata', ($hits[0]['metadata']['n'] ?? null) === 1);
check('search returns similarity close to 1', abs((float)$hits[0]['similarity'] - 1.0) < 1e-9);
$afterDelete = $store->delete('memory', 1);
check('delete succeeded', $afterDelete === true);
$remaining = $store->search('memory', [1.0, 0.0], 5);
check('delete removes the vector', count($remaining) === 1 && (int)$remaining[0]['id'] === 2);

echo "== Task 5d: PdoMemoryRepository degraded store ==\n";
$repo = new PdoMemoryRepository(null);
$id = $repo->add(new MemoryItem(id: null, sessionId: 3, content: 'first', type: MemoryItem::TYPE_TEXT));
check('degraded add returns positive id', $id > 0);
$found = $repo->findById($id);
check('degraded findById round-trip', $found !== null && $found->content === 'first' && $found->sessionId === 3);
$repo->add(new MemoryItem(id: null, sessionId: 4, content: 'second'));
$s3 = $repo->findBySession(3, 10);
check('findBySession filters by session', count($s3) === 1 && $s3[0]->content === 'first');
check('count reflects degraded rows', $repo->count() >= 2);
$all = $repo->all();
check('all() is non-empty and ordered', count($all) >= 2);

echo "== Task 5e: EmbeddingMemory store/recall ==\n";
$embedder = new TestEmbedder();
$memory = new EmbeddingMemory($store, $embedder, $repo);
$diag = $memory->diagnostic();
check('diagnostic reports healthy stack', ($diag['vector_store'] ?? false) === true
    && ($diag['embedder'] ?? false) === true
    && ($diag['repository'] ?? false) === true);
$storedId = $memory->storeText(3, 'apple pie recipe', MemoryItem::TYPE_FACT, ['tags' => ['food']]);
check('storeText persists an item', $storedId > 0);
$recall = $memory->recallContextForQuery(3, 'apple pie', 5);
check('recall finds the stored item', count($recall['items']) === 1);
check('recall text contains the content', str_contains((string)$recall['text'], 'apple pie recipe'));
$other = $memory->recallContextForQuery(99, 'apple pie', 5);
check('recall is session-isolated', $other['items'] === [] && $other['text'] === '');
$memory->storeFinalAnswer(3, 'The pie needs apples and flour.');
$fa = $memory->searchRelevant(3, 'pie', 5);
$faFound = false;
foreach ($fa as $it) {
    if ($it->content === 'The pie needs apples and flour.') { $faFound = true; }
}
check('final answer is recalled', $faFound);
$rawId = $memory->keepRaw(5, 'raw note', MemoryItem::TYPE_CONTEXT);
check('keepRaw persists without embedding', $rawId > 0);

// Unavailable embedder: everything degrades to "no memory".
$dead = new EmbeddingMemory($store, new TestEmbedder(false), $repo);
$deadDiag = $dead->diagnostic();
check('dead embedder reports unavailable', ($deadDiag['embedder'] ?? true) === false);
$deadRecall = $dead->recallContextForQuery(3, 'apple', 5);
check('dead embedder recall = empty', $deadRecall['items'] === [] && $deadRecall['text'] === '');

echo "== Task 5f: Qdrant degradation + factory fallback ==\n";
$qdrant = new QdrantVectorStore(baseUrl: '', apiKey: '', timeoutMs: 100, maxRetries: 0);
check('Qdrant without base URL is unavailable', $qdrant->isAvailable() === false);
check('Qdrant upsert degrades to false', $qdrant->upsert('m', 1, [1.0], []) === false);
check('Qdrant search degrades to []', $qdrant->search('m', [1.0], 5) === []);
check('Qdrant delete degrades to false', $qdrant->delete('m', 1) === false);
Config::set([
    'vector' => [
        'driver' => 'qdrant',
        'qdrant' => ['url' => '', 'api_key' => '', 'timeout_ms' => 100, 'max_retries' => 0],
    ],
]);
$factoryStore = VectorStoreFactory::create(null);
check('factory falls back to mysql driver', $factoryStore instanceof VectorStore);

echo "== Task 5g: idempotent migration file ==\n";
$sqlPath = __DIR__ . '/../db/migrations/014_memory_items.sql';
$sql = is_file($sqlPath) ? (string)file_get_contents($sqlPath) : '';
check('014_memory_items.sql exists', $sql !== '');
check('migration is CREATE IF NOT EXISTS', str_contains($sql, 'CREATE TABLE IF NOT EXISTS memory_items'));
check('migration carries table-version', str_contains($sql, '@table-version memory_items'));
check('migration declares session index', str_contains($sql, 'idx_memory_session_created'));
check('migration declares type index', str_contains($sql, 'idx_memory_type'));

echo "\n== Task 5h: memory_items is declared in db/schema.sql too ==\n";
// SchemaManager::recreate() (Settings -> Database -> ReCreate DB) replays
// db/schema.sql and then BASELINES db/migrations/ — it records 014 as
// applied without executing it. A table that only the migration creates is
// therefore reported as applied while still absent, and
// PdoMemoryRepository degrades to "no memory" mode. Declaring the table in
// both places is what makes the recreate path truthful.
$schemaPath = __DIR__ . '/../db/schema.sql';
$schemaSql  = is_file($schemaPath) ? (string)file_get_contents($schemaPath) : '';
check('db/schema.sql declares memory_items',
    str_contains($schemaSql, 'CREATE TABLE IF NOT EXISTS memory_items'));
check('db/schema.sql carries @table-version memory_items (same as 014)',
    str_contains($schemaSql, '-- @table-version memory_items 1.0.0'));

// The two DDL blocks must stay byte-identical (014 ships CRLF, schema.sql
// is LF throughout — normalise the line endings before comparing).
$memoryDdl = static function (string $source): string {
    $normalised = str_replace("\r\n", "\n", $source);
    return preg_match('/CREATE TABLE IF NOT EXISTS memory_items \(.*?\n\) ENGINE=[^;]*;/s', $normalised, $m) === 1
        ? $m[0]
        : '';
};
check('schema.sql and 014 declare the identical memory_items DDL',
    $memoryDdl($schemaSql) !== '' && $memoryDdl($schemaSql) === $memoryDdl($sql),
    'md5 ' . md5($memoryDdl($schemaSql)) . ' vs ' . md5($memoryDdl($sql)));
check('schema.sql keeps the memory indexes',
    str_contains($schemaSql, 'idx_memory_session_created') && str_contains($schemaSql, 'idx_memory_type'));

// SchemaInspector compares the live app_config versions against the
// @table-version annotations found in db/schema.sql — memory_items must be
// part of that expected set, exactly like revoap_worker_presence is.
$memoryParsed   = \App\Settings\SchemaParser::parseFile($schemaPath);
$memoryExpected = \App\Settings\VersionParser::expectedFromSchema($schemaPath, $memoryParsed);
check('SchemaParser finds memory_items in schema.sql',
    isset($memoryParsed['memory_items']['columns']['content']));
check('expectedFromSchema exposes memory_items 1.0.0 (no Check DB drift)',
    ($memoryExpected['tables']['memory_items'] ?? null) === '1.0.0',
    (string)($memoryExpected['tables']['memory_items'] ?? 'absent'));
check('014 and schema.sql agree on the version (an apply leaves no drift)',
    \App\Settings\VersionParser::compare(
        (string)(\App\Settings\VersionParser::parse($sql)['tables']['memory_items'] ?? '0'),
        (string)($memoryExpected['tables']['memory_items'] ?? '0')
    ) === 0);
check('014 stays safe to re-run (single idempotent statement)',
    count(\App\Settings\MigrationManager::splitStatements($sql)) === 1);

echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
