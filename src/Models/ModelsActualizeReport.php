<?php
/**
 * ModelsActualizeReport: the run's Final answer, written from its own envelope.
 *
 * A models-actualization run is a council session like any other - JobService
 * creates the row up front so every session endpoint resolves while it runs -
 * but it has no pipeline, no steps and no consensus. Until now that session
 * was never finished: the job went `completed`, the Models tab showed the
 * result, and History kept showing the run as `running` for ever.
 *
 * This class turns the envelope the actualizer already persists as the job
 * result into the document an operator actually wants: what was downloaded,
 * what was already current, what changed, what was dropped, and which model
 * replaced which. It reads nothing else - no database, no config, no network -
 * so the same text can be produced for a historical job row by the heal
 * command in `bin/models-actualize-heal.php`.
 *
 * Every section is derived from a key that exists in the envelope; a key that
 * is absent (an older run, a run that died before the stage that writes it)
 * omits its section instead of printing an empty one, and no number is
 * invented.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-27
 */
declare(strict_types=1);

namespace App\Models;

final class ModelsActualizeReport
{
    /** Names shown in a comma list before it is truncated to "… (+N more)". */
    private const INLINE_LIMIT = 12;

    /** Rows listed per section in the body; the rest are summarised. */
    private const LIST_LIMIT = 40;

    /**
     * The verdict stored for a run that computes no consensus.
     *
     * `council_sessions.consensus_status` is
     * `enum('pending','agreement','partial','conflict')`, and the production
     * MySQL runs non-strict, so any other value is silently stored as an empty
     * string - the first version of this class wrote `not_applicable` and read
     * back `''` without a single error. An actualization run has no consensus
     * to report, so the empty value IS the answer; naming it here keeps the
     * write honest and the schema untouched.
     */
    public const CONSENSUS_STATUS = '';

