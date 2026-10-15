<?php
/**
 * UsageLedger: append-only per-run token & cost billing (v2.1.0, T5).
 *
 * Why this table exists when council_model_responses already has the numbers
 * --------------------------------------------------------------------------
 * `council_model_responses` is keyed by STEP and carries no user. Answering
 * "what did user 42 spend this month" from it means joining three tables and
 * scanning the largest one in the schema — on every panel view and, worse, on
 * every quota check in the run-creation hot path. The ledger denormalises
 * exactly those numbers into one append-only table keyed by the two questions
 * money asks: WHO (`user_id`) and WHEN (`run_created_at`).
 *
 * The source rows stay authoritative. Every ledger row is derived by
 * {@see self::syncSession()} from council_model_responses, the derivation is
 * idempotent, and `bin/usage-ledger-backfill.php` can rebuild the whole table
 * from source. A derived table that could drift and stay drifted would be
 * worse than no table; one that provably converges is a cache with an audit
 * shape.
 *
 * Invariants (asserted by tests/UsageLedgerTest.php)
 * --------------------------------------------------
 *  - APPEND-ONLY. This class exposes no update and no delete. Not "admins
 *    may delete": a billing row an administrator can edit is not billing.
 *    Corrections happen by appending, and retention is archival.
 *  - IDEMPOTENT. One row per (session_id, response_id) under a UNIQUE key,
 *    written with INSERT…IGNORE. Every completion path — sync engine, stream,
 *    worker, safety net, manual repair — can therefore call syncSession()
 *    unconditionally without coordinating with the others.
 *  - EVERY SETTLED RUN GETS A MARKER ROW (response_id = 0), even a run that
 *    failed before its first model call. "How many runs did user N make" must
 *    be answerable from the ledger alone; a run with no responses still
 *    happened, and its absence from billing would be a lie of omission.
 *  - NULL MEANS "NOT REPORTED", never zero — for `cost`, `cached_tokens`,
 *    `cache_write_tokens`, `cache_discount` and `reasoning_tokens`. This is
 *    the rule council_model_responses has followed since migrations 018/019,
 *    carried through verbatim so the two tables can never disagree about what
 *    a gap means. A provider that reports no price produces a NULL-cost row,
 *    and aggregates report the COVERAGE (how much of the spend is priced)
 *    rather than pretending the gap was free.
 *  - FAILED RUNS ARE BILLED. A run that burned tokens and then failed spent
 *    real money; `run_status` records which it was so a panel can show it,
 *    but the tokens and cost are on the row either way.
 *
 * Reasoning tokens: council_model_responses does not persist them per call;
 * the step meta does, per STEP. A step with exactly one response row can be
 * attributed honestly; a step with several cannot, and those rows carry NULL
 * rather than a guess that double-counts.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

namespace App\Usage;

use App\Core\Database;
use App\Core\Logger;
use PDO;

final class UsageLedger
{
    public const TABLE = 'revoap_usage_ledger';

    /** response_id of the per-run marker row (see class doc). */
    public const MARKER_RESPONSE_ID = 0;

    /** Ceiling for one syncMissing() sweep, so a huge backlog cannot lock
     *  the request that happened to trigger it. */
    public const SYNC_BATCH = 200;

    private readonly ?Database $db;

    /** @var bool|null memoised table-exists probe */
    private ?bool $tableReady = null;
    /** @var bool|null memoised council_model_responses cache-column probe */
    private ?bool $hasCacheColumns = null;
    /** @var bool|null memoised council_model_responses cost-column probe */
    private ?bool $hasCostColumn = null;

    /** @param null|PDO|Database $db  Both accepted, see AvailabilityRepository. */
    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function isPersistent(): bool
    {
        return $this->db !== null;
    }

    /* ------------------------------------------------------------------
     * Write side
     * ---------------------------------------------------------------- */

    /**
     * Derive (or re-derive) the ledger rows for ONE settled session.
     *
     * Idempotent: existing (session_id, response_id) rows are skipped by the
     * UNIQUE key, so calling this twice — or from two completion paths that
     * both fire — writes each row once. Never throws: billing must not fail
     * the run it is billing, and a ledger outage is logged where an operator
     * can see it, not surfaced to the caller as a 500.
     *
     * @return int number of rows actually inserted (0 on re-sync or no DB)
     */
    public function syncSession(int $sessionId): int
    {
        if ($sessionId <= 0 || $this->db === null || !$this->tableAvailable()) {
            return 0;
        }
        try {
            return (int)$this->db->run(function (PDO $pdo) use ($sessionId): int {
                $session = $this->fetchSession($pdo, $sessionId);
                if ($session === null) {
                    return 0;
                }
                $responses = $this->fetchResponses($pdo, $sessionId);

                $now = time();
                $inserted = 0;
                $pdo->beginTransaction();
                try {
                    // The marker row first: a session whose responses fail to
                    // insert still gets its "this run happened" record.
                    $inserted += $this->insertRow($pdo, [
                        'session_id'         => $sessionId,
                        'user_id'            => $session['user_id'],
                        'response_id'        => self::MARKER_RESPONSE_ID,
                        'provider'           => '',
                        'model'              => '',
                        'prompt_tokens'      => 0,
                        'completion_tokens'  => 0,
                        'total_tokens'       => 0,
                        'cached_tokens'      => null,
                        'cache_write_tokens' => null,
                        'reasoning_tokens'   => null,
                        'cost'               => null,
                        'cache_discount'     => null,
                        'cached'             => 0,
                        'tokens_estimated'   => 0,
                        'run_status'         => $session['status'],
                        'created_at'         => $now,
                        'run_created_at'     => $session['created_at'],
                    ]);
                    foreach ($responses as $row) {
                        $inserted += $this->insertRow($pdo, $row + [
                            'session_id'     => $sessionId,
                            'user_id'        => $session['user_id'],
                            'run_status'     => $session['status'],
                            'created_at'     => $now,
                            'run_created_at' => $session['created_at'],
                        ]);
                    }
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                return $inserted;
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.sync_failed', [
                'session_id' => $sessionId,
                'message'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return 0;
        }
    }

    /**
     * Sweep settled sessions that have NO ledger rows yet and sync them.
     *
     * This is the convergence backstop: syncSession() hangs off the settle
     * hook, but a crash between the session UPDATE and the ledger INSERT —
     * or a database that only just received migration 024 — leaves gaps. The
     * marker row is what makes the LEFT JOIN below terminate: every synced
     * session has at least one row, including sessions with zero model calls.
     *
     * @return int number of sessions synced in this sweep
     */
    public function syncMissing(int $limit = self::SYNC_BATCH): int
    {
        if ($this->db === null || !$this->tableAvailable()) {
            return 0;
        }
        $limit = max(1, min(5000, $limit));
        try {
            $ids = $this->db->run(function (PDO $pdo) use ($limit): array {
                $stmt = $pdo->prepare(
                    'SELECT cs.id
                     FROM council_sessions cs
                     LEFT JOIN ' . self::TABLE . ' l ON l.session_id = cs.id
                     WHERE cs.status IN (\'completed\', \'failed\') AND l.id IS NULL
                     ORDER BY cs.id DESC
                     LIMIT ' . $limit
                );
                $stmt->execute();
                $rows = $stmt->fetchAll();
                return is_array($rows)
                    ? array_map(static fn($r): int => (int)(is_array($r) ? ($r['id'] ?? 0) : $r), $rows)
                    : [];
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.sweep_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return 0;
        }
        $synced = 0;
        foreach ($ids as $id) {
            if ($id > 0) {
                $this->syncSession($id);
                $synced++;
            }
        }
        return $synced;
    }

    /* ------------------------------------------------------------------
     * Read side — every method tolerates a missing table with an empty,
     * correctly-shaped answer. A panel that renders "no data yet" beats a
     * 500 on an unmigrated install.
     * ---------------------------------------------------------------- */

    /**
     * Aggregate totals for one user (or, with null, for UNATTRIBUTED rows).
     *
     * `cost` and `cost_rows`/`rows` give the coverage: cost is the sum over
     * PRICED rows only, and a consumer that shows money must show the
     * coverage with it — an invented $0.00 for an unpriced provider is worse
     * than a visible gap (the budgets catalogue says exactly this).
     *
     * @param int|null    $userId null = rows with user_id IS NULL
     * @param string|null $from   DATETIME lower bound on run_created_at (inclusive)
     * @param string|null $to     DATETIME upper bound (exclusive)
     * @return array{runs:int,calls:int,prompt_tokens:int,completion_tokens:int,total_tokens:int,
     *               cached_tokens:int,cache_write_tokens:int,reasoning_tokens:int,
     *               cost:float|null,cost_rows:int,rows:int,cached_calls:int,
     *               cache_discount:float|null,failures:int}
     */
    public function totals(?int $userId = null, ?string $from = null, ?string $to = null): array
    {
        $empty = [
            'runs' => 0, 'calls' => 0,
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0,
            'cached_tokens' => 0, 'cache_write_tokens' => 0, 'reasoning_tokens' => 0,
            'cost' => null, 'cost_rows' => 0, 'rows' => 0, 'cached_calls' => 0,
            'cache_discount' => null, 'failures' => 0,
        ];
        if ($this->db === null || !$this->tableAvailable()) {
            return $empty;
        }
        try {
            [$where, $bind] = $this->windowWhere($userId, $from, $to);
            $row = $this->db->run(function (PDO $pdo) use ($where, $bind): ?array {
                // SUM over a NULL-able column ignores NULLs, which is exactly
                // the "not reported" semantics; COUNT(cost) counts priced rows.
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) AS rows_all,
                            SUM(CASE WHEN response_id = 0 THEN 1 ELSE 0 END) AS runs,
                            SUM(CASE WHEN response_id <> 0 THEN 1 ELSE 0 END) AS calls,
                            SUM(CASE WHEN response_id <> 0 AND cached = 1 THEN 1 ELSE 0 END) AS cached_calls,
                            SUM(CASE WHEN run_status = \'failed\' AND response_id = 0 THEN 1 ELSE 0 END) AS failures,
                            COALESCE(SUM(prompt_tokens), 0)     AS prompt_tokens,
                            COALESCE(SUM(completion_tokens), 0) AS completion_tokens,
                            COALESCE(SUM(total_tokens), 0)      AS total_tokens,
                            COALESCE(SUM(cached_tokens), 0)      AS cached_tokens,
                            COALESCE(SUM(cache_write_tokens), 0) AS cache_write_tokens,
                            COALESCE(SUM(reasoning_tokens), 0)   AS reasoning_tokens,
                            SUM(cost)           AS cost,
                            COUNT(cost)         AS cost_rows,
                            SUM(cache_discount) AS cache_discount
                     FROM ' . self::TABLE . ' ' . $where
                );
                $stmt->execute($bind);
                $r = $stmt->fetch();
                return is_array($r) ? $r : null;
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.totals_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return $empty;
        }
        if ($row === null) {
            return $empty;
        }
        return [
            'runs'               => (int)($row['runs'] ?? 0),
            'calls'              => (int)($row['calls'] ?? 0),
            'prompt_tokens'      => (int)($row['prompt_tokens'] ?? 0),
            'completion_tokens'  => (int)($row['completion_tokens'] ?? 0),
            'total_tokens'       => (int)($row['total_tokens'] ?? 0),
            'cached_tokens'      => (int)($row['cached_tokens'] ?? 0),
            'cache_write_tokens' => (int)($row['cache_write_tokens'] ?? 0),
            'reasoning_tokens'   => (int)($row['reasoning_tokens'] ?? 0),
            'cost'               => isset($row['cost']) ? (float)$row['cost'] : null,
            'cost_rows'          => (int)($row['cost_rows'] ?? 0),
            'rows'               => (int)($row['rows_all'] ?? 0),
            'cached_calls'       => (int)($row['cached_calls'] ?? 0),
            'cache_discount'     => isset($row['cache_discount']) ? (float)$row['cache_discount'] : null,
            'failures'           => (int)($row['failures'] ?? 0),
        ];
    }

    /**
     * Daily buckets for the sparkline/series view. Buckets on
     * `run_created_at` via SUBSTR, which yields YYYY-MM-DD on every dialect
     * that stores DATETIME canonically (the same trick HistoryService uses)
     * and bills a backfilled run on the day it RAN, not the day it was
     * written.
     *
     * @return array<int,array{day:string,runs:int,tokens:int,cost:float|null,cost_rows:int,calls:int}>
     */
    public function series(?int $userId = null, int $days = 30): array
    {
        if ($this->db === null || !$this->tableAvailable()) {
            return [];
        }
        $days = max(1, min(366, $days));
        $from = gmdate('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
        try {
            [$where, $bind] = $this->windowWhere($userId, $from, null);
            $rows = $this->db->run(function (PDO $pdo) use ($where, $bind): array {
                $stmt = $pdo->prepare(
                    'SELECT SUBSTR(run_created_at, 1, 10) AS day,
                            SUM(CASE WHEN response_id = 0 THEN 1 ELSE 0 END) AS runs,
                            SUM(CASE WHEN response_id <> 0 THEN 1 ELSE 0 END) AS calls,
                            COALESCE(SUM(total_tokens), 0) AS tokens,
                            SUM(cost)   AS cost,
                            COUNT(cost) AS cost_rows
                     FROM ' . self::TABLE . ' ' . $where . "
                     GROUP BY SUBSTR(run_created_at, 1, 10)
                     ORDER BY day ASC"
                );
                $stmt->execute($bind);
                $rs = $stmt->fetchAll();
                return is_array($rs) ? $rs : [];
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.series_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $day = (string)($r['day'] ?? '');
            if ($day === '') {
                // A NULL run_created_at (session row lost its timestamp)
                // cannot be bucketed honestly; the totals still count it.
                continue;
            }
            $out[] = [
                'day'       => $day,
                'runs'      => (int)($r['runs'] ?? 0),
                'calls'     => (int)($r['calls'] ?? 0),
                'tokens'    => (int)($r['tokens'] ?? 0),
                'cost'      => isset($r['cost']) ? (float)$r['cost'] : null,
                'cost_rows' => (int)($r['cost_rows'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Per-model/provider breakdown for one user (null = unattributed).
     *
     * @return array<int,array{provider:string,model:string,calls:int,tokens:int,
     *               cached_tokens:int,cost:float|null,cost_rows:int}>
     */
    public function byModel(?int $userId = null, ?string $from = null, ?string $to = null, int $limit = 50): array
    {
        if ($this->db === null || !$this->tableAvailable()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        try {
            [$where, $bind] = $this->windowWhere($userId, $from, $to);
            // The marker row (provider = '') is excluded: it is a run
            // counter, not a model call, and grouping it would invent an
            // empty-named model in the breakdown.
            $cond = $where === '' ? 'WHERE response_id <> 0' : $where . ' AND response_id <> 0';
            $rows = $this->db->run(function (PDO $pdo) use ($cond, $bind, $limit): array {
                $stmt = $pdo->prepare(
                    'SELECT provider, model,
                            COUNT(*) AS calls,
                            COALESCE(SUM(total_tokens), 0)  AS tokens,
                            COALESCE(SUM(cached_tokens), 0) AS cached_tokens,
                            SUM(cost)   AS cost,
                            COUNT(cost) AS cost_rows
                     FROM ' . self::TABLE . ' ' . $cond . '
                     GROUP BY provider, model
                     ORDER BY tokens DESC, calls DESC
                     LIMIT ' . $limit
                );
                $stmt->execute($bind);
                $rs = $stmt->fetchAll();
                return is_array($rs) ? $rs : [];
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.by_model_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'provider'      => (string)($r['provider'] ?? ''),
                'model'         => (string)($r['model'] ?? ''),
                'calls'         => (int)($r['calls'] ?? 0),
                'tokens'        => (int)($r['tokens'] ?? 0),
                'cached_tokens' => (int)($r['cached_tokens'] ?? 0),
                'cost'          => isset($r['cost']) ? (float)$r['cost'] : null,
                'cost_rows'     => (int)($r['cost_rows'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Every end-user with ledger activity in the window, plus their identity
     * columns. Users with NO activity in the window are included with zero
     * totals when $includeIdle is true (the admin panel lists accounts, not
     * just spenders) — the window filter then lives in the JOIN, not the
     * WHERE, which is the classic way to accidentally drop the zeros.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listUsers(?string $from = null, ?string $to = null, bool $includeIdle = true, int $limit = 500): array
    {
        if ($this->db === null) {
            return [];
        }
        $limit = max(1, min(2000, $limit));
        try {
            return $this->db->run(function (PDO $pdo) use ($from, $to, $includeIdle, $limit): array {
                if (!$this->tableExists($pdo)) {
                    return [];
                }
                $hasProfiles = $this->tableExistsNamed($pdo, 'user_profiles');
                $joinCond = 'l.user_id = u.id';
                $bind = [];
                if ($from !== null) {
                    $joinCond .= ' AND l.run_created_at >= :from';
                    $bind['from'] = $from;
                }
                if ($to !== null) {
                    $joinCond .= ' AND l.run_created_at < :to';
                    $bind['to'] = $to;
                }
                $profileSelect = $hasProfiles
                    ? 'p.first_name, p.last_name, p.email AS profile_email,'
                    : '';
                $profileJoin = $hasProfiles
                    ? ' LEFT JOIN user_profiles p ON p.user_id = u.id'
                    : '';
                $sql = "SELECT u.id, u.external_id, u.email, u.created_at AS user_created,
                               {$profileSelect}
                               SUM(CASE WHEN l.response_id = 0 THEN 1 ELSE 0 END) AS runs,
                               SUM(CASE WHEN l.response_id <> 0 THEN 1 ELSE 0 END) AS calls,
                               COALESCE(SUM(l.total_tokens), 0) AS total_tokens,
                               SUM(l.cost)   AS cost,
                               COUNT(l.cost) AS cost_rows,
                               MAX(l.run_created_at) AS last_run
                        FROM users u
                        LEFT JOIN " . self::TABLE . " l ON {$joinCond}{$profileJoin}
                        GROUP BY u.id, u.external_id, u.email, u.created_at"
                     . ($hasProfiles ? ', p.first_name, p.last_name, p.email' : '') . "
                        ORDER BY total_tokens DESC, u.id ASC
                        LIMIT {$limit}";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bind);
                $rows = $stmt->fetchAll();
                if (!is_array($rows)) {
                    return [];
                }
                $out = [];
                foreach ($rows as $r) {
                    $runs = (int)($r['runs'] ?? 0);
                    if (!$includeIdle && $runs === 0 && (int)($r['calls'] ?? 0) === 0) {
                        continue;
                    }
                    $first = trim((string)($r['first_name'] ?? ''));
                    $last  = trim((string)($r['last_name'] ?? ''));
                    $out[] = [
                        'id'          => (int)($r['id'] ?? 0),
                        'external_id' => (string)($r['external_id'] ?? ''),
                        'email'       => (string)($r['profile_email'] ?? '') !== ''
                                            ? (string)$r['profile_email']
                                            : (($r['email'] ?? null) === null ? null : (string)$r['email']),
                        'name'        => trim($first . ' ' . $last) !== '' ? trim($first . ' ' . $last) : null,
                        'runs'        => $runs,
                        'calls'       => (int)($r['calls'] ?? 0),
                        'tokens'      => (int)($r['total_tokens'] ?? 0),
                        'cost'        => isset($r['cost']) ? (float)$r['cost'] : null,
                        'cost_rows'   => (int)($r['cost_rows'] ?? 0),
                        'last_run'    => ($r['last_run'] ?? null) === null ? null : (string)$r['last_run'],
                    ];
                }
                return $out;
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.list_users_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
    }

    /**
     * Recent runs for one user, newest first, for the detail panel. Reads
     * the marker rows joined back to council_sessions for the status and a
     * short preview. `user_id IS NULL` selects the unattributed bucket.
     *
     * @return array<int,array{session_id:int,status:string,query_preview:string,
     *               tokens:int,cost:float|null,cost_rows:int,run_created_at:string|null}>
     */
    public function recentRuns(?int $userId, int $limit = 25): array
    {
        if ($this->db === null || !$this->tableAvailable()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        try {
            return $this->db->run(function (PDO $pdo) use ($userId, $limit): array {
                $userCond = $userId === null ? 'l.user_id IS NULL' : 'l.user_id = :uid';
                $bind = $userId === null ? [] : ['uid' => $userId];
                // Per-run rollup from the ledger itself (marker excluded from
                // the money columns by response_id <> 0), joined to the session
                // for status + preview.
                $stmt = $pdo->prepare(
                    "SELECT l.session_id,
                            MAX(l.run_status) AS run_status,
                            MAX(l.run_created_at) AS run_created_at,
                            COALESCE(SUM(CASE WHEN l.response_id <> 0 THEN l.total_tokens ELSE 0 END), 0) AS tokens,
                            SUM(CASE WHEN l.response_id <> 0 THEN l.cost END) AS cost,
                            SUM(CASE WHEN l.response_id <> 0 AND l.cost IS NOT NULL THEN 1 ELSE 0 END) AS cost_rows
                     FROM " . self::TABLE . " l
                     WHERE {$userCond}
                     GROUP BY l.session_id
                     ORDER BY l.session_id DESC
                     LIMIT {$limit}"
                );
                $stmt->execute($bind);
                $rows = $stmt->fetchAll();
                if (!is_array($rows) || $rows === []) {
                    return [];
                }
                $ids = array_map(static fn($r): int => (int)($r['session_id'] ?? 0), $rows);
                $ids = array_values(array_filter($ids, static fn(int $i): bool => $i > 0));
                $sessions = [];
                if ($ids !== []) {
                    $place = implode(',', array_fill(0, count($ids), '?'));
                    $s = $pdo->prepare(
                        "SELECT id, status, query_text FROM council_sessions WHERE id IN ({$place})"
                    );
                    $s->execute($ids);
                    foreach ($s->fetchAll() ?: [] as $sr) {
                        if (is_array($sr)) {
                            $sessions[(int)$sr['id']] = $sr;
                        }
                    }
                }
                $out = [];
                foreach ($rows as $r) {
                    $sid = (int)($r['session_id'] ?? 0);
                    $session = $sessions[$sid] ?? null;
                    $preview = '';
                    if ($session !== null) {
                        $q = (string)($session['query_text'] ?? '');
                        $preview = mb_substr(preg_replace('/\s+/u', ' ', $q) ?? $q, 0, 120);
                        $preview = Logger::sanitizeScalar($preview);
                    }
                    $status = (string)($r['run_status'] ?? '');
                    if ($session !== null && (string)($session['status'] ?? '') !== '') {
                        // The session row is authoritative for status: a run
                        // re-settled after its ledger sync (manual repair)
                        // must not show the stale verdict.
                        $status = (string)$session['status'];
                    }
                    $out[] = [
                        'session_id'   => $sid,
                        'status'       => $status,
                        'query_preview' => $preview,
                        'tokens'       => (int)($r['tokens'] ?? 0),
                        'cost'         => isset($r['cost']) ? (float)$r['cost'] : null,
                        'cost_rows'    => (int)($r['cost_rows'] ?? 0),
                        'run_created_at' => ($r['run_created_at'] ?? null) === null ? null : (string)$r['run_created_at'],
                    ];
                }
                return $out;
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.recent_runs_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
    }

    /**
     * Every ledger row for ONE user, oldest first — the billing side of a
     * data-subject export. Rows are returned as stored: an export that
     * silently re-derived or rounded its own numbers would not be the record.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rowsForUser(int $userId, int $limit = 20000): array
    {
        if ($userId <= 0 || $this->db === null || !$this->tableAvailable()) {
            return [];
        }
        $limit = max(1, min(100000, $limit));
        try {
            return (array)$this->db->run(static function (PDO $pdo) use ($userId, $limit): array {
                $stmt = $pdo->prepare(
                    'SELECT id, session_id, user_id, response_id, provider, model,
                            prompt_tokens, completion_tokens, total_tokens,
                            cached_tokens, cache_write_tokens, reasoning_tokens,
                            cost, cache_discount, cached, tokens_estimated,
                            run_status, created_at, run_created_at
                     FROM ' . self::TABLE . '
                     WHERE user_id = :uid
                     ORDER BY id ASC
                     LIMIT ' . $limit
                );
                $stmt->execute(['uid' => $userId]);
                $rs = $stmt->fetchAll();
                return is_array($rs) ? $rs : [];
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.user_rows_failed', [
                'user_id' => $userId,
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
    }

    /**
     * Everything the ledger knows about ONE session — the GDPR/export unit
     * and the debugging answer to "where did this bill come from".
     *
     * @return array<int,array<string,mixed>>
     */
    public function rowsForSession(int $sessionId): array
    {
        if ($sessionId <= 0 || $this->db === null || !$this->tableAvailable()) {
            return [];
        }
        try {
            return (array)$this->db->run(static function (PDO $pdo) use ($sessionId): array {
                $stmt = $pdo->prepare(
                    'SELECT * FROM ' . self::TABLE . ' WHERE session_id = :sid ORDER BY id ASC'
                );
                $stmt->execute(['sid' => $sessionId]);
                $rs = $stmt->fetchAll();
                return is_array($rs) ? $rs : [];
            });
        } catch (\Throwable $e) {
            Logger::warn('usage.ledger.session_rows_failed', [
                'session_id' => $sessionId,
                'message'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return [];
        }
    }

    /** Does the ledger table exist on the live database? (memoised) */
    public function tableAvailable(): bool
    {
        if ($this->db === null) {
            return false;
        }
        if ($this->tableReady !== null) {
            return $this->tableReady;
        }
        try {
            $this->tableReady = (bool)$this->db->run(fn(PDO $pdo): bool => $this->tableExists($pdo));
        } catch (\Throwable) {
            $this->tableReady = false;
        }
        return $this->tableReady;
    }

    /* ------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    /**
     * The dialect-correct INSERT that skips a duplicate (session_id,
     * response_id) pair instead of failing on it. Returns 1 when a row was
     * written, 0 when it already existed.
     *
     * @param array<string,mixed> $row
     */
    private function insertRow(PDO $pdo, array $row): int
    {
        $driver = $this->driver($pdo);
        $ignore = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $cols = implode(', ', array_keys($row));
        $holders = implode(', ', array_map(static fn(string $c): string => ':' . $c, array_keys($row)));
        $stmt = $pdo->prepare(
            "{$ignore} INTO " . self::TABLE . " ({$cols}) VALUES ({$holders})"
        );
        $bind = [];
        foreach ($row as $k => $v) {
            $bind[':' . $k] = $v;
        }
        $stmt->execute($bind);
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    /**
     * The session facts the ledger rows inherit.
     *
     * @return array{user_id:int|null,status:string,created_at:string|null}|null
     *         null when the session row is gone.
     */
    private function fetchSession(PDO $pdo, int $sessionId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, status, created_at FROM council_sessions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'user_id'    => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'status'     => (string)($row['status'] ?? ''),
            'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : null,
        ];
    }

    /**
     * Source rows for the ledger: one per model call, with the step meta's
     * reasoning figure attached ONLY where the attribution is unambiguous
     * (exactly one response on the step). Column probes keep this working on
     * databases that predate migrations 018/019 — those columns are then
     * simply absent from the SELECT and the ledger stores NULL, which is
     * what "not reported" means.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchResponses(PDO $pdo, int $sessionId): array
    {
        $cacheCols = $this->cacheColumnsPresent($pdo)
            ? 'r.cache_read_tokens, r.cache_write_tokens, r.cache_discount,'
            : '';
        $costCol = $this->costColumnPresent($pdo) ? 'r.cost,' : '';
        $stmt = $pdo->prepare(
            "SELECT r.id AS response_id, r.step_id, r.provider, r.model,
                    r.prompt_tokens, r.completion_tokens, r.total_tokens,
                    r.tokens_estimated,
                    {$cacheCols} {$costCol}
                    st.meta AS step_meta
             FROM council_model_responses r
             INNER JOIN council_steps st ON st.id = r.step_id
             WHERE st.session_id = :sid
             ORDER BY r.id ASC"
        );
        $stmt->execute(['sid' => $sessionId]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        // Responses per step: reasoning tokens live in the step meta, so they
        // can only be attributed to a ledger row when the step has exactly
        // ONE response. A step with several gets NULL in every row rather
        // than the same figure copied into each — copied numbers sum, and a
        // sum that double-counts is worse than an honest gap.
        $perStep = [];
        foreach ($rows as $r) {
            $sid = (int)($r['step_id'] ?? 0);
            $perStep[$sid] = ($perStep[$sid] ?? 0) + 1;
        }

        $out = [];
        foreach ($rows as $r) {
            $responseId = (int)($r['response_id'] ?? 0);
            if ($responseId <= 0) {
                continue;
            }
            $stepId = (int)($r['step_id'] ?? 0);
            $reasoning = null;
            if ($stepId > 0 && ($perStep[$stepId] ?? 0) === 1) {
                $meta = $this->decodeMeta($r['step_meta'] ?? null);
                $raw = $meta['reasoning'] ?? ($meta['reasoning_tokens'] ?? null);
                if (is_numeric($raw) && (int)$raw > 0) {
                    $reasoning = (int)$raw;
                }
            }
            // isset() == exists AND not NULL, which is exactly the ledger's
            // rule: an absent column (pre-018/019 database) and a reported
            // NULL both become the ledger's NULL, "not reported".
            $cachedTokens = isset($r['cache_read_tokens']) ? (int)$r['cache_read_tokens'] : null;
            $cacheWrite = isset($r['cache_write_tokens']) ? (int)$r['cache_write_tokens'] : null;
            $cacheDiscount = isset($r['cache_discount']) ? (float)$r['cache_discount'] : null;
            $cost = isset($r['cost']) ? (float)$r['cost'] : null;
            $out[] = [
                'response_id'        => $responseId,
                'provider'           => mb_substr((string)($r['provider'] ?? ''), 0, 64),
                'model'              => mb_substr((string)($r['model'] ?? ''), 0, 128),
                'prompt_tokens'      => (int)($r['prompt_tokens'] ?? 0),
                'completion_tokens'  => (int)($r['completion_tokens'] ?? 0),
                'total_tokens'       => (int)($r['total_tokens'] ?? 0),
                'cached_tokens'      => $cachedTokens,
                'cache_write_tokens' => $cacheWrite,
                'reasoning_tokens'   => $reasoning,
                'cost'               => $cost,
                'cache_discount'     => $cacheDiscount,
                'cached'             => $cachedTokens !== null && $cachedTokens > 0 ? 1 : 0,
                'tokens_estimated'   => (int)($r['tokens_estimated'] ?? 0) === 1 ? 1 : 0,
            ];
        }
        return $out;
    }

    /**
     * WHERE fragment + binds for (user, window). `user_id = :uid` vs
     * `user_id IS NULL` are different questions — the unattributed bucket is
     * a first-class citizen, not the absence of a filter.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function windowWhere(?int $userId, ?string $from, ?string $to): array
    {
        $where = [];
        $bind  = [];
        if ($userId === null) {
            $where[] = 'user_id IS NULL';
        } else {
            $where[] = 'user_id = :uid';
            $bind['uid'] = $userId;
        }
        if ($from !== null && $from !== '') {
            $where[] = 'run_created_at >= :from';
            $bind['from'] = $from;
        }
        if ($to !== null && $to !== '') {
            $where[] = 'run_created_at < :to';
            $bind['to'] = $to;
        }
        return ['WHERE ' . implode(' AND ', $where), $bind];
    }

    /** @return array<string,mixed> */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 8);
        return is_array($decoded) ? $decoded : [];
    }

    private function driver(PDO $pdo): string
    {
        try {
            return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            return '';
        }
    }

    private function tableExists(PDO $pdo): bool
    {
        return $this->tableExistsNamed($pdo, self::TABLE);
    }

    /**
     * Driver-aware table probe. sqlite_master for the test suites,
     * information_schema for deployments — the same split
     * ResponseRepository::cacheColumnsPresent() makes.
     */
    private function tableExistsNamed(PDO $pdo, string $table): bool
    {
        $table = strtolower($table);
        if ($this->driver($pdo) === 'sqlite') {
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

    /** Probe for migrations-018 cache columns on council_model_responses. */
    private function cacheColumnsPresent(PDO $pdo): bool
    {
        if ($this->hasCacheColumns !== null) {
            return $this->hasCacheColumns;
        }
        return $this->hasCacheColumns = $this->columnExists($pdo, 'council_model_responses', 'cache_read_tokens');
    }

    /** Probe for the migrations-019 cost column on council_model_responses. */
    private function costColumnPresent(PDO $pdo): bool
    {
        if ($this->hasCostColumn !== null) {
            return $this->hasCostColumn;
        }
        return $this->hasCostColumn = $this->columnExists($pdo, 'council_model_responses', 'cost');
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($this->driver($pdo) === 'sqlite') {
                $rs = $pdo->query('PRAGMA table_info(' . $table . ')');
                foreach ($rs ? $rs->fetchAll() : [] as $r) {
                    if (is_array($r) && strtolower((string)($r['name'] ?? '')) === strtolower($column)) {
                        return true;
                    }
                }
                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = :t AND LOWER(COLUMN_NAME) = :c LIMIT 1'
            );
            $stmt->execute(['t' => strtolower($table), 'c' => strtolower($column)]);
            return $stmt->fetch() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
