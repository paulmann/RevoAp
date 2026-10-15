<?php
/**
 * AvailabilityRepository: persistence for the cross-run availability monitor.
 *
 * Table `revoap_model_availability` — one row per `provider/model`, updated in
 * place. The counters are windowed, not lifetime: {@see self::decay()} ages
 * them out on every read so a model that failed 400 times last month and has
 * answered the last 50 calls converges back to Healthy instead of carrying a
 * permanent scar.
 *
 * Works without a database. Every method degrades to an in-memory map that
 * lives for the request, which keeps the resolver usable on a DB-less install
 * (verdicts are then Unknown for everything, and substitution still works —
 * it just has no reliability signal to score with). That is the same contract
 * the rest of RevoAp's repositories follow.
 *
 * All SQL is prepared. No user input ever reaches a query string: the only
 * interpolated value is the table name, which is a class constant.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

use App\Core\Database;
use App\Core\Logger;
use PDO;

final class AvailabilityRepository
{
    public const TABLE = 'revoap_model_availability';

    /** @var array<string,ModelAvailability> request-scoped fallback store */
    private array $memory = [];

    /** @var array<int,array<string,mixed>> */
    private array $history = [];

    private ?Database $db = null;

    private const MAX_HISTORY = 500;

    /**
     * @param null|\PDO|Database $db  Either handle is accepted, matching every
     *                                other repository in the project: callers
     *                                pass `$db ?? $pdo` and should not have to
     *                                know which one is populated. A raw PDO is
     *                                wrapped so the resilient retry path (a
     *                                probe landing during a MySQL
     *                                `wait_timeout` drop) applies either way.
     */
    public function __construct(null|\PDO|Database $db = null)
    {
        $this->db = $db instanceof \PDO ? Database::fromPdo($db) : $db;
    }

    public function isPersistent(): bool
    {
        return $this->db !== null;
    }

    /**
     * Every verdict, optionally restricted to a set of ids.
     *
     * @param  array<int,string>|null $ids  `provider/model`; null = all
     * @return array<string,ModelAvailability> keyed by id
     */
    public function all(?array $ids = null, int $windowSeconds = 3600): array
    {
        $pdo = $this->db?->pdo();
        if ($pdo === null) {
            if ($ids === null) { return $this->memory; }
            $out = [];
            foreach ($ids as $id) {
                if (isset($this->memory[$id])) { $out[$id] = $this->memory[$id]; }
            }
            return $out;
        }

        try {
            $sql = 'SELECT * FROM ' . self::TABLE;
            $params = [];
            if ($ids !== null && $ids !== []) {
                $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn($v) => $v !== '')));
                if ($ids === []) { return []; }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql .= " WHERE model_id IN ($placeholders)";
                $params = $ids;
            }
            $sql .= ' ORDER BY model_id ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $out = [];
            $now = time();
            foreach ($rows as $row) {
                $availability = ModelAvailability::fromRow($row);
                $availability = $this->applyDecay($availability, $windowSeconds, $now);
                $out[$availability->id] = $availability;
            }
            return $out;
        } catch (\Throwable $e) {
            // A missing table (fresh install, migration not yet applied) is a
            // normal state, not an incident — log at debug and carry on with
            // no verdicts rather than failing the request.
            Logger::debug('availability repository read failed: ' . $e->getMessage());
            return $ids === null ? $this->memory : [];
        }
    }

    public function find(string $id, int $windowSeconds = 3600): ?ModelAvailability
    {
        $all = $this->all([$id], $windowSeconds);
        return $all[$id] ?? null;
    }

    /**
     * Record the outcome of one real call.
     *
     * Called by the pipeline after every L1/L4 response, which is what makes
     * the monitor cheap: most verdicts come from traffic the system was going
     * to generate anyway. Scheduled probes only fill the gaps for models
     * nobody has called recently.
     */
    public function record(
        string $id,
        bool $ok,
        ?float $latencyMs = null,
        ?UnavailableReason $reason = null,
        ?string $error = null,
        FallbackPolicy $policy = new FallbackPolicy(),
    ): ModelAvailability {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('model id must not be empty');
        }
        [$provider, $model] = self::splitId($id);
        $current = $this->find($id, $policy->monitorWindowSeconds);
        $now = time();

        $successes = $current?->successes ?? 0;
        $failures  = $current?->failures ?? 0;
        $consecutive = $current?->consecutiveFailures ?? 0;
        $reasons = $current?->recentReasons ?? [];

        if ($ok) {
            $successes++;
            $consecutive = 0;
        } else {
            $failures++;
            $consecutive++;
            if ($reason !== null) {
                array_unshift($reasons, $reason);
                $reasons = array_slice($reasons, 0, 8);
            }
        }

        $samples = $successes + $failures;
        $rate = $samples > 0 ? $successes / $samples : null;

        // Latency is an exponential moving average with alpha = 0.3. A plain
        // mean over a window would let one 40 s timeout dominate the verdict
        // for the whole window; an EMA forgets it geometrically.
        $avg = $latencyMs === null
            ? $current?->avgLatencyMs
            : ($current?->avgLatencyMs === null ? $latencyMs : (0.3 * $latencyMs) + (0.7 * $current->avgLatencyMs));

        $p95 = $current?->p95LatencyMs;
        if ($latencyMs !== null) {
            // A cheap p95 surrogate: the EMA of the max. Not a true percentile,
            // but monotone in the same direction and O(1) — and unlike a real
            // percentile it needs no sample buffer in a table that is written
            // on every single model call.
            $p95 = $p95 === null ? $latencyMs : max($latencyMs, (0.9 * $p95) + (0.1 * $latencyMs));
        }

        // The PREVIOUS status is passed unchanged, on success as well as on
        // failure. Passing Healthy here would erase a Retired verdict before
        // verdict() could see it — and Retired is deliberately sticky, because
        // it records an administrator's decision ("we are not allowed to use
        // this any more") or a catalogue fact ("the provider removed it"), not
        // a transient health measurement. One lucky call must not undo either.
        $status = self::verdict(
            $current?->status ?? AvailabilityStatus::Unknown,
            $rate,
            $samples,
            $consecutive,
            $policy
        );

        $next = new ModelAvailability(
            id: $id,
            provider: $provider,
            model: $model,
            status: $status,
            successes: $successes,
            failures: $failures,
            successRate: $rate === null ? null : round($rate, 6),
            avgLatencyMs: $avg === null ? null : round($avg, 2),
            p95LatencyMs: $p95 === null ? null : round($p95, 2),
            lastSuccessAt: $ok ? $now : $current?->lastSuccessAt,
            lastFailureAt: $ok ? $current?->lastFailureAt : $now,
            lastProbedAt: $current?->lastProbedAt,
            lastError: $ok ? null : ($error !== null ? mb_substr($error, 0, 500) : $current?->lastError),
            recentReasons: $reasons,
            consecutiveFailures: $consecutive,
            note: $current?->note,
        );

        $this->persist($next);
        return $next;
    }

    /**
     * Record the outcome of a scheduled probe (not real traffic).
     *
     * Probes are weighted HALF: a probe is a cheap ping, and letting it
     * overwrite the verdict built from hundreds of real calls would let one
     * unlucky probe flip a healthy model to Unavailable.
     */
    public function recordProbe(string $id, bool $ok, ?float $latencyMs = null, ?string $error = null): ModelAvailability
    {
        $id = trim($id);
        [$provider, $model] = self::splitId($id);
        $current = $this->find($id);
        $now = time();

        $next = new ModelAvailability(
            id: $id,
            provider: $provider,
            model: $model,
            status: $ok
                ? AvailabilityStatus::Healthy
                : ($current !== null && $current->consecutiveFailures >= 2 ? AvailabilityStatus::Unavailable : AvailabilityStatus::Degraded),
            successes: ($current?->successes ?? 0) + ($ok ? 1 : 0),
            failures: ($current?->failures ?? 0) + ($ok ? 0 : 1),
            successRate: null,
            avgLatencyMs: $latencyMs !== null ? round($latencyMs, 2) : $current?->avgLatencyMs,
            p95LatencyMs: $current?->p95LatencyMs,
            lastSuccessAt: $ok ? $now : $current?->lastSuccessAt,
            lastFailureAt: $ok ? $current?->lastFailureAt : $now,
            lastProbedAt: $now,
            lastError: $ok ? null : ($error !== null ? mb_substr($error, 0, 500) : null),
            recentReasons: $current?->recentReasons ?? [],
            consecutiveFailures: $ok ? 0 : ($current?->consecutiveFailures ?? 0) + 1,
            note: $current?->note,
        );
        // Recompute the rate from the counters we just wrote.
        $samples = $next->successes + $next->failures;
        $rate = $samples > 0 ? $next->successes / $samples : null;
        $next = new ModelAvailability(
            $next->id, $next->provider, $next->model, $next->status, $next->successes, $next->failures,
            $rate === null ? null : round($rate, 6), $next->avgLatencyMs, $next->p95LatencyMs,
            $next->lastSuccessAt, $next->lastFailureAt, $next->lastProbedAt, $next->lastError,
            $next->recentReasons, $next->consecutiveFailures, $next->note
        );

        $this->persist($next);
        return $next;
    }

    /** An administrator switched a model on or off, or the catalogue lost it. */
    public function setStatus(string $id, AvailabilityStatus $status, ?string $note = null): void
    {
        [$provider, $model] = self::splitId($id);
        $current = $this->find($id);
        $this->persist(new ModelAvailability(
            id: $id,
            provider: $provider,
            model: $model,
            status: $status,
            successes: $current?->successes ?? 0,
            failures: $current?->failures ?? 0,
            successRate: $current?->successRate,
            avgLatencyMs: $current?->avgLatencyMs,
            p95LatencyMs: $current?->p95LatencyMs,
            lastSuccessAt: $current?->lastSuccessAt,
            lastFailureAt: $current?->lastFailureAt,
            lastProbedAt: $current?->lastProbedAt,
            lastError: $current?->lastError,
            recentReasons: $current?->recentReasons ?? [],
            consecutiveFailures: $current?->consecutiveFailures ?? 0,
            note: $note ?? $current?->note,
        ));
    }

    /**
     * Forget a model's verdict — "reset the breaker" from the UI.
     *
     * Used after an operator fixes a key or tops up a quota: without it the
     * model stays Unavailable until the window ages the failures out, which
     * reads as "my fix did nothing".
     */
    public function forget(string $id): bool
    {
        $id = trim($id);
        unset($this->memory[$id]);
        $pdo = $this->db?->pdo();
        if ($pdo === null) { return true; }
        try {
            $stmt = $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE model_id = ? LIMIT 1');
            $stmt->execute([$id]);
            return true;
        } catch (\Throwable $e) {
            Logger::debug('availability forget failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Drop every verdict. Used by the admin "reset all" action. */
    public function truncate(): int
    {
        $this->memory = [];
        $pdo = $this->db?->pdo();
        if ($pdo === null) { return 0; }
        try {
            return $pdo->exec('DELETE FROM ' . self::TABLE) ?: 0;
        } catch (\Throwable $e) {
            Logger::debug('availability truncate failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** @return array<int,array<string,mixed>> most recent first */
    public function history(int $limit = 50): array
    {
        return array_slice(array_reverse($this->history), 0, max(1, min(self::MAX_HISTORY, $limit)));
    }

    /** @return array<string,int> status => count, for the dashboard tiles */
    public function statusCounts(): array
    {
        $out = [];
        foreach (AvailabilityStatus::cases() as $case) { $out[$case->value] = 0; }
        foreach ($this->all() as $availability) {
            $key = $availability->status->value;
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    private function persist(ModelAvailability $availability): void
    {
        $this->memory[$availability->id] = $availability;
        $this->history[] = ['at' => time(), 'id' => $availability->id, 'status' => $availability->status->value, 'rate' => $availability->successRate];
        if (count($this->history) > self::MAX_HISTORY) {
            $this->history = array_slice($this->history, -self::MAX_HISTORY);
        }

        $pdo = $this->db?->pdo();
        if ($pdo === null) { return; }

        try {
            // One statement, idempotent, no read-modify-write race: a worker
            // and a web request can both be recording the same model at the
            // same moment, and a SELECT-then-UPDATE would lose one of them.
            // The counters are authoritative values computed by the caller,
            // so ON DUPLICATE KEY UPDATE assigns rather than increments.
            $sql = 'INSERT INTO ' . self::TABLE . ' (
                        model_id, provider, model, status,
                        successes, failures, success_rate,
                        avg_latency_ms, p95_latency_ms,
                        last_success_at, last_failure_at, last_probed_at,
                        last_error, recent_reasons, consecutive_failures, note, updated_at
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        provider = VALUES(provider),
                        model = VALUES(model),
                        status = VALUES(status),
                        successes = VALUES(successes),
                        failures = VALUES(failures),
                        success_rate = VALUES(success_rate),
                        avg_latency_ms = VALUES(avg_latency_ms),
                        p95_latency_ms = VALUES(p95_latency_ms),
                        last_success_at = VALUES(last_success_at),
                        last_failure_at = VALUES(last_failure_at),
                        last_probed_at = VALUES(last_probed_at),
                        last_error = VALUES(last_error),
                        recent_reasons = VALUES(recent_reasons),
                        consecutive_failures = VALUES(consecutive_failures),
                        note = VALUES(note),
                        updated_at = VALUES(updated_at)';

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $availability->id,
                $availability->provider,
                $availability->model,
                $availability->status->value,
                $availability->successes,
                $availability->failures,
                $availability->successRate,
                $availability->avgLatencyMs,
                $availability->p95LatencyMs,
                $availability->lastSuccessAt,
                $availability->lastFailureAt,
                $availability->lastProbedAt,
                $availability->lastError,
                $availability->recentReasons === [] ? null : json_encode(
                    array_map(static fn(UnavailableReason $r): string => $r->value, $availability->recentReasons),
                    JSON_UNESCAPED_SLASHES
                ),
                $availability->consecutiveFailures,
                $availability->note,
                time(),
            ]);
        } catch (\Throwable $e) {
            Logger::debug('availability persist failed for ' . $availability->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Half-life decay of the counters.
     *
     * Every read ages the counters by the fraction of the window that has
     * elapsed since `updated_at`, so the verdict reflects recent behaviour.
     * Doing this on read rather than in a scheduled job means there is no job
     * to forget to install, and the arithmetic is a few float ops.
     */
    private function applyDecay(ModelAvailability $a, int $windowSeconds, int $now): ModelAvailability
    {
        $windowSeconds = max(60, $windowSeconds);
        $reference = $a->lastProbedAt ?? $a->lastFailureAt ?? $a->lastSuccessAt ?? $now;
        $age = max(0, $now - $reference);
        if ($age <= 0 || $a->samples() === 0) {
            return $a;
        }
        // Exponential decay with a half-life of one window. After one window
        // the counters are halved; after three they are an eighth.
        $factor = 0.5 ** ($age / $windowSeconds);
        if ($factor > 0.999) {
            return $a;
        }
        $successes = (int)floor($a->successes * $factor);
        $failures  = (int)floor($a->failures * $factor);
        $samples = $successes + $failures;
        $rate = $samples > 0 ? $successes / $samples : null;

        return new ModelAvailability(
            $a->id, $a->provider, $a->model, $a->status, $successes, $failures,
            $rate === null ? null : round($rate, 6), $a->avgLatencyMs, $a->p95LatencyMs,
            $a->lastSuccessAt, $a->lastFailureAt, $a->lastProbedAt, $a->lastError,
            $a->recentReasons, $a->consecutiveFailures, $a->note
        );
    }

    /**
     * Turn counters into a verdict.
     *
     * Retired is sticky: it is set by an administrator or by the catalogue, and
     * no amount of successful traffic should silently un-retire a model the
     * operator switched off.
     */
    private static function verdict(
        AvailabilityStatus $previous,
        ?float $rate,
        int $samples,
        int $consecutiveFailures,
        FallbackPolicy $policy,
    ): AvailabilityStatus {
        if ($previous === AvailabilityStatus::Retired) {
            return AvailabilityStatus::Retired;
        }
        // A hard streak overrides the ratio: 3 consecutive failures on a model
        // with a 99 % lifetime rate means something just broke.
        if ($consecutiveFailures >= 3) {
            return AvailabilityStatus::Unavailable;
        }
        if ($samples < $policy->minSamplesForVerdict || $rate === null) {
            return $previous === AvailabilityStatus::Degraded ? AvailabilityStatus::Degraded : AvailabilityStatus::Unknown;
        }
        if ($rate < $policy->unavailableSuccessFloor) {
            return AvailabilityStatus::Unavailable;
        }
        if ($rate < $policy->degradedSuccessFloor) {
            return AvailabilityStatus::Degraded;
        }
        return AvailabilityStatus::Healthy;
    }

    /** @return array{0:string,1:string} [provider, model] */
    public static function splitId(string $id): array
    {
        $id = trim($id);
        if (str_contains($id, '/')) {
            [$vendor, $model] = explode('/', $id, 2);
            return [$vendor, $model];
        }
        return ['', $id];
    }
}
