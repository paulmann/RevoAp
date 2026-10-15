<?php
/**
 * JobService: Application service layer for durable consensus jobs.
 *
 * The web request handler only validates input and shapes the payload;
 * the heavy lifting (session creation, validation, payload assembly,
 * intent transitions) lives here so the controller stays thin and the
 * unit tests can drive the service directly.
 *
 * Phase 1 explicitly does NOT dispatch the LLM pipeline — it just
 * enqueues a row and returns. A future worker (Phase 2 PHP CLI, Phase
 * 3 native Python) polls `revoap_jobs` and runs the pipeline
 * outside of PHP-FPM.
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

use App\Admin\Budgets\BudgetExceededException;
use App\Admin\Budgets\UserQuotaInterface;
use App\Core\Config;
use App\Core\Logger;
use App\Pipeline\QueryAttachments;
use App\Storage\SessionRepository;

final class JobService
{
    /**
     * @var callable():int Resolves the implicit user id when the caller
     *                     did not pass one. Defaults to a no-arg closure
     *                     returning the bootstrap "local" user id when
     *                     UserProfileRepository is wired in.
     */
    private $defaultUserResolver;

    /**
     * @param UserQuotaInterface|null $quotas v2.1.0 (T5) — per-user budget
     *        enforcement. createJob()/createBatchJob() refuse with a
     *        BudgetExceededException BEFORE creating any row, so a blocked
     *        run leaves no session, no job and no ledger trace behind.
     */
    public function __construct(
        private readonly JobRepository     $jobs,
        private readonly SessionRepository $sessions,
        ?callable                          $defaultUserResolver = null,
        private readonly ?UserQuotaInterface $quotas = null,
    ) {
        $this->defaultUserResolver = $defaultUserResolver ?? static fn(): ?int => null;
    }

    /**
     * The budget gate shared by both enqueue paths.
     *
     * Infrastructure failures answer 'ok' inside the enforcer (see
     * UserQuotaEnforcer's failure-direction contract); anything thrown
     * anyway is caught here, because a budget subsystem outage must not
     * become a queue outage. Only a real 'block' verdict refuses.
     *
     * @throws BudgetExceededException when the user's budget blocks new runs
     */
    private function enforceQuota(?int $userId): void
    {
        if ($this->quotas === null || $userId === null || $userId <= 0) {
            return;
        }
        try {
            $verdict = $this->quotas->checkForRun($userId);
        } catch (\Throwable $e) {
            Logger::warn('jobs.quota_check_failed', [
                'user_id' => $userId,
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return;
        }
        if (($verdict['action'] ?? 'ok') === 'block') {
            throw new BudgetExceededException($verdict, $userId);
        }
    }

    /**
     * Create a new durable job. Performs:
     *   1. Input validation (query non-empty, models present).
     *   2. Creates a council_sessions row so existing /api/council/session/{id}
     *      endpoints (history, events, stats) immediately work.
     *   3. Inserts an `revoap_jobs` row with status=queued.
     *
     * Returns the new ids + replay URL — never blocks on an LLM call.
     *
     * @param  array<string,mixed> $input  Raw user input (already JSON-decoded).
     * @return array{job_id:int,session_id:int,status:string,events_url:string}
     * @throws \InvalidArgumentException When input is missing or malformed.
     * @throws BudgetExceededException When the user's budget blocks new runs
     *         (v2.1.0, T5) — refused BEFORE any session or job row exists.
     */
    public function createJob(array $input): array
    {
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }
        $selectedModels = $this->normalizeSelectedModels($input['selected_models'] ?? null);
        $attachmentSet = QueryAttachments::fromRequest($input['attachments'] ?? null);
        if ($attachmentSet['errors'] !== []) {
            throw new \InvalidArgumentException('attachments: ' . implode(' ', $attachmentSet['errors']));
        }
        $attachments = $attachmentSet['items'];

        // Attribution rule (T6): an ABSENT user_id means "the web console
        // enqueued this" and resolves through the default resolver (the
        // local profile user). An EXPLICIT null — what /v1 passes for an
        // external caller that named no identity — stays null: anonymous
        // API spend must land in the UNATTRIBUTED bucket, not adopt the
        // operator's local user (and with it the operator's budgets).
        if (array_key_exists('user_id', $input)) {
            $userId = is_numeric($input['user_id']) ? (int)$input['user_id'] : null;
        } else {
            $resolved = ($this->defaultUserResolver)();
            $userId = is_int($resolved) && $resolved > 0 ? $resolved : null;
        }
        // v2.1.0 (T5) — refuse BEFORE any row is created: a blocked run
        // must not leave an orphan session behind that History shows as
        // permanently 'running'.
        $this->enforceQuota($userId);

        $skillId   = isset($input['skill_id']) && is_numeric($input['skill_id'])
            ? (int)$input['skill_id']
            : 0;
        $skillSlug = isset($input['skill']) && is_string($input['skill'])
            ? trim($input['skill'])
            : null;

        // Create the council_sessions row up-front so SSE replay /
        // /api/council/session/{id}/events can serve a valid response
        // immediately, even before the worker picks the job up.
        $sessionId = $this->sessions->create(
            skillId: $skillId,
            query:   $query,
            userId:  $userId,
        );
        $sessionMeta = $this->buildSessionMetadata($input, $attachments);
        if (method_exists($this->sessions, 'setMetadata')
            && (($skillSlug !== null && $skillSlug !== '') || !empty($sessionMeta['attachments']))) {
            try {
                $this->sessions->setMetadata(
                    $sessionId,
                    $skillSlug !== null && $skillSlug !== '' ? $skillSlug : null,
                    $sessionMeta,
                );
            } catch (\Throwable) {
                // Older deploys may not have the metadata columns yet.
            }
        }

        $payload = $this->buildPayload(
            query:           $query,
            selectedModels:  $selectedModels,
            input:           $input,
            sessionId:       $sessionId,
            userId:          $userId,
            attachments:     $attachments,
        );

        $priority = isset($input['priority']) && is_numeric($input['priority'])
            ? (int)$input['priority']
            : 0;

        $maxAttempts = self::resolveMaxAttempts();

        $jobId = $this->jobs->create(
            sessionId:    $sessionId,
            userId:       $userId,
            payload:      $payload,
            priority:     $priority,
            maxAttempts:  $maxAttempts,
        );

        return [
            'job_id'     => $jobId,
            'session_id' => $sessionId,
            'status'     => JobStatus::Queued->value,
            'events_url' => sprintf('/api/council/session/%d/events/stream', $sessionId),
        ];
    }

    public function get(int $id): ?Job
    {
        return $this->jobs->find($id);
    }

    public function getBySessionId(int $sessionId): ?Job
    {
        return $this->jobs->findBySessionId($sessionId);
    }

    /**
     * v1.8.44 — enqueue the worker self-test job (Settings → Worker →
     * Test). Identical to a normal job except for two payload markers:
     *
     *   * `kind = 'selftest'`  — makes {@see PipelineJobRunner} run
     *     {@see WorkerSelfTest} instead of the LLM pipeline, so the
     *     check costs zero tokens;
     *   * `selftest.probe_providers` — opt-in TCP reachability probe,
     *     false unless the operator asked for it.
     *
     * A council_sessions row is still created so the existing replay /
     * timeline endpoints resolve the same way they do for a real run.
     * `max_attempts` is pinned to 1: a failed self-test is a diagnostic
     * finding, not work worth auto-recovering.
     *
     * @return array{job_id:int,session_id:int,status:string,events_url:string}
     */
    public function createSelfTestJob(bool $probeProviders = false): array
    {
        $query     = 'worker self-test';
        $sessionId = $this->sessions->create(
            skillId: 0,
            query:   $query,
            userId:  null,
        );

        $payload = [
            'kind'         => WorkerSelfTest::KIND,
            'query'        => $query,
            'session_id'   => $sessionId,
            'phase'        => 1,
            'enqueued_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            'selftest'     => ['probe_providers' => $probeProviders],
            'request_meta' => ['origin' => 'settings.worker.test'],
        ];

        $jobId = $this->jobs->create(
            sessionId:   $sessionId,
            userId:      null,
            payload:     $payload,
            // A self-test finishes in milliseconds and the operator is
            // waiting on it, so it may jump ahead of queued pipeline
            // runs (acquireNext orders by priority DESC, id ASC).
            priority:    10,
            maxAttempts: 1,
        );

        return [
            'job_id'     => $jobId,
            'session_id' => $sessionId,
            'status'     => JobStatus::Queued->value,
            'events_url' => sprintf('/api/council/session/%d/events/stream', $sessionId),
        ];
    }

    /**
     * v1.8.49 — enqueue the "actualize linked models" job (Models tab).
     *
     * Same shape as {@see self::createSelfTestJob()}: a council_sessions row
     * so the existing timeline / replay endpoints resolve, plus a payload
     * marker that makes {@see PipelineJobRunner} route the job to
     * {@see \App\Models\ModelsActualizer} instead of the LLM pipeline.
     *
     * The only payload data the run needs is `selection` — the model pool
     * the Chat sidebar is holding. It is captured in the pre-run snapshot,
     * so a rollback restores the operator's actual selection rather than a
     * server-side approximation of it — plus `import_source`, the catalogue
     * the run's first stage imports from (`bothub` / `openrouter`). The
     * source travels in the payload because the import runs in the worker,
     * not in the request that enqueued it: the browser must not sit on a
     * catalogue fetch, and the chosen source has to survive however long
     * the job waits in the queue. `max_attempts` is 1: this job writes
     * configuration, and silently retrying a half-diagnosed failure would
     * rewrite presets twice.
     *
     * @param array<int,array{provider:string,model:string}> $selection
     * @param null|string $importSource `bothub` | `openrouter`; null lets
     *        the worker apply {@see \App\Models\ModelImporter::defaultSource()}
     * @return array{job_id:int,session_id:int,status:string,events_url:string}
     */
    public function createModelsActualizeJob(array $selection = [], ?string $importSource = null): array
    {
        $query     = 'models actualize';
        $sessionId = $this->sessions->create(
            skillId: 0,
            query:   $query,
            userId:  null,
        );

        $source = \App\Models\ModelImporter::normaliseSource($importSource);
        $payload = [
            'kind'         => \App\Models\ModelsActualizer::KIND,
            'query'        => $query,
            'session_id'   => $sessionId,
            'phase'        => 1,
            'enqueued_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            'selection'    => array_values($selection),
            'request_meta' => ['origin' => 'models.actualize'],
        ];
        if ($source !== null) {
            $payload[\App\Models\ModelsActualizer::PAYLOAD_IMPORT_SOURCE] = $source;
        }

        $jobId = $this->jobs->create(
            sessionId:   $sessionId,
            userId:      null,
            payload:     $payload,
            // The operator is waiting on it, and it rewrites configuration
            // the pipeline reads — it must not queue behind real runs.
            priority:    10,
            maxAttempts: 1,
        );

        return [
            'job_id'     => $jobId,
            'session_id' => $sessionId,
            'status'     => JobStatus::Queued->value,
            'events_url' => sprintf('/api/council/session/%d/events/stream', $sessionId),
        ];
    }

    /**
     * Enqueue an asynchronous Batch API run (Chat → "Run via Batch API").
     *
     * Same envelope as {@see self::createJob()} — a `council_sessions` row
     * up front so every existing session endpoint resolves immediately, and
     * a `revoap_jobs` row the worker picks up — with two differences that
     * decide how the worker treats it:
     *
     *   * `kind = 'batch_pipeline'`, which routes the job to
     *     {@see \App\Batch\BatchPipeline} instead of the synchronous engine;
     *   * `batch_options`, the engine options the run needs *after* the
     *     results arrive. They are captured at enqueue time because the
     *     accept phase can run in a different worker process hours later,
     *     and it has to continue the pipeline with the reasoning level, the
     *     cache policy and the cycle limit the operator actually asked for.
     *
     * `max_attempts` is pinned to 1: the batch executor already retries what
     * is retryable (polls, downloads) on its own schedule, so a job-level
     * retry would only re-run the synchronous L2/L3/L4 continuation on top
     * of a batch that already answered.
     *
     * The session is deliberately left `running`. A batch session is not
     * finished until its results are in, and `running` is the only
     * non-terminal value the `council_sessions.status` enum carries — the
     * explicit "awaiting batch" state the UI needs is derived from this
     * job's `result.batch` block rather than from the session column, so no
     * schema change is involved (see the task report).
     *
     * @param  array<string,mixed> $input Raw input (already JSON-decoded).
     * @return array{job_id:int,session_id:int,status:string,run_mode:string,events_url:string}
     * @throws \InvalidArgumentException When the query is missing.
     * @throws BudgetExceededException When the user's budget blocks new runs
     *         (v2.1.0, T5) — refused BEFORE any session or job row exists.
     */
    public function createBatchJob(array $input): array
    {
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }
        // Single-model mode and the Batch API are mutually exclusive by
        // construction, not by preference: single-model mode asks exactly one
        // model ONE question and uses its answer as the final answer, while a
        // batch job parks the L1 fan-out at the provider for minutes or hours
        // and then continues the consensus stages. Accepting both would mean
        // silently choosing one of them for the operator. The combination is
        // refused here, where the request is still visible, instead of being
        // discovered by a worker hours later.
        if (($input['single_model'] ?? null) === true
            || ($input['single_model'] ?? null) === 1
            || ($input['single_model'] ?? null) === '1'
        ) {
            throw new \InvalidArgumentException(
                'single_model cannot be combined with the Batch API run mode: a single-model run '
                . 'calls one model directly and computes no consensus, so there is no fan-out to batch.'
            );
        }
        $selectedModels = $this->normalizeSelectedModels($input['selected_models'] ?? null);
        $batchAttachments = QueryAttachments::fromRequest($input['attachments'] ?? null);
        if ($batchAttachments['errors'] !== []) {
            throw new \InvalidArgumentException('attachments: ' . implode(' ', $batchAttachments['errors']));
        }

        // Same attribution rule as createJob() — see the comment there.
        if (array_key_exists('user_id', $input)) {
            $userId = is_numeric($input['user_id']) ? (int)$input['user_id'] : null;
        } else {
            $resolved = ($this->defaultUserResolver)();
            $userId = is_int($resolved) && $resolved > 0 ? $resolved : null;
        }
        // v2.1.0 (T5) — refuse BEFORE any row is created: a blocked run
        // must not leave an orphan session behind that History shows as
        // permanently 'running'.
        $this->enforceQuota($userId);

        $skillId   = isset($input['skill_id']) && is_numeric($input['skill_id']) ? (int)$input['skill_id'] : 0;
        $skillSlug = isset($input['skill']) && is_string($input['skill']) ? trim($input['skill']) : null;

        $sessionId = $this->sessions->create(
            skillId: $skillId,
            query:   $query,
            userId:  $userId,
        );
        $sessionMeta = $this->buildSessionMetadata($input, $batchAttachments['items']);
        if (method_exists($this->sessions, 'setMetadata')
            && (($skillSlug !== null && $skillSlug !== '') || !empty($sessionMeta['attachments']))) {
            try {
                $this->sessions->setMetadata(
                    $sessionId,
                    $skillSlug !== null && $skillSlug !== '' ? $skillSlug : null,
                    $sessionMeta,
                );
            } catch (\Throwable) {
                // Older deploys may not have the metadata columns yet.
            }
        }

        $options = [
            // Same policy the synchronous worker path states explicitly:
            // a resumed phase may repeat an L1 call it already paid for if
            // the cache is bypassed, and provider errors are never cached,
            // so reuse is the safe default here too.
            'cache_mode'      => 'reuse',
            'reasoning'       => self::reasoning($input['reasoning'] ?? null),
            'consensus_skill' => $this->normalizeConsensusSkill($input['consensus_skill'] ?? null),
            'cycle_limit'     => self::intOrNull($input['cycle_limit'] ?? null),
            'domain'          => self::scalarOrNull($input['domain'] ?? null),
            'skill'           => self::scalarOrNull($input['skill'] ?? null),
            'skill_id'        => self::intOrNull($input['skill_id'] ?? null),
            'client_run_id'   => self::scalarOrNull($input['client_run_id'] ?? null),
        ];

        // The batch L1 planner builds every item through the same
        // buildL1Messages() the synchronous path uses, and the accept-phase
        // continuation re-enters the engine through repairSession() - both
        // read the attachments from these options, so carrying them here is
        // the whole wiring.
        if ($batchAttachments['items'] !== []) {
            $options['attachments'] = $batchAttachments['items'];
        }

        $payload = [
            'kind'            => \App\Batch\BatchPipeline::KIND,
            'query'           => $query,
            'session_id'      => $sessionId,
            'user_id'         => $userId,
            'phase'           => 1,
            'enqueued_at'     => gmdate('Y-m-d\TH:i:s\Z'),
            'selected_models' => $selectedModels,
            'lock_models'     => !empty($input['lock_models']),
            'run_mode'        => 'batch',
            'batch_options'   => array_filter(
                $options,
                static fn(mixed $v): bool => $v !== null && $v !== '',
            ),
            'request_meta'    => $this->buildRequestMeta($input),
        ];

        $jobId = $this->jobs->create(
            sessionId:   $sessionId,
            userId:      $userId,
            payload:     $payload,
            // A batch run is not urgent by nature and competes with real
            // synchronous work for the same worker, so it queues normally
            // rather than jumping the queue.
            priority:    0,
            maxAttempts: 1,
        );

        return [
            'job_id'     => $jobId,
            'session_id' => $sessionId,
            'status'     => JobStatus::Queued->value,
            'run_mode'   => 'batch',
            'events_url' => sprintf('/api/council/session/%d/events/stream', $sessionId),
        ];
    }

    /**
     * List the most recent jobs (newest first), capped for the REST API
     * run listing (GET /v1/runs).
     *
     * @return array<int,Job>
     */
    public function listRecent(int $limit = 20): array
    {
        return $this->jobs->listRecent($limit);
    }

    public function requestPause(int $id): ?JobStatus
    {
        return $this->jobs->requestIntent($id, JobStatus::PauseRequested);
    }

    public function requestResume(int $id): ?JobStatus
    {
        return $this->jobs->requestIntent($id, JobStatus::ResumeRequested);
    }

    public function requestCancel(int $id): ?JobStatus
    {
        return $this->jobs->requestIntent($id, JobStatus::CancelRequested);
    }

    public function requestConsensus(int $id): ?JobStatus
    {
        return $this->jobs->requestIntent($id, JobStatus::ConsensusRequested);
    }

    /**
     * Build the canonical payload row. Mirrors the runtime options the
     * synchronous /api/council/run handler accepts so the worker can
     * dispatch with parity once it lands.
     *
     * @param  list<array{provider:string,model:string}>|null $selectedModels
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function buildPayload(
        string  $query,
        ?array  $selectedModels,
        array   $input,
        int     $sessionId,
        ?int    $userId,
        array   $attachments = [],
    ): array {
        $payload = [
            'query'           => $query,
            'session_id'      => $sessionId,
            'user_id'         => $userId,
            'skill'           => self::scalarOrNull($input['skill']           ?? null),
            'skill_id'        => self::intOrNull   ($input['skill_id']        ?? null),
            'consensus_skill' => $this->normalizeConsensusSkill($input['consensus_skill'] ?? null),
            'cycle_limit'     => self::intOrNull   ($input['cycle_limit']     ?? null),
            'reasoning'       => self::reasoning   ($input['reasoning']       ?? null),
            // Single-model mode (Chat → "Single model, no consensus"). Kept as a
            // real boolean rather than dropped with the nulls below: `false`
            // means "the operator had the switch off" and must reach the engine
            // as such, while a missing key means the same thing. Only `true`
            // switches the run.
            'single_model'    => ($input['single_model'] ?? null) === true
                || ($input['single_model'] ?? null) === 1
                || ($input['single_model'] ?? null) === '1'
                ? true : null,
            'selected_models' => $selectedModels,
            'profile'         => self::arrayOrNull ($input['profile']         ?? null),
            'domain'          => self::scalarOrNull($input['domain']          ?? null),
            'analyzer'        => self::arrayOrNull ($input['analyzer']        ?? null),
            'client_run_id'   => self::scalarOrNull($input['client_run_id']   ?? null),
            'attachments'     => $attachments,
            'request_meta'    => $this->buildRequestMeta($input),
            'enqueued_at'     => gmdate('Y-m-d\TH:i:s\Z'),
            'phase'           => 1,
        ];
        // Drop nulls so the JSON envelope is tight, but keep the
        // required `query` and `session_id` keys.
        $required = ['query', 'session_id', 'phase', 'enqueued_at'];
        foreach (array_keys($payload) as $k) {
            if (in_array($k, $required, true)) { continue; }
            if ($payload[$k] === null || $payload[$k] === []) {
                unset($payload[$k]);
            }
        }
        return $payload;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function buildRequestMeta(array $input): array
    {
        $meta = [];
        $headers = $input['request_meta'] ?? null;
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (!is_string($k) || $k === '') { continue; }
                if (!is_scalar($v)) { continue; }
                $kl = strtolower($k);
                if (str_contains($kl, 'auth') || str_contains($kl, 'cookie') || str_contains($kl, 'token')) {
                    continue;
                }
                $meta[$k] = (string)$v;
            }
        }
        if (isset($input['origin']) && is_string($input['origin']) && $input['origin'] !== '') {
            $meta['origin'] = $input['origin'];
        }
        if (isset($input['user_agent']) && is_string($input['user_agent']) && $input['user_agent'] !== '') {
            $meta['user_agent'] = $input['user_agent'];
        }
        return $meta;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    private function buildSessionMetadata(array $input, array $attachments = []): ?array
    {
        $meta = [];
        foreach (['reasoning', 'cycle_limit', 'consensus_skill', 'domain'] as $k) {
            if (isset($input[$k])) { $meta[$k] = $input[$k]; }
        }
        // The run mode belongs in the session metadata, not only in the job
        // payload: History's "Finish session" reads the session, and without
        // this key it cannot tell a single-model run from a crashed consensus
        // one (see SessionCompletionService::singleModelRun()).
        if (($input['single_model'] ?? null) === true
            || ($input['single_model'] ?? null) === 1
            || ($input['single_model'] ?? null) === '1'
        ) {
            $meta['single_model'] = true;
        }
        // v1.9.x - the names of the attached documents travel in the session
        // metadata so the History card can say which files a run read.
        if ($attachments !== []) {
            $meta['attachments'] = array_values(array_map(
                static fn(array $item): string => (string)($item['name'] ?? ''),
                $attachments,
            ));
        }
        return $meta === [] ? null : $meta;
    }

    /**
     * @param  mixed $value
     * @return list<array{provider:string,model:string}>|null
     */
    private function normalizeSelectedModels(mixed $value): ?array
    {
        if ($value === null) { return null; }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($value)) { return null; }
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row)) { continue; }
            $p = isset($row['provider']) ? strtolower(trim((string)$row['provider'])) : '';
            $m = isset($row['model'])    ? trim((string)$row['model']) : '';
            if ($p === '' || $m === '') { continue; }
            $out[] = ['provider' => $p, 'model' => $m];
        }
        return $out === [] ? null : $out;
    }

    /**
     * Accepts either a slug string or a structured envelope describing
     * the consensus scoring backend.
     *
     * @return string|array<string,mixed>|null
     */
    private function normalizeConsensusSkill(mixed $value): string|array|null
    {
        if ($value === null) { return null; }
        if (is_array($value)) { return $value; }
        if (is_string($value)) {
            $s = trim($value);
            if ($s === '') { return null; }
            $decoded = json_decode($s, true);
            if (is_array($decoded)) { return $decoded; }
            $clean = preg_replace('/[^a-z0-9_\-]/i', '', $s);
            return (is_string($clean) && $clean !== '') ? $clean : null;
        }
        return null;
    }

    private static function scalarOrNull(mixed $v): mixed
    {
        if ($v === null) { return null; }
        if (is_scalar($v)) {
            $s = (string)$v;
            return $s === '' ? null : $s;
        }
        return null;
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) { return $v; }
        if (is_string($v) && is_numeric($v)) { return (int)$v; }
        if (is_float($v)) { return (int)$v; }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function arrayOrNull(mixed $v): ?array
    {
        if (is_array($v)) { return $v; }
        return null;
    }

    /**
     * Reasoning is either a level slug ("low"/"medium"/"high"/"off") or
     * an OpenRouter-shaped envelope ({effort:string,...}).
     *
     * @return string|array<string,mixed>|null
     */
    /**
     * Resolve the per-row `max_attempts` ceiling from Config. Falls
     * back to 3 (the schema default) if Config has not been loaded —
     * tests construct a JobService without bootstrapping Config and
     * we want them to still get a sane stamp on the row.
     */
    private static function resolveMaxAttempts(): int
    {
        try {
            $policy = Config::jobsRecoveryPolicy();
            $value = (int)($policy['max_attempts'] ?? 3);
            return $value > 0 ? $value : 3;
        } catch (\Throwable) {
            return 3;
        }
    }

    private static function reasoning(mixed $v): string|array|null
    {
        if (is_string($v)) {
            $s = trim($v);
            return $s === '' ? null : $s;
        }
        if (is_array($v)) { return $v; }
        return null;
    }
}
