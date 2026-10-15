<?php
/**
 * PipelineEvent: Immutable append-only event in the pipeline event log.
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

final readonly class PipelineEvent
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $type,
        public ?int   $stepId,
        public array  $payload,
        public string $createdAt,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'type'       => $this->type,
            'step_id'    => $this->stepId,
            'payload'    => $this->payload,
            'created_at' => $this->createdAt,
        ];
    }
}
