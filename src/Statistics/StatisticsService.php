<?php
/**
 * StatisticsService: read-only aggregator for the Statistic admin tab.
 *
 * Reads from existing tables — does NOT introduce a new dedicated stats
 * store:
 *   - council_sessions               → session counts, durations, status
 *   - council_steps.meta (JSON)      → per-call provider, model, tokens,
 *                                      cost, latency, cache_hit and
 *                                      reasoning/cached token details
 *                                      (preferred source: PipelineEngine
 *                                      writes statsPayload + cacheMeta
 *                                      into every L1 step's meta).
 *   - council_model_responses        → fallback when meta is empty (no
 *                                      cost / cache info, but core token
 *                                      and latency figures are present).
 *
 * If the database is not configured, every public method returns a
 * documented "no_database" envelope so the controller can render a
 * clear diagnostic instead of a generic 500 — matching the History tab
 * pattern. Free-form fields are not returned to the client at all
 * (statistics are numeric), so no extra sanitization is needed.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.2
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\Statistics;

use App\Core\Database;
use App\Core\Logger;
use PDO;

final class StatisticsService
{
    public function __construct(
        private readonly null|PDO|Database $db,
        private readonly StatisticsAggregator $aggregator = new StatisticsAggregator(),
    ) {}

    public function isAvailable(): bool
    {
        return $this->db !== null;
    }

    /**
     * Expose the underlying connection so callers can produce a
     * DatabaseHealth snapshot without each controller reaching into
     * Application::pdo() separately.
     */
    public function db(): null|PDO|Database
    {
        return $this->db;
    }

    /**
     * Periods supported by the UI / API.
     *
     * @return array<int,string>
     */
    public function periods(): array
    {
        return StatisticsPeriod::SUPPORTED;
    }

    /**
     * Compute aggregated statistics for the given period.
     *
     * @param string                 $periodRaw raw period token from request
     * @param \DateTimeImmutable|null $anchor   optional anchor "now" (tests)
     * @return array<string,mixed>
     */
    public function summary(string $periodRaw, ?\DateTimeImmutable $anchor = null): array
    {
        if ($this->db === null) {
            return [
                'ok'      => false,
                'error'   => 'no_database',
                'message' => 'Persistent statistics require a configured database. Open the Settings tab to configure the DSN, then apply migrations.',
            ];
        }
        $range = StatisticsPeriod::range($periodRaw, $anchor);

        try {
            $sessions  = $this->fetchSessions($range['from'], $range['to']);
            $modelRows = $this->fetchModelRows($range['from'], $range['to']);
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'error'   => 'statistics_query_failed',
                'message' => Logger::sanitizeScalar('Statistics query failed: ' . $e->getMessage()),
                'period'  => $range['period'],
                'from'    => $range['from'],
                'to'      => $range['to'],
            ];
        }

        $payload = $this->aggregator->aggregate($sessions, $modelRows);
        $payload['ok']     = true;
        $payload['period'] = $range['period'];
        $payload['from']   = $range['from'];
        $payload['to']     = $range['to'];
        $payload['anchor'] = $range['anchor'];
        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchSessions(string $from, string $to): array
    {
        return (array)$this->run(static function (PDO $pdo) use ($from, $to): array {
            // Only the columns the aggregator actually reads. We deliberately
            // omit completed_at so the query runs against minimal smoke-test
            // schemas that don't ship it.
            $stmt = $pdo->prepare(
                'SELECT id, status, total_tokens, duration_ms, created_at
                 FROM council_sessions
                 WHERE created_at >= :from AND created_at < :to'
            );
            $stmt->execute(['from' => $from, 'to' => $to]);
            $rs = $stmt->fetchAll();
            return is_array($rs) ? $rs : [];
        });
    }

    /**
     * Returns one row per LLM call. Source of truth is council_steps.meta
     * JSON because PipelineEngine writes the statsPayload + cacheMeta
     * into every L1 step at completion time. We fall back to
     * council_model_responses for sessions whose meta was empty (e.g.
     * synthesis steps, or failed steps where only the response row was
     * written).
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchModelRows(string $from, string $to): array
    {
        $rows = (array)$this->run(static function (PDO $pdo) use ($from, $to): array {
            $stmt = $pdo->prepare(
                'SELECT cs.id AS session_id, cs.created_at AS session_created_at,
                        st.id AS step_id, st.step_key, st.label, st.status AS step_status,
                        st.meta AS meta_json,
                        st.started_at AS step_started, st.ended_at AS step_ended
                 FROM council_sessions cs
                 INNER JOIN council_steps st ON st.session_id = cs.id
                 WHERE cs.created_at >= :from AND cs.created_at < :to'
            );
            $stmt->execute(['from' => $from, 'to' => $to]);
            $rs = $stmt->fetchAll();
            return is_array($rs) ? $rs : [];
        });

        $stepsWithMeta   = [];
        $callRows        = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $stepId = (int)($r['step_id'] ?? 0);
            $meta   = self::decodeJson($r['meta_json'] ?? null);
            if (is_array($meta) && isset($meta['provider'], $meta['model'])) {
                $stepsWithMeta[$stepId] = true;
                $callRows[] = [
                    'provider'          => (string)$meta['provider'],
                    'model'             => (string)$meta['model'],
                    'prompt_tokens'     => (int)($meta['prompt_tokens']     ?? 0),
                    'completion_tokens' => (int)($meta['completion_tokens'] ?? 0),
                    'total_tokens'      => (int)($meta['total_tokens']      ?? 0),
                    'latency_ms'        => (int)($meta['latency_ms']        ?? 0),
                    'cached_tokens'     => (int)($meta['cached_tokens']     ?? 0),
                    'reasoning_tokens'  => (int)($meta['reasoning_tokens']  ?? 0),
                    'cache_hit'         => (bool)($meta['cache_hit']        ?? false),
                    'tokens_estimated'  => (bool)($meta['tokens_estimated'] ?? false),
                    'cost'              => isset($meta['cost']) && is_numeric($meta['cost'])
                                                ? (float)$meta['cost'] : null,
                    'status'            => (string)($r['step_status'] ?? 'completed'),
                ];
            }
        }

        // Use uniformly-named placeholders to avoid PDO's
        // "mixed named and positional parameters" error (HY093).
        // Each excluded step id gets its own :skipN placeholder.
        $stepIds = array_keys($stepsWithMeta);
        $skipPlaceholders = [];
        foreach ($stepIds as $i => $_sid) {
            $skipPlaceholders[] = ':skip' . $i;
        }
        $skipClause = $skipPlaceholders === [] ? '' : implode(',', $skipPlaceholders);

        $fallbackRows = (array)$this->run(static function (PDO $pdo) use ($from, $to, $stepIds, $skipClause): array {
            $sql = 'SELECT mr.model, mr.provider, mr.prompt_tokens, mr.completion_tokens,
                           mr.total_tokens, mr.latency_ms, mr.tokens_estimated, mr.step_id
                    FROM council_model_responses mr
                    INNER JOIN council_steps st ON st.id = mr.step_id
                    INNER JOIN council_sessions cs ON cs.id = st.session_id
                    WHERE cs.created_at >= :from AND cs.created_at < :to';
            if ($skipClause !== '') {
                $sql .= ' AND mr.step_id NOT IN (' . $skipClause . ')';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':from', $from);
            $stmt->bindValue(':to',   $to);
            foreach ($stepIds as $i => $sid) {
                $stmt->bindValue(':skip' . $i, (int)$sid, PDO::PARAM_INT);
            }
            $stmt->execute();
            $rs = $stmt->fetchAll();
            return is_array($rs) ? $rs : [];
        });

        foreach ($fallbackRows as $r) {
            if (!is_array($r)) { continue; }
            $callRows[] = [
                'provider'          => (string)($r['provider'] ?? ''),
                'model'             => (string)($r['model']    ?? ''),
                'prompt_tokens'     => (int)($r['prompt_tokens']     ?? 0),
                'completion_tokens' => (int)($r['completion_tokens'] ?? 0),
                'total_tokens'      => (int)($r['total_tokens']      ?? 0),
                'latency_ms'        => (int)($r['latency_ms']        ?? 0),
                'cached_tokens'     => 0,
                'reasoning_tokens'  => 0,
                'cache_hit'         => false,
                'tokens_estimated'  => (int)($r['tokens_estimated'] ?? 0) === 1,
                'cost'              => null,
                'status'            => 'completed',
            ];
        }
        return $callRows;
    }

    private static function decodeJson(mixed $raw): mixed
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @template T
     * @param  callable(PDO):T $fn
     * @return T
     */
    private function run(callable $fn): mixed
    {
        if ($this->db instanceof Database) {
            return $this->db->run($fn);
        }
        if ($this->db instanceof PDO) {
            return $fn($this->db);
        }
        throw new \RuntimeException('statistics database is not configured');
    }
}
