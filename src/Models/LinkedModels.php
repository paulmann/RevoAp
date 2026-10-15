<?php
/**
 * LinkedModels: keep the three config-bound models in step with the mirror.
 *
 * An actualization already rewrites the persisted catalogue, every
 * consensus preset and the Chat pool. Three more models are bound to the
 * same providers but live outside those structures, in flat config keys:
 *
 *   `analyzer.provider` / `analyzer.model`
 *        the request analyzer — the model that classifies a query and,
 *        during this very run, assigns the preset pools;
 *   `pipeline.synthesis_provider` / `pipeline.synthesis_model`
 *        the L4 synthesis model;
 *   `pipeline.conflict_arbiter.provider` / `pipeline.conflict_arbiter.model`
 *        the L3 conflict arbiter.
 *
 * Without this class a provider that ships a new release would move the
 * presets and the pool onto it while the analyzer, the synthesis step and
 * the arbiter kept calling the previous one — a model that may already be
 * retired, or worse, silently answering from something the operator never
 * chose.
 *
 * The rules are the ones {@see ModelVersion} already enforces for the pool,
 * and they are deliberately conservative:
 *
 *   * only the newest release *on the same line* replaces a model:
 *     `gpt-5.5` → `gpt-5.6` yes, `gpt-5.5` → `gpt-5.5-pro` no (that is a
 *     different model), `deepseek-r1` → `deepseek-r2` no (a release alias
 *     is its own line);
 *   * the replacement must exist in the *live* catalogue — a model the
 *     provider does not actually serve is never written;
 *   * a provider that answered with an empty list is treated as
 *     unavailable (an outage is not evidence of anything) and nothing
 *     about its models is touched;
 *   * a linked model is never moved to a different provider on its own.
 *     The provider key is rewritten only alongside a model that has
 *     disappeared from its own provider's live list (see {@see self::plan()}
 *     for the exact condition), so the effective provider never changes
 *     behind the operator's back.
 *
 * Nothing here throws and nothing here decides: {@see self::plan()} only
 * describes what should change and why it did not, {@see self::apply()}
 * writes the plan, {@see self::restore()} puts a recorded state back. Every
 * decision that is *not* taken is reported as a warning, so the UI can say
 * "the analyzer is still on gpt-5.5 because its provider answered with an
 * empty list" instead of silently keeping a stale model.
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

use App\Core\Config;
use App\Core\RuntimeConfig;

final class LinkedModels
{
    /** The request analyzer (`analyzer.*`). */
    public const ROLE_ANALYZER = 'analyzer';

    /** L4 synthesis (`pipeline.synthesis_*`). */
    public const ROLE_SYNTHESIS = 'synthesis';

    /** L3 conflict arbiter (`pipeline.conflict_arbiter.*`). */
    public const ROLE_ARBITER = 'arbiter';

    /**
     * The three roles, in the order the UI lists them, each with the config
     * paths it owns.
     *
     * @var array<string,array{label:string,provider_path:string,model_path:string}>
     */
    private const ROLES = [
        self::ROLE_ANALYZER => [
            'label'         => 'Analyzer',
            'provider_path' => 'analyzer.provider',
            'model_path'    => 'analyzer.model',
        ],
        self::ROLE_SYNTHESIS => [
            'label'         => 'Synthesis',
            'provider_path' => 'pipeline.synthesis_provider',
            'model_path'    => 'pipeline.synthesis_model',
        ],
        self::ROLE_ARBITER => [
            'label'         => 'Arbiter',
            'provider_path' => 'pipeline.conflict_arbiter.provider',
            'model_path'    => 'pipeline.conflict_arbiter.model',
        ],
    ];

    /** Role keys, in the order the summary lists them. @return array<int,string> */
    public static function roles(): array
    {
        return array_keys(self::ROLES);
    }

    /**
     * The three roles as they are configured right now.
     *
     * `*_overridden` records whether the value comes from `runtime.json`
     * (an override) or from `config.php` (the shipped default). A rollback
     * needs that distinction: an override the run created has to be
     * *removed* to get back to the default, and writing the default as a
     * fresh override would pin it forever.
     *
     * @return array<string,array{role:string,label:string,provider:string,model:string,provider_overridden:bool,model_overridden:bool}>
     */
    public static function current(): array
    {
        $out = [];
        foreach (self::ROLES as $role => $spec) {
            $out[$role] = [
                'role'                => $role,
                'label'               => $spec['label'],
                'provider'            => self::value($spec['provider_path']),
                'model'               => self::value($spec['model_path']),
                'provider_overridden' => self::isOverridden($spec['provider_path']),
                'model_overridden'    => self::isOverridden($spec['model_path']),
            ];
        }
        return $out;
    }

    /**
     * Decide what should change, without changing anything.
     *
     * @param array<string,array<int,mixed>> $liveLists provider => raw live
     *        list, exactly as {@see ModelsActualizer::collectLiveLists()}
     *        returns it (only the providers that were actually queried)
     *
     * @return array{
     *     changes:array<int,array{role:string,label:string,provider:string,model:string,previous_provider:string,previous_model:string}>,
     *     warnings:array<int,string>,
     *     previous:array<string,array<string,mixed>>
     * }
     */
    public static function plan(array $liveLists): array
    {
        $changes = [];
        $warnings = [];
        foreach (self::ROLES as $role => $spec) {
            $label = $spec['label'];
            $provider = self::value($spec['provider_path']);
            $model = self::value($spec['model_path']);

            if ($model === '') {
                $warnings[] = sprintf('%s model is not configured; left as is.', $label);
                continue;
            }
            if ($provider === '') {
                $warnings[] = sprintf(
                    '%s model %s has no provider configured; left as is.',
                    $label,
                    $model,
                );
                continue;
            }
            // Connector names are lower-cased everywhere else (catalogue rows,
            // the live list, the pool), so the lookup is too — the configured
            // spelling is what gets reported.
            $providerKey = strtolower($provider);
            if (!array_key_exists($providerKey, $liveLists)) {
                $warnings[] = sprintf(
                    '%s model %s was left as is: provider "%s" is not an active connector.',
                    $label,
                    $model,
                    $provider,
                );
                continue;
            }

            $candidates = array_map(
                static fn(array $row): string => $row['model'],
                ModelCatalogDiff::normalise(
                    is_array($liveLists[$providerKey]) ? $liveLists[$providerKey] : [],
                    $providerKey,
                ),
            );
            // Batch-only ids are dropped before the version rule runs. They
            // share a line with their synchronous twin (after the `:batch`
            // suffix is stripped — see ModelId), so they WOULD be eligible
            // as "the newest release on the line", and moving the analyzer,
            // the synthesizer or the arbiter onto one would fail every call
            // they make. They are not evidence of an outage, so they are
            // simply not candidates.
            $batchOnly = array_values(array_filter(
                $candidates,
                static fn(string $id): bool => ModelId::isBatchId($id),
            ));
            $candidates = array_values(array_diff($candidates, $batchOnly));
            if ($batchOnly !== []) {
                $warnings[] = sprintf(
                    '%d batch-only identifier(s) served by "%s" were skipped for %s (they cannot be called '
                        . 'synchronously): %s.',
                    count($batchOnly),
                    $provider,
                    $label,
                    implode(', ', array_slice($batchOnly, 0, 5)) . (count($batchOnly) > 5 ? ', …' : ''),
                );
            }
            if (ModelId::isBatchId($model)) {
                $warnings[] = sprintf(
                    '%s model %s is itself a batch-only identifier, so no synchronous role can call it; '
                        . 'left as is — point it at the plain id.',
                    $label,
                    $model,
                );
                continue;
            }
            if ($candidates === []) {
                // An empty list is an outage or a provider without a public
                // catalogue — no evidence about this model either way.
                $warnings[] = sprintf(
                    '%s model %s was left as is: provider "%s" returned no live model list '
                        . '(transport failure or no public catalogue).',
                    $label,
                    $model,
                    $provider,
                );
                continue;
            }

            $newest = ModelVersion::newestOnSameLine($candidates, $model);
            if ($newest !== null && ModelVersion::compare($newest, $model) > 0) {
                $changes[] = [
                    'role'              => $role,
                    'label'             => $label,
                    'provider'          => $provider,
                    'model'             => $newest,
                    'previous_provider' => $provider,
                    'previous_model'    => $model,
                ];
                $warnings[] = sprintf(
                    '%s model %s → %s (newest release on the same line at "%s").',
                    $label,
                    $model,
                    $newest,
                    $provider,
                );
                continue;
            }

            $served = in_array($model, $candidates, true);
            if ($served) {
                $warnings[] = sprintf(
                    '%s model %s is still served by "%s" and has no newer release on its line; left as is.',
                    $label,
                    $model,
                    $provider,
                );
                continue;
            }
            if ($newest !== null) {
                $warnings[] = sprintf(
                    '%s model %s is no longer served by "%s" and only older releases on its line are live '
                        . '(newest: %s); left as is — a downgrade is never automatic.',
                    $label,
                    $model,
                    $provider,
                    $newest,
                );
                continue;
            }
            // Gone, and its line is gone with it. Relocating to another
            // provider would change what the operator configured without
            // their say-so, so the run reports it and keeps the value.
            $warnings[] = sprintf(
                '%s model %s is no longer served by "%s" and no release on the same line is live there; '
                    . 'left as is (a linked model is never moved to another provider automatically).',
                $label,
                $model,
                $provider,
            );
        }

        return ['changes' => $changes, 'warnings' => $warnings, 'previous' => self::current()];
    }

    /**
     * Write the planned changes.
     *
     * Only values that actually differ from what is configured are written,
     * which is what keeps the provider key untouched unless the plan
     * deliberately moved a model (it never does — see {@see self::plan()}).
     * Dot-notation keys are used throughout so the rest of the `pipeline`
     * block in `runtime.json` survives.
     *
     * @param array<int,array<string,mixed>> $changes
     */
    public static function apply(array $changes): void
    {
        $updates = [];
        foreach ($changes as $change) {
            $role = (string)($change['role'] ?? '');
            $spec = self::ROLES[$role] ?? null;
            if ($spec === null) {
                continue;
            }
            $model = trim((string)($change['model'] ?? ''));
            if ($model !== '' && $model !== self::value($spec['model_path'])) {
                $updates[$spec['model_path']] = $model;
            }
            $provider = trim((string)($change['provider'] ?? ''));
            if ($provider !== '' && strtolower($provider) !== strtolower(self::value($spec['provider_path']))) {
                $updates[$spec['provider_path']] = strtolower($provider);
            }
        }
        if ($updates === []) {
            return;
        }
        // v1.8.50 — the analyzer/synthesis/arbiter move is part of the same
        // all-or-nothing run as the catalogue and the presets: a write that
        // did not land must throw so the caller restores the snapshot
        // instead of reporting a reconciled cluster that is not in effect.
        RuntimeConfig::updateOrFail($updates);
        Config::reload(Config::rootDir() . '/config.php');
    }

    /**
     * Put a recorded (pre-run) state back.
     *
     * A value that was an override before the run is written again; a value
     * that came from `config.php` is *un-overridden* instead of being
     * pinned to itself, so the shipped default stays in charge.
     *
     * @param array<string,mixed>|null $recorded {@see self::current()} as it
     *        was captured before the run
     */
    public static function restore(?array $recorded): void
    {
        if ($recorded === null) {
            return;
        }
        $updates = [];
        $forget = [];
        foreach (self::ROLES as $role => $spec) {
            $state = $recorded[$role] ?? null;
            if (!is_array($state)) {
                continue;
            }
            foreach ([
                ['provider', $spec['provider_path']],
                ['model', $spec['model_path']],
            ] as [$field, $path]) {
                $value = trim((string)($state[$field] ?? ''));
                if ($value === '') {
                    continue;
                }
                if (($state[$field . '_overridden'] ?? false) === true) {
                    $updates[$path] = $value;
                    continue;
                }
                $forget[] = $path;
            }
        }
        if ($updates !== []) {
            RuntimeConfig::updateOrFail($updates);
        }
        if ($forget !== []) {
            RuntimeConfig::forgetOrFail($forget);
        }
        if ($updates !== [] || $forget !== []) {
            Config::reload(Config::rootDir() . '/config.php');
        }
    }

    /** A config value as a trimmed string; anything non-scalar reads as ''. */
    private static function value(string $path): string
    {
        $value = Config::get($path, '');
        if (is_string($value)) {
            return trim($value);
        }
        return is_int($value) || is_float($value) ? trim((string)$value) : '';
    }

    /** Does `runtime.json` hold this key (as opposed to `config.php`)? */
    private static function isOverridden(string $path): bool
    {
        $cursor = RuntimeConfig::all();
        foreach (explode('.', $path) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return false;
            }
            $cursor = $cursor[$part];
        }
        return true;
    }
}
