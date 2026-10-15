<?php
/**
 * PipelineStep: Immutable description of a step in the council pipeline.
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

final readonly class PipelineStep
{
    public function __construct(
        public ?int   $id,
        public string $key,
        public string $label,
        public int    $orderIndex,
        public string $layer,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'key'         => $this->key,
            'label'       => $this->label,
            'order_index' => $this->orderIndex,
            'layer'       => $this->layer,
        ];
    }
}
