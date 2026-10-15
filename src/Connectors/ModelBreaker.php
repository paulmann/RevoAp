<?php
declare(strict_types=1);

namespace App\Connectors;

/**
 * R11 - stop calling a model that has plainly stopped answering, within one run.
 *
 * A consensus run issues several rounds of L1 calls. Without this, a model whose
 * provider is down is asked again in every round, and each round waits for its
 * timeout - so one dead participant can multiply the run's wall-clock time by
 * the cycle limit while contributing nothing.
 *
 * The breaker is deliberately **per run and in memory**:
 *
 *   - it answers the question a single run asks ("is this model still worth
 *     calling in *this* council?"), not a fleet-wide health question;
 *   - it needs no storage, so it cannot become stale state that outlives the
 *     incident, and a provider that recovers is retried on the next run - which
 *     is the right default for a system where the operator watches runs;
 *   - it never hides a failure: every open circuit is reported with the model
 *     and the reason, so the outcome says "3 consecutive failures, skipped in
 *     the remaining rounds" instead of quietly returning fewer opinions.
 *
 * A cross-run breaker would need a store and a recovery policy; that is a
 * different feature and is not smuggled in here.
 */
final class ModelBreaker
{
    /** @var array<string,int> consecutive failures per model */
    private array $failures = [];

    /** @var array<string,string> why a model was dropped, for the report */
    private array $reasons = [];

    public function __construct(private readonly ?int $threshold = null)
    {
    }

    public function threshold(): int
    {
        return $this->threshold ?? CallPolicy::breakerThreshold();
    }

    /** Record one finished call. A success clears the streak. */
    public function record(string $model, bool $ok, string $reason = ''): void
    {
        if ($model === '') {
            return;
        }
        if ($ok) {
            unset($this->failures[$model], $this->reasons[$model]);

            return;
        }
        $count = ($this->failures[$model] ?? 0) + 1;
        $this->failures[$model] = $count;
        if ($count >= $this->threshold()) {
            $this->reasons[$model] = sprintf(
                '%d consecutive failures in this run%s',
                $count,
                $reason !== '' ? ' (' . $reason . ')' : '',
            );
        }
    }

    public function isOpen(string $model): bool
    {
        return isset($this->reasons[$model]);
    }

    /** @return array<int,array{model:string,reason:string}> */
    public function summary(): array
    {
        $out = [];
        foreach ($this->reasons as $model => $reason) {
            $out[] = ['model' => $model, 'reason' => $reason];
        }

        return $out;
    }


    /** Why this model was dropped, for the operator-facing report. */
    public function openReason(string $model): string
    {
        return $this->reasons[$model] ?? '';
    }
    /** @return array<string,int> */
    public function streaks(): array
    {
        return $this->failures;
    }
}
