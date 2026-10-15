<?php
/**
 * DomainPresetsController: HTTP layer for Settings → Domain Models.
 *
 * Endpoints:
 *   GET  /api/domain-presets            — list every preset (with selection state)
 *   GET  /api/domain-presets/{slug}     — one preset by slug
 *   POST /api/domain-presets            — upsert a preset (admin-gated)
 *   POST /api/domain-presets/{slug}/select — replace selection inside a preset
 *
 * Authentication mirrors Settings.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\AdminAuth;
use App\Core\Config;
use App\DomainPresets\DomainPreset;
use App\DomainPresets\DomainPresetRepository;
use App\DomainPresets\DomainPresetTransfer;
use App\Helpers\JsonHelper;
use App\Core\Logger;
use App\Http\Response;

final class DomainPresetsController
{
    public function __construct(
        private readonly DomainPresetRepository $repo,
    ) {}

    public function index(): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        $presets = array_map(static fn (DomainPreset $p): array => $p->toArray(), $this->repo->all());
        return Response::json([
            'presets'   => $presets,
            'persisted' => $this->repo->tableAvailable(),
            // v1.8.41: toolbar data — providers that currently resolve an
            // API key, plus the full per-provider model catalog so the
            // "Apply" button can spread picks across a provider without an
            // extra round-trip. Backwards-compatible additions.
            'providers' => $this->providersWithKeys(),
            'catalog'   => $this->modelCatalog(),
        ]);
    }

    /** @param array<string,string> $params */
    public function show(array $params): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        $slug = (string)($params['slug'] ?? '');
        $preset = $this->repo->findBySlug($slug);
        if ($preset === null) {
            return Response::json(['error' => 'not_found', 'slug' => $slug], 404);
        }
        return Response::json(['preset' => $preset->toArray()]);
    }

    /**
     * GET /api/domain-presets/export
     *
     * The whole preset set as a document the operator can keep. Registered
     * before /api/domain-presets/{slug} so the literal path wins over the
     * placeholder.
     */
    public function export(): string
    {
        $presets = $this->repo->all();
        return Response::json(DomainPresetTransfer::document($presets, gmdate('Y-m-d\TH:i:s\Z')));
    }

    /**
     * POST /api/domain-presets/import
     *
     * Body: the exported document, plus `"apply": true` to write it.
     * Without `apply` this is a dry run that reports exactly what would change
     * (added and removed models per preset, unknown models, skipped presets).
     * Only presets that already exist are touched; a model the active catalogue
     * does not serve is reported, never written.
     */
    public function import(): string
    {
        $raw = (string)file_get_contents('php://input');
        $payload = JsonHelper::tryDecode($raw);
        if (!is_array($payload)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_document',
                'message' => 'The body must be the JSON document the export produced.',
            ], 400);
        }

        $existing = [];
        foreach ($this->repo->all() as $preset) {
            $existing[$preset->slug] = $preset;
        }
        $planned = DomainPresetTransfer::plan($payload, $existing, \App\Core\Config::getActiveModels());
        if (($planned['ok'] ?? false) !== true) {
            return Response::json([
                'ok'      => false,
                'error'   => (string)($planned['error'] ?? 'invalid_document'),
                'message' => (string)($planned['message'] ?? 'The document could not be read.'),
            ], 400);
        }

        $apply   = ($payload['apply'] ?? false) === true;
        $written = [];
        $failed  = [];
        if ($apply) {
            foreach ($planned['plan'] as $entry) {
                if (($entry['action'] ?? '') !== 'update') {
                    continue;
                }
                $slug = (string)$entry['slug'];
                try {
                    $this->repo->save(new DomainPreset(
                        id:          $existing[$slug]->id,
                        slug:        $slug,
                        name:        (string)($entry['name'] ?? $existing[$slug]->name),
                        description: $existing[$slug]->description,
                        models:      $entry['models'],
                        arbiter:     is_array($entry['arbiter']) ? $entry['arbiter'] : null,
                        skillSlug:   is_string($entry['skill_slug']) ? $entry['skill_slug'] : null,
                        isSystem:    $existing[$slug]->isSystem,
                    ));
                    $written[] = $slug;
                } catch (\Throwable $e) {
                    $failed[$slug] = Logger::sanitizeScalar($e->getMessage());
                }
            }
        }

        return Response::json([
            'ok'      => $failed === [],
            'applied' => $apply,
            'written' => $written,
            'failed'  => $failed,
            'plan'    => $planned['plan'],
        ], $failed === [] ? 200 : 500);
    }
    public function save(): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        $body = $this->readJsonBody();
        $slug = strtolower(trim((string)($body['slug'] ?? '')));
        $name = trim((string)($body['name'] ?? ''));
        if ($slug === '' || $name === '') {
            return Response::json(['error' => 'invalid', 'message' => 'slug and name required'], 400);
        }
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{1,62}$/', $slug) !== 1) {
            return Response::json(['error' => 'invalid_slug'], 400);
        }
        $description = trim((string)($body['description'] ?? ''));
        $models = $this->coerceModels($body['models'] ?? []);
        $arbiter = $this->coerceArbiter($body['arbiter'] ?? null);
        $skillSlug = isset($body['skill_slug']) && is_string($body['skill_slug'])
            ? trim($body['skill_slug']) : null;
        $isSystem = (bool)($body['is_system'] ?? false);
        $preset = new DomainPreset(
            id: null,
            slug: $slug,
            name: mb_substr($name, 0, 128),
            description: mb_substr($description, 0, 8192),
            models: $models,
            arbiter: $arbiter,
            skillSlug: $skillSlug === '' ? null : $skillSlug,
            isSystem: $isSystem,
        );
        $saved = $this->repo->save($preset);
        return Response::json([
            'ok'     => true,
            'preset' => $saved->toArray(),
        ] + self::persistence($this->repo));
    }

    /** @param array<string,string> $params */
    public function select(array $params): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        $slug = (string)($params['slug'] ?? '');
        $preset = $this->repo->findBySlug($slug);
        if ($preset === null) {
            return Response::json(['error' => 'not_found', 'slug' => $slug], 404);
        }
        $body = $this->readJsonBody();
        $models = $this->coerceModels($body['models'] ?? $preset->models);
        $arbiter = array_key_exists('arbiter', $body) ? $this->coerceArbiter($body['arbiter']) : $preset->arbiter;
        $updated = new DomainPreset(
            id: $preset->id,
            slug: $preset->slug,
            name: $preset->name,
            description: $preset->description,
            models: $models,
            arbiter: $arbiter,
            skillSlug: $preset->skillSlug,
            isSystem: $preset->isSystem,
        );
        $saved = $this->repo->save($updated);
        return Response::json([
            'ok'     => true,
            'preset' => $saved->toArray(),
        ] + self::persistence($this->repo));
    }

    /**
     * v1.8.50 — say out loud whether a save survived the request.
     *
     * {@see DomainPresetRepository::save()} falls back to an in-memory store
     * when `domain_presets` is missing, and returns the saved preset either
     * way. The Models tab therefore printed "Saved." for a preset that
     * disappeared on the next page load. The extra keys are additive (the
     * existing `ok` / `preset` contract is untouched) and let the button
     * report "saved in memory only" instead of a claim it cannot support.
     *
     * @return array{persisted:bool,warning:?string}
     */
    private static function persistence(DomainPresetRepository $repo): array
    {
        $persisted = $repo->tableAvailable();
        return [
            'persisted' => $persisted,
            'warning'   => $persisted
                ? null
                : 'Stored in memory only: the domain_presets table is not available, so this change '
                    . 'disappears when the request ends. Import db/schema.sql (Settings → Database → '
                    . 'ReCreate DB) to persist presets.',
        ];
    }

    /** @param mixed $raw @return array<int,array{provider:string,model:string,label?:string,active?:bool,selected?:bool}> */
    private function coerceModels(mixed $raw): array
    {
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) { continue; }
            $p = trim((string)($m['provider'] ?? ''));
            $id = trim((string)($m['model'] ?? ''));
            if ($p === '' || $id === '') { continue; }
            $row = [
                'provider' => mb_substr($p, 0, 64),
                'model'    => mb_substr($id, 0, 191),
                'active'   => array_key_exists('active', $m) ? (bool)$m['active'] : true,
                'selected' => array_key_exists('selected', $m) ? (bool)$m['selected'] : true,
            ];
            if (isset($m['label']) && is_string($m['label'])) {
                $row['label'] = mb_substr(trim($m['label']), 0, 128);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array{provider:string,model:string,role?:string}|null */
    private function coerceArbiter(mixed $raw): ?array
    {
        if (!is_array($raw)) { return null; }
        $p = trim((string)($raw['provider'] ?? ''));
        $id = trim((string)($raw['model'] ?? ''));
        if ($p === '' || $id === '') { return null; }
        $row = [
            'provider' => mb_substr($p, 0, 64),
            'model'    => mb_substr($id, 0, 191),
        ];
        if (isset($raw['role']) && is_string($raw['role'])) {
            $row['role'] = mb_substr(trim($raw['role']), 0, 32);
        }
        return $row;
    }

    /**
     * Providers that currently resolve an API key, for the Domain Models
     * "Apply" toolbar. `has_key` is always true for returned entries — a
     * provider only appears here when Config::getConnectorKey() succeeds.
     *
     * @return array<int,array{provider:string,has_key:bool,active:bool}>
     */
    private function providersWithKeys(): array
    {
        $out = [];
        foreach (['openrouter', 'bothub', 'openai', 'anthropic', 'gemini', 'deepseek', 'xai'] as $provider) {
            try {
                $key = Config::getConnectorKey($provider);
            } catch (\Throwable) {
                continue;
            }
            if ($key === '') { continue; }
            $out[] = [
                'provider' => $provider,
                'has_key'  => true,
                'active'   => (bool)Config::get("connectors.$provider.active", false),
            ];
        }
        return $out;
    }

    /**
     * Full model catalog grouped by provider, for the Domain Models
     * "Apply" toolbar. Every row is included regardless of its `active`
     * flag so Apply can spread picks across the whole provider catalog;
     * each provider's list is sorted active-first while preserving the
     * original config order within each group.
     *
     * @return array<string,array<int,array{model:string,label:string,active:bool}>>
     */
    private function modelCatalog(): array
    {
        $byProvider = [];
        foreach (Config::get('models', []) as $m) {
            if (!is_array($m)) { continue; }
            $provider = (string)($m['provider'] ?? '');
            $model    = (string)($m['model'] ?? '');
            if ($provider === '' || $model === '') { continue; }
            $label = (string)($m['label'] ?? '');
            if ($label === '') { $label = self::prettifyModelId($model); }
            $byProvider[$provider][] = [
                'model'  => $model,
                'label'  => $label,
                'active' => (bool)($m['active'] ?? false),
            ];
        }
        foreach ($byProvider as &$rows) {
            $active   = [];
            $inactive = [];
            foreach ($rows as $row) {
                if ($row['active']) {
                    $active[] = $row;
                } else {
                    $inactive[] = $row;
                }
            }
            $rows = array_merge($active, $inactive);
        }
        unset($rows);
        ksort($byProvider);
        return $byProvider;
    }

    /**
     * Prettify a model id into a human-readable label: strip the vendor
     * prefix (everything up to and including the last "/"), collapse
     * non-alphanumeric separators into spaces, then uppercase the first
     * letter of each word while keeping digits as-is.
     * e.g. "deepseek-r1" -> "DeepSeek R1", "gpt-5.5-pro" -> "GPT 5.5 Pro",
     * "openai/gpt-4o" -> "GPT 4o".
     */
    private static function prettifyModelId(string $model): string
    {
        $model = ltrim(trim($model), '~');
        if (str_contains($model, '/')) {
            $model = substr($model, strrpos($model, '/') + 1);
        }
        $words = preg_split('/[^a-zA-Z0-9]+/', $model) ?: [];
        $out   = '';
        foreach ($words as $word) {
            if ($word === '') { continue; }
            $out .= ' ' . ucfirst($word);
        }
        return trim($out);
    }

    private function guard(): ?string
    {
        if (!Config::isSettingsTabEnabled()) {
            return Response::json(['error' => 'settings_disabled'], 404);
        }
        // v1.8.60 — the decision is taken once, in AdminAuth, so every guard
        // on this surface obeys `Protect_Settings_by_Admin_Key`: with the flag
        // at 0 the gate is open (no key configured, no key presented, nothing
        // to challenge), while at 1 — or absent — the historical 503 / 401
        // pair is returned unchanged.
        $gate = AdminAuth::gateState();
        if (!$gate['allowed']) {
            if (!$gate['configured']) {
                return Response::json(['error' => 'admin_not_configured'], 503);
            }
            return Response::json(['error' => 'unauthorized'], 401);
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') { return []; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
