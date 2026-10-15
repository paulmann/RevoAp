<?php
/**
 * AlternativeCandidate: one ranked replacement, with the reasoning attached.
 *
 * The `components` map is the point of this class. A bare score is a number
 * an operator cannot argue with; five named components are five things they
 * can inspect and disagree with. The UI renders them as a breakdown, and the
 * audit trail records them, so "why did my run use a different model?" has an
 * answer that does not require reading the resolver's source.
 *
 * @project  RevoAp   @version 2.0.0   @since 2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

final readonly class AlternativeCandidate
{
    /**
     * @param array<string,mixed>       $row        The full catalogue row.
     * @param array<string,float>       $components vendor/context/reliability/latency/price, each 0..1.
     */
    public function __construct(
        public string $id,
        public string $provider,
        public string $model,
        public array $row,
        public float $score,
        public array $components,
        public bool $sameVendor,
        public ?int $contextLength,
        public ?float $avgLatencyMs,
        public ?float $price,
        public AvailabilityStatus $status,
        public ?UnavailableReason $reason = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'score' => $this->score,
            'score_percent' => (int)round($this->score * 100),
            'components' => $this->components,
            'same_vendor' => $this->sameVendor,
            'context_length' => $this->contextLength,
            'avg_latency_ms' => $this->avgLatencyMs,
            'price' => $this->price,
            'status' => $this->status->value,
            'status_tone' => $this->status->tone(),
            'status_label_key' => $this->status->labelKey(),
            'reason' => $this->reason?->value,
            'reason_label_key' => $this->reason?->labelKey(),
        ];
    }
}
