<?php
/**
 * BudgetRepository: reads and writes for `revoap_budgets` /
 * `revoap_budget_alerts` (schema shipped in migration 023, HTTP surface
 * wired here for the per-user scope in v2.1.0).
 *
 * Scope of this class in T5: the `scope='user'` rows the quota enforcer
 * checks and the admin Users panel edits. The generic Budgets panel
 * (all scopes, forecasts, notification wiring) remains a pending surface —
 * this repository is deliberately shaped so that panel can grow on top of
 * it rather than around it: every method takes/returns plain arrays, never
 * a user-specific type.
 *
 * Two honesty rules carried over from the schema comments:
 *
 *  1. `spent_amount` on the budget row is a ROLLUP for fast limit checks,
 *     never the money source of truth. The enforcer recomputes live spend
 *     from the usage ledger and refreshes the rollup as a side effect; the
 *     cost report still reads source rows.
 *  2. Alerts fire ONCE per (budget, period, threshold). Re-firing a 95 %
 *     alert on every run after the threshold would train the operator to
 *     ignore the channel that is supposed to page them.
 *
 * Every method tolerates a missing table (migration 023 not applied) with
 * an empty/false answer and a debug-level log — an unmigrated install keeps
 * running, it just has no budgets. Nothing here throws.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

namespace App\Admin\Budgets;

use App\Core\Database;
use App\Core\Logger;
use PDO;

final class BudgetRepository
{
    public const TABLE        = 'revoap_budgets';
    public const ALERTS_TABLE = 'revoap_budget_alerts';

    public const SCOPE_USER = 'user';

    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    /** What happens when spend reaches the limit. 'throttle' (steer runs to
     *  the cheapest models) is declared in the schema and the catalogue but
     *  NOT yet enforced anywhere — see UserQuotaEnforcer, which downgrades
     *  it to 'warn' and says so in the verdict rather than silently
     *  pretending to throttle. */
    public const ACTIONS = ['warn', 'throttle', 'block'];

    private readonly ?Database $db;

    /** @var bool|null memoised "both tables exist" probe */
    private ?bool $ready = null;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function isPersistent(): bool
    {
        return $this->db !== null;
    }

    /** Are the migration-023 budget tables present? (memoised) */
    public function tablesAvailable(): bool
    {
        if ($this->db === null) {
            return false;
        }
        if ($this->ready !== null) {
            return $this->ready;
        }
        try {
            $this->ready = (bool)$this->db->run(fn(PDO $pdo): bool =>
                $this->tableExists($pdo, self::TABLE) && $this->tableExists($pdo, self::ALERTS_TABLE));
        } catch (\Throwable) {
            $this->ready = false;
        }
        return $this->ready;
    }

    /* ------------------------------------------------------------------
     * Reads
     * ---------------------------------------------------------------- */

    /**
     * Budgets bound to one end user.
     *
     * @param bool $enabledOnly true for the enforcement path; the panel
     *                          passes false so a disabled budget stays
     *                          visible and re-enableable.
     * @return array<int,array<string,mixed>>
     */
    public function listForUser(int $userId, bool $enabledOnly = true): array
    {
        if ($userId <= 0 || !$this->tablesAvailable()) {
            return [];
        }
        try {
            return $this->db->run(function (PDO $pdo) use ($userId, $enabledOnly): array {
                $sql = 'SELECT * FROM ' . self::TABLE . "
                        WHERE scope = 'user' AND scope_value = :uid"
                     . ($enabledOnly ? ' AND enabled = 1' : '') . '
                        ORDER BY id ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['uid' => (string)$userId]);
                $rows = $stmt->fetchAll();
                return is_array($rows) ? array_map([self::class, 'shape'], $rows) : [];
            });
        } catch (\Throwable $e) {
            Logger::debug('budgets.list_failed: ' . Logger::sanitizeScalar($e->getMessage()));
            return [];
        }
    }

    /**
     * Alerts for one budget (or all user-scope budgets when null), newest
     * first. Read side for the panel; the enforcer uses fireAlert().
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentAlerts(?int $budgetId = null, int $limit = 50): array
    {
        if (!$this->tablesAvailable()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        try {
            return $this->db->run(function (PDO $pdo) use ($budgetId, $limit): array {
                $where = $budgetId === null ? '' : 'WHERE budget_id = :bid';
                $stmt = $pdo->prepare(
                    'SELECT * FROM ' . self::ALERTS_TABLE . ' ' . $where . '
                     ORDER BY id DESC LIMIT ' . $limit
                );
                $stmt->execute($budgetId === null ? [] : ['bid' => $budgetId]);
                $rows = $stmt->fetchAll();
                return is_array($rows) ? $rows : [];
            });
        } catch (\Throwable $e) {
            Logger::debug('budgets.alerts_failed: ' . Logger::sanitizeScalar($e->getMessage()));
            return [];
        }
    }

    /* ------------------------------------------------------------------
     * Writes
     * ---------------------------------------------------------------- */

    /**
     * Create or update one user-scope budget.
     *
     * Validation is total and the error strings are stable keys, because the
     * panel renders them through the catalogue: an amount must be a positive
     * number (0 means "block everything", which an operator should configure
     * deliberately as 0.0001, not by accident), the period and the action must
     * be in the closed sets, thresholds must be a comma list of 1..99, and
     * the currency must be three letters — a money column whose unit is a
     * typo is not a money column.
     *
     * @param array<string,mixed> $input {id?, name?, amount, currency?, period?,
     *                                   action_at_limit?, thresholds?, enabled?}
     * @return array{ok:bool,budget_id?:int,error?:string}
     */
    public function upsertUserBudget(int $userId, array $input): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'invalid_user'];
        }
        if (!$this->tablesAvailable()) {
            return ['ok' => false, 'error' => 'table_missing'];
        }
        $id = isset($input['id']) && is_numeric($input['id']) ? (int)$input['id'] : 0;

        $amount = $input['amount'] ?? null;
        if (!is_numeric($amount) || (float)$amount < 0 || (float)$amount > 9_999_999) {
            return ['ok' => false, 'error' => 'invalid_amount'];
        }
        $period = strtolower(trim((string)($input['period'] ?? 'monthly')));
        if (!in_array($period, self::PERIODS, true)) {
            return ['ok' => false, 'error' => 'invalid_period'];
        }
        $action = strtolower(trim((string)($input['action_at_limit'] ?? 'warn')));
        if (!in_array($action, self::ACTIONS, true)) {
            return ['ok' => false, 'error' => 'invalid_action'];
        }
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return ['ok' => false, 'error' => 'invalid_currency'];
        }
        $thresholds = self::normalizeThresholds($input['thresholds'] ?? '50,80,95');
        if ($thresholds === null) {
            return ['ok' => false, 'error' => 'invalid_thresholds'];
        }
        $enabled = !array_key_exists('enabled', $input) || self::truthy($input['enabled']);
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            $name = 'User ' . $userId . ' · ' . $period;
        }
        $name = mb_substr($name, 0, 120);

        try {
            return (array)$this->db->run(function (PDO $pdo) use (
                $id, $userId, $name, $amount, $currency, $period, $action, $thresholds, $enabled
            ): array {
                $now = time();
                if ($id > 0) {
                    // Only a USER-scope row may be updated through this method:
                    // an id pointing at a global budget would otherwise let a
                    // per-user edit silently rewrite the installation-wide limit.
                    $guard = $pdo->prepare(
                        "SELECT id FROM " . self::TABLE . "
                         WHERE id = :id AND scope = 'user' AND scope_value = :uid LIMIT 1"
                    );
                    $guard->execute(['id' => $id, 'uid' => (string)$userId]);
                    if ($guard->fetch() === false) {
                        return ['ok' => false, 'error' => 'not_found'];
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE ' . self::TABLE . '
                         SET name = :name, amount = :amount, currency = :cur,
                             period = :period, action_at_limit = :action,
                             thresholds = :thresholds, enabled = :enabled, updated_at = :now
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'id' => $id, 'name' => $name, 'amount' => (float)$amount,
                        'cur' => $currency, 'period' => $period, 'action' => $action,
                        'thresholds' => $thresholds, 'enabled' => $enabled ? 1 : 0, 'now' => $now,
                    ]);
                    return ['ok' => true, 'budget_id' => $id];
                }
                // :created_at and :updated_at are SEPARATE parameters even
                // though they carry the same instant: the deployment runs
                // with ATTR_EMULATE_PREPARES = false, and MySQL's native
                // prepares refuse a reused named parameter (SQLSTATE HY093).
                // SQLite tolerates the reuse, which is exactly why the
                // offline suite could not catch this — the live beta pass
                // on MariaDB did (T6, finding #5).
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . self::TABLE . '
                        (name, scope, scope_value, currency, amount, period,
                         action_at_limit, thresholds, spent_amount, period_start,
                         period_end, enabled, created_at, updated_at)
                     VALUES
                        (:name, \'user\', :uid, :cur, :amount, :period,
                         :action, :thresholds, 0, 0, 0, :enabled, :created_at, :updated_at)'
                );
                $stmt->execute([
                    'name' => $name, 'uid' => (string)$userId, 'cur' => $currency,
                    'amount' => (float)$amount, 'period' => $period, 'action' => $action,
                    'thresholds' => $thresholds, 'enabled' => $enabled ? 1 : 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                return ['ok' => true, 'budget_id' => (int)$pdo->lastInsertId()];
            });
        } catch (\Throwable $e) {
            Logger::warn('budgets.upsert_failed', [
                'user_id' => $userId,
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return ['ok' => false, 'error' => 'write_failed'];
        }
    }

    /**
     * Refresh the denormalised rollup after a live recompute. Best-effort:
     * the rollup is a convenience for fast checks and the (pending) Budgets
     * panel; failing to write it changes nothing about enforcement, which
     * just recomputed the truth.
     */
    public function touchSpent(int $budgetId, float $spent, int $periodStart, int $periodEnd): void
    {
        if ($budgetId <= 0 || !$this->tablesAvailable()) {
            return;
        }
        try {
            $this->db->run(function (PDO $pdo) use ($budgetId, $spent, $periodStart, $periodEnd): void {
                $stmt = $pdo->prepare(
                    'UPDATE ' . self::TABLE . '
                     SET spent_amount = :spent, period_start = :ps, period_end = :pe, updated_at = :now
                     WHERE id = :id'
                );
                $stmt->execute([
                    'spent' => $spent, 'ps' => $periodStart, 'pe' => $periodEnd,
                    'now' => time(), 'id' => $budgetId,
                ]);
            });
        } catch (\Throwable $e) {
            Logger::debug('budgets.rollup_failed: ' . Logger::sanitizeScalar($e->getMessage()));
        }
    }

    /**
     * Record a threshold crossing, once per (budget, period, threshold/kind).
     *
     * @return bool true when THIS call inserted the row (first crossing),
     *              false when it already existed or the write failed.
     */
    public function fireAlert(
        int $budgetId,
        string $kind,
        string $periodKey,
        int $threshold,
        float $spent,
        float $amount,
    ): bool {
        if ($budgetId <= 0 || !$this->tablesAvailable()) {
            return false;
        }
        try {
            return (bool)$this->db->run(function (PDO $pdo) use (
                $budgetId, $kind, $periodKey, $threshold, $spent, $amount
            ): bool {
                $dup = $pdo->prepare(
                    'SELECT id FROM ' . self::ALERTS_TABLE . '
                     WHERE budget_id = :bid AND period_key = :pk AND kind = :kind AND threshold = :th
                     LIMIT 1'
                );
                $dup->execute(['bid' => $budgetId, 'pk' => $periodKey, 'kind' => $kind, 'th' => $threshold]);
                if ($dup->fetch() !== false) {
                    return false;
                }
                $ins = $pdo->prepare(
                    'INSERT INTO ' . self::ALERTS_TABLE . '
                        (budget_id, threshold, kind, fired_at, period_key, spent, amount, notified)
                     VALUES (:bid, :th, :kind, :fired, :pk, :spent, :amount, 0)'
                );
                $ins->execute([
                    'bid' => $budgetId, 'th' => $threshold, 'kind' => $kind,
                    'fired' => time(), 'pk' => $periodKey,
                    'spent' => $spent, 'amount' => $amount,
                ]);
                return true;
            });
        } catch (\Throwable $e) {
            Logger::debug('budgets.alert_failed: ' . Logger::sanitizeScalar($e->getMessage()));
            return false;
        }
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------- */

    /**
     * Normalize a thresholds value to the canonical "50,80,95" shape, or
     * null when the input is not a comma list of distinct 1..99 integers.
     */
    public static function normalizeThresholds(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $parts = array_map('strval', $raw);
        } else {
            $parts = explode(',', (string)$raw);
        }
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (!ctype_digit($p)) {
                return null;
            }
            $n = (int)$p;
            if ($n < 1 || $n > 99) {
                return null;
            }
            $out[$n] = true;
        }
        if ($out === []) {
            return null;
        }
        $keys = array_keys($out);
        sort($keys);
        return implode(',', $keys);
    }

    /**
     * A stable period key for alert dedup: '2026-10-15' (daily),
     * '2026-W42' (weekly, ISO), '2026-10' (monthly), '2026' (yearly).
     * The key must roll over exactly when the spend window does, or an
     * alert either re-fires inside one period or never fires in the next.
     */
    public static function periodKey(string $period, \DateTimeImmutable $windowStart): string
    {
        return match ($period) {
            'daily'   => $windowStart->format('Y-m-d'),
            'weekly'  => $windowStart->format('o-\WW'),
            'monthly' => $windowStart->format('Y-m'),
            'yearly'  => $windowStart->format('Y'),
            default   => $windowStart->format('Y-m-d'),
        };
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (float)$v !== 0.0;
        }
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Cast one budget row to the array shape every consumer sees. Strings
     * out of PDO become the types the JSON panel needs; `amount`/`spent`
     * stay floats with 4-decimal money semantics.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id'              => (int)($row['id'] ?? 0),
            'name'            => (string)($row['name'] ?? ''),
            'scope'           => (string)($row['scope'] ?? 'user'),
            'scope_value'     => ($row['scope_value'] ?? null) === null ? null : (string)$row['scope_value'],
            'currency'        => (string)($row['currency'] ?? 'USD'),
            'amount'          => (float)($row['amount'] ?? 0),
            'period'          => (string)($row['period'] ?? 'monthly'),
            'action_at_limit' => (string)($row['action_at_limit'] ?? 'warn'),
            'thresholds'      => (string)($row['thresholds'] ?? '50,80,95'),
            'spent_amount'    => (float)($row['spent_amount'] ?? 0),
            'period_start'    => (int)($row['period_start'] ?? 0),
            'period_end'      => (int)($row['period_end'] ?? 0),
            'enabled'         => (int)($row['enabled'] ?? 0) === 1,
            'created_at'      => (int)($row['created_at'] ?? 0),
            'updated_at'      => (int)($row['updated_at'] ?? 0),
        ];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $table = strtolower($table);
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            $driver = '';
        }
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND LOWER(name) = :t LIMIT 1");
            $stmt->execute(['t' => $table]);
            return $stmt->fetch() !== false;
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = :t LIMIT 1'
        );
        $stmt->execute(['t' => $table]);
        return $stmt->fetch() !== false;
    }
}
