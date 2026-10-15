<?php
/**
 * WorkerJobExecutor: runs one acquired job so that a database outage
 * does not destroy it.
 *
 * The failure this class exists for (production incident, MySQL
 * restarted under a long-lived worker):
 *
 *   1. `systemctl restart mysql` kills every pooled connection.
 *   2. The worker keeps running, holds a dead handle, and its presence
 *      beacon keeps beating, so the UI stays green.
 *   3. Every acquired job dies ~5s later with
 *      `SQLSTATE[HY000] 2006 MySQL server has gone away`, is written to
 *      the row as `failed`, and the session is left `running` with
 *      `tokens: 0` and not a single persisted event.
 *   4. Only a manual worker restart helps.
 *
 * The contract implemented here, in order:
 *
 *   1. BEFORE the job: verify the connection and reconnect if it is
 *      dead ({@see Database::ensureConnected()}). A server that is
 *      unreachable this early is *not* worth booting the engine for —
 *      the job goes straight back to the queue instead of burning five
 *      seconds to produce an empty run.
 *   2. DURING the job: a "connection lost" error is an infrastructure
 *      fault, not a job failure. Reconnect and re-run the job once, in
 *      this same loop iteration.
 *   3. If the retry loses the connection again, or a reconnect is
 *      impossible, return the job to the queue WITHOUT spending its
 *      retry budget (`attempts` covers MODEL failures — see
 *      {@see JobRepository::requeueForInfrastructure()}) and schedule it
 *      `$requeueDelaySeconds` in the future so a genuinely dead database
 *      cannot be hammered in a tight loop.
 *   4. Anything that is NOT a connection error propagates unchanged, so
 *      ordinary failures keep their existing handling (attempts
 *      bookkeeping, `error_json`, `failed` status).
 *
 * The executor is deliberately free of logging: it reports what happened
 * through its return value, and the caller (the CLI worker) decides how
 * to record it.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use App\Core\Logger;
use PDOException;
use Throwable;

final class WorkerJobExecutor
{
    /**
     * Default delay before a requeued job becomes pickable again. Long
     * enough to let a restarting database finish coming up, short enough
     * that a transient blip is invisible to the user.
     */
    public const DEFAULT_REQUEUE_DELAY_SECONDS = 15;

    /**
     * Attempts allowed AFTER a reconnect. One extra attempt is the
     * contract: a second consecutive loss means the outage is ongoing,
     * and the queue is a better place for the work than this process.
     */
    public const DEFAULT_MAX_RECONNECT_RETRIES = 1;

    /** Machine-readable cause written to the requeued row. */
    public const REASON_DATABASE_UNAVAILABLE = 'database_unavailable';

    public function __construct(
        private readonly JobRepository    $jobs,
        private readonly JobRunnerInterface $runner,
        private readonly ?Database        $db = null,
        private readonly int              $requeueDelaySeconds = self::DEFAULT_REQUEUE_DELAY_SECONDS,
        private readonly int              $maxReconnectRetries = self::DEFAULT_MAX_RECONNECT_RETRIES,
    ) {}

    /**
     * Execute one job, absorbing database connection faults.
     *
     * @return array{
     *     status:?JobStatus,
     *     requeued:bool,
     *     retried:bool,
     *     reconnects:int,
     *     losses:int,
     *     requeue_delay_s:int,
     *     infrastructure_error:?string
     * } `status` is null when the job was handed back to the queue
     *   (it never reached a terminal state). `reconnects`/`losses` are
     *   deltas attributable to this job.
     *
     * @throws Throwable Any error that is not a lost database connection,
     *                   unchanged and unwrapped.
     */
    public function execute(Job $job, string $workerId): array
    {
        $reconnectsBefore = $this->db?->reconnects() ?? 0;
        $lossesBefore     = $this->db?->losses() ?? 0;
        $retries          = 0;
        $retried          = false;

        while (true) {
            // 1. Pre-flight. Also the only place a dead handle is
            //    repaired before it can poison the engine's first write.
            if ($this->db !== null && !$this->db->ensureConnected()) {
                return $this->requeue(
                    job:      $job,
                    class:    'DatabaseUnavailable',
                    message:  'Database connection unavailable before the job started.',
                    retried:  $retried,
                    reconnectsBefore: $reconnectsBefore,
                    lossesBefore:     $lossesBefore,
                );
            }

            try {
                $status = $this->runner->run($job, $workerId);
                return [
                    'status'               => $status,
                    'requeued'             => false,
                    'retried'              => $retried,
                    'reconnects'           => ($this->db?->reconnects() ?? 0) - $reconnectsBefore,
                    'losses'               => ($this->db?->losses() ?? 0) - $lossesBefore,
                    'requeue_delay_s'      => 0,
                    'infrastructure_error' => null,
                ];
            } catch (Throwable $e) {
                // 4. Ordinary failure: leave it completely alone.
                if (!self::isConnectionLoss($e)) {
                    throw $e;
                }
                // 2. One retry on a fresh connection.
                if ($retries < $this->maxReconnectRetries && $this->reconnect($e)) {
                    $retries++;
                    $retried = true;
                    continue;
                }
                // 3. Give it back to the queue, retry budget intact.
                return $this->requeue(
                    job:      $job,
                    class:    (new \ReflectionClass($e))->getShortName(),
                    message:  $e->getMessage(),
                    retried:  $retried,
                    reconnectsBefore: $reconnectsBefore,
                    lossesBefore:     $lossesBefore,
                );
            }
        }
    }

    /**
     * Is this throwable a database-connection fault we must not treat as
     * a job failure?
     *
     * Both flavours qualify: an established connection that went away
     * (`2006` / `1053` / `08S01`) AND a server that could not be reached
     * at all (`2002` / `2003`). Neither says anything about the job, so
     * neither may be allowed to burn work.
     *
     * Kept public+static so the worker and the offline tests classify
     * errors exactly the way the executor does.
     */
    public static function isConnectionLoss(Throwable $e): bool
    {
        return $e instanceof PDOException && Database::isConnectionError($e);
    }

    /** Force a fresh connection. Returns false when the server is still down. */
    private function reconnect(PDOException $cause): bool
    {
        if ($this->db === null) {
            return false;
        }
        return $this->db->reconnect($cause);
    }

    /**
     * Hand the job back to the queue without consuming its retry budget.
     *
     * @return array{
     *     status:?JobStatus,requeued:bool,retried:bool,reconnects:int,losses:int,
     *     requeue_delay_s:int,infrastructure_error:?string
     * }
     */
    private function requeue(
        Job $job,
        string $class,
        string $message,
        bool $retried,
        int $reconnectsBefore,
        int $lossesBefore,
    ): array {
        $safeMessage = Logger::sanitizeScalar($message);
        $requeued    = false;
        $failure     = null;
        try {
            $requeued = $this->jobs->requeueForInfrastructure(
                $job->id,
                self::REASON_DATABASE_UNAVAILABLE,
                $this->requeueDelaySeconds,
                ['message' => $safeMessage, 'class' => $class],
            );
        } catch (Throwable $writeError) {
            // The database is unreachable, so the requeue itself cannot
            // be written. The row stays `running` with a stale heartbeat
            // and the worker's auto-recovery sweep will pick it up. Never
            // propagate: the worker must stay alive.
            $failure = Logger::sanitizeScalar($writeError->getMessage());
        }

        return [
            'status'               => null,
            'requeued'             => $requeued,
            'retried'              => $retried,
            'reconnects'           => ($this->db?->reconnects() ?? 0) - $reconnectsBefore,
            'losses'               => ($this->db?->losses() ?? 0) - $lossesBefore,
            'requeue_delay_s'      => $this->requeueDelaySeconds,
            'infrastructure_error' => $failure === null ? $safeMessage : $safeMessage . ' (requeue write failed: ' . $failure . ')',
        ];
    }
}
