<?php
declare(strict_types=1);

namespace App\Diagnostics;

/**
 * R2 - why a run ended the way it did, and what to do about it.
 *
 * Pure: it takes the facts that are already recorded and returns one verdict
 * with one recommended next step. Nothing here queries, and nothing hides a
 * failure - the point of the screen it feeds is that an operator can explain a
 * run without opening a log.
 *
 * Two rules shape the ordering:
 *
 *   1. **System causes are checked before run causes.** If the worker is
 *      running stale code, or the schema is behind, that explains a run's
 *      failure better than any per-model reason - and every evening of work on
 *      this codebase has proved it: the same symptoms had a system cause.
 *   2. **A cause without an action is not a cause.** Every branch returns at
 *      least one next step; an unrecognised failure falls back to naming the
 *      raw record rather than shrugging.
 *
 * A run that simply did its job returns `ok` with no actions, so callers can
 * render the block unconditionally.
 */
final class RunDiagnosis
{
    public const OK = 'ok';

    /**
     * @param array{
     *     status?:string, status_reason?:string, status_detail?:string, status_facts?:array<string,mixed>,
     *     pool?:array<int,array<string,mixed>>, missing?:array<int,array<string,mixed>>,
     *     breaker?:array<int,array<string,mixed>>, preflight?:array<string,mixed>|null,
     *     code_state?:string, code_newest_file?:string, schema_live?:string|null, schema_expected?:string|null,
     *     session_id?:int
     * } $facts
     * @return array{severity:string,cause:string,headline:string,detail:string,actions:array<int,array<string,mixed>>}
     */
    public static function fromFacts(array $facts): array
    {
        $f = static fn(string $key): string => trim((string)($facts[$key] ?? ''));

        // --- 1. system causes: they explain the run, not the other way round ---
        if (($facts['code_state'] ?? '') === 'stale') {
            return self::verdict('error', 'stale_worker_code',
                'The worker is running code older than what is deployed.',
                'A PHP process keeps the classes it loaded at start, so a worker started before a deploy '
                    . 'runs the old code while the files on disk are new. This explains failures that no '
                    . 'per-model reason does, including "call to undefined method" errors. '
                    . 'Newest deployed file: ' . ($f('code_newest_file') ?: 'not reported') . '.',
                // The panel asks; the worker stops cleanly; systemd starts it again.
                // No sudo, no shell from PHP - see WorkerRestartSignal.
                [self::action('restart_worker', 'Restart the workers', 'endpoint',
                    '/api/worker/restart', 'POST', true),
                 self::action('open_raw_event', 'Open the raw record', 'navigate')]);
        }

        $live = $facts['schema_live'] ?? null;
        $expected = $facts['schema_expected'] ?? null;
        if (is_string($live) && is_string($expected) && $expected !== '' && $live !== $expected) {
            return self::verdict('warn', 'schema_out_of_date',
                sprintf('The database schema is behind: live %s, expected %s.', $live, $expected),
                'A release that moves the schema leaves an existing database one step behind until migrations '
                    . 'are applied. Until then the columns the new code writes may not exist, and the run can '
                    . 'fail in ways that look unrelated.',
                [self::action('apply_migrations', 'Apply migrations', 'endpoint',
                    '/api/settings/migrations/apply', 'POST', true),
                 self::action('open_raw_event', 'Open the raw record', 'navigate')]);
        }

        // --- 2. run causes -----------------------------------------------------
        $status = strtolower($f('status'));
        $failedSteps = is_array($facts['failed_steps'] ?? null) ? $facts['failed_steps'] : [];
        if ($status === '' || $status === self::OK || $status === 'completed') {
            // A record that says "completed" is a claim, not evidence. Session #21 is
            // the case that taught this: it was written as completed before the engine
            // learned to tell the truth, while its L4 step had failed. The screen is
            // the last place that should repeat that, so the steps decide here.
            if ($failedSteps !== []) {
                $names = [];
                foreach ($failedSteps as $step) {
                    $key = trim((string)($step['step_key'] ?? ''));
                    if ($key !== '') { $names[] = $key; }
                }
                $named = $names === [] ? 'a step' : implode(', ', $names);

                return self::verdict('error', 'recorded_completed_but_failed',
                    'The record says this run completed, but ' . $named . ' failed.',
                    'This session was written before the engine started recording why a run ended, '
                    . 'so its status is the old, optimistic one. The steps show what actually happened: '
                    . 'the synthesis never produced an answer. Re-running completes the run.'
                    . self::missingText($facts['missing'] ?? []),
                    [self::action('rerun_synthesis', 'Re-run the synthesis (L4)', 'endpoint',
                        '/api/history/session/{id}/complete', 'POST', false),
                     self::action('open_raw_event', 'Open the raw record', 'navigate')]);
            }
            if (($facts['breaker'] ?? []) !== []) {
                return self::verdict('warn', 'breaker_dropped_models',
                    'The run completed, but some models were dropped part-way.',
                    self::missingText($facts['missing'] ?? []) . ' The breaker dropped: '
                        . self::breakerText($facts['breaker'] ?? []) . '.',
                    [self::action('open_model_selector', 'Review the model pool in the sidebar', 'navigate')]);
            }

            return self::verdict('ok', self::OK, 'This run completed.', '', []);
        }

        $reason = $f('status_reason');
        $detail = $f('status_detail');

        if ($reason === 'pool_no_usable_models' || $reason === 'pool_resolved_empty') {
            return self::verdict('error', 'pool_no_usable_models',
                'The pool resolved to no callable model.',
                'Every model the request named was refused before any call was made'
                    . ($detail !== '' ? ': ' . $detail : '.')
                    . ' The usual reason is a stale catalogue: the pool references identifiers the provider '
                    . 'no longer serves.',
                [self::action('refresh_catalogue', 'Refresh the model catalogue', 'endpoint',
                    '/api/models/actualize', 'POST', false),
                 self::action('open_model_selector', 'Pick models in the sidebar', 'navigate')]);
        }

        if ($reason === 'l4_synthesis_failed' || $reason === 'answer_is_fallback' || $reason === 'answer_empty'
            || $reason === 'l4_step_failed') {
            return self::verdict('error', 'synthesis_failed',
                'The synthesis step failed, so there is no final answer.',
                ($detail !== '' ? $detail : 'The synthesis call did not return a usable answer.')
                    . self::missingText($facts['missing'] ?? []),
                [self::action('rerun_synthesis', 'Re-run the synthesis (L4)', 'endpoint',
                    '/api/history/session/{id}/complete', 'POST', false),
                 self::action('open_raw_event', 'Open the raw record', 'navigate')]);
        }

        if ($reason === 'l1_no_results' || $reason === 'l1_no_available_models') {
            $raises = self::timeoutHint($facts['missing'] ?? []);

            return self::verdict('error', 'l1_no_results',
                'No participant produced an answer.',
                ($detail !== '' ? $detail : 'Every L1 call failed.') . self::missingText($facts['missing'] ?? [])
                    . $raises,
                [self::action('open_raw_event', 'Open the raw record', 'navigate'),
                 self::action('refresh_catalogue', 'Refresh the model catalogue', 'endpoint',
                     '/api/models/actualize', 'POST', false)]);
        }

        if ($reason === 'connector_unavailable') {
            return self::verdict('error', 'connector_unavailable',
                'A provider could not be used.',
                ($detail !== '' ? $detail : 'The connector reported that its key is absent or refused.'),
                [self::action('open_connector_settings', 'Open Settings → Connectors', 'navigate'),
                 self::action('open_raw_event', 'Open the raw record', 'navigate')]);
        }

        if ($detail !== '' || $reason !== '') {
            return self::verdict('error', $reason !== '' ? $reason : 'unknown',
                $reason !== '' ? 'This run ended as ' . $status . ' (' . $reason . ').' : 'This run did not complete.',
                $detail !== '' ? $detail : 'The engine recorded no further explanation.',
                [self::action('open_raw_event', 'Open the raw record', 'navigate')]);
        }

        return self::verdict('error', 'unknown',
            'This run did not complete, and no reason was recorded.',
            'The run ended as ' . $status . ' without a reason. The raw record is the only remaining source.',
            [self::action('open_raw_event', 'Open the raw record', 'navigate')]);
    }

