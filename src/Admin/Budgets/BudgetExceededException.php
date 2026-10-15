<?php
/**
 * BudgetExceededException: a run was refused because a user budget is spent.
 *
 * Thrown by the enqueue path (JobService::createJob) where the only way to
 * refuse is to throw, and caught by the HTTP layer, which renders it as a 429
 * with the verdict attached. Carrying the verdict on the exception is what
 * lets every catcher render the SAME machine-readable detail (which budget,
 * how much, when it resets) instead of a bare "no".
 *
 * Deliberately NOT an InvalidArgumentException: a budget refusal is not a
 * malformed request, and the launch paths already map InvalidArgumentException
 * to 400. A quota breach that arrived as a 400 would tell an SDK client to
 * fix its payload and retry the same spend forever.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

namespace App\Admin\Budgets;

final class BudgetExceededException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $verdict the enforcer verdict that blocked
     * @param int|null            $userId  the identity the budget belongs to
     */
    public function __construct(
        public readonly array $verdict,
        public readonly ?int $userId = null,
    ) {
        $blocking = $verdict['blocking'] ?? null;
        $name = is_array($blocking) ? (string)($blocking['name'] ?? 'budget') : 'budget';
        parent::__construct('Run refused: ' . $name . ' is exhausted for this user.');
    }

    /** HTTP status the renderers should use. 429, not 402: the request is
     *  well-formed and MAY succeed once the period rolls over — which is
     *  exactly what 429 + Retry-After says, and what an SDK backoff loop
     *  already understands. */
    public function status(): int
    {
        return 429;
    }

    /** Retry-After seconds when the verdict knows the period end. */
    public function retryAfter(): ?int
    {
        $raw = $this->verdict['retry_after'] ?? null;
        return is_int($raw) && $raw > 0 ? $raw : null;
    }
}
