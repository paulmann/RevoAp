<?php
/**
 * SkillDraftsController: draft a consensus skill, review it, keep it.
 *
 * A skill is the role set and the system prompts a council runs with. Until now
 * the only way one appeared was SkillAutoCreator inside a run: the pipeline
 * found no matching skill, drafted one through the synthesis model and saved it
 * silently. That is the right behaviour for a fallback nobody has time to
 * approve, but it leaves the operator out of the one decision that shapes a
 * council's behaviour - what its roles are.
 *
 *   POST /api/skills/draft          {"query": "..."} -> a draft, nothing saved
 *   POST /api/skills/draft/confirm  {"definition": {...}} -> the skill is kept
 *
 * The draft endpoint never writes. The confirm endpoint validates the same
 * shape a run would require, refuses a definition that would not survive one,
 * and returns an existing skill rather than overwriting it - a system skill
 * must never be replaced by a draft that happens to share its slug.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\RuntimeConfig;
use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Skills\SkillAutoCreator;
use App\Skills\SkillRepository;

final class SkillDraftsController
{
    /** Longest query accepted for a draft, in characters. */
    private const MAX_QUERY_CHARS = 2000;

    public function __construct(
        private readonly SkillAutoCreator $creator,
        private readonly ?SkillRepository $skills = null,
    ) {}

    /** POST /api/skills/draft */
    public function draft(): string
    {
        $payload = $this->readJsonBody();
        $query = isset($payload['query']) && is_string($payload['query']) ? trim($payload['query']) : '';
        if ($query === '') {
            return Response::json([
                'ok'      => false,
                'error'   => 'query_required',
                'message' => 'Describe the task the skill should handle.',
            ], 400);
        }
        if (mb_strlen($query) > self::MAX_QUERY_CHARS) {
            $query = mb_substr($query, 0, self::MAX_QUERY_CHARS);
        }

        $draft = $this->creator->draft($query);
        return Response::json([
            'ok'         => true,
            'source'     => $draft['source'],
            'model'      => $draft['model'],
            'definition' => $draft['definition'],
            'saved'      => false,
        ]);
    }

    /** POST /api/skills/draft/confirm */
    public function confirm(): string
    {
        $payload = $this->readJsonBody();
        $definition = $payload['definition'] ?? null;
        if (!is_array($definition)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'definition_required',
                'message' => 'Send the reviewed definition back in "definition".',
            ], 400);
        }
        if (!$this->creator->isValidDraft($definition)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'definition_invalid',
                'message' => 'A definition needs a name and at least one role carrying a key and a system prompt.',
            ], 400);
        }

        try {
            $skill = $this->creator->persist($definition);
        } catch (\InvalidArgumentException $e) {
            return Response::json([
                'ok'      => false,
                'error'   => 'definition_invalid',
                'message' => $e->getMessage(),
            ], 400);
        }

        return Response::json([
            'ok'      => true,
            'saved'   => true,
            'skill'   => [
                'slug'  => $skill->slug,
                'name'  => $skill->name,
                'roles' => count($skill->roles()),
            ],
        ]);
    }

    /**
     * GET /api/skills/generator
     *
     * The model that drafts roles, and where its value comes from. Mirrors the
     * actualize section's settings shape on purpose: the interface renders both
     * with the same picker and the same sentence.
     */
    public function generatorSettings(): string
    {
        $provider = trim((string)Config::get('skills.generator.provider', ''));
        $model    = trim((string)Config::get('skills.generator.model', ''));
        [$effectiveProvider, $effectiveModel] = $this->creator->generatorTarget();
        $own = $provider !== '' && $model !== '';

        return Response::json([
            'ok'            => true,
            'provider'      => $provider,
            'model'         => $model,
            'source'        => $own ? 'section' : 'inherited',
            'source_key'    => 'skills.generator.provider / skills.generator.model',
            'inherited_key' => 'pipeline.synthesis_provider / pipeline.synthesis_model',
            'inherited'     => $own ? null : ['provider' => $effectiveProvider, 'model' => $effectiveModel],
            'effective'     => ['provider' => $effectiveProvider, 'model' => $effectiveModel],
        ]);
    }

    /**
     * POST /api/skills/generator
     *
     * Body `{"provider": "...", "model": "..."}` to choose, `{"reset": true}`
     * to inherit again. The model must be one the active catalogue serves: a
     * generator that cannot be called would fail inside a draft the operator
     * just asked for, which is the worst moment to discover it.
     */
    public function saveGenerator(): string
    {
        $payload = $this->readJsonBody();

        if (($payload['reset'] ?? false) === true) {
            RuntimeConfig::update([
                'skills.generator.provider' => '',
                'skills.generator.model'    => '',
            ]);
            return Response::json([
                'ok'      => true,
                'message' => 'The role generator inherits the synthesis model again.',
            ]);
        }

        $provider = isset($payload['provider']) && is_string($payload['provider']) ? trim($payload['provider']) : '';
        $model    = isset($payload['model']) && is_string($payload['model']) ? trim($payload['model']) : '';
        if ($provider === '' || $model === '') {
            return Response::json([
                'ok'      => false,
                'error'   => 'provider_and_model_required',
                'message' => 'Send {"provider": "...", "model": "..."} or {"reset": true}.',
            ], 400);
        }
        if (str_contains($model, ':batch')) {
            return Response::json([
                'ok'      => false,
                'error'   => 'model_not_synchronous',
                'message' => 'A batch-only model cannot draft roles: the call has to answer while the operator waits.',
            ], 400);
        }
        $known = false;
        foreach (Config::getActiveModels() as $row) {
            if (is_array($row)
                && (string)($row['provider'] ?? '') === $provider
                && (string)($row['model'] ?? '') === $model) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return Response::json([
                'ok'      => false,
                'error'   => 'model_not_in_catalogue',
                'message' => 'The active catalogue does not serve ' . $provider . ' / ' . $model . '.',
            ], 400);
        }

        RuntimeConfig::update([
            'skills.generator.provider' => mb_substr($provider, 0, 64),
            'skills.generator.model'    => mb_substr($model, 0, 191),
        ]);

        return Response::json([
            'ok'      => true,
            'message' => 'The role generator uses ' . $provider . ' / ' . $model . '.',
        ]);
    }
    /**
     * GET /api/skills/selector
     *
     * The Automatic Skill Selector's settings: the decision model (its own,
     * or the role generator's by inheritance), how many arXiv papers a new
     * skill studies, whether building new skills is allowed, and the
     * fallback skill that takes over when it is not.
     */
    public function selectorSettings(): string
    {
        $provider = trim((string)Config::get('skills.selector.decision.provider', ''));
        $model    = trim((string)Config::get('skills.selector.decision.model', ''));
        $own      = $provider !== '' && $model !== '';
        if ($own) {
            $effective = ['provider' => $provider, 'model' => $model];
        } else {
            $gProvider = trim((string)Config::get('skills.generator.provider', ''));
            $gModel    = trim((string)Config::get('skills.generator.model', ''));
            $effective = $gProvider !== '' && $gModel !== ''
                ? ['provider' => $gProvider, 'model' => $gModel]
                : [
                    'provider' => (string)Config::get('pipeline.synthesis_provider', 'bothub'),
                    'model'    => (string)Config::get('pipeline.synthesis_model', 'gpt-6-luna-pro'),
                ];
        }
        $fallback = trim((string)Config::get('skills.selector.fallback_skill', ''));
        return Response::json([
            'ok' => true,
            'decision' => [
                'provider'  => $provider,
                'model'     => $model,
                'source'    => $own ? 'section' : 'inherited',
                'effective' => $effective,
            ],
            'max_papers'      => max(1, min(10, (int)Config::get('skills.selector.max_papers', 5))),
            'auto_create'     => (bool)Config::get('skills.selector.auto_create', true),
            'fallback_skill'  => $fallback,
            'fallback_exists' => $fallback !== '' && $this->skills !== null && $this->skills->findBySlug($fallback) !== null,
            'keys' => [
                'decision' => 'skills.selector.decision.provider / skills.selector.decision.model',
                'papers'   => 'skills.selector.max_papers',
                'create'   => 'skills.selector.auto_create',
                'fallback' => 'skills.selector.fallback_skill',
            ],
        ]);
    }

    /**
     * POST /api/skills/selector
     *
     * Any subset of the settings may be sent. `decision` accepts
     * {"provider","model"} to choose the deciding model or "inherit" to
     * hand the choice back to the role generator.
     */
    public function saveSelector(): string
    {
        $payload = $this->readJsonBody();
        $update  = [];
        $message = [];

        if (array_key_exists('decision', $payload)) {
            $decision = $payload['decision'];
            if ($decision === 'inherit' || $decision === null) {
                $update['skills.selector.decision.provider'] = '';
                $update['skills.selector.decision.model']    = '';
                $message[] = 'the decision model inherits the role generator again';
            } elseif (is_array($decision)) {
                $provider = isset($decision['provider']) && is_string($decision['provider']) ? trim($decision['provider']) : '';
                $model    = isset($decision['model']) && is_string($decision['model']) ? trim($decision['model']) : '';
                if ($provider === '' || $model === '') {
                    return Response::json([
                        'ok'      => false,
                        'error'   => 'provider_and_model_required',
                        'message' => 'Send {"decision": {"provider": "...", "model": "..."}} or {"decision": "inherit"}.',
                    ], 400);
                }
                if (str_contains($model, ':batch')) {
                    return Response::json([
                        'ok'      => false,
                        'error'   => 'model_not_synchronous',
                        'message' => 'A batch-only model cannot make the decision: the answer has to arrive while the run waits.',
                    ], 400);
                }
                $known = false;
                foreach (Config::getActiveModels() as $row) {
                    if (is_array($row)
                        && (string)($row['provider'] ?? '') === $provider
                        && (string)($row['model'] ?? '') === $model) {
                        $known = true;
                        break;
                    }
                }
                if (!$known) {
                    return Response::json([
                        'ok'      => false,
                        'error'   => 'model_not_in_catalogue',
                        'message' => 'The active catalogue does not serve ' . $provider . ' / ' . $model . '.',
                    ], 400);
                }
                $update['skills.selector.decision.provider'] = mb_substr($provider, 0, 64);
                $update['skills.selector.decision.model']    = mb_substr($model, 0, 191);
                $message[] = 'the decision model is set';
            } else {
                return Response::json([
                    'ok'      => false,
                    'error'   => 'decision_invalid',
                    'message' => 'decision must be an object or the string "inherit".',
                ], 400);
            }
        }

        if (array_key_exists('max_papers', $payload)) {
            $max = (int)$payload['max_papers'];
            if ($max < 1 || $max > 10) {
                return Response::json([
                    'ok'      => false,
                    'error'   => 'max_papers_out_of_range',
                    'message' => 'max_papers must be between 1 and 10.',
                ], 400);
            }
            $update['skills.selector.max_papers'] = $max;
            $message[] = 'papers per research: ' . $max;
        }

        if (array_key_exists('auto_create', $payload)) {
            $update['skills.selector.auto_create'] = (bool)$payload['auto_create'];
            $message[] = 'building new skills is ' . ((bool)$payload['auto_create'] ? 'on' : 'off');
        }

        if (array_key_exists('fallback_skill', $payload)) {
            $slug = is_string($payload['fallback_skill']) ? trim($payload['fallback_skill']) : '';
            if ($slug !== '') {
                if (preg_match('/^[a-z0-9][a-z0-9\-_]{0,63}$/i', $slug) !== 1) {
                    return Response::json([
                        'ok'      => false,
                        'error'   => 'fallback_skill_invalid',
                        'message' => 'A fallback skill slug is letters, digits, dashes and underscores.',
                    ], 400);
                }
                if ($this->skills !== null && $this->skills->findBySlug($slug) === null) {
                    return Response::json([
                        'ok'      => false,
                        'error'   => 'fallback_skill_unknown',
                        'message' => 'No skill with slug "' . $slug . '" exists.',
                    ], 400);
                }
            }
            $update['skills.selector.fallback_skill'] = mb_substr($slug, 0, 64);
            $message[] = $slug === '' ? 'fallback skill cleared' : 'fallback skill: ' . $slug;
        }

        if ($update === []) {
            return Response::json([
                'ok'      => false,
                'error'   => 'nothing_to_save',
                'message' => 'Send decision, max_papers, auto_create or fallback_skill.',
            ], 400);
        }
        RuntimeConfig::update($update);
        return Response::json([
            'ok'      => true,
            'message' => 'Saved - ' . implode('; ', $message) . '.',
        ]);
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return $_POST;
        }
        $parsed = JsonHelper::tryDecode($raw);
        return is_array($parsed) ? $parsed : [];
    }
}