<?php
/**
 * CatalogTruncation: decide which catalogue rows survive the row cap.
 *
 * v1.8.60 — the persisted catalogue (`var/config/runtime.json` → `models[]`)
 * is what L1 can actually dispatch to, and it has to hold every model the
 * provider serves or the two drift apart: the pool, the domain presets and
 * the three linked roles all reference rows *of this list*. Until now the
 * cap was a hard-coded 64 and the truncation was blind — `array_slice()` in
 * arrival order — so raising the ceiling would only have moved the cliff.
 *
 * This class exists so truncation is a *decision* rather than a slice:
 *
 *   1. **Referenced rows are never dropped.** Anything the pool
 *      (`pipeline.default_selected_models`), a domain preset
 *      (`domain_presets.definition_json` → `models` / `arbiter`) or a
 *      linked role (analyzer / synthesis / arbiter) points at is kept,
 *      because dropping one is precisely the failure this release is
 *      about: the catalogue no longer contains the model the pipeline was
 *      told to call. When the protected rows alone exceed the cap the cap
 *      loses — an over-full catalogue is a disclosure to report, a
 *      dangling reference is a broken install.
 *
 *   2. **The rest is ranked, not sliced.** Rows are grouped by
 *      {@see ProviderFamily}, ordered inside each family by
 *      {@see ModelVersion} (newest release first) and then by their
 *      catalogue position — which is the providers' own ranking, since
 *      `config/models/*.php` ships them in order. Families are then
 *      interleaved, so every family contributes its newest flagship before
 *      any family contributes its second string. A blind slice gives the
 *      first family in the list 64 rows and every other vendor none; this
 *      gives the operator a catalogue that still spans the vendors.
 *
 *   3. **Everything is counted.** The return value names the limit, how
 *      many rows were kept, how many the cap left out, how many of the
 *      kept rows were protected, and the rule that was applied, so an
 *      actualization run can put "≈528 rows left out: the catalogue is
 *      capped at 400; newest release per family kept" in its report
 *      instead of truncating in silence.
 *
 * Pure functions, no I/O, no config access: the caller passes the limit in.
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

final class CatalogTruncation
{
    /** The rule label for "no cap was applied". */
    public const RULE_UNCAPPED = 'uncapped';

    /** The rule label for "everything fitted under the cap". */
    public const RULE_UNCHANGED = 'unchanged';

    /** The rule label for "protected rows only, cap exceeded on purpose". */
    public const RULE_PROTECTED_OVER_CAP = 'protected_over_cap';

    /** The rule label for the ranked, family-interleaved selection. */
    public const RULE_FLAGSHIPS_PER_FAMILY = 'flagships_per_family';

    /**
     * Apply the cap, keeping every protected row and ranking the rest.
     *
     * @param array<int,array<string,mixed>> $rows      Catalogue rows, in
     *        catalogue order (`provider`, `model`, plus any metadata).
     * @param int                            $limit     0 = no ceiling.
     * @param array<int,string>              $protected `provider|model`
     *        keys (see {@see self::key()}) that must survive the cap.
     *
     * @return array{
     *     rows:array<int,array<string,mixed>>,
     *     limit:int,
     *     kept:int,
     *     dropped:int,
     *     protected_kept:int,
     *     over_cap:int,
     *     rule:string,
     *     dropped_ids:array<int,string>,
     *     families:int
     * }
     */
    public static function apply(array $rows, int $limit, array $protected = []): array
    {
        $total = count($rows);
        if ($limit <= 0) {
            return self::result($rows, $limit, 0, 0, self::RULE_UNCAPPED, []);
        }
        if ($total <= $limit) {
            return self::result($rows, $limit, 0, 0, self::RULE_UNCHANGED, []);
        }

        $protectedKeys = [];
        foreach ($protected as $key) {
            $normalised = self::normaliseKey($key);
            if ($normalised !== '') {
                $protectedKeys[$normalised] = true;
            }
        }

        // Pass 1 — referenced rows. Their catalogue order is preserved so a
        // run that changes nothing else does not reorder the file.
        $kept = [];
        $keptSeen = [];
        $rest = [];
        $protectedKept = 0;
        foreach ($rows as $row) {
            $key = self::key(
                (string)($row['provider'] ?? ''),
                (string)($row['model'] ?? ''),
            );
            if ($key === '') {
                continue;
            }
            if (isset($keptSeen[$key])) {
                continue;
            }
            if (isset($protectedKeys[$key])) {
                $keptSeen[$key] = true;
                $kept[] = $row;
                $protectedKept++;
                continue;
            }
            $rest[] = ['key' => $key, 'row' => $row];
        }

        if (count($kept) >= $limit) {
            // The referenced rows alone fill (or overflow) the cap. The
            // invariant wins: a pool entry pointing at a row the catalogue
            // does not hold is a broken install, an over-full catalogue is
            // a number in the report.
            $dropped = [];
            foreach ($rest as $entry) {
                $dropped[] = $entry['key'];
            }
            sort($dropped);
            return self::result(
                self::sortByCatalogueOrder($rows, $kept),
                $limit,
                count($dropped),
                $protectedKept,
                count($kept) > $limit ? self::RULE_PROTECTED_OVER_CAP : self::RULE_FLAGSHIPS_PER_FAMILY,
                $dropped,
            );
        }

        $ranked = self::rank($rest);
        $budget = $limit - count($kept);
        $dropped = [];
        for ($i = 0; $i < count($ranked); $i++) {
            $entry = $ranked[$i];
            if ($budget <= 0) {
                $dropped[] = $entry['key'];
                continue;
            }
            $budget--;
            $keptSeen[$entry['key']] = true;
            $kept[] = $entry['row'];
        }

        return self::result(
            self::sortByCatalogueOrder($rows, $kept),
            $limit,
            count($dropped),
            $protectedKept,
            self::RULE_FLAGSHIPS_PER_FAMILY,
            $dropped,
        );
    }

    /** Canonical `provider|model` key, matching {@see ModelCatalogDiff::key()}. */
    public static function key(string $provider, string $model): string
    {
        $provider = strtolower(trim($provider));
        $model = trim($model);
        if ($provider === '' || $model === '') {
            return '';
        }
        return $provider . '|' . $model;
    }

    /**
     * Rank the unprotected rows: families round-robin, newest release first
     * inside each family, catalogue position as the final tie-break.
     *
     * @param array<int,array{key:string,row:array<string,mixed>}> $rest
     * @return array<int,array{key:string,row:array<string,mixed>}>
     */
    private static function rank(array $rest): array
    {
        $byFamily = [];
        foreach ($rest as $position => $entry) {
            $model = (string)($entry['row']['model'] ?? '');
            $family = ProviderFamily::of($model);
            $byFamily[$family][] = ['position' => $position, 'entry' => $entry];
        }

        // Within a family: newest release first, then catalogue order. The
        // newest-first rule is what makes the surviving row "the flagship"
        // rather than "whichever row the provider listed last".
        $lanes = [];
        foreach ($byFamily as $family => $entries) {
            usort($entries, static function (array $a, array $b): int {
                $cmp = ModelVersion::compare(
                    (string)($b['entry']['row']['model'] ?? ''),
                    (string)($a['entry']['row']['model'] ?? ''),
                );
                return $cmp !== 0 ? $cmp : $a['position'] <=> $b['position'];
            });
            // Families with the strongest first pick lead, so a truncated
            // catalogue starts with the newest flagship on the host rather
            // than with an alphabetically lucky vendor. The index is
            // zero-padded because `ksort()` compares these keys as strings:
            // without it "10" would sort before "2" and the editorial order
            // would be silently replaced by a wrong one.
            $lanes[sprintf('%03d', ProviderFamily::order($family)) . "\0" . $family] = $entries;
        }
        ksort($lanes);

        $ranked = [];
        $depth = 0;
        $maxDepth = 0;
        foreach ($lanes as $entries) {
            $maxDepth = max($maxDepth, count($entries));
        }
        while ($depth < $maxDepth) {
            foreach ($lanes as $entries) {
                if (isset($entries[$depth])) {
                    $ranked[] = $entries[$depth]['entry'];
                }
            }
            $depth++;
        }
        return $ranked;
    }

    /**
     * Return the kept rows in their original catalogue order, so a
     * truncation never reshuffles the rows that survived (runtime.json would
     * otherwise churn between two identical runs).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $kept
     * @return array<int,array<string,mixed>>
     */
    private static function sortByCatalogueOrder(array $rows, array $kept): array
    {
        $keepKeys = [];
        foreach ($kept as $row) {
            $key = self::key((string)($row['provider'] ?? ''), (string)($row['model'] ?? ''));
            if ($key !== '') {
                $keepKeys[$key] = $row;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $key = self::key((string)($row['provider'] ?? ''), (string)($row['model'] ?? ''));
            if ($key !== '' && isset($keepKeys[$key])) {
                $out[] = $keepKeys[$key];
                unset($keepKeys[$key]);
            }
        }
        // Anything that was not in $rows (defensive: a caller passing a
        // filtered $kept) is appended rather than lost.
        foreach ($keepKeys as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>              $dropped
     * @return array{rows:array<int,array<string,mixed>>,limit:int,kept:int,dropped:int,protected_kept:int,over_cap:int,rule:string,dropped_ids:array<int,string>,families:int}
     */
    private static function result(
        array $rows,
        int $limit,
        int $dropped,
        int $protectedKept,
        string $rule,
        array $droppedIds,
    ): array {
        $families = [];
        foreach ($rows as $row) {
            $families[ProviderFamily::of((string)($row['model'] ?? ''))] = true;
        }
        return [
            'rows'           => array_values($rows),
            'limit'          => $limit,
            'kept'           => count($rows),
            'dropped'        => $dropped,
            'protected_kept' => $protectedKept,
            'over_cap'       => max(0, count($rows) - max(0, $limit)),
            'rule'           => $rule,
            'dropped_ids'    => array_values($droppedIds),
            'families'       => count($families),
        ];
    }

    /** Normalise a caller-supplied `provider|model` key. */
    private static function normaliseKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || !str_contains($key, '|')) {
            return '';
        }
        [$provider, $model] = explode('|', $key, 2);
        return self::key($provider, $model);
    }

    /**
     * Human sentence for the report: what the cap did, in the operator's
     * words. Returns '' when nothing was left out.
     *
     * @param array<string,mixed> $report {@see self::apply()}
     */
    public static function describe(array $report): string
    {
        $dropped = (int)($report['dropped'] ?? 0);
        $rule = (string)($report['rule'] ?? '');
        if ($dropped <= 0) {
            return '';
        }
        $reason = match ($rule) {
            self::RULE_PROTECTED_OVER_CAP => 'the rows the pool, the presets and the linked roles reference already exceed it, '
                . 'so the cap was raised for this run instead of leaving a dangling reference',
            default => 'the newest release of every provider family was kept first',
        };
        return sprintf(
            '%d catalogue row(s) were left out: the catalogue is capped at %d rows and %s. '
                . '%d referenced row(s) were kept regardless of the cap.',
            $dropped,
            (int)($report['limit'] ?? 0),
            $reason,
            (int)($report['protected_kept'] ?? 0),
        );
    }
}
