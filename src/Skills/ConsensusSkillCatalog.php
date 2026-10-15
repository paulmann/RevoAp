<?php
/**
 * ConsensusSkillCatalog: the bundled consensus skills, each with the models
 * its council runs on.
 *
 * The main page's "Consensus skill" dropdown asks this list two questions:
 * what each skill *is* (name, scoring backend, description, tags — the
 * payload it has always carried) and which models it *runs on*, so choosing a
 * skill can bring the Chat sidebar's pool in line with the council that will
 * really answer.
 *
 * The link is not stored on the skill. It is stored on the PRESET: a
 * `domain_presets` row carries `definition_json.skill_slug`, and the join here
 * is exact slug equality in one direction only —
 * `preset.skillSlug === skill.slug`. A skill that no preset names gets
 * `preset: null`, `models: []` and `uses_user_pool: true`. Nothing is guessed
 * and no preset is substituted: "this council uses the pool you assembled" and
 * "this council uses the default preset" are different promises, and only one
 * of them has been made.
 *
 * The payload discloses no secret — `models` and `arbiter` are provider/model
 * ids, the same ones `/api/connectors` already publishes — which is why the
 * route that serves it stays public.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-22
 */
declare(strict_types=1);

namespace App\Skills;

use App\DomainPresets\DomainPreset;

final class ConsensusSkillCatalog
{
    /** The tag a definition must carry to appear in the dropdown. */
    public const TAG = 'consensus';

    /** What `consensus_scoring` falls back to when the definition is silent. */
    public const DEFAULT_SCORING = 'semantic_components';

    public function __construct(private readonly string $definitionsDir) {}

    /**
     * Every bundled consensus skill, sorted by slug, each entry carrying its
     * model binding.
     *
     * @param iterable<DomainPreset> $presets the same presets the Models tab lists
     * @return array<int,array<string,mixed>>
     */
    public function list(iterable $presets): array
    {
        $bindings = self::bindingsBySkillSlug($presets);
        $skills = [];
        foreach ($this->definitionFiles() as $file) {
            $decoded = $this->readDefinition($file);
            if ($decoded === null) { continue; }
            $tags = self::tagsOf($decoded);
            if (!in_array(self::TAG, $tags, true)) { continue; }
            $slug = (string)($decoded['slug'] ?? pathinfo($file, PATHINFO_FILENAME));
            $entry = [
                'slug'              => $slug,
                'file'              => pathinfo($file, PATHINFO_FILENAME),
                'name'              => (string)($decoded['name'] ?? ''),
                'description'       => (string)($decoded['description'] ?? ''),
                'consensus_scoring' => isset($decoded['consensus_scoring']) && is_string($decoded['consensus_scoring'])
                    ? $decoded['consensus_scoring'] : self::DEFAULT_SCORING,
                'tags'              => $tags,
                // How many arXiv sources the document will show; the tab uses
                // it as a hint before the reader opens the full description.
                'sources_count'     => is_array($decoded['sources'] ?? null) ? count($decoded['sources']) : 0,
            ];
            // The union appends the four binding keys; none of them can clash
            // with the keys above.
            $skills[] = $entry + self::binding($slug, $bindings);
        }
        usort($skills, static fn(array $a, array $b): int => strcmp((string)$a['slug'], (string)$b['slug']));
        return $skills;
    }

