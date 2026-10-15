<?php
/**
 * VectorStore: Persistence and similarity search for embedding vectors.
 *
 * Implements VectorStoreInterface and acts as the default ('mysql')
 * driver of the Task-5 vector memory work. Vectors are stored in the
 * existing `vector_embeddings` table (collection, source_id, vector
 * JSON, metadata JSON); cosine similarity is computed in PHP. The
 * contract-level identifier `id` maps to the `source_id` column so the
 * existing schema stays valid without a migration.
 *
 * Vectors are normalized to unit length on write, so search() computes
 * pure dot products of normalized vectors (identical value to the
 * cosine, cheaper per comparison). Public static cosine() is kept for
 * backward compatibility and reused by other utilities.
 *
 * For very large collections, switch the `vector.driver` config key to
 * `qdrant`; this implementation favours portability over raw
 * throughput. Measured thresholds are documented in
 * docs/VectorMemory.md.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  2.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Storage;

use App\Core\Logger;
use App\Helpers\JsonHelper;
use PDO;
use Throwable;

final class VectorStore implements VectorStoreInterface
{
    /** @var array<string,array<int,array{vector:array<int,float>,metadata:array<string,mixed>}>> */
    private array $memory = [];

    public function __construct(private readonly ?PDO $pdo = null) {}

    /**
     * Insert or replace a normalized vector for the given
     * collection/id pair. Degrades to false on any failure.
     *
     * @param list<float|int>     $vector
     * @param array<string,mixed> $metadata
     */
    public function upsert(string $collection, int $id, array $vector, array $metadata = []): bool
    {
        $normalized = self::normalizeVector($vector);
        if ($normalized === []) {
            Logger::warn('vector.upsert.skip_zero_norm', ['collection' => $collection, 'id' => $id]);
            return false;
        }
        if ($this->pdo === null) {
            $this->memory[$collection][$id] = ['vector' => $normalized, 'metadata' => $metadata];
            return true;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO vector_embeddings (collection, source_id, vector, metadata)
                 VALUES (:c, :s, :v, :m)
                 ON DUPLICATE KEY UPDATE vector = VALUES(vector), metadata = VALUES(metadata)'
            );
            $stmt->execute([
                'c' => $collection,
                's' => $id,
                'v' => JsonHelper::encode($normalized),
                'm' => JsonHelper::encode($metadata),
            ]);
            return true;
        } catch (Throwable $e) {
            Logger::warn('vector.upsert.failed', [
                'collection' => $collection,
                'id'         => $id,
                'error'      => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return false;
        }
    }

    /**
     * Search the collection by cosine similarity. Returns [] for empty
     * or zero-norm queries, empty collections and storage failures.
     *
     * @param list<float|int> $query
     * @return list<array{id:int,similarity:float,metadata:array<string,mixed>}>
     */
    public function search(string $collection, array $query, int $topK = 10): array
    {
        $normalized = self::normalizeVector($query);
        if ($normalized === []) {
            return [];
        }
        $rows = $this->loadCollection($collection);
        $out  = [];
        foreach ($rows as $id => $row) {
            $score = self::cosine($normalized, $row['vector']);
            $out[] = ['id' => $id, 'similarity' => $score, 'metadata' => $row['metadata']];
        }
        usort($out, static fn(array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
        return array_slice($out, 0, max(1, $topK));
    }

    /**
     * Delete a vector by id. In-memory mode unsets the slot; database
     * mode issues a DELETE that is a no-op when the row is absent.
     */
    public function delete(string $collection, int $id): bool
    {
        if (isset($this->memory[$collection][$id])) {
            unset($this->memory[$collection][$id]);
        }
        if ($this->pdo === null) {
            return true;
        }
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM vector_embeddings WHERE collection = :c AND source_id = :s'
            );
            $stmt->execute(['c' => $collection, 's' => $id]);
            return true;
        } catch (Throwable $e) {
            Logger::warn('vector.delete.failed', [
                'collection' => $collection,
                'id'         => $id,
                'error'      => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return false;
        }
    }

    /** The MySQL driver is always usable: in-memory mode is a valid backing store. */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return array<int,array{vector:array<int,float>,metadata:array<string,mixed>}>
     */
    private function loadCollection(string $collection): array
    {
        if ($this->pdo === null) {
            return $this->memory[$collection] ?? [];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT source_id, vector, metadata FROM vector_embeddings WHERE collection = :c');
            $stmt->execute(['c' => $collection]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            Logger::warn('vector.search.load_failed', [
                'collection' => $collection,
                'error'      => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
        $out = [];
        foreach ($rows ?: [] as $r) {
            if (!is_array($r)) { continue; }
            $vec  = JsonHelper::tryDecode((string)($r['vector'] ?? ''), []);
            $meta = JsonHelper::tryDecode((string)($r['metadata'] ?? ''), []);
            if (!is_array($vec)) { continue; }
            $out[(int)$r['source_id']] = [
                'vector'   => array_map('floatval', $vec),
                'metadata' => is_array($meta) ? $meta : [],
            ];
        }
        return $out;
    }

    /**
     * Normalize a vector to unit length. Returns [] for empty vectors
     * and zero-norm vectors (they have no direction).
     *
     * @param list<float|int> $vector
     * @return array<int,float>
     */
    public static function normalizeVector(array $vector): array
    {
        $values = array_map('floatval', array_values($vector));
        $norm   = 0.0;
        foreach ($values as $f) {
            $norm += $f * $f;
        }
        if ($norm <= 0.0) {
            return [];
        }
        $scale = 1.0 / sqrt($norm);
        return array_map(static fn(float $f): float => $f * $scale, $values);
    }

    /**
     * Cosine similarity between two vectors. Handles zero vectors
     * (returns 0.0) and differing lengths (compares the shared
     * prefix only).
     *
     * Public API is preserved: it doubles as a standalone utility.
     *
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) { return 0.0; }
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += (float)$a[$i] * (float)$b[$i];
            $na  += (float)$a[$i] * (float)$a[$i];
            $nb  += (float)$b[$i] * (float)$b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) { return 0.0; }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
