<?php
/**
 * Job: Read-only value object representing one row from `revoap_jobs`.
 *
 * Always constructed by JobRepository from a DB row or an in-memory
 * mirror. The shape mirrors the columns of the migration 011 + 012
 * table: id, session_id, user_id, status, priority, attempts,
 * max_attempts, payload, result, error, locked_by, locked_at,
 * heartbeat_at, next_run_at, last_recovered_at, recovery_reason,
 * last_error_at, created_at, started_at, finished_at, updated_at.
 *
 * v1.8.39 adds the retry-policy bookkeeping fields used by the stale-
 * job auto-recovery sweep (attempts / max_attempts / next_run_at /
 * last_recovered_at / recovery_reason / last_error_at). Older rows
 * surfaced before migration 012 was applied populate the new fields
 * with defaults (attempts=0, max_attempts=3, the rest null) so callers
 * never see NULL surprises.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Jobs;

final readonly class Job
{
    /**
     * @param array<string,mixed>      $payload  Validated job payload (query, models, …).
     * @param array<string,mixed>|null $result   Worker-emitted final result envelope.
     * @param array<string,mixed>|null $error    Failure envelope (class/message/stage).
     */
    public function __construct(
        public int     $id,
        public int     $sessionId,
        public ?int    $userId,
        public JobStatus $status,
        public int     $priority,
        public array   $payload,
        public ?array  $result,
        public ?array  $error,
        public ?string $lockedBy,
        public ?string $lockedAt,
        public ?string $heartbeatAt,
        public string  $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $updatedAt = null,
        public int     $attempts = 0,
        public int     $maxAttempts = 3,
        public ?string $nextRunAt = null,
        public ?string $lastRecoveredAt = null,
        public ?string $recoveryReason = null,
        public ?string $lastErrorAt = null,
    ) {}

    /**
     * Browser-safe representation. Strips API keys, raw connector
     * secrets, and any field whose name matches a secret heuristic in
     * the payload. Errors are sanitized to class+message only.
     *
     * v1.8.39 surfaces the retry-policy bookkeeping so the UI can show
     * how many recoveries the job has consumed and when (if ever) the
     * worker will pick it up again.
     *
     * @return array<string,mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id'                 => $this->id,
            'session_id'         => $this->sessionId,
            'user_id'            => $this->userId,
            'status'             => $this->status->value,
            'priority'           => $this->priority,
            'payload'            => self::sanitizePayload($this->payload),
            'result'             => $this->result,
            'error'              => $this->error !== null ? self::sanitizeError($this->error) : null,
            'created_at'         => $this->createdAt,
            'started_at'         => $this->startedAt,
            'finished_at'        => $this->finishedAt,
            'updated_at'         => $this->updatedAt,
            'heartbeat_at'       => $this->heartbeatAt,
            'attempts'           => $this->attempts,
            'max_attempts'       => $this->maxAttempts,
            'next_run_at'        => $this->nextRunAt,
            'last_recovered_at'  => $this->lastRecoveredAt,
            'recovery_reason'    => $this->recoveryReason,
            'last_error_at'      => $this->lastErrorAt,
        ];
    }

    /**
     * Strip every key whose name suggests a secret (api_key, token,
     * password, secret, authorization, …). Operates recursively so a
     * leaf like `payload.connectors.openrouter.api_key` is removed.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function sanitizePayload(array $payload): array
    {
        $needles = ['key', 'token', 'secret', 'password', 'authorization', 'apikey', 'api_key'];
        $walker = static function (mixed $v) use (&$walker, $needles): mixed {
            if (!is_array($v)) {
                return $v;
            }
            $out = [];
            foreach ($v as $k => $value) {
                $kl = strtolower((string)$k);
                $hit = false;
                foreach ($needles as $needle) {
                    if (str_contains($kl, $needle)) { $hit = true; break; }
                }
                if ($hit) { continue; }
                $out[$k] = $walker($value);
            }
            return $out;
        };
        $sanitized = $walker($payload);
        return is_array($sanitized) ? $sanitized : [];
    }

    /**
     * @param  array<string,mixed> $error
     * @return array<string,mixed>
     */
    public static function sanitizeError(array $error): array
    {
        $out = [];
        foreach (['class', 'message', 'stage', 'cycle', 'at', 'exhausted_retries', 'attempts'] as $k) {
            if (array_key_exists($k, $error)) {
                $out[$k] = $error[$k];
            }
        }
        return $out;
    }
}
