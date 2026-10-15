<?php
/**
 * ModelCatalogDiff: reconcile the live provider catalogues with the
 * persisted one.
 *
 * The persisted catalogue (`var/config/runtime.json` → `models[]`) is what
 * the pipeline can actually dispatch to; the live lists come from
 * `GET {base}/models` on each active connector. Stage 1 of an
 * actualization run is exactly this comparison: what appeared, what the
 * provider no longer serves.
 *
 * The subtle part is failure. `ConnectorInterface::listModels()` swallows
 * transport errors and returns `[]`, so an empty live list means either
 * "this provider serves nothing" or "the request failed" — and treating
 * the second as the first would mark the whole catalogue stale and let
 * stage 3 rewrite every preset around an outage. An empty live list is
 * therefore reported as *unavailable*, never as evidence of staleness.
 *
 * Pure functions, no I/O: the whole comparison is testable against
 * fixtures.
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

final class ModelCatalogDiff
{
    /** Canonical `provider|model` key. */
    public static function key(string $provider, string $model): string
    {
        return strtolower(trim($provider)) . '|' . trim($model);
    }

    /**
     * Normalise one provider's live list into deduplicated rows.
     *
     * Accepts the two shapes the connectors can hand back: a plain list of
     * ids (`OpenRouterConnector::listModels()`) and a list of
     * `['id' => …]` maps (as some OpenAI-compatible endpoints answer), so
     * a caller never has to know which provider it is holding.
     *
     * @param array<int,mixed> $live
     * @return array<int,array{provider:string,model:string}>
     */
    public static function normalise(array $live, string $provider): array
    {
        $provider = strtolower(trim($provider));
        $seen = [];
        $out = [];
        foreach ($live as $entry) {
            $id = '';
            if (is_string($entry)) {
                $id = $entry;
            } elseif (is_array($entry) && isset($entry['id']) && is_string($entry['id'])) {
                $id = $entry['id'];
            }
            $id = trim($id);
            if ($id === '') { continue; }
            $key = self::key($provider, $id);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = ['provider' => $provider, 'model' => $id];
        }
        return $out;
    }

    /**
     * Compare the persisted catalogue against live lists.
     *
     * @param array<int,array<string,mixed>> $catalog persisted rows
     *        (`['provider' => …, 'model' => …]`, extra keys ignored)
     * @param array<string,array<int,mixed>> $live    provider => raw live list
     *        (only providers that were actually queried)
     *
     * @return array{
     *     live:array<int,array{provider:string,model:string}>,
     *     added:array<int,array{provider:string,model:string}>,
     *     stale:array<int,array{provider:string,model:string}>,
     *     unchanged:array<int,array{provider:string,model:string}>,
     *     unavailable:array<int,string>,
     *     live_count:int,
     *     catalog_count:int
     * }
     */
    public static function compare(array $catalog, array $live): array
    {
        $liveRows = [];
        $unavailable = [];
        foreach ($live as $provider => $list) {
            $rows = self::normalise(is_array($list) ? $list : [], (string)$provider);
            if ($rows === []) {
                // Either an outage or a provider with no public catalogue.
                // Both mean "no evidence", never "everything is stale".
                $unavailable[] = strtolower(trim((string)$provider));
                continue;
            }
            foreach ($rows as $row) {
                $liveRows[self::key($row['provider'], $row['model'])] = $row;
            }
        }
        // A key => true map, not array_keys(): the stale test below does an
        // isset() lookup by key, and array_keys() returns a *list*, where
        // isset() would be answering "is there an element at this numeric
        // index" — i.e. always no.
        $liveKeys = [];
        $liveProviders = [];
        foreach ($liveRows as $key => $row) {
            $liveKeys[$key] = true;
            $liveProviders[$row['provider']] = true;
        }

        $catalogKeys = [];
        $catalogRows = [];
        foreach ($catalog as $row) {
            if (!is_array($row)) { continue; }
            $provider = strtolower(trim((string)($row['provider'] ?? '')));
            $model = trim((string)($row['model'] ?? ''));
            if ($provider === '' || $model === '') { continue; }
            $key = self::key($provider, $model);
            if (isset($catalogKeys[$key])) { continue; }
            $catalogKeys[$key] = true;
            $catalogRows[$key] = ['provider' => $provider, 'model' => $model];
        }

        $added = [];
        $unchanged = [];
        foreach ($liveRows as $key => $row) {
            if (isset($catalogKeys[$key])) {
                $unchanged[] = $row;
                continue;
            }
            $added[] = $row;
        }

        $stale = [];
        foreach ($catalogRows as $key => $row) {
            if (isset($liveKeys[$key])) { continue; }
            // A catalogue row is only provably gone when its own provider
            // answered with a non-empty list this run.
            if (!isset($liveProviders[$row['provider']])) { continue; }
            $stale[] = $row;
        }

        sort($unavailable);
        return [
            'live'          => array_values($liveRows),
            'added'         => $added,
            'stale'         => $stale,
            'unchanged'     => $unchanged,
            'unavailable'   => array_values(array_unique($unavailable)),
            'live_count'    => count($liveRows),
            'catalog_count' => count($catalogRows),
        ];
    }
}
