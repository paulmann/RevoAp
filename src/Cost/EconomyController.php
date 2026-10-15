<?php
declare(strict_types=1);

namespace App\Cost;

use App\Core\Database;
use App\Http\Response;

/**
 * R10 - the read-only endpoint behind the economy advice.
 *
 * Thin on purpose: it validates the request, reads the journal through
 * `CostHistory` and hands everything to `EconomyAdvisor`. Any arithmetic or
 * wording decision belongs in those two, where it is covered by
 * `tests/EconomyPolicyTest.php` - not here.
 *
 * The response carries no secrets: model identifiers, aggregate cost and
 * latency ranges, and the cache verdict. That is the same class of information
 * History and Statistics already expose to the same (authenticated) operator.
 */
final class EconomyController
{
    public function __construct(
        private readonly ?Database $db = null,
        private readonly ?int $defaultHistoryDays = 30,
    ) {
    }

    /** POST /api/council/economy/advice */
    public function advice(?array $body = null): string
    {
        $body ??= $this->readJsonBody();
        if ($body === null) {
            return Response::json(['ok' => false, 'error' => 'invalid_body'], 400);
        }

        $pool = [];
        foreach (($body['selected_models'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $model = trim((string)($row['model'] ?? ''));
            if ($model === '') { continue; }
            $pool[] = ['provider' => (string)($row['provider'] ?? ''), 'model' => $model];
        }

        $runMode = strtolower(trim((string)($body['run_mode'] ?? 'sync')));
        if (!in_array($runMode, ['sync', 'batch'], true)) {
            return Response::json(['ok' => false, 'error' => 'invalid_run_mode'], 400);
        }

        $days = $body['history_days'] ?? $this->defaultHistoryDays;
        $days = is_numeric($days) ? (int)$days : (int)$this->defaultHistoryDays;

        $observations = (new CostHistory($this->db))->observations($days);

        $advice = EconomyAdvisor::advise([
            'pool'          => $pool,
            'run_mode'      => $runMode,
            'batch_capable' => isset($body['batch_capable']) ? (bool)$body['batch_capable'] : null,
            'batch_ready'   => (bool)($body['batch_ready'] ?? false),
            'interactive'   => (bool)($body['interactive'] ?? true),
            'cycle_limit'   => is_numeric($body['cycle_limit'] ?? null) ? (int)$body['cycle_limit'] : 1,
        ], $observations);

        return Response::json(['ok' => true, 'history_days' => $days] + $advice);
    }

    /** @return array<string,mixed>|null */
    private function readJsonBody(): ?array
    {
        $raw = (string)file_get_contents('php://input');
        if (trim($raw) === '') {
            // An empty body is a valid question: the operator asked for advice
            // with no run described yet, and the answer is the mode itself.
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
