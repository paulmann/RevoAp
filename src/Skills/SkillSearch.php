<?php
/**
 * SkillSearch: Semantic skill search over the 'skills' vector collection.
 *
 * Task 5/6 adaptation of the semantic-search idea from
 * PROTO Consensus-AI-main/src/Application/Council/SkillSearch.php,
 * rebuilt on the project's own contracts:
 *   - App\Storage\VectorStoreInterface (id + similarity hits);
 *   - App\Memory\MemoryEmbedderInterface (embed() never throws,
 *     [] means "embedding unavailable").
 *
 * Used by SkillRouter as an optional, config-gated boost
 * (`skills.vector_boost = true`). Every method degrades silently:
 * indexing a skill with no id or an unavailable embedder returns
 * false, and search() returns an empty map, so routing keeps working
 * on the token-overlap baseline when the vector stack is absent.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

namespace App\Skills;

use App\Memory\MemoryEmbedderInterface;
use App\Storage\VectorStoreInterface;
use Throwable;

final class SkillSearch
{
    public const COLLECTION = 'skills';

    private const TOP_K    = 5;
    private const MIN_SCORE = 0.0;

    public function __construct(
        private readonly ?VectorStoreInterface $store = null,
        private readonly ?MemoryEmbedderInterface $embedder = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->store !== null
            && $this->embedder !== null
            && $this->embedder->isAvailable();
    }

    /**
     * Index (upsert) one skill's searchable text under the 'skills'
     * collection keyed by the skill's row id. Skills without an id
     * (not yet persisted) are skipped; returns false on any
     * degradation instead of throwing.
     */
    public function index(int $skillId, string $text, string $slug = ''): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $text = trim($text);
        if ($skillId <= 0 || $text === '') {
            return false;
        }
        $embedding = $this->embedder->embed($text);
        if ($embedding === []) {
            return false;
        }
        try {
            return $this->store->upsert(self::COLLECTION, $skillId, $embedding, [
                'slug' => $slug,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Semantic search over the skills collection.
     *
     * @return array<int,float> Map of skillId => cosine similarity,
     *                          empty on any failure.
     */
    public function search(string $query, int $topK = self::TOP_K): array
    {
        if (!$this->isAvailable() || trim($query) === '') {
            return [];
        }
        $embedding = $this->embedder->embed($query);
        if ($embedding === []) {
            return [];
        }
        try {
            $hits = $this->store->search(self::COLLECTION, $embedding, max(1, $topK));
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($hits as $hit) {
            if (!is_array($hit) || !isset($hit['id'])) {
                continue;
            }
            $similarity = (float)($hit['similarity'] ?? 0.0);
            if ($similarity < self::MIN_SCORE) {
                continue;
            }
            $out[(int)$hit['id']] = $similarity;
        }
        return $out;
    }
}
