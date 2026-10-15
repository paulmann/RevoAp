<?php
/**
 * SkillSelector: the Automatic Skill Selector behind the first entry of the
 * consensus skill list.
 *
 * Before a council exists, one configured model reads the task together with
 * the whole skill catalogue and answers with either the best existing skill
 * (plus the role that should lead it) or an explicit call to build a new one.
 * A new skill is researched first: the selector asks arXiv for the task's
 * literature, ranks the papers, and the role generator writes the skill's
 * roles, synthesis prompt and sources from that reading.
 *
 * The decision model is its own setting (skills.selector.decision.*) and
 * inherits the role generator's model, which itself inherits the synthesis
 * model. The selector never throws for a decision it cannot get: a missing
 * model, unparsable JSON or a dead arXiv endpoint degrade step by step - to
 * the lexical router, to the configured fallback skill, to a shipped default.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Skills;

use App\Connectors\ConnectorRegistry;
use App\Core\Config;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use App\Research\ArxivClient;
use App\Research\PaperRanker;

final class SkillSelector
{
    /** What the composer sends when the Automatic Skill Selector is chosen. */
    public const AUTO_STEMS = ['auto-selector', 'auto_selector'];

    private const MAX_QUERY_CHARS    = 2000;
    private const MAX_DESCRIPTION    = 200;
    private const MAX_ROLES_PER_ITEM = 6;
    private const MAX_PAPERS         = 10;
    private const DEFAULT_PAPERS     = 5;
    private const MAX_ARXIV_QUERY    = 160;

    /** @var callable(string,int):array{papers:array<int,array<string,mixed>>,error:?string,query:string} */
    private $arxivSearch;

    /** @var callable(string,string,array<int,array<string,string>>):?string */
    private $chatFn;

    public function __construct(
        private readonly SkillRepository   $repo,
        private readonly ConnectorRegistry $connectors,
        private readonly ?SkillAutoCreator $creator = null,
        ?callable $arxivSearch = null,
        ?callable $chat = null,
    ) {
        $this->arxivSearch = $arxivSearch
            ?? static fn(string $topic, int $max): array => ArxivClient::search($topic, $max);
        $this->chatFn = $chat ?? function (string $provider, string $model, array $messages): ?string {
            $conn = $this->connectors->getByProvider($provider);
            if ($conn === null) {
                return null;
            }
            try {
                return (string)$conn->chat($model, $messages, ['temperature' => 0.0])->content;
            } catch (\Throwable $e) {
                Logger::warn('skill.selector.chat_failed', [
                    'message' => Logger::sanitizeScalar($e->getMessage()),
                ]);
                return null;
            }
        };
    }

    /** Is this run asking for the Automatic Skill Selector? */
    public static function isRequested(array $options): bool
    {
        foreach (['consensus_skill', 'skill'] as $key) {
            $value = $options[$key] ?? null;
            if (is_string($value) && in_array(strtolower(trim($value)), self::AUTO_STEMS, true)) {
                return true;
            }
            if (is_array($value) && isset($value['slug']) && is_string($value['slug'])
                && in_array(strtolower(trim($value['slug'])), self::AUTO_STEMS, true)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Move the preferred role to the front of the participant order. The
     * council assigns skill roles to participants in order, so the role
     * picked for this task becomes the one the first model wears.
     */
    public static function withPreferredRole(Skill $skill, ?string $roleKey): Skill
    {
        if ($roleKey === null || $roleKey === '') {
            return $skill;
        }
        $picked = null;
        $rest   = [];
        foreach ($skill->roles() as $role) {
            if ($picked === null && is_array($role) && (string)($role['key'] ?? '') === $roleKey) {
                $picked = $role;
                continue;
            }
            $rest[] = $role;
        }
        if ($picked === null) {
            return $skill;
        }
        $definition          = $skill->definition;
        $definition['roles'] = array_merge([$picked], $rest);
        return new Skill(
            id:          $skill->id,
            slug:        $skill->slug,
            name:        $skill->name,
            description: $skill->description,
            definition:  $definition,
            isSystem:    $skill->isSystem,
            useCount:    $skill->useCount,
        );
    }

    /**
     * The whole decision for one run. Never throws: every branch that cannot
     * proceed degrades to the next best source.
     *
     * @return array{skill:Skill,source:string,created:bool,role:?string,reason:string,model:?string,papers:int,arxiv_query:?string}
     */
    public function select(string $query): array
    {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_CHARS);

        $decision = null;
        $target   = null;
        try {
            $decision = $this->decide($query);
            $target   = $this->lastTarget;
        } catch (\Throwable $e) {
            Logger::warn('skill.selector.decision_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
        }

        // 1) The model named an existing skill; its role pick rides along.
        if (is_array($decision) && ($decision['action'] ?? '') === 'use') {
            $slug  = trim((string)($decision['slug'] ?? ''));
            $skill = $slug !== '' ? $this->repo->findBySlug($slug) : null;
            if ($skill !== null && !in_array($skill->slug, self::AUTO_STEMS, true)) {
                return $this->envelope(
                    $skill,
                    'model',
                    false,
                    $this->validRole($skill, $decision['role'] ?? null),
                    (string)($decision['reason'] ?? ''),
                    $target,
                    0,
                    null,
                );
            }
            // It named something the catalogue does not serve: fall through to
            // the research path, which is what "no usable match" means.
        }

        // 2) No model answer at all: the lexical router still works.
        if (!is_array($decision)) {
            $routed = (new SkillRouter($this->repo))->resolve($query);
            if ($routed !== null) {
                return $this->envelope(
                    $routed,
                    'router',
                    false,
                    null,
                    'the selector model was unavailable; routed lexically',
                    null,
                    0,
                    null,
                );
            }
        }

        // 3) Build a new skill from the literature.
        if ($this->creator !== null && (bool)Config::get('skills.selector.auto_create', true)) {
            $created = $this->createFromResearch($query, is_array($decision) ? $decision : []);
            if ($created !== null) {
                return $this->envelope(
                    $created['skill'],
                    'created',
                    true,
                    $created['role'],
                    (string)($decision['reason'] ?? 'no existing skill fits the task'),
                    $created['model'],
                    $created['papers'],
                    $created['arxiv_query'],
                );
            }
        }

        // 4) Named fallback, then the shipped default, then a generic build.
        $fallback = $this->fallbackSkill() ?? $this->repo->findBySlug('consensus-reasoning');
        if ($fallback !== null) {
            return $this->envelope(
                $fallback,
                'fallback',
                false,
                null,
                'the selector could not build a new skill; using the fallback',
                null,
                0,
                null,
            );
        }
        if ($this->creator !== null) {
            return $this->envelope(
                $this->creator->create($query),
                'fallback',
                false,
                null,
                'no skills are available; a generic council was built',
                null,
                0,
                null,
            );
        }
        throw new \RuntimeException('The skill selector found no skill to run and no way to build one.');
    }

    private ?string $lastTarget = null;

    /**
     * One model turn: which skill (and role) should carry the task, or
     * whether a new one must be built.
     *
     * @return array<string,mixed>|null
     */
    private function decide(string $query): ?array
    {
        [$provider, $model] = $this->decisionTarget();
        if ($provider === '' || $model === '') {
            return null;
        }
        $catalog = $this->catalogPayload();
        if ($catalog === []) {
            return null;
        }
        $messages = [
            ['role' => 'system', 'content' => $this->decisionSystemPrompt()],
            ['role' => 'user',   'content' =>
                "Task:\n" . $query . "\n\nThe skills catalogue, as JSON:\n"
                . (string)json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ];
        $raw = ($this->chatFn)($provider, $model, $messages);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $parsed = JsonHelper::extractFirstObject($raw);
        if (!is_array($parsed)) {
            return null;
        }
        $action = strtolower(trim((string)($parsed['action'] ?? '')));
        if ($action !== 'use' && $action !== 'create') {
            return null;
        }
        $parsed['action'] = $action;
        $this->lastTarget = $provider . '/' . $model;
        return $parsed;
    }

    /**
     * The catalogue the decision model reads: every skill, its description,
     * its tags and the keys of its roles. The selector itself is skipped.
     *
     * @return array<int,array<string,mixed>>
     */
    private function catalogPayload(): array
    {
        $out = [];
        foreach ($this->repo->all() as $skill) {
            if (in_array($skill->slug, self::AUTO_STEMS, true)) {
                continue;
            }
            $roles = [];
            foreach ($skill->roles() as $role) {
                if (!is_array($role)) {
                    continue;
                }
                $roles[] = [
                    'key'   => (string)($role['key'] ?? ''),
                    'label' => (string)($role['label'] ?? ($role['key'] ?? '')),
                ];
                if (count($roles) >= self::MAX_ROLES_PER_ITEM) {
                    break;
                }
            }
            $tags = $skill->definition['tags'] ?? [];
            $out[] = [
                'slug'        => $skill->slug,
                'name'        => $skill->name,
                'description' => mb_substr($skill->description, 0, self::MAX_DESCRIPTION),
                'tags'        => is_array($tags) ? array_slice(array_map('strval', $tags), 0, 6) : [],
                'roles'       => $roles,
            ];
        }
        return $out;
    }

    /**
     * Fetch, rank and hand the literature to the role generator. Returns
     * null when nothing could be built; the caller degrades to the fallback.
     *
     * @param  array<string,mixed> $decision
     * @return array{skill:Skill,role:?string,model:?string,papers:int,arxiv_query:string}|null
     */
    private function createFromResearch(string $query, array $decision): ?array
    {
        try {
            $arxivQuery = trim((string)($decision['arxiv_query'] ?? ''));
            if ($arxivQuery === '') {
                $arxivQuery = $query;
            }
            $arxivQuery = mb_substr($arxivQuery, 0, self::MAX_ARXIV_QUERY);
            $max    = $this->maxPapers();
            $found  = ($this->arxivSearch)($arxivQuery, $max);
            $papers = is_array($found['papers'] ?? null) ? array_values($found['papers']) : [];
            try {
                $ranked = PaperRanker::rank($papers, ArxivClient::topicTerms($arxivQuery), $max);
            } catch (\Throwable) {
                $ranked = array_slice($papers, 0, $max);
            }
            $built = $this->creator->createFromResearch($query, $ranked);
            return [
                'skill'       => $built['skill'],
                'role'        => $built['preferred_role'],
                'model'       => $built['model'],
                'papers'      => count($ranked),
                'arxiv_query' => $arxivQuery,
            ];
        } catch (\Throwable $e) {
            Logger::warn('skill.selector.research_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return null;
        }
    }

    private function maxPapers(): int
    {
        $configured = (int)Config::get('skills.selector.max_papers', self::DEFAULT_PAPERS);
        return max(1, min(self::MAX_PAPERS, $configured > 0 ? $configured : self::DEFAULT_PAPERS));
    }

    private function fallbackSkill(): ?Skill
    {
        $slug = trim((string)Config::get('skills.selector.fallback_skill', ''));
        if ($slug === '' || in_array($slug, self::AUTO_STEMS, true)) {
            return null;
        }
        return $this->repo->findBySlug($slug);
    }

    /** Keep a role key only when the skill actually carries it. */
    private function validRole(Skill $skill, mixed $role): ?string
    {
        if (!is_string($role)) {
            return null;
        }
        $key = trim($role);
        if ($key === '') {
            return null;
        }
        foreach ($skill->roles() as $candidate) {
            if (is_array($candidate) && (string)($candidate['key'] ?? '') === $key) {
                return $key;
            }
        }
        return null;
    }

    /**
     * @return array{0:string,1:string} provider, model
     */
    private function decisionTarget(): array
    {
        $provider = trim((string)Config::get('skills.selector.decision.provider', ''));
        $model    = trim((string)Config::get('skills.selector.decision.model', ''));
        if ($provider !== '' && $model !== '') {
            return [$provider, $model];
        }
        if ($this->creator !== null) {
            return $this->creator->generatorTarget();
        }
        return [
            (string)Config::get('pipeline.synthesis_provider', 'bothub'),
            (string)Config::get('pipeline.synthesis_model', 'gpt-6-luna-pro'),
        ];
    }

    private function decisionSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the skill selector of a multi-model consensus platform. You receive a task and the catalogue of every skill (a council: role prompts plus a synthesis strategy).

Choose the single best skill for this task, or decide that a new one must be built.

Rules:
- Prefer an existing skill only when it genuinely fits the task's subject AND method. A generic council is not a good fit for a specialised task.
- Choose "create" when no skill fits, or when the topic is narrow/domain-specific enough that existing roles would not carry its method.
- When action is "use": slug must be one of the catalogue slugs, and role must be one of that skill's role keys - the one role that best fits THIS task.
- When action is "create": provide arxiv_query - 1 to 6 search words/phrases for the literature that a new skill should be grounded in.
- reason: one or two sentences, concrete.

Output STRICT JSON only, no commentary:
{"action": "use" | "create", "slug": "<when use>", "role": "<when use>", "arxiv_query": "<when create>", "reason": "..."}
PROMPT;
    }

    /**
     * @return array{skill:Skill,source:string,created:bool,role:?string,reason:string,model:?string,papers:int,arxiv_query:?string}
     */
    private function envelope(
        Skill $skill,
        string $source,
        bool $created,
        ?string $role,
        string $reason,
        ?string $model,
        int $papers,
        ?string $arxivQuery,
    ): array {
        return [
            'skill'       => $skill,
            'source'      => $source,
            'created'     => $created,
            'role'        => $role,
            'reason'      => $reason,
            'model'       => $model,
            'papers'      => $papers,
            'arxiv_query' => $arxivQuery,
        ];
    }
}
