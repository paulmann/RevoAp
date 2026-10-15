<?php
declare(strict_types=1);

namespace App\Cost;

/**
 * R10 - is this run a candidate for the asynchronous batch queue?
 *
 * A pure decision: it takes the shape of the run as plain values and returns a
 * recommendation with a reason the operator can argue with. It never switches
 * anything on by itself - the batch window is up to 24 hours, so a silent
 * switch would be a silent delay.
 *
 * The order of the rules matters: the first objection wins, because "the pool
 * cannot be batched" outranks "the run would be slow" in every reading.
 */
final class BatchSuitability
{
    public const BATCH = 'batch';
    public const SYNC  = 'sync';

    /**
     * @param array{
     *     run_mode?:string,            // what this run is set to right now
     *     pool_size?:int,              // how many models L1 will call
     *     batch_capable?:bool|null,    // provider capability report for the pool
     *     expected_seconds?:int|null,  // from history, when there is history
     *     interactive?:bool,           // does the operator wait for this answer?
     *     batch_ready?:bool            // is the batch contour usable at all (key + ext-curl)?
     * } $run
     * @return array{recommend:string,reason:string,decided_by:string}
     */
    public static function decide(array $run): array
    {
        $mode        = strtolower(trim((string)($run['run_mode'] ?? 'sync')));
        $poolSize    = max(0, (int)($run['pool_size'] ?? 0));
        $capable     = $run['batch_capable'] ?? null;
        $interactive = ($run['interactive'] ?? true) === true;
        $ready       = ($run['batch_ready'] ?? false) === true;
        $seconds     = isset($run['expected_seconds']) && is_numeric($run['expected_seconds'])
            ? max(0, (int)$run['expected_seconds'])
            : null;

        if ($poolSize === 0) {
            return self::verdict(self::SYNC, decidedBy: 'pool',
                reason: 'There is no run to move to the queue: the pool is empty.');
        }
        if ($mode === self::BATCH) {
            return self::verdict(self::BATCH, decidedBy: 'already',
                reason: 'This run is already set to the Batch API.');
        }
        if (!$ready) {
            return self::verdict(self::SYNC, decidedBy: 'contour',
                reason: 'The batch contour is not usable right now (provider key or ext-curl), so the run stays synchronous.');
        }
        if ($capable === false) {
            return self::verdict(self::SYNC, decidedBy: 'capability',
                reason: 'The models in this pool are not offered for batch processing by the provider, so the run stays synchronous.');
        }
        if ($interactive) {
            return self::verdict(self::SYNC, decidedBy: 'interactive',
                reason: 'You are waiting for this answer: the batch window is measured in minutes to hours, so the run stays synchronous.');
        }
        if ($seconds !== null && $seconds < EconomyMode::minBatchSeconds()) {
            return self::verdict(self::SYNC, decidedBy: 'fast',
                reason: sprintf('Similar runs finish in about %d s, which is quicker than the batch window would be.', $seconds));
        }
        if ($seconds !== null) {
            return self::verdict(self::BATCH, decidedBy: 'slow',
                reason: sprintf('Similar runs take about %d s; the same work through the Batch API is cheaper and can be collected asynchronously.', $seconds));
        }

        return self::verdict(self::SYNC, decidedBy: 'unknown',
            reason: 'There is no history for this pool yet, so the run stays synchronous until there is something to base a recommendation on.');
    }

    /** @return array{recommend:string,reason:string,decided_by:string} */
    private static function verdict(
        string $recommend,
        string $decidedBy,
        string $reason,
    ): array {
        return ['recommend' => $recommend, 'reason' => $reason, 'decided_by' => $decidedBy];
    }
}
