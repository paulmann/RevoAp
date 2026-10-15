<?php
declare(strict_types=1);

namespace App\Cost;

/**
 * R10 - what a run is likely to cost and take, from what similar runs cost.
 *
 * Pure arithmetic over observations the caller supplies: the estimator never
 * queries and never guesses. Every number it returns is a range, and a range
 * built on two samples says so - an estimate presented as a fact is worse than
 * no estimate at all.
 *
 * Cost units are whatever the provider reported (`ChatResponse::$cost`), so the
 * caller must not mix currencies; the model only compares and sums.
 */
final class RunCostEstimator
{
    /** @param array<int,array{model:string,cost:float,latency_ms:float,calls:int}> $observations */
    public static function fromObservations(array $observations, int $cycles = 1): array
    {
        $cycles = max(1, $cycles);
        $perModel = [];
        foreach ($observations as $row) {
            $model = (string)($row['model'] ?? '');
            if ($model === '') { continue; }
            $calls = max(1, (int)($row['calls'] ?? 1));
            $perModel[$model] = [
                'model'          => $model,
                'avg_cost'       => ((float)($row['cost'] ?? 0.0)) / $calls,
                'avg_latency_ms' => ((float)($row['latency_ms'] ?? 0.0)) / $calls,
                'calls'          => $calls,
            ];
        }

        if ($perModel === []) {
            return [
                'cost_low'  => null,
                'cost_high' => null,
                'eta_low_s' => null,
                'eta_high_s' => null,
                'samples'   => 0,
                'per_model' => [],
                'notes'     => 'No comparable run has been measured yet, so there is nothing to estimate from. '
                    . 'This is not a zero cost - it is an unknown one.',
            ];
        }

        $costLow = 0.0; $costHigh = 0.0; $latency = 0.0;
        foreach ($perModel as $row) {
            $costLow  += $row['avg_cost'];
            // The high end allows for the run needing a second pass: the
            // cheapest reading of "the model may answer twice" without
            // pretending to know the retry policy.
            $costHigh += $row['avg_cost'] * 2;
            $latency  += $row['avg_latency_ms'];
        }
        $costLow  *= $cycles;
        $costHigh *= $cycles;
        $latency  = $latency * $cycles / 1000.0;

        $samples = 0;
        foreach ($perModel as $row) { $samples += $row['calls']; }

        return [
            'cost_low'   => round($costLow, 6),
            'cost_high'  => round($costHigh, 6),
            'eta_low_s'  => (int)max(1, round($latency * 0.7)),
            'eta_high_s' => (int)max(2, round($latency * 1.6)),
            'samples'    => $samples,
            'per_model'  => $perModel,
            'notes'      => $samples < 5
                ? sprintf('Estimate from %d observation(s): treat it as a rough order of magnitude, not a quote.', $samples)
                : sprintf('Estimate from %d observation(s) of comparable runs.', $samples),
        ];
    }

    /**
     * The documented batch discount, applied as an *estimate*: the provider
     * charges less for the asynchronous path, and saying "about half" without
     * measuring it is exactly why this is labelled as an estimate.
     *
     * @param array{cost_low:?float,cost_high:?float} $estimate
     * @return array{low:?float,high:?float,note:string}
     */
    public static function batchAlternative(array $estimate): array
    {
        $low  = $estimate['cost_low'] ?? null;
        $high = $estimate['cost_high'] ?? null;
        if ($low === null || $high === null) {
            return ['low' => null, 'high' => null, 'note' => 'No estimate to compare against.'];
        }

        return [
            'low'  => round($low * 0.5, 6),
            'high' => round($high * 0.5, 6),
            'note' => 'Estimated from the provider\'s documented asynchronous discount, not measured on this deployment; '
                . 'the trade-off is the wait - minutes to 24 hours.',
        ];
    }
}
