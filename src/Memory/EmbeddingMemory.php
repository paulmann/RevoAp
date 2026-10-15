<?php
/**
 * EmbeddingMemory: The vector memory core (Task 5 search/store engine).
 *
 * Combines three pieces into one facade:
 *   - MemoryEmbedderInterface - model-driven text→vector embedding
 *     (App\Memory\ConnectorMemoryEmbedder wraps connector registry);
 *   - VectorStoreInterface    - cosine-similarity storage
 *     (App\Storage\VectorStore on MySQL JSON by default, optional
 *     Qdrant via config `vector.driver=qgdrant`);
 *   - PdoMemoryRepository     - durable `memory_items` rows and
 *     degraded in-process fallback when the table is absent.
 *
 * Guarantees:
 *   - embed()/search/store failures NEVER propagate: every public
 *     method returns a safe default, so the pipeline continues with
 *     "no memory" instead of breaking;
 *   - vectors are unit-normalized on write (embedding outputs and
 *     generic vectors alike);
 *   - searchRelevant() applies a session filter so a session only
 *     recalls its own memory, while searchGlobal()/recallContextGlobal()
 *     recall across ALL sessions for pipeline prompt injection;
 *   - the projected memory is capped at 5_000 bytes (separator +
 *     ellipsis) so prompt paging cost can never explode;
 *   - `keepRawMemory` keeps a plain `memory_items` row even when the
 *     vector path is disabled (pipeline.memory_enabled=false).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-09-18
 */
declare(strict_types=1);

namespace App\Memory;

use App\Core\Logger;
use App\Storage\VectorStore;
use App\Storage\VectorStoreInterface;

final class EmbeddingMemory
{
    public const COLLECTION = 'memory';

    /** Not a valid id - used when persistence is explicitly disabled. */
    public const NO_ID = 0;

    private const DEFAULT_TOP_K = 10;
    private const MIN_SCORE = 0.0;
    private const MAX_PROJECTED_BYTES = 5000;

    public function __construct(
        private readonly ?VectorStoreInterface $vectorStore = null,
        private readonly ?MemoryEmbedderInterface $embedder = null,
        private readonly ?PdoMemoryRepository $repository = null,
    ) {}

    public function vectorStore(): ?VectorStoreInterface
    {
        return $this->vectorStore;
    }

    public function embedder(): ?MemoryEmbedderInterface
    {
        return $this->embedder;
    }

    public function repository(): ?PdoMemoryRepository
    {
        return $this->repository;
    }

    /**
     * Initialise with project defaults when dependencies were omitted:
     * VectorStore(mysql in-memory) + embedder as given. Never throws.
     */
    public static function createForDefault(
        ?MemoryEmbedderInterface $embedder = null,
        ?PdoMemoryRepository $repository = null,
    ): self {
        $store = new VectorStore();
        return new self($store, $embedder, $repository);
    }

