<?php
/**
 * VectorStoreInterface: Abstraction for vector storage engines.
 *
 * Single contract for both drivers of the Task-5 vector memory work:
 *   - App\Storage\VectorStore      (MySQL JSON storage, default driver)
 *   - App\Storage\Vector\QdrantVectorStore (Qdrant over ext-curl)
 *
 * Implementations must use cosine similarity as the distance metric and
 * return results sorted by similarity in descending order. All methods
 * must degrade to a failure return value (false / empty list / false)
 * instead of throwing, so the pipeline can always fall back to
 * "no memory" behaviour.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

namespace App\Storage;

interface VectorStoreInterface
{
    /**
     * Insert or update a single vector in a given collection.
     *
     * @param string              $collection Logical collection name (e.g. `skills`, `memory`).
     * @param int                 $id         Domain identifier associated with the vector.
     * @param list<float|int>     $vector     Embedding vector (normalized on write).
     * @param array<string,mixed> $metadata   Arbitrary metadata stored alongside the vector.
     */
    public function upsert(string $collection, int $id, array $vector, array $metadata = []): bool;

    /**
     * Search for nearest vectors in the given collection.
     *
     * Implementations must:
     * - use cosine similarity as the distance metric,
     * - return results sorted by similarity in descending order,
     * - return [] (not throw) when the query is empty/zero-norm, the
     *   collection is empty, or the backend is unreachable.
     *
     * @param string           $collection  Collection name.
     * @param list<float|int>  $queryVector Query embedding vector.
     * @param int              $topK        Maximum number of results.
     *
     * @return list<array{id:int, similarity:float, metadata:array<string,mixed>}> Results.
     */
    public function search(string $collection, array $queryVector, int $topK = 10): array;

    /**
     * Delete a vector from the given collection by its identifier.
     */
    public function delete(string $collection, int $id): bool;

    /**
     * Whether the backend is usable in this SAPI/configuration.
     *
     * Used by the factory and CLI diagnostics to report an honest
     * degradation status (e.g. Qdrant without the curl extension).
     */
    public function isAvailable(): bool;
}
