<?php
/**
 * StepResult: Immutable result of a single pipeline step.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Pipeline;

use App\Connectors\ChatResponse;

final readonly class StepResult
{
    /**
     * @param ChatResponse[] $responses
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public PipelineStep $step,
        public string  $status,        // 'completed' | 'failed' | 'skipped'
        public array   $responses,
        public ?string $summary = null,
        public array   $meta = [],
    ) {
        if (!in_array($status, ['completed', 'failed', 'skipped'], true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
    }

    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isFailed(): bool    { return $this->status === 'failed'; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'step'      => $this->step->toArray(),
            'status'    => $this->status,
            'summary'   => $this->summary,
            'responses' => array_map(static fn(ChatResponse $r): array => $r->toArray(), $this->responses),
            'meta'      => $this->meta,
        ];
    }
}
