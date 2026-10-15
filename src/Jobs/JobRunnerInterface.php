<?php
/**
 * JobRunnerInterface: the narrow contract a job executor needs from the
 * pipeline.
 *
 * {@see WorkerJobExecutor} adds database-resilience around "run this
 * job" — pre-flight connection check, one transparent retry after a
 * reconnect, requeue-instead-of-fail when the infrastructure is the
 * problem. Keeping that concern out of {@see PipelineJobRunner} means
 * the resilience logic can be exercised offline with a stub runner that
 * throws `2006` on demand, instead of having to boot a whole pipeline
 * engine (and its connectors) inside a unit test.
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

interface JobRunnerInterface
{
    /**
     * Execute the supplied job and return its resulting status.
     *
     * Implementations MAY throw: an exception means the job did not
     * reach a terminal state through the normal path, and the executor
     * decides whether that is an infrastructure fault (requeue) or a
     * genuine failure (re-throw, so the caller's existing failure
     * handling runs).
     *
     * Pre-condition: the job is already `running` and locked by
     * `$workerId` — {@see JobRepository::acquireNext()} establishes that
     * atomically.
     */
    public function run(Job $job, string $workerId): JobStatus;
}
