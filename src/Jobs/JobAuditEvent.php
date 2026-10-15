<?php
/**
 * JobAuditEvent: Read-only value object representing one row from
 * `revoap_job_events`.
 *
 * Append-only; constructed by JobAuditRepository from a DB row or an
 * in-memory mirror. The shape mirrors the columns of migration 013.
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

final readonly class JobAuditEvent
{
    /**
     * @param array<string,mixed>|null $context Sanitized structural
     *                                          context — never carries
     *                                          secrets or raw prompts.
     */
    public function __construct(
        public int     $id,
        public int     $jobId,
        public int     $sessionId,
        public ?int    $userId,
        public string  $eventType,
        public ?string $statusFrom,
        public ?string $statusTo,
        public ?int    $attempt,
        public ?int    $maxAttempts,
        public ?int    $backoffSeconds,
        public ?string $nextRunAt,
        public ?string $workerId,
        public ?string $reason,
        public ?string $message,
        public ?array  $context,
        public string  $createdAt,
    ) {}

    /**
     * Browser-safe representation. The repository already pre-sanitizes
     * the context before persistence, but this method runs a final pass
     * so a context_json blob that pre-dates the sanitizer still cannot
     * leak secrets through the timeline API.
     *
     * @return array<string,mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id'              => $this->id,
            'job_id'          => $this->jobId,
            'session_id'      => $this->sessionId,
            'user_id'         => $this->userId,
            'event_type'      => $this->eventType,
            'status_from'     => $this->statusFrom,
            'status_to'       => $this->statusTo,
            'attempt'         => $this->attempt,
            'max_attempts'    => $this->maxAttempts,
            'backoff_seconds' => $this->backoffSeconds,
            'next_run_at'     => $this->nextRunAt,
            'worker_id'       => $this->workerId,
            'reason'          => $this->reason,
            'message'         => $this->message,
            'context'         => $this->context !== null
                                  ? Job::sanitizePayload($this->context)
                                  : null,
            'created_at'      => $this->createdAt,
        ];
    }
}
