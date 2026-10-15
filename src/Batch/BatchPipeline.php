<?php
/**
 * BatchPipeline: the resumable, non-blocking Batch API run.
 *
 * `payload.kind = 'batch_pipeline'` routes a job here instead of into the
 * synchronous engine. The job is a durable little state machine whose every
 * phase is a function of the row it was handed — see {@see BatchState} for
 * the state and why it lives in the database.
 *
 * ## Why the worker is never blocked
 *
 * A batch takes minutes to 24 hours. A worker that slept on it would be
 * useless for every other job in the queue, and a worker that polled it in
 * a tight loop would hammer the provider. So the run *parks* instead:
 * {@see JobRepository::parkUntil()} puts the row back in `queued` with
 * `next_run_at` set to the next poll — 30 s for batches up to 1000 rows,
 * 150 s above that, the documentation's own sizing rule — and this method
 * returns. The worker's poll loop moves on to other work; the row comes
 * back when it is due, possibly in a different process.
 *
 * ## What survives a restart, and how
 *
 * Everything. Each phase writes its state through
 * {@see JobRepository::recordResult()} *before* it returns or parks, and
 * each phase reads it back from the job row. So:
 *
 *   * `systemctl restart revoap-worker` between polls: the row is `queued`
 *     with a future `next_run_at`; the new worker picks it up when due and
 *     continues at `poll`.
 *   * a crash between the upload and the create: the state still has no
 *     `batch_id`, so {@see BatchState::action()} says `prepare` and the
 *     document is rebuilt — same participants, same `custom_id`s, same
 *     steps — and submitted.
 *   * a crash during the accept: the state says `accepting` with the
 *     output file id, so the download is simply repeated (a GET), and the
 *     per-step write-back skips participants whose step already carries a
 *     response, which is what makes it idempotent.
 *   * `--once` mode: exactly the same, because parking is a row state and
 *     not a loop state.
 *
 * ## What is submitted, and what is not
 *
 * Only L1 goes through the Batch API. L2 (normalisation), L3 (the verdict)
 * and L4 (synthesis) all consume L1's answers and therefore cannot be
 * expressed before them — they run synchronously after the results are
 * accepted, in the same invocation, through the same engine entry point a
 * repaired session uses. Cancelling a batch after `completed` is therefore
 * not instantaneous: the accept-and-continue stage is ordinary synchronous
 * work, and the job honours a cancel request at the phase boundary it next
 * reaches.
 *
 * ## Per-model failures never sink the run
 *
 * Three kinds of per-participant failure reach the accept phase and all
 * three are recorded against their own participant and step — never as a
 * run-level failure:
 *
 *   * a line the provider refused (`response.status_code` >= 400 inside an
 *     otherwise `completed` batch — rate limits, moderation);
 *   * a line the builder refused before upload (a malformed body);
 *   * a `custom_id` that never came back at all.
 *
 * The run is failed as a whole only when there is nothing left to build an
 * answer from, or when the batch itself failed / could not be read.
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

use App\Connectors\Batch\BatchCustomId;
use App\Connectors\Batch\BatchJsonlBuilder;
use App\Connectors\Batch\BatchLifecycle;
use App\Connectors\Batch\BatchModelCatalog;
use App\Connectors\Batch\BatchResultParser;
use App\Connectors\Batch\BatchResultReport;
use App\Connectors\Batch\BothubBatchClient;
use App\Connectors\ChatResponse;
use App\Core\Logger;
use App\Jobs\Job;
use App\Jobs\JobRepository;

final class BatchPipeline
{
    /**
     * Payload marker that routes a job to this pipeline. Mirrors
     * {@see \App\Models\ModelsActualizer::KIND}: a non-pipeline job kind,
     * recognised before the pipeline payload contract is enforced.
     */
    public const KIND = 'batch_pipeline';

    /** `recovery_reason` written when a phase parks the row. */
    public const PARK_REASON_POLL = 'batch_poll_wait';

    /** Delay before a freshly submitted batch is first polled. */
    public const FIRST_POLL_DELAY_SECONDS = 30;

    /** Delay granted to the accept phase so it starts in a clean invocation. */
    public const ACCEPT_DELAY_SECONDS = 1;

    /** First N participants accepted, quoted in the job report. */
    public const REPORT_SAMPLE_SIZE = 5;

    public function __construct(
        private readonly JobRepository            $jobs,
        private readonly BatchL1PlannerInterface  $planner,
        private readonly BothubBatchClient        $client,
    ) {}

    /**
     * Is this job payload a batch run?
     *
     * @param array<string,mixed> $payload
     */
    public static function isBatchPayload(array $payload): bool
    {
        return ($payload['kind'] ?? null) === self::KIND;
    }

    /**
     * Advance the run by one phase.
     *
     * @param null|callable():void $heartbeat Called at each phase transition
     *        so the queue row stays fresh through a long accept phase.
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     *         `status` is the job status the caller should return.
     */
    public function run(Job $job, string $workerId, ?callable $heartbeat = null): array
    {
        $state = BatchState::fromJobResult($job->result);
        $beat  = static function () use ($heartbeat): void {
            if ($heartbeat === null) { return; }
            try { $heartbeat(); } catch (\Throwable) {}
        };

        $action = $state->action();
        $this->log('batch.phase', $job, $workerId, [
            'phase'  => $state->phase(),
            'action' => $action,
            'batch_id' => $state->batchId(),
        ]);

        return match ($action) {
            BatchState::ACTION_CANCEL  => $this->cancel($job, $workerId, $state, $beat),
            BatchState::ACTION_ACCEPT  => $this->accept($job, $workerId, $state, $beat),
            BatchState::ACTION_POLL    => $this->poll($job, $workerId, $state, $beat),
            BatchState::ACTION_DONE    => $this->alreadyDone($job, $state),
            default                    => $this->prepare($job, $workerId, $state, $beat),
        };
    }

    // -----------------------------------------------------------------
    // Phase: prepare → upload → create
    // -----------------------------------------------------------------

    /**
     * Build the L1 fan-out, upload the document, create the batch, park.
     *
     * All three steps happen in one invocation on purpose: the request
     * bodies exist only in this window (see {@see BatchState}), and spread
     * across three park/pickup cycles they would have to be persisted to
     * survive, which is exactly what is being avoided.
     *
     * @param callable():void $beat
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     */
    private function prepare(Job $job, string $workerId, BatchState $state, callable $beat): array
    {
        $payload = $job->payload;
        $query   = (string)($payload['query'] ?? '');
        $options = is_array($payload['batch_options'] ?? null) ? $payload['batch_options'] : [];
        $models  = is_array($payload['selected_models'] ?? null) ? $payload['selected_models'] : null;

        $plan = $this->planner->prepareBatchL1($job->sessionId, $query, $models, $options, $job->id);
        $beat();
        if (($plan['ok'] ?? false) !== true) {
            return $this->failJob(
                $job,
                'batch_prepare_failed',
                (string)($plan['reason'] ?? 'The batch L1 fan-out could not be prepared.'),
                $state->with([
                    'phase' => BatchState::PHASE_NEW,
                    'error' => (string)($plan['reason'] ?? 'batch prepare failed'),
                ]),
                $workerId,
            );
        }

        $participants = is_array($plan['participants'] ?? null) ? $plan['participants'] : [];
        if ($participants === []) {
            return $this->failJob(
                $job,
                'batch_empty',
                'No participant could be expressed as a batch request.',
                $state->with(['phase' => BatchState::PHASE_NEW, 'error' => 'no batchable participants']),
                $workerId,
            );
        }

        $entries = [];
        foreach ($participants as $p) {
            $entries[] = BatchJsonlBuilder::entry((string)$p['custom_id'], (array)$p['body']);
        }
        $document = BatchJsonlBuilder::build($entries);
        if ($document->isEmpty()) {
            return $this->failJob(
                $job,
                'batch_document_invalid',
                'Every batch line was refused by local validation, so nothing was uploaded. First reason: '
                    . (string)($document->rejected[0]['reason'] ?? 'unknown'),
                $state->with([
                    'phase'          => BatchState::PHASE_NEW,
                    'rejected_lines' => $document->rejected,
                    'error'          => 'local JSONL validation refused every line',
                ]),
                $workerId,
            );
        }

        // Participants whose line was refused never get submitted, but they
        // still exist as steps. They are carried in the state so the accept
        // phase can mark them failed with the validation reason instead of
        // leaving them running forever.
        $rejectedIds = [];
        foreach ($document->rejected as $row) {
            $rejectedIds[(string)$row['custom_id']] = (string)$row['reason'];
        }
        $submittedIds = array_flip($document->customIds());
        $keptParticipants = [];
        foreach ($participants as $p) {
            $id = (string)$p['custom_id'];
            if (isset($submittedIds[$id])) {
                $keptParticipants[] = $p;
            }
        }

        $state = $state->with([
            'phase'          => BatchState::PHASE_PREPARED,
            'participants'   => array_map(static fn(array $p): array => [
                'custom_id' => (string)$p['custom_id'],
                'step_id'   => (int)($p['step_id'] ?? 0),
                'provider'  => (string)($p['provider'] ?? ''),
                'model'     => (string)($p['model'] ?? ''),
                'role'      => (string)($p['role'] ?? ''),
            ], $keptParticipants),
            'rejected_lines' => $document->rejected,
            'rejected_reasons' => $rejectedIds,
            'request_count'  => $document->lineCount,
            'prepared_at'    => gmdate('Y-m-d\TH:i:s\Z'),
            'document_bytes' => $document->byteSize(),
            'pool'           => is_array($plan['pool'] ?? null) ? $plan['pool'] : [],
            'skipped'        => is_array($plan['skipped'] ?? null) ? $plan['skipped'] : [],
            'error'          => '',
        ]);
        $this->persist($job, $state);

        // --- upload ---------------------------------------------------
        try {
            $upload = $this->client->uploadInputFile($document, BatchJsonlBuilder::FILENAME);
        } catch (\Throwable $e) {
            return $this->failJob(
                $job,
                (new \ReflectionClass($e))->getShortName(),
                $e->getMessage(),
                $state->with(['error' => $e->getMessage()]),
                $workerId,
            );
        }
        $beat();
        $state = $state->with([
            'phase'         => BatchState::PHASE_UPLOADED,
            'input_file_id' => (string)$upload['id'],
            'upload_field'  => (string)($upload['field'] ?? ''),
            'uploaded_at'   => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->persist($job, $state);

        // --- create ---------------------------------------------------
        try {
            $batch = $this->client->createBatch((string)$upload['id']);
        } catch (\Throwable $e) {
            return $this->failJob(
                $job,
                (new \ReflectionClass($e))->getShortName(),
                $e->getMessage(),
                $state->with(['error' => $e->getMessage()]),
                $workerId,
            );
        }
        $beat();

        $delay = BatchLifecycle::pollDelaySeconds($document->lineCount);
        $state = $state->with([
            'phase'        => BatchState::PHASE_SUBMITTED,
            'batch_id'     => (string)$batch['id'],
            'status'       => (string)($batch['status'] ?? BatchLifecycle::VALIDATING),
            'polls'        => 0,
            'poll_failures'=> 0,
            'submitted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'next_poll_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delay),
            'error'        => '',
        ]);
        $this->persist($job, $state);
        $this->park($job, $delay, $state);

        $this->log('batch.submitted', $job, $workerId, [
            'batch_id'      => $state->batchId(),
            'input_file_id' => $state->inputFileId(),
            'requests'      => $document->lineCount,
            'rejected'      => count($document->rejected),
            'upload_field'  => (string)$upload['field'],
        ]);

        return $this->parkedResult($state, $delay);
    }

    // -----------------------------------------------------------------
    // Phase: poll
    // -----------------------------------------------------------------

    /**
     * Ask the provider for the batch status and park again.
     *
     * A poll that cannot be read is not a failure: the batch is still the
     * provider's problem, and the next attempt costs one GET. Only after
     * {@see BatchState::MAX_POLL_FAILURES} consecutive unreadable polls —
     * with an exponential-ish backoff between them — is the run failed,
     * because at that point "we cannot see it" has stopped being plausible
     * as a network blip.
     *
     * @param callable():void $beat
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     */
    private function poll(Job $job, string $workerId, BatchState $state, callable $beat): array
    {
        $snapshot = $this->client->getBatch($state->batchId());
        $beat();
        $baseDelay = BatchLifecycle::pollDelaySeconds($state->requestCount());

        if (($snapshot['ok'] ?? false) !== true) {
            $failures = $state->pollFailures() + 1;
            $failed   = $state->with([
                'polls'         => $state->polls() + 1,
                'poll_failures' => $failures,
                'status'        => BatchLifecycle::UNKNOWN,
                'error'         => (string)($snapshot['error'] ?? 'poll failed'),
            ]);
            $this->persist($job, $failed);
            if ($failed->pollFailuresExhausted()) {
                return $this->parkedResult($failed, $baseDelay, terminal: true, error:
                    sprintf(
                        'The batch status could not be read %d times in a row; last error: %s',
                        $failures,
                        (string)($snapshot['error'] ?? 'unknown'),
                    )
                );
            }
            $delay = $failed->nextPollDelaySeconds($baseDelay);
            $failed = $failed->with(['next_poll_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delay)]);
            $this->persist($job, $failed);
            $this->park($job, $delay, $failed);
            $this->log('batch.poll.unreadable', $job, $workerId, [
                'batch_id' => $state->batchId(),
                'failures' => $failures,
                'delay_s'  => $delay,
                'error'    => (string)($snapshot['error'] ?? ''),
            ]);
            return $this->parkedResult($failed, $delay);
        }

        $status   = (string)($snapshot['status'] ?? BatchLifecycle::UNKNOWN);
        $outputId = (string)($snapshot['output_file_id'] ?? '');
        $next = $state->with([
            'status'         => $status,
            'output_file_id' => $outputId,
            'error_file_id'  => (string)($snapshot['error_file_id'] ?? ''),
            'polls'          => $state->polls() + 1,
            'poll_failures'  => 0,
            'last_poll_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'request_counts' => $snapshot['request_counts'] ?? [],
            'error'          => '',
        ]);

        $this->log('batch.poll', $job, $workerId, [
            'batch_id'  => $state->batchId(),
            'status'    => $status,
            'polls'     => $next->polls(),
            'completed' => (int)($snapshot['request_counts']['completed'] ?? 0),
            'failed'    => (int)($snapshot['request_counts']['failed'] ?? 0),
        ]);

        if (BatchLifecycle::isTerminal($status)) {
            // `failed` / `cancelled` are verdicts, not polls: hand over to
            // the accept phase in the very next invocation so the provider's
            // own reason is read from the same snapshot the verdict came
            // from, and no further polling can overwrite it.
            $next = $next->with([
                'phase'        => BatchState::PHASE_ACCEPTING,
                'terminal_at'  => gmdate('Y-m-d\TH:i:s\Z'),
                'failure_reason' => $status === BatchLifecycle::COMPLETED
                    ? ''
                    : BatchLifecycle::failureReason((array)($snapshot['raw'] ?? [])),
            ]);
            $this->persist($job, $next);
            $delay = self::ACCEPT_DELAY_SECONDS;
            $this->park($job, $delay, $next);
            return $this->parkedResult($next, $delay);
        }

        $delay = $baseDelay;
        $next  = $next->with(['next_poll_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delay)]);
        $this->persist($job, $next);
        $this->park($job, $delay, $next);
        return $this->parkedResult($next, $delay);
    }

    // -----------------------------------------------------------------
    // Phase: accept → write back → continue
    // -----------------------------------------------------------------

    /**
     * Download the output document, map it back onto the participants and
     * hand the results to the engine, which continues the pipeline.
     *
     * @param callable():void $beat
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     */
    private function accept(Job $job, string $workerId, BatchState $state, callable $beat): array
    {
        $status  = $state->status();
        $payload = $job->payload;
        $query   = (string)($payload['query'] ?? '');
        $options = is_array($payload['batch_options'] ?? null) ? $payload['batch_options'] : [];

        // A batch the provider failed or cancelled produced no answers we
        // can use. The verdict and its cause go into the job report; the
        // job does NOT end `failed` for a cancellation the operator asked
        // for — that is `cancelled`.
        $cancelledByUs = $state->cancelRequested() || $status === BatchLifecycle::CANCELLED;
        if (!$state->hasOutput() && !BatchLifecycle::hasOutput($status)) {
            $reason = (string)($state->failureReason());
            if ($reason === '') {
                $reason = BatchLifecycle::failureReason($state->data());
            }
            $message = $reason !== ''
                ? sprintf('The provider %s the batch: %s', $status === BatchLifecycle::CANCELLED ? 'cancelled' : 'failed', $reason)
                : sprintf('The provider reported the batch as "%s" with no reason.', $status);
            $final = $state->with(['phase' => BatchState::PHASE_DONE, 'error' => $message]);
            $this->persist($job, $final);
            if ($cancelledByUs) {
                return $this->cancelResult($job, $final, $workerId, $message);
            }
            return $this->failJob($job, 'batch_failed', $message, $final, $workerId);
        }

        $download = $this->client->downloadResult($state->outputFileId());
        $beat();
        if (($download['ok'] ?? false) !== true) {
            // A download failure is retryable (it is a GET), so the run
            // parks in `accepting` rather than dying here. The failure is
            // counted on the same budget as an unreadable poll so a
            // permanently broken file id cannot be retried forever.
            $failures = $state->pollFailures() + 1;
            $stuck = $state->with([
                'phase'         => BatchState::PHASE_ACCEPTING,
                'poll_failures' => $failures,
                'error'         => (string)($download['error'] ?? 'download failed'),
            ]);
            $this->persist($job, $stuck);
            $delay = $stuck->nextPollDelaySeconds(BatchLifecycle::pollDelaySeconds($state->requestCount()));
            if ($stuck->pollFailuresExhausted()) {
                return $this->failJob(
                    $job,
                    'batch_download_failed',
                    sprintf(
                        'The result file could not be downloaded %d times in a row; last error: %s',
                        $failures,
                        (string)($download['error'] ?? 'unknown'),
                    ),
                    $stuck,
                    $workerId,
                );
            }
            $stuck = $stuck->with(['next_poll_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delay)]);
            $this->persist($job, $stuck);
            $this->park($job, $delay, $stuck);
            return $this->parkedResult($stuck, $delay);
        }

        $report = BatchResultParser::parse((string)$download['content'], $state->customIds());
        $accepting = $state->with([
            'phase'         => BatchState::PHASE_ACCEPTING,
            'result'        => $report->toArray(),
            'downloaded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'download_bytes'=> (int)($download['bytes'] ?? 0),
            'download_path' => (string)($download['path'] ?? ''),
            'error'         => '',
        ]);
        $this->persist($job, $accepting);
        $this->log('batch.results.parsed', $job, $workerId, [
            'batch_id' => $state->batchId(),
            'summary'  => $report->summary(),
            'problems' => $report->hasProblems(),
        ]);

        $outcomes = $this->buildOutcomes($state, $report, $cancelledByUs);
        $beat();
        try {
            $completion = $this->planner->completeBatchL1(
                $job->sessionId,
                $query,
                $options,
                $outcomes,
                $state->participants(),
            );
        } catch (\Throwable $e) {
            $failed = $accepting->with([
                'phase' => BatchState::PHASE_DONE,
                'error' => $e->getMessage(),
            ]);
            $this->persist($job, $failed);
            return $this->failJob(
                $job,
                (new \ReflectionClass($e))->getShortName(),
                $e->getMessage(),
                $failed,
                $workerId,
            );
        }
        $beat();

        $answered = (int)($completion['answered'] ?? 0);
        $failedCount = (int)($completion['failed'] ?? 0);
        $envelope = is_array($completion['envelope'] ?? null) ? $completion['envelope'] : [];
        $envelope = array_merge($envelope, $accepting->with([
            'phase'    => BatchState::PHASE_DONE,
            'result'   => $report->toArray(),
            'answered' => $answered,
            'failed_rows' => $failedCount,
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ])->toEnvelope());

        $status = (string)($completion['status'] ?? 'completed');
        if ($cancelledByUs) {
            // The operator cancelled, but the results had already been
            // downloaded and written. Keeping them is strictly better than
            // discarding work the provider already billed for, and the
            // terminal status still says what happened.
            $envelope['batch']['cancel_requested'] = true;
        }
        $this->jobs->recordResult($job->id, $envelope);

        // A batch where NOTHING answered is a failed run: there is no
        // answer to continue the pipeline from, and the engine will have
        // reported that as a run-level failure. One model short of the pool
        // is not: that is a partial result, and the run still completes.
        if ($answered === 0) {
            $this->jobs->updateStatus($job->id, \App\Jobs\JobStatus::Failed);
            $this->log('batch.failed.no_answers', $job, $workerId, [
                'batch_id' => $state->batchId(),
                'summary'  => $report->summary(),
            ]);
            return [
                'status'   => \App\Jobs\JobStatus::Failed->value,
                'phase'    => BatchState::PHASE_DONE,
                'parked'   => false,
                'delay_s'  => 0,
                'envelope' => $envelope,
            ];
        }

        $finalStatus = $cancelledByUs ? \App\Jobs\JobStatus::Cancelled : \App\Jobs\JobStatus::Completed;
        $this->jobs->updateStatus($job->id, $finalStatus);
        $this->log('batch.completed', $job, $workerId, [
            'batch_id'    => $state->batchId(),
            'status'      => $finalStatus->value,
            'answered'    => $answered,
            'failed_rows' => $failedCount,
            'summary'     => $report->summary(),
        ]);

        return [
            'status'   => $finalStatus->value,
            'phase'    => BatchState::PHASE_DONE,
            'parked'   => false,
            'delay_s'  => 0,
            'envelope' => $envelope,
        ];
    }

    /**
     * Map the parsed document and the persisted participant list onto one
     * outcome per participant.
     *
     * This is the join where the "never lose a request silently" rule is
     * enforced end to end. Every participant ends up in the list exactly
     * once, with either a response or a reason:
     *
     *   * answered                → the ChatResponse the engine will record;
     *   * refused by the provider → the per-line reason from the report;
     *   * refused by the builder  → the local validation reason;
     *   * absent from the file    → "no answer arrived", named.
     *
     * @return list<array{custom_id:string,response:?ChatResponse,error:?string}>
     */
    private function buildOutcomes(BatchState $state, BatchResultReport $report, bool $cancelledByUs): array
    {
        $rejectedReasons = $state->rejectedReasons();
        $outcomes = [];
        foreach ($state->participants() as $participant) {
            $customId = $participant['custom_id'];
            $entry    = $report->entry($customId);
            if ($entry === null) {
                $reason = isset($rejectedReasons[$customId])
                    ? sprintf('the request was refused locally before upload: %s', (string)$rejectedReasons[$customId])
                    : ($cancelledByUs
                        ? 'the batch was cancelled before this request was answered'
                        : 'the provider returned no row for this request');
                $outcomes[] = ['custom_id' => $customId, 'response' => null, 'error' => $reason];
                continue;
            }
            $failure = $entry->failureReason();
            if ($failure !== null) {
                $outcomes[] = ['custom_id' => $customId, 'response' => null, 'error' => $failure];
                continue;
            }
            /** @var array<string,mixed> $body */
            $body = (array)$entry->body;
            $outcomes[] = [
                'custom_id' => $customId,
                // The body is turned into a ChatResponse by the planner,
                // through the connector's own buildChatResponseFromRaw(), so
                // token accounting, `actualModel` and the cache store behave
                // exactly as they do on the synchronous path. Passing the
                // raw array keeps this class free of connector knowledge.
                'response'  => null,
                'error'     => null,
                'body'      => $body,
                'status_code' => $entry->statusCode,
            ];
        }
        return $outcomes;
    }

    // -----------------------------------------------------------------
    // Phase: cancel
    // -----------------------------------------------------------------

    /**
     * Tell the provider to stop, then finish the job as `cancelled`.
     *
     * The provider call is attempted once and its outcome is recorded
     * either way. A cancel that the provider refuses (an already-completed
     * batch, a network fault) must still leave the job in a terminal state
     * the operator can see — a run stuck in "cancelling" because the
     * cancel request itself failed is worse than a run that says
     * "cancelled, and the provider said X".
     *
     * @param callable():void $beat
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     */
    private function cancel(Job $job, string $workerId, BatchState $state, callable $beat): array
    {
        if (!$state->hasBatch()) {
            // Nothing was submitted, so there is nothing at the provider to
            // stop — and this is the cheapest possible cancel: the batch is
            // never created, so none of its requests are ever billed.
            $message = 'The batch was cancelled before it was submitted; nothing was sent to the provider.';
            $final = $state->with([
                'phase'            => BatchState::PHASE_DONE,
                'cancelled_at'     => gmdate('Y-m-d\TH:i:s\Z'),
                'error'            => $message,
            ]);
            return $this->cancelResult($job, $final, $workerId, $message);
        }
        $result = $this->client->cancelBatch($state->batchId());
        $beat();
        $message = ($result['ok'] ?? false)
            ? sprintf('Batch %s cancelled at the provider (status "%s").', $state->batchId(), (string)$result['status'])
            : sprintf(
                'The batch cancel request was not accepted by the provider (%s); the batch may still complete.',
                (string)($result['error'] ?? 'unknown error'),
            );
        $final = $state->with([
            'phase'            => BatchState::PHASE_DONE,
            'cancel_requested' => true,
            'cancelled_at'     => gmdate('Y-m-d\TH:i:s\Z'),
            'status'           => (string)($result['status'] ?? $state->status()),
            'error'            => $message,
        ]);
        if (($result['ok'] ?? false) !== true) {
            Logger::warn('batch.cancel.refused', [
                'job_id'   => $job->id,
                'batch_id' => $state->batchId(),
                'error'    => (string)($result['error'] ?? ''),
            ]);
        }
        return $this->cancelResult($job, $final, $workerId, $message);
    }

    /**
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     */
    private function cancelResult(Job $job, BatchState $state, string $workerId, string $message): array
    {
        $envelope = array_merge($this->baseEnvelope($job), $state->toEnvelope());
        $envelope['status'] = 'cancelled';
        $this->jobs->recordResult($job->id, $envelope);
        $this->jobs->updateStatus($job->id, \App\Jobs\JobStatus::Cancelled);
        $this->log('batch.cancelled', $job, $workerId, [
            'batch_id' => $state->batchId(),
            'message'  => $message,
        ]);
        return [
            'status'   => \App\Jobs\JobStatus::Cancelled->value,
            'phase'    => BatchState::PHASE_DONE,
            'parked'   => false,
            'delay_s'  => 0,
            'envelope' => $envelope,
        ];
    }

    // -----------------------------------------------------------------
    // Terminal helpers
    // -----------------------------------------------------------------

    /**
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     */
    private function alreadyDone(Job $job, BatchState $state): array
    {
        return [
            'status'   => $job->status->value,
            'phase'    => BatchState::PHASE_DONE,
            'parked'   => false,
            'delay_s'  => 0,
            'envelope' => $state->toEnvelope(),
        ];
    }

    /**
     * End the run `failed`, with the state that explains why.
     *
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     */
    private function failJob(Job $job, string $class, string $message, BatchState $state, string $workerId): array
    {
        $safe = Logger::sanitizeScalar($message);
        $envelope = array_merge($this->baseEnvelope($job), $state->with([
            'phase' => BatchState::PHASE_DONE,
            'error' => $safe,
        ])->toEnvelope());
        $this->jobs->recordResult($job->id, $envelope);
        $this->jobs->recordError($job->id, [
            'class'   => $class,
            'message' => $safe,
            'stage'   => 'batch',
            'at'      => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->jobs->updateStatus($job->id, \App\Jobs\JobStatus::Failed);
        Logger::error('batch.failed', [
            'job_id'   => $job->id,
            'batch_id' => $state->batchId(),
            'class'    => $class,
            'message'  => $safe,
        ]);
        return [
            'status'   => \App\Jobs\JobStatus::Failed->value,
            'phase'    => BatchState::PHASE_DONE,
            'parked'   => false,
            'delay_s'  => 0,
            'envelope' => $envelope,
        ];
    }

    /**
     * The parked branch: the row is already back in the queue, so the job
     * status reported to the worker is `queued` and no webhook fires.
     *
     * @return array{status:string,phase:string,parked:bool,delay_s:int,envelope:array<string,mixed>}
     */
    private function parkedResult(BatchState $state, int $delay, bool $terminal = false, string $error = ''): array
    {
        if ($terminal) {
            $state = $state->with(['phase' => BatchState::PHASE_DONE, 'error' => $error]);
        }
        return [
            'status'   => $terminal ? \App\Jobs\JobStatus::Failed->value : \App\Jobs\JobStatus::Queued->value,
            'phase'    => $state->phase(),
            'parked'   => !$terminal,
            'delay_s'  => $delay,
            'envelope' => $state->toEnvelope(),
        ];
    }

    /** Persist the phase state so the next pickup — or process — can resume. */
    private function persist(Job $job, BatchState $state): void
    {
        $existing = is_array($job->result) ? $job->result : [];
        $this->jobs->recordResult($job->id, array_merge($this->baseEnvelope($job), $existing, $state->toEnvelope()));
    }

    /** Park the row for `$delay` seconds, recording why. */
    private function park(Job $job, int $delay, BatchState $state): void
    {
        $this->jobs->parkUntil($job->id, $delay, self::PARK_REASON_POLL . ':' . $state->phase());
    }

    /**
     * The keys the UI reads that are not batch state: the session, the mode
     * and the pool, so History can render "awaiting batch" without joining
     * anything.
     *
     * @return array<string,mixed>
     */
    private function baseEnvelope(Job $job): array
    {
        return [
            'session_id' => $job->sessionId,
            'run_mode'   => 'batch',
        ];
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function log(string $event, Job $job, string $workerId, array $extra = []): void
    {
        Logger::info($event, array_merge([
            'job_id'     => $job->id,
            'session_id' => $job->sessionId,
            'worker_id'  => $workerId,
        ], $extra));
    }
}
