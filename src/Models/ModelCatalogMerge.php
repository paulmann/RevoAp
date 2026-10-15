<?php
/**
 * ModelCatalogMerge: the two merges the persisted catalogue needs.
 *
 * The catalogue lives in `var/config/runtime.json` → `models[]` and is the
 * single source both the Models-tab table and `Config::getActiveModels()`
 * read (the pipeline's L1 fan-out). Two different writers touch it, and
 * both of them used to lose data:
 *
 *   1. **The operator's "Save models" button.** The form only carries
 *      `provider` / `model` / `active`, so a naive replace dropped the
 *      `label`, `rank`, `category` and `default` keys the shipped
 *      catalogues (`config/models/*.php`) put on a row — the curated
 *      ordering and the "recommended" annotations vanished on the first
 *      save. {@see self::mergeSubmitted()} re-attaches them per row.
 *
 *   2. **"Import models".** A provider catalogue holds hundreds of ids;
 *      the persisted list is capped by configuration
 *      ({@see \App\Core\Config::catalogMaxRows()}) because the form has to
 *      stay editable. {@see self::mergeImported()} appends only what fits,
 *      never duplicates a row that is already there, and never rewrites an
 *      existing row's `active` / `rank` / `category` — an import is a
 *      statement about *what the provider serves*, not about what the
 *      operator wants to run.
 *
 *      v1.8.60 — the cap is no longer the hard-coded {@see self::MAX_ROWS}
 *      and the clamp that used to rewrite every caller's limit is gone. An
 *      *actualization run* does not truncate here at all: it lets the merge
 *      keep everything and truncates in
 *      {@see \App\Models\CatalogTruncation}, where the rows the pool and
 *      the presets reference are known and can be protected.
 *
 * Both merges are pure: they take the current rows, return new rows, and
 * write nothing. The caller persists the result through
 * {@see \App\Settings\SettingsPersist}.
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

final class ModelCatalogMerge
{
    /**
     * Legacy row cap, kept for backwards compatibility only.
     *
     * v1.8.60 — this constant is no longer the catalogue's ceiling. The
     * effective cap is configuration (`models.catalog.max_rows`, read
     * through {@see \App\Core\Config::catalogMaxRows()}), and it is applied
     * by {@see \App\Models\CatalogTruncation} with the referenced rows
     * protected and the rest ranked by family. Callers that used to pass no
     * limit now pass {@see self::LIMIT_FROM_CONFIG}; this constant survives
     * because it is part of the published API and because a caller that
     * wants the historical behaviour explicitly can still ask for it.
     */
    public const MAX_ROWS = 64;

    /** Sentinel limit: "read the cap from configuration". */
    public const LIMIT_FROM_CONFIG = -1;

    /**
     * Resolve an effective row cap. `0` means "no ceiling"; a negative
     * sentinel means "ask the configuration"; anything else is honoured
     * as-is.
     *
     * The v1.8.59 code clamped every limit to {@see self::MAX_ROWS}
     * (`max(0, min($limit, self::MAX_ROWS))`), which is why raising the
     * constant in one place changed nothing: the clamp silently rewrote the
     * caller's intent. There is no clamp here — the limit a caller passes
     * is the limit that is applied.
     */
    public static function resolveLimit(int $limit = self::LIMIT_FROM_CONFIG): int
    {
        if ($limit < 0) {
            return \App\Core\Config::catalogMaxRows();
        }
        return $limit;
    }

    /** Whether a resolved limit is in force (0 = no ceiling). */
    public static function isLimited(int $limit): bool
    {
        return $limit > 0;
    }

    /** Provider slug normalisation shared by every row of this class. */
    public static function normaliseProvider(mixed $provider): string
    {
        return strtolower(trim((string)$provider));
    }

    /** Identity of a catalogue row: provider + model id, lowercased provider. */
    public static function key(string $provider, string $model): string
    {
        return self::normaliseProvider($provider) . '|' . trim($model);
    }

    /**
     * Coerce one row into the persisted shape, or null when it carries no
     * usable identity. Optional metadata (`label`, `rank`, `category`,
     * `default`) is kept when present so a round-trip through the form does
     * not strip it.
     *
     * @param  mixed $row
     * @return array<string,mixed>|null
     */
    public static function normaliseRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $provider = self::normaliseProvider($row['provider'] ?? '');
        $model    = trim((string)($row['model'] ?? ''));
        if ($provider === '' || $model === '') {
            return null;
        }
        $out = [
            'provider' => $provider,
            'model'    => $model,
            'active'   => (bool)($row['active'] ?? false),
        ];
        // A batch-only id may be catalogued — the provider does serve it —
        // but it can never be switched on for the synchronous pool, and the
        // row carries the reason so the tab can say why instead of offering
        // a checkbox that would only ever fail at dispatch time.
        $rejection = ModelId::syncPoolRejection($model);
        if ($rejection !== null) {
            $out['active']           = false;
            $out['batch_only']       = true;
            $out['sync_pool_reason'] = $rejection;
        }
        if (array_key_exists('default', $row)) {
            $out['default'] = (bool)$row['default'];
        }
        if (isset($row['label']) && is_string($row['label']) && trim($row['label']) !== '') {
            $out['label'] = trim($row['label']);
        }
        if (isset($row['rank']) && is_numeric($row['rank'])) {
            $out['rank'] = (int)$row['rank'];
        }
        if (isset($row['category']) && is_string($row['category']) && trim($row['category']) !== '') {
            $out['category'] = trim($row['category']);
        }
        // v1.8.60 — staleness travels with the row. An actualization marks a
        // row the provider no longer serves (`stale=true`, `active=false`,
        // plus the reason), and the operator's next "Save models" must not
        // quietly re-activate it: the form only carries
        // provider/model/active, so the flag has to be carried by this
        // normaliser like `label`/`rank`/`category` already are.
        if (($row['stale'] ?? false) === true) {
            $out['stale'] = true;
            if (isset($row['stale_reason']) && is_string($row['stale_reason']) && trim($row['stale_reason']) !== '') {
                $out['stale_reason'] = trim($row['stale_reason']);
            }
        }
        return $out;
    }

    /**
     * @param  array<int,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function normaliseAll(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $clean = self::normaliseRow($row);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /**
     * Index rows by {@see self::key()}, first occurrence winning (the
     * shipped catalogues list a model once, and a duplicate the operator
     * added by hand must not shadow the annotated row).
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    public static function indexByKey(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $clean = self::normaliseRow($row);
            if ($clean === null) {
                continue;
            }
            $key = self::key($clean['provider'], $clean['model']);
            if (!isset($index[$key])) {
                $index[$key] = $clean;
            }
        }
        return $index;
    }

    /**
     * Save-side merge: keep the operator's list and order, restore the
     * metadata the form did not carry.
     *
     * `active` always comes from the submitted row — that is the field the
     * operator actually toggled. Every other key falls back to the row
     * that is already in effect, so a save can never silently change a
     * rank, a category or the fresh-install `default` flag.
     *
     * @param  array<int,mixed> $submitted Rows straight from the request.
     * @param  array<int,mixed> $previous  Rows currently in effect.
     * @return array{rows:array<int,array<string,mixed>>,added:int,kept:int,limit:int,truncated:int}
     */
    public static function mergeSubmitted(array $submitted, array $previous, int $limit = self::LIMIT_FROM_CONFIG): array
    {
        $limit = self::resolveLimit($limit);
        $previousIndex = self::indexByKey(self::normaliseAll($previous));
        $rows = [];
        $seen = [];
        $added = 0;
        $kept = 0;
        $truncated = 0;
        foreach ($submitted as $row) {
            $clean = self::normaliseRow($row);
            if ($clean === null) {
                continue;
            }
            $key = self::key($clean['provider'], $clean['model']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            // A limit of 0 is "no ceiling", not "keep nothing": the guard has
            // to be positive, or the first row would already be over it.
            if ($limit > 0 && count($rows) >= $limit) {
                $truncated++;
                continue;
            }
            $existing = $previousIndex[$key] ?? null;
            if ($existing === null) {
                $added++;
                $rows[] = $clean;
            } else {
                $kept++;
                // The operator's toggle is the only thing the form decides;
                // everything else stays as the row already is — including the
                // key order the shipped catalogue uses, so runtime.json does
                // not churn between two identical saves.
                $row = $existing;
                $row['active'] = $clean['active'];
                $rows[] = $row;
            }
        }
        return [
            'rows'      => $rows,
            'added'     => $added,
            'kept'      => $kept,
            'limit'     => $limit,
            'truncated' => $truncated,
        ];
    }

    /**
     * Import-side merge: append the provider's ids that are not in the
     * catalogue yet.
     *
     * New rows are inert (`active=false`, `rank=0`, `category='imported'`):
     * importing is a statement about what the provider *serves*, and
     * switching a few hundred newly discovered ids on would silently
     * change what L1 actually calls. The operator enables the ones they
     * want with the row's own checkbox, and "Save models" then persists
     * that choice like any other.
     *
     * Existing rows are returned untouched — same order, same flags.
     *
     * Rows whose id is a batch-only identifier are catalogued too, but
     * inert and *marked*: `active=false`, `batch_only=true` and a
     * `sync_pool_reason` saying why, plus their own `batch` list in the
     * returned counters. They are served by the provider, so hiding them
     * would make the catalogue disagree with the provider's own list; they
     * can never run in the synchronous pool, so the reason travels with the
     * row instead of being dropped on the floor.
     *
     * @param  array<int,mixed> $existing Rows currently in effect.
     * @param  array<int,mixed> $incoming Provider catalogue rows ({id,name}).
     * @param  string           $provider Provider slug for the incoming rows.
     * @return array{rows:array<int,array<string,mixed>>,added:array<int,string>,batch:array<int,string>,present:int,duplicates:int,truncated:int,limit:int}
     */
    public static function mergeImported(
        array $existing,
        array $incoming,
        string $provider,
        int $limit = self::LIMIT_FROM_CONFIG,
    ): array {
        $provider = self::normaliseProvider($provider);
        $rows = self::normaliseAll($existing);
        $seen = [];
        foreach ($rows as $row) {
            $seen[self::key($row['provider'], $row['model'])] = true;
        }
        // v1.8.60 — no clamp against MAX_ROWS. The caller's limit is the
        // limit; `0` means "no ceiling". An over-cap catalogue is a decision
        // for {@see \App\Models\CatalogTruncation::apply()}, which knows
        // which rows the pool and the presets reference and can rank the
        // rest by family — an arrival-order cut here would decide the same
        // question with strictly less information.
        $limit = self::resolveLimit($limit);
        $added = [];
        $batch = [];
        $duplicates = 0;
        $truncated = 0;
        foreach ($incoming as $entry) {
            $model = self::incomingId($entry);
            if ($model === '') {
                continue;
            }
            $key = self::key($provider, $model);
            if (isset($seen[$key])) {
                $duplicates++;
                continue;
            }
            if ($limit > 0 && count($rows) >= $limit) {
                $truncated++;
                continue;
            }
            $seen[$key] = true;
            $row = [
                'provider' => $provider,
                'model'    => $model,
                'label'    => self::incomingLabel($entry, $model),
                'rank'     => 0,
                'active'   => false,
                'default'  => false,
                'category' => 'imported',
            ];
            $rejection = ModelId::syncPoolRejection($model);
            if ($rejection !== null) {
                $row['batch_only']       = true;
                $row['sync_pool_reason'] = $rejection;
                $row['category']         = 'batch';
                $batch[] = $provider . '|' . $model;
            }
            $rows[] = $row;
            $added[] = $provider . '|' . $model;
        }
        return [
            'rows'       => $rows,
            'added'      => $added,
            'batch'      => $batch,
            'present'    => count($rows) - count($added),
            'duplicates' => $duplicates,
            'truncated'  => $truncated,
            'limit'      => $limit,
        ];
    }

    /**
     * The model id inside a provider-catalogue entry. OpenRouter and BotHub
     * both answer with `{id, name}`; a bare string is accepted too so the
     * merge can be tested and reused without the HTTP shape.
     */
    public static function incomingId(mixed $entry): string
    {
        if (is_string($entry)) {
            return trim($entry);
        }
        if (!is_array($entry)) {
            return '';
        }
        foreach (['id', 'model'] as $field) {
            $value = $entry[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    /** Human label for an imported row (`{name}` when the provider sent one). */
    private static function incomingLabel(mixed $entry, string $model): string
    {
        if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
            $name = trim($entry['name']);
            if ($name !== '') {
                return mb_substr($name, 0, 128);
            }
        }
        return $model;
    }
}