    /**
     * The Final answer of one actualization run.
     *
     * @param array<string,mixed> $envelope the value persisted as the job result
     * @return string Markdown
     */
    public static function markdown(array $envelope): string
    {
        $ok         = ($envelope['ok'] ?? false) === true;
        $stage      = (string)($envelope['stage'] ?? '');
        $import     = self::arr($envelope['import'] ?? null);
        $diff       = self::arr($envelope['diff'] ?? null);
        $catalog    = self::arr($envelope['catalog'] ?? null);
        $analyzer   = self::arr($envelope['analyzer'] ?? null);
        $invariant  = self::arr($envelope['invariant'] ?? null);
        $warnings   = self::listOfStrings($envelope['warnings'] ?? null);
        $assigned   = self::arr($envelope['assigned'] ?? null);
        $reasons    = self::arr($envelope['reasons'] ?? null);
        $linked     = self::listOfArrays($envelope['linked'] ?? null);
        $source     = (string)($import['source'] ?? '');
        $duration   = (int)($envelope['duration_ms'] ?? 0);

        $out = [];
        $out[] = '# Models actualization' . ($source !== '' ? ' - ' . $source : '');
        $out[] = '';
        $out[] = $ok
            ? '**Result: success.** ' . self::successLine($import, $diff, $catalog, $duration)
            : '**Result: problems.** ' . self::problemLine($envelope);
        if (!$ok) {
            // The envelope names the stage the run died in separately
            // (`failed_stage`); `stage` is the terminal verdict itself.
            $stopped = (string)($envelope['failed_stage'] ?? '');
            if ($stopped !== '') {
                $out[] = '';
                $out[] = 'The run stopped in stage `' . $stopped . '`.';
            }
        }

        // ---- synchronization numbers -------------------------------------
        $table = [];
        if (isset($import['fetched'])) {
            $table[] = ['Downloaded from the provider', (int)$import['fetched'] . ' model(s)'];
        }
        if (isset($import['present'])) {
            $table[] = ['Already in the catalogue', (int)$import['present'] . ' row(s)'];
        }
        if (isset($import['added'])) {
            $table[] = ['New rows added', (string)count(self::listOfStrings($import['added'] ?? null))];
        }
        if (isset($import['duplicates'])) {
            $table[] = ['Duplicate rows collapsed', (int)$import['duplicates']];
        }
        if (isset($import['batch'])) {
            $table[] = ['Batch-only ids among the new rows', count(self::listOfStrings($import['batch'] ?? null)) . ' (inactive for the synchronous pool)'];
        }
        if (isset($import['unusable'])) {
            $table[] = ['Rows the importer refused', (int)$import['unusable']];
        }
        if (isset($diff['live_count'])) {
            $table[] = ['Models the provider serves now', (int)$diff['live_count']];
        }
        if (isset($diff['catalog_count'])) {
            $table[] = ['Rows in the catalogue after the run', (int)$diff['catalog_count']];
        }
        if (isset($diff['stale'])) {
            $table[] = ['Marked stale (no longer served)', count(self::listOfStrings($diff['stale'] ?? null))];
        }
        if (isset($catalog['families'])) {
            $table[] = ['Vendor families represented', (int)$catalog['families']];
        }
        if (isset($catalog['stale'])) {
            $table[] = ['Stale rows in the catalogue', (int)$catalog['stale']];
        }
        if ($assigned !== []) {
            $table[] = ['Presets actualized', count($assigned) . ' (' . implode(', ', array_map('strval', array_keys($assigned))) . ')'];
        }
        if ($table !== []) {
            $out[] = '';
            $out[] = '## Synchronization';
            $out[] = '';
            $out[] = '| What | Value |';
            $out[] = '| --- | --- |';
            foreach ($table as [$k, $v]) {
                $out[] = '| ' . $k . ' | ' . $v . ' |';
            }
        }

        // ---- new rows ------------------------------------------------------
        $added = self::listOfStrings($import['added'] ?? null);
        if ($added !== []) {
            $out[] = '';
            $out[] = '## New in the catalogue (' . count($added) . ')';
            $out[] = '';
            foreach (self::clip($added) as $row) {
                $out[] = '- `' . str_replace('|', '/', $row) . '`';
            }
            if (count($added) > self::LIST_LIMIT) {
                $out[] = '- … and ' . (count($added) - self::LIST_LIMIT) . ' more';
            }
        }
        $batchAdded = self::listOfStrings($import['batch'] ?? null);
        if ($batchAdded !== []) {
            $out[] = '';
            $out[] = 'Batch-only identifiers among them (' . count($batchAdded) . '): '
                . self::inline($batchAdded)
                . '. They are served through the asynchronous Batch API, so the synchronous pool cannot call them.';
        }

        // ---- what went away ------------------------------------------------
        $stale = self::listOfStrings($diff['stale'] ?? null);
        if ($stale !== []) {
            $out[] = '';
            $out[] = '## No longer served (' . count($stale) . ') - marked stale and inactive';
            $out[] = '';
            foreach (self::clip($stale) as $row) {
                $out[] = '- `' . str_replace('|', '/', $row) . '`';
            }
            if (count($stale) > self::LIST_LIMIT) {
                $out[] = '- … and ' . (count($stale) - self::LIST_LIMIT) . ' more';
            }
        }

        // ---- which model replaced which ------------------------------------
        $out[] = '';
        $out[] = '## Updated to another model';
        $out[] = '';
        if ($linked === []) {
            $out[] = 'None. Every linked role is still served by its provider and has no newer release '
                . 'on its line, so nothing was switched automatically.';
        } else {
            foreach ($linked as $change) {
                $out[] = '- **' . (string)($change['label'] ?? $change['role'] ?? 'role') . '**: '
                    . self::ref($change, 'previous_provider', 'previous_model')
                    . ' -> ' . self::ref($change, 'provider', 'model');
            }
        }
        $poolRemovals = self::poolRemovals($warnings);
        if ($poolRemovals !== []) {
            $out[] = '';
            $out[] = 'Removed from the active pool (the catalogue no longer holds them as active):';
            $out[] = '';
            foreach ($poolRemovals as $row) {
                $out[] = '- `' . str_replace('|', '/', $row) . '`';
            }
        }

        // ---- presets --------------------------------------------------------
        if ($assigned !== []) {
            $out[] = '';
            $out[] = '## Presets';
            foreach ($assigned as $slug => $models) {
                $list = self::listOfStrings($models);
                $out[] = '';
                $out[] = '### ' . (string)$slug . ' (' . count($list) . ')';
                $out[] = '';
                foreach ($list as $model) {
                    $out[] = '- `' . str_replace('|', '/', $model) . '`';
                }
                $reason = isset($reasons[(string)$slug]) ? trim((string)$reasons[(string)$slug]) : '';
                if ($reason !== '') {
                    $out[] = '';
                    $out[] = '> ' . $reason;
                }
            }
        }

        // ---- analyzer --------------------------------------------------------
        if ($analyzer !== []) {
            $model = trim((string)($analyzer['provider'] ?? '') . '/' . (string)($analyzer['model'] ?? ''), '/');
            $out[] = '';
            $out[] = '## Analyzer';
            $out[] = '';
            $bits = [];
            if ($model !== '') { $bits[] = 'model `' . $model . '`'; }
            if (($analyzer['source'] ?? '') !== '') { $bits[] = 'chosen by `' . (string)$analyzer['source'] . '`'; }
            if (isset($analyzer['requests'])) { $bits[] = (int)$analyzer['requests'] . ' request(s)'; }
            if (isset($analyzer['corrections'])) { $bits[] = (int)$analyzer['corrections'] . ' correction(s)'; }
            if (isset($analyzer['attempts'])) { $bits[] = 'up to ' . (int)$analyzer['attempts'] . ' attempt(s) per preset'; }
            $out[] = implode(', ', $bits) . '.';
            $note = trim((string)($analyzer['note'] ?? ''));
            if ($note !== '') { $out[] = ''; $out[] = '> ' . $note; }
        }

        // ---- invariant --------------------------------------------------------
        if ($invariant !== []) {
            $out[] = '';
            $out[] = '## Catalogue invariant';
            $out[] = '';
            $checked = (int)($invariant['checked'] ?? 0);
            $out[] = ($invariant['ok'] ?? false) === true
                ? $checked . ' reference(s) checked - every model the pool, the presets and the linked roles '
                    . 'name is an active catalogue row.'
                : 'Violations: ' . count(self::listOfArrays($invariant['violations'] ?? null)) . '.';
        }

        // ---- warnings ----------------------------------------------------------
        if ($warnings !== []) {
            $out[] = '';
            $out[] = '## Warnings (' . count($warnings) . ')';
            $out[] = '';
            foreach ($warnings as $warning) {
                $out[] = '- ' . $warning;
            }
        }

        // ---- timing -------------------------------------------------------------
        $out[] = '';
        $out[] = '## Timing';
        $out[] = '';
        $out[] = 'Started ' . (string)($envelope['started_at'] ?? '-')
            . ', finished ' . (string)($envelope['finished_at'] ?? '-')
            . ($duration > 0 ? ' (' . number_format($duration / 1000, 1) . ' s)' : '') . '.';

        return implode("\n", $out) . "\n";
    }

