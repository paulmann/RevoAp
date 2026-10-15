<?php
/**
 * AuditLog: append-only, hash-chained record of administrative actions.
 *
 * Why a hash chain
 * ----------------
 * An audit log that an administrator can edit is not evidence. The obvious
 * protection — no UPDATE or DELETE in the repository — only covers the
 * application: anyone with the database password (which is in `.sql.php` on
 * the same host) can `DELETE FROM revoap_audit_log WHERE …` and the table
 * looks perfectly consistent afterwards.
 *
 * So each row stores `prev_hash` = the previous row's `entry_hash`, and
 * `entry_hash` = SHA-256 over (prev_hash ‖ the row's own canonical fields).
 * Deleting or editing any row in the middle breaks the chain from that point
 * on, and {@see self::verify()} reports the exact index. Rewriting the whole
 * table is still possible for someone who recomputes every hash — nothing in
 * a single database can prevent that — but it turns a one-line DELETE into a
 * deliberate, whole-table forgery, which is a different thing to have to
 * accuse someone of.
 *
 * What gets recorded
 * ------------------
 * Administrative state changes: settings writes, key rotations, model
 * catalogue changes, firewall edits, user and role changes, backup and
 * restore, migration applies, maintenance mode, and every DENIED attempt at
 * the above. Read-only browsing is not recorded — it would drown the trail in
 * noise and, on an install with the History tab open, would itself store
 * prompts a second time.
 *
 * Secret handling
 * ---------------
 * `before` / `after` carry the DIFF, not the object, and both pass through the
 * same redactor as the debug logger. An audit trail that leaks the API key it
 * was recording a rotation of is worse than no trail: it is a second copy of
 * the secret, in a table nobody thinks to scrub.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Admin\Audit;

use App\Core\Database;
use App\Core\AdminAuth;
use App\Core\Logger;
use App\Http\AdminGate;
use PDO;

final class AuditLog
{
    public const TABLE = 'revoap_audit_log';

    /** The genesis row's prev_hash. A fixed, obvious sentinel rather than
     *  empty, so a chain that starts at the wrong place is visible. */
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Results. A closed set, stored as a string so the table stays readable
     *  in a mysql client. */
    public const RESULT_OK      = 'ok';
    public const RESULT_DENIED  = 'denied';
    public const RESULT_ERROR   = 'error';

    /** @var array<int,array<string,mixed>> in-process fallback when there is no DB */
    private array $memory = [];

    private ?Database $db = null;

    /** @param null|\PDO|Database $db  See AvailabilityRepository for why both. */
    public function __construct(null|\PDO|Database $db = null)
    {
        $this->db = $db instanceof \PDO ? Database::fromPdo($db) : $db;
    }

    public function isPersistent(): bool
    {
        return $this->db !== null;
    }

    /**
     * Record one action.
     *
     * Never throws. An audit write that fails must not fail the operation it
     * was describing — an operator who cannot save a setting because the audit
     * table is missing has a worse problem than a missing audit entry, and the
     * failure itself is logged where it can be seen.
     *
     * @param string            $action     Verb, stable and greppable: 'settings.write'
     * @param array<string,mixed> $before   Redacted before-state (or the diff)
     * @param array<string,mixed> $after    Redacted after-state
     * @param string            $result     self::RESULT_*
     */
    public function record(
        string $action,
        array $before = [],
        array $after = [],
        string $result = self::RESULT_OK,
        string $targetType = '',
        ?string $targetId = null,
        ?string $detail = null,
        ?string $actorLabel = null,
    ): bool {
        $action = self::cleanAction($action);
        if ($action === '') {
            return false;
        }

        $entry = [
            'created_at'  => time(),
            'actor_type'  => AdminGate::openWithoutToken() ? 'anonymous' : 'admin',
            'actor_id'    => self::actorId(),
            'actor_label' => $actorLabel ?? self::defaultActorLabel(),
            'action'      => $action,
            'target_type' => mb_substr($targetType, 0, 64),
            'target_id'   => $targetId === null ? null : mb_substr($targetId, 0, 191),
            'result'      => in_array($result, [self::RESULT_OK, self::RESULT_DENIED, self::RESULT_ERROR], true)
                                ? $result : self::RESULT_ERROR,
            'detail'      => $detail === null ? null : mb_substr(Logger::sanitizeScalar($detail), 0, 500),
            'before_json' => self::encodeRedacted($before),
            'after_json'  => self::encodeRedacted($after),
            'ip'          => self::clientIpBinary(),
            'user_agent'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'request_id'  => self::requestId(),
        ];

        $pdo = $this->db?->pdo();
        if ($pdo === null) {
            $entry['prev_hash']  = self::GENESIS;
            $entry['entry_hash'] = self::hash($entry, self::GENESIS);
            $this->memory[] = $entry;
            // Cap the in-memory trail: a long-running worker with no database
            // would otherwise grow without bound.
            if (count($this->memory) > 500) {
                $this->memory = array_slice($this->memory, -500);
            }
            return true;
        }

        try {
            $prev = $this->lastHash($pdo);
            $entry['prev_hash'] = $prev;
            $entry['entry_hash'] = self::hash($entry, $prev);

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (
                    created_at, actor_type, actor_id, actor_label, action,
                    target_type, target_id, result, detail,
                    before_json, after_json, ip, user_agent, request_id,
                    prev_hash, entry_hash
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $entry['created_at'], $entry['actor_type'], $entry['actor_id'], $entry['actor_label'],
                $entry['action'], $entry['target_type'], $entry['target_id'], $entry['result'],
                $entry['detail'], $entry['before_json'], $entry['after_json'], $entry['ip'],
                $entry['user_agent'], $entry['request_id'], $entry['prev_hash'], $entry['entry_hash'],
            ]);
            return true;
        } catch (\Throwable $e) {
            // Keep the entry in memory so a later `verify()` can at least show
            // that the application TRIED, and log the reason.
            $this->memory[] = $entry + ['prev_hash' => self::GENESIS, 'entry_hash' => '', 'persist_error' => $e->getMessage()];
            Logger::warning('audit write failed for ' . $action . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Convenience: a denied attempt. */
    public function denied(string $action, string $targetType = '', ?string $targetId = null, ?string $detail = null): bool
    {
        return $this->record($action, [], [], self::RESULT_DENIED, $targetType, $targetId, $detail);
    }

    /**
     * Read the trail, newest first.
     *
     * Every filter is a bound parameter. The only interpolated SQL is the
     * table name (a class constant) and the dynamically built WHERE clause,
     * whose column names come from a fixed allow-list — never from input.
     *
     * @param array<string,mixed> $filter action|actor|result|target_type|from|to|q
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function query(array $filter = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;

        $pdo = $this->db?->pdo();
        if ($pdo === null) {
            $rows = array_reverse($this->memory);
            return [
                'rows' => array_slice($rows, $offset, $perPage),
                'total' => count($rows),
                'page' => $page,
                'per_page' => $perPage,
            ];
        }

        // Column allow-list: `$filter` keys arrive from the query string.
        $allowed = ['action' => 'action', 'actor' => 'actor_label', 'result' => 'result', 'target_type' => 'target_type'];
        $where = [];
        $params = [];
        // Single-entry lookup by primary key (used by AuditController::show()).
        // Bound like every other filter, never interpolated.
        if (isset($filter['id']) && is_numeric($filter['id'])) {
            $where[] = 'id = ?';
            $params[] = (int)$filter['id'];
        }
        foreach ($allowed as $key => $column) {
            $value = $filter[$key] ?? null;
            if (!is_string($value) || trim($value) === '') { continue; }
            $where[] = "$column = ?";
            $params[] = trim($value);
        }
        if (isset($filter['from']) && is_numeric($filter['from'])) {
            $where[] = 'created_at >= ?';
            $params[] = (int)$filter['from'];
        }
        if (isset($filter['to']) && is_numeric($filter['to'])) {
            $where[] = 'created_at <= ?';
            $params[] = (int)$filter['to'];
        }
        // Full-text-ish search across the human-readable columns. A LIKE on
        // three columns is not free, but this table is small (administrative
        // actions, not pipeline events) and the alternative — a FULLTEXT index
        // — adds a migration and a MyISAM/InnoDB caveat for no real gain.
        $q = $filter['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            $needle = '%' . self::escapeLike(trim($q)) . '%';
            $where[] = '(action LIKE ? OR actor_label LIKE ? OR detail LIKE ? OR target_id LIKE ?)';
            array_push($params, $needle, $needle, $needle, $needle);
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        try {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . $whereSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT id, created_at, actor_type, actor_id, actor_label, action,
                        target_type, target_id, result, detail, before_json, after_json,
                        ip, user_agent, request_id, prev_hash, entry_hash
                 FROM ' . self::TABLE . $whereSql . '
                 ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            Logger::debug('audit query failed: ' . $e->getMessage());
            return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'error' => 'query_failed'];
        }

        return [
            'rows' => array_map([self::class, 'shapeRow'], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Verify the hash chain.
     *
     * Walks oldest → newest recomputing each entry_hash from its own fields and
     * the previous row's hash. Returns the first break, or `ok` with the count.
     *
     * Streams rather than loading the table: an audit trail that has run for a
     * year can be hundreds of thousands of rows, and `verify` is exactly the
     * operation someone runs when something already went wrong.
     *
     * @return array{ok:bool,checked:int,first_break:?array<string,mixed>,error:?string}
     */
    public function verify(int $maxRows = 200_000): array
    {
        $pdo = $this->db?->pdo();
        if ($pdo === null) {
            return ['ok' => true, 'checked' => count($this->memory), 'first_break' => null, 'error' => 'no_database'];
        }
        try {
            $stmt = $pdo->query(
                'SELECT id, created_at, actor_type, actor_id, actor_label, action,
                        target_type, target_id, result, detail, before_json, after_json,
                        request_id, prev_hash, entry_hash
                 FROM ' . self::TABLE . ' ORDER BY id ASC LIMIT ' . max(1, $maxRows)
            );
            if ($stmt === false) {
                return ['ok' => false, 'checked' => 0, 'first_break' => null, 'error' => 'query_failed'];
            }

            $checked = 0;
            $expectedPrev = self::GENESIS;
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $checked++;
                $storedHash = (string)($row['entry_hash'] ?? '');
                $storedPrev = (string)($row['prev_hash'] ?? '');

                if (!hash_equals($expectedPrev, $storedPrev)) {
                    return ['ok' => false, 'checked' => $checked, 'first_break' => [
                        'id' => (int)$row['id'], 'reason' => 'prev_hash_mismatch',
                        'expected_prev' => $expectedPrev, 'found_prev' => $storedPrev,
                    ], 'error' => null];
                }
                $recomputed = self::hash($row, $storedPrev);
                if (!hash_equals($recomputed, $storedHash)) {
                    return ['ok' => false, 'checked' => $checked, 'first_break' => [
                        'id' => (int)$row['id'], 'reason' => 'entry_hash_mismatch',
                        'expected' => $recomputed, 'found' => $storedHash,
                    ], 'error' => null];
                }
                $expectedPrev = $storedHash;
            }
            return ['ok' => true, 'checked' => $checked, 'first_break' => null, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'checked' => 0, 'first_break' => null, 'error' => $e->getMessage()];
        }
    }

    /** Distinct actions present, for the filter dropdown. */
    public function actions(): array
    {
        $pdo = $this->db?->pdo();
        if ($pdo === null) {
            return array_values(array_unique(array_column($this->memory, 'action')));
        }
        try {
            $stmt = $pdo->query('SELECT DISTINCT action FROM ' . self::TABLE . ' ORDER BY action ASC LIMIT 200');
            return $stmt === false ? [] : array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable) {
            return [];
        }
    }

    /* ------------------------------------------------------------------
     * Hashing
     * ---------------------------------------------------------------- */

    /**
     * The canonical hash of one entry.
     *
     * Field order and separators are FIXED and documented here, because any
     * change invalidates every previously written row. The separator is `\x1f`
     * (unit separator), which cannot appear in any of the field values after
     * redaction, so no two distinct rows can produce the same canonical string.
     *
     * `ip` and `user_agent` are deliberately EXCLUDED: they are the two fields
     * most likely to be normalised differently by a future refactor (IPv6
     * representation, UA truncation), and a hash that breaks on a cosmetic
     * change is worse than one that covers slightly less.
     *
     * @param array<string,mixed> $row
     */
    public static function hash(array $row, string $prevHash): string
    {
        $parts = [
            $prevHash,
            (string)($row['created_at'] ?? 0),
            (string)($row['actor_type'] ?? ''),
            (string)($row['actor_id'] ?? ''),
            (string)($row['actor_label'] ?? ''),
            (string)($row['action'] ?? ''),
            (string)($row['target_type'] ?? ''),
            (string)($row['target_id'] ?? ''),
            (string)($row['result'] ?? ''),
            (string)($row['detail'] ?? ''),
            (string)($row['before_json'] ?? ''),
            (string)($row['after_json'] ?? ''),
            (string)($row['request_id'] ?? ''),
        ];
        return hash('sha256', implode("\x1f", $parts));
    }

    private function lastHash(PDO $pdo): string
    {
        try {
            $stmt = $pdo->query('SELECT entry_hash FROM ' . self::TABLE . ' ORDER BY id DESC LIMIT 1');
            $value = $stmt === false ? false : $stmt->fetchColumn();
            return is_string($value) && $value !== '' ? $value : self::GENESIS;
        } catch (\Throwable) {
            return self::GENESIS;
        }
    }

    /* ------------------------------------------------------------------
     * Field preparation
     * ---------------------------------------------------------------- */

    /**
     * Redact then encode a before/after payload.
     *
     * Values are redacted individually rather than the encoded JSON as a whole,
     * because the redactor's patterns are written for raw values: a key that
     * has already been JSON-escaped (`sk-abc\"def`) will not match a pattern
     * written for `sk-abc`.
     *
     * @param array<string,mixed> $data
     */
    private static function encodeRedacted(array $data): ?string
    {
        if ($data === []) { return null; }
        $clean = self::redactDeep($data, 0);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json)) { return null; }
        // A diff that is larger than the column is truncated rather than
        // dropped: "changed 40 settings" is more useful than nothing, and the
        // marker says the record is incomplete instead of implying it is whole.
        if (strlen($json) > 60_000) {
            $json = substr($json, 0, 60_000) . '…[truncated]';
        }
        return $json;
    }

    /**
     * @param array<string,mixed>|list<mixed> $data
     * @return array<string,mixed>|list<mixed>
     */
    private static function redactDeep(array $data, int $depth): array
    {
        if ($depth > 6) { return ['…' => 'depth limit']; }
        $out = [];
        foreach ($data as $key => $value) {
            $name = is_string($key) ? $key : (string)$key;
            if (self::isSecretKey($name)) {
                $out[$name] = '[redacted]';
                continue;
            }
            if (is_array($value)) { $out[$name] = self::redactDeep($value, $depth + 1); continue; }
            if (is_string($value)) { $out[$name] = Logger::sanitizeScalar($value); continue; }
            if (is_scalar($value) || $value === null) { $out[$name] = $value; continue; }
            $out[$name] = '[' . get_debug_type($value) . ']';
        }
        return $out;
    }

    /** Key names whose VALUE is a secret regardless of what it looks like. */
    private static function isSecretKey(string $name): bool
    {
        $n = strtolower($name);
        foreach ([
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'api-key',
            'private_key', 'privatekey', 'dsn', 'authorization', 'cookie', 'session',
            'totp', 'recovery', 'credential', 'key',
        ] as $needle) {
            if (str_contains($n, $needle)) { return true; }
        }
        return false;
    }

    /** Escape LIKE wildcards so a search for `100%` is literal. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** `settings.write` is valid; `'; DROP TABLE` is not. */
    private static function cleanAction(string $action): string
    {
        $action = trim($action);
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){0,3}$/i', $action) !== 1) {
            return '';
        }
        return strtolower($action);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function shapeRow(array $row): array
    {
        $ip = $row['ip'] ?? null;
        return [
            'id'          => (int)($row['id'] ?? 0),
            'created_at'  => (int)($row['created_at'] ?? 0),
            'actor_type'  => (string)($row['actor_type'] ?? ''),
            'actor_id'    => $row['actor_id'] === null ? null : (string)$row['actor_id'],
            'actor_label' => (string)($row['actor_label'] ?? ''),
            'action'      => (string)($row['action'] ?? ''),
            'target_type' => (string)($row['target_type'] ?? ''),
            'target_id'   => $row['target_id'] === null ? null : (string)$row['target_id'],
            'result'      => (string)($row['result'] ?? ''),
            'detail'      => $row['detail'] === null ? null : (string)$row['detail'],
            'before'      => self::decode($row['before_json'] ?? null),
            'after'       => self::decode($row['after_json'] ?? null),
            // Rendered as text, never as a binary blob: JSON has no byte type.
            'ip'          => is_string($ip) && $ip !== '' ? @inet_ntop($ip) : null,
            'user_agent'  => $row['user_agent'] ?? null,
            'request_id'  => $row['request_id'] ?? null,
            'prev_hash'   => (string)($row['prev_hash'] ?? ''),
            'entry_hash'  => (string)($row['entry_hash'] ?? ''),
        ];
    }

    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') { return null; }
        $decoded = json_decode($json, true, 8);
        return is_array($decoded) ? $decoded : null;
    }

    private static function actorId(): ?string
    {
        // The admin surface is token-gated, not user-gated, in v1; there is no
        // authenticated identity to record. The RBAC table introduced in
        // migration 023 gives one, and when it does the session user id goes
        // here. Until then the honest value is null, not a fabricated one.
        $sessionUser = $_SESSION['revoap_admin_user_id'] ?? null;
        return is_scalar($sessionUser) && $sessionUser !== '' ? (string)$sessionUser : null;
    }

    /**
     * A stable, non-reversible label for whoever is acting.
     *
     * The admin surface is token-gated, not user-gated, so there is no account
     * name to record. What IS recordable is a masked fingerprint of the
     * presented token: enough to tell two operators apart in the trail and to
     * correlate entries, without storing anything that helps an attacker who
     * has read access to the audit table. `AdminAuth::mask()` shows the first
     * and last four characters only, which for a 64-hex token leaves 56
     * characters unknown.
     *
     * An unprotected install is labelled honestly as anonymous rather than
     * pretending to be an authenticated administrator.
     */
    private static function defaultActorLabel(): string
    {
        if (AdminGate::openWithoutToken()) { return 'anonymous (admin protection off)'; }
        $presented = AdminAuth::presented();
        if ($presented === '') { return 'admin (token not presented)'; }
        return 'admin:' . AdminAuth::mask($presented);
    }

    /**
     * The client IP as 16 bytes, for the VARBINARY(16) column.
     *
     * `inet_pton` handles both families and stores IPv4 in its mapped form, so
     * one column and one index cover both. Returns null when the address is
     * absent or unparseable — never a placeholder, because a fabricated IP in
     * an audit trail is worse than a gap.
     */
    private static function clientIpBinary(): ?string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($raw) || $raw === '') { return null; }
        $packed = @inet_pton($raw);
        return is_string($packed) ? $packed : null;
    }

    /**
     * Per-request correlation id.
     *
     * Lets an operator tie an audit entry to the debug log lines and to the
     * pipeline events of the same request, which is the only way to answer
     * "what else happened while this setting changed?".
     */
    private static function requestId(): string
    {
        static $id = null;
        if ($id !== null) { return $id; }
        $incoming = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        // Honour an inbound id only when it is already the right shape, so a
        // client cannot inject arbitrary bytes into the column.
        if (is_string($incoming) && preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $incoming) === 1) {
            return $id = $incoming;
        }
        return $id = bin2hex(random_bytes(8));
    }
}
