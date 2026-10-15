<?php
/**
 * SkillRouter: Selects the most appropriate skill for a free-form query.
 *
 * Strategy:
 *  1. Token-overlap scoring against skill name + description + tags.
 *  2. Optional vector cosine boost when the embedding store has vectors
 *     for skills under the 'skills' collection.
 *  3. Tie-break on use_count (popular skills win).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Skills;

use App\Core\Config;
use App\Helpers\Utf8Helper;
use App\Memory\MemoryEmbedderInterface;
use App\Storage\VectorStoreInterface;

final class SkillRouter
{
    private const VECTOR_TOP = 5;
    private const VECTOR_SCORE_WEIGHT = 0.25;

    private ?SkillSearch $skillSearch = null;

    public function __construct(private readonly SkillRepository $repo) {}

    /**
     * Optional semantic skill boost (Task 5, config-gated). Creates the
     * SkillSearch adapter over the given vector store + embedder; the
     * boost is only consulted in resolve() when `skills.vector_boost`
     * is enabled, so wiring this does NOT change routing by default.
     */
    public function setVectorBoost(
        ?VectorStoreInterface $store = null,
        ?MemoryEmbedderInterface $embedder = null,
    ): void {
        $this->skillSearch = new SkillSearch($store, $embedder);
    }

    /**
     * Find the best matching skill or null when none is similar enough.
     */
    public function resolve(string $query): ?Skill
    {
        $skills = $this->repo->all();
        if ($skills === []) {
            return null;
        }
        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return null;
        }
        $best = null; $bestScore = 0.0;
        $boost = [];
        if ($this->skillSearch !== null && (bool)Config::get('skills.vector_boost', false)) {
            $boost = $this->skillSearch->search($query, self::VECTOR_TOP);
        }
        foreach ($skills as $skill) {
            $haystack = $this->tokenize($skill->name . ' ' . $skill->description . ' ' . $this->skillTags($skill));
            if ($haystack === []) {
                continue;
            }
            $score = $this->jaccard($tokens, $haystack);
            $score += min(0.05, $skill->useCount * 0.001);
            $skillId = $skill->id ?? 0;
            if ($skillId > 0 && isset($boost[$skillId])) {
                $score += self::VECTOR_SCORE_WEIGHT * (float)$boost[$skillId];
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $skill;
            }
        }
        return $bestScore >= 0.08 ? $best : null;
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private function jaccard(array $a, array $b): float
    {
        $sa = array_unique($a);
        $sb = array_unique($b);
        $inter = count(array_intersect($sa, $sb));
        $union = count(array_unique(array_merge($sa, $sb)));
        return $union === 0 ? 0.0 : $inter / $union;
    }

    /** @return string[] */
    private function tokenize(string $text): array
    {
        $text = strtolower(Utf8Helper::toUtf8($text));
        $text = preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $stop  = ['a','an','the','of','to','and','or','for','with','is','are','be','in','on','at','it','by','i'];
        return array_values(array_filter(
            $parts,
            static fn(string $w): bool => $w !== '' && mb_strlen($w) >= 2 && !in_array($w, $stop, true)
        ));
    }

    private function skillTags(Skill $skill): string
    {
        $tags = $skill->definition['tags'] ?? [];
        return is_array($tags) ? implode(' ', array_map('strval', $tags)) : '';
    }
}
