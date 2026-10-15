<?php
/**
 * FinalPromptPreviewer: v1.8.45.
 *
 * Builds a non-destructive preview of the final prompt RevoAp would
 * send to the LLM for a given sample query, optionally narrowed to a
 * specific skill / role. The preview reuses {@see SystemPromptAssembler}
 * so the rendered blocks match exactly what the pipeline composes at
 * runtime — no duplicate JS logic, no shadow assembler.
 *
 * Hard guarantees:
 *   - No connector / LLM calls are made.
 *   - No pipeline run is started; no council job is enqueued.
 *   - No prompt bodies are logged. Errors carry only metadata
 *     (lengths, slugs).
 *   - The previewer always reads saved settings (toggle + override +
 *     profile addendum). Unsaved Settings UI edits leak nothing into
 *     this preview by design.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-15
 */
declare(strict_types=1);

namespace App\Profiles;

use App\Skills\Skill;
use App\Skills\SkillRepository;
use PDO;

final class FinalPromptPreviewer
{
    public const FALLBACK_QUERY = 'Preview request — sample user query for prompt assembly inspection.';

    public function __construct(
        private readonly SystemPromptAssembler $assembler,
        private readonly ?UserProfileRepository $profiles = null,
        private readonly ?SkillRepository $skills = null,
    ) {}

    /**
     * Build a structured preview for the supplied sample query and
     * (optional) skill identifier. The returned array is ready to
     * JSON-encode for the Settings UI.
     *
     * @param array{query?:string,skill?:string,skill_id?:int|string,role?:string} $input
     * @return array{
     *   query:string,
     *   blocks:list<array{label:string,included:bool,body:string,reason:string}>,
     *   assembled:string,
     *   metadata:array{
     *     use_system_prompt:bool,
     *     has_custom_system_prompt:bool,
     *     skill_preview_available:bool,
     *     selected_skill:?array{slug:string,name:string,role_key:?string,role_label:?string},
     *     profile_loaded:bool,
     *     base_prompt_chars:int,
     *     assembled_chars:int
     *   }
     * }
     */
    public function preview(array $input): array
    {
        $query = isset($input['query']) && is_string($input['query']) ? trim($input['query']) : '';
        if ($query === '') {
            $query = self::FALLBACK_QUERY;
        }

        $profile = $this->loadProfile();
        [$skill, $roleEntry] = $this->resolveSkill($input);
        $rolePrompt = $this->extractRolePrompt($skill, $roleEntry);

        $blocks = $this->assembler->composeBlocks($profile, $rolePrompt);
        $blocks[] = [
            'label'    => 'USER',
            'included' => true,
            'body'     => $query,
            'reason'   => '',
        ];

        $assembled = $this->renderAssembled($blocks);

        $useBase    = $this->assembler->useBasePrompt();
        $baseChars  = mb_strlen($this->assembler->effectiveBasePrompt());
        $hasCustom  = $this->hasCustomBasePrompt();

        $selectedSkill = null;
        if ($skill !== null) {
            $selectedSkill = [
                'slug'       => $skill->slug,
                'name'       => $skill->name,
                'role_key'   => is_array($roleEntry) && isset($roleEntry['key']) ? (string)$roleEntry['key'] : null,
                'role_label' => is_array($roleEntry) && isset($roleEntry['label']) ? (string)$roleEntry['label'] : null,
            ];
        }

        return [
            'query'     => $query,
            'blocks'    => $blocks,
            'assembled' => $assembled,
            'metadata'  => [
                'use_system_prompt'        => $useBase,
                'has_custom_system_prompt' => $hasCustom,
                'skill_preview_available'  => $this->skills !== null,
                'selected_skill'           => $selectedSkill,
                'profile_loaded'           => $profile !== null,
                'base_prompt_chars'        => $baseChars,
                'assembled_chars'          => mb_strlen($assembled),
            ],
        ];
    }

    /**
     * Render the concatenated preview from the structured blocks.
     * Mirrors {@see SystemPromptAssembler::compose()} but also emits
     * the `[USER]` block so the operator sees the exact text that
     * would be appended as the user message.
     *
     * @param list<array{label:string,included:bool,body:string,reason:string}> $blocks
     */
    private function renderAssembled(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            if (!$b['included'] || $b['body'] === '') {
                continue;
            }
            $parts[] = '[' . $b['label'] . "]\n" . $b['body'];
        }
        return implode("\n\n", $parts);
    }

    private function loadProfile(): ?UserProfile
    {
        if ($this->profiles === null) {
            return null;
        }
        try {
            return $this->profiles->findByExternalId(UserProfileRepository::LOCAL_EXTERNAL_ID);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the requested skill (by id or slug). Returns the Skill
     * and the chosen role definition (first role unless a `role`
     * key/label was supplied). Returns [null, null] when no skill is
     * requested or the lookup fails — the preview then explains that
     * the runtime auto-router picks the skill.
     *
     * @param array<string,mixed> $input
     * @return array{0:?Skill,1:?array<string,mixed>}
     */
    private function resolveSkill(array $input): array
    {
        if ($this->skills === null) {
            return [null, null];
        }
        $skill = null;
        try {
            if (isset($input['skill_id'])) {
                $id = (int)$input['skill_id'];
                if ($id > 0) {
                    $skill = $this->skills->findById($id);
                }
            }
            if ($skill === null && isset($input['skill']) && is_string($input['skill']) && $input['skill'] !== '') {
                $skill = $this->skills->findBySlug(trim($input['skill']));
            }
        } catch (\Throwable) {
            $skill = null;
        }
        if ($skill === null) {
            return [null, null];
        }
        $roles = $skill->roles();
        if ($roles === []) {
            return [$skill, null];
        }
        $wantedRole = isset($input['role']) && is_string($input['role']) ? trim($input['role']) : '';
        if ($wantedRole !== '') {
            foreach ($roles as $r) {
                if (!is_array($r)) { continue; }
                if (($r['key'] ?? null) === $wantedRole || ($r['label'] ?? null) === $wantedRole) {
                    return [$skill, $r];
                }
            }
        }
        $first = $roles[0];
        return [$skill, is_array($first) ? $first : null];
    }

    /**
     * Extract the role/skill system prompt for the preview. When the
     * role itself carries `system` text we use it verbatim; otherwise
     * we surface the synthesis prompt so the preview still reflects a
     * realistic skill-shaped block.
     *
     * @param array<string,mixed>|null $roleEntry
     */
    private function extractRolePrompt(?Skill $skill, ?array $roleEntry): string
    {
        if ($skill === null) {
            return '';
        }
        if ($roleEntry !== null && isset($roleEntry['system']) && is_string($roleEntry['system'])) {
            $sys = trim($roleEntry['system']);
            if ($sys !== '') {
                return $sys;
            }
        }
        $synthesis = trim($skill->synthesisPrompt());
        return $synthesis;
    }

    private function hasCustomBasePrompt(): bool
    {
        $file = $this->assembler->fileDefaultPrompt();
        $effective = $this->assembler->effectiveBasePrompt();
        return $effective !== '' && $effective !== $file;
    }
}