    /**
     * A one-line Final answer for a run that never produced an envelope - the
     * actualizer was not wired into the worker, or it threw before it could
     * report. Used so a failed run cannot leave its session `running`.
     */
    public static function failure(string $message, string $class = ''): string
    {
        $out = [
            '# Models actualization',
            '',
            '**Result: failed.** Nothing was written to the configuration.',
            '',
            $class !== '' ? '`' . $class . '`: ' . $message : $message,
            '',
            'Re-run the actualization from the Models tab once the cause is addressed.',
        ];
        return implode("\n", $out) . "\n";
    }

    /** @param array<string,mixed> $import @param array<string,mixed> $diff @param array<string,mixed> $catalog */
    private static function successLine(array $import, array $diff, array $catalog, int $durationMs): string
    {
        $bits = [];
        if (isset($import['fetched'])) {
            $bits[] = 'downloaded ' . (int)$import['fetched'] . ' model(s) from "' . (string)($import['source'] ?? '?') . '"';
        }
        $added = count(self::listOfStrings($import['added'] ?? null));
        if ($added > 0) {
            $bits[] = $added . ' new row(s)';
        } else {
            $bits[] = 'no new rows';
        }
        $stale = count(self::listOfStrings($diff['stale'] ?? null));
        $bits[] = $stale > 0 ? $stale . ' row(s) marked stale' : 'nothing dropped as stale';
        if (isset($diff['live_count'])) {
            $bits[] = 'the provider serves ' . (int)$diff['live_count'];
        }
        if (isset($catalog['kept'])) {
            $bits[] = 'the catalogue holds ' . (int)$catalog['kept'];
        }
        if ($durationMs > 0) {
            $bits[] = 'in ' . number_format($durationMs / 1000, 1) . ' s';
        }
        return ucfirst(implode('; ', $bits)) . '.';
    }

