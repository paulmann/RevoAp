<?php
/**
 * EmbedResponse: Immutable value object describing an embedding response.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Connectors;

final readonly class EmbedResponse
{
    /**
     * @param float[] $vector
     * @param array<string,mixed> $rawResponse
     */
    public function __construct(
        public array  $vector,
        public string $model,
        public string $provider,
        public int    $promptTokens,
        public int    $totalTokens,
        public float  $latencyMs,
        public array  $rawResponse = [],
    ) {
        if ($this->promptTokens < 0 || $this->totalTokens < 0) {
            throw new \InvalidArgumentException('Token counts must be non-negative');
        }
        if ($this->latencyMs < 0.0) {
            throw new \InvalidArgumentException('latencyMs must be non-negative');
        }
    }

    /** @return float[] */
    public function vector(): array
    {
        return $this->vector;
    }
}
