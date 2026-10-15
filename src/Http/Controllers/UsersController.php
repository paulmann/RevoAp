<?php
/**
 * UsersController: the multi-user HTTP surface (v2.1.0, T5).
 *
 * Two audiences, one controller, because they read the same ledger:
 *
 *   ADMIN — "all users": GET /api/admin/users[/{id}], the per-user budget
 *   editor, the ledger resync and the data export. Every route opens with
 *   AdminGate::deny() (the docs/ADMIN.md §4 convention), every write lands
 *   in the hash-chained audit trail INCLUDING denied attempts, and every
 *   list answer is no-store (Response::json does this globally).
 *
 *   USER — "my usage": GET /api/my/usage, deliberately NOT admin-gated.
 *   An end user's own totals are not an operator surface; what protects
 *   them is identity, not a token. Scoping rules live in one comment on
 *   myUsage() because getting them wrong in either direction is a bug:
 *   too open leaks spend across users, too closed breaks the console's own
 *   "what have I spent" question on a single-operator install.
 *
 * What this controller is NOT: the operator RBAC panel. `revoap_admin_users`
 * management (creating console accounts, roles, TOTP enrolment) is the
 * separate "Users & access" surface still pending in docs/ADMIN.md §3 —
 * conflating the two tables is exactly the mistake the migration-023 schema
 * comment warns about, so this controller never touches it.
 *
 * Money honesty (same rule everywhere in v2): `cost` is null when providers
 * reported no price, and every payload that carries money also carries
 * `cost_coverage` so the panel can say "some spend is unpriced" instead of
 * showing an invented $0.00.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Admin\Audit\AuditLog;
use App\Admin\Budgets\BudgetRepository;
use App\Admin\Budgets\UserQuotaInterface;
use App\Admin\FeatureFlags\FeatureFlagRegistry;
use App\Auth\Identity;
use App\Core\Database;
use App\Core\Logger;
use App\Http\AdminGate;
use App\Http\Response;
use App\Profiles\UserProfileRepository;
use App\Statistics\StatisticsPeriod;
use App\Usage\UsageLedger;
use PDO;

final class UsersController
{
    public function __construct(
        private readonly UsageLedger $ledger,
        private readonly BudgetRepository $budgets,
        private readonly ?AuditLog $audit = null,
        private readonly null|PDO|Database $db = null,
        private readonly ?UserProfileRepository $profiles = null,
        private readonly ?FeatureFlagRegistry $flags = null,
        private readonly ?UserQuotaInterface $quotas = null,
    ) {
    }

    /* ------------------------------------------------------------------
     * ADMIN — all users
     * ---------------------------------------------------------------- */

    /**
     * GET /api/admin/users?period=&include_idle=
     *
     * Every end user with identity columns + ledger totals for the period,
     * plus the UNATTRIBUTED bucket as a first-class entry: runs whose caller
     * presented no user_id are real spend, and a total that silently dropped
     * them would not reconcile with the Statistic tab.
     */
    public function list(): string
    {
        if ($denied = AdminGate::deny('admin.users')) {
            $this->audit?->denied('users.read', 'users');
            return $denied;
        }
        $period = StatisticsPeriod::normalize(self::queryString('period'));
        $range  = StatisticsPeriod::range($period);
        $from   = $period === StatisticsPeriod::ALL ? null : $range['from'];
        $to     = $period === StatisticsPeriod::ALL ? null : $range['to'];
        $includeIdle = !in_array(self::queryString('include_idle'), ['0', 'false', 'no'], true);

        $users = $this->ledger->listUsers($from, $to, $includeIdle);
        foreach ($users as &$u) {
            // The panel's "console (local)" badge reads this; without it the
            // local identity — the one every web run belongs to — is
            // indistinguishable from an API-created user in the list.
            $u['is_local'] = (string)($u['external_id'] ?? '') === UserProfileRepository::LOCAL_EXTERNAL_ID;
            // Active budgets as chips: the list must say "this user is
            // limited" without a second round-trip per row.
            $u['budgets'] = array_values(array_map(
                static fn(array $b): array => [
                    'id' => $b['id'], 'name' => $b['name'], 'amount' => $b['amount'],
                    'currency' => $b['currency'], 'period' => $b['period'],
                    'action_at_limit' => $b['action_at_limit'], 'spent_amount' => $b['spent_amount'],
                    'enabled' => $b['enabled'],
                ],
                $this->budgets->listForUser((int)$u['id'], false),
            ));
        }
        unset($u);

        return Response::json([
            'ok'          => true,
            'period'      => $period,
            'from'        => $from,
            'to'          => $to,
            'persistent'  => $this->ledger->isPersistent(),
            'ledger_migrated' => $this->ledger->tableAvailable(),
            'users'       => $users,
            'unattributed' => $this->unattributedBlock($from, $to),
        ]);
    }

    /**
     * GET /api/admin/users/{id}?period=&days=
     *
     * One user: identity, totals, daily series, per-model breakdown, recent
     * runs, budgets and their recent alerts.
     */
    /** @param array<string,string> $params */
    public function detail(array $params): string
    {
        if ($denied = AdminGate::deny('admin.users')) {
            $this->audit?->denied('users.read', 'user', (string)($params['id'] ?? ''));
            return $denied;
        }
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $identity = $this->fetchUserIdentity($id);
        if ($identity === null) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $period = StatisticsPeriod::normalize(self::queryString('period'));
        $range  = StatisticsPeriod::range($period);
        $from   = $period === StatisticsPeriod::ALL ? null : $range['from'];
        $to     = $period === StatisticsPeriod::ALL ? null : $range['to'];
        $days   = max(1, min(366, (int)(self::queryString('days') ?: 30)));

        $budgets = $this->budgets->listForUser($id, false);
        $alerts  = [];
        foreach ($budgets as $b) {
            foreach ($this->budgets->recentAlerts((int)$b['id'], 20) as $a) {
                $alerts[] = $a + ['budget_name' => $b['name']];
            }
        }
        usort($alerts, static fn(array $x, array $y): int => (int)($y['fired_at'] ?? 0) <=> (int)($x['fired_at'] ?? 0));

        return Response::json([
            'ok'       => true,
            'user'     => $identity,
            'period'   => $period,
            'totals'   => $this->ledger->totals($id, $from, $to),
            'series'   => $this->ledger->series($id, $days),
            'by_model' => $this->ledger->byModel($id, $from, $to),
            'recent_runs' => $this->ledger->recentRuns($id, 25),
            'budgets'  => $budgets,
            'alerts'   => array_slice($alerts, 0, 50),
        ]);
    }

    /**
     * POST /api/admin/users/{id}/budget
     *
     * Create or update ONE user-scope budget. Body: {id?, name?, amount,
     * currency?, period?, action_at_limit?, thresholds?, enabled?}. The
     * audit trail records the diff, and the answer reports whether the row
     * actually persisted — a write that failed must never render as saved
     * (docs/ADMIN.md §4).
     */
    /** @param array<string,string> $params */
    public function saveBudget(array $params): string
    {
        if ($denied = AdminGate::deny('admin.users')) {
            $this->audit?->denied('users.budget.write', 'user', (string)($params['id'] ?? ''));
            return $denied;
        }
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        if ($this->fetchUserIdentity($id) === null) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $body = $this->jsonBody();
        $before = null;
        $editId = isset($body['id']) && is_numeric($body['id']) ? (int)$body['id'] : 0;
        if ($editId > 0) {
            foreach ($this->budgets->listForUser($id, false) as $b) {
                if ((int)$b['id'] === $editId) {
                    $before = $b;
                    break;
                }
            }
            if ($before === null) {
                return Response::json(['ok' => false, 'error' => 'budget_not_found'], 404);
            }
        }
        $result = $this->budgets->upsertUserBudget($id, $body);
        if (!($result['ok'] ?? false)) {
            $this->audit?->record(
                'users.budget.write',
                $before ?? [],
                $body,
                AuditLog::RESULT_ERROR,
                'user',
                (string)$id,
                'Budget write refused: ' . (string)($result['error'] ?? 'unknown'),
            );
            return Response::json($result, 400);
        }
        $after = null;
        foreach ($this->budgets->listForUser($id, false) as $b) {
            if ((int)$b['id'] === (int)($result['budget_id'] ?? 0)) {
                $after = $b;
                break;
            }
        }
        $this->audit?->record(
            'users.budget.write',
            $before === null ? [] : self::budgetDiffView($before),
            $after === null ? [] : self::budgetDiffView($after),
            AuditLog::RESULT_OK,
            'user',
            (string)$id,
            $before === null ? 'User budget created' : 'User budget updated',
        );
        return Response::json([
            'ok'        => true,
            'budget_id' => (int)($result['budget_id'] ?? 0),
            'budget'    => $after,
            'persisted' => true,
        ]);
    }

    /**
     * POST /api/admin/users/sync
     *
     * Re-derive ledger rows for settled sessions that have none — the manual
     * trigger for the same convergence sweep the safety net runs. Capped so
     * a first backfill on a large installation cannot time out the request;
     * the CLI (bin/usage-ledger-backfill.php) is the tool for the full run.
     */
    public function sync(): string
    {
        if ($denied = AdminGate::deny('admin.users')) {
            $this->audit?->denied('usage.ledger.resync', 'usage_ledger');
            return $denied;
        }
        $synced = $this->ledger->syncMissing(UsageLedger::SYNC_BATCH);
        $this->audit?->record(
            'usage.ledger.resync',
            [],
            ['sessions_synced' => $synced],
            AuditLog::RESULT_OK,
            'usage_ledger',
            null,
            $synced > 0 ? "Resynced {$synced} settled session(s) into the ledger" : 'Ledger already converged',
        );
        return Response::json([
            'ok'       => true,
            'synced'   => $synced,
            'complete' => $synced < UsageLedger::SYNC_BATCH,
            'message'  => $synced < UsageLedger::SYNC_BATCH
                ? 'Ledger is converged with the source rows.'
                : 'More sessions remain; run again or use bin/usage-ledger-backfill.php.',
        ]);
    }

    /**
     * GET /api/admin/users/{id}/export
     *
     * Data-subject export (GDPR-shaped): everything the installation holds
     * about one end user — identity, profile, full session contents, and
     * every ledger row — as a single JSON download. Audited, because an
     * export is a bulk read of personal data and the trail should say who
     * pulled it and when.
     */
    /** @param array<string,string> $params */
    public function export(array $params): string
    {
        if ($denied = AdminGate::deny('admin.users')) {
            $this->audit?->denied('users.export', 'user', (string)($params['id'] ?? ''));
            return $denied;
        }
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $identity = $this->fetchUserIdentity($id);
        if ($identity === null) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $sessions = $this->fetchUserSessions($id);
        $ledger   = $this->ledger->rowsForUser($id);
        $bundle = [
            'export' => [
                'schema'       => 'revoap.user_export/1',
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'app'          => 'RevoAp',
                'note'         => 'Complete record of one end user: identity, profile, sessions (including query and answer text) and billing rows.',
            ],
            'user'     => $identity,
            'sessions' => $sessions,
            'usage'    => [
                'totals' => $this->ledger->totals($id),
                'rows'   => $ledger,
            ],
        ];
        $this->audit?->record(
            'users.export',
            [],
            ['sessions' => count($sessions), 'ledger_rows' => count($ledger)],
            AuditLog::RESULT_OK,
            'user',
            (string)$id,
            'Exported user data bundle',
        );
        $stamp = gmdate('Ymd\THis\Z');
        return Response::download(
            (string)json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "revoap-user-{$id}-{$stamp}.json",
            'application/json; charset=utf-8',
        );
    }

    /* ------------------------------------------------------------------
     * USER — my usage
     * ---------------------------------------------------------------- */

    /**
     * GET /api/my/usage?period=&days=
     *
     * Identity rules, in full (this is the whole security model of the
     * endpoint):
     *
     *   - authenticated END USER → forced to its own id; ?user_id ignored.
     *   - OPERATOR (gate open)   → ?user_id=<n> when present, otherwise the
     *     local profile user — the operator's own console runs.
     *   - ANONYMOUS              → the local profile user. This matches the
     *     documented v1.8.6 exposure (History and Statistics are already
     *     open on such installs, and this payload is aggregates only — no
     *     query text, no answers). With `multiuser.ownership` ON, anonymous
     *     callers instead get identity_required: an install that asked for
     *     enforcement gets enforcement on every surface at once.
     */
    public function myUsage(): string
    {
        $strict = false;
        if ($this->flags !== null) {
            try {
                $strict = $this->flags->enabled('multiuser.ownership');
            } catch (\Throwable) {
                $strict = false;
            }
        }
        $viewer = Identity::fromRequest($strict);
        if ($viewer->seesNothing()) {
            return Response::json([
                'ok'      => false,
                'error'   => 'identity_required',
                'message' => 'This installation enforces per-user ownership; usage requires an authenticated identity.',
            ], 403);
        }

        $userId = $viewer->scopedUserId();
        if ($userId === null && $viewer->isAdmin()) {
            $requested = self::queryString('user_id');
            if (is_numeric($requested) && (int)$requested > 0) {
                $userId = (int)$requested;
            }
        }
        if ($userId === null) {
            $userId = $this->localUserId();
        }

        $period = StatisticsPeriod::normalize(self::queryString('period'));
        $range  = StatisticsPeriod::range($period);
        $from   = $period === StatisticsPeriod::ALL ? null : $range['from'];
        $to     = $period === StatisticsPeriod::ALL ? null : $range['to'];
        $days   = max(1, min(366, (int)(self::queryString('days') ?: 30)));

        $identity = $userId === null ? null : $this->fetchUserIdentity($userId);
        $payload = [
            'ok'       => true,
            'viewer'   => $viewer->kind,
            'user'     => $identity,
            'period'   => $period,
            'persistent'    => $this->ledger->isPersistent(),
            'ledger_migrated' => $this->ledger->tableAvailable(),
            'totals'   => $userId === null ? null : $this->ledger->totals($userId, $from, $to),
            'series'   => $userId === null ? [] : $this->ledger->series($userId, $days),
            'by_model' => $userId === null ? [] : $this->ledger->byModel($userId, $from, $to, 12),
            'budgets'  => $userId === null ? [] : $this->budgets->listForUser($userId, false),
        ];
        // The live verdict, so the panel can say "runs are blocked until
        // Monday" from the same numbers the enforcer will use — a panel that
        // recomputes the budget state client-side is a second implementation
        // of the limit rule, and the two will disagree eventually.
        if ($userId !== null && $this->quotas !== null) {
            try {
                $verdict = $this->quotas->checkForRun($userId);
                unset($verdict['budgets']);   // already above, shaped for UI
                $payload['quota'] = $verdict;
            } catch (\Throwable $e) {
                $payload['quota'] = ['action' => 'ok', 'checked' => false,
                    'error' => Logger::sanitizeScalar($e->getMessage())];
            }
        }
        return Response::json($payload);
    }

    /* ------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    /**
     * The unattributed bucket, same shape as a user row minus identity.
     *
     * @return array<string,mixed>
     */
    private function unattributedBlock(?string $from, ?string $to): array
    {
        $t = $this->ledger->totals(null, $from, $to);
        return [
            'runs'      => $t['runs'],
            'calls'     => $t['calls'],
            'tokens'    => $t['total_tokens'],
            'cost'      => $t['cost'],
            'cost_rows' => $t['cost_rows'],
            'coverage'  => $t['calls'] > 0 ? round($t['cost_rows'] / $t['calls'], 4) : null,
        ];
    }

    /**
     * users + user_profiles in one shape. Returns null when the user row
     * does not exist (404 territory) — a ledger row for a deleted user is
     * still real spend, but it is not an account.
     *
     * @return array<string,mixed>|null
     */
    private function fetchUserIdentity(int $id): ?array
    {
        $pdo = $this->pdoOrNull();
        if ($pdo === null) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT id, external_id, email, created_at FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch();
            if (!is_array($user)) {
                return null;
            }
            $profile = null;
            try {
                $p = $pdo->prepare(
                    'SELECT first_name, last_name, email, job_title FROM user_profiles WHERE user_id = :id LIMIT 1'
                );
                $p->execute(['id' => $id]);
                $row = $p->fetch();
                if (is_array($row)) {
                    $profile = $row;
                }
            } catch (\Throwable) {
                $profile = null;   // profiles table optional on old schemas
            }
            $first = trim((string)($profile['first_name'] ?? ''));
            $last  = trim((string)($profile['last_name'] ?? ''));
            $name  = trim($first . ' ' . $last);
            return [
                'id'          => (int)($user['id'] ?? $id),
                'external_id' => (string)($user['external_id'] ?? ''),
                'email'       => ($profile['email'] ?? null) !== null && (string)$profile['email'] !== ''
                                    ? (string)$profile['email']
                                    : (($user['email'] ?? null) === null ? null : (string)$user['email']),
                'name'        => $name !== '' ? $name : null,
                'job_title'   => ($profile['job_title'] ?? null) === null ? null : (string)$profile['job_title'],
                'created_at'  => (string)($user['created_at'] ?? ''),
                'is_local'    => ($user['external_id'] ?? '') === UserProfileRepository::LOCAL_EXTERNAL_ID,
            ];
        } catch (\Throwable $e) {
            Logger::debug('users.identity_failed: ' . Logger::sanitizeScalar($e->getMessage()));
            return null;
        }
    }

    /**
     * Full session records for the export bundle. Content included raw:
     * this is the data subject's own text, and redacting it would defeat
     * the purpose of an export. Secret-shaped strings that ended up INSIDE
     * a prompt are the operator's audit-export concern, not this bundle's —
     * they are user content like everything else in the row.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchUserSessions(int $id): array
    {
        $pdo = $this->pdoOrNull();
        if ($pdo === null) {
            return [];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT id, skill_slug, status, consensus_status, consensus_score,
                        total_tokens, duration_ms, query_text, final_answer,
                        created_at, completed_at
                 FROM council_sessions WHERE user_id = :id ORDER BY id ASC'
            );
            $stmt->execute(['id' => $id]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            Logger::debug('users.sessions_failed: ' . Logger::sanitizeScalar($e->getMessage()));
            return [];
        }
    }

    /** The console's own pipeline identity (external_id 'local'). */
    private function localUserId(): ?int
    {
        if ($this->profiles === null) {
            return null;
        }
        try {
            $id = $this->profiles->ensureLocalUser();
            return $id > 0 ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pdoOrNull(): ?PDO
    {
        if ($this->db instanceof PDO) {
            return $this->db;
        }
        if ($this->db instanceof Database) {
            try {
                return $this->db->pdo();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * The budget fields that matter for an audit diff — never the row's
     * rollup counters, which change without anyone deciding anything.
     *
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private static function budgetDiffView(array $b): array
    {
        return [
            'id'              => $b['id'] ?? null,
            'name'            => $b['name'] ?? null,
            'amount'          => $b['amount'] ?? null,
            'currency'        => $b['currency'] ?? null,
            'period'          => $b['period'] ?? null,
            'action_at_limit' => $b['action_at_limit'] ?? null,
            'thresholds'      => $b['thresholds'] ?? null,
            'enabled'         => $b['enabled'] ?? null,
        ];
    }

    private static function queryString(string $key): string
    {
        $raw = $_GET[$key] ?? '';
        return is_string($raw) ? $raw : '';
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 16);
        return is_array($decoded) ? $decoded : [];
    }
}
