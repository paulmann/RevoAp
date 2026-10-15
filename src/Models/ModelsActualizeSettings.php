<?php
/**
 * ModelsActualizeSettings: the Actualize section's own settings.
 *
 * One setting so far — which model performs the assignment calls of a
 * run (stage `assigning_presets`). It deliberately lives in its own key
 * rather than reusing `analyzer.provider` / `analyzer.model`, because the
 * two roles are not the same job:
 *
 *   * `analyzer.*` classifies an incoming *query* — Request Analyzer, the
 *     L0 router the whole pipeline depends on;
 *   * the Actualize section asks a model to *pick a consensus pool from a
 *     live catalogue* — a different prompt, a different failure mode, and
 *     an operator may well want a stronger (or cheaper) model for it.
 *
 * Letting the Actualize card write `analyzer.*` would silently re-point
 * the classifier. So the section gets its own key and *inherits* when it
 * is not set: `models.actualize.analyzer` unset is not "use some default",
 * it is "the value in effect is the analyzer's, and the UI says so".
 *
 * Storage: the `app_config` table under `models.actualize.analyzer`, next
 * to `models.actualize.status` and `models.actualize.snapshot.latest`
 * ({@see ModelsSnapshotStore}), which is where app_config-backed UI
 * preferences in this namespace already live. It is *not* a runtime.json
 * key: `models[]` there is the catalogue list, so `models.actualize.…`
 * would collide with it.
 *
 * When `app_config` is unavailable every read degrades to the inherited
 * value and every write reports false — an actualization run must keep
 * working on a host mid-migration, and the analyst of "which model is in
 * effect" must stay answerable.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Models;

use App\Analyzer\RequestAnalyzer;
use App\Core\Config;
use App\Core\Logger;
use App\Settings\ConfigRepository;

final class ModelsActualizeSettings
{
    /** The section's analyzer override: `{provider, model}`. */
    public const KEY_ANALYZER = 'models.actualize.analyzer';

    /** Namespace for the key, matching {@see ModelsSnapshotStore}. */
    public const SCOPE = 'models';

    /** The value came from this section's own key. */
    public const SOURCE_SECTION = 'section';

    /** The value came from `analyzer.provider` / `analyzer.model`. */
    public const SOURCE_INHERITED = 'inherited';

    public function __construct(private readonly ?ConfigRepository $config = null) {}

    /**
     * Is the section's setting readable and writable at all?
     *
     * Same contract as {@see ModelsSnapshotStore::available()}: it creates
     * the table when it is missing (`CREATE TABLE IF NOT EXISTS`, a no-op
     * on any host that imported db/schema.sql) and reports false rather
     * than throwing. Only the admin-gated save path calls it.
     */
    public function available(): bool
    {
        if ($this->config === null) {
            return false;
        }
        try {
            $this->config->ensureTable();
            return $this->config->tableExists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * What the section's key holds, or null when it is unset.
     *
     * @return array{provider:string,model:string}|null
     */
    public function section(): ?array
    {
        $raw = $this->read();
        if (!is_array($raw)) {
            return null;
        }
        $provider = strtolower(trim((string)($raw['provider'] ?? '')));
        $model    = trim((string)($raw['model'] ?? ''));
        if ($provider === '' || $model === '') {
            return null;
        }
        return ['provider' => $provider, 'model' => $model];
    }

    /**
     * What `analyzer.*` says — the value that applies when this section
     * has not been given one.
     *
     * @return array{provider:string,model:string}
     */
    public function inherited(): array
    {
        return [
            'provider' => strtolower(trim((string)Config::get('analyzer.provider', RequestAnalyzer::DEFAULT_PROVIDER))),
            'model'    => trim((string)Config::get('analyzer.model', RequestAnalyzer::DEFAULT_MODEL)),
        ];
    }

    /**
     * The model an Actualize run will actually call, and where that value
     * came from.
     *
     * A stored value that cannot be called synchronously is *refused here*
     * rather than at the connector (`{@see ModelId::syncCallRejection()}`):
     * a `:batch` id is served by the asynchronous Batch API, the assignment
     * call is synchronous, and continuing with it would fail the whole run
     * on a setting the operator cannot connect to any error message. The
     * refusal is not silent — `note` carries the reason and the UI prints
     * it — and the run falls back to the inherited analyzer, which is the
     * pre-A2 behaviour, so a bad stored value can never make the section
     * unusable.
     *
     * @return array{provider:string,model:string,source:string,note:?string}
     */
    public function resolve(): array
    {
        $section = $this->section();
        if ($section !== null) {
            $rejection = ModelId::syncCallRejection($section['model']);
            if ($rejection === null) {
                return [
                    'provider' => $section['provider'],
                    'model'    => $section['model'],
                    'source'   => self::SOURCE_SECTION,
                    'note'     => null,
                ];
            }
            Logger::warn('models.actualize.analyzer_override_refused', [
                'provider' => $section['provider'],
                'model'    => $section['model'],
            ]);
            return [
                ...$this->inherited(),
                'source' => self::SOURCE_INHERITED,
                'note'   => 'The model set for the Actualize section was ignored: ' . $rejection,
            ];
        }
        return [...$this->inherited(), 'source' => self::SOURCE_INHERITED, 'note' => null];
    }

    /**
     * Set the section's model. Returns false when it could not be stored,
     * so the caller can report that instead of claiming a save.
     *
     * The batch-only rule is enforced on the way in as well as on the way
     * out: storing an id the section can never use would make the save
     * look successful and the setting look broken.
     *
     * @throws \InvalidArgumentException when the pair is unusable
     */
    public function save(string $provider, string $model): bool
    {
        $provider = strtolower(trim($provider));
        $model    = trim($model);
        if ($provider === '' || $model === '') {
            throw new \InvalidArgumentException('Both a provider and a model are required.');
        }
        $rejection = ModelId::syncCallRejection($model);
        if ($rejection !== null) {
            throw new \InvalidArgumentException($rejection);
        }
        if ($this->config === null) {
            return false;
        }
        $encoded = json_encode(
            ['provider' => $provider, 'model' => $model],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (!is_string($encoded)) {
            // Only invalid UTF-8 gets here, and storing `{}` instead would
            // report a save that discarded the value.
            return false;
        }
        try {
            $this->config->set(self::KEY_ANALYZER, $encoded, self::SCOPE);
            return true;
        } catch (\Throwable $e) {
            Logger::warn('models.actualize.settings_not_stored', [
                'key'   => self::KEY_ANALYZER,
                'error' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return false;
        }
    }

    /**
     * Drop the section's override, so `analyzer.*` applies again.
     *
     * Deleting the row is the only honest way to express "unset": writing
     * today's inherited value instead would freeze it, and the section
     * would stop following the analyzer the moment the operator changes it
     * — which is the opposite of what "not set" means here.
     */
    public function clear(): bool
    {
        if ($this->config === null) {
            return false;
        }
        try {
            $this->config->delete(self::KEY_ANALYZER);
            return true;
        } catch (\Throwable $e) {
            Logger::warn('models.actualize.settings_not_cleared', [
                'key'   => self::KEY_ANALYZER,
                'error' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private function read(): ?array
    {
        if ($this->config === null) {
            return null;
        }
        try {
            $raw = $this->config->get(self::KEY_ANALYZER);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
