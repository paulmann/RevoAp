<?php
/**
 * ModelSubstitution: keep a council at full strength when a model is known broken.
 *
 * The dispatcher skips a model whose breaker is open, which is right - calling a
 * provider that just failed four times is how a run wastes its whole budget on
 * timeouts. What was missing is the other half: the council then runs one
 * member short, and an operator who asked for five perspectives silently gets
 * four. History already reports the gap ("a model dropped by the breaker"),
 * which is honest, but the run is still weaker than the one that was asked for.
 *
 * This class plans the replacement at pool assembly time - before the first
 * call, where the state is simple and nothing is in flight:
 *
 *   - a model the breaker reports as open is replaced by a candidate the active
 *     catalogue offers and the pool does not already contain;
 *   - vendors already present in the pool are avoided while other vendors are
 *     available, because the point of a council is independent perspectives, and
 *     five models from one vendor agree with each other more often than not;
 *   - the pool keeps its size: no candidate means the slot stays empty and the
 *     reason is reported, never padded with a duplicate or an unrelated id;
 *   - nothing is substituted silently - the caller gets a list of
 *     from -> to pairs to publish with the run.
 *
 * Pure: no database, no config lookups, no network. The caller supplies the
 * pool, the broken ids and the candidates.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-28
 */
declare(strict_types=1);

namespace App\Pipeline;

final class ModelSubstitution
{
    /**
     * @param array<int,array<string,mixed>> $pool       Resolved pool (provider, model).
     * @param array<int,string>              $openModels `provider/model` ids the breaker reports.
     * @param array<int,array<string,mixed>> $candidates Active catalogue rows.
     * @return array{pool:array<int,array<string,mixed>>,replaced:array<int,array<string,string>>,unfilled:array<int,array<string,string>>}
     */
    public static function plan(array $pool, array $openModels, array $candidates): array
    {
        $open = [];
        foreach ($openModels as $id) {
            $id = trim((string)$id);
            if ($id !== '') { $open[$id] = true; }
        }
        if ($open === []) {
            return ['pool' => $pool, 'replaced' => [], 'unfilled' => []];
        }

        $inPool = [];
        foreach ($pool as $entry) {
            $inPool[self::id($entry)] = true;
        }

        // Candidates worth having, in a stable order, keyed by id.
        $available = [];
        foreach ($candidates as $row) {
            $id = self::id($row);
            if ($id === '' || $id === '/' || isset($inPool[$id]) || isset($open[$id])) {
                continue;
            }
            $available[$id] = $row;
        }

        $next = $pool;
        $replaced = [];
        $unfilled = [];

        foreach ($pool as $index => $entry) {
            $id = self::id($entry);
            if ($id === '' || !isset($open[$id])) {
                continue;
            }
            $chosen = self::pick($available, self::vendors($next));
            if ($chosen === null) {
                $unfilled[] = ['provider' => (string)($entry['provider'] ?? ''), 'model' => (string)($entry['model'] ?? '')];
                continue;
            }
            $next[$index] = $chosen;
            $replaced[] = [
                'from' => $id,
                'to'   => self::id($chosen),
            ];
            unset($available[self::id($chosen)]);
        }

        return ['pool' => $next, 'replaced' => $replaced, 'unfilled' => $unfilled];
    }

    /**
     * Prefer a vendor the pool does not use yet; fall back to any candidate.
     *
     * @param array<string,array<string,mixed>> $available
     * @param array<string,bool>                $usedVendors
     * @return array<string,mixed>|null
     */
    private static function pick(array $available, array $usedVendors): ?array
    {
        foreach ($available as $row) {
            $vendor = strtolower((string)($row['provider'] ?? ''));
            if (!isset($usedVendors[$vendor])) {
                return $row;
            }
        }
        foreach ($available as $row) {
            return $row;
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $pool @return array<string,bool> */
    private static function vendors(array $pool): array
    {
        $out = [];
        foreach ($pool as $row) {
            $vendor = strtolower((string)($row['provider'] ?? ''));
            if ($vendor !== '') { $out[$vendor] = true; }
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function id(array $row): string
    {
        $provider = trim((string)($row['provider'] ?? ''));
        $model    = trim((string)($row['model'] ?? ''));
        return $provider === '' || $model === '' ? '' : $provider . '/' . $model;
    }
}