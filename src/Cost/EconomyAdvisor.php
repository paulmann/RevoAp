<?php
declare(strict_types=1);

namespace App\Cost;

use App\Connectors\PromptCache\Capabilities;

/**
 * R10 - the single place that turns the decision core into one answer.
 *
 * Everything here is pure: it takes the shape of a proposed run plus the
 * observations read from the journal, and returns what the operator should be
 * told before the run starts. No queries, no clock, no config reads beyond the
 * mode object it is handed - which is what makes the whole thing testable and
 * keeps the HTTP layer thin enough to stay boring.
 *
 * The output never overstates what is known:
 *   - an estimate is a range, and it is `null` when there is nothing to base it
 *     on (never a zero that would read as "free");
 *   - a batch comparison appears only when an estimate exists, and is labelled
 *     as the documented discount rather than a measurement;
 *   - cache advice appears per model only when that model has measured cache
 *     traffic, and stays silent for families the provider does not document.
 */
final class EconomyAdvisor
{
    /**
     * @param array{
     *     pool?:array<int,array{provider?:string,model?:string}>,
     *     run_mode?:string,
     *     batch_capable?:bool|null,
     *     batch_ready?:bool,
     *     interactive?:bool,
     *     expected_seconds?:int|null,
     *     cycle_limit?:int
     * } $run
     * @param array<int,array<string,mixed>> $observations
     *        CostHistory rows: model, provider, calls, latency_ms, cost, tokens, cost_reported
     *        plus the optional cache totals (cache_reads, cache_writes).
     * @return array<string,mixed>
     */
    public static function advise(array $run, array $observations, ?EconomyMode $mode = null): array
    {
        $mode ??= EconomyMode::fromConfig();
        $body = [
            'mode'   => $mode->level(),
            'label'  => $mode->label(),
            'advice' => $mode->advises(),
        ];

        if (!$mode->advises()) {
            // `off` means off: no recommendation, no estimate, nothing to
            // render. This is the level that has to reproduce the previous
            // behaviour, so it returns the mode and stops here.
            return $body + ['recommendation' => null, 'estimate' => null, 'batch' => null, 'cache' => [], 'note' => ''];
        }

        // The estimate answers "what will THIS run cost", so it is built from
        // the models that are actually in the pool. Summing every model ever
        // used would describe a run nobody is about to make.
        $poolModels = [];
        foreach ($run['pool'] ?? [] as $row) {
            $model = trim((string)($row['model'] ?? ''));
            if ($model !== '') { $poolModels[$model] = true; }
        }
        $poolHadEntries = $poolModels !== [];
        if ($poolHadEntries) {
            $observations = array_values(array_filter(
                $observations,
                static fn(array $row): bool => isset($poolModels[trim((string)($row['model'] ?? ''))]),
            ));
        }

        $cycles   = max(1, (int)($run['cycle_limit'] ?? 1));
        $input    = CostHistory::toEstimatorInput($observations, $cycles);
        if ($poolHadEntries && $input['rows'] === []) {
            $input['note'] = 'No comparable calls have been recorded for this pool yet.';
        }
        $estimate = $input['rows'] === []
            ? null
            : RunCostEstimator::fromObservations($input['rows'], $cycles);

        // R1 - the optional per-run ceiling. The estimate is compared against it
        // and the difference is stated; the run is not blocked here on purpose.
        // Refusing work the operator explicitly asked for is a stronger act than
        // this setting makes, and a client-side block would be a pre-submit gate
        // this flow does not have. What the operator gets instead is the fact,
        // named, before the result arrives.
        $budget = EconomyMode::budgetPerRun();
        $budgetVerdict = null;
        if ($budget !== null) {
            $high = $estimate['cost_high'] ?? null;
            $budgetVerdict = [
                'limit'          => $budget,
                'estimated_high' => $high,
                'exceeded'       => is_numeric($high) && (float)$high > $budget,
                'note'           => !is_numeric($high)
                    ? 'No estimate for this pool yet, so nothing is claimed against the ceiling.'
                    : ((float)$high > $budget
                        ? 'The high end of the estimate is above the configured ceiling for one run. '
                            . 'Not blocked - trim the pool, or raise economy.budget_per_run if the run is worth it.'
                        : 'The estimate stays under the configured ceiling for one run.'),
            ];
        }
        $recommendation = BatchSuitability::decide([
            'run_mode'         => (string)($run['run_mode'] ?? 'sync'),
            'pool_size'        => count($run['pool'] ?? []),
            'batch_capable'    => $run['batch_capable'] ?? null,
            'batch_ready'      => $run['batch_ready'] ?? false,
            'interactive'      => $run['interactive'] ?? true,
            'expected_seconds' => $run['expected_seconds'] ?? ($estimate['eta_high_s'] ?? null),
        ]);

        return $body + [
            'recommendation' => $recommendation,
            'estimate'       => $estimate,
            'batch'          => $estimate === null ? null : RunCostEstimator::batchAlternative($estimate),
            'cache'          => self::cacheAdvice($observations),
            'budget'         => $budgetVerdict,
            'note'           => $input['note'],
        ];
    }

    /**
     * One advice row per model that has measured cache traffic.
     *
     * A model with no recorded cache figures produces nothing at all: "no data"
     * is not "caching is useless", and the panel must not imply otherwise.
     *
     * @param array<int,array<string,mixed>> $observations
     * @return array<int,array{model:string,advice:string,reason:string,hit_rate:float}>
     */
    private static function cacheAdvice(array $observations): array
    {
        $out = [];
        foreach ($observations as $row) {
            $model = trim((string)($row['model'] ?? ''));
            if ($model === '') { continue; }
            $reads  = (int)($row['cache_reads'] ?? 0);
            $writes = (int)($row['cache_writes'] ?? 0);
            if ($reads === 0 && $writes === 0) { continue; }

            $usage = [
                'calls'  => max(1, (int)($row['calls'] ?? 1)),
                'reads'  => $reads,
                'writes' => $writes,
            ];
            $capability = self::capabilityFor($model);
            $verdict = CacheValuePolicy::advise($usage, $capability);
            $out[] = [
                'model'    => $model,
                'advice'   => $verdict['advice'],
                'reason'   => $verdict['reason'],
                'hit_rate' => $verdict['hit_rate'],
            ];
        }

        return $out;
    }

    /** @return array{family:string,explicit_breakpoints:bool|null} */
    private static function capabilityFor(string $model): array
    {
        $capability = Capabilities::forModel($model);
        if ($capability->isUnknown()) {
            return ['family' => '', 'explicit_breakpoints' => null];
        }

        return ['family' => '', 'explicit_breakpoints' => $capability->requiresBreakpoints()];
    }
}
