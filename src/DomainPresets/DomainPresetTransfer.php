<?php
/**
 * DomainPresetTransfer: the document presets travel in, and the plan for
 * reading one back.
 *
 * Export is the easy half - a JSON file the operator can keep next to a
 * configuration backup. Import is where the damage would be, so the rules are
 * deliberately narrow:
 *
 *   - only presets that already exist are touched (matched by slug). An import
 *     restores what was exported; it does not invent pools, because a pool the
 *     engine never had is a pool nobody chose;
 *   - every model is checked against the active catalogue. A pool entry the
 *     provider no longer serves is reported, not written: importing a dead id
 *     is how a working council quietly loses a member;
 *   - a preset whose models ALL fail validation is skipped rather than written
 *     empty - an empty pool is worse than a stale one, it makes the run fail at
 *     dispatch instead of at import;
 *   - nothing is written unless the caller asks to apply. The plan is computed
 *     first and returned, so the operator sees exactly what would change.
 *
 * Pure: no database, no filesystem. The controller does the writes.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-28
 */
declare(strict_types=1);

namespace App\DomainPresets;

final class DomainPresetTransfer
{
    /** Format version of the exported document. */
    public const DOCUMENT_VERSION = 1;

    /**
     * The exported document.
     *
     * @param array<int,DomainPreset> $presets
     * @return array<string,mixed>
     */
    public static function document(array $presets, string $exportedAt): array
    {
        $out = [];
        foreach ($presets as $preset) {
            $out[] = self::presetToArray($preset);
        }
        return [
            'ok'           => true,
            'kind'         => 'revoap.domain-presets',
            'version'      => self::DOCUMENT_VERSION,
            'exported_at'  => $exportedAt,
            'count'        => count($out),
            'presets'      => $out,
        ];
    }

    /**
     * A stable string form of an arbiter. Key ORDER is not a difference: the
     * database hands the row back in its own column order, so a plain
     * json_encode() comparison would call every stored arbiter "changed" and
     * write a preset nobody asked to change.
     *
     * @param array<string,mixed>|null $arbiter
     */
    private static function canonical(?array $arbiter): string
    {
        if ($arbiter === null) {
            return '';
        }
        ksort($arbiter);
        return (string)json_encode($arbiter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** One preset in the document, in the shape the importer reads back. */
    public static function presetToArray(DomainPreset $preset): array
    {
        return [
            'slug'        => $preset->slug,
            'name'        => $preset->name,
            'description' => $preset->description,
            'models'      => $preset->selectedModels(),
            'arbiter'     => $preset->arbiter,
            'skill_slug'  => $preset->skillSlug,
            'is_system'   => $preset->isSystem,
        ];
    }

    /**
     * What an import would do, preset by preset.
     *
     * @param array<string,mixed>                   $payload  The parsed document.
     * @param array<string,DomainPreset>            $existing Presets by slug.
     * @param array<int,array<string,string>>        $active   Active catalogue
     *                                                         entries (provider, model).
     * @return array{ok:bool,error?:string,message?:string,plan:array<int,array<string,mixed>>}
     */
    public static function plan(array $payload, array $existing, array $active): array
    {
        $incoming = $payload['presets'] ?? null;
        if (!is_array($incoming)) {
            return ['ok' => false, 'error' => 'invalid_document', 'plan' => [],
                'message' => 'The document has no "presets" list.'];
        }
        if (isset($payload['kind']) && (string)$payload['kind'] !== 'revoap.domain-presets') {
            return ['ok' => false, 'error' => 'wrong_kind', 'plan' => [],
                'message' => 'This is not a RevoAp preset document.'];
        }

        $known = [];
        foreach ($active as $row) {
            $provider = is_array($row) ? (string)($row['provider'] ?? '') : '';
            $model    = is_array($row) ? (string)($row['model'] ?? '') : '';
            if ($provider !== '' && $model !== '') {
                $known[$provider . '/' . $model] = true;
            }
        }

        $plan = [];
        foreach ($incoming as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = strtolower(trim((string)($entry['slug'] ?? '')));
            if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug) !== 1) {
                $plan[] = ['slug' => (string)($entry['slug'] ?? ''), 'action' => 'skipped',
                    'reason' => 'invalid_slug', 'added' => [], 'removed' => [], 'unknown_models' => []];
                continue;
            }
            if (!isset($existing[$slug])) {
                $plan[] = ['slug' => $slug, 'action' => 'skipped', 'reason' => 'unknown_preset',
                    'added' => [], 'removed' => [], 'unknown_models' => []];
                continue;
            }

            $wanted = [];
            $unknown = [];
            foreach ((array)($entry['models'] ?? []) as $m) {
                $provider = is_array($m) ? trim((string)($m['provider'] ?? '')) : '';
                $model    = is_array($m) ? trim((string)($m['model'] ?? '')) : '';
                $id       = $provider . '/' . $model;
                if ($provider === '' || $model === '') { continue; }
                if (!isset($known[$id])) { $unknown[$id] = true; continue; }
                $wanted[$id] = ['provider' => $provider, 'model' => $model];
            }
            $wanted = array_values($wanted);

            if ($wanted === []) {
                $plan[] = ['slug' => $slug, 'action' => 'skipped', 'reason' => 'no_usable_models',
                    'added' => [], 'removed' => [], 'unknown_models' => array_keys($unknown)];
                continue;
            }

            $current = [];
            foreach ($existing[$slug]->selectedModels() as $m) {
                $current[(string)$m['provider'] . '/' . (string)$m['model']] = true;
            }
            $next = [];
            foreach ($wanted as $m) { $next[$m['provider'] . '/' . $m['model']] = true; }

            $added   = array_keys(array_diff_key($next, $current));
            $removed = array_keys(array_diff_key($current, $next));

            // A preset is more than its models: the display name, the arbiter and
            // the skill link travel in the document too, and an import that only
            // compared models would report "unchanged" for a document whose names
            // differ - so restoring one's own export would silently keep the old
            // names. Metadata counts as a change.
            $name = (string)($entry['name'] ?? $existing[$slug]->name);
            // An EXPLICIT null in the document means "no arbiter" and clears it; an
            // ABSENT key means the document says nothing about it, so the current
            // one is kept. Collapsing the two would make a restore silently keep a
            // drifted arbiter - the export always writes the key, so a restore
            // has to be able to clear what the export says is empty.
            $arbiter = array_key_exists('arbiter', $entry)
                ? (is_array($entry['arbiter']) ? $entry['arbiter'] : null)
                : $existing[$slug]->arbiter;
            $skill = array_key_exists('skill_slug', $entry)
                ? (is_string($entry['skill_slug']) && $entry['skill_slug'] !== '' ? $entry['skill_slug'] : null)
                : $existing[$slug]->skillSlug;
            $metaChanged = $name !== $existing[$slug]->name
                || self::canonical($arbiter) !== self::canonical($existing[$slug]->arbiter)
                || $skill !== $existing[$slug]->skillSlug;

            $plan[] = [
                'slug'           => $slug,
                'action'         => ($added === [] && $removed === [] && $unknown === [] && !$metaChanged)
                    ? 'unchanged' : 'update',
                'name'           => $name,
                'models'         => $wanted,
                'arbiter'        => $arbiter,
                'skill_slug'     => $skill,
                'added'          => $added,
                'removed'        => $removed,
                'unknown_models' => array_keys($unknown),
            ];
        }

        return ['ok' => true, 'plan' => $plan];
    }
}