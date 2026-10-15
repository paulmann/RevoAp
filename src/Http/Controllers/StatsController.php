<?php
/**
 * StatsController: Returns per-session statistics.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Connectors\ChatResponse;
use App\Http\Response;
use App\Stats\StatsAggregator;
use App\Storage\ResponseRepository;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;

final class StatsController
{
    public function __construct(
        private readonly SessionRepository  $sessions,
        private readonly StepRepository     $steps,
        private readonly ResponseRepository $responses,
        private readonly StatsAggregator    $aggregator,
    ) {}

    /** @param array<string,string> $params */
    public function show(array $params): string
    {
        $id = (int)($params['id'] ?? 0);
        $session = $this->sessions->find($id);
        if ($session === null) {
            return Response::json(['error' => 'session not found'], 404);
        }
        $steps = $this->steps->listStepsBySession($id);
        $rows  = [];
        foreach ($steps as $step) {
            $stepKey = (string)$step['step_key'];
            $layer   = preg_match('/^(L\d)/', $stepKey, $m) === 1 ? $m[1] : 'L?';
            foreach ($this->responses->listByStep((int)$step['id']) as $resp) {
                $promptT = (int)($resp['prompt_tokens'] ?? 0);
                $complT  = (int)($resp['completion_tokens'] ?? 0);
                $totalT  = (int)($resp['total_tokens'] ?? 0);
                if ($totalT !== $promptT + $complT) {
                    $totalT = $promptT + $complT;
                }
                $cr = new ChatResponse(
                    content:          (string)($resp['raw_content'] ?? ''),
                    model:            (string)$resp['model'],
                    provider:         (string)$resp['provider'],
                    promptTokens:     $promptT,
                    completionTokens: $complT,
                    totalTokens:      $totalT,
                    latencyMs:        (float)($resp['latency_ms'] ?? 0),
                    rawResponse:      [],
                    tokensEstimated:  (bool)($resp['tokens_estimated'] ?? 0),
                    // Prompt cache. These three columns exist only once
                    // db/migrations/018 has been applied; the isset() guard
                    // keeps a pre-018 database working, and null is then
                    // read as "no cache data" — which is the truth for it.
                    cachedTokens:     isset($resp['cache_read_tokens']) && $resp['cache_read_tokens'] !== null
                                          ? (int)$resp['cache_read_tokens'] : null,
                    cacheWriteTokens: isset($resp['cache_write_tokens']) && $resp['cache_write_tokens'] !== null
                                          ? (int)$resp['cache_write_tokens'] : null,
                    cacheDiscount:    isset($resp['cache_discount']) && is_numeric($resp['cache_discount'])
                                          ? (float)$resp['cache_discount'] : null,
                );
                $rows[] = ['step' => $stepKey, 'layer' => $layer, 'resp' => $cr];
            }
        }
        $totalLatency = (float)($session['duration_ms'] ?? 0);
        $stats = $this->aggregator->aggregate($rows, $totalLatency);
        return Response::json([
            'session' => $session,
            'stats'   => $stats->toArray(),
        ]);
    }
}
