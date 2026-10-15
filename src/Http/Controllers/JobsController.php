<?php
/**
 * JobsController: HTTP endpoints for the Worker Architecture v2 durable
 * job layer (Phase 1).
 *
 *   POST /api/council/jobs                 enqueue a new consensus job
 *   GET  /api/council/jobs/{id}            browser-safe status snapshot
 *   POST /api/council/jobs/{id}/pause      intent: pause_requested
 *   POST /api/council/jobs/{id}/resume     intent: resume_requested
 *   POST /api/council/jobs/{id}/cancel     intent: cancel_requested
 *   POST /api/council/jobs/{id}/run-consensus  intent: consensus_requested
 *
 * Plus a complementary SSE replay endpoint that streams persisted
 * pipeline_events without invoking the LLM pipeline:
 *
 *   GET /api/council/session/{id}/events/stream[?live=1]
 *
 * The replay endpoint supports `after_event_id` (query string) and the
 * `Last-Event-ID` header so EventSource clients can reconnect and
 * pick up missed events. When `live=1` is passed the connection stays
 * open and polls pipeline_events as the v1.8.36 worker writes new rows;
 * the loop exits as soon as the matching job reaches a terminal status
 * or the stream window elapses. The terminal `PIPELINE_REPLAY_DONE`
 * frame carries the current job_status so the browser knows whether to
 * reconnect with Last-Event-ID or stop.
 *
 * v1.8.42 — server-side export endpoints for the durable audit timeline
 * so the History UI, curl, the CLI and external integrations can grab
 * a downloadable JSON or CSV bundle of the sanitised events:
 *
 *   GET /api/council/jobs/{id}/timeline/export?format=json|csv&...
 *   GET /api/council/session/{id}/job-timeline/export?format=json|csv&...
 *
 * Both endpoints honour the v1.8.41 client-side filter UX (category,
 * event_type, q, include_context) so the server bundle matches what
 * the operator sees on screen.
 *
 * No route in this controller dispatches the LLM pipeline itself — the
 * Phase 2 PHP CLI worker (`bin/revoap-worker.php`) polls
 * `revoap_jobs` and runs the pipeline outside of PHP-FPM.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.2.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Batch\BatchCanceller;
use App\Jobs\Job;
use App\Jobs\JobAuditEvent;
use App\Jobs\JobAuditRepository;
use App\Jobs\JobService;
use App\Jobs\JobStatus;
use App\Storage\StepRepository;

final class JobsController
{
    public function __construct(
        private readonly JobService              $jobs,
        private readonly StepRepository          $steps,
        private readonly ?JobAuditRepository     $audit = null,
        private readonly ?BatchCanceller         $batchCanceller = null,
    ) {}

    /**
     * v1.8.37 — Soft ceiling (seconds) on how long a single replay/streaming
     * connection stays open before returning PIPELINE_REPLAY_DONE so the
     * EventSource client can decide whether to reconnect (still queued/
     * running) or stop (terminal status). Mirrors the proxy idle-timeout
     * a typical Apache/nginx deploy uses.
     */
    private const int STREAM_WINDOW_SECONDS = 55;

    /**
     * Poll interval (seconds) used by the streaming loop when no new
     * events have landed yet. Kept short so PIPELINE_PAUSED / L1_RESPONSE
     * frames reach the browser quickly without burning the DB.
     */
    private const float STREAM_POLL_INTERVAL = 0.5;

    /**
     * POST /api/council/jobs — enqueue a durable consensus job.
     * Returns quickly without running any LLM call.
     */
    public function create(): string
    {
        $payload = $this->readJsonBody();
        // Bring along origin/UA for the request_meta block (best-effort,
        // no auth headers are forwarded).
        if (!isset($payload['origin']) && isset($_SERVER['HTTP_REFERER'])) {
            $payload['origin'] = (string)$_SERVER['HTTP_REFERER'];
        }
        if (!isset($payload['user_agent']) && isset($_SERVER['HTTP_USER_AGENT'])) {
            $payload['user_agent'] = (string)$_SERVER['HTTP_USER_AGENT'];
        }
        // "Run via Batch API" is a different enqueue, not a flag on the
        // synchronous one: the job kind decides which executor the worker
        // routes it to ({@see \App\Batch\BatchPipeline}), and the two have
        // different payload contracts. The switch is read from the request
        // body so the existing endpoint and its response shape are unchanged
        // for every caller that does not ask for it.
        $runMode = strtolower(trim((string)($payload['run_mode'] ?? $payload['mode'] ?? '')));
        try {
            $created = $runMode === 'batch'
                ? $this->jobs->createBatchJob($payload)
                : $this->jobs->createJob($payload);
        } catch (\App\Admin\Budgets\BudgetExceededException $e) {
            // v2.1.0 (T5) — per-user budget refusal. 429 + Retry-After, the
            // same shape /v1/runs answers with, so one client behaviour
            // ("back off until the period resets") works on both surfaces.
            $retry = $e->retryAfter();
            if ($retry !== null && !headers_sent()) {
                header('Retry-After: ' . $retry);
            }
            return Response::json([
                'ok'      => false,
                'error'   => 'budget_exceeded',
                'message' => $e->getMessage(),
                'budget'  => $e->verdict['blocking'] ?? null,
            ], 429);
        } catch (\InvalidArgumentException $e) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_request',
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            return Response::json([
                'ok'      => false,
                'error'   => 'enqueue_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
        return Response::json([
            'ok' => true,
            ...$created,
        ], 201);
    }

    /**
     * @param array<string,string> $params
     */
    public function get(array $params): string
    {
        $id  = (int)($params['id'] ?? 0);
        $job = $this->jobs->get($id);
        if ($job === null) {
            return Response::json([
                'ok'    => false,
                'error' => 'job_not_found',
                'id'    => $id,
            ], 404);
        }
        return Response::json([
            'ok'  => true,
            'job' => $job->toPublicArray(),
        ]);
    }

    /** @param array<string,string> $params */
    public function pause(array $params): string
    {
        return $this->intent($params, JobStatus::PauseRequested);
    }

    /** @param array<string,string> $params */
    public function resume(array $params): string
    {
        return $this->intent($params, JobStatus::ResumeRequested);
    }

    /**
     * POST /api/council/jobs/{id}/cancel
     *
     * A batch job cannot be cancelled the way a synchronous one is. Its row
     * sleeps between polls, and the batch it owns is running — and billing
     * — on the provider, so stopping it locally would leave that batch
     * running with nobody left to stop it. {@see BatchCanceller} records
     * the intent where the batch executor reads its state from and wakes
     * the parked row, and the executor then calls
     * `POST /batches/{id}/cancel` before it flips the job.
     *
     * Every other job kind keeps the plain cooperative intent, unchanged.
     *
     * @param array<string,string> $params
     */
    public function cancel(array $params): string
    {
        $id  = (int)($params['id'] ?? 0);
        $job = $id > 0 ? $this->jobs->get($id) : null;
        if ($job !== null && $this->batchCanceller !== null && BatchCanceller::handles($job)) {
            $outcome = $this->batchCanceller->request($job);
            if (($outcome['applied'] ?? false) === true) {
                $fresh = $this->jobs->get($id) ?? $job;
                return Response::json([
                    'ok'            => true,
                    'status'        => $fresh->status->value,
                    'cancel_mode'   => 'batch',
                    'batch_rearmed' => (bool)($outcome['rearmed'] ?? false),
                    'message'       => (string)$outcome['message'],
                    'job'           => $fresh->toPublicArray(),
                ]);
            }
            // Fall through to the generic intent so a terminal job still
            // gets the normal "cannot be cancelled" behaviour.
        }
        return $this->intent($params, JobStatus::CancelRequested);
    }

    /** @param array<string,string> $params */
    public function runConsensus(array $params): string
    {
        return $this->intent($params, JobStatus::ConsensusRequested);
    }

    /**
     * GET /api/council/jobs/{id}/timeline
     *
     * Returns the chronologically ordered list of durable audit events
     * recorded for one job (when it was created, queued, acquired by a
     * worker, heartbeats lapsed, requeued, exhausted, completed, …) plus
     * a compact `job` summary block. No secrets — context_json is
     * recursively sanitized by JobAuditEvent::toPublicArray().
     *
     * @param array<string,string> $params
     */
    public function timeline(array $params): string
    {
        $id  = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $job = $this->jobs->get($id);
        if ($job === null) {
            return Response::json([
                'ok'    => false,
                'error' => 'job_not_found',
                'id'    => $id,
            ], 404);
        }
        $events = $this->audit !== null && $this->audit->tableAvailable()
            ? $this->audit->listByJobId($id)
            : [];
        return Response::json([
            'ok'         => true,
            'job'        => self::jobSummary($job),
            'events'     => array_map(static fn(JobAuditEvent $e): array => $e->toPublicArray(), $events),
            'audit_table_available' => $this->audit !== null && $this->audit->tableAvailable(),
        ]);
    }

    /**
     * GET /api/council/session/{id}/job-timeline
     *
     * Like timeline() but keyed by session id. Returns the most recent
     * job for the session plus its full audit event list. Legacy sessions
     * that pre-date the durable job layer get an empty events array and
     * an explanatory `note` so the History UI can render a neutral
     * "No durable job timeline recorded for this legacy run." message.
     *
     * @param array<string,string> $params
     */
    public function sessionTimeline(array $params): string
    {
        $sessionId = (int)($params['id'] ?? 0);
        if ($sessionId <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $job = $this->jobs->getBySessionId($sessionId);
        $auditAvailable = $this->audit !== null && $this->audit->tableAvailable();
        // Session-scoped events span every job that ran for this session.
        $events = $auditAvailable ? $this->audit->listBySessionId($sessionId) : [];
        if ($job === null && $events === []) {
            return Response::json([
                'ok'                    => true,
                'session_id'            => $sessionId,
                'job'                   => null,
                'events'                => [],
                'audit_table_available' => $auditAvailable,
                'note'                  => 'No durable job timeline recorded for this legacy run.',
            ]);
        }
        return Response::json([
            'ok'                    => true,
            'session_id'            => $sessionId,
            'job'                   => $job !== null ? self::jobSummary($job) : null,
            'events'                => array_map(static fn(JobAuditEvent $e): array => $e->toPublicArray(), $events),
            'audit_table_available' => $auditAvailable,
        ]);
    }

    /**
     * GET /api/council/jobs/{id}/timeline/export?format=json|csv
     *
     * v1.8.42 — server-side download of the sanitised audit timeline
     * for one job. The query string mirrors the History UI filter
     * toolbar (category / event_type / q / include_context) so curl,
     * the CLI and external integrations can grab the same bundle the
     * operator sees on screen.
     *
     * @param array<string,string> $params
     */
    public function exportJobTimeline(array $params): string
    {
        $jobId = (int)($params['id'] ?? 0);
        if ($jobId <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $format = self::resolveFormat();
        if ($format === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'unsupported_format',
                'message' => 'Supported formats: json, csv.',
            ], 400);
        }
        $job = $this->jobs->get($jobId);
        if ($job === null) {
            return Response::json([
                'ok'    => false,
                'error' => 'job_not_found',
                'id'    => $jobId,
            ], 404);
        }
        $auditAvailable = $this->audit !== null && $this->audit->tableAvailable();
        $events = $auditAvailable ? $this->audit->listByJobId($jobId) : [];
        return $this->emitExport(
            format: $format,
            scope: 'job',
            sessionId: $job->sessionId,
            jobId: $jobId,
            job: $job,
            events: $events,
            auditAvailable: $auditAvailable,
            note: null,
        );
    }

    /**
     * GET /api/council/session/{id}/job-timeline/export?format=json|csv
     *
     * v1.8.42 — server-side download of the sanitised audit timeline
     * for an entire session. Mirrors sessionTimeline() but materialises
     * a JSON bundle or RFC-4180 CSV stream with the same filters the
     * History UI exposes. The optional `job_id` query parameter narrows
     * the export to a single job inside a session that retried after
     * exhaustion.
     *
     * @param array<string,string> $params
     */
    public function exportSessionTimeline(array $params): string
    {
        $sessionId = (int)($params['id'] ?? 0);
        if ($sessionId <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $format = self::resolveFormat();
        if ($format === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'unsupported_format',
                'message' => 'Supported formats: json, csv.',
            ], 400);
        }
        $job = $this->jobs->getBySessionId($sessionId);
        $auditAvailable = $this->audit !== null && $this->audit->tableAvailable();
        $events = $auditAvailable ? $this->audit->listBySessionId($sessionId) : [];

        // Optional narrowing to a single job_id inside this session.
        $jobIdFilter = isset($_GET['job_id']) && is_numeric($_GET['job_id'])
            ? max(0, (int)$_GET['job_id'])
            : 0;
        if ($jobIdFilter > 0) {
            $events = array_values(array_filter(
                $events,
                static fn(JobAuditEvent $e): bool => $e->jobId === $jobIdFilter
            ));
        }

        $note = null;
        if ($job === null && $events === []) {
            $note = 'No durable job timeline recorded for this legacy run.';
        }

        return $this->emitExport(
            format: $format,
            scope: 'session',
            sessionId: $sessionId,
            jobId: $jobIdFilter > 0 ? $jobIdFilter : ($job?->id),
            job: $job,
            events: $events,
            auditAvailable: $auditAvailable,
            note: $note,
        );
    }

    /**
     * Shared materializer for both export endpoints. Applies the v1.8.41
     * filter taxonomy and emits either a downloadable JSON bundle
     * (`revoap.job_timeline.v1`) or an RFC-4180 CSV stream.
     *
     * @param list<JobAuditEvent> $events
     */
    private function emitExport(
        string $format,
        string $scope,
        int $sessionId,
        ?int $jobId,
        ?Job $job,
        array $events,
        bool $auditAvailable,
        ?string $note,
    ): string {
        $filter = self::buildExportFilter($format);

        $publicEvents = array_map(
            static fn(JobAuditEvent $e): array => $e->toPublicArray(),
            $events
        );
        $totalCount = count($publicEvents);
        $filteredEvents = self::applyExportFilter($publicEvents, $filter);
        $filteredCount = count($filteredEvents);

        if (!$filter['include_context']) {
            foreach ($filteredEvents as $i => $row) {
                unset($row['context']);
                $filteredEvents[$i] = $row;
            }
        }

        $filename = self::buildFilename($scope, $sessionId, $jobId, $format);

        if ($format === 'csv') {
            return $this->emitCsv($filteredEvents, $filename, $filter['include_context']);
        }

        $bundle = [
            'ok'                    => true,
            'format'                => 'revoap.job_timeline.v1',
            'app'                   => 'RevoAp',
            'exported_at'           => gmdate('Y-m-d\TH:i:s\Z'),
            'scope'                 => $scope,
            'session_id'            => $sessionId,
            'job_id'                => $jobId,
            'job'                   => $job !== null ? self::jobSummary($job) : null,
            'filter'                => [
                'category'        => $filter['category'],
                'event_type'      => $filter['event_type'],
                'q'               => $filter['q'],
                'include_context' => $filter['include_context'],
                'job_id'          => $filter['job_id'],
            ],
            'event_count_total'     => $totalCount,
            'event_count_exported'  => $filteredCount,
            'audit_table_available' => $auditAvailable,
            'events'                => $filteredEvents,
        ];
        if ($note !== null) {
            $bundle['note'] = $note;
        }

        return $this->emitJsonAttachment($bundle, $filename);
    }

    /**
     * Map a free-form `format` query argument to the canonical lowercase
     * keyword. Defaults to json when omitted. Returns null on anything
     * other than json/csv so the caller can render a structured 400.
     */
    private static function resolveFormat(): ?string
    {
        $raw = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'json';
        if ($raw === '') { return 'json'; }
        return match ($raw) {
            'json' => 'json',
            'csv'  => 'csv',
            default => null,
        };
    }

    /**
     * Parse the query string into the canonical filter shape shared by
     * both export endpoints. `include_context` defaults to 1 for JSON
     * (bundle parity with the UI) and 0 for CSV (keeps the row width
     * predictable for spreadsheet consumers).
     *
     * @return array{
     *     category:string,
     *     event_type:string,
     *     q:string,
     *     include_context:bool,
     *     job_id:int
     * }
     */
    private static function buildExportFilter(string $format): array
    {
        $category = isset($_GET['category']) ? trim((string)$_GET['category']) : 'all';
        if ($category === '') { $category = 'all'; }
        // Accept the legacy `result/error` spelling as a synonym for the
        // canonical `result_error` category so the UI dash variant and
        // the underscore variant both work over curl.
        if ($category === 'result-error' || $category === 'result/error') {
            $category = 'result_error';
        }
        $allowedCategories = ['all', 'lifecycle', 'worker', 'recovery', 'control', 'result_error'];
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'all';
        }

        $eventType = isset($_GET['event_type']) ? trim((string)$_GET['event_type']) : '';
        if (mb_strlen($eventType) > 64) { $eventType = mb_substr($eventType, 0, 64); }

        $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
        if (mb_strlen($q) > 200) { $q = mb_substr($q, 0, 200); }

        $default = $format === 'csv' ? false : true;
        $include = $default;
        if (isset($_GET['include_context'])) {
            $raw = (string)$_GET['include_context'];
            if ($raw === '' || $raw === '0' || strcasecmp($raw, 'false') === 0 || strcasecmp($raw, 'no') === 0) {
                $include = false;
            } elseif ($raw === '1' || strcasecmp($raw, 'true') === 0 || strcasecmp($raw, 'yes') === 0) {
                $include = true;
            }
        }

        $jobId = isset($_GET['job_id']) && is_numeric($_GET['job_id'])
            ? max(0, (int)$_GET['job_id'])
            : 0;

        return [
            'category'        => $category,
            'event_type'      => $eventType,
            'q'               => $q,
            'include_context' => $include,
            'job_id'          => $jobId,
        ];
    }

    /**
     * Server-side mirror of the v1.8.41 history.js applyTimelineFilter
     * helper. The categorisation taxonomy must stay in lockstep with the
     * client so the same events are visible regardless of whether export
     * is triggered from the browser or curl.
     *
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed>       $filter
     * @return list<array<string,mixed>>
     */
    private static function applyExportFilter(array $events, array $filter): array
    {
        $cat = (string)($filter['category'] ?? 'all');
        $needle = strtolower(trim((string)($filter['q'] ?? '')));
        $eventType = (string)($filter['event_type'] ?? '');
        $out = [];
        foreach ($events as $ev) {
            $type = (string)($ev['event_type'] ?? '');
            if ($cat !== 'all' && self::categoryFor($type) !== $cat) { continue; }
            if ($eventType !== '' && $type !== $eventType) { continue; }
            if ($needle !== '') {
                $hay = strtolower(implode("\n", [
                    (string)($ev['event_type']  ?? ''),
                    (string)($ev['status_from'] ?? ''),
                    (string)($ev['status_to']   ?? ''),
                    (string)($ev['reason']      ?? ''),
                    (string)($ev['message']     ?? ''),
                    (string)($ev['worker_id']   ?? ''),
                ]));
                if (strpos($hay, $needle) === false) { continue; }
            }
            $out[] = $ev;
        }
        return $out;
    }

    /**
     * Canonical event_type → category mapping. Kept in sync with the
     * TIMELINE_CATEGORIES constant in assets/js/history.js.
     */
    private static function categoryFor(string $eventType): string
    {
        static $map = [
            'job.created'        => 'lifecycle',
            'job.queued'         => 'lifecycle',
            'worker.running'     => 'lifecycle',
            'consensus_requested'=> 'lifecycle',
            'job.completed'      => 'lifecycle',
            'job.failed'         => 'lifecycle',
            'status.changed'     => 'lifecycle',
            'worker.acquired'    => 'worker',
            'lock.released'      => 'worker',
            'stale.detected'     => 'recovery',
            'stale.requeued'     => 'recovery',
            'stale.exhausted'    => 'recovery',
            'pause_requested'    => 'control',
            'paused'             => 'control',
            'resume_requested'   => 'control',
            'cancel_requested'   => 'control',
            'cancelled'          => 'control',
            'result.recorded'    => 'result_error',
            'error.recorded'     => 'result_error',
        ];
        return $map[$eventType] ?? 'lifecycle';
    }

    /**
     * Build the Content-Disposition filename for the export. Filename
     * convention: revoap-timeline[-session-<id>][-job-<id>]-<UTC>.<ext>
     */
    private static function buildFilename(string $scope, int $sessionId, ?int $jobId, string $format): string
    {
        $stamp = gmdate('Y-m-d\THis\Z');
        $parts = ['revoap-timeline'];
        if ($scope === 'session' || $sessionId > 0) {
            $parts[] = 'session-' . $sessionId;
        }
        if ($jobId !== null && $jobId > 0) {
            $parts[] = 'job-' . $jobId;
        }
        $parts[] = $stamp;
        $base = implode('-', $parts);
        return $base . '.' . $format;
    }

    /**
     * Emit a JSON bundle as a download attachment. We bypass Response::json
     * so we can add Content-Disposition before the body is returned.
     *
     * @param array<string,mixed> $bundle
     */
    private function emitJsonAttachment(array $bundle, string $filename): string
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('Content-Disposition: attachment; filename="' . self::asciiSafe($filename) . '"');
        }
        return JsonHelper::encode($bundle, false, true);
    }

    /**
     * Emit the filtered events as an RFC-4180 CSV stream. Columns are
     * stable and documented in the v1.8.42 changelog. The context_json
     * column is appended only when include_context=1.
     *
     * @param list<array<string,mixed>> $events
     */
    private function emitCsv(array $events, string $filename, bool $includeContext): string
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/csv; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('Content-Disposition: attachment; filename="' . self::asciiSafe($filename) . '"');
        }
        $columns = [
            'id', 'created_at', 'job_id', 'session_id', 'event_type',
            'status_from', 'status_to', 'attempt', 'max_attempts',
            'backoff_seconds', 'next_run_at', 'worker_id',
            'reason', 'message',
        ];
        if ($includeContext) { $columns[] = 'context_json'; }

        $lines = [self::csvJoin($columns)];
        foreach ($events as $ev) {
            $row = [];
            foreach ($columns as $col) {
                if ($col === 'context_json') {
                    $ctx = $ev['context'] ?? null;
                    if ($ctx === null || $ctx === []) {
                        $row[] = '';
                    } else {
                        try {
                            $row[] = JsonHelper::encode($ctx);
                        } catch (\Throwable) {
                            $row[] = '';
                        }
                    }
                    continue;
                }
                $val = $ev[$col] ?? null;
                if ($val === null) { $row[] = ''; continue; }
                if (is_bool($val)) { $row[] = $val ? 'true' : 'false'; continue; }
                if (is_scalar($val)) { $row[] = (string)$val; continue; }
                try {
                    $row[] = JsonHelper::encode($val);
                } catch (\Throwable) {
                    $row[] = '';
                }
            }
            $lines[] = self::csvJoin($row);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * RFC-4180 row encoder: quote any cell that contains a comma, CR, LF
     * or double-quote; escape inner quotes by doubling.
     *
     * @param list<string> $cells
     */
    private static function csvJoin(array $cells): string
    {
        $encoded = [];
        foreach ($cells as $cell) {
            $s = (string)$cell;
            if (preg_match('/[",\r\n]/', $s) === 1) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }
            $encoded[] = $s;
        }
        return implode(',', $encoded);
    }

    /**
     * Filenames in Content-Disposition must be ASCII-safe to avoid issues
     * with older proxies. We replace anything outside [\w.-] with a dash.
     */
    private static function asciiSafe(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? $name;
        return $clean === '' ? 'revoap-timeline.export' : $clean;
    }

    /**
     * Compact job summary used by both timeline endpoints. Mirrors the
     * subset of Job::toPublicArray() that the UI renders next to the
     * timeline (status, attempts, next retry, recovery reason).
     *
     * @return array<string,mixed>
     */
    private static function jobSummary(Job $job): array
    {
        return [
            'id'                => $job->id,
            'session_id'        => $job->sessionId,
            'status'            => $job->status->value,
            'attempts'          => $job->attempts,
            'max_attempts'      => $job->maxAttempts,
            'next_run_at'       => $job->nextRunAt,
            'last_recovered_at' => $job->lastRecoveredAt,
            'recovery_reason'   => $job->recoveryReason,
            'last_error_at'     => $job->lastErrorAt,
            'created_at'        => $job->createdAt,
            'started_at'        => $job->startedAt,
            'finished_at'       => $job->finishedAt,
            'heartbeat_at'      => $job->heartbeatAt,
            'worker_id'         => $job->lockedBy,
            'error'             => $job->error !== null ? Job::sanitizeError($job->error) : null,
        ];
    }

    /**
     * GET /api/council/session/{id}/events/stream — SSE replay of
     * persisted pipeline_events. Does NOT execute the pipeline.
     *
     * Query parameters:
     *   after_event_id (int)    — Resume from a known event id (also
     *                             read from Last-Event-ID header).
     *   heartbeat (0|1)         — Emit a leading `: keep-alive` comment.
     *   live (0|1)              — v1.8.37: when 1, keep the connection
     *                             open and poll pipeline_events for new
     *                             rows until the matching job reaches a
     *                             terminal status OR the stream window
     *                             (STREAM_WINDOW_SECONDS) elapses. When
     *                             omitted, returns the persisted snapshot
     *                             synchronously and terminates immediately
     *                             with PIPELINE_REPLAY_DONE — the
     *                             pre-1.8.37 behaviour.
     *
     * Live-mode contract: the endpoint emits a terminal PIPELINE_REPLAY_DONE
     * frame whose `data.job_status` reflects the most recent job snapshot
     * read from `revoap_jobs`. EventSource clients should reconnect
     * with `Last-Event-ID` (or `?after_event_id=<id>`) when `job_status`
     * is non-terminal (queued/running/pause_requested/paused/
     * resume_requested/consensus_requested) and stop reconnecting on
     * completed/failed/cancelled.
     *
     * @param array<string,string> $params
     */
    public function eventsStream(array $params): string
    {
        $sessionId = (int)($params['id'] ?? 0);
        if ($sessionId <= 0) {
            return Response::json(['ok' => false, 'error' => 'session_id_required'], 400);
        }

        // Resolve resume cursor.
        $afterId = 0;
        if (isset($_GET['after_event_id']) && is_numeric($_GET['after_event_id'])) {
            $afterId = max(0, (int)$_GET['after_event_id']);
        } elseif (isset($_SERVER['HTTP_LAST_EVENT_ID']) && is_numeric($_SERVER['HTTP_LAST_EVENT_ID'])) {
            $afterId = max(0, (int)$_SERVER['HTTP_LAST_EVENT_ID']);
        }
        $emitHeartbeat = isset($_GET['heartbeat']) && (string)$_GET['heartbeat'] !== '0';
        $live = isset($_GET['live']) && (string)$_GET['live'] !== '0' && (string)$_GET['live'] !== '';

        // SSE headers. We deliberately avoid running the live pipeline:
        // this endpoint is replay-only — the worker persists events.
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            header('X-Content-Type-Options: nosniff');
        }

        $body = '';
        if ($emitHeartbeat) {
            $body .= ": keep-alive\n\n";
        }

        // 1) Snapshot — drain any events already persisted past the cursor.
        $latestId = $afterId;
        $emitted = 0;
        $events = $this->steps->listEventsBySession($sessionId);
        foreach ($events as $ev) {
            $eid = (int)($ev['id'] ?? 0);
            if ($eid <= $afterId) { continue; }
            $body .= $this->formatEventFrame($ev);
            if ($eid > $latestId) { $latestId = $eid; }
            $emitted++;
        }

        // 2) Live mode (v1.8.37): keep polling pipeline_events until the
        // matching job hits a terminal status or the stream window expires.
        $jobStatusValue = null;
        $jobTerminal = false;
        if ($live) {
            $deadline = microtime(true) + self::STREAM_WINDOW_SECONDS;
            // Stream the snapshot already buffered above ASAP so the
            // browser can render the first frames.
            if ($body !== '') {
                echo $body;
                if (function_exists('ob_flush')) { @ob_flush(); }
                @flush();
                $body = '';
            }
            while (microtime(true) < $deadline) {
                if (connection_aborted()) { break; }
                // Pull any events that landed since `latestId`.
                $batch = $this->steps->listEventsBySession($sessionId);
                $emittedThisLoop = 0;
                foreach ($batch as $ev) {
                    $eid = (int)($ev['id'] ?? 0);
                    if ($eid <= $latestId) { continue; }
                    echo $this->formatEventFrame($ev);
                    $latestId = $eid;
                    $emitted++;
                    $emittedThisLoop++;
                }
                if ($emittedThisLoop > 0) {
                    if (function_exists('ob_flush')) { @ob_flush(); }
                    @flush();
                }
                // Inspect job status for terminal exit.
                $job = $this->jobs->getBySessionId($sessionId);
                if ($job !== null) {
                    $jobStatusValue = $job->status->value;
                    if ($job->status->isTerminal()) {
                        $jobTerminal = true;
                        break;
                    }
                }
                // Yield to the OS so we don't busy-loop.
                usleep((int)(self::STREAM_POLL_INTERVAL * 1_000_000));
            }
        } else {
            // Snapshot mode — emit a single status read so the client can
            // decide whether to reconnect immediately.
            $job = $this->jobs->getBySessionId($sessionId);
            if ($job !== null) {
                $jobStatusValue = $job->status->value;
                $jobTerminal = $job->status->isTerminal();
            }
        }

        // 3) Terminal frame so EventSource clients close cleanly. The
        // `job_status` and `job_terminal` keys let the browser decide
        // whether to reconnect (Last-Event-ID) or stop.
        $body .= 'event: PIPELINE_REPLAY_DONE' . "\n";
        $body .= 'data: ' . JsonHelper::encode([
            'session_id'    => $sessionId,
            'last_event_id' => $latestId,
            'replayed'      => $emitted,
            'live'          => $live,
            'job_status'    => $jobStatusValue,
            'job_terminal'  => $jobTerminal,
            'note'          => $live
                ? 'Stream window elapsed or job terminal — reconnect with Last-Event-ID if non-terminal.'
                : 'Snapshot replay — reconnect with ?live=1 for live polling.',
        ]) . "\n\n";
        return $body;
    }

    /**
     * Serialise one persisted pipeline_events row into the EventSource
     * wire frame the frontend understands. Centralised so the snapshot
     * and live-polling branches agree.
     *
     * @param  array<string,mixed> $ev
     */
    private function formatEventFrame(array $ev): string
    {
        $eid = (int)($ev['id'] ?? 0);
        $type = (string)($ev['event_type'] ?? 'EVENT');
        $payload = $ev['payload'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = JsonHelper::tryDecode($payload);
            $payload = is_array($decoded) ? $decoded : ['raw' => $payload];
        }
        $bodyArr = [
            'event_id'   => $eid,
            'step_id'    => isset($ev['step_id']) ? (int)$ev['step_id'] : null,
            'created_at' => (string)($ev['created_at'] ?? ''),
            'payload'    => is_array($payload) ? $payload : null,
        ];
        $out  = 'id: ' . $eid . "\n";
        $out .= 'event: ' . $type . "\n";
        $out .= 'data: ' . JsonHelper::encode($bodyArr) . "\n\n";
        return $out;
    }

    /**
     * Intent transitions take effect only on active jobs. A terminal
     * job returns 409 with the current status so the UI can refresh
     * without retrying.
     *
     * @param array<string,string> $params
     */
    private function intent(array $params, JobStatus $intent): string
    {
        $id  = (int)($params['id'] ?? 0);
        $job = $this->jobs->get($id);
        if ($job === null) {
            return Response::json([
                'ok'    => false,
                'error' => 'job_not_found',
                'id'    => $id,
            ], 404);
        }
        if ($job->status->isTerminal()) {
            return Response::json([
                'ok'      => false,
                'error'   => 'job_terminal',
                'status'  => $job->status->value,
                'message' => 'Job already reached a terminal status; intent ignored.',
            ], 409);
        }
        $resolved = match ($intent) {
            JobStatus::PauseRequested     => $this->jobs->requestPause($id),
            JobStatus::ResumeRequested    => $this->jobs->requestResume($id),
            JobStatus::CancelRequested    => $this->jobs->requestCancel($id),
            JobStatus::ConsensusRequested => $this->jobs->requestConsensus($id),
            default                        => null,
        };
        if ($resolved === null) {
            return Response::json([
                'ok'    => false,
                'error' => 'intent_rejected',
                'id'    => $id,
            ], 409);
        }
        // Re-fetch to surface the persisted state.
        $job = $this->jobs->get($id);
        return Response::json([
            'ok'      => true,
            'id'      => $id,
            'intent'  => $intent->value,
            'status'  => $job?->status->value ?? $resolved->value,
            'note'    => 'Cooperative — takes effect at the next worker checkpoint.',
        ]);
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return is_array($_POST) ? $_POST : [];
        }
        $decoded = JsonHelper::tryDecode($raw);
        return is_array($decoded) ? $decoded : [];
    }
}
