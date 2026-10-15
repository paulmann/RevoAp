<?php
/**
 * ApiV1Controller: launch endpoints of the external REST API v1.
 *
 * POST /v1/runs starts a consensus run in one of three modes:
 *   - stream=true   -> Server-Sent Events inside THIS request. The
 *                      pipeline events (PIPELINE_STARTED, L1_RESPONSE,
 *                      ..., PIPELINE_DONE / PIPELINE_FATAL) are emitted
 *                      live, mirroring the legacy /api/council/stream
 *                      contract. A file-backed StreamRunLock guards
 *                      against duplicate client_run_id submissions.
 *   - async=true    -> durable queue. JobService::createJob() persists a
 *                      council_sessions row and an revoap_jobs row and
 *                      returns 202 Accepted with events/result/cancel
 *                      URLs. The CLI worker executes the job outside of
 *                      PHP-FPM. This is the DEFAULT for API clients,
 *                      because consensus runs are long-lived.
 *   - async=false   -> synchronous run. PipelineEngine::run() blocks
 *                      until the final answer exists and the response
 *                      carries final_answer/consensus/usage inline.
 *
 * Every request first passes App\Api\ApiAuth::check() (API key,
 * scopes, admin-path deny-list, per-key rate limit). Requests are
 * translated to/from the native / OpenAI / Anthropic shapes by
 * App\Api\FormatAdapter, so off-the-shelf SDKs can talk to RevoAp.
 *
 * Read endpoints (Step 5) answer agents that poll after a launch:
 *   - GET  /v1/runs             -> recent run list
 *   - GET  /v1/runs/{id}        -> run record
 *   - GET  /v1/runs/{id}/events -> persisted pipeline events
 *                                  (?after_event_id= cursor)
 *   - GET  /v1/runs/{id}/result -> finished run record
 *   - POST /v1/runs/{id}/cancel -> cancellation intent (write scope)
 *   - GET  /v1/models           -> active consensus models
 *   - GET  /v1/usage            -> aggregated usage (?period=)
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

namespace App\Api;

use App\Admin\Budgets\BudgetExceededException;
use App\Admin\Budgets\UserQuotaInterface;
use App\Core\Config;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Jobs\Job;
use App\Jobs\JobStatus;
use App\Jobs\JobService;
use App\Pipeline\PipelineEngine;
use App\Pipeline\RunControl;
use App\Pipeline\SseEventStreamer;
use App\Pipeline\StreamRunLock;
use App\Stats\SessionStats;
use App\Statistics\StatisticsPeriod;
use App\Statistics\StatisticsService;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;

final class ApiV1Controller
{
    /**
     * Request-body override for offline tests (CLI has no php://input).
     * Null means "read the real request body".
     */
    private ?array $bodyOverride = null;

    /**
     * @param UserQuotaInterface|null $quotas v2.1.0 (T5) — per-user budget
     *        enforcement for run creation. Null on installs without the
     *        budget subsystem wired: the launch path behaves exactly as
     *        before, which is the correct default for an unmigrated host.
     */
    public function __construct(
        private readonly ApiAuth           $auth,
        private readonly FormatAdapter     $formats,
        private readonly JobService        $jobs,
        private readonly PipelineEngine    $engine,
        private readonly SessionRepository $sessions,
        private readonly StepRepository    $steps,
        private readonly ?RunControl       $runControl = null,
        private readonly ?StreamRunLock    $streamLock = null,
        private readonly ?StatisticsService $statistics = null,
        private readonly ?UserQuotaInterface $quotas = null,
    ) {}

    /** Test seam: substitute the decoded JSON request body. */
    public function setBodyForTest(?array $body): void
    {
        $this->bodyOverride = $body;
    }

    /**
     * POST /v1/runs - start a consensus run.
     *
     * Request body (native messages):
     *   query, selected_models | models, skill, reasoning, cycle_limit,
     *   consensus_skill, priority, user_id, client_run_id,
     *   stream (bool), async (bool, default true).
     *
     * OpenAI/Anthropic envelopes (model + messages [+ system]) are
     * mapped by FormatAdapter::parseRequest() before dispatch.
     */
    public function createRun(): string
    {
        $hint = $this->formatHint();
        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            // Unreadable/non-JSON body: fail fast with a 400 in the
            // declared format instead of a 500 from the router.
            $format = $this->formats->detectFormat([], $hint);
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', $e->getMessage()),
                400,
            );
        }

        // Parse the envelope first (in the declared format) so a
        // malformed payload fails fast with a 400 response. Body
        // reception itself is hardened below (readJsonBody()).
        try {
            $parsed = $this->formats->parseRequest($body, $hint);
        } catch (\InvalidArgumentException $e) {
            $format = $this->formats->detectFormat($body, $hint);
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', $e->getMessage()),
                400,
            );
        }
        $format = $parsed['format'];

        // Authentication gate: nothing is dispatched before this passes.
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/runs', true);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        $input  = $parsed['input'];
        $query  = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', 'query is required'),
                400,
            );
        }

        $userId = isset($input['user_id']) && is_numeric($input['user_id'])
            ? (int)$input['user_id']
            : null;

        // v2.1.0 (T5) — per-user budget gate. Checked HERE, before the
        // stream/async/sync branch, so all three launch shapes share one
        // enforcement point and one refusal format. A blocked run never
        // reaches the engine, the queue or the ledger.
        if (($refused = $this->quotaGate($userId, $format)) !== null) {
            return $refused;
        }

        if ($parsed['stream']) {
            return $this->streamRun(
                $query,
                $userId,
                $this->buildRunOptions($input),
                $this->resolveClientRunId($input),
            );
        }

        if ($this->wantAsync($body)) {
            return $this->enqueueRun($input, $format, $userId);
        }

        return $this->syncRun($query, $userId, $this->buildRunOptions($input), $format);
    }

    // ----------------------------------------------------------------
    // Read endpoints (Step 5)
    // ----------------------------------------------------------------

    /**
     * GET /v1/runs - list recent runs, newest first. Always rendered in
     * the native run shape (SDK envelopes describe a single message).
     */
    public function listRuns(): string
    {
        $format = $this->responseFormat();
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/runs', false);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        $limit = $this->boundedLimit($_GET['limit'] ?? 20, 20, 100);
        $runs  = [];
        foreach ($this->jobs->listRecent($limit) as $job) {
            $runs[] = $this->formats->runResponse(
                FormatAdapter::FORMAT_NATIVE,
                $this->buildRunFromJob($job),
            );
        }
        return Response::json(['ok' => true, 'count' => count($runs), 'runs' => $runs]);
    }

    /**
     * GET /v1/runs/{id} - one run record. Works for durable jobs and
     * for synchronous runs (fallback to council_sessions).
     *
     * @param array<string,mixed> $params router parameters ('id')
     */
    public function getRun(array $params): string
    {
        $format = $this->responseFormat();
        $id     = $this->parseRunId($params);
        if ($id === null) {
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', 'A numeric run id is required.'),
                400,
            );
        }
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/runs/' . $id, false);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        $run = $this->resolveRun($id);
        if ($run === null) {
            return Response::json(
                $this->formats->errorResponse($format, 404, 'not_found', 'Run not found.'),
                404,
            );
        }
        return Response::json($this->formats->runResponse($format, $run));
    }

    /**
     * GET /v1/runs/{id}/events - persisted pipeline events in insertion
     * order. Agents resume polling with ?after_event_id=<last seen id>.
     *
     * @param array<string,mixed> $params router parameters ('id')
     */
    public function getRunEvents(array $params): string
    {
        $format = $this->responseFormat();
        $id     = $this->parseRunId($params);
        if ($id === null) {
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', 'A numeric run id is required.'),
                400,
            );
        }
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/runs/' . $id . '/events', false);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        if ($this->sessions->find($id) === null) {
            return Response::json(
                $this->formats->errorResponse($format, 404, 'not_found', 'Run not found.'),
                404,
            );
        }

        $after  = max(0, (int)($_GET['after_event_id'] ?? 0));
        $events = [];
        foreach ($this->steps->listEventsBySession($id) as $row) {
            $normalized = $this->normalizeEventRow($row);
            if ($normalized === null || (int)$normalized['id'] <= $after) {
                continue;
            }
            $events[] = $normalized;
        }

        $payload = [
            'ok'         => true,
            'run_id'     => 'run_' . $id,
            'session_id' => $id,
            'events'     => $events,
        ];
        if ($events !== []) {
            $last = end($events);
            $payload['latest_event_id'] = (int)$last['id'];
        }
        return Response::json($payload);
    }

    /**
     * GET /v1/runs/{id}/result - the finished run record. Answers 202
     * Accepted with the live run record while the run is still active
     * (agents poll until a terminal status arrives).
     *
     * @param array<string,mixed> $params router parameters ('id')
     */
    public function getRunResult(array $params): string
    {
        $format = $this->responseFormat();
        $id     = $this->parseRunId($params);
        if ($id === null) {
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', 'A numeric run id is required.'),
                400,
            );
        }
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/runs/' . $id . '/result', false);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        $run = $this->resolveRun($id);
        if ($run === null) {
            return Response::json(
                $this->formats->errorResponse($format, 404, 'not_found', 'Run not found.'),
                404,
            );
        }
        $finished = in_array($run['status'], ['completed', 'failed', 'cancelled'], true);
        return Response::json(
            $this->formats->runResponse($format, $run),
            $finished ? 200 : 202,
        );
    }

    /**
     * POST /v1/runs/{id}/cancel - request cancellation (write scope).
     * Durable jobs receive a CancelRequested intent and the cooperative
     * RunControl reject signal (when wired); sync sessions only get the
     * RunControl signal.
     *
     * @param array<string,mixed> $params router parameters ('id')
     */
    public function cancelRun(array $params): string
    {
        $format = $this->responseFormat();
        $id     = $this->parseRunId($params);
        if ($id === null) {
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', 'A numeric run id is required.'),
                400,
            );
        }
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/runs/' . $id . '/cancel', true);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        $job = $this->jobs->getBySessionId($id);
        if ($job !== null) {
            if ($job->status->isTerminal()) {
                return Response::json(
                    $this->formats->errorResponse($format, 409, 'invalid_state', 'Run already finished.'),
                    409,
                );
            }
            try {
                $this->jobs->requestCancel($job->id);
            } catch (\Throwable $e) {
                $message = Logger::sanitizeScalar($e->getMessage());
                return Response::json(
                    $this->formats->errorResponse($format, 500, 'cancel_failed', $message),
                    500,
                );
            }
            if ($this->runControl !== null) {
                try {
                    $this->runControl->applyAction($id, RunControl::ACTION_REJECT, 'API cancel request');
                } catch (\Throwable) {
                    // Best-effort: the durable intent above is authoritative.
                }
            }
            $job = $this->jobs->get($job->id) ?? $job;
            return Response::json($this->formats->runResponse($format, $this->buildRunFromJob($job)));
        }

        // Legacy synchronous run: only the cooperative control file exists.
        if ($this->sessions->find($id) === null) {
            return Response::json(
                $this->formats->errorResponse($format, 404, 'not_found', 'Run not found.'),
                404,
            );
        }
        if ($this->runControl !== null) {
            try {
                $this->runControl->applyAction($id, RunControl::ACTION_REJECT, 'API cancel request');
            } catch (\Throwable) {
            }
        }
        $session = $this->sessions->find($id) ?? [];
        return Response::json($this->formats->runResponse($format, $this->buildRunFromSession($session)));
    }

    /**
     * GET /v1/models - the active consensus models an API client may
     * request in a run. Read scope.
     */
    public function models(): string
    {
        $format = $this->responseFormat();
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/models', false);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        try {
            $models = [];
            foreach (Config::getActiveModels() as $m) {
                if (!is_array($m)) { continue; }
                $provider = strtolower(trim((string)($m['provider'] ?? '')));
                $model    = trim((string)($m['model'] ?? ''));
                if ($model === '') { continue; }
                $models[] = [
                    'id'       => $provider !== '' ? $provider . '/' . $model : $model,
                    'provider' => $provider,
                    'model'    => $model,
                ];
            }
        } catch (\Throwable $e) {
            $message = Logger::sanitizeScalar($e->getMessage());
            return Response::json(
                $this->formats->errorResponse($format, 500, 'models_unavailable', $message),
                500,
            );
        }
        return Response::json(['ok' => true, 'count' => count($models), 'models' => $models]);
    }

    /**
     * GET /v1/usage - aggregated platform usage for the requested
     * period (all|year|quarter|week|day|hour). Read scope. Requires
     * the optional StatisticsService wiring; without a database the
     * endpoint answers 503 service_unavailable.
     */
    public function usage(): string
    {
        $format = $this->responseFormat();
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/usage', false);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        $periodRaw = isset($_GET['period']) && is_string($_GET['period']) ? $_GET['period'] : '';
        $period    = StatisticsPeriod::normalize($periodRaw);
        // v2.1.0 (T5) — optional per-user scoping. An API key is an OPERATOR
        // credential (the table carries no user binding), so the caller
        // chooses the scope explicitly; omitting it keeps the historical
        // whole-installation summary.
        $userRaw = $_GET['user_id'] ?? null;
        $userId  = is_numeric($userRaw) && (int)$userRaw > 0 ? (int)$userRaw : null;

        if ($this->statistics === null) {
            return Response::json(
                $this->formats->errorResponse($format, 503, 'service_unavailable', 'Usage statistics are not configured on this host.'),
                503,
            );
        }
        $summary = $this->statistics->summary($period, null, $userId);
        if (!($summary['ok'] ?? false)) {
            $error   = (string)($summary['error'] ?? 'usage_unavailable');
            $message = (string)($summary['message'] ?? 'Usage statistics are unavailable.');
            return Response::json(
                $this->formats->errorResponse($format, 503, $error, $message),
                503,
            );
        }
        return Response::json($summary);
    }

    // ----------------------------------------------------------------
    // Launch branches
    // ----------------------------------------------------------------

    /**
     * Synchronous branch (async=false): run the pipeline in-process and
     * return the final run record rendered in the caller's format.
     */
    private function syncRun(string $query, ?int $userId, array $options, string $format): string
    {
        if ($this->runControl !== null) {
            $this->engine->setRunControl($this->runControl);
        }
        try {
            $result = $this->engine->run($query, $userId, $options);
        } catch (\Throwable $e) {
            $message = Logger::sanitizeScalar($e->getMessage());
            Logger::error('api.v1.sync_run.failed', ['message' => $message]);
            return Response::json(
                $this->formats->errorResponse($format, 500, 'internal_error', $message),
                500,
            );
        }

        $sessionId = (int)($result['session_id'] ?? 0);
        $session   = $this->sessions->find($sessionId) ?? [];

        $consensus = null;
        if (isset($result['consensus']) && is_object($result['consensus'])
            && method_exists($result['consensus'], 'toArray')
        ) {
            $consensus = $result['consensus']->toArray();
        } elseif (isset($result['consensus']) && is_array($result['consensus'])) {
            $consensus = $result['consensus'];
        }

        // One model's answer instead of a council verdict. `consensus` stays
        // null because nothing was compared - not `{"score":0}`, which would
        // read as "measured, and they disagreed". The keys are strictly
        // additive, so a client that only knows the consensus shape keeps
        // working and can still tell the two runs apart.
        $singleModel = ($result['run_mode'] ?? null) === PipelineEngine::MODE_SINGLE_MODEL;

        $run = [
            'run_id'       => 'run_' . $sessionId,
            'session_id'   => $sessionId,
            'job_id'       => null,
            'status'       => $this->mapSessionStatus((string)($session['status'] ?? 'running')),
            'created_at'   => (string)($session['created_at'] ?? date('c')),
            'final_answer' => (isset($result['final_answer']) && $result['final_answer'] !== '')
                ? (string)$result['final_answer']
                : null,
            'consensus'    => $singleModel ? null : $consensus,
            'model'        => $this->firstModel($result['selected_models'] ?? null),
            'usage'        => $this->usageFromResult($result),
            'urls'         => $this->runUrls($sessionId),
        ];
        if ($singleModel) {
            $run['run_mode']            = PipelineEngine::MODE_SINGLE_MODEL;
            $run['consensus_computed']  = false;
        }
        return Response::json($this->formats->runResponse($format, $run));
    }

    /**
     * Asynchronous branch (default): enqueue a durable job and answer
     * 202 Accepted with the polling/control URLs. Never blocks on LLM
     * calls.
     */
    /**
     * @param int|null $userId the identity createRun() resolved from the
     *        request. Passed through EXPLICITLY (even as null) so the async
     *        branch attributes the run exactly like the sync/stream branches:
     *        an external API caller that named no user produces an
     *        UNATTRIBUTED run — it must not silently adopt the console's
     *        local profile user (and with it that user's budgets), which is
     *        what JobService's web-oriented default resolver would do.
     */
    private function enqueueRun(array $input, string $format, ?int $userId = null): string
    {
        $input = $this->normalizeNativeModels($input);
        $input['user_id'] = $userId;
        try {
            $created = $this->jobs->createJob($input);
        } catch (BudgetExceededException $e) {
            // createRun() already gated this path; catching it anyway keeps
            // the 429 shape correct if the two checks ever race on a budget
            // that crosses its limit between them (spend is concurrent).
            return $this->budgetRefusal($format, $e->verdict, $e->retryAfter());
        } catch (\InvalidArgumentException $e) {
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', $e->getMessage()),
                400,
            );
        } catch (\Throwable $e) {
            $message = Logger::sanitizeScalar($e->getMessage());
            Logger::error('api.v1.enqueue.failed', ['message' => $message]);
            return Response::json(
                $this->formats->errorResponse($format, 500, 'enqueue_failed', $message),
                500,
            );
        }

        $sessionId = (int)($created['session_id'] ?? 0);
        $run = [
            'run_id'       => 'run_' . $sessionId,
            'session_id'   => $sessionId,
            'job_id'       => (int)($created['job_id'] ?? 0),
            'status'       => 'queued',
            'created_at'   => date('c'),
            'final_answer' => null,
            'consensus'    => null,
            'model'        => $this->firstModel($input['selected_models'] ?? null),
            'usage'        => null,
            'urls'         => $this->runUrls($sessionId),
        ];
        return Response::json($this->formats->runResponse($format, $run), 202);
    }

    /**
     * Streaming branch (stream=true): an event-stream response driven by
     * the pipeline inside the current request, guarded by the same
     * client_run_id lock the legacy endpoint uses.
     */
    private function streamRun(
        string $query,
        ?int   $userId,
        array  $options,
        string $clientRunId,
    ): string {
        $this->sendSseHeaders();
        $streamer = new SseEventStreamer();
        $streamer->comment('RevoAp API v1 consensus stream - keepalive');

        $locked = false;
        if ($this->streamLock !== null) {
            $existing = $this->streamLock->read($clientRunId);
            $locked   = $this->streamLock->acquire($clientRunId);
            if (!$locked) {
                $streamer->emit('PIPELINE_DUPLICATE_RUN_REJECTED', [
                    'client_run_id' => $clientRunId,
                    'reason'        => 'another worker is already running this client_run_id',
                    'existing'      => $existing,
                ], null);
                $streamer->emit('PIPELINE_DONE', [
                    'reason'        => 'duplicate_run_rejected',
                    'client_run_id' => $clientRunId,
                ], null);
                return '';
            }
            // Lock acquired: stream the pipeline and stamp the
            // session id onto the lock the moment the engine reports it.
            $self = $this;
            $streamer->setOnEmit(static function (string $type, array $payload) use ($self, $clientRunId): void {
                if ($type === 'PIPELINE_STARTED' && isset($payload['session_id'])) {
                    $sid = (int)$payload['session_id'];
                    if ($sid > 0) {
                        $self->streamLock->recordSession($clientRunId, $sid);
                    }
                }
            });
        }

        $this->engine->setEventStreamer($streamer);
        if ($this->runControl !== null) {
            $this->engine->setRunControl($this->runControl);
        }
        try {
            $this->engine->run($query, $userId, array_merge($options, [
                'client_run_id' => $clientRunId,
            ]));
        } catch (\Throwable $e) {
            $message   = Logger::sanitizeScalar($e->getMessage());
            $sessionId = $this->engine->currentSessionId();
            if ($sessionId !== null) {
                try {
                    $this->engine->finalizeRunningSteps($sessionId, $message);
                } catch (\Throwable) {
                }
            }
            $streamer->emit('PIPELINE_FATAL', [
                'message' => $message,
                'class'   => (new \ReflectionClass($e))->getShortName(),
            ], null);
        } finally {
            $this->engine->setEventStreamer(null);
            if ($locked && $this->streamLock !== null && $clientRunId !== '') {
                $this->streamLock->release($clientRunId);
            }
        }
        return '';
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Emit an ApiAuth rejection as a format-aware JSON error with the
     * gate's own status and (for 429) rate-limit headers.
     *
     * @param array<string,mixed> $gate
     */
    /**
     * Per-user budget gate for run creation (v2.1.0, T5).
     *
     * Returns a ready-to-send 429 body when the user's budget blocks new
     * runs, null when the run may proceed. A 'warn' verdict proceeds WITH
     * an X-RevoAp-Budget-Warning header: the run is the operator's money
     * decision to allow, and a header is the only place it can be reported
     * without changing the response shape an SDK parses.
     *
     * Enforcement failures never refuse a run: a quota check that errors
     * answers 'ok' inside the enforcer (fail-open by design, see
     * UserQuotaEnforcer), and anything thrown anyway is caught here — a
     * budget subsystem outage must not become a run-surface outage.
     */
    private function quotaGate(?int $userId, string $format): ?string
    {
        if ($this->quotas === null) {
            return null;
        }
        try {
            $verdict = $this->quotas->checkForRun($userId);
        } catch (\Throwable $e) {
            Logger::warn('api.v1.quota_check_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return null;
        }
        $action = (string)($verdict['action'] ?? 'ok');
        if ($action === 'block') {
            $retry = isset($verdict['retry_after']) && (int)$verdict['retry_after'] > 0
                ? (int)$verdict['retry_after'] : null;
            return $this->budgetRefusal($format, $verdict, $retry);
        }
        if ($action === 'warn') {
            $blocking = null;
            foreach ((array)($verdict['budgets'] ?? []) as $b) {
                if (is_array($b) && !empty($b['at_limit'])) {
                    $blocking = $b;
                    break;
                }
            }
            $label = is_array($blocking) ? (string)($blocking['name'] ?? 'budget') : 'budget';
            $this->applyHeaders([
                'X-RevoAp-Budget-Warning' => 'user budget at or over limit: ' . $label,
            ]);
        }
        return null;
    }

    /**
     * The 429 body + Retry-After for a budget refusal, rendered in the
     * caller's dialect. The payload names the budget, the spend, the limit
     * and the reset time: "no" without the numbers sends an integration
     * debugging its own code instead of its bill.
     *
     * @param array<string,mixed> $verdict
     */
    private function budgetRefusal(string $format, array $verdict, ?int $retryAfter): string
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            $this->applyHeaders(['Retry-After' => (string)$retryAfter]);
        }
        $blocking = is_array($verdict['blocking'] ?? null) ? $verdict['blocking'] : [];
        $name   = (string)($blocking['name'] ?? 'User budget');
        $spent  = $blocking['spent'] ?? null;
        $amount = $blocking['amount'] ?? null;
        $cur    = (string)($blocking['currency'] ?? 'USD');
        $resets = isset($blocking['resets_at']) && (int)$blocking['resets_at'] > 0
            ? gmdate('Y-m-d\TH:i:s\Z', (int)$blocking['resets_at']) : null;
        $message = sprintf(
            '%s exhausted%s%s. New runs are refused until the %s period resets%s.',
            $name,
            is_numeric($spent) && is_numeric($amount) ? sprintf(' (%s %s of %s %s)', $spent, $cur, $amount, $cur) : '',
            isset($blocking['cost_coverage']) && (float)$blocking['cost_coverage'] < 1.0
                ? '; note that some providers report no cost, so actual spend may be higher' : '',
            (string)($blocking['period'] ?? 'budget'),
            $resets !== null ? ' at ' . $resets : '',
        );
        $body = $this->formats->errorResponse($format, 429, 'budget_exceeded', $message);
        if ($format === FormatAdapter::FORMAT_NATIVE) {
            $body['budget'] = [
                'name'      => $name,
                'spent'     => $spent,
                'amount'    => $amount,
                'currency'  => $cur,
                'period'    => $blocking['period'] ?? null,
                'resets_at' => $resets,
            ];
        }
        return Response::json($body, 429);
    }

    /** @param array<string,mixed> $gate */
    private function gateFailure(array $gate, string $format): string
    {
        $status = (int)($gate['status'] ?? 401);
        $body = $this->formats->errorResponse(
            $format,
            $status,
            (string)($gate['error'] ?? 'error'),
            (string)($gate['message'] ?? 'Request rejected.'),
        );
        if (isset($gate['headers']) && is_array($gate['headers'])) {
            $this->applyHeaders($gate['headers']);
        }
        return Response::json($body, $status);
    }

    /** Attach validated response headers (rate-limit bookkeeping). */
    private function applyHeaders(array $headers): void
    {
        if (headers_sent()) { return; }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1 || !is_scalar($value)) {
                continue;
            }
            header($name . ': ' . $value);
        }
    }

    /** Default async=true; false|0|off|no selects the synchronous branch. */
    private function wantAsync(array $body): bool
    {
        if (!array_key_exists('async', $body)) {
            return true;
        }
        $value = $body['async'];
        if (is_bool($value)) { return $value; }
        if (is_int($value) || is_float($value)) { return (int)$value !== 0; }
        if (is_string($value)) {
            $s = strtolower(trim($value));
            if ($s === '0' || $s === 'false' || $s === 'off' || $s === 'no') {
                return false;
            }
            return true;
        }
        return true;
    }

    /** Route-provided format hint: ?format=native|openai|anthropic. */
    private function formatHint(): ?string
    {
        $hint = $_GET['format'] ?? null;
        if (is_string($hint) && trim($hint) !== '') {
            return strtolower(trim($hint));
        }
        return null;
    }

    /**
     * Format of the RESPONSE rendering, selected by ?format=. Native by
     * default; openai/anthropic render SDK-shaped run records.
     */
    private function responseFormat(): string
    {
        $hint = $_GET['format'] ?? null;
        if (is_string($hint) && in_array(strtolower(trim($hint)), FormatAdapter::FORMATS, true)) {
            return strtolower(trim($hint));
        }
        return FormatAdapter::FORMAT_NATIVE;
    }

    /** Clamp a client-supplied limit into [1, max]; invalid -> default. */
    private function boundedLimit(mixed $raw, int $default, int $max): int
    {
        if (is_numeric($raw)) {
            return max(1, min($max, (int)$raw));
        }
        return $default;
    }

    /**
     * Run ids in the URL are session ids (run_<sessionId>). Accept both
     * the plain numeric and the run_-prefixed form.
     *
     * @param array<string,mixed> $params
     */
    private function parseRunId(array $params): ?int
    {
        $raw = $params['id'] ?? null;
        if (is_string($raw) && str_starts_with(strtolower($raw), 'run_')) {
            $raw = substr($raw, 4);
        }
        if (is_numeric($raw)) {
            $id = (int)$raw;
            return $id > 0 ? $id : null;
        }
        return null;
    }

    /**
     * Canonical run record for a session id. Prefers the durable job
     * (authoritative for API-launched runs), falls back to the
     * council_sessions row for synchronous/legacy runs.
     *
     * @return array<string,mixed>|null
     */
    private function resolveRun(int $sessionId): ?array
    {
        $job = $this->jobs->getBySessionId($sessionId);
        if ($job !== null) {
            return $this->buildRunFromJob($job);
        }
        $session = $this->sessions->find($sessionId);
        return $session === null ? null : $this->buildRunFromSession($session);
    }

    /**
     * Canonical run record from a durable Job. Pulls final_answer /
     * consensus / usage out of the worker-emitted result envelope when
     * present.
     *
     * @return array<string,mixed>
     */
    private function buildRunFromJob(Job $job): array
    {
        $result = $job->result ?? [];
        $session = $this->sessions->find($job->sessionId) ?? [];

        $finalAnswer = (isset($result['final_answer']) && $result['final_answer'] !== '')
            ? (string)$result['final_answer']
            : $this->finalAnswerFromSession($session);

        $consensus = is_array($result['consensus'] ?? null) ? $result['consensus'] : null;
        if ($consensus === null) {
            $consensus = [
                'score'  => $session['consensus_score'] ?? null,
                'status' => (string)($session['consensus_status'] ?? 'pending'),
            ];
        }

        $model = $this->firstModel($job->payload['selected_models'] ?? null);
        if ($model === null) {
            $model = $this->modelLabelFromResult($result);
        }

        $usage = $this->usageFromEnvelope($result);
        if ($usage === null) {
            $usage = $this->usageFromSession($session);
        }

        $status = $this->mapJobStatus($job->status);
        // T6 (beta pass): the SESSION is the canonical run record; the job
        // is the work envelope. A job that reached terminal `completed`
        // while its session stands `failed` — legacy rows from before the
        // runner learned to fail such jobs, or any future divergence —
        // must not make the API paint a green run that has no answer.
        if ($status === 'completed' && (string)($session['status'] ?? '') === 'failed') {
            $status = 'failed';
        }

        return [
            'run_id'       => 'run_' . $job->sessionId,
            'session_id'   => $job->sessionId,
            'job_id'       => $job->id,
            'status'       => $status,
            'created_at'   => (string)($session['created_at'] ?? $job->createdAt),
            'final_answer' => $finalAnswer,
            'consensus'    => $consensus,
            'model'        => $model,
            'usage'        => $usage,
            'urls'         => $this->runUrls($job->sessionId),
        ];
    }

    /**
     * Canonical run record from a council_sessions row (sync/legacy).
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function buildRunFromSession(array $session): array
    {
        $id = (int)($session['id'] ?? 0);
        return [
            'run_id'       => 'run_' . $id,
            'session_id'   => $id,
            'job_id'       => null,
            'status'       => $this->mapSessionStatus((string)($session['status'] ?? 'running')),
            'created_at'   => (string)($session['created_at'] ?? date('c')),
            'final_answer' => $this->finalAnswerFromSession($session),
            'consensus'    => [
                'score'  => $session['consensus_score'] ?? null,
                'status' => (string)($session['consensus_status'] ?? 'pending'),
            ],
            'model'        => null,
            'usage'        => $this->usageFromSession($session),
            'urls'         => $this->runUrls($id),
        ];
    }

    /** Map a JobStatus enum onto the canonical run status set. */
    private function mapJobStatus(JobStatus $status): string
    {
        return match ($status) {
            JobStatus::Completed => 'completed',
            JobStatus::Failed    => 'failed',
            JobStatus::Cancelled, JobStatus::CancelRequested => 'cancelled',
            JobStatus::Queued    => 'queued',
            default              => 'running',
        };
    }

    /** @param array<string,mixed> $session */
    private function finalAnswerFromSession(array $session): ?string
    {
        return (isset($session['final_answer']) && $session['final_answer'] !== '')
            ? (string)$session['final_answer']
            : null;
    }

    /**
     * Usage block from a worker result envelope. Tolerant to the common
     * envelope shapes (usage block or stats block); null when absent.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>|null
     */
    private function usageFromEnvelope(array $result): ?array
    {
        $usage = $result['usage'] ?? null;
        if (is_array($result['stats'] ?? null) && !is_array($usage)) {
            $usage = $result['stats'];
        }
        if (!is_array($usage)) {
            return null;
        }
        $prompt     = (int)($usage['prompt_tokens'] ?? 0);
        $completion = (int)($usage['completion_tokens'] ?? 0);
        $total      = (int)($usage['total_tokens'] ?? 0);
        if ($total === 0) {
            $total = $prompt + $completion;
        }
        if ($prompt === 0 && $completion === 0 && $total === 0) {
            return null;
        }
        return [
            'prompt_tokens'     => $prompt,
            'completion_tokens' => $completion,
            'total_tokens'      => $total,
        ];
    }

    /**
     * Usage from a council_sessions row: only total_tokens is durable.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>|null
     */
    private function usageFromSession(array $session): ?array
    {
        $total = (int)($session['total_tokens'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        return ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => $total];
    }

    /** @param array<string,mixed> $result */
    private function modelLabelFromResult(array $result): ?string
    {
        $model = trim((string)($result['model'] ?? ''));
        if ($model !== '') {
            return $model;
        }
        return $this->firstModel($result['selected_models'] ?? null);
    }

    /**
     * Normalize a pipeline_events row for JSON. Payload may be stored as
     * a JSON string (DB) or as a native array (memory mode).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function normalizeEventRow(array $row): ?array
    {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $payload = $row['payload'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($payload)) {
            $payload = null;
        }
        return [
            'id'         => $id,
            'session_id' => (int)($row['session_id'] ?? 0),
            'step_id'    => isset($row['step_id']) ? (int)$row['step_id'] : null,
            'event_type' => (string)($row['event_type'] ?? ''),
            'payload'    => $payload,
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    /**
     * Build the runtime options the PipelineEngine understands from a
     * parsed input envelope. Mirrors CouncilController::extractRunOptions.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function buildRunOptions(array $input): array
    {
        $opts = [];
        foreach (['selected_models', 'reasoning', 'consensus_skill', 'domain', 'profile', 'analyzer'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                $opts[$key] = $input[$key];
            }
        }
        // Single-model mode (one model, the skill's system prompts, no
        // consensus). Literal truthy values only, so a client that sends
        // `"no"`/`0` gets the consensus pipeline rather than an accidental
        // single call. The engine reports `run_mode` back either way - it
        // degrades to the consensus path, with a reason, when the database
        // cannot store the "single" verdict yet.
        $single = $input['single_model'] ?? null;
        if ($single === true || $single === 1 || $single === '1' || $single === 'true') {
            $opts['single_model'] = true;
        }
        if (isset($input['cycle_limit'])) {
            $raw = $input['cycle_limit'];
            if (is_array($raw)) {
                $raw = $raw['cycle_limit'] ?? null;
            }
            if (is_string($raw) && is_numeric($raw)) {
                $opts['cycle_limit'] = (int)$raw;
            } elseif (is_int($raw)) {
                $opts['cycle_limit'] = $raw;
            } elseif (is_float($raw)) {
                $opts['cycle_limit'] = (int)$raw;
            }
        }
        return $opts;
    }

    /**
     * Native envelopes may carry `models` instead of `selected_models`.
     * Normalize to the JobService canonical [{provider, model}] shape.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalizeNativeModels(array $input): array
    {
        if (isset($input['selected_models']) || !isset($input['models']) || !is_array($input['models'])) {
            return $input;
        }
        $rows = [];
        foreach ($input['models'] as $row) {
            if (!is_array($row)) { continue; }
            $provider = strtolower(trim((string)($row['provider'] ?? $row['connector'] ?? '')));
            $model    = trim((string)($row['model'] ?? ''));
            if ($provider === '' || $model === '') { continue; }
            $rows[] = ['provider' => $provider, 'model' => $model];
        }
        if ($rows !== []) {
            $input['selected_models'] = $rows;
        }
        return $input;
    }

    /** Stable client_run_id: caller-supplied (sanitized) or a fingerprint. */
    private function resolveClientRunId(array $input): string
    {
        $raw = $input['client_run_id'] ?? null;
        if (is_string($raw)) {
            $clean = preg_replace('/[^A-Za-z0-9_-]/', '', trim($raw)) ?? '';
            if ($clean !== '') {
                return 'api_' . substr($clean, 0, 64);
            }
        }
        $query   = (string)($input['query'] ?? '');
        $options = $this->buildRunOptions($input);
        $fp      = json_encode($options, JSON_UNESCAPED_SLASHES);
        return 'fp_' . substr(hash('sha256', $query . '|' . (string)$fp), 0, 24);
    }

    /**
     * Usage block ({prompt_tokens, completion_tokens, total_tokens})
     * aggregated from the pipeline SessionStats.
     */
    private function usageFromResult(array $result): ?array
    {
        $stats = $result['stats'] ?? null;
        if (!$stats instanceof SessionStats) {
            return null;
        }
        $prompt = 0;
        $completion = 0;
        foreach ($stats->perModel as $usage) {
            $prompt     += $usage->promptTokens;
            $completion += $usage->completionTokens;
        }
        $total = $stats->totalTokens;
        if ($total < $prompt + $completion && ($prompt > 0 || $completion > 0)) {
            $total = $prompt + $completion;
        }
        return [
            'prompt_tokens'     => $prompt,
            'completion_tokens' => $completion,
            'total_tokens'      => $total,
        ];
    }

    /** @return array{events_url:string,result_url:string,cancel_url:string} */
    private function runUrls(int $sessionId): array
    {
        return [
            'events_url' => '/v1/runs/' . $sessionId . '/events',
            'result_url' => '/v1/runs/' . $sessionId . '/result',
            'cancel_url' => '/v1/runs/' . $sessionId . '/cancel',
        ];
    }

    /** Map council_sessions statuses onto the canonical run status set. */
    private function mapSessionStatus(string $status): string
    {
        return match ($status) {
            'completed'          => 'completed',
            'failed'             => 'failed',
            'rejected', 'cancelled' => 'cancelled',
            'queued'             => 'queued',
            default              => 'running',
        };
    }

    /** First selected model as "provider/model" (or null when absent). */
    private function firstModel(mixed $selectedModels): ?string
    {
        if (!is_array($selectedModels) || $selectedModels === []) {
            return null;
        }
        $row = reset($selectedModels);
        if (!is_array($row)) {
            return null;
        }
        $provider = strtolower(trim((string)($row['provider'] ?? '')));
        $model    = trim((string)($row['model'] ?? ''));
        if ($model === '') {
            return null;
        }
        return $provider !== '' ? $provider . '/' . $model : $model;
    }

    /** Read the decoded JSON body (or the injected test override). */
    private function readJsonBody(): array
    {
        if ($this->bodyOverride !== null) {
            return $this->bodyOverride;
        }
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return is_array($_POST) && $_POST !== [] ? $_POST : [];
        }
        try {
            $decoded = JsonHelper::tryDecode($raw);
        } catch (\Throwable $e) {
            $jsonError = json_last_error_msg();
            throw new \InvalidArgumentException('malformed request body: ' . ($jsonError !== '' ? $jsonError : $e->getMessage()));
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('malformed request body: JSON object expected');
        }
        return $decoded;
    }

    /**
     * SSE headers + output-buffer discipline, mirroring the legacy
     * CouncilController::stream() contract.
     */
    private function sendSseHeaders(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('X-Content-Type-Options: nosniff');
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);
        // Same rationale as the legacy endpoint: keep the worker alive
        // even if the EventSource client drops, and stretch the default
        // 30s ceiling for multi-cycle consensus runs.
        @ignore_user_abort(true);
        @set_time_limit(0);
    }

    /**
     * GET /v1/runs/{id}/stream — replay a finished run as an SDK-dialect stream.
     *
     * Distinct from the private {@see self::streamRun()}, which streams a LIVE
     * pipeline inside a POST /v1/runs request. This one replays an already
     * completed run in the OpenAI / Anthropic / native frame dialects, for SDK
     * clients that fetch a result rather than drive a run.
     *
     * Same authentication, same run resolution and same error envelope as
     * {@see self::getRunResult()}; only the rendering differs. Reusing that path
     * is the point: a second entry point with its own auth check is how an
     * endpoint ends up reachable when its sibling is not.
     *
     * The dialect is chosen by the caller and validated against the closed set,
     * because it selects a *serialiser* — an unvalidated value here would be an
     * unvalidated branch selector.
     *
     * Note this streams a COMPLETED run, not a live one. RevoAp's pipeline is a
     * council that produces one final answer; there is no token source to
     * forward. Presenting this as live generation would be a lie about what
     * happened, and would break any client that reasons about token boundaries.
     * Live progress is the existing /api/council/stream endpoint.
     *
     * @param array<string,string> $params
     */
    public function replayRunStream(array $params, string $format): string
    {
        $allowed = [
            FormatAdapter::FORMAT_NATIVE,
            FormatAdapter::FORMAT_OPENAI,
            FormatAdapter::FORMAT_ANTHROPIC,
        ];
        if (!in_array($format, $allowed, true)) {
            $format = FormatAdapter::FORMAT_NATIVE;
        }
        $id = $this->parseRunId($params);
        if ($id === null) {
            return Response::json(
                $this->formats->errorResponse($format, 400, 'invalid_request', 'A numeric run id is required.'),
                400,
            );
        }
        $gate = $this->auth->check($_SERVER, $_GET, '/v1/runs/' . $id . '/stream', false);
        if (!$gate['ok']) {
            return $this->gateFailure($gate, $format);
        }
        $this->applyHeaders($gate['headers'] ?? []);

        $run = $this->resolveRun($id);
        if ($run === null) {
            return Response::json(
                $this->formats->errorResponse($format, 404, 'not_found', 'Run not found.'),
                404,
            );
        }

        // From here the response is a stream: headers are already committed by
        // Response::sse(), so every subsequent failure has to travel AS a frame.
        // Returning a JSON error body after SSE headers would produce a document
        // the client cannot parse and cannot distinguish from a corrupt stream.
        Response::sse();
        foreach (\App\Api\Protocol\StreamEncoder::encode($format, $run) as $frame) {
            echo $frame;
            if (ob_get_level() > 0) { @ob_flush(); }
            @flush();
            // A client that disconnected must stop the loop: continuing writes
            // into a dead socket for the length of a large answer, and on a
            // shared host that is CPU and bandwidth spent for nobody.
            if (connection_aborted()) { break; }
        }
        return '';
    }
}