    /**
     * Add the skills an operator kept to the catalogue's own list.
     *
     * The bundled definitions are files; a skill that came out of the draft
     * flow lives in the database and is served by the engine already - the run
     * resolves its skill through SkillRepository and records the skill and role
     * fingerprints either way. What was missing was visibility: the composer's
     * list is built from this catalogue, which reads the files only, so a kept
     * skill could never be chosen for a run.
     *
     * A slug already in the list wins: the bundled definition is the one the
     * deployment ships, and a database row that happens to share its slug (the
     * persist path returns the existing skill rather than creating one) must
     * not shadow it.
     *
     * @param  array<int,array<string,mixed>> $skills     What list() produced.
     * @param  iterable<\App\Skills\Skill>    $userSkills Rows from the repository.
     * @return array<int,array<string,mixed>>
     */
    public static function mergeUserSkills(array $skills, iterable $userSkills): array
    {
        $known = [];
        foreach ($skills as $entry) {
            $known[(string)($entry['slug'] ?? '')] = true;
        }

        foreach ($userSkills as $skill) {
            $slug = (string)$skill->slug;
            if ($slug === '' || isset($known[$slug])) {
                continue;
            }
            $definition = is_array($skill->definition) ? $skill->definition : [];
            $tags = self::tagsOf($definition);
            $scoring = isset($definition['consensus_scoring']) && is_string($definition['consensus_scoring'])
                ? $definition['consensus_scoring']
                : self::DEFAULT_SCORING;

            $known[$slug] = true;
            $skills[] = [
                'slug'              => $slug,
                'file'              => null,
                'name'              => (string)$skill->name,
                'description'       => (string)$skill->description,
                'consensus_scoring' => $scoring,
                'tags'              => $tags,
                'sources_count'     => is_array($definition['sources'] ?? null) ? count($definition['sources']) : 0,
                // Says where it came from, so the interface can label it and the
                // reader of the payload can tell a shipped skill from a kept one.
                'source'            => $skill->isSystem ? 'bundled' : 'user',
                'is_system'         => $skill->isSystem,
                // No preset of its own is the normal case for a drafted skill:
                // the same binding shape a shipped skill without one gets.
                'preset'            => null,
                'models'            => [],
                'uses_user_pool'    => true,
            ];
        }

        return $skills;
    }
    /**
     * The presets indexed by the skill slug each one names.
     *
     * The FIRST preset that names a slug wins, so a duplicate binding resolves
     * identically on every request: the repository hands rows back in id
     * order, which makes the oldest link the stable one.
     *
     * @param iterable<DomainPreset> $presets
     * @return array<string,DomainPreset>
     */
    public static function bindingsBySkillSlug(iterable $presets): array
    {
        $out = [];
        foreach ($presets as $preset) {
            if (!$preset instanceof DomainPreset) { continue; }
            $slug = $preset->skillSlug;
            if (!is_string($slug) || $slug === '' || isset($out[$slug])) { continue; }
            $out[$slug] = $preset;
        }
        return $out;
    }

    /**
     * The model-binding half of one skill's entry.
     *
     * `models` are the preset's ACTIVE, selected participants — the same rows
     * the Models tab's preset list shows as that preset's pool, not every row
     * the preset mentions.
     *
     * @param array<string,DomainPreset> $bindings
     * @return array{preset:?string,models:array<int,array{provider:string,model:string}>,arbiter:?array<string,mixed>,uses_user_pool:bool}
     */
    public static function binding(string $skillSlug, array $bindings): array
    {
        $preset = $bindings[$skillSlug] ?? null;
        if ($preset === null) {
            return [
                'preset'         => null,
                'models'         => [],
                'arbiter'        => null,
                'uses_user_pool' => true,
            ];
        }
        return [
            'preset'         => $preset->slug,
            'models'         => $preset->selectedModels(),
            'arbiter'        => $preset->arbiter,
            'uses_user_pool' => false,
        ];
    }

    /**
     * The pool a skill with no preset of its own falls back to: the ACTIVE,
     * selected models of the `default` preset, or an empty array when no such
     * preset exists.
     *
     * Deliberately kept OUT of {@see self::binding()}. That method answers
     * "does this skill have a preset of its own?", and its `preset: null` /
     * `models: []` / `uses_user_pool: true` answer is the honest one — a
     * fallback is a DIFFERENT promise ("if it has none, the panel shows the
     * Default pool"), so it is published as its own field and applied by the
     * client only where it asked for one.
     *
     * @param iterable<DomainPreset> $presets
     * @return array<int,array{provider:string,model:string}>
     */
    public static function defaultPool(iterable $presets): array
    {
        foreach ($presets as $preset) {
            if ($preset instanceof DomainPreset && $preset->slug === 'default') {
                return $preset->selectedModels();
            }
        }
        return [];
    }

    /** @return string[] the definition files, in a stable order */
    private function definitionFiles(): array
    {
        if (!is_dir($this->definitionsDir)) { return []; }
        $files = glob(rtrim($this->definitionsDir, '/') . '/*.json') ?: [];
        sort($files);
        return $files;
    }

    /** @return array<string,mixed>|null the decoded definition, or null */
    private function readDefinition(string $file): ?array
    {
        $body = @file_get_contents($file);
        if (!is_string($body) || $body === '') { return null; }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $decoded @return string[] */
    private static function tagsOf(array $decoded): array
    {
        $tags = is_array($decoded['tags'] ?? null) ? $decoded['tags'] : [];
        return array_map('strval', $tags);
    }
}
