<?php
/**
 * ModelAvailability: the persistent health verdict for one model.
 *
 * One row per `provider/model`, written by the availability monitor and by
 * the pipeline's own post-call recording. Read at pool-assembly time so a
 * model that has been failing for the last ten minutes is substituted before
 * the run pays its timeout.
 *
 * The counters cover a rolling window, not all time: a model that failed
 * 400 times last month and has answered the last 50 calls is healthy, and a
 * lifetime average would say otherwise forever.
 *
 * @project  RevoAp   @version 2.0.0   @since 2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

final readonly class ModelAvailability
{
    /**
     * @param array<int,UnavailableReason> $recentReasons most recent first
     */
    public function __construct(
        public string $id,
        public string $provider,
        public string $model,
        public AvailabilityStatus $status,
        public int $successes = 0,
        public int $failures = 0,
        public ?float $successRate = null,
        public ?float $avgLatencyMs = null,
        public ?float $p95LatencyMs = null,
        public ?int $lastSuccessAt = null,
        public ?int $lastFailureAt = null,
        public ?int $lastProbedAt = null,
        public ?string $lastError = null,
        public array $recentReasons = [],
        public int $consecutiveFailures = 0,
        public ?string $note = null,
    ) {
    }

    public function samples(): int
    {
        return $this->successes + $this->failures;
    }

    /** The dominant reason for the current verdict, for the UI's "why". */
    public function primaryReason(): ?UnavailableReason
    {
        if ($this->recentReasons !== []) {
            return $this->recentReasons[0];
        }
        return match ($this->status) {
            AvailabilityStatus::Unavailable => UnavailableReason::Breaker,
            AvailabilityStatus::Retired     => UnavailableReason::Removed,
            AvailabilityStatus::Degraded    => UnavailableReason::Timeout,
            default                         => null,
        };
    }

    /** True when the verdict is based on enough observations to trust. */
    public function confident(int $minSamples = 5): bool
    {
        return $this->samples() >= $minSamples;
    }

    /** Seconds since the last successful answer, or null if never. */
    public function secondsSinceSuccess(?int $now = null): ?int
    {
        if ($this->lastSuccessAt === null) { return null; }
        return max(0, ($now ?? time()) - $this->lastSuccessAt);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status->value,
            'status_tone' => $this->status->tone(),
            'status_label_key' => $this->status->labelKey(),
            'successes' => $this->successes,
            'failures' => $this->failures,
            'samples' => $this->samples(),
            'success_rate' => $this->successRate,
            'avg_latency_ms' => $this->avgLatencyMs,
            'p95_latency_ms' => $this->p95LatencyMs,
            'last_success_at' => $this->lastSuccessAt,
            'last_failure_at' => $this->lastFailureAt,
            'last_probed_at' => $this->lastProbedAt,
            'last_error' => $this->lastError,
            'consecutive_failures' => $this->consecutiveFailures,
            'recent_reasons' => array_map(
                static fn(UnavailableReason $r): array => ['value' => $r->value, 'label_key' => $r->labelKey(), 'transient' => $r->transient()],
                $this->recentReasons
            ),
            'primary_reason' => $this->primaryReason()?->value,
            'primary_reason_label_key' => $this->primaryReason()?->labelKey(),
            'note' => $this->note,
        ];
    }

    /**
     * Rebuild from a database row. Tolerant of missing columns so a schema
     * that predates a field still produces a usable verdict.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $provider = (string)($row['provider'] ?? '');
        $model = (string)($row['model'] ?? '');
        $id = (string)($row['model_id'] ?? ($provider !== '' && !str_contains($model, '/') ? "$provider/$model" : $model));

        $reasons = [];
        $raw = $row['recent_reasons'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }
        if (is_array($raw)) {
            foreach ($raw as $r) {
                $reason = UnavailableReason::parse($r);
                if ($reason !== UnavailableReason::Unverified || $r === UnavailableReason::Unverified->value) {
                    $reasons[] = $reason;
                }
            }
        }

        return new self(
            id: $id,
            provider: $provider,
            model: $model,
            status: AvailabilityStatus::parse($row['status'] ?? null),
            successes: (int)($row['successes'] ?? 0),
            failures: (int)($row['failures'] ?? 0),
            successRate: isset($row['success_rate']) ? (float)$row['success_rate'] : null,
            avgLatencyMs: isset($row['avg_latency_ms']) ? (float)$row['avg_latency_ms'] : null,
            p95LatencyMs: isset($row['p95_latency_ms']) ? (float)$row['p95_latency_ms'] : null,
            lastSuccessAt: isset($row['last_success_at']) ? (int)$row['last_success_at'] : null,
            lastFailureAt: isset($row['last_failure_at']) ? (int)$row['last_failure_at'] : null,
            lastProbedAt: isset($row['last_probed_at']) ? (int)$row['last_probed_at'] : null,
            lastError: isset($row['last_error']) ? (string)$row['last_error'] : null,
            recentReasons: $reasons,
            consecutiveFailures: (int)($row['consecutive_failures'] ?? 0),
            note: isset($row['note']) ? (string)$row['note'] : null,
        );
    }
}
