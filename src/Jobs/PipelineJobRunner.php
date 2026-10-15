<?php
/**
 * PipelineJobRunner: bridge between the durable job layer and the
 * existing synchronous LLM PipelineEngine.
 *
 * Phase 2 (v1.8.36) of Worker Architecture v2. The CLI worker
 * (bin/revoap-worker.php) acquires a row from `revoap_jobs`,
 * hands it to this runner, and the runner reconstructs the call the
 * legacy `/api/council/stream` controller would have made — same
 * options, same SystemPromptAssembler / DomainRouter / RequestAnalyzer
 * wiring, same RunControl checkpoints. The engine creates no new
 * session: it reuses the `session_id` the JobService already created
 * up-front, so /api/council/session/{id}/events/stream replays the
 * worker-produced events without any URL fixup.
 *
 * Control intent propagation (cooperative, not preemptive):
 *
 *   * Before invoking the engine, `pause_requested` / `cancel_requested`
 *     are honored as a no-op transition (the worker never starts).
 *   * The engine polls `RunControl` at its own checkpoints; the runner
 *     mirrors the durable job intents into RunControl so the engine
 *     sees them as a normal user pause / reject / finalize signal.
 *   * After the engine returns, the final status is rebound to the
 *     job (`completed` / `failed` / `cancelled` / `paused`).
 *
 * Heartbeats are emitted lazily inside the engine's per-event hook so
 * a hung remote model cannot make the row look stale. We avoid forking
 * a side thread: PHP threading is hostile to PDO and the lazy emit is
 * frequent enough in practice (every L1/L2/L3 step lands an event).
 *
 * Error routing: this class reports a failure by moving the row to
 * `failed`. Connection-loss faults are the ONE exception — they are
 * classified by {@see WorkerJobExecutor}, which wraps this runner and
 * turns them into a requeue instead, so that a database restart cannot
 * terminally fail a queue full of healthy work.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Jobs;

use App\Batch\BatchPipeline;
use App\Core\Logger;
use App\Models\ModelsActualizeReport;
use App\Storage\SessionRepository;
use App\Models\ModelsActualizer;
use App\Pipeline\EventStreamer;
use App\Pipeline\PipelineEngine;
use App\Pipeline\RunControl;

final class PipelineJobRunner implements JobRunnerInterface
{
    /** Heartbeat at most once per this many seconds. */
    public const HEARTBEAT_MIN_INTERVAL_SECONDS = 5;

    /**
     * v1.8.44 — self-test executor. Recognises the `kind=selftest`
     * payload marker and runs the cheap, token-free worker checks
     * instead of the LLM pipeline.
     */
    private readonly WorkerSelfTest $selfTest;

    /**
     * v1.8.49 — model-actualization executor. Recognises the
     * `kind=models_actualize` payload marker (Models tab → "Actualize
     * linked models") and reconciles the linked models with the live
     * provider catalogues. Null when the CLI did not wire one, in which
     * case that job kind reports a clean failure instead of silently
     * running the pipeline on a probe payload.
     */
    private readonly ?ModelsActualizer $modelsActualizer;

    /**
     * Sessions owned by the non-pipeline job kinds. A `models_actualize`
     * run creates one (JobService::createModelsActualizeJob) but has no
     * engine to close it, so this runner closes it: the job is what the
     * operator sees in the Models tab, and the session is what History
     * lists - leaving one of them running made the same run look finished
     * in one place and stuck in the other.
     */
    private readonly ?SessionRepository $sessions;

    /**
     * Asynchronous Batch API executor. Recognises the
     * `kind=batch_pipeline` payload marker (Chat → "Run via Batch API")
     * and advances its durable state machine instead of running the
     * synchronous engine. Null when the CLI did not wire one, in which
     * case that job kind reports a clean failure instead of silently
     * running the synchronous pipeline on a payload it does not own.
     */
    private readonly ?BatchPipeline $batchPipeline;

    /**
     * v1.8.44 — optional presence beacon callback supplied by the CLI
     * worker. Invoked alongside the job heartbeat so a long pipeline run
     * keeps the worker's presence row fresh even though the poll loop is
     * blocked inside the engine.
     *
     * @var null|callable():void
     */
    private $presenceBeat = null;

    public function __construct(
        private readonly JobRepository  $jobs,
        private readonly PipelineEngine $engine,
        private readonly ?RunControl    $runControl = null,
        ?WorkerSelfTest                 $selfTest = null,
        ?ModelsActualizer               $modelsActualizer = null,
        ?BatchPipeline                  $batchPipeline = null,
        ?SessionRepository              $sessions = null,
    ) {
        $this->selfTest = $selfTest ?? new WorkerSelfTest($jobs);
        $this->modelsActualizer = $modelsActualizer;
        $this->batchPipeline = $batchPipeline;
        $this->sessions = $sessions;
    }

    /**
     * Wire the presence beacon callback ({@see WorkerPresenceRepository}).
     * Pass null to detach. Best-effort: any exception from the callback
     * is swallowed so the beacon can never abort a pipeline run.
     *
     * @param null|callable():void $beat
     */
    public function setPresenceBeat(?callable $beat): void
    {
        $this->presenceBeat = $beat;
    }

    /**
     * Execute the supplied job. Mutates `revoap_jobs` to reflect
     * the run lifecycle and returns the resulting JobStatus.
     *
     * Pre-conditions: the job MUST already be in `running` state with
     * `locked_by` set to this worker; JobRepository::acquireNext()
     * does this atomically.
     */
    public function run(Job $job, string $workerId): JobStatus
    {
        $sessionId = $job->sessionId;
        $payload   = $job->payload;
        $query     = isset($payload['query']) ? (string)$payload['query'] : '';

        // v1.8.44 — self-test jobs (`kind=selftest`) validate the whole
        // web → DB → worker → result chain without invoking a model.
        // Checked BEFORE the query validation so a probe payload never
        // has to carry a meaningful query. v1.8.49 adds `kind=models_actualize`
        // on the same footing: a non-pipeline job kind, recognised before
        // the pipeline payload contract is enforced. The Batch API executor
        // (`kind=batch_pipeline`) joins them for the same reason — its
        // payload carries a query, but it is not a synchronous run and must
        // not be handed to the engine.
        $isSelfTest = WorkerSelfTest::isSelfTest($payload);
        $isModelsActualize = ($payload['kind'] ?? null) === ModelsActualizer::KIND;
        $isBatch = BatchPipeline::isBatchPayload($payload);
        if (!$isSelfTest && !$isModelsActualize && !$isBatch && $query === '') {
            return $this->fail($job, 'invalid_payload', 'payload.query is missing or empty');
        }

        // Pre-flight: an intent submitted between enqueue and acquire
        // may already be on the row. Honour cancel/pause without
        // touching the engine.
        //
        // A batch job is deliberately EXEMPT. Its row sleeps between polls,
        // and while it sleeps the provider is running — and billing — a
        // batch that only we can stop. Terminating the job locally here
        // would leave that batch running forever with no owner. The batch
        // executor handles `cancel_requested` itself: it calls
        // `POST /batches/{id}/cancel`, records what the provider answered,
        // and only then moves the job to a terminal state.
        $live = $isBatch ? null : $this->jobs->readStatus($job->id);
        if ($live === JobStatus::CancelRequested) {
            $this->jobs->updateStatus($job->id, JobStatus::Cancelled);
            $this->log('worker.job.cancelled_before_start', $job, $workerId);
            return JobStatus::Cancelled;
        }
        if ($live === JobStatus::PauseRequested) {
            $this->jobs->updateStatus($job->id, JobStatus::Paused);
            $this->jobs->releaseLock($job->id);
            $this->log('worker.job.paused_before_start', $job, $workerId);
            return JobStatus::Paused;
        }

        if ($isSelfTest) {
            return $this->runSelfTest($job, $workerId);
        }
        if ($isModelsActualize) {
            return $this->runModelsActualize($job, $workerId);
        }
        if ($isBatch) {
            return $this->runBatchPipeline($job, $workerId);
        }

        // Map durable intents -> cooperative RunControl signals so the
        // engine's existing checkpoints handle pause/cancel/consensus
        // without any new wiring inside PipelineEngine.
        if ($this->runControl !== null) {
            try {
                $this->runControl->finalize($sessionId);
            } catch (\Throwable) {
                // Best-effort: a stale control file should not block the run.
            }
        }

        // Wire a heartbeat-on-emit hook + an intent-poll-on-emit hook
        // into the engine. The engine emits one event per pipeline
        // step, so this is a cheap natural checkpoint.
        $lastBeat = 0.0;
        $jobId    = $job->id;
        $runner   = $this;
        $hookStreamer = new class($runner, $this->runControl, $jobId, $workerId, $sessionId, $lastBeat) implements EventStreamer {
            public function __construct(
                private PipelineJobRunner $runner,
                private ?RunControl       $rc,
                private int               $jobId,
                private string            $workerId,
                private int               $sessionId,
                private float             $lastBeat,
            ) {}
            public function emit(string $eventType, array $payload, ?int $stepId = null): void
            {
                $this->runner->onEngineEvent(
                    jobId:    $this->jobId,
                    workerId: $this->workerId,
                    sessionId:$this->sessionId,
                    eventType:$eventType,
                    runControl:$this->rc,
                );
            }
        };
        // Engine emits to BOTH this hook and the StepRepository
        // (unconditionally). When the operator never opened the SSE
        // page, the StepRepository writes alone are still enough for
        // the replay endpoint to reconstruct the run.
        $this->engine->setEventStreamer($hookStreamer);
        if ($this->runControl !== null) {
            $this->engine->setRunControl($this->runControl);
        }

        $options = $this->buildEngineOptions($payload, $sessionId);
        $userId  = isset($payload['user_id']) && is_numeric($payload['user_id'])
            ? (int)$payload['user_id']
            : null;

        $this->log('worker.job.start', $job, $workerId);
        try {
            $result = $this->engine->run($query, $userId, $options);
        } catch (\Throwable $e) {
            $this->engine->setEventStreamer(null);
            return $this->fail(
                $job,
                (new \ReflectionClass($e))->getShortName(),
                $e->getMessage(),
            );
        }
        $this->engine->setEventStreamer(null);

        // v1.8.46 — delivery invariant. Every event of this run must land
        // on the session the browser was handed by JobService, otherwise
        // /api/council/session/{id}/events/stream replays an empty id while
        // the run shows up under a different row in History. The engine
        // honours `options.session_id` (see PipelineEngine::run); this
        // check turns a silent regression into an auditable warning
        // instead of a chat that never leaves "no events yet".
        $engineSessionId = isset($result['session_id']) && is_numeric($result['session_id'])
            ? (int)$result['session_id']
            : 0;
        if ($engineSessionId !== $sessionId) {
            Logger::warn('worker.job.session_mismatch', [
                'job_id'            => $job->id,
                'expected_session'  => $sessionId,
                'engine_session'    => $engineSessionId,
                'note'              => '/api/council/session/{expected}/events/stream will replay nothing',
            ]);
        }

        // Post-run: examine the durable intent that may have been
        // recorded mid-flight. The engine itself stops cooperatively
        // (rejected/finalizing) but the job row is the source of truth
        // for the lifecycle.
        $postStatus = $this->jobs->readStatus($job->id);
        if ($postStatus === JobStatus::CancelRequested) {
            $this->jobs->updateStatus($job->id, JobStatus::Cancelled);
            $this->log('worker.job.cancelled_post_run', $job, $workerId);
            return JobStatus::Cancelled;
        }
        if ($postStatus === JobStatus::PauseRequested) {
            $this->jobs->updateStatus($job->id, JobStatus::Paused);
            $this->jobs->releaseLock($job->id);
            $this->log('worker.job.paused_post_run', $job, $workerId);
            return JobStatus::Paused;
        }

        // Success path — persist result envelope and flip to completed.
        $envelope = $this->buildResultEnvelope($result);
        $this->jobs->recordResult($job->id, $envelope);
        $this->jobs->updateStatus($job->id, JobStatus::Completed);
        $this->log('worker.job.completed', $job, $workerId, [
            'duration_ms' => $envelope['stats']['duration_ms'] ?? null,
            'final_chars' => isset($envelope['final_answer']) ? mb_strlen((string)$envelope['final_answer']) : 0,
        ]);
        return JobStatus::Completed;
    }

    /**
     * Heartbeat + intent poll hook the engine invokes for every event.
     * Public so the anonymous EventStreamer can forward to it without
     * tripping the private-method visibility check.
     *
     * @internal
     */
    public function onEngineEvent(
        int $jobId,
        string $workerId,
        int $sessionId,
        string $eventType,
        ?RunControl $runControl,
    ): void {
        // Rate-limited heartbeat. Presence beacon rides along: it is
        // throttled independently (1/s) and the engine emits events far
        // more often than that during a real run.
        static $last = [];
        $now = microtime(true);
        if (!isset($last[$jobId]) || ($now - (float)$last[$jobId]) >= self::HEARTBEAT_MIN_INTERVAL_SECONDS) {
            try {
                $this->jobs->heartbeat($jobId, $workerId);
            } catch (\Throwable $e) {
                Logger::warn('worker.heartbeat.failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            }
            $last[$jobId] = $now;
        }
        $this->beatPresence();
        // Mirror durable intent -> RunControl so the engine's own
        // cooperative checkpoints handle the cancel/pause/finalize.
        if ($runControl === null) { return; }
        try {
            $current = $this->jobs->readStatus($jobId);
        } catch (\Throwable) {
            return;
        }
        if ($current === JobStatus::CancelRequested) {
            try { $runControl->applyAction($sessionId, RunControl::ACTION_REJECT); } catch (\Throwable) {}
        } elseif ($current === JobStatus::PauseRequested) {
            try { $runControl->applyAction($sessionId, RunControl::ACTION_PAUSE); } catch (\Throwable) {}
        } elseif ($current === JobStatus::ConsensusRequested) {
            try { $runControl->applyAction($sessionId, RunControl::ACTION_FINALIZE); } catch (\Throwable) {}
        }
    }

    /**
     * Reconstruct the `options` array PipelineEngine::run() expects
     * from the canonical job payload assembled by JobService.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildEngineOptions(array $payload, int $sessionId): array
    {
        $opts = [
            // Reuse the pre-created session id so /events/stream
            // resolves to the live worker output.
            'session_id' => $sessionId,
            // v1.8.46 — cache policy for a worker run, stated explicitly
            // instead of left implicit in the engine's default.
            //
            // A job reuses the response cache on purpose: the cache key is
            // the exact (provider, model, normalized messages, significant
            // options) tuple, so a hit can only ever be the answer to this
            // very call — while a retried job (attempt 2 after a transport
            // failure) or a `repairSession` replay would otherwise re-pay
            // for every L1 call it already made. Provider errors and empty
            // completions are never cached (see
            // ModelResponseCache::isCacheable), so a retry cannot be
            // handed a failure.
            //
            // Operators who need one guaranteed-fresh run set
            // `cache_mode = 'bypass'` — the engine then skips both the
            // lookup and the store for this run only.
            'cache_mode' => 'reuse',
        ];
        if (isset($payload['selected_models']) && is_array($payload['selected_models'])) {
            $opts['selected_models'] = $payload['selected_models'];
            // Worker-supplied selection is authoritative; suppress the
            // auto-routing layer so we don't second-guess the operator.
            $opts['lock_models'] = !empty($payload['lock_models']);
        }
        if (isset($payload['reasoning'])) {
            $opts['reasoning'] = $payload['reasoning'];
        }
        if (isset($payload['consensus_skill'])) {
            $opts['consensus_skill'] = $payload['consensus_skill'];
        }
        if (isset($payload['cycle_limit']) && is_numeric($payload['cycle_limit'])) {
            $opts['cycle_limit'] = (int)$payload['cycle_limit'];
        }
        // Single-model mode (Chat → "Single model, no consensus"). Forwarded
        // only when the producer actually asked for it: the engine reads a
        // literal truthy value, and a job payload that merely carries the key
        // as false/null must keep the consensus path. Batch jobs never reach
        // here with it - JobService refuses the combination outright.
        if (($payload['single_model'] ?? null) === true
            || ($payload['single_model'] ?? null) === 1
            || ($payload['single_model'] ?? null) === '1'
        ) {
            $opts['single_model'] = true;
        }

        if (isset($payload['attachments']) && is_array($payload['attachments'])) {
            // Re-validated at dispatch: a payload edited in the database must
            // not smuggle anything past the request-time checks. Fully
            // qualified on purpose - this file carries no App\Pipeline imports.
            $attachmentSet = \App\Pipeline\QueryAttachments::fromRequest($payload['attachments']);
            if ($attachmentSet['items'] !== []) {
                $opts['attachments'] = $attachmentSet['items'];
            }
        }
        if (isset($payload['domain']) && is_string($payload['domain']) && $payload['domain'] !== '') {
            $opts['domain'] = $payload['domain'];
        }
        if (isset($payload['skill']) && is_string($payload['skill']) && $payload['skill'] !== '') {
            $opts['skill'] = $payload['skill'];
        }
        if (isset($payload['skill_id']) && is_numeric($payload['skill_id'])) {
            $opts['skill_id'] = (int)$payload['skill_id'];
        }
        if (isset($payload['client_run_id']) && is_string($payload['client_run_id']) && $payload['client_run_id'] !== '') {
            $opts['client_run_id'] = $payload['client_run_id'];
        }
        return $opts;
    }

    /**
     * @param  array<string,mixed> $engineResult
     * @return array<string,mixed>
     */
    private function buildResultEnvelope(array $engineResult): array
    {
        $envelope = [
            'session_id'      => $engineResult['session_id'] ?? null,
            'final_answer'    => $engineResult['final_answer'] ?? '',
            'selected_models' => $engineResult['selected_models'] ?? [],
            'consensus'       => null,
            'stats'           => null,
            'synthesis_stats' => $engineResult['synthesis_stats'] ?? null,
            // v1.8.48 — carried through from the engine so the job row
            // records that the run's L4 fell back to the apology body. The
            // job itself still ends `completed` (it ran; the queue is not a
            // place to invent a second, contradicting verdict), but the
            // Worker card and the job feeds can now say "the last run did
            // not produce an answer" instead of showing a clean green row.
            'status'            => $engineResult['status'] ?? 'completed',
            'synthesis_failure' => $engineResult['synthesis_failure'] ?? null,
        ];
        $consensus = $engineResult['consensus'] ?? null;
        if (is_object($consensus) && method_exists($consensus, 'toArray')) {
            $envelope['consensus'] = $consensus->toArray();
        } elseif (is_array($consensus)) {
            $envelope['consensus'] = $consensus;
        }
        $stats = $engineResult['stats'] ?? null;
        if (is_object($stats) && method_exists($stats, 'toArray')) {
            $envelope['stats'] = $stats->toArray();
        } elseif (is_array($stats)) {
            $envelope['stats'] = $stats;
        }
        return $envelope;
    }

    /**
     * v1.8.49 — run the model-actualization pass and persist its envelope.
     *
     * Like the self-test, the job reports its verdict through
     * `result_json` (`ok` / `stage` / `warnings[]` / `assigned`) and the
     * Models tab renders it from there. Unlike the self-test, a run that
     * ends in `problems` also ends the JOB as `completed`: the
     * actualization already decided not to write anything on its own
     * (all-or-nothing), so a non-terminal "Failed" would only push a
     * deliberate no-op into the queue's failure counter. An exception is
     * different — that means no envelope at all, so it stays `failed` with
     * `error_json`, where real worker faults are visible.
     */
    private function runModelsActualize(Job $job, string $workerId): JobStatus
    {
        if ($this->modelsActualizer === null) {
            return $this->fail(
                $job,
                'ModelsActualizerUnavailable',
                'This worker was started without the models-actualization service wired in '
                . '(see bin/revoap-worker.php); restart it to enable this job kind.',
            );
            // The session exists already; close it with the same reason so
            // History does not keep a run that never started.
            $this->finishActualizeSession(
                $job,
                ModelsActualizeReport::failure(
                    'This worker was started without the models-actualization service wired in; '
                    . 'the run never started.',
                    'ModelsActualizerUnavailable',
                ),
                0,
                false,
            );
        }
        $this->log('worker.job.models_actualize.start', $job, $workerId);
        // v1.8.50 — the run is long (a live catalogue fetch per connector
        // plus one analyzer call per preset) and, unlike the pipeline path,
        // it emits no engine events, so nothing kept its queue row fresh.
        // Past `pipeline.jobs.recovery.stale_after_seconds` (300s by
        // default) another worker's recovery sweep would declare it stale,
        // requeue it underneath the run that is still in progress and
        // eventually fail it as "recovery exhausted" — which is exactly
        // what "it spins on Analysing models and never gets anywhere, then
        // resets" looks like from the UI. Every stage transition now
        // heartbeats.
        $heartbeat = function () use ($job, $workerId): void {
            try {
                $this->jobs->heartbeat($job->id, $workerId);
            } catch (\Throwable $e) {
                Logger::warn('worker.heartbeat.failed', [
                    'job_id' => $job->id,
                    'error'  => $e->getMessage(),
                ]);
            }
            $this->beatPresence();
        };
        try {
            $envelope = $this->modelsActualizer->run($job, $workerId, $heartbeat);
        } catch (\Throwable $e) {
            // The actualizer converts its own failures into a `problems`
            // envelope; this is the last resort for something that escaped
            // it. Its status record is written anyway, so the Models tab can
            // show the error instead of falling back to its initial state.
            $this->modelsActualizer->recordFailure($job, $e->getMessage(), [
                'class'   => (new \ReflectionClass($e))->getShortName(),
                'message' => $e->getMessage(),
            ]);
            $this->finishActualizeSession(
                $job,
                ModelsActualizeReport::failure($e->getMessage(), (new \ReflectionClass($e))->getShortName()),
                0,
                false,
            );
            return $this->fail(
                $job,
                (new \ReflectionClass($e))->getShortName(),
                $e->getMessage(),
            );
        }
        $this->jobs->recordResult($job->id, $envelope);
        $this->jobs->updateStatus($job->id, JobStatus::Completed);
        // v1.9.3 - close the run's own session. The envelope already holds
        // everything History should show: what was imported, what went
        // stale, which model replaced which. A run that ended in `problems`
        // still completes its session - the job is completed on purpose
        // (nothing was written), and the answer says so.
        $this->finishActualizeSession(
            $job,
            ModelsActualizeReport::markdown($envelope),
            (int)($envelope['duration_ms'] ?? 0),
            ($envelope['ok'] ?? false) === true,
        );
        $this->log('worker.job.models_actualize.done', $job, $workerId, [
            'stage'       => (string)($envelope['stage'] ?? ''),
            'warnings'    => count((array)($envelope['warnings'] ?? [])),
            'duration_ms' => (int)($envelope['duration_ms'] ?? 0),
        ]);
        if (($envelope['ok'] ?? false) !== true) {
            Logger::warn('worker.models_actualize.problems', [
                'job_id'   => $job->id,
                'stage'    => (string)($envelope['stage'] ?? ''),
                'message'  => (string)($envelope['message'] ?? ''),
                'warnings' => array_values(array_map(
                    static fn(mixed $w): string => (string)$w,
                    (array)($envelope['warnings'] ?? []),
                )),
            ]);
        }
        return JobStatus::Completed;
    }

    /**
     * Write the run's Final answer and close its council session.
     *
     * Best effort by design: the actualization itself has already succeeded
     * or failed on its own terms, and a session row that could not be
     * updated must not turn a finished run into a failed job. The failure
     * is logged instead.
     *
     * @param string $markdown   the run's Final answer
     * @param int    $durationMs how long the run took
     * @param bool   $ok         whether the run produced a usable result
     */
    private function finishActualizeSession(Job $job, string $markdown, int $durationMs, bool $ok): void
    {
        if ($this->sessions === null || $job->sessionId <= 0) {
            return;
        }
        try {
            $this->sessions->complete(
                id:              $job->sessionId,
                finalAnswer:     $markdown,
                consensusScore:  null,
                consensusStatus: ModelsActualizeReport::CONSENSUS_STATUS,
                totalTokens:     0,
                durationMs:      $durationMs,
                status:          $ok ? 'completed' : 'failed',
            );
        } catch (\Throwable $e) {
            Logger::warn('worker.models_actualize.session_not_closed', [
                'job_id'     => $job->id,
                'session_id' => $job->sessionId,
                'error'      => Logger::sanitizeScalar($e->getMessage()),
            ]);
        }
    }

    /**
     * Advance the asynchronous Batch API run by one phase.
     *
     * The executor owns the whole lifecycle of this job kind — preparing,
     * uploading, creating, polling, accepting, continuing and cancelling —
     * and reports the phase it reached through the returned status. Two of
     * those returns are unusual for this class and both are intentional:
     *
     *   * `JobStatus::Queued` — the phase PARKED the row (a batch waits on
     *     the provider for minutes to 24 h, so the worker must not sleep on
     *     it). `JobRepository::parkUntil()` has already put the row back in
     *     the queue with a future `next_run_at`, so `queued` is the literal
     *     truth about the row, and returning it also keeps the worker's
     *     webhook dispatch silent, which is right: a poll is not a terminal
     *     run state.
     *   * `JobStatus::Cancelled` — the operator asked to stop, and the
     *     provider was told to stop before the job was flipped.
     *
     * The heartbeat rides along through the phase transitions: preparing
     * writes step rows, and the accept phase can spend minutes inside the
     * synchronous L2/L3/L4 continuation, either of which can outlive
     * `pipeline.jobs.recovery.stale_after_seconds` if nothing beats.
     */
    private function runBatchPipeline(Job $job, string $workerId): JobStatus
    {
        if ($this->batchPipeline === null) {
            return $this->fail(
                $job,
                'BatchPipelineUnavailable',
                'This worker was started without the Batch API pipeline wired in '
                . '(see bin/revoap-worker.php); restart it to enable this job kind.',
            );
        }
        $heartbeat = function () use ($job, $workerId): void {
            try {
                $this->jobs->heartbeat($job->id, $workerId);
            } catch (\Throwable $e) {
                Logger::warn('worker.heartbeat.failed', [
                    'job_id' => $job->id,
                    'error'  => $e->getMessage(),
                ]);
            }
            $this->beatPresence();
        };

        try {
            $result = $this->batchPipeline->run($job, $workerId, $heartbeat);
        } catch (\Throwable $e) {
            // The batch executor converts every condition it can describe
            // into a phase outcome, so anything reaching here escaped it and
            // means no envelope was produced. Same rule as the other kinds:
            // a throw is a real worker fault and stays `failed` with
            // `error_json`, where real faults are visible.
            return $this->fail(
                $job,
                (new \ReflectionClass($e))->getShortName(),
                $e->getMessage(),
            );
        }

        $status = JobStatus::tryFromString((string)($result['status'] ?? '')) ?? JobStatus::Failed;
        $this->log('worker.job.batch.phase_done', $job, $workerId, [
            'batch_phase' => (string)($result['phase'] ?? ''),
            'batch_status' => $status->value,
            'parked'      => (bool)($result['parked'] ?? false),
            'delay_s'     => (int)($result['delay_s'] ?? 0),
        ]);
        return $status;
    }

    /**
     * v1.8.44 — run the token-free self-test and persist its envelope.
     *
     * The job ALWAYS terminates as `completed`, even when checks failed:
     * a self-test is diagnostic, not production work, so a failing check
     * must not inflate the queue's Failed counter — an operator seeing
     * "Failed: 1" cannot tell a broken pipeline from a diagnostic
     * finding, and a genuine failed job becomes unfindable. The verdict
     * lives in `result_json` (`ok` / `passed` / `failed` / `checks[]`)
     * and the Settings → Worker card renders it from `result.ok`, never
     * from the terminal status.
     *
     * The exception path is different: a throw inside the diagnostic
     * harness itself means no envelope was produced at all. That is a
     * real worker fault and stays `failed` with `error_json` set, so it
     * is visible where real failures are visible.
     */
    private function runSelfTest(Job $job, string $workerId): JobStatus
    {
        $this->log('worker.job.selftest.start', $job, $workerId);
        try {
            $envelope = $this->selfTest->run($job, $workerId);
        } catch (\Throwable $e) {
            return $this->fail(
                $job,
                (new \ReflectionClass($e))->getShortName(),
                $e->getMessage(),
            );
        }
        $ok = ($envelope['ok'] ?? false) === true;
        $this->jobs->recordResult($job->id, $envelope);
        $this->jobs->updateStatus($job->id, JobStatus::Completed);
        $this->log('worker.job.selftest.done', $job, $workerId, [
            'ok'          => $ok,
            'passed'      => (int)($envelope['passed'] ?? 0),
            'failed'      => (int)($envelope['failed'] ?? 0),
            'duration_ms' => (int)($envelope['duration_ms'] ?? 0),
        ]);
        if (!$ok) {
            // Diagnostics failed but the JOB succeeded — keep the
            // operator-visible trail in the log, not on the job row.
            Logger::warn('worker.selftest.checks_failed', [
                'job_id'    => $job->id,
                'worker_id' => $workerId,
                'passed'    => (int)($envelope['passed'] ?? 0),
                'failed'    => (int)($envelope['failed'] ?? 0),
                'checks'    => array_values(array_map(
                    static fn(array $c): string => (string)($c['name'] ?? '?'),
                    array_filter(
                        is_array($envelope['checks'] ?? null) ? $envelope['checks'] : [],
                        static fn(array $c): bool => ($c['ok'] ?? true) !== true,
                    ),
                )),
            ]);
        }
        return JobStatus::Completed;
    }

    /**
     * Fire the presence beacon when one is wired. Never throws — the
     * beacon is a diagnostic and must not be able to abort a run.
     */
    private function beatPresence(): void
    {
        if ($this->presenceBeat === null) {
            return;
        }
        try {
            ($this->presenceBeat)();
        } catch (\Throwable) {
            // Best-effort only.
        }
    }

    private function fail(Job $job, string $class, string $message): JobStatus
    {
        $sanitized = Logger::sanitizeScalar($message);
        $error = [
            'class'   => $class,
            'message' => $sanitized,
            'at'      => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        // v1.8.51 — "Call to undefined method …" is almost never a bug in
        // the deployed code: it is a long-lived worker that still holds the
        // classes it loaded at start-up, running a mix of new callers and
        // old callees (PHP loads a class once per process). The original
        // error is kept verbatim and only appended to, so nothing is
        // hidden — the hint just names the one remedy that works.
        $hint = self::staleCodeHint($sanitized);
        if ($hint !== null) {
            $error['stale_code'] = true;
            $error['message']    = $sanitized . ' ' . $hint;
        }
        $this->jobs->recordError($job->id, $error);
        $this->jobs->updateStatus($job->id, JobStatus::Failed);
        Logger::error('worker.job.failed', [
            'job_id'     => $job->id,
            'session_id' => $job->sessionId,
            'class'      => $class,
            'message'    => $error['message'],
            'stale_code' => $hint !== null,
        ]);
        return JobStatus::Failed;
    }

    /**
     * The remedy text for the "mixed code in a long-lived process" fault,
     * or null when this error is not one of those.
     *
     * Kept public+static so the offline tests classify a message exactly
     * the way the runner does.
     */
    public static function staleCodeHint(string $message): ?string
    {
        if (preg_match('/\bCall to undefined (?:method|function|constant)\b/', $message) !== 1) {
            return null;
        }
        return 'This looks like a worker running STALE CODE: the job used a class member that this PHP process '
            . 'does not have, which happens when the worker was started before the current code was deployed '
            . '(an already-loaded class is never reloaded). Restart it with '
            . '`sudo systemctl restart revoap-worker` (Settings → Worker reports code_state=stale when the '
            . 'process predates the files on disk).';
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
