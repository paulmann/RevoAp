<?php
/**
 * UserQuotaEnforcer: per-user spend limits, checked where runs are created.
 *
 * This is the enforcement half of the budgets schema shipped in migration
 * 023: `revoap_budgets` rows with scope='user' answer "may this user start
 * another run right now?". The check runs on the launch paths —
 * ApiV1Controller::createRun() (the task's named enforcement point, which
 * covers /v1/runs, /v1/chat/completions and /v1/messages because they are one
 * code path), JobService::createJob() (web + async) and CouncilController
 * (web sync/SSE).
 *
 * Where the money comes from
 * --------------------------
 * Live from the usage ledger (App\Usage\UsageLedger::totals over the current
 * period window), NOT from the budget row's `spent_amount` rollup. The schema
 * comment is explicit that the rollup exists for fast checks and may be
 * seconds stale; the ledger is indexed by (user_id, run_created_at), so the
 * live sum already IS the fast check, and the rollup is refreshed as a side
 * effect for the (pending) Budgets panel. One number, one source.
 *
 * What a window is
 * ----------------
 * Calendar periods in UTC — daily resets at midnight, weekly on Monday,
 * monthly on the 1st, yearly on January 1st. Rolling windows were considered
 * and rejected for budgets: "Resets {date}" must be a date an operator can
 * put in a calendar, and a spend limit that slides with the clock makes "am
 * I allowed to run the nightly batch" unanswerable without arithmetic.
 *
 * Failure direction
 * -----------------
 * Every infrastructure failure (no DB, unmigrated tables, ledger gap, broken
 * flag registry) answers `ok, checked=false`. A quota check that fails CLOSED
 * would turn a missing migration into an outage of the entire run surface —
 * and unlike an auth check, the money question has a compensating control
 * (the alerts still fire when the tables come back). Enforcement requires
 * the operator to have switched on the `cost.budgets` feature flag; until
 * then this class is inert by design.
 *
 * 'throttle' honesty
 * ------------------
 * action_at_limit='throttle' (steer runs to the cheapest models) is declared
 * in the schema and the catalogue but NOT implemented — model selection
 * happens in the pipeline, and rewriting it from a budget check is a larger
 * feature than T5. Until it exists, throttle is downgraded to warn WITH an
 * explicit `throttle_not_enforced` warning in the verdict and on the alert,
 * because silently treating it as 'warn' would let an operator believe runs
 * are being steered when they are not.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

namespace App\Admin\Budgets;

use App\Admin\Audit\AuditLog;
use App\Admin\FeatureFlags\FeatureFlagRegistry;
use App\Core\Logger;
use App\Usage\UsageLedger;

final class UserQuotaEnforcer implements UserQuotaInterface
{
    public function __construct(
        private readonly UsageLedger $ledger,
        private readonly BudgetRepository $budgets,
        private readonly ?FeatureFlagRegistry $flags = null,
        private readonly ?AuditLog $audit = null,
    ) {
    }

    /**
     * @return array{action:'ok'|'warn'|'block',checked:bool,
     *               budgets:array<int,array<string,mixed>>,
     *               blocking:array<string,mixed>|null,retry_after:int|null,
     *               cost_coverage:float|null,warnings:array<int,string>}
     */
    public function checkForRun(?int $userId): array
    {
        /** @var array{action:'ok'|'warn'|'block',checked:bool,budgets:list<array<string,mixed>>,blocking:array<string,mixed>|null,retry_after:int|null,cost_coverage:float|null,warnings:list<string>} $verdict */
        $verdict = [
            'action'        => 'ok',
            'checked'       => false,
            'budgets'       => [],
            'blocking'      => null,
            'retry_after'   => null,
            'cost_coverage' => null,
            'warnings'      => [],
        ];

        // No end-user identity → nothing per-user to enforce. Operator runs
        // without a user_id are governed by the (pending) global budgets
        // surface, not by this class.
        if ($userId === null || $userId <= 0) {
            return $verdict;
        }
        if (!$this->enforcementEnabled() || !$this->budgets->tablesAvailable()) {
            return $verdict;
        }
        $rows = $this->budgets->listForUser($userId, true);
        if ($rows === []) {
            return $verdict;
        }

        $verdict['checked'] = true;
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $worst  = 0;                       // 0 ok · 1 warn · 2 block
        $coverageWorst = null;

        foreach ($rows as $b) {
            $budgetId = (int)($b['id'] ?? 0);
            $period   = (string)($b['period'] ?? 'monthly');
            [$start, $end] = self::window($period, $now);
            $from = $start->format('Y-m-d H:i:s');
            $to   = $end->format('Y-m-d H:i:s');

            $totals   = $this->ledger->totals($userId, $from, $to);
            $spent    = $totals['cost'] ?? null;
            $spentF   = $spent === null ? 0.0 : (float)$spent;
            $coverage = $totals['calls'] > 0 ? ($totals['cost_rows'] / $totals['calls']) : null;
            if ($coverage !== null) {
                $coverageWorst = $coverageWorst === null ? $coverage : min($coverageWorst, $coverage);
            }

            $amount = (float)($b['amount'] ?? 0);
            $pct = $amount > 0.0
                ? ($spentF / $amount) * 100.0
                : ($spentF > 0.0 ? 100.0 : 0.0);

            $periodKey = BudgetRepository::periodKey($period, $start);

            // Threshold alerts — once per (budget, period, threshold), which
            // is what makes a paged operator trust the channel.
            foreach (self::thresholdList((string)($b['thresholds'] ?? '')) as $th) {
                if ($pct >= (float)$th) {
                    $this->budgets->fireAlert($budgetId, 'threshold', $periodKey, $th, $spentF, $amount);
                }
            }
            $atLimit = $pct >= 100.0;
            if ($atLimit) {
                $this->budgets->fireAlert($budgetId, 'limit', $periodKey, 100, $spentF, $amount);
            }

            // Rollup refresh (best-effort; the live number above is the truth).
            $this->budgets->touchSpent($budgetId, $spentF, $start->getTimestamp(), $end->getTimestamp());

            $action = (string)($b['action_at_limit'] ?? 'warn');
            $effective = $action;
            $entry = [
                'budget_id'       => $budgetId,
                'name'            => (string)($b['name'] ?? ''),
                'currency'        => (string)($b['currency'] ?? 'USD'),
                'period'          => $period,
                'period_key'      => $periodKey,
                'amount'          => $amount,
                'spent'           => round($spentF, 6),
                'percent'         => round($pct, 2),
                'action_at_limit' => $action,
                'effective_action' => $action,
                'at_limit'        => $atLimit,
                'cost_coverage'   => $coverage === null ? null : round($coverage, 4),
                'window'          => ['from' => $from, 'to' => $to],
                'resets_at'       => $end->getTimestamp(),
            ];

            if ($atLimit) {
                if ($action === 'block') {
                    $worst = 2;
                    $verdict['blocking'] = $entry;
                    $retry = $end->getTimestamp() - $now->getTimestamp();
                    $verdict['retry_after'] = $retry > 0 ? $retry : 60;
                } elseif ($action === 'throttle') {
                    // Downgraded, loudly — see class doc.
                    $effective = 'warn';
                    $entry['effective_action'] = 'warn';
                    $entry['throttle_not_enforced'] = true;
                    if (!in_array('throttle_not_enforced', $verdict['warnings'], true)) {
                        $verdict['warnings'][] = 'throttle_not_enforced';
                    }
                    $worst = max($worst, 1);
                } else {
                    $worst = max($worst, 1);
                }
            }
            $verdict['budgets'][] = $entry;
        }

        $verdict['cost_coverage'] = $coverageWorst === null ? null : round($coverageWorst, 4);
        if ($coverageWorst !== null && $coverageWorst < 1.0
            && !in_array('cost_unreported', $verdict['warnings'], true)) {
            // Under-counting can only UNDER-block; saying so is what keeps a
            // blocked-then-surprised operator from concluding the ledger is
            // broken when the provider simply never prices its calls.
            $verdict['warnings'][] = 'cost_unreported';
        }
        $verdict['action'] = $worst === 2 ? 'block' : ($worst === 1 ? 'warn' : 'ok');

        if ($verdict['action'] === 'block') {
            $this->auditBlock($userId, $verdict);
        }
        return $verdict;
    }

    /**
     * Calendar window [start, end) for a period, anchored at $now (UTC).
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    public static function window(string $period, \DateTimeImmutable $now): array
    {
        $midnight = $now->setTime(0, 0, 0);
        $start = match ($period) {
            'daily'   => $midnight,
            'weekly'  => $midnight->modify('monday this week'),
            'monthly' => $midnight->setDate((int)$midnight->format('Y'), (int)$midnight->format('n'), 1),
            'yearly'  => $midnight->setDate((int)$midnight->format('Y'), 1, 1),
            default   => $midnight,
        };
        // PHP's 'this week' follows ISO-8601 (weeks start Monday), so on a
        // Sunday 'monday this week' correctly yields the Monday SIX days ago.
        // The guard below is belt-and-braces: should a future PHP or a
        // locale-driven modifier ever roll the Monday forward, a budget that
        // resets a day late every Sunday is the kind of bug that only fires
        // in production, on the quietest day of the week.
        if ($period === 'weekly' && $start > $midnight) {
            $start = $midnight->modify('-7 days')->modify('monday this week');
        }
        $end = match ($period) {
            'daily'   => $start->modify('+1 day'),
            'weekly'  => $start->modify('+7 days'),
            'monthly' => $start->modify('+1 month'),
            'yearly'  => $start->modify('+1 year'),
            default   => $start->modify('+1 day'),
        };
        return [$start, $end];
    }

    /** Is the operator opt-in flag on? No registry wired → not enforced. */
    private function enforcementEnabled(): bool
    {
        if ($this->flags === null) {
            return false;
        }
        try {
            // isAdmin=true is deliberate: cost.budgets ships with rollout
            // 'admins', which for a CONTROL-PLANE switch means "the operator
            // has opted in" — the enforcement itself then applies to every
            // caller. Reading it with the caller's own admin state would mean
            // an API client is exempt from the very budget its operator set.
            return $this->flags->enabled('cost.budgets', null, true);
        } catch (\Throwable $e) {
            Logger::debug('budgets.flag_read_failed: ' . Logger::sanitizeScalar($e->getMessage()));
            return false;
        }
    }

    /** @return array<int,int> */
    private static function thresholdList(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p)) {
                $n = (int)$p;
                if ($n >= 1 && $n <= 99) {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }

    /**
     * A blocked run is a DENIED action and belongs in the audit trail — with
     * the real identity in the label, which is exactly the attribution the
     * audit layer was waiting for (AuditLog::actorId() picks up an RBAC
     * session automatically once one exists; until then the label carries
     * the end-user id).
     *
     * @param array<string,mixed> $verdict
     */
    private function auditBlock(int $userId, array $verdict): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            $blocking = is_array($verdict['blocking'] ?? null) ? $verdict['blocking'] : [];
            $this->audit->record(
                'budget.run_blocked',
                [],
                [
                    'budget_id' => $blocking['budget_id'] ?? null,
                    'period'    => $blocking['period'] ?? null,
                    'spent'     => $blocking['spent'] ?? null,
                    'amount'    => $blocking['amount'] ?? null,
                ],
                AuditLog::RESULT_DENIED,
                'user',
                (string)$userId,
                'Run refused: user budget exhausted (' . (string)($blocking['name'] ?? '') . ')',
                'user:' . $userId,
            );
        } catch (\Throwable $e) {
            // The refusal already happened; an audit outage must not
            // additionally fail the response that reports it.
            Logger::debug('budgets.audit_failed: ' . Logger::sanitizeScalar($e->getMessage()));
        }
    }
}
