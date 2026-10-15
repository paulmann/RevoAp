<?php
/**
 * UserQuotaInterface: the one question a run-creation path asks about money.
 *
 * Exists so ApiV1Controller / JobService / CouncilController type-hint a
 * contract instead of a concrete enforcer: the launch paths must keep working
 * with no budget subsystem at all (null), with the real enforcer, and with a
 * stub in tests that needs to answer "block" without a database. Three call
 * sites inventing three optional-constructor shapes is how an enforcement
 * point gets skipped in one of them.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

namespace App\Admin\Budgets;

interface UserQuotaInterface
{
    /**
     * May a new run be started for this user?
     *
     * Implementations MUST NOT throw for ordinary conditions (no database,
     * unmigrated tables, no budgets configured) — those answer
     * `['action' => 'ok', 'checked' => false]`. A quota check that fails
     * closed on infrastructure trouble turns a missing migration into an
     * outage of the whole run surface.
     *
     * @param int|null $userId  null = the caller presented no end-user
     *                          identity; per-user budgets cannot apply.
     * @return array{
     *   action: 'ok'|'warn'|'block',
     *   checked: bool,
     *   budgets: array<int,array<string,mixed>>,
     *   blocking: array<string,mixed>|null,
     *   retry_after: int|null,
     *   cost_coverage: float|null,
     *   warnings: array<int,string>
     * }
     */
    public function checkForRun(?int $userId): array;
}
