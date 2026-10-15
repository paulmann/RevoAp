<?php
declare(strict_types=1);

namespace App\Cost;

use App\Core\Database;
use PDO;

/**
 * R10 - what comparable runs actually cost and took, read from the journal.
 *
use App\Core\Logger;
 * The estimator is pure: it never queries. This class is the other half - it
 * turns persisted per-call rows into the observations the estimator consumes,
 * and it is deliberately thin so the arithmetic stays testable.
 *
 * Two honesty rules, both learned from the columns themselves:
 *
 *   - `cost` is NULL when the provider did not report a price. Such a call is
 *     counted (it happened) but contributes no money, and the result says how
 *     many calls had a price at all. Treating a missing price as zero would
 *     make the least-documented provider look like the cheapest one.
 *   - when the `cost` column does not exist yet (a database that has not
 *     applied `db/migrations/019`), the query still runs and reports every
 *     price as unreported, rather than failing the whole estimate.
 *
 * Reads only; this class never writes.
 */
final class CostHistory
{
    private const DEFAULT_DAYS = 30;
    private const MAX_ROWS     = 5000;

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * Per-model observations for the estimator, newest window first.
     *
     * @return array<int,array{model:string,provider:string,calls:int,latency_ms:float,cost:float,tokens:int,cost_reported:int}>
     */
    public function observations(int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, min(365, $days));
        if ($this->db === null) {
            return [];
        }

