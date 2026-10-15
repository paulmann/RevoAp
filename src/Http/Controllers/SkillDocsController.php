<?php
/**
 * SkillDocsController: one consensus skill's full description, published as
 * a share document.
 *
 * The Skills page lists the catalogue and, on a click, posts here. The
 * document is rendered by {@see SkillDoc} from the skill's own definition
 * (bundled JSON or the database row a draft flow kept) and published through
 * {@see DocumentShareController::publish()} - the same write, the same
 * limits and the same MD.Viewer URL the Share button uses, so the two paths
 * can never drift apart. The answer carries `viewer_url` only when the
 * extension is actually installed; the client opens whichever URL the
 * server says is right.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\DomainPresets\DomainPresetRepository;
use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Skills\ConsensusSkillCatalog;
use App\Skills\SkillDoc;
use App\Skills\SkillRepository;

final class SkillDocsController
{
    public function __construct(
        private readonly string $rootDir,
        private readonly DocumentShareController $share,
        private readonly ?SkillRepository $skills = null,
        private readonly ?DomainPresetRepository $presets = null,
    ) {}

    /** POST /api/consensus/skills/{slug}/document */
    public function document(array $params): string
    {
        $slug = trim((string)($params['slug'] ?? ''));

        // The same merge the page (and the composer) shows: bundled files
        // first, kept user skills appended; a slug clash keeps the bundled
        // definition, exactly like the catalogue.
        $catalog = new ConsensusSkillCatalog($this->rootDir . '/src/Skills/definitions');
        $list = ConsensusSkillCatalog::mergeUserSkills(
            $catalog->list($this->presets !== null ? $this->presets->all() : []),
            $this->skills !== null ? $this->skills->all() : [],
        );

        $entry = null;
        foreach ($list as $row) {
            if (is_array($row) && (string)($row['slug'] ?? '') === $slug) {
                $entry = $row;
                break;
            }
        }
        if ($entry === null || $slug === '') {
            return Response::json([
                'ok'      => false,
                'error'   => 'skill_not_found',
                'message' => 'No consensus skill with that slug.',
            ], 404);
        }

        $definition = [];
        if (($entry['source'] ?? 'bundled') === 'user' && $this->skills !== null) {
            foreach ($this->skills->all() as $skill) {
                if ((string)$skill->slug === $slug) {
                    $definition = is_array($skill->definition) ? $skill->definition : [];
                    break;
                }
            }
        } else {
            $definition = $this->readBundledDefinition((string)($entry['file'] ?? ''));
            // A system skill kept in the database (the four domain skills have
            // no catalogue file) has no file to read: fall back to its row so
            // the document shows its roles and sources.
            if ($definition === [] && $this->skills !== null) {
                foreach ($this->skills->all() as $skill) {
                    if ((string)$skill->slug === $slug) {
                        $definition = is_array($skill->definition) ? $skill->definition : [];
                        break;
                    }
                }
            }
        }

        $markdown = SkillDoc::markdown([
            'slug'           => (string)$entry['slug'],
            'name'           => (string)($entry['name'] ?? $entry['slug']),
            'description'    => (string)($entry['description'] ?? ''),
            'source'         => (string)($entry['source'] ?? 'bundled'),
            'scoring'        => (string)($entry['consensus_scoring'] ?? ConsensusSkillCatalog::DEFAULT_SCORING),
            'preset'         => is_array($entry['preset'] ?? null) ? $entry['preset'] : null,
            'models'         => is_array($entry['models'] ?? null) ? $entry['models'] : [],
            'uses_user_pool' => ($entry['uses_user_pool'] ?? false) === true,
            'auto_selector'  => ($definition['auto_selector'] ?? false) === true,
            'stages'         => is_array($definition['stages'] ?? null) ? $definition['stages'] : [],
            'roles'          => is_array($definition['roles'] ?? null) ? $definition['roles'] : [],
            'sources'        => is_array($definition['sources'] ?? null) ? $definition['sources'] : [],
        ]);

        $result = $this->share->publish(
            'Skill description ' . (string)($entry['name'] ?? $entry['slug']),
            $markdown,
        );
        $http = (int)($result['http'] ?? 500);
        unset($result['http']);
        return Response::json($result, $http);
    }

    /**
     * The bundled definition JSON behind a catalogue entry. The catalogue
     * emits plain basenames; the guards stay anyway because this path was
     * handed to us by a URL.
     *
     * @return array<string,mixed>
     */
    private function readBundledDefinition(string $file): array
    {
        $file = basename($file);
        if ($file === '' || str_contains($file, '/') || str_contains($file, '\\')) {
            return [];
        }
        $path = rtrim($this->rootDir, '/\\') . '/src/Skills/definitions/' . $file . '.json';
        if (!is_file($path)) {
            return [];
        }
        $decoded = JsonHelper::tryDecode((string)file_get_contents($path));
        return is_array($decoded) ? $decoded : [];
    }
}
