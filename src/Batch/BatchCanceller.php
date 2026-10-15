<?php
/**
 * BatchCanceller: record "stop this batch" so a worker actually acts on it.
 *
 * Cancelling a batch is not a status change. A batch runs on the provider,
 * which bills for it whether or not anyone is watching, so a job moved to
 * `cancelled` locally while `POST /batches/{id}/cancel` is never sent is a
 * lie the operator pays for. The cancel request therefore has to survive
 * until a worker can deliver it, and that worker may be asleep.
 *
 * ## Why the generic intent path does not work here
 *
 * {@see \App\Jobs\JobRepository::requestIntent()} records an intent by
 * writing `cancel_requested` as the row's STATUS. For a batch job that has
 * parked itself between polls, the row is `queued` with a future
 * `next_run_at`, and `cancel_requested` is not in the pickable set — so the
 * intent writes the row into a state no worker will ever look at again.
 * The provider keeps running the batch, and the operator sees a job stuck
 * at "cancelling" forever.
 *
 * So this class does three things instead:
 *
 *   1. stamps `cancel_requested` into the job's `result_json`, where the
 *      batch executor reads its state from — that is what makes the next
 *      pickup choose the cancel phase;
 *   2. re-arms a parked row ({@see JobRepository::rearmParked()}), clearing
 *      `next_run_at` so the pickup happens within a second rather than at
 *      the end of a 150-second poll interval;
 *   3. leaves the row's status alone otherwise, because the executor sets it
 *      to `cancelled` itself, *after* the provider has acknowledged.
 *
 * A job a worker is currently holding is stamped but not re-armed: the
 * running phase will park it on its own, and the pickup after that sees the
 * flag. Worst case one poll interval of delay, never a lost cancel.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Batch;

use App\Core\Logger;
use App\Jobs\Job;
use App\Jobs\JobRepository;

final class BatchCanceller
{
    public function __construct(private readonly JobRepository $jobs) {}

    /**
     * Is this job an asynchronous batch run?
     *
     * The check the caller needs before choosing between this path and the
     * generic intent one.
     */
    public static function handles(Job $job): bool
    {
        return BatchPipeline::isBatchPayload($job->payload);
    }

    /**
     * Record the cancel intent and make sure a worker will observe it.
     *
     * @return array{applied:bool,rearmed:bool,message:string}
     *         `applied=false` means the caller should fall back to the
     *         ordinary intent path (this is not a batch job, or it is
     *         already finished).
     */
    public function request(Job $job): array
    {
        if (!self::handles($job)) {
            return ['applied' => false, 'rearmed' => false, 'message' => 'not a batch job'];
        }
        if ($job->status->isTerminal()) {
            return ['applied' => false, 'rearmed' => false, 'message' => 'the job already finished'];
        }

        $state = BatchState::fromJobResult($job->result)->with([
            'cancel_requested'    => true,
            'cancel_requested_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $envelope = array_merge(
            is_array($job->result) ? $job->result : [],
            $state->toEnvelope(),
        );
        $stored  = $this->jobs->recordResult($job->id, $envelope);
        $rearmed = $this->jobs->rearmParked($job->id) !== null;

        Logger::info('batch.cancel.requested', [
            'job_id'   => $job->id,
            'batch_id' => $state->batchId(),
            'stored'   => $stored,
            'rearmed'  => $rearmed,
        ]);

        if (!$stored) {
            return [
                'applied'  => false,
                'rearmed'  => $rearmed,
                'message'  => 'the cancel intent could not be written to the job row',
            ];
        }
        return [
            'applied' => true,
            'rearmed' => $rearmed,
            'message' => $rearmed
                ? 'Cancel requested. A worker will tell the provider to stop at the next opportunity.'
                : 'Cancel requested. The worker holding this run will stop it at its next checkpoint.',
        ];
    }
}
