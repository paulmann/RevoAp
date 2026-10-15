<?php
/**
 * AvailabilityMonitor: keeps the availability table honest when traffic does not.
 *
 * The pipeline records every real call, which is enough for popular models.
 * It is not enough for the two cases that actually hurt:
 *
 *   1. **A model nobody has called for a week.** Its verdict is whatever it
 *      was a week ago. If the provider retired it in the meantime, the first
 *      run that selects it pays a full timeout and reports a failure the
 *      monitor could have predicted.
 *   2. **A model that just recovered.** Its counters are still full of the
 *      outage, so it stays Unavailable and keeps being substituted out long
 *      after it is fine.
 *
 * The monitor closes both gaps with a scheduled probe: a minimal chat call
 * (one token, cheapest model, short timeout) that costs a fraction of a cent
 * and produces a fresh verdict. It is the scheduler task
 * `probe_model_availability`, and it can also be run by hand from the Models
 * tab ("Probe now").
 *
 * Deliberate limits
 * -----------------
 *   - **Concurrency is capped** (`maxParallel`, default 4). Probing 200
 *     models at once looks like an attack on the provider and will get the
 *     key rate-limited, which then makes every model look Unavailable.
 *   - **A probe never costs more than it saves.** Models are skipped when
 *     they were called successfully inside the window already — real traffic
 *     is strictly better evidence than a synthetic ping.
 *   - **Retired and disabled models are not probed.** Probing something the
 *     operator switched off is noise, and probing a removed model produces a
 *     permanent 404 in the provider's logs against your account.
 *   - **Failures are recorded but never thrown.** A monitor that can take
 *     down the request it runs in is worse than no monitor.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

use App\Connectors\ConnectorRegistry;
use App\Core\Logger;

final class AvailabilityMonitor
{
    /** Probe payload. One token out, no system prompt: the cheapest possible
     *  call that still exercises authentication, routing and the model id. */
    private const PROBE_PROMPT = 'ping';
    private const PROBE_MAX_TOKENS = 1;

    public function __construct(
        private readonly AvailabilityRepository $repository,
        private readonly ?ConnectorRegistry $connectors = null,
        private readonly int $maxParallel = 4,
        private readonly float $timeoutSeconds = 12.0,
    ) {
    }

    /**
     * Decide which models need a probe right now.
     *
     * @param  array<int,array<string,mixed>> $catalogue
     * @param  array<string,ModelAvailability> $availability
     * @return array<int,array<string,mixed>> catalogue rows, probe order
     */
    public function dueForProbe(array $catalogue, array $availability, FallbackPolicy $policy): array
    {
        if (!$policy->monitorEnabled) {
            return [];
        }
        $now = time();
        $due = [];
        foreach ($catalogue as $row) {
            $id = AlternativeResolver::id($row);
            if ($id === '') { continue; }
            if (!$this->isProbeable($row)) { continue; }

            $state = $availability[$id] ?? null;
            if ($state === null) {
                $due[$id] = ['row' => $row, 'age' => PHP_INT_MAX, 'priority' => 3];
                continue;
            }
            // Skip Retired: probing it can only produce a 404.
            if ($state->status === AvailabilityStatus::Retired) { continue; }

            // Fresh successful traffic is better evidence than a probe.
            $sinceSuccess = $state->secondsSinceSuccess($now);
            if ($sinceSuccess !== null && $sinceSuccess < $policy->monitorWindowSeconds) { continue; }

            $reference = $state->lastProbedAt ?? $state->lastFailureAt ?? $state->lastSuccessAt ?? 0;
            $age = max(0, $now - $reference);
            if ($age < $policy->monitorIntervalSeconds) { continue; }

            // Prioritise: a model that is currently marked bad and might have
            // recovered is more valuable to probe than a healthy-but-quiet one,
            // because a false Unavailable silently shrinks every council.
            $priority = match (true) {
                $state->status === AvailabilityStatus::Unavailable => 1,
                $state->status === AvailabilityStatus::Degraded    => 2,
                default                                            => 3,
            };
            $due[$id] = ['row' => $row, 'age' => $age, 'priority' => $priority];
        }

        // Worst first, then oldest first, then by id for stability.
        uasort($due, static function (array $a, array $b, ): int {
            return ($a['priority'] <=> $b['priority'])
                ?: ($b['age'] <=> $a['age'])
                ?: strcmp((string)$a['row']['model'], (string)$b['row']['model']);
        });

        return array_map(static fn(array $e): array => $e['row'], $due);
    }

    /**
     * Run the probes. Returns a summary for the scheduler's run record.
     *
     * @param  array<int,array<string,mixed>> $targets
     * @return array{probed:int,ok:int,failed:int,skipped:int,duration_ms:int,results:array<int,array<string,mixed>>}
     */
    public function probe(array $targets): array
    {
        $started = microtime(true);
        $results = [];
        $ok = 0;
        $failed = 0;
        $skipped = 0;

        if ($this->connectors === null) {
            // No connector registry means this is a dry inspection (the admin
            // panel asking "what WOULD be probed?"), not a real probe run.
            foreach ($targets as $row) {
                $results[] = [
                    'id' => AlternativeResolver::id($row),
                    'status' => 'skipped',
                    'reason' => 'no_connector_registry',
                ];
                $skipped++;
            }
            return $this->summary($ok, $failed, $skipped, $results, $started);
        }

        // Process in bounded batches. curl_multi with all handles open at once
        // is how a 200-model catalogue exhausts the file-descriptor limit.
        foreach (array_chunk($targets, max(1, $this->maxParallel)) as $batch) {
            foreach ($batch as $row) {
                $id = AlternativeResolver::id($row);
                $provider = (string)($row['provider'] ?? '');
                $model = (string)($row['model'] ?? '');
                if ($id === '' || $provider === '' || $model === '') {
                    $skipped++;
                    $results[] = ['id' => $id, 'status' => 'skipped', 'reason' => 'incomplete_row'];
                    continue;
                }
                [$success, $latencyMs, $error, $reason] = $this->probeOne($provider, $model);
                if ($success) {
                    $ok++;
                    $this->repository->recordProbe($id, true, $latencyMs);
                    $results[] = ['id' => $id, 'status' => 'healthy', 'latency_ms' => $latencyMs];
                } else {
                    $failed++;
                    $this->repository->recordProbe($id, false, $latencyMs, $error);
                    $results[] = [
                        'id' => $id,
                        'status' => 'failed',
                        'reason' => $reason?->value,
                        'reason_label_key' => $reason?->labelKey(),
                        'error' => $error,
                        'latency_ms' => $latencyMs,
                    ];
                }
            }
        }

        return $this->summary($ok, $failed, $skipped, $results, $started);
    }

    /**
     * Probe one model.
     *
     * @return array{0:bool,1:?float,2:?string,3:?UnavailableReason}
     *         [ok, latencyMs, error, reason]
     */
    private function probeOne(string $provider, string $model): array
    {
        $started = microtime(true);
        try {
            $connector = $this->connectors?->get($provider);
            if ($connector === null) {
                return [false, null, 'no connector registered for ' . $provider, UnavailableReason::KeyMissing];
            }

            $response = $connector->chat(
                model: $model,
                messages: [['role' => 'user', 'content' => self::PROBE_PROMPT]],
                options: [
                    'max_tokens' => self::PROBE_MAX_TOKENS,
                    'temperature' => 0,
                    'stream' => false,
                    // A probe that waits 60 s for a timeout defeats the point:
                    // the whole monitor run would take longer than a real call.
                    'timeout_seconds' => $this->timeoutSeconds,
                ]
            );

            $latency = (microtime(true) - $started) * 1000.0;

            if ($response === null) {
                return [false, $latency, 'connector returned no response', UnavailableReason::ProviderError];
            }
            if (method_exists($response, 'isSuccess') ? !$response->isSuccess() : (bool)($response->ok ?? true) === false) {
                $error = (string)(method_exists($response, 'error') ? $response->error() : ($response->error ?? 'unknown'));
                return [false, $latency, $error, self::classify($error)];
            }
            return [true, $latency, null, null];
        } catch (\App\Connectors\Exceptions\ConnectorKeyNotFoundException $e) {
            return [false, (microtime(true) - $started) * 1000.0, $e->getMessage(), UnavailableReason::KeyMissing];
        } catch (\Throwable $e) {
            $latency = (microtime(true) - $started) * 1000.0;
            $message = $e->getMessage();
            Logger::debug('availability probe failed for ' . $provider . '/' . $model . ': ' . $message);
            return [false, $latency, $message, self::classify($message)];
        }
    }

    /**
     * Map an error string onto a reason.
     *
     * Matching on text is inherently fragile, so the patterns are ordered
     * most-specific-first and the default is ProviderError (transient, worth
     * retrying) rather than Removed (permanent). Guessing "removed" from a
     * network error would retire a healthy model.
     */
    public static function classify(string $error): UnavailableReason
    {
        $e = strtolower($error);
        return match (true) {
            str_contains($e, 'api key'), str_contains($e, 'unauthorized'),
            str_contains($e, 'authentication'), str_contains($e, 'invalid_api_key'),
            str_contains($e, '401')                                => UnavailableReason::KeyMissing,
            str_contains($e, 'rate limit'), str_contains($e, '429'),
            str_contains($e, 'quota'), str_contains($e, 'insufficient'),
            str_contains($e, 'credit')                             => UnavailableReason::Quota,
            str_contains($e, 'not found'), str_contains($e, '404'),
            str_contains($e, 'unknown model'), str_contains($e, 'no such model'),
            str_contains($e, 'does not exist')                     => UnavailableReason::Removed,
            str_contains($e, 'timeout'), str_contains($e, 'timed out'),
            str_contains($e, '28')                                 => UnavailableReason::Timeout,
            str_contains($e, 'content filter'), str_contains($e, 'moderation'),
            str_contains($e, 'flagged'), str_contains($e, 'policy') => UnavailableReason::ContentFiltered,
            str_contains($e, 'context'), str_contains($e, 'too long'),
            str_contains($e, 'maximum context')                    => UnavailableReason::ContextTooSmall,
            str_contains($e, '500'), str_contains($e, '502'), str_contains($e, '503'),
            str_contains($e, '504'), str_contains($e, 'server error'),
            str_contains($e, 'bad gateway'), str_contains($e, 'service unavailable')
                                                                   => UnavailableReason::ProviderError,
            default                                                => UnavailableReason::ProviderError,
        };
    }

    /** Only enabled rows with enough identity to call are worth a request. */
    private function isProbeable(array $row): bool
    {
        foreach (['enabled', 'active', 'is_active'] as $flag) {
            if (array_key_exists($flag, $row) && !$row[$flag]) {
                return false;
            }
        }
        return ($row['provider'] ?? '') !== '' && ($row['model'] ?? '') !== '';
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @return array<string,mixed>
     */
    private function summary(int $ok, int $failed, int $skipped, array $results, float $started): array
    {
        return [
            'probed'      => $ok + $failed,
            'ok'          => $ok,
            'failed'      => $failed,
            'skipped'     => $skipped,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'results'     => $results,
        ];
    }
}
