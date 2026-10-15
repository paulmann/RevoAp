<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Http\Response;
use PDO;

/**
 * R9 extension - what the recorded runs say about each model's usefulness.
 *
 * Read-only. It gathers two disjoint sets of facts and hands them to
 * {@see ModelUsefulness}, which does the folding:
 *
 *   - consensus rounds (`L3.*consensus` steps) carry the per-participant
 *     contribution the engine measured;
 *   - failed `L1.*` calls carry the calls a model did not answer, with the
 *     reason the transport reported.
 *
 * The two are never merged into one score: the second set is about the
 * connection and the provider, and counting it as a poor model would be a
 * misreading of the data.
 *
 * Everything degrades to an empty report instead of failing: this is an
 * informational view, not a gate.
 */
final class UsefulnessController
{
    private const DEFAULT_DAYS = 90;
    private const MAX_STEPS   = 2000;
    private const MAX_MODELS  = 200;
    private const MAX_METRIC_ROWS = 5000;

    public function __construct(
        private readonly ?Database $db = null,
        private readonly int $defaultDays = self::DEFAULT_DAYS,
    ) {
    }

    /** GET /api/models/usefulness */
    public function report(?int $days = null): string
    {
        // Same gate as the Models tab this feeds: the numbers are the operator's
        // own, and they name models the run used.
        if (!\App\Core\AdminAuth::gateState()['unlocked']) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $days = max(1, min(365, $days ?? $this->defaultDays));
        $models = [];
        $metrics = [];
        $loadError = null;

        if ($this->db !== null) {
            try {
                // Metrics are gathered separately so a missing cost or cache
                // column costs this table and not the report.
                try {
                    // $pdo belongs to the callback below, not to this scope, so the
                    // read goes through the same Database::run wrapper.
                    $metrics = $this->db->run(
                        fn(PDO $pdo): array => ModelMetrics::fromRows($this->metricRows($pdo, $days)),
                    );
                } catch (\Throwable) {
                    $metrics = [];
                }
                $models = $this->db->run(fn(PDO $pdo): array => ModelUsefulness::combine(
                    self::roundsFromRows($this->roundRows($pdo, $days)),
                    self::failuresFromRows($this->failureRows($pdo, $days)),
                ));
            } catch (\Throwable $e) {
                // Reported as "no data" with a reason, never as a 500: the
                // screen that shows this is diagnostic by nature.
                $loadError = 'usefulness_query_failed';
                $models = [];
            }
        }

        return Response::json([
            'ok'          => true,
            'metrics'     => $metrics,
            'days'        => $days,
            'measured'    => $models !== [],
            'load_error'  => $loadError,
            'thresholds'  => ['strong_ratio' => ModelUsefulness::STRONG_RATIO, 'weak_ratio' => ModelUsefulness::WEAK_RATIO],
            'note'        => 'Shares are the part of each answer the other participants corroborated, '
                . 'averaged per round. Calls a model did not answer are counted separately and never as a '
                . 'negative contribution.',
            'models'      => array_slice($models, 0, self::MAX_MODELS),
        ]);
    }


    /**
     * Latency, price and cache behaviour per model (R9).
     *
     * Read separately from the usefulness fold and in its own try/catch: a
     * deployment whose `cost` or cache columns are missing should lose this
     * table, not the whole report.
     *
     * @return array<int,array<string,mixed>>
     */
    private function metricRows(PDO $pdo, int $days): array
    {
        $sql = 'SELECT r.model AS model, r.provider AS provider, r.latency_ms AS latency_ms,'
            . ' r.total_tokens AS total_tokens, r.cost AS cost,'
            . ' r.cache_read_tokens AS cache_read_tokens, r.cache_write_tokens AS cache_write_tokens'
            . ' FROM council_model_responses r'
            . ' JOIN council_steps s ON s.id = r.step_id'
            . ' WHERE s.started_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
            . ' ORDER BY r.id DESC LIMIT ' . self::MAX_METRIC_ROWS;
        $stmt = $pdo->query($sql);

        return ($stmt === false ? [] : ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array<int,array<string,mixed>> */
    private function roundRows(PDO $pdo, int $days): array
    {
        $sql = 'SELECT s.meta FROM council_steps s'
            . ' WHERE s.step_key LIKE :pattern AND s.meta IS NOT NULL'
            . ' AND s.started_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
            . ' ORDER BY s.id DESC LIMIT ' . self::MAX_STEPS;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['pattern' => 'L3.%consensus']);

        return ($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    private function failureRows(PDO $pdo, int $days): array
    {
        $sql = 'SELECT s.step_key, s.summary, s.meta FROM council_steps s'
            . ' WHERE s.step_key LIKE :pattern AND s.status = :status'
            . ' AND s.started_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
            . ' ORDER BY s.id DESC LIMIT ' . self::MAX_STEPS;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['pattern' => 'L1.%', 'status' => 'failed']);

        return ($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    /**
     * Pull the recorded contribution objects out of the L3 step metas.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function roundsFromRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string)($row['meta'] ?? ''), true);
            if (!is_array($meta)) { continue; }
            $contribution = $meta['contribution'] ?? null;
            if (is_array($contribution) && is_array($contribution['models'] ?? null) && $contribution['models'] !== []) {
                $out[] = $contribution;
            }
        }

        return $out;
    }

    /**
     * Turn failed L1 steps into `{model, reason}` rows.
     *
     * The model is read from the step meta when the engine recorded it there
     * (it is the identifier the operator typed); the step key is normalised by
     * StepKeyHelper, so it is only the fallback.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{model:string,reason:string}>
     */
    public static function failuresFromRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string)($row['meta'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $model = trim((string)($meta['model'] ?? ''));
            if ($model === '') {
                $key = (string)($row['step_key'] ?? '');
                $at = strrpos($key, '@');
                $model = $at === false ? '' : substr($key, $at + 1);
            }
            if ($model === '') { continue; }

            $reason = trim((string)($meta['error'] ?? ''));
            if ($reason === '') {
                $reason = trim((string)($row['summary'] ?? ''));
            }
            $out[] = ['model' => $model, 'reason' => $reason];
        }

        return $out;
    }
}
