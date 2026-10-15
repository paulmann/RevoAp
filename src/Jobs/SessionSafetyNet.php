<?php
/**
 * SessionSafetyNet: close a run's session when nothing else did.
 *
 * Every job kind owns a council session - JobService creates the row up front
 * so the session endpoints resolve while the run works. The pipeline engine
 * closes the ones it drives, the models actualization closes its own, and the
 * rest were simply left: the queue row reached a terminal state, the session
 * stayed `running` for ever, and History kept showing a green "running" with no
 * steps and no answer. Ten such rows had accumulated on the production host.
 *
 * This net runs after a job ends, in the worker, and closes exactly the
 * sessions nobody else closed:
 *
 *   - only when the session is still `running` AND carries no final answer, so
 *     a real answer written by an engine is never overwritten;
 *   - only for a terminal job status, so a requeued job keeps its session;
 *   - best effort: a session row that cannot be updated is logged and never
 *     turns a finished job into a failed one.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-28
 */
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Logger;
use App\Storage\SessionRepository;

final class SessionSafetyNet
{
    /**
     * The Final answer written for a run that ended without one. Kept short and
     * factual: it says what happened, not what the run was about.
     */
    public static function answerFor(Job $job, JobStatus $status): string
    {
        $line = match ($status) {
            JobStatus::Completed => 'The run finished, but it did not write a final answer.',
            JobStatus::Cancelled => 'The run was cancelled before it wrote a final answer.',
            default              => 'The run ended as `' . $status->value . '` without writing a final answer.',
        };

        return "# Run closed by the worker\n\n"
            . '**Result: ' . $status->value . ".**\n\n"
            . $line . "\n\n"
            . 'This session was closed automatically so History does not keep it `running` for ever. '
            . 'Nothing was rewritten: the session had no answer of its own.' . "\n";
    }

    /**
     * Close the session of a job that ended, when the session still has no
     * answer.
     *
     * @param callable(int):array{status:string,answer:?string}|null $readSession
     *        Returns the session row's status and final answer, or null.
     * @return bool True when this call closed the session.
     */
    public static function closeIfOrphaned(
        Job $job,
        JobStatus $status,
        ?SessionRepository $sessions,
        ?callable $readSession = null,
    ): bool {
        if ($sessions === null || $job->sessionId <= 0) {
            return false;
        }
        if (!in_array($status, [JobStatus::Completed, JobStatus::Failed, JobStatus::Cancelled], true)) {
            return false;
        }

        try {
            if ($readSession !== null) {
                $row = $readSession($job->sessionId);
                if (!is_array($row)) {
                    return false;
                }
                if (($row['status'] ?? '') !== 'running') {
                    return false;
                }
                if (trim((string)($row['answer'] ?? '')) !== '') {
                    // An engine wrote a real answer - leave it alone.
                    return false;
                }
            }

            $sessions->complete(
                id:              $job->sessionId,
                finalAnswer:     self::answerFor($job, $status),
                consensusScore:  null,
                // 'none', NOT '': consensus_status is an ENUM and '' is not
                // a member. A strict-mode MySQL/MariaDB refused the whole
                // UPDATE (error 1265), this method caught it, and the
                // orphaned session stayed 'running' forever — the safety net
                // silently failing at the one job it exists for. 'none'
                // means "closed without a verdict, and none is owed"
                // (migration 025; see db/schema.sql).
                consensusStatus: 'none',
                totalTokens:     0,
                durationMs:      0,
                status:          $status === JobStatus::Completed ? 'completed' : 'failed',
            );

            Logger::info('worker.session.closed_by_safety_net', [
                'job_id'     => $job->id,
                'session_id' => $job->sessionId,
                'status'     => $status->value,
            ]);
            return true;
        } catch (\Throwable $e) {
            Logger::warn('worker.session.safety_net_failed', [
                'job_id'     => $job->id,
                'session_id' => $job->sessionId,
                'error'      => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return false;
        }
    }
}