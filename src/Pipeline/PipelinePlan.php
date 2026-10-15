<?php
/**
 * PipelinePlan: how many steps a run is expected to take.
 *
 * The interface shows where a run is; without a plan it can only count what it
 * has already drawn, which says nothing about how much is left. The pipeline
 * knows the shape up front:
 *
 *   L0  the question card                         1
 *   L1  one answer per selected model             K
 *   L2  normalisation                             1
 *   L3  consensus verdict                         1
 *   L4  final answer                              1
 *
 * so the first cycle is `K + 4` steps. Cycles after the first one repeat L1-L4
 * when the consensus does not converge, which is why this class reports the
 * first cycle and the cycle ceiling separately instead of pretending to know
 * the exact total: a run that cycles three times legitimately takes more steps
 * than `K + 4`, and a counter that called that "over 100%" would be worse than
 * no counter at all. The interface adds one cycle's worth when a new round
 * starts.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-28
 */
declare(strict_types=1);

namespace App\Pipeline;

final class PipelinePlan
{
    /** Steps that are not L1 answers: the question, L2, L3 and L4. */
    public const NON_L1_STEPS = 4;

    /**
     * Steps a single-model run draws on purpose.
     *
     * Its shape is not the council's: L0 asks the question, L1 collects the one
     * answer, and L2/L3/L4 are recorded as explicitly skipped because there was
     * nothing to normalise, compare or synthesise. Reporting `K + 4` for such a
     * run would tell the interface to wait for steps that will never come, so
     * the fixed shape is published instead: the question, the answer, and the
     * three stages that did not apply.
     */
    public const SINGLE_MODEL_STEPS = 5;

    /** Steps the first cycle of a run takes. */
    public static function firstCycleSteps(int $modelCount): int
    {
        return max(0, $modelCount) + self::NON_L1_STEPS;
    }

    /**
     * Steps a single-model run draws: the question card, the one answer, and
     * the L2/L3/L4 rows that record why they were not run.
     */
    public static function singleModelSteps(): int
    {
        return self::SINGLE_MODEL_STEPS;
    }

    /**
     * Steps one further cycle adds: the same L1 answers plus L2, L3 and L4.
     * (The question card is drawn once, at the start.)
     */
    public static function nextCycleSteps(int $modelCount): int
    {
        return max(0, $modelCount) + self::NON_L1_STEPS - 1;
    }
}