<?php
/**
 * WorkerFeedController: HTTP layer for the header's worker-status
 * indicator.
 *
 * Two endpoints, both PUBLIC — exactly like GET /api/health/worker,
 * whose snapshot they complement:
 *
 *   GET /api/health/worker/jobs         — the ≤10 jobs the indicator's
 *                                         "worker is busy" tooltip lists
 *                                         (ids, statuses, timings only).
 *   GET /api/health/worker/diagnostics  — the last self-test verdict
 *                                         (provider identities masked)
 *                                         plus the problems derived from
 *                                         the public health snapshot.
 *
 * Neither route is admin-gated: the indicator polls every 5 s from every
 * open tab and must keep working on a deployment where the operator
 * never unlocks Settings. Both payloads are scrubbed at the source
 * ({@see \App\Worker\WorkerJobFeed}, {@see \App\Worker\WorkerDiagnostics})
 * — no query text, no session titles, no user ids, no results, no
 * connector names.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Logger;
use App\Http\Response;
use App\Worker\WorkerDiagnostics;
use App\Worker\WorkerJobFeed;
use Throwable;

final class WorkerFeedController
{
    public function __construct(
        private readonly WorkerJobFeed     $feed,
        private readonly WorkerDiagnostics $diagnostics,
    ) {}

    /**
     * GET /api/health/worker/jobs
     *
     * Optional query parameters:
     *   ?limit=<1..10>    rows to return (clamped, default 10)
     *   ?window=<60..86400> recently-finished window in seconds
     *
     * Responses:
     *   200 {ok:true, generated_at, jobs_table_available, limit,
     *        recent_window_s, unfinished_returned,
     *        recent_finished_returned, truncated, jobs[]}
     *   500 {ok:false, error:"jobs_failed", message}
     *
     * A missing `revoap_jobs` table is NOT an error here: the envelope
     * carries `jobs_table_available:false` and an empty list so the
     * tooltip can explain the situation instead of showing a spinner.
     */
    public function jobs(): string
    {
        try {
            return Response::json($this->feed->feed(
                self::numericParam('limit'),
                self::numericParam('window'),
            ));
        } catch (Throwable $e) {
            return self::failure('jobs_failed', $e);
        }
    }

    /**
     * GET /api/health/worker/diagnostics
     *
     * Responses:
     *   200 {generated_at, available, job_id, at, age_s, ok, passed,
     *        failed, duration_ms, checks[], derived{...}}
     *   500 {ok:false, error:"diagnostics_failed", message}
     *
     * `available:false` (nobody pressed Test yet) is a 200 with an empty
     * check list — a normal answer, not an error. The 500 envelope uses
     * `ok` as an envelope flag, which is unambiguous because the success
     * envelope never carries a top-level envelope flag (its `ok` is the
     * self-test verdict).
     */
    public function diagnostics(): string
    {
        try {
            return Response::json($this->diagnostics->report());
        } catch (Throwable $e) {
            return self::failure('diagnostics_failed', $e);
        }
    }

    /**
     * Read one numeric query parameter. Invalid values are ignored
     * (null) so the service applies its own default + clamp — a hostile
     * query string can never widen the row cap.
     */
    private static function numericParam(string $name): ?int
    {
        $raw = $_GET[$name] ?? null;
        return is_numeric($raw) ? (int)$raw : null;
    }

    /**
     * A failing feed must still be readable JSON. The message is
     * sanitized before it leaves the process, and the failure is
     * recorded once in the server log for the operator.
     */
    private static function failure(string $error, Throwable $e): string
    {
        Logger::warn('worker.feed.' . $error, [
            'class'   => (new \ReflectionClass($e))->getShortName(),
            'message' => Logger::sanitizeScalar($e->getMessage()),
        ]);
        return Response::json([
            'ok'      => false,
            'error'   => $error,
            'message' => Logger::sanitizeScalar($e->getMessage()),
        ], 500);
    }
}