        try {
            return $this->db->run(function (PDO $pdo) use ($days): array {
                $hasCost = $this->costColumnPresent($pdo);
                // Each fragment ends where the next one needs a comma; the
                // cache fragment is last, so it carries none. Getting this wrong
                // produced an invalid statement whose error the catch below used
                // to swallow - the reason this comment exists.
                $costSelect = $hasCost
                    ? 'SUM(CASE WHEN r.cost IS NULL THEN 0 ELSE r.cost END) AS cost_total,'
                      . ' SUM(CASE WHEN r.cost IS NULL THEN 0 ELSE 1 END) AS cost_calls,'
                    : '0 AS cost_total, 0 AS cost_calls,';

                // Cache totals ride along so the advisor can say something
                // measured about caching per model. The columns come from
                // migration 018, so a database without them reports zeros here
                // - which the advisor reads as "nothing measured" and stays
                // silent about, rather than as "the cache never fired".
                $cacheSelect = $this->cacheColumnsPresent($pdo)
                    ? 'SUM(COALESCE(r.cache_read_tokens,0)) AS cache_reads,'
                      . ' SUM(COALESCE(r.cache_write_tokens,0)) AS cache_writes'
                    : '0 AS cache_reads, 0 AS cache_writes';

                $sql = 'SELECT r.model AS model, r.provider AS provider,'
                    . ' COUNT(*) AS calls,'
                    . ' SUM(r.latency_ms) AS latency_total,'
                    . ' SUM(r.total_tokens) AS tokens_total,'
                    . ' ' . $costSelect
                    . ' ' . $cacheSelect
                    . ' FROM council_model_responses r'
                    . ' JOIN council_steps s ON s.id = r.step_id'
                    // The window is interpolated, not bound: MySQL does not accept a
                    // placeholder inside an INTERVAL expression, and $days is clamped to
                    // an integer above. A bound parameter here reads as "no history" for
                    // every deployment - which is exactly what it did before this.
                    . ' WHERE s.started_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
                    . ' GROUP BY r.model, r.provider'
                    . ' ORDER BY calls DESC'
                    . ' LIMIT ' . self::MAX_ROWS;

                $stmt = $pdo->query($sql);

                return self::fromRows((($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC))) ?: []);
            });
        } catch (\Throwable $e) {
            // A failed read is "no history", never a broken page - but never
            // silent either: the reason goes to the log, because a swallow here
            // is what turned one missing comma into an hour of digging.
            Logger::error('cost.history.query_failed', [
                'days'    => $days,
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);

            return [];
        }
    }

    /**
     * Shape the raw aggregate rows. Pure, so the arithmetic can be tested
     * without a database.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{model:string,provider:string,calls:int,latency_ms:float,cost:float,tokens:int,cost_reported:int}>
     */
    public static function fromRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $model = trim((string)($row['model'] ?? ''));
            if ($model === '') { continue; }
            $calls = max(1, (int)($row['calls'] ?? 1));
            $out[] = [
                'model'         => $model,
                'provider'      => (string)($row['provider'] ?? ''),
                'calls'         => $calls,
                // Totals are kept as totals: the estimator divides by the call
                // count itself, so nothing here pre-averages and then averages
                // again.
                'latency_ms'    => (float)($row['latency_total'] ?? 0.0),
                'cost'          => (float)($row['cost_total'] ?? 0.0),
                'tokens'        => (int)($row['tokens_total'] ?? 0),
                'cost_reported' => (int)($row['cost_calls'] ?? 0),
                'cache_reads'   => (int)($row['cache_reads'] ?? 0),
                'cache_writes'  => (int)($row['cache_writes'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Turn observations into the estimator's input, keeping the "no price"
     * count so the caller can say so instead of implying a free run.
     *
     * @param array<int,array<string,mixed>> $observations
     * @return array{rows:array<int,array{model:string,cost:float,latency_ms:float,calls:int}>,calls:int,priced_calls:int,note:string}
     */
    public static function toEstimatorInput(array $observations, int $cycles = 1): array
    {
        $rows = [];
        $calls = 0;
        $priced = 0;
        foreach ($observations as $row) {
            $rows[] = [
                'model'      => (string)($row['model'] ?? ''),
                'cost'       => (float)($row['cost'] ?? 0.0),
                'latency_ms' => (float)($row['latency_ms'] ?? 0.0),
                'calls'      => max(1, (int)($row['calls'] ?? 1)),
            ];
            $calls  += max(1, (int)($row['calls'] ?? 1));
            $priced += max(0, (int)($row['cost_reported'] ?? 0));
        }

        $note = '';
        if ($rows === []) {
            $note = 'No comparable calls have been recorded yet.';
        } elseif ($priced === 0) {
            $note = 'None of the recorded calls carries a provider-reported price, so a money estimate is impossible; '
                . 'the time estimate still stands.';
        } elseif ($priced < $calls) {
            $note = sprintf(
                'Only %d of %d recorded call(s) carry a provider-reported price, so the cost estimate is a floor rather than a range.',
                $priced,
                $calls,
            );
        }

        return [
            'rows'         => RunCostEstimator::fromObservations($rows, $cycles)['per_model'] === []
                ? []
                : $rows,
            'calls'        => $calls,
            'priced_calls' => $priced,
            'note'         => $note,
        ];
    }

    /** Probe for the column added by db/migrations/019, driver-aware. */
    private function costColumnPresent(PDO $pdo): bool
    {
        if ($this->hasCostColumn !== null) {
            return $this->hasCostColumn;
        }
        $found = false;
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $rows = $pdo->query('PRAGMA table_info(council_model_responses)');
                foreach (($rows === false ? [] : $rows->fetchAll()) ?: [] as $row) {
                    if (is_array($row) && ($row['name'] ?? '') === 'cost') { $found = true; break; }
                }
            } elseif ($driver === 'mysql') {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM council_model_responses LIKE 'cost'");
                $stmt->execute();
                $found = $stmt->fetch() !== false;
            } else {
                $pdo->query('SELECT cost FROM council_model_responses WHERE 1=0');
                $found = true;
            }
        } catch (\Throwable) {
            $found = false;
        }

        return $this->hasCostColumn = $found;
    }

    /** Probe for the cache columns added by db/migrations/018, driver-aware. */
    private function cacheColumnsPresent(PDO $pdo): bool
    {
        if ($this->hasCacheColumns !== null) {
            return $this->hasCacheColumns;
        }
        $found = false;
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $rows = $pdo->query('PRAGMA table_info(council_model_responses)');
                foreach (($rows === false ? [] : $rows->fetchAll()) ?: [] as $row) {
                    if (is_array($row) && ($row['name'] ?? '') === 'cache_read_tokens') { $found = true; break; }
                }
            } elseif ($driver === 'mysql') {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM council_model_responses LIKE 'cache_read_tokens'");
                $stmt->execute();
                $found = $stmt->fetch() !== false;
            } else {
                $pdo->query('SELECT cache_read_tokens FROM council_model_responses WHERE 1=0');
                $found = true;
            }
        } catch (\Throwable) {
            $found = false;
        }

        return $this->hasCacheColumns = $found;
    }

    private ?bool $hasCostColumn = null;
    private ?bool $hasCacheColumns = null;
}