    /** @return array<string,mixed> */
    private static function action(
        string $id,
        string $label,
        string $kind,
        ?string $target = null,
        ?string $method = null,
        bool $confirm = false,
    ): array {
        return [
            'id'                 => $id,
            'label'              => $label,
            'kind'               => $kind,     // endpoint | command | navigate
            'target'             => $target,
            'method'             => $method,
            'needs_confirmation' => $confirm,
        ];
    }

    /** @return array{severity:string,cause:string,headline:string,detail:string,actions:array<int,array<string,mixed>>} */
    private static function verdict(string $severity, string $cause, string $headline, string $detail, array $actions): array
    {
        return ['severity' => $severity, 'cause' => $cause, 'headline' => $headline, 'detail' => $detail, 'actions' => $actions];
    }

    /** @param array<int,array<string,mixed>> $missing */
    private static function missingText(array $missing): string
    {
        if ($missing === []) {
            return '';
        }
        $parts = [];
        foreach ($missing as $row) {
            $model = (string)($row['model'] ?? '');
            $reason = (string)($row['reason'] ?? '');
            if ($model === '') { continue; }
            $parts[] = $model . ($reason !== '' ? ' (' . $reason . ')' : '');
        }

        return $parts === [] ? '' : ' Not answered: ' . implode('; ', $parts) . '.';
    }

    /** @param array<int,array<string,mixed>> $breaker */
    private static function breakerText(array $breaker): string
    {
        $parts = [];
        foreach ($breaker as $row) {
            $model = (string)($row['model'] ?? '');
            if ($model !== '') { $parts[] = $model; }
        }

        return implode(', ', $parts);
    }

    /**
     * When every participant timed out, the useful sentence is the one about the
     * budget they timed out against - not another "check the provider key".
     *
     * @param array<int,array<string,mixed>> $missing
     */
    private static function timeoutHint(array $missing): string
    {
        $timeouts = 0;
        foreach ($missing as $row) {
            if (str_contains(strtolower((string)($row['reason'] ?? '')), 'tim')) {
                $timeouts++;
            }
        }
        if ($timeouts === 0) {
            return '';
        }

        return sprintf(
            ' %d of them timed out; if these are reasoning models that legitimately need longer, raise '
            . 'connectors.<provider>.timeout_chat rather than replacing them.',
            $timeouts,
        );
    }
}