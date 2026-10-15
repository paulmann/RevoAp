<?php
/**
 * StatsCollector: In-memory recorder of per-step ChatResponse stats.
 *
 * Retains every recorded ChatResponse so that step-, layer-, and
 * session-level aggregates can be rebuilt on demand.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Stats;

use App\Connectors\ChatResponse;

final class StatsCollector
{
    /** @var array<string,array<int,array{step:string,layer:string,resp:ChatResponse}>> */
    private array $byStep = [];

    private float $startedAt;
    private ?float $endedAt = null;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Record a ChatResponse against a given step (and inferred layer).
     *
     * @param  string $stepKey   e.g. 'L1.role.analyst'
     * @param  ChatResponse $response
     * @param  string|null  $layer     Override; otherwise derived from prefix.
     */
    public function record(string $stepKey, ChatResponse $response, ?string $layer = null): void
    {
        $layer ??= $this->inferLayer($stepKey);
        $this->byStep[$stepKey] ??= [];
        $this->byStep[$stepKey][] = [
            'step'  => $stepKey,
            'layer' => $layer,
            'resp'  => $response,
        ];
    }

    public function finish(): void
    {
        if ($this->endedAt === null) {
            $this->endedAt = microtime(true);
        }
    }

    /** @return TokenUsage[] */
    public function getStepStats(string $stepKey): array
    {
        $out = [];
        foreach ($this->byStep[$stepKey] ?? [] as $row) {
            $out[] = $this->toUsage($row['resp']);
        }
        return $out;
    }

    public function getSessionStats(): SessionStats
    {
        $aggregator = new StatsAggregator();
        $rows = [];
        foreach ($this->byStep as $stepRows) {
            foreach ($stepRows as $r) { $rows[] = $r; }
        }
        $end = $this->endedAt ?? microtime(true);
        return $aggregator->aggregate($rows, ($end - $this->startedAt) * 1000.0);
    }

    private function toUsage(ChatResponse $r): TokenUsage
    {
        return new TokenUsage(
            model:            $r->effectiveModel(),
            provider:         $r->provider,
            promptTokens:     $r->promptTokens,
            completionTokens: $r->completionTokens,
            totalTokens:      $r->totalTokens,
            latencyMs:        $r->latencyMs,
            calls:            1,
            cost:             $r->cost,
            costAvailable:    $r->cost !== null,
            cachedTokens:     $r->cachedTokens ?? 0,
            reasoningTokens:  $r->reasoningTokens ?? 0,
            tokensEstimated:  $r->tokensEstimated,
            cacheWriteTokens: $r->cacheWriteTokens ?? 0,
            cacheCalls:       $r->cacheWasRead() ? 1 : 0,
            cacheReportedCalls: $r->hasCacheStats() ? 1 : 0,
            cacheDiscount:    $r->cacheDiscount,
            cacheDiscountAvailable: $r->cacheDiscount !== null,
        );
    }

    private function inferLayer(string $stepKey): string
    {
        if (preg_match('/^(L\d)/', $stepKey, $m) === 1) {
            return $m[1];
        }
        return 'L?';
    }
}
