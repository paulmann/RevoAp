<?php
/**
 * SkillAutoCreator: Builds a new Skill on-the-fly when no existing skill matches.
 *
 * Calls a generator LLM (via the connector registry) to draft a skill JSON
 * definition, validates required fields, and persists it through SkillRepository.
 * If the LLM is unavailable, falls back to a synthetic generic skill so the
 * pipeline can still run.
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

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\ConnectorException;
use App\Core\Config;
use App\Helpers\JsonHelper;

final class SkillAutoCreator
{
    /** @var callable(string,string,array<int,array<string,string>>):?string|null */
    private $chatFn = null;

    public function __construct(
        private readonly SkillRepository    $repo,
        private readonly ConnectorRegistry  $connectors,
        ?callable $chat = null,
    ) {
        $this->chatFn = $chat;
    }

    public function create(string $query): Skill
    {
        $draft = $this->draftDefinition($query) ?? $this->fallbackDefinition($query);
        return $this->persist($draft);
    }

    /**
     * Draft a skill definition and STOP - nothing is saved.
     *
     * This is the review half of an auto-created skill. create() saves inside
     * a run, silently, which is right for a fallback nobody has time to
     * approve; an operator who asks for a draft wants to see it first, edit
     * it, and only then keep it. The envelope says whether a model or the
     * synthetic fallback produced it, because those two deserve different
     * trust.
     *
     * @return array{definition:array<string,mixed>,source:string,model:?string}
     */
    public function draft(string $query): array
    {
        $definition = $this->draftDefinition($query);
        if ($definition !== null) {
            return [
                'definition' => $definition,
                'source'     => 'model',
                'model'      => implode('/', $this->generatorTarget()),
            ];
        }
        return [
            'definition' => $this->fallbackDefinition($query),
            'source'     => 'fallback',
            'model'      => null,
        ];
    }

    /**
     * Keep a reviewed definition. Refuses anything that would not survive a
     * run, and an existing slug is returned instead of overwritten - a system
     * skill must never be replaced by a draft that happens to share its name.
     *
     * @param array<string,mixed> $draft
     * @throws \InvalidArgumentException
     */
    public function persist(array $draft): Skill
    {
        if (!$this->isValidDraft($draft)) {
            throw new \InvalidArgumentException('The definition needs a name and at least one role with a key and a system prompt.');
        }
        $slug = $this->makeSlug((string)($draft['slug'] ?? ($draft['name'] ?? 'auto-skill')));
        $existing = $this->repo->findBySlug($slug);
        if ($existing !== null) {
            return $existing;
        }
        $skill = new Skill(
            id:          null,
            slug:        $slug,
            name:        (string)($draft['name'] ?? 'Auto Skill'),
            description: (string)($draft['description'] ?? 'Auto-generated skill'),
            definition:  $draft,
            isSystem:    false,
            useCount:    0,
        );
        return $this->repo->save($skill);
    }

    /**
     * The model that drafts roles and system prompts: its own setting when one
     * is chosen, the synthesis model otherwise.
     *
     * @return array{0:string,1:string} provider, model
     */
    public function generatorTarget(): array
    {
        $provider = trim((string)Config::get('skills.generator.provider', ''));
        $model    = trim((string)Config::get('skills.generator.model', ''));
        if ($provider !== '' && $model !== '') {
            return [$provider, $model];
        }
        return [
            (string)Config::get('pipeline.synthesis_provider', 'bothub'),
            (string)Config::get('pipeline.synthesis_model', 'gpt-6-luna-pro'),
        ];
    }

    /**
     * One model turn, either through the injected test channel or the real
     * connectors. Returns null when the call cannot be made or fails.
     *
     * @param array<int,array<string,string>> $messages
     */
    private function chat(string $provider, string $model, array $messages): ?string
    {
        if ($this->chatFn !== null) {
            $out = ($this->chatFn)($provider, $model, $messages);
            return is_string($out) ? $out : null;
        }
        $conn = $this->connectors->getByProvider($provider);
        if ($conn === null) {
            return null;
        }
        try {
            return (string)$conn->chat($model, $messages, ['temperature' => 0.2])->content;
        } catch (ConnectorException) {
            return null;
        }
    }

    /**
     * v1.9.5 - draft a skill from the task AND the literature the selector
     * fetched for it. The papers become the definition's sources, and the
     * model also names the role this task should lead with.
     *
     * @param  array<int,array<string,mixed>> $papers
     * @return array{skill:Skill,preferred_role:?string,model:?string}
     */
    public function createFromResearch(string $query, array $papers): array
    {
        [$provider, $model] = $this->generatorTarget();
        $draft = null;
        $usedModel = null;
        if ($papers !== []) {
            $messages = [
                ['role' => 'system', 'content' => $this->researchSystemPrompt()],
                ['role' => 'user',   'content' => $this->researchUserMessage($query, $papers)],
            ];
            $content = $this->chat($provider, $model, $messages);
            if ($content !== null) {
                $parsed = JsonHelper::extractFirstObject($content);
                if ($this->isValidDraft($parsed)) {
                    $draft = $parsed;
                    $usedModel = $provider . '/' . $model;
                }
            }
        }
        if ($draft === null) {
            $draft = $this->fallbackDefinition($query);
            $draft['description'] = 'Auto-generated skill grounded in arXiv research - multi-perspective consensus.';
        }
        $sources = $this->normalizeSources($draft['sources'] ?? null, $papers);
        if ($sources !== []) {
            $draft['sources'] = $sources;
        }
        $preferred = null;
        if (isset($draft['preferred_role']) && is_string($draft['preferred_role'])) {
            $key = trim($draft['preferred_role']);
            foreach (($draft['roles'] ?? []) as $role) {
                if (is_array($role) && (string)($role['key'] ?? '') === $key) {
                    $preferred = $key;
                    break;
                }
            }
        }
        return [
            'skill'          => $this->persist($draft),
            'preferred_role' => $preferred,
            'model'          => $usedModel,
        ];
    }

    private function researchSystemPrompt(): string
    {
        return <<<'PROMPT'
You design "skills" for a multi-LLM consensus pipeline. A set of arXiv papers is provided; anchor the skill's roles and method in what the papers actually do. Output STRICT JSON only with: slug (kebab-case), name, description, roles (3-5 entries of {key,label,system} with substantial expert prompts), synthesis_prompt, tags, preferred_role (the role key that should lead THIS task), sources (array of {title,url} for the papers actually relevant - copy titles and urls verbatim). No commentary outside JSON.
PROMPT;
    }

    /** @param array<int,array<string,mixed>> $papers */
    private function researchUserMessage(string $query, array $papers): string
    {
        $lines = ['Task:', mb_substr($query, 0, 2000), '', 'arXiv papers:'];
        $i = 0;
        foreach ($papers as $paper) {
            if (!is_array($paper)) { continue; }
            $i++;
            $title   = trim((string)($paper['title'] ?? ''));
            $url     = trim((string)($paper['url'] ?? ($paper['link'] ?? '')));
            $summary = trim((string)($paper['summary'] ?? ($paper['abstract'] ?? '')));
            $lines[] = $i . '. ' . $title;
            if ($url !== '') { $lines[] = '   url: ' . $url; }
            if ($summary !== '') { $lines[] = '   abstract: ' . mb_substr(preg_replace('/\s+/u', ' ', $summary) ?? $summary, 0, 600); }
            if ($i >= 8) { break; }
        }
        return implode("\n", $lines);
    }

    /**
     * Keep only real http(s) sources: the model's list when it named any,
     * otherwise the papers the selector actually fetched.
     *
     * @param  mixed $claimed
     * @param  array<int,array<string,mixed>> $papers
     * @return array<int,array{title:string,url:string}>
     */
    private function normalizeSources(mixed $claimed, array $papers): array
    {
        $out = [];
        if (is_array($claimed)) {
            foreach ($claimed as $source) {
                if (!is_array($source)) { continue; }
                $url   = trim((string)($source['url'] ?? ''));
                $title = trim((string)($source['title'] ?? ''));
                if ($url === '' || preg_match('#^https?://#i', $url) !== 1) { continue; }
                if ($title === '') { $title = $url; }
                $out[] = ['title' => mb_substr($title, 0, 300), 'url' => $url];
                if (count($out) >= 5) { break; }
            }
        }
        if ($out === []) {
            foreach ($papers as $paper) {
                if (!is_array($paper)) { continue; }
                $url   = trim((string)($paper['url'] ?? ($paper['link'] ?? '')));
                $title = trim((string)($paper['title'] ?? ''));
                if ($url === '' || preg_match('#^https?://#i', $url) !== 1) { continue; }
                if ($title === '') { $title = $url; }
                $out[] = ['title' => mb_substr($title, 0, 300), 'url' => $url];
                if (count($out) >= 5) { break; }
            }
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function draftDefinition(string $query): ?array
    {
        // The role generator can have a model of its own - it writes role
        // prompts, which is a different job from writing the final answer, and
        // an operator may well want a cheaper or a stronger model for it. Unset
        // means the synthesis model, exactly as the actualize section inherits
        // the analyzer.
        [$provider, $model] = $this->generatorTarget();
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user',   'content' => "Design a skill JSON for the following query:\n{$query}"],
        ];
        $content = $this->chat($provider, $model, $messages);
        if ($content === null) {
            return null;
        }
        $parsed = JsonHelper::extractFirstObject($content);
        return $this->isValidDraft($parsed) ? $parsed : null;
    }

    /** @return array<string,mixed> */
    private function fallbackDefinition(string $query): array
    {
        $shortQ = mb_substr($query, 0, 80);
        $name   = 'Generic Council on: ' . $shortQ;
        return [
            'slug'        => $this->makeSlug($name),
            'name'        => $name,
            'description' => 'Auto-generated generic skill — multi-perspective consensus.',
            'roles' => [
                ['key' => 'analyst',   'label' => 'Analyst',   'system' => 'You are an analytical reviewer. Decompose the question and answer rigorously.'],
                ['key' => 'critic',    'label' => 'Critic',    'system' => 'You are a critical reviewer. Identify weaknesses and risks.'],
                ['key' => 'pragmatist','label' => 'Pragmatist','system' => 'You are a pragmatic adviser focused on actionable next steps.'],
            ],
            'synthesis_prompt' => 'Combine the role responses into a balanced final answer with clear recommendations.',
            'tags' => ['generic', 'consensus'],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You design "skills" for a multi-LLM consensus pipeline. Output STRICT JSON only.
The JSON must contain: slug (kebab-case), name, description, roles (array of {key,label,system}),
synthesis_prompt (string), tags (string array). Do not include commentary outside JSON.
PROMPT;
    }

    /** @param array<string,mixed>|null $d */
    public function isValidDraft(?array $d): bool
    {
        if (!is_array($d)) { return false; }
        if (!isset($d['name'], $d['roles']) || !is_array($d['roles']) || $d['roles'] === []) {
            return false;
        }
        foreach ($d['roles'] as $r) {
            if (!is_array($r) || empty($r['key']) || empty($r['system'])) { return false; }
        }
        return true;
    }

    private function makeSlug(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? $text;
        $text = trim($text, '-');
        if ($text === '') { $text = 'auto-' . substr(sha1((string)microtime(true)), 0, 8); }
        return mb_substr($text, 0, 60);
    }
}