    /**
     * Recursively embed + upsert one text into the memory collection.
     */
    public function storeText(
        int $sessionId,
        string $content,
        string $type = MemoryItem::TYPE_TEXT,
        array $metadata = [],
    ): int {
        $item = new MemoryItem(
            id:        null,
            sessionId: $sessionId,
            content:   $content,
            metadata:  $metadata,
            type:      $type,
        );
        $id   = $this->repository !== null ? $this->repository->add($item) : 0;
        if ($this->embedder === null || $this->vectorStore === null || !$this->embedder->isAvailable()) {
            return $id;
        }
        $embedding = $this->embedder->embed($item->searchPayload());
        if ($embedding === []) {
            return $id;
        }
        try {
            $this->vectorStore->upsert(
                self::COLLECTION,
                $id,
                $embedding,
                $item->vectorMetadata(),
            );
        } catch (\Throwable $e) {
            Logger::warn('memory.store.upsert_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
        }
        return $id;
    }

    /**
     * Store the session's final answer as memory.
     *
     * @param array<string,mixed>|null $meta
     */
    public function storeFinalAnswer(int $sessionId, string $answer, ?array $meta = null): int
    {
        return $this->storeText(
            $sessionId,
            $answer,
            MemoryItem::TYPE_TEXT,
            array_merge([
                'role'       => 'final_answer',
                'session_id' => $sessionId,
            ], is_array($meta) ? $meta : []),
        );
    }

    /**
     * Enable raw `memory_items` persistence only (no vector path).
     * Calls repository->add directly; returns an id or 0.
     */
    public function keepRaw(int $sessionId, string $content, string $type = MemoryItem::TYPE_TEXT): int
    {
        if ($this->repository === null) {
            return self::NO_ID;
        }
        return $this->repository->add(new MemoryItem(
            id:        null,
            sessionId: $sessionId,
            content:   $content,
            type:      $type,
        ));
    }

    /**
     * Recall the memory items most relevant to the query, limited to
     * the given session.
     *
     * @return list<MemoryItem>
     */
    public function searchRelevant(int $sessionId, string $query, int $limit = self::DEFAULT_TOP_K): array
    {
        return $this->searchInternal($query, $limit, $sessionId);
    }

    /**
     * Cross-session semantic recall (pipeline memory integration).
     * Unlike searchRelevant() this does NOT filter by session id, so
     * the pipeline can reuse knowledge stored by earlier conversations.
     *
     * @return list<MemoryItem>
     */
    public function searchGlobal(string $query, int $limit = self::DEFAULT_TOP_K): array
    {
        return $this->searchInternal($query, $limit, null);
    }

    /**
     * Shared search implementation.
     *
     * @param int|null $sessionId session filter; null searches ALL sessions.
     * @return list<MemoryItem>
     */
    private function searchInternal(string $query, int $limit, ?int $sessionId): array
    {
        if ($query === '' || $this->vectorStore === null || $this->embedder === null
            || !$this->embedder->isAvailable()) {
            return [];
        }
        try {
            $embedding = $this->embedder->embed($query);
        } catch (\Throwable $e) {
            Logger::warn('memory.search.embed_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
            return [];
        }
        if ($embedding === []) {
            return [];
        }
        try {
            $hits = $this->vectorStore->search(self::COLLECTION, $embedding, $limit);
        } catch (\Throwable $e) {
            Logger::warn('memory.search.store_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
            return [];
        }
        $results = [];
        foreach ($hits as $hit) {
            if (!is_array($hit) || !isset($hit['id'])) { continue; }
            if ((float)($hit['similarity'] ?? 0.0) < self::MIN_SCORE) { continue; }
            $item = $this->lookupItem((int)$hit['id'], $hit);
            if ($item === null) { continue; }
            if ($sessionId !== null && $item->sessionId !== $sessionId) { continue; }
            $results[$item->id ?? (int)$hit['id']] = $item;
            if (count($results) >= $limit) { break; }
        }
        return array_values($results);
    }

    /**
     * Build the context text to inject into an LLM prompt.
     *
     * @param int $limit max recalled items
     */
    public function recallContextForQuery(int $sessionId, string $query, int $limit = self::DEFAULT_TOP_K): array
    {
        $items = $this->searchRelevant($sessionId, $query, $limit);
        $text  = $this->buildMemoryText($items);
        $context = '';
        if ($items !== []) {
            $context = $this->truncateProjected($text);
        }
        return ['items' => $items, 'text' => $context];
    }

    /**
     * Cross-session recall context for pipeline prompt injection.
     *
     * @param int $limit max recalled items
     */
    public function recallContextGlobal(string $query, int $limit = self::DEFAULT_TOP_K): array
    {
        $items = $this->searchGlobal($query, $limit);
        $text  = $this->buildMemoryText($items);
        $context = '';
        if ($items !== []) {
            $context = $this->truncateProjected($text);
        }
        return ['items' => $items, 'text' => $context];
    }

    /**
     * Inspect whether vector memory is usable in this environment.
     *
     * @return array<string,mixed> diagnostic envelope for /api/health.
     */
    public function diagnostic(): array
    {
        return [
            'vector_store' => $this->vectorStore !== null,
            'embedder'     => $this->embedder !== null && $this->embedder->isAvailable(),
            'repository'   => $this->repository !== null,
        ];
    }

    /**
     * @param array<memory, array<string,mixed>> $hit
     */
    private function lookupItem(int $id, array $hit = []): ?MemoryItem
    {
        if ($this->repository !== null) {
            $item = $this->repository->findById($id);
            if ($item !== null) {
                return $item;
            }
        }
        $meta = is_array($hit['metadata'] ?? null) ? $hit['metadata'] : [];
        $content = is_string($meta['content'] ?? null) ? $meta['content'] : '';
        if ($content === '') {
            return null;
        }
        return new MemoryItem(
            id:        $id,
            sessionId: isset($meta['session_id']) ? (int)$meta['session_id'] : 0,
            content:   $content,
            metadata:  $meta,
            type:      is_string($meta['type'] ?? null) ? $meta['type'] : MemoryItem::TYPE_TEXT,
        );
    }

    /**
     * @param list<MemoryItem> $items
     */
    private function buildMemoryText(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $chunks = [];
        foreach ($items as $item) {
            $chunks[] = '[' . $item->type . '] ' . $item->content;
        }
        return implode("\n---\n", $chunks);
    }

    private function truncateProjected(string $text): string
    {
        if (strlen($text) <= self::MAX_PROJECTED_BYTES) {
            return $text;
        }
        $cut = substr($text, 0, self::MAX_PROJECTED_BYTES - 3);
        return $cut . '…';
    }
}
