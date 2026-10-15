<?php
/**
 * AuditController: read-only HTTP surface over the hash-chained audit trail.
 *
 * There is deliberately NO update and NO delete route. An audit log that the
 * same UI can edit is not an audit log, and "we only expose delete to
 * administrators" is the exact argument every tampered log ever made. The
 * retention story is archival (a scheduled task moves old rows to a cold
 * table), not deletion through the console.
 *
 * Endpoints
 *   GET /api/audit          paginated, filterable listing
 *   GET /api/audit/{id}     one entry with its full before/after diff
 *   GET /api/audit/verify   recompute the chain and report the first break
 *   GET /api/audit/export   CSV or JSON download of the filtered set
 *
 * All four are admin-gated. The trail contains IP addresses, user agents and
 * configuration diffs; it is not a public resource, and `AdminGate::deny()`
 * returns the same 403 shape the rest of the operator surface uses so the SPA
 * can show one consistent unlock affordance.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Admin\Audit\AuditLog;
use App\Http\AdminGate;
use App\Http\Response;

final class AuditController
{
    /** Hard ceiling on one export. A CSV of a million audit rows is not a
     *  download, it is an outage; anything larger is a database task. */
    private const MAX_EXPORT_ROWS = 50_000;

    public function __construct(private readonly AuditLog $audit)
    {
    }

    /** GET /api/audit */
    public function index(): string
    {
        if ($denied = AdminGate::deny('audit')) {
            return $denied;
        }

        $filter = self::readFilter($_GET);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));

        $result = $this->audit->query($filter, $page, $perPage);

        return Response::json([
            'ok'       => true,
            'persistent' => $this->audit->isPersistent(),
            'entries'  => $result['rows'],
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
            'pages'    => $perPage > 0 ? (int)ceil($result['total'] / $perPage) : 0,
            'filter'   => $filter,
            'actions'  => $this->audit->actions(),
            ...(isset($result['error']) ? ['error' => $result['error']] : []),
        ]);
    }

    /**
     * GET /api/audit/{id}
     *
     * @param array<string,string> $params
     */
    public function show(array $params): string
    {
        if ($denied = AdminGate::deny('audit')) {
            return $denied;
        }
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }

        // Fetch by walking the paginated query rather than adding a
        // `findById()` to the repository: the trail is append-only and an id
        // lookup is rare enough that reusing the already-tested query path is
        // worth more than a second code path to maintain.
        $result = $this->audit->query(['id' => $id], 1, 1);
        $rows = $result['rows'] ?? [];
        if ($rows === []) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        return Response::json(['ok' => true, 'entry' => $rows[0]]);
    }

    /** GET /api/audit/verify */
    public function verify(): string
    {
        if ($denied = AdminGate::deny('audit')) {
            return $denied;
        }
        $result = $this->audit->verify();
        return Response::json([
            'ok' => true,
            'intact' => (bool)$result['ok'],
            'checked' => (int)$result['checked'],
            'first_break' => $result['first_break'],
            'error' => $result['error'],
            'persistent' => $this->audit->isPersistent(),
            // The chain is only meaningful when the trail is persisted. An
            // in-memory trail verifies trivially and saying "intact" about it
            // would be misleading, so the reason travels with the verdict.
            'meaningful' => $this->audit->isPersistent(),
        ]);
    }

    /** GET /api/audit/export?format=csv|json */
    public function export(): string
    {
        if ($denied = AdminGate::deny('audit')) {
            return $denied;
        }
        $format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
        if (!in_array($format, ['csv', 'json'], true)) {
            $format = 'csv';
        }
        $filter = self::readFilter($_GET);
        $result = $this->audit->query($filter, 1, self::MAX_EXPORT_ROWS);
        $rows = $result['rows'] ?? [];

        $stamp = gmdate('Ymd-His');
        if ($format === 'json') {
            return Response::download(
                (string)json_encode(
                    ['exported_at' => time(), 'filter' => $filter, 'count' => count($rows), 'entries' => $rows],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                "revoap-audit-{$stamp}.json",
                'application/json; charset=utf-8'
            );
        }

        return Response::download(self::toCsv($rows), "revoap-audit-{$stamp}.csv", 'text/csv; charset=utf-8');
    }

    /* ------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    /**
     * Whitelist the filter keys.
     *
     * `$_GET` is attacker-controlled and the filter feeds a WHERE clause whose
     * column names are chosen from a fixed map inside the repository. Reading
     * only the known keys here means an unexpected parameter cannot reach the
     * query builder at all, which is a stronger guarantee than the allow-list
     * alone.
     *
     * @param  array<string,mixed> $query
     * @return array<string,mixed>
     */
    private static function readFilter(array $query): array
    {
        $out = [];
        foreach (['action', 'actor', 'result', 'target_type', 'q'] as $key) {
            $value = $query[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $out[$key] = mb_substr(trim($value), 0, 191);
            }
        }
        foreach (['from', 'to'] as $key) {
            $value = $query[$key] ?? null;
            if (is_numeric($value)) {
                // A negative or absurd timestamp is clamped rather than
                // rejected: `from=0` is a legitimate "everything" and
                // `to=99999999999` is a legitimate "now and later".
                $out[$key] = max(0, min(4_102_444_800, (int)$value));
            }
        }
        return $out;
    }

    /**
     * Rows to CSV.
     *
     * Two things this has to get right that a naive `fputcsv` loop does not:
     *
     *  1. **Formula injection.** A cell beginning `=`, `+`, `-` or `@` is
     *     executed by Excel and LibreOffice when the file is opened. Audit
     *     fields contain attacker-influenced text (the detail of a denied
     *     request, a target id copied from a query string), so `=cmd|…` is a
     *     reachable value. Each such cell is prefixed with a single quote,
     *     which both spreadsheets treat as "literal text" and which is
     *     invisible when the cell is displayed.
     *  2. **Encoding.** The BOM is what makes UTF-8 Cyrillic, Chinese and
     *     Arabic open correctly in Excel; without it the file is interpreted
     *     as the system codepage and renders as mojibake.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private static function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        // UTF-8 BOM — see (2) above.
        fwrite($handle, "\xEF\xBB\xBF");

        $columns = ['id', 'created_at', 'iso_time', 'actor_type', 'actor_label', 'action',
                    'target_type', 'target_id', 'result', 'detail', 'ip', 'user_agent',
                    'request_id', 'before', 'after', 'prev_hash', 'entry_hash'];
        self::putRow($handle, $columns);

        foreach ($rows as $row) {
            $before = is_array($row['before'] ?? null)
                ? (string)json_encode($row['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '';
            $after = is_array($row['after'] ?? null)
                ? (string)json_encode($row['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '';
            $createdAt = (int)($row['created_at'] ?? 0);
            self::putRow($handle, [
                (string)($row['id'] ?? ''),
                (string)$createdAt,
                $createdAt > 0 ? gmdate('Y-m-d\TH:i:s\Z', $createdAt) : '',
                (string)($row['actor_type'] ?? ''),
                (string)($row['actor_label'] ?? ''),
                (string)($row['action'] ?? ''),
                (string)($row['target_type'] ?? ''),
                (string)($row['target_id'] ?? ''),
                (string)($row['result'] ?? ''),
                (string)($row['detail'] ?? ''),
                (string)($row['ip'] ?? ''),
                (string)($row['user_agent'] ?? ''),
                (string)($row['request_id'] ?? ''),
                $before,
                $after,
                (string)($row['prev_hash'] ?? ''),
                (string)($row['entry_hash'] ?? ''),
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * @param resource             $handle
     * @param array<int,mixed>     $cells
     */
    private static function putRow($handle, array $cells): void
    {
        fputcsv($handle, array_map([self::class, 'neutralizeFormula'], $cells), ',', '"', '\\');
    }

    /** Prefix a spreadsheet-executable cell so it is treated as text. */
    private static function neutralizeFormula(mixed $value): string
    {
        $s = is_scalar($value) || $value === null ? (string)$value : '';
        if ($s === '') { return $s; }
        $first = $s[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
            || $first === "\t" || $first === "\r") {
            return "'" . $s;
        }
        return $s;
    }
}
