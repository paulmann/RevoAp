<?php
/**
 * ConsensusResult: Immutable result of consensus analysis.
 *
 * Status semantics:
 *  - 'agreement': score >= 0.85 (strong overlap of key claims).
 *  - 'partial':   0.55 <= score < 0.85 (substantial overlap, some divergence).
 *  - 'conflict':  score < 0.55.
 *  - 'single':    no comparison happened at all — one model answered and its
 *                 answer is the final answer (a single-model run). The score is
 *                 not measured: callers store NULL for it, never 0 and never
 *                 the trivial 1.0 the one-answer branch of the engines would
 *                 otherwise report. See PipelineEngine::MODE_SINGLE_MODEL.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Consensus;

final readonly class ConsensusResult
{
    /**
     * The verdict stored for a run that computed no consensus.
     *
     * A value object still needs a score, so it carries 0.0; what reaches the
     * database is NULL, declared by the caller that knows the run mode
     * (PipelineEngine::runSingleModel). Keeping the VO constructible means L3's
     * explainer and the repair planner can describe such a run without special
     * cases.
     */
    public const STATUS_SINGLE = 'single';

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public float  $score,
        public string $status,
        public string $summary,
        public array  $meta = [],
    ) {
        if ($this->score < 0.0 || $this->score > 1.0) {
            throw new \InvalidArgumentException('Consensus score must be in [0,1]');
        }
        if (!in_array($this->status, ['agreement', 'partial', 'conflict', self::STATUS_SINGLE], true)) {
            throw new \InvalidArgumentException("Invalid status: {$this->status}");
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'score'   => $this->score,
            'status'  => $this->status,
            'summary' => $this->summary,
            'meta'    => $this->meta,
        ];
    }
}