    /** @param array<string,mixed> $envelope */
    private static function problemLine(array $envelope): string
    {
        $error = self::arr($envelope['error'] ?? null);
        $text  = trim((string)($error['message'] ?? ''));
        if ($text === '') {
            $text = trim((string)($envelope['message'] ?? ''));
        }
        return $text !== '' ? $text : 'The run finished without a usable result.';
    }

    /**
     * Pool entries the warnings say were removed. The warning text is the only
     * place the actualizer reports them, and it lists the ids after a colon.
     *
     * @param  array<int,string> $warnings
     * @return array<int,string>
     */
    private static function poolRemovals(array $warnings): array
    {
        foreach ($warnings as $warning) {
            if (!str_contains($warning, 'Removed ') || !str_contains($warning, 'pool entry')) {
                continue;
            }
            $colon = strpos($warning, ':');
            if ($colon === false) {
                continue;
            }
            $ids = [];
            foreach (explode(',', substr($warning, $colon + 1)) as $piece) {
                // The warning ends the id list with a sentence, then adds
                // advice; only the ids before that sentence are entries.
                $piece = trim($piece);
                $break = strpos($piece, '. ');
                if ($break !== false) {
                    $piece = substr($piece, 0, $break);
                }
                $piece = trim($piece, " \t\n\r\0\x0B.");
                // An id is `provider/model`; anything else is prose.
                if ($piece !== '' && str_contains($piece, '/')) {
                    $ids[] = $piece;
                }
            }
            if ($ids !== []) {
                return array_values($ids);
            }
        }
        return [];
    }

    /** `provider/model` from two keys, or `-` when either is missing. */
    private static function ref(array $row, string $providerKey, string $modelKey): string
    {
        $provider = trim((string)($row[$providerKey] ?? ''));
        $model    = trim((string)($row[$modelKey] ?? ''));
        if ($model === '') {
            return '-';
        }
        return '`' . ($provider !== '' ? $provider . '/' : '') . $model . '`';
    }

    /**
     * A comma list, truncated with a count so a 546-model import does not turn
     * into a wall of text.
     *
     * @param array<int,string> $items
     */
    private static function inline(array $items): string
    {
        if (count($items) <= self::INLINE_LIMIT) {
            return implode(', ', $items);
        }
        return implode(', ', array_slice($items, 0, self::INLINE_LIMIT))
            . ', … (+' . (count($items) - self::INLINE_LIMIT) . ' more)';
    }

    /**
     * @param  array<int,string> $items
     * @return array<int,string>
     */
    private static function clip(array $items): array
    {
        return array_slice($items, 0, self::LIST_LIMIT);
    }

    /** @return array<string,mixed> */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array<int,string> */
    private static function listOfStrings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            } elseif (is_int($item) || is_float($item)) {
                $out[] = (string)$item;
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
