<?php
/**
 * SessionCompletionService: finish an interrupted council session.
 *
 * A session can end up half-written for reasons that have nothing to do
 * with the query: a provider socket died mid-batch, PHP hit its time
 * limit during L4, the FPM worker was killed, or the SSE connection was
 * dropped on a host where the run was not allowed to outlive it. The row
 * stays in `council_sessions` with status=running and the L1/L2 results
 * that WERE produced stay in `council_steps` / `council_model_responses`
 * — nothing in the current UI can turn that into a finished answer, so
 * the only option is re-running the whole council and paying for every
 * model twice.
 *
 * This service closes that gap. Given a session id it:
 *
 *   1. Reads the session, its steps, and its model responses.
 *   2. Rebuilds the run context from `council_sessions.skill_slug` +
 *      `options_json` (selected models, reasoning, cycle limit), falling
 *      back to the models recorded in the step journal for legacy rows
 *      that predate `options_json`.
 *   3. Computes a PLAN of exactly the stages that are missing.
 *   4. Executes only those stages, in order, feeding the persisted
 *      results back in as input. Models that already answered are never
 *      re-queried — their stored responses are replayed from
 *      `council_model_responses`.
 *   5. Marks the session completed through SessionRepository::complete()
 *      with a valid final answer, consensus verdict, token total and
 *      duration.
 *
 * Every step created while finishing a session is tagged in its `meta`
 * JSON with `repair => true` and the original reason, so reconstructed
 * steps stay distinguishable from the steps of the original run. The
 * journal is append-only: nothing is deleted or overwritten, and a
 * "stuck" step is moved to a terminal status instead of being removed.
 *
 * Concurrency: a repair takes the same file lock the SSE pipeline uses
 * ({@see \App\Pipeline\StreamRunLock}, keyed by a deterministic
 * `history-session-complete:<id>` pseudo client-run id), so two parallel
 * clicks cannot run the same repair twice, and a second click after a
 * success is a clean 409 rather than a duplicate run.
 *
 * The service never returns API keys, DSN credentials or absolute paths:
 * every free-form field is passed through Logger::sanitizeScalar.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

namespace App\History;

use App\Connectors\ChatResponse;
use App\Consensus\ConsensusResult;
use App\Core\Logger;
use App\Pipeline\PipelineEngine;
use App\Pipeline\StreamRunLock;
use App\Skills\Skill;
use App\Skills\SkillRepository;
use App\Storage\ResponseRepository;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;

final class SessionCompletionService
{
    /** Prefix of the StreamRunLock pseudo client-run id used as the repair mutex. */
    public const LOCK_PREFIX = 'history-session-complete:';

    /** Reasons surfaced in step meta / API payloads. */
    public const REASON = 'history_finish_session';

    /** Bundled default skill used when the session's own skill is gone. */
    public const FALLBACK_SKILL_SLUG = 'consensus_reasoning';

    public function __construct(
        private readonly SessionRepository   $sessions,
        private readonly StepRepository      $steps,
        private readonly ResponseRepository  $responses,
        private readonly SkillRepository     $skills,
        private readonly PipelineEngine      $engine,
        private readonly ?StreamRunLock      $lock = null,
    ) {}

    /**
     * Plan and (unless $dryRun) execute the completion of one session.
     *
     * @return array{body:array<string,mixed>,status:int} HTTP body + suggested status code.
     */
    public function complete(int $sessionId, bool $dryRun = false, bool $force = false): array
    {
        if ($sessionId <= 0) {
            return $this->respond([
                'ok'      => false,
                'error'   => 'invalid_id',
                'message' => 'A positive session id is required.',
            ], 400);
        }

        $ctx = $this->buildContext($sessionId);
        if (is_array($ctx['error'])) {
            $err = $ctx['error'];
            return $this->respond([
                'ok'         => false,
                'session_id' => $sessionId,
                'error'      => (string)$err['code'],
                'message'    => (string)$err['message'],
                'warnings'   => $ctx['warnings'],
            ], (int)$err['status']);
        }

        $envelope = [
            'ok'         => true,
            'session_id' => $sessionId,
            'dry_run'    => $dryRun,
            'plan'       => $ctx['plan'],
            'executed'   => [],
            'already_completed' => $ctx['already'],
            'session'    => $ctx['session_snapshot'],
            'warnings'   => $ctx['warnings'],
        ];

        if ($ctx['required'] !== true) {
            return $this->respond(array_merge($envelope, [
                'ok'      => false,
                'error'   => 'already_completed',
                'message' => 'Session #' . $sessionId . ' is already finished — nothing to build.',
            ]), 409);
        }

        if ($dryRun) {
            // Read-only: no step rows, no events, no session update.
            return $this->respond(array_merge($envelope, ['dry_run' => true]), 200);
        }

        // Refuse to touch a session another worker is still driving.
        if (!$force) {
            $live = $this->lock?->findBySession($sessionId);
            if (is_array($live)) {
                return $this->respond(array_merge($envelope, [
                    'ok'      => false,
                    'error'   => 'session_in_progress',
                    'message' => 'A pipeline run for this session is still active (started '
                        . (string)($live['acquired_at'] ?? 'recently') . '). Wait for it to finish, '
                        . 'or retry with ?force=1 if you are certain it is dead.',
                ]), 409);
            }
        }

        $lockId = self::LOCK_PREFIX . $sessionId;
        if ($this->lock !== null && !$this->lock->acquire($lockId)) {
            return $this->respond(array_merge($envelope, [
                'ok'      => false,
                'error'   => 'completion_in_progress',
                'message' => 'Another completion run for this session is already in progress.',
            ]), 409);
        }

        try {
            if ($this->lock !== null) {
                $this->lock->recordSession($lockId, $sessionId);
            }
            // Re-plan under the lock: a concurrent worker may have just
            // finished the session while we were waiting for the mutex.
            $ctx = $this->buildContext($sessionId);
            if (is_array($ctx['error'])) {
                $err = $ctx['error'];
                return $this->respond([
                    'ok'         => false,
                    'session_id' => $sessionId,
                    'error'      => (string)$err['code'],
                    'message'    => (string)$err['message'],
                    'warnings'   => $ctx['warnings'],
                ], (int)$err['status']);
            }
            if ($ctx['required'] !== true) {
                return $this->respond(array_merge($envelope, [
                    'ok'      => false,
                    'error'   => 'already_completed',
                    'message' => 'Session #' . $sessionId . ' was finished by another worker.',
                    'already_completed' => $ctx['already'],
                    'session' => $ctx['session_snapshot'],
                    'warnings'=> $ctx['warnings'],
                ]), 409);
            }

            return $this->runCompletion($sessionId, $ctx);
        } finally {
            $this->lock?->release($lockId);
        }
    }

    // -----------------------------------------------------------------
    // Execution
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $ctx
     * @return array{body:array<string,mixed>,status:int}
     */
    private function runCompletion(int $sessionId, array $ctx): array
    {
        $skill = $ctx['skill'];
        if (!$skill instanceof Skill) {
            return $this->respond([
                'ok'      => false,
                'session_id' => $sessionId,
                'error'   => 'skill_unavailable',
                'message' => 'The skill this session ran with no longer exists in the catalog.',
            ], 409);
        }

        $this->steps->appendEvent($sessionId, null, 'SESSION_REPAIR_STARTED', [
            'session_id' => $sessionId,
            'reason'     => self::REASON,
            'plan'       => $ctx['plan'],
            'dry_run'    => false,
        ]);

        // Any step still flagged `running` belongs to the process that
        // died. Move it to a terminal status (append-only: the row is
        // updated, never deleted) and say so explicitly.
        $staleNote = 'Abandoned: the run that owned this step never finished it. '
            . 'Superseded by the History "Finish session" rebuild.';
        $stale = [];
        try {
            $stale = $this->steps->listRunningSteps($sessionId);
            if ($stale !== []) {
                $this->steps->failRunningSteps($sessionId, $staleNote);
                $this->steps->appendEvent($sessionId, null, 'SESSION_REPAIR_STALE_STEPS', [
                    'reason' => self::REASON,
                    'count'  => count($stale),
                    'steps'  => array_map(
                        static fn(array $r): array => [
                            'step_id'  => (int)($r['id'] ?? 0),
                            'step_key' => (string)($r['step_key'] ?? ''),
                        ],
                        $stale,
                    ),
                ]);
            }
        } catch (\Throwable $e) {
            Logger::warn('session_repair.stale_steps_failed', [
                'session_id' => $sessionId,
                'message'    => $e->getMessage(),
            ]);
        }

        try {
            $result = $this->engine->repairSession([
                'session_id'  => $sessionId,
                'skill'       => $skill,
                'query'       => $ctx['query'],
                'options'     => $ctx['options'],
                'l1_results'  => $ctx['l1_results'],
                'l2_normalized' => $ctx['l2_normalized'],
                'consensus'   => $ctx['consensus'],
                'plan'        => $ctx['engine_plan'],
                'reason'      => self::REASON,
            ]);
        } catch (\Throwable $e) {
            $message = Logger::sanitizeScalar($e->getMessage());
            Logger::error('session_repair.failed', [
                'session_id' => $sessionId,
                'class'      => (new \ReflectionClass($e))->getShortName(),
                'message'    => $message,
            ]);
            $this->recordFailure($sessionId, $message);
            return $this->respond([
                'ok'         => false,
                'session_id' => $sessionId,
                'error'      => 'repair_failed',
                'message'    => $message,
                'plan'       => $ctx['plan'],
                'executed'   => [],
                'already_completed' => $ctx['already'],
                'session'    => $ctx['session_snapshot'],
                'warnings'   => $ctx['warnings'],
            ], 500);
        }

        $finalAnswer   = (string)$result['final_answer'];
        $consensus     = $result['consensus'];
        $totalTokens   = max(0, (int)$ctx['existing_tokens'] + (int)$result['tokens']);
        $totalDuration = max(0, (int)$ctx['existing_duration_ms'] + (int)$result['duration_ms']);

        // v1.8.48 — the rebuild ran, but L4 fell back to the apology body
        // again. Reporting that as a finished session is what made a broken
        // synthesis indistinguishable from a good one; the operator asked
        // for exactly the opposite ("нужно сохранять как Error и давать
        // возможность перезапустить"). The fallback body is still stored —
        // it is the only record of what the models answered — but the row is
        // marked failed, the failure is journaled, and the button stays
        // available for another attempt.
        $synthesisFailure = is_array($result['synthesis_failure'] ?? null) ? $result['synthesis_failure'] : null;
        // v1.8.60 — the engine's own terminal verdict for the repair it just
        // ran, which is no longer "synthesis_failure is null" alone. A
        // repair that produced no usable answer for a different reason (the
        // L1 step it rebuilt answered nothing, the answer body came back
        // empty) must be reported the same way: not finished.
        $repairVerdict = (string)($result['status'] ?? ($synthesisFailure === null ? 'completed' : 'failed'));
        $repairReason  = (string)($result['status_reason'] ?? '');
        if ($synthesisFailure !== null || $repairVerdict === 'failed') {
            $reason = $synthesisFailure !== null
                ? $this->describeSynthesisFailure($synthesisFailure)
                : $this->describeVerdictFailure($repairReason, (string)($result['status_detail'] ?? ''));
            Logger::warn('session_repair.synthesis_failed', [
                'session_id' => $sessionId,
                'reason'     => $repairReason !== '' ? $repairReason : 'synthesis_failure',
                'provider'   => $synthesisFailure['provider'] ?? null,
                'model'      => $synthesisFailure['model'] ?? null,
                'code'       => $synthesisFailure['provider_code'] ?? null,
                'message'    => $reason,
            ]);
            $consensus        = $consensus ?? new ConsensusResult(
                0.0,
                ConsensusResult::STATUS_SINGLE,
                'Single-model run: one model answered and its answer is the final answer; no consensus was computed.',
                ['single_model' => true],
            );
            try {
                $this->sessions->complete(
                    id:              $sessionId,
                    finalAnswer:     $finalAnswer,
                    consensusScore:  $consensus->status === ConsensusResult::STATUS_SINGLE ? null : $consensus->score,
                    consensusStatus: $consensus->status,
                    totalTokens:     $totalTokens,
                    durationMs:      $totalDuration,
                    status:          'failed',
                );
            } catch (\Throwable $e) {
                Logger::warn('session_repair.failed_state_write_failed', [
                    'session_id' => $sessionId,
                    'message'    => Logger::sanitizeScalar($e->getMessage()),
                ]);
            }
            $this->recordFailure($sessionId, $reason);
            return $this->respond([
                'ok'         => false,
                'session_id' => $sessionId,
                'error'      => 'synthesis_failed',
                'message'    => $reason,
                'plan'       => $ctx['plan'],
                'executed'   => $result['executed'],
                'already_completed' => $ctx['already'],
                'session'    => $this->snapshot($sessionId, $finalAnswer, $consensus, $totalTokens, $totalDuration, 'failed'),
                'synthesis_failure' => $synthesisFailure,
                'warnings'   => $ctx['warnings'],
            ], 502);
        }

        $consensus = $consensus ?? new ConsensusResult(
            0.0,
            ConsensusResult::STATUS_SINGLE,
            'Single-model run: one model answered and its answer is the final answer; no consensus was computed.',
            ['single_model' => true],
        );
        try {
            $this->sessions->complete(
                id:              $sessionId,
                finalAnswer:     $finalAnswer,
                consensusScore:  $consensus->status === ConsensusResult::STATUS_SINGLE ? null : $consensus->score,
                consensusStatus: $consensus->status,
                totalTokens:     $totalTokens,
                durationMs:      $totalDuration,
            );
        } catch (\Throwable $e) {
            $message = Logger::sanitizeScalar($e->getMessage());
            Logger::error('session_repair.complete_write_failed', [
                'session_id' => $sessionId,
                'message'    => $message,
            ]);
            $this->recordFailure($sessionId, 'Final write failed: ' . $message);
            return $this->respond([
                'ok'         => false,
                'session_id' => $sessionId,
                'error'      => 'repair_failed',
                'message'    => 'The rebuild succeeded but the session row could not be marked completed: ' . $message,
                'plan'       => $ctx['plan'],
                'executed'   => $result['executed'],
                'already_completed' => $ctx['already'],
                'session'    => $ctx['session_snapshot'],
                'warnings'   => $ctx['warnings'],
            ], 500);
        }

        $snapshot = $this->snapshot($sessionId, $finalAnswer, $consensus, $totalTokens, $totalDuration);

        $this->steps->appendEvent($sessionId, null, 'SESSION_REPAIR_COMPLETED', [
            'session_id'      => $sessionId,
            'reason'          => self::REASON,
            'executed'        => $result['executed'],
            'repair_tokens'   => (int)$result['tokens'],
            'repair_ms'       => (int)$result['duration_ms'],
            'total_tokens'    => $totalTokens,
            'duration_ms'     => $totalDuration,
            'consensus'       => $consensus->toArray(),
            'stale_steps'     => count($stale),
            'final_answer_chars' => mb_strlen($finalAnswer),
        ]);

        return $this->respond([
            'ok'         => true,
            'session_id' => $sessionId,
            'dry_run'    => false,
            'plan'       => $ctx['plan'],
            'executed'   => $result['executed'],
            'already_completed' => $ctx['already'],
            'session'    => $snapshot,
            'warnings'   => $ctx['warnings'],
        ], 200);
    }

    /**
     * One-line, operator-facing sentence for a failed synthesis. Names the
     * provider, the model and the provider's own error code when they are
     * known, because "L4 · Synthesis (failed)" alone was the complaint that
     * started this: the message said a synthesis had failed without saying
     * which model the provider had rejected or why.
     *
     * @param array<string,mixed> $failure
     */
    /**
     * v1.8.60 — the engine refused/failed the repair for a reason that is
     * not a synthesis record (an empty pool, no L1 answers, an empty body).
     * Say it in the same shape as {@see self::describeSynthesisFailure()} so
     * the History card renders one kind of sentence for one kind of event.
     */
    private function describeVerdictFailure(string $reason, string $detail): string
    {
        $labels = [
            'pool_no_usable_models' => 'The model pool could not be used: no entry of it is an active '
                . 'catalogue row with a registered connector',
            'catalog_empty'         => 'The active model catalogue is empty, so no model could be called',
            'l1_no_results'         => 'No model answered during the rebuild',
            'l4_synthesis_failed'   => 'The rebuild\'s synthesis (L4) did not produce an answer',
            'l4_fallback_body'      => 'The rebuild stored a fallback notice instead of an answer',
            'empty_final_answer'    => 'The rebuild produced no final answer at all',
            'synthesis_model_not_served' => 'The synthesis model is not served any more',
            'arbiter_model_not_served'   => 'The conflict arbiter model is not served any more',
            'analyzer_model_not_served'  => 'The analyzer model is not served any more',
        ];
        $head = $labels[$reason] ?? 'The rebuild did not produce a usable answer';
        $head .= ($reason !== '' ? ' [' . $reason . ']' : '') . '.';
        if ($detail !== '') {
            $head .= ' ' . $detail;
        }
        return $head . ' Run Actualize and pick current models, then try again.';
    }

    private function describeSynthesisFailure(array $failure): string
    {
        $model    = trim((string)($failure['model'] ?? ''));
        $provider = trim((string)($failure['provider'] ?? ''));
        $code     = trim((string)($failure['provider_code'] ?? ''));
        $message  = trim((string)($failure['message'] ?? ''));
        $hint     = trim((string)($failure['hint'] ?? ''));

        $head = 'The final synthesis (L4) did not produce an answer';
        $where = [];
        if ($model !== '')    { $where[] = 'model "' . $model . '"'; }
        if ($provider !== '') { $where[] = 'provider "' . $provider . '"'; }
        if ($where !== [])    { $head .= ' for ' . implode(' on ', $where); }
        if ($code !== '')     { $head .= ' [' . $code . ']'; }
        $head .= '.';

        $attempts = is_array($failure['attempts'] ?? null) ? $failure['attempts'] : [];
        if (count($attempts) > 1) {
            $names = [];
            foreach ($attempts as $attempt) {
                $m = trim((string)($attempt['model'] ?? ''));
                if ($m !== '' && !in_array($m, $names, true)) { $names[] = $m; }
            }
            if ($names !== []) {
                $head .= ' Tried: ' . implode(', ', $names) . '.';
            }
        }

        $parts = [Logger::sanitizeScalar($head)];
        if ($message !== '') { $parts[] = 'Provider said: ' . $message; }
        if ($hint !== '')    { $parts[] = $hint; }
        $parts[] = 'The stored fallback body is kept for reference; re-run the synthesis from '
            . 'History once the model is available again.';

        return implode(' ', array_filter($parts, static fn(string $p): bool => trim($p) !== ''));
    }

    /**
     * Record an unrecoverable rebuild failure without stranding the
     * session: the status is left untouched (so another click stays
     * possible and meaningful) and the failure is journaled as an
     * append-only step + event.
     */
    private function recordFailure(int $sessionId, string $message): void
    {
        try {
            $stepId = $this->steps->createStep(
                $sessionId,
                'repair.failure',
                'Repair · failed',
                900,
                ['layer' => 'repair', 'repair' => true, 'reason' => self::REASON, 'message' => $message],
            );
            $this->steps->completeStep($stepId, 'failed', $message, ['repair' => true]);
        } catch (\Throwable $e) {
            Logger::warn('session_repair.failure_step_failed', [
                'session_id' => $sessionId,
                'message'    => $e->getMessage(),
            ]);
        }
        $this->steps->appendEvent($sessionId, null, 'SESSION_REPAIR_FAILED', [
            'session_id' => $sessionId,
            'reason'     => self::REASON,
            'message'    => $message,
        ]);
    }

    /**
     * Report the session state this repair just produced.
     *
     * `$fallbackStatus` is used when the row cannot be re-read at all — the
     * old default was the literal string `completed`, which meant "we could
     * not read the row" was reported to the operator as a successful,
     * finished session. Callers now pass what they actually wrote.
     *
     * @return array<string,mixed>
     */
    private function snapshot(
        int $sessionId,
        string $finalAnswer,
        ConsensusResult $consensus,
        int $tokens,
        int $durationMs,
        string $fallbackStatus = 'completed',
    ): array {
        $fresh = $this->sessions->find($sessionId) ?? [];
        // A single-model run has no measured score, and the session row is the
        // one that knows it: re-asserting the value object's placeholder 0.0
        // here would be the same lie as storing a zero for "never measured".
        // The row's own status is authoritative whenever it carries one.
        $rowStatus = strtolower(trim((string)($fresh['consensus_status'] ?? '')));
        $isSingle  = $rowStatus === 'single' || $consensus->status === ConsensusResult::STATUS_SINGLE;
        return [
            'id'               => $sessionId,
            'status'           => (string)($fresh['status'] ?? $fallbackStatus),
            'completed_at'     => isset($fresh['completed_at']) && $fresh['completed_at'] !== null
                ? (string)$fresh['completed_at'] : null,
            'final_answer'     => Logger::sanitizeScalar($finalAnswer),
            'consensus_status' => (string)$consensus->status,
            'consensus_score'  => $isSingle ? null : $consensus->score,
            'total_tokens'     => $tokens,
            'duration_ms'      => $durationMs,
        ];
    }

    // -----------------------------------------------------------------
    // Planning
    // -----------------------------------------------------------------

    /**
     * Read-only analysis of one session: current state, what is already
     * done, what is missing, and the rebuilt run context the engine needs
     * to fill the gaps.
     *
     * @return array<string,mixed>
     */
    private function buildContext(int $sessionId): array
    {
        $warnings = [];
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return $this->contextError('not_found', 'Session #' . $sessionId . ' does not exist.', 404, $warnings);
        }

        try {
            $steps = $this->steps->listStepsBySession($sessionId);
        } catch (\Throwable $e) {
            return $this->contextError(
                'history_query_failed',
                Logger::sanitizeScalar('Could not read the step journal: ' . $e->getMessage()),
                500,
                $warnings,
            );
        }

        $responses = [];
        foreach ($steps as $step) {
            $id = (int)($step['id'] ?? 0);
            if ($id <= 0) { continue; }
            try {
                $responses[$id] = $this->responses->listByStep($id);
            } catch (\Throwable) {
                $responses[$id] = [];
            }
        }

        // The rebuild works from round 1 on purpose: later rounds depend on
        // peer context from the round they followed, which a half-written
        // journal cannot always reproduce. Say so instead of silently
        // ignoring the extra rows.
        foreach ($steps as $step) {
            if ($this->roundOf($step) > 1) {
                $warnings[] = 'This session already has rows from consensus rounds after the first '
                    . 'one. The rebuild deliberately works from the round-1 results; the later '
                    . 'rows stay in the journal for audit but are not used.';
                break;
            }
        }

        // ---- L1: what already answered, and what never did -------------
        /** @var array<int,array<string,mixed>> $l1Rows */
        $l1Rows        = [];
        /** @var array<string,bool> $l1CompletedKeys */
        $l1CompletedKeys = [];
        /** @var array<string,array<string,mixed>> $l1CompletedSteps */
        $l1CompletedSteps = [];
        /** @var array<string,array{provider:string,model:string,status:string}> $l1SeenModels */
        $l1SeenModels  = [];

        foreach ($steps as $step) {
            if ($this->layerOf($step) !== 'L1' || $this->roundOf($step) !== 1) { continue; }
            $meta     = $this->metaOf($step);
            $provider = strtolower(trim((string)($meta['provider'] ?? '')));
            $model    = trim((string)($meta['model'] ?? ''));
            $status   = (string)($step['status'] ?? '');
            if ($provider !== '' && $model !== '') {
                $key = $provider . '|' . $model;
                $l1SeenModels[$key] = ['provider' => $provider, 'model' => $model, 'status' => $status];
            }
            $stepId = (int)($step['id'] ?? 0);
            $row    = $this->firstModelResponse($responses[$stepId] ?? []);
            if ($status !== 'completed' || $row === null) { continue; }
            $roleKey = $this->roleKeyOf($step, $row);
            if ($roleKey === '') { continue; }
            $response = $this->rebuildChatResponse($row);
            if ($response === null) { continue; }
            $l1Rows[] = [
                'role'     => ['key' => $roleKey, 'origin_key' => (string)($meta['origin_role'] ?? $roleKey)],
                'response' => $response,
                'step_id'  => $stepId,
            ];
            if ($provider !== '' && $model !== '') {
                $l1CompletedKeys[$provider . '|' . $model] = true;
                $l1CompletedSteps[$provider . '|' . $model] = [
                    'step_id'  => $stepId,
                    'step_key' => (string)($step['step_key'] ?? ''),
                    'role'     => $roleKey,
                ];
            }
        }

        // ---- L2: which roles were normalised ---------------------------
        /** @var array<string,bool> $l2CompletedRoles */
        $l2CompletedRoles = [];
        foreach ($steps as $step) {
            if ($this->layerOf($step) !== 'L2' || $this->roundOf($step) !== 1) { continue; }
            if ((string)($step['status'] ?? '') !== 'completed') { continue; }
            $roleKey = (string)($this->metaOf($step)['role'] ?? '');
            if ($roleKey !== '') { $l2CompletedRoles[$roleKey] = true; }
        }

        /** @var array<int,array<string,mixed>> $l2Normalized */
        $l2Normalized    = [];
        /** @var array<int,string> $l2Missing */
        $l2Missing       = [];
        /** @var array<int,array<string,mixed>> $l2Already */
        $l2Already       = [];
        foreach ($l1Rows as $row) {
            $roleKey = (string)($row['role']['key'] ?? '');
            $stepId  = (int)($row['step_id'] ?? 0);
            $payload = $this->normalizedPayload($responses[$stepId] ?? []);
            if (isset($l2CompletedRoles[$roleKey]) && $payload !== null) {
                $l2Normalized[] = ['role' => $row['role'], 'normalized' => $payload];
                $l2Already[] = [
                    'layer'   => 'L2',
                    'step_key' => 'L2.normalize.' . $roleKey,
                    'detail'  => 'Claims for role "' . $roleKey . '" are already stored.',
                ];
                continue;
            }
            $l2Missing[] = $roleKey;
        }

        // ---- L3: the stored verdict, when it is usable -----------------
        // A single-model run never had a verdict to store, and that is a
        // decision rather than a gap: it asked one model and used its answer.
        // Recognising `'single'` here is what stops "Finish session" from
        // scheduling an L3 that would score one answer against nothing and
        // write back the trivial 1.0 / agreement the run deliberately refused.
        // This is why the mode is also recorded in `options_json` (see
        // PipelineEngine): a row that carries no L3 step is otherwise
        // indistinguishable from a crashed run.
        $singleModelRun = $this->singleModelRun(
            $session,
            $this->decodeJson($session['options_json'] ?? null) ?? [],
            $steps,
        );
        $l3Required = true;
        $consensus  = null;
        $l3Info     = null;
        foreach ($steps as $step) {
            if ($this->layerOf($step) !== 'L3' || $this->roundOf($step) !== 1) { continue; }
            if ((string)($step['status'] ?? '') !== 'completed') { continue; }
            $payload = $this->metaOf($step)['consensus'] ?? null;
            if (!is_array($payload)) { continue; }
            $rebuilt = $this->rebuildConsensus($payload);
            if ($rebuilt === null) { continue; }
            $consensus = $rebuilt;
            $l3Info = [
                'step_id'  => (int)($step['id'] ?? 0),
                'step_key' => (string)($step['step_key'] ?? ''),
                'score'    => $rebuilt->score,
                'status'   => $rebuilt->status,
            ];
            break;
        }
        if ($consensus !== null) {
            $l3Required = false;
        } elseif ($singleModelRun) {
            $warnings[] = 'This run was executed in single-model mode: one model answered and no consensus '
                . 'was ever computed, so none will be computed here either. The stored answer is reused as-is.';
        } elseif (trim((string)($session['consensus_status'] ?? '')) !== ''
            && in_array((string)$session['consensus_status'], ['agreement', 'partial', 'conflict'], true)
            && $session['consensus_score'] !== null
        ) {
            // A stored verdict on the session row is usable as a fallback,
            // but a deterministic recomputation is free (L3 calls no LLM),
            // so the stage is still scheduled and simply overwrites it.
            $warnings[] = 'Session row carries a consensus verdict but no completed L3 step was found — '
                . 'the verdict will be recomputed (L3 is deterministic and calls no model).';
        }

        // ---- L4: the stored synthesis, when it is usable ---------------
        $existingFinal = null;
        $l4Info        = null;
        // A failed L4 step is the fingerprint of a run whose synthesis never
        // happened: the engine still wrote a locally assembled fallback body
        // (and, before v1.8.48, stamped the session `completed` with it), so
        // "the session row has a final answer" is NOT evidence that a usable
        // synthesis exists. Without this flag such a session was planned as
        // `l4.required = false`, the repair reused the fallback text, and
        // "Finish session" reproduced the broken answer with a green badge —
        // the reported behaviour.
        $l4Failed     = false;
        $l4FailedInfo = null;
        foreach ($steps as $step) {
            if ($this->layerOf($step) !== 'L4') { continue; }
            $status = (string)($step['status'] ?? '');
            if ($status === 'failed') {
                $l4Failed = true;
                if ($l4FailedInfo === null) {
                    $l4FailedInfo = [
                        'step_id'  => (int)($step['id'] ?? 0),
                        'step_key' => (string)($step['step_key'] ?? ''),
                        'detail'   => Logger::sanitizeScalar(mb_substr((string)($step['summary'] ?? ''), 0, 400)),
                    ];
                }
                continue;
            }
            if ($status !== 'completed') { continue; }
            $summary = (string)($step['summary'] ?? '');
            if (trim($summary) === '') { continue; }
            $existingFinal = $summary;
            $l4Info = [
                'step_id'  => (int)($step['id'] ?? 0),
                'step_key' => (string)($step['step_key'] ?? ''),
                'chars'    => mb_strlen($summary),
            ];
        }
        // A completed L4 step above always wins. Only when there is none is
        // the session row consulted, and then only if the journal shows no
        // failed synthesis.
        if ($existingFinal === null && $l4Failed) {
            $warnings[] = 'The last synthesis (L4) failed on this session, so the stored final answer is a '
                . 'fallback body rather than a synthesis — it will be ignored and the synthesis re-run.'
                . ($l4FailedInfo !== null && (string)$l4FailedInfo['detail'] !== ''
                    ? ' Reported: ' . (string)$l4FailedInfo['detail']
                    : '');
        } elseif ($existingFinal === null && trim((string)($session['final_answer'] ?? '')) !== '') {
            $existingFinal = (string)$session['final_answer'];
            $warnings[] = 'No completed L4 step was found, but the session row already carries a final '
                . 'answer — it will be preserved instead of synthesising a new one.';
        }
        $l4Required = $existingFinal === null;

        // ---- Run options ----------------------------------------------
        $options = $this->decodeJson($session['options_json'] ?? null) ?? [];
        if ($options === []) {
            $warnings[] = 'This session has no stored runtime options (legacy row) — selected models, '
                . 'reasoning and cycle limit were reconstructed from the step journal and the defaults.';
        }
        $wanted = $this->wantedModels($options, $l1SeenModels, $warnings);

        $accepted = [];
        $rejected = [];
        if ($wanted !== []) {
            $preview  = $this->engine->previewModelSelection($wanted);
            $accepted = is_array($preview['accepted'] ?? null) ? $preview['accepted'] : [];
            $rejected = is_array($preview['rejected'] ?? null) ? $preview['rejected'] : [];
            foreach ($rejected as $r) {
                $warnings[] = 'Model ' . (string)($r['provider'] ?? '?') . '/' . (string)($r['model'] ?? '?')
                    . ' cannot be used anymore (' . (string)($r['reason'] ?? 'unknown') . ').';
            }
        } else {
            $warnings[] = 'The original model selection could not be recovered, so no model will be '
                . 're-queried. The rebuild proceeds from the answers already stored in the journal.';
        }

        $l1Missing = [];
        if ($accepted === [] && $wanted !== [] && $l1Rows === []) {
            return $this->contextError(
                'nothing_to_repair',
                'None of the models this session was run with is available any more, and no answer was '
                . 'stored — there is nothing to rebuild from.',
                409,
                $warnings,
            );
        }
        foreach ($accepted as $entry) {
            $key = (string)$entry['provider'] . '|' . (string)$entry['model'];
            if (isset($l1CompletedKeys[$key])) { continue; }
            $l1Missing[] = ['provider' => (string)$entry['provider'], 'model' => (string)$entry['model']];
        }

        if ($l1Rows === [] && $l1Missing === []) {
            return $this->contextError(
                'nothing_to_repair',
                'This session has no stored model answers and no model that could be queried — '
                . 'there is nothing to build a final answer from.',
                409,
                $warnings,
            );
        }

        // ---- Plan items (single flat list the UI renders verbatim) -----
        $items = [];
        foreach ($l1Missing as $entry) {
            $items[] = [
                'layer'    => 'L1',
                'action'   => 'query_model',
                'provider' => (string)$entry['provider'],
                'model'    => (string)$entry['model'],
                'detail'   => 'Model ' . (string)$entry['provider'] . '/' . (string)$entry['model']
                    . ' never produced an answer — it will be queried once.',
            ];
        }
        foreach ($l2Missing as $roleKey) {
            $items[] = [
                'layer'  => 'L2',
                'action' => 'normalize',
                'role'   => $roleKey,
                'detail' => 'Claims for role "' . $roleKey . '" are missing — they will be extracted '
                    . '(no model call).',
            ];
        }
        if ($l1Missing !== []) {
            $items[] = [
                'layer'  => 'L2',
                'action' => 'normalize',
                'detail' => 'Any model that answers during the rebuild will also be normalised '
                    . '(no extra model call).',
            ];
        }
        if ($l3Required) {
            $items[] = [
                'layer'  => 'L3',
                'action' => 'score_consensus',
                'detail' => 'The consensus verdict is missing — it will be computed from the stored '
                    . 'claims (no model call).',
            ];
        }
        if ($l4Required) {
            $items[] = [
                'layer'  => 'L4',
                'action' => 'synthesize',
                'detail' => $l4Failed
                    ? 'The previous synthesis failed, so it will be re-run from the stored answers and '
                        . 'the consensus verdict.'
                    : 'The final answer is missing — it will be synthesised from the stored '
                        . 'answers and the consensus verdict.',
                'retry'  => $l4Failed,
            ];
        }

        $needRow = (string)($session['status'] ?? '') !== 'completed'
            || trim((string)($session['final_answer'] ?? '')) === '';
        if ($needRow && $items === []) {
            $items[] = [
                'layer'  => 'session',
                'action' => 'mark_completed',
                'detail' => 'Every pipeline stage already has a result — only the session row still '
                    . 'has to be marked completed.',
            ];
        }

        $required = $needRow || $items !== [];

        // ---- already-completed list -----------------------------------
        $already = [];
        foreach ($l1Rows as $row) {
            $roleKey = (string)($row['role']['key'] ?? '');
            $already[] = [
                'layer'   => 'L1',
                'step_id' => (int)$row['step_id'],
                'detail'  => 'Answer from "' . $roleKey . '" is reused — the model will not be queried again.',
            ];
        }
        foreach ($l2Already as $entry) { $already[] = $entry; }
        if ($l3Info !== null) {
            $already[] = [
                'layer'    => 'L3',
                'step_id'  => (int)$l3Info['step_id'],
                'step_key' => (string)$l3Info['step_key'],
                'detail'   => 'Consensus verdict ' . (string)$l3Info['status'] . ' ('
                    . number_format((float)$l3Info['score'], 3) . ') is reused.',
            ];
        }
        if ($l4Info !== null) {
            $already[] = [
                'layer'    => 'L4',
                'step_id'  => (int)$l4Info['step_id'],
                'step_key' => (string)$l4Info['step_key'],
                'detail'   => 'Stored synthesis is reused (' . (int)$l4Info['chars'] . ' chars).',
            ];
        }
        if (!$needRow) {
            $already[] = [
                'layer'  => 'session',
                'detail' => 'The session row is already marked completed.',
            ];
        }

        $plan = [
            'required' => $required,
            'reason'   => $required ? 'session_incomplete' : 'session_complete',
            'items'    => $items,
            'l1'       => [
                'expected'  => $accepted,
                'completed' => array_values(array_map(
                    static fn(array $r): array => ['provider' => (string)$r['provider'], 'model' => (string)$r['model']],
                    array_filter(
                        $l1SeenModels,
                        static fn(array $r): bool => $r['status'] === 'completed',
                    ),
                )),
                'missing'   => $l1Missing,
                'rejected'  => $rejected,
            ],
            'l2'       => [
                'roles_completed' => array_keys($l2CompletedRoles),
                'roles_missing'   => $l2Missing,
            ],
            'l3'       => ['required' => $l3Required, 'existing' => $l3Info],
            'l4'       => [
                'required' => $l4Required,
                'existing' => $l4Info,
                // v1.8.48 — the reason the plan exists at all, in the two
                // shapes the UI needs them: `failed` is "an L4 step is in
                // the journal marked failed", `retry` is "this run will
                // re-run the synthesis rather than only flip the session
                // row". Retry implies required.
                'failed'   => $l4Failed,
                'retry'    => $l4Required && $l4Failed,
                'failure'  => $l4FailedInfo,
            ],
            'session'  => [
                'status'        => (string)($session['status'] ?? ''),
                'has_final'     => trim((string)($session['final_answer'] ?? '')) !== '',
                'row_completed' => !$needRow,
            ],
        ];

        $skill = $this->resolveSkill($session, $warnings);

        return [
            'error'    => null,
            'warnings' => array_values(array_unique($warnings)),
            'plan'     => $plan,
            'already'  => $already,
            'required' => $required,
            'session'  => $session,
            'session_snapshot' => [
                'id'               => $sessionId,
                'status'           => (string)($session['status'] ?? ''),
                'completed_at'     => $session['completed_at'] !== null ? (string)$session['completed_at'] : null,
                'created_at'       => (string)($session['created_at'] ?? ''),
                'final_answer'     => Logger::sanitizeScalar((string)($session['final_answer'] ?? '')),
                'consensus_status' => (string)($session['consensus_status'] ?? ''),
                'consensus_score'  => $session['consensus_score'] !== null ? (float)$session['consensus_score'] : null,
                'total_tokens'     => (int)($session['total_tokens'] ?? 0),
                'duration_ms'      => (int)($session['duration_ms'] ?? 0),
            ],
            'query'    => (string)($session['query_text'] ?? ''),
            'options'  => $this->rebuildOptions($options, $session, $steps),
            'skill'    => $skill,
            'l1_results'    => $l1Rows,
            'l2_normalized' => $l2Normalized,
            'consensus'     => $consensus,
            'existing_final_answer' => $existingFinal,
            'existing_tokens'       => $this->aggregateExistingTokens($steps, $responses),
            'existing_duration_ms'  => (int)($session['duration_ms'] ?? 0),
            'engine_plan'  => [
                'l1_missing'  => $l1Missing,
                'l2_missing'  => $l2Missing,
                'l3'          => $l3Required,
                'l4'          => $l4Required,
                'existing_final_answer' => $existingFinal,
            ],
        ];
    }

    /**
     * @param  array<string,mixed> $warnings
     * @return array<string,mixed>
     */
    private function contextError(string $code, string $message, int $status, array $warnings): array
    {
        return [
            'error'    => ['code' => $code, 'message' => Logger::sanitizeScalar($message), 'status' => $status],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Rebuild the run options a repair needs from the stored snapshot.
     *
     * `options_json` intentionally stores a *description* of the reasoning
     * preset (see PipelineEngine::describeReasoning) rather than the raw
     * option, and it never stored the cycle limit at all — the resolved
     * value only ever made it into the L0_OPTIONS event. Both are
     * reconstructed here so the engine sees the closest possible
     * equivalent of the original run.
     *
     * The run mode is reconstructed too: a single-model session must not be
     * continued as a consensus one, or the engine would score the single
     * answer it is handed. See singleModelRun().
     *
     * @param  array<string,mixed> $options
     * @param  array<string,mixed> $session
     * @param  array<int,array<string,mixed>> $steps
     * @return array<string,mixed>
     */
    private function rebuildOptions(array $options, array $session, array $steps): array
    {
        $out = [];
        if (isset($options['selected_models']) && is_array($options['selected_models'])) {
            $out['selected_models'] = $options['selected_models'];
        }

        $reasoning = $options['reasoning'] ?? null;
        if (is_array($reasoning) && array_key_exists('effective', $reasoning)) {
            // describeReasoning() envelope: prefer the resolved payload,
            // then the requested preset name.
            if (is_array($reasoning['effective']) && $reasoning['effective'] !== []) {
                $out['reasoning'] = $reasoning['effective'];
            } elseif (is_string($reasoning['requested'] ?? null) && $reasoning['requested'] !== '') {
                $out['reasoning'] = (string)$reasoning['requested'];
            }
        } elseif (is_string($reasoning) || is_array($reasoning)) {
            $out['reasoning'] = $reasoning;
        }

        $cycle = $options['cycle_limit'] ?? null;
        if (!is_int($cycle) && !(is_string($cycle) && is_numeric($cycle))) {
            // Not a scalar in the metadata — read the L0_OPTIONS event,
            // which is where the resolved limit has always been recorded.
            $cycle = $this->cycleLimitFromEvents($session, $steps);
        }
        if (is_int($cycle) || (is_string($cycle) && is_numeric($cycle))) {
            $out['cycle_limit'] = (int)$cycle;
        }

        if (isset($options['consensus_skill'])) {
            $out['consensus_skill'] = $options['consensus_skill'];
        }
        if (isset($options['single_model'])) {
            $out['single_model'] = $options['single_model'];
        }
        return $out;
    }

    /**
     * Was this session a single-model run?
     *
     * Two independent signals, because either can be the only survivor:
     *
     *   - the stored verdict / the recorded run mode (`consensus_status =
     *     'single'`, `options_json.single_model`) — the durable record of the
     *     decision;
     *   - the journal: both L3 and L4 exist and are `skipped` while L1 answered.
     *     A skipped L3 is an explicit "no consensus was computed" and cannot be
     *     produced by a crashed run (a crash leaves `running`, which the engine
     *     and this planner both terminalise as `failed`).
     *
     * Neither signal schedules an L3. That is the point: without this check the
     * planner sees a session with no L3 step and marks one required, and the
     * rebuild would score a single answer against nothing — recreating exactly
     * the trivial 1.0 / agreement that a single-model run exists to avoid.
     *
     * @param  array<string,mixed>            $session
     * @param  array<string,mixed>            $options
     * @param  array<int,array<string,mixed>> $steps
     */
    private function singleModelRun(array $session, array $options, array $steps): bool
    {
        if (strtolower(trim((string)($session['consensus_status'] ?? ''))) === 'single') {
            return true;
        }
        foreach (['single_model', 'run_mode'] as $key) {
            $value = $options[$key] ?? null;
            if ($value === true || $value === 1 || $value === '1') {
                return true;
            }
            if (is_string($value) && strtolower(trim($value)) === 'single_model') {
                return true;
            }
        }

        $l1Answered = false;
        $l3Skipped  = false;
        $l4Skipped  = false;
        foreach ($steps as $step) {
            if ($this->roundOf($step) !== 1) { continue; }
            $layer  = $this->layerOf($step);
            $status = strtolower((string)($step['status'] ?? ''));
            if ($layer === 'L1' && $status === 'completed') { $l1Answered = true; }
            if ($layer === 'L3' && $status === 'skipped')      { $l3Skipped  = true; }
            if ($layer === 'L4' && $status === 'skipped')      { $l4Skipped  = true; }
        }
        return $l1Answered && $l3Skipped && $l4Skipped;
    }

    /**
     * @param array<string,mixed> $session
     * @param array<int,array<string,mixed>> $steps
     */
    private function cycleLimitFromEvents(array $session, array $steps): ?int
    {
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            return null;
        }
        try {
            $events = $this->steps->listEventsBySession($sessionId);
        } catch (\Throwable) {
            return null;
        }
        foreach (array_reverse($events) as $event) {
            if ((string)($event['event_type'] ?? '') !== 'L0_OPTIONS') { continue; }
            $payload = $this->decodeJson($event['payload'] ?? null);
            $limit   = $payload['cycle_limit'] ?? null;
            if (is_int($limit) || (is_string($limit) && is_numeric($limit))) {
                return (int)$limit;
            }
        }
        return null;
    }

    /**
     * Ordered list of models the session was originally run with.
     *
     * @param  array<string,mixed> $options
     * @param  array<string,array{provider:string,model:string,status:string}> $seen
     * @param  array<int,string> $warnings
     * @return array<int,array{provider:string,model:string}>
     */
    private function wantedModels(array $options, array $seen, array &$warnings): array
    {
        $out = [];
        $mark = [];
        $models = $options['selected_models'] ?? null;
        if (is_array($models)) {
            foreach ($models as $entry) {
                if (!is_array($entry)) { continue; }
                $p = strtolower(trim((string)($entry['provider'] ?? '')));
                $m = trim((string)($entry['model'] ?? ''));
                if ($p === '' || $m === '') { continue; }
                $key = $p . '|' . $m;
                if (isset($mark[$key])) { continue; }
                $mark[$key] = true;
                $out[] = ['provider' => $p, 'model' => $m];
            }
        }
        if ($out !== []) {
            return $out;
        }
        // Legacy session: reproduce the original order from the journal —
        // step order_index preserves the participant index of the run.
        foreach ($seen as $key => $row) {
            unset($key);
            $out[] = ['provider' => $row['provider'], 'model' => $row['model']];
        }
        if ($out !== []) {
            $warnings[] = 'The originally selected models were rebuilt from the step journal.';
        }
        return $out;
    }

    /**
     * Token total already attributable to the session.
     *
     * Counts one model call per completed L1/L4 step (the earliest
     * response row): L2 re-records the very same L1 response together with
     * its normalised payload, so naively summing every row would double
     * count every L1 answer.
     *
     * @param array<int,array<string,mixed>>            $steps
     * @param array<int,array<int,array<string,mixed>>> $responses
     */
    private function aggregateExistingTokens(array $steps, array $responses): int
    {
        $total = 0;
        foreach ($steps as $step) {
            if ((string)($step['status'] ?? '') !== 'completed') { continue; }
            $layer = $this->layerOf($step);
            if ($layer !== 'L1' && $layer !== 'L4') { continue; }
            $rows = $responses[(int)($step['id'] ?? 0)] ?? [];
            if ($rows === []) { continue; }
            $total += max(0, (int)($rows[0]['total_tokens'] ?? 0));
        }
        return $total;
    }

    /**
     * Resolve the skill the interrupted run used.
     *
     * Preferred order: the stored `skill_id`, then the denormalised
     * `skill_slug`, then the bundled default consensus skill. The last
     * step keeps a session finishable after its custom skill was deleted
     * from the catalog — the L1/L2/L3 results are already stored, so only
     * the L4 synthesis prompt needs *a* skill.
     *
     * @param  array<string,mixed> $session
     * @param  array<int,string>   $warnings
     */
    private function resolveSkill(array $session, array &$warnings): ?Skill
    {
        $slug = trim((string)($session['skill_slug'] ?? ''));
        $id   = (int)($session['skill_id'] ?? 0);
        try {
            if ($id > 0) {
                $skill = $this->skills->findById($id);
                if ($skill !== null) { return $skill; }
            }
            if ($slug !== '') {
                $skill = $this->skills->findBySlug($slug);
                if ($skill !== null) { return $skill; }
            }
            $fallback = $this->skills->findBySlug(self::FALLBACK_SKILL_SLUG);
            if ($fallback !== null) {
                $warnings[] = 'The skill this session ran with (' . ($slug !== '' ? $slug : '#' . $id)
                    . ') is no longer in the catalog — the bundled "' . self::FALLBACK_SKILL_SLUG
                    . '" skill is used for synthesis instead.';
                return $fallback;
            }
        } catch (\Throwable $e) {
            Logger::warn('session_repair.skill_lookup_failed', ['message' => $e->getMessage()]);
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Row helpers
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $step */
    private function layerOf(array $step): string
    {
        $meta  = $this->metaOf($step);
        $layer = (string)($meta['layer'] ?? '');
        if ($layer !== '') { return $layer; }
        $key = (string)($step['step_key'] ?? '');
        $head = explode('.', $key)[0] ?? '';
        return $head;
    }

    /** @param array<string,mixed> $step */
    private function roundOf(array $step): int
    {
        $meta = $this->metaOf($step);
        return max(1, (int)($meta['round'] ?? 1));
    }

    /**
     * @param  array<string,mixed> $step
     * @return array<string,mixed>
     */
    private function metaOf(array $step): array
    {
        return $this->decodeJson($step['meta'] ?? null) ?? [];
    }

    /**
     * @param  array<string,mixed> $step
     * @param  array<string,mixed> $row
     */
    private function roleKeyOf(array $step, array $row): string
    {
        $meta = $this->metaOf($step);
        $role = trim((string)($meta['role'] ?? ''));
        if ($role !== '') { return $role; }
        $role = trim((string)($row['role_name'] ?? ''));
        if ($role !== '') { return $role; }
        // Fall back to the step key: `L1.role.<roleKey>` (or the
        // round-2 variant `L1.round2.role.<roleKey>`).
        $key = (string)($step['step_key'] ?? '');
        if (preg_match('/^L1\.(?:round\d+\.)?role\.(.+)$/', $key, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * First response row that carries raw model output.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function firstModelResponse(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            if (trim((string)($row['raw_content'] ?? '')) !== '') { return $row; }
        }
        return null;
    }

    /**
     * Decoded `normalized_response` payload recorded by L2 on an L1 step.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function normalizedPayload(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $decoded = $this->decodeJson($row['normalized_response'] ?? null);
            if (is_array($decoded) && isset($decoded['claims']) && is_array($decoded['claims'])) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Rebuild an immutable ChatResponse from a stored response row.
     *
     * @param  array<string,mixed> $row
     */
    private function rebuildChatResponse(array $row): ?ChatResponse
    {
        $content = (string)($row['raw_content'] ?? '');
        $model   = trim((string)($row['model'] ?? ''));
        $provider= trim((string)($row['provider'] ?? ''));
        if ($content === '' || $model === '' || $provider === '') {
            return null;
        }
        $prompt = max(0, (int)($row['prompt_tokens'] ?? 0));
        $done   = max(0, (int)($row['completion_tokens'] ?? 0));
        $total  = max(0, (int)($row['total_tokens'] ?? 0));
        if ($total !== $prompt + $done) {
            // ChatResponse enforces the invariant; stored legacy rows may
            // disagree with it, so reconcile rather than throw.
            $total = $prompt + $done;
        }
        try {
            return new ChatResponse(
                content: $content,
                model: $model,
                provider: strtolower($provider),
                promptTokens: $prompt,
                completionTokens: $done,
                totalTokens: $total,
                latencyMs: max(0.0, (float)($row['latency_ms'] ?? 0)),
                rawResponse: [],
                tokensEstimated: (int)($row['tokens_estimated'] ?? 0) === 1,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed> $payload
     */
    private function rebuildConsensus(array $payload): ?ConsensusResult
    {
        $status = strtolower(trim((string)($payload['status'] ?? '')));
        if (!in_array($status, ['agreement', 'partial', 'conflict'], true)) {
            return null;
        }
        if (!array_key_exists('score', $payload) || !is_numeric($payload['score'])) {
            return null;
        }
        $score = (float)$payload['score'];
        $score = max(0.0, min(1.0, $score));
        $meta  = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        try {
            return new ConsensusResult($score, $status, (string)($payload['summary'] ?? ''), $meta);
        } catch (\Throwable) {
            return null;
        }
    }

    private function decodeJson(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{body:array<string,mixed>,status:int}
     */
    private function respond(array $body, int $status): array
    {
        return ['body' => $body, 'status' => $status];
    }
}
