<?php
/**
 * WorkerDiagnostics: public, sanitized view of the last worker
 * self-test plus the problems derivable from the public health
 * snapshot. Serves GET /api/health/worker/diagnostics.
 *
 * Two blocks, one endpoint:
 *
 *   1. The **last self-test** ever enqueued (Settings → Worker → Test
 *      writes a `revoap_jobs` row with `payload.kind = 'selftest'` whose
 *      result envelope lands in `result_json`). Only the verdict, the
 *      per-check name/ok/detail/ms tuple and the timings are exposed —
 *      never the raw row, never the payload.
 *
 *   2. **`derived`** — the problems computable from the public
 *      `GET /api/health/worker` snapshot (missing job/event/presence
 *      table, worker not running, stale heartbeat, stale jobs, exhausted
 *      retries), each with a ready-to-render sentence and — where a safe
 *      copy/paste remedy exists — the exact CLI command.
 *
 * Provider hygiene: the self-test's `providers_configured` detail names
 * the active connectors and which of them hold a key. That is fine in the
 * admin-only dump (GET /api/settings/worker/debug) but NOT on a public
 * endpoint, so the detail is rewritten here into counts ("active: 2
 * provider(s); key present: yes") and every remaining connector name or
 * `base_url` host is scrubbed as a second pass. `providers_reachable`
 * (opt-in TCP probe) is masked the same way.
 *
 * `available: false` with an empty check list is a normal, non-error
 * answer: it just means nobody pressed Test yet.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Worker;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use App\Jobs\JobAuditRepository;
use App\Jobs\JobRepository;
use App\Jobs\WorkerHealthService;
use App\Jobs\WorkerSelfTest;
use PDO;
use Throwable;

final class WorkerDiagnostics
{
    /** Rows scanned by the fallback path when the targeted query is unusable. */
    private const FALLBACK_SCAN_LIMIT = 200;

    /** Placeholder substituted for a connector name that survives the rewrite. */
    private const PROVIDER_PLACEHOLDER = '<provider>';

    public function __construct(
        private readonly JobRepository          $jobs,
        private readonly ?WorkerHealthService   $health = null,
        private readonly ?JobAuditRepository    $audit = null,
        private readonly ?Database              $db = null,
    ) {}

    /**
     * Build the response envelope.
     *
     * @param  ?array<string,mixed> $snapshot Health snapshot the `derived`
     *                                        block reasons over. Passed in
     *                                        by a caller that already has
     *                                        one; otherwise it is fetched.
     * @return array<string,mixed>
     */
    public function report(?array $snapshot = null): array
    {
        $snapshot ??= $this->snapshot();

        $selftest = $this->lastSelfTest();

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            // ---- last self-test -------------------------------------
            'available'   => $selftest['available'],
            'job_id'      => $selftest['job_id'],
            'at'          => $selftest['at'],
            'age_s'       => $selftest['age_s'],
            'ok'          => $selftest['ok'],
            'passed'      => $selftest['passed'],
            'failed'      => $selftest['failed'],
            'duration_ms' => $selftest['duration_ms'],
            'checks'      => $selftest['checks'],
            // ---- problems derived from the public snapshot ----------
            'derived'     => self::derive($snapshot, $this->eventsTableAvailable()),
        ];
    }

    /**
     * Last `kind=selftest` job whose result envelope was persisted.
     *
     * @return array{available:bool,job_id:?int,at:?string,age_s:?int,ok:?bool,passed:?int,failed:?int,duration_ms:?int,checks:list<array<string,mixed>>}
     */
    private function lastSelfTest(): array
    {
        $empty = [
            'available'   => false,
            'job_id'      => null,
            'at'          => null,
            'age_s'       => null,
            'ok'          => null,
            'passed'      => null,
            'failed'      => null,
            'duration_ms' => null,
            'checks'      => [],
        ];

        $row = $this->selfTestRow();
        if ($row === null || !is_array($row['result'])) {
            return $empty;
        }

        $result = $row['result'];
        if (!WorkerSelfTest::isSelfTest(['kind' => $result['kind'] ?? null])) {
            // A row matched the payload marker but carries no usable
            // result envelope (truncated write, older format). Treat it
            // as "nothing to show" rather than inventing a verdict.
            return $empty;
        }

        $at = self::nullableStamp($row['finished_at'] ?? null)
            ?? self::nullableStamp($row['created_at'] ?? null);
        $atUnix = $at === null ? null : self::toUnix($at);

        return [
            'available'   => true,
            'job_id'      => (int)($row['id'] ?? 0),
            'at'          => $at,
            'age_s'       => $atUnix === null ? null : max(0, time() - $atUnix),
            'ok'          => ($result['ok'] ?? false) === true,
            'passed'      => max(0, (int)($result['passed'] ?? 0)),
            'failed'      => max(0, (int)($result['failed'] ?? 0)),
            'duration_ms' => isset($result['duration_ms']) && is_numeric($result['duration_ms'])
                ? (int)$result['duration_ms'] : null,
            'checks'      => self::presentChecks($result['checks'] ?? null),
        ];
    }

    /**
     * Locate the newest self-test row. DB path first (a marker query
     * ordered by the primary key, which stops at the first match), then
     * the repository's recent-window scan as a fail-soft fallback for
     * the memory mirror and for any engine that rejects the query.
     *
     * The marker is matched with LIKE rather than JSON_EXTRACT /
     * JSON_UNQUOTE: `payload_json` is written by exactly one code path
     * ({@see \App\Jobs\JobRepository::create()} through
     * {@see \App\Helpers\JsonHelper::encode()} — compact, unescaped), so
     * the literal is stable, and LIKE behaves identically on MySQL,
     * MariaDB and SQLite instead of depending on a JSON function being
     * compiled in. A false positive cannot produce a wrong verdict: the
     * decoded envelope is validated with
     * {@see WorkerSelfTest::isSelfTest()} before anything is returned.
     *
     * @return array{id:int,created_at:?string,finished_at:?string,result:?array<string,mixed>}|null
     */
    private function selfTestRow(): ?array
    {
        if ($this->db !== null) {
            try {
                return $this->db->run(static function (PDO $pdo): ?array {
                    $stmt = $pdo->query(
                        'SELECT id, created_at, finished_at, updated_at, result_json
                         FROM revoap_jobs
                         WHERE result_json IS NOT NULL
                           AND payload_json LIKE \'%"kind":"selftest"%\'
                         ORDER BY id DESC
                         LIMIT 1'
                    );
                    if ($stmt === false) { return null; }
                    $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    if (!is_array($fetched)) { return null; }
                    $decoded = is_string($fetched['result_json'] ?? null)
                        ? JsonHelper::tryDecode((string)$fetched['result_json'], null)
                        : null;
                    return [
                        'id'          => (int)($fetched['id'] ?? 0),
                        'created_at'  => is_scalar($fetched['created_at'] ?? null) ? (string)$fetched['created_at'] : null,
                        'finished_at' => is_scalar($fetched['finished_at'] ?? null) ? (string)$fetched['finished_at'] : null,
                        'result'      => is_array($decoded) ? $decoded : null,
                    ];
                });
            } catch (Throwable) {
                // Fall through to the repository scan.
            }
        }

        try {
            foreach ($this->jobs->listRecent(self::FALLBACK_SCAN_LIMIT) as $job) {
                if (!WorkerSelfTest::isSelfTest($job->payload) || $job->result === null) {
                    continue;
                }
                return [
                    'id'          => $job->id,
                    'created_at'  => $job->createdAt,
                    'finished_at' => $job->finishedAt,
                    'result'      => $job->result,
                ];
            }
        } catch (Throwable) {
            // No usable source — report "no self-test yet".
        }
        return null;
    }

    /**
     * Normalize the persisted check list, masking connector identities.
     *
     * @param  mixed $checks
     * @return list<array{name:string,ok:bool,detail:string,ms:?float}>
     */
    private static function presentChecks(mixed $checks): array
    {
        if (!is_array($checks)) {
            return [];
        }
        $out = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $name = trim((string)($check['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $detail = Logger::sanitizeScalar((string)($check['detail'] ?? ''));
            if (str_starts_with($name, 'providers_')) {
                $detail = self::maskProviders($name, $detail);
            }
            $ms = $check['ms'] ?? null;
            $out[] = [
                'name'   => $name,
                'ok'     => ($check['ok'] ?? false) === true,
                'detail' => $detail,
                'ms'     => is_numeric($ms) ? round((float)$ms, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * Rewrite a provider-facing check detail so it carries counts and
     * yes/no answers instead of connector identities.
     *
     * `providers_configured` is re-rendered deterministically from the
     * counts parsed out of the original sentence; every other
     * `providers_*` detail (the opt-in TCP probe) is reduced to its
     * ok/failed tally. Both are then scrubbed of any connector name or
     * `base_url` host that survived.
     */
    private static function maskProviders(string $name, string $detail): string
    {
        if ($name === 'providers_configured') {
            $active = self::countSegment($detail, 'active:');
            if ($active !== null) {
                $keyed = self::countSegment($detail, 'key present:');
                $missing = self::countSegment($detail, 'active without key:');
                $rewritten = 'active: ' . $active . ' provider(s); key present: '
                    . (($keyed ?? 0) > 0 ? 'yes' : 'no');
                if (str_contains($detail, 'network probe')) {
                    $rewritten .= '; network probe: skipped (no tokens spent)';
                }
                if (($missing ?? 0) > 0) {
                    $rewritten .= '; active without key: ' . $missing . ' provider(s)';
                }
                return self::scrubProviderNames($rewritten);
            }
            if (str_contains($detail, 'no active connector')) {
                // Already name-free.
                return self::scrubProviderNames($detail);
            }
            return self::scrubProviderNames('provider configuration could not be summarized.');
        }

        // Opt-in reachability probe — keep the verdict counts, drop the
        // "provider:host:443" targets.
        $okCount     = preg_match_all('/:443 ok \(/i', $detail) ?: 0;
        $failedCount = preg_match_all('/:443 failed \(/i', $detail) ?: 0;
        if ($okCount + $failedCount > 0) {
            $rewritten = 'probed ' . ($okCount + $failedCount) . ' provider host(s): '
                . $okCount . ' reachable, ' . $failedCount . ' unreachable — '
                . 'TCP connect only, no request sent, no tokens spent.';
            if ($failedCount > 0 && str_contains($detail, 'no provider base_url')) {
                $rewritten .= ' (base_url missing for some connectors)';
            }
            return self::scrubProviderNames($rewritten);
        }
        if (str_contains($detail, 'no provider base_url')) {
            return 'no provider base_url to probe.';
        }
        return self::scrubProviderNames('provider reachability probe returned no comparable result.');
    }

    /**
     * Count the comma-separated entries of a `label: a, b, c;` segment.
     * Returns null when the label is absent; 0 for an explicit
     * `none`/empty list.
     */
    private static function countSegment(string $detail, string $label): ?int
    {
        $pos = stripos($detail, $label);
        if ($pos === false) {
            return null;
        }
        $rest = substr($detail, $pos + strlen($label));
        // Stop at the next separator so `active:` cannot swallow the
        // `active without key:` tail.
        $end = strlen($rest);
        foreach ([';', '—', '(', ')'] as $stop) {
            $hit = strpos($rest, $stop);
            if ($hit !== false && $hit < $end) {
                $end = $hit;
            }
        }
        $segment = trim(substr($rest, 0, $end), " \t\n\r\0\x0B.");
        if ($segment === '' || strcasecmp($segment, 'none') === 0) {
            return 0;
        }
        return count(array_filter(
            array_map('trim', explode(',', $segment)),
            static fn(string $part): bool => $part !== '',
        ));
    }

    /**
     * Second-pass redaction: no connector name and no connector host may
     * appear in a public detail, whatever the summary above produced.
     */
    private static function scrubProviderNames(string $detail): string
    {
        foreach (self::providerTokens() as $token) {
            if (stripos($detail, $token) !== false) {
                $detail = (string)preg_replace(
                    '/' . preg_quote($token, '/') . '/i',
                    self::PROVIDER_PLACEHOLDER,
                    $detail,
                );
            }
        }
        return $detail;
    }

    /**
     * Connector identifiers and hosts that must never leave a public
     * endpoint: the configured connector keys plus the host of every
     * configured `base_url`. Short tokens (< 3 chars) are skipped —
     * scrubbing a 2-letter substring would mangle unrelated words.
     *
     * @return list<string>
     */
    private static function providerTokens(): array
    {
        $tokens = [];
        try {
            $connectors = Config::get('connectors', []);
        } catch (Throwable) {
            return $tokens;
        }
        if (!is_array($connectors)) {
            return $tokens;
        }
        foreach ($connectors as $name => $cfg) {
            if (is_string($name) && strlen($name) >= 3) {
                $tokens[] = $name;
            }
            if (!is_array($cfg)) {
                continue;
            }
            $baseUrl = $cfg['base_url'] ?? null;
            if (!is_string($baseUrl) || $baseUrl === '') {
                continue;
            }
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if (is_string($host) && strlen($host) >= 3) {
                $tokens[] = $host;
                // `api.bothub.chat` → also scrub the registrable label.
                $parts = explode('.', $host);
                if (count($parts) >= 2 && strlen($parts[0]) >= 3) {
                    $tokens[] = $parts[0];
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Problems derivable from the public health snapshot (plus the audit
     * table probe, which the snapshot does not carry). Every entry is
     * render-ready: a sentence plus, where one exists, the exact command.
     *
     * @param  array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function derive(array $snapshot, bool $eventsTableAvailable): array
    {
        $jobsTable    = ($snapshot['jobs_table_available'] ?? false) === true;
        $presence     = ($snapshot['presence_table_available'] ?? false) === true;
        $migration    = ($snapshot['migration_applied'] ?? false) === true;
        $workerStatus = (string)($snapshot['worker_status'] ?? 'unknown');
        $staleJobs    = max(0, (int)($snapshot['stale_jobs'] ?? 0));
        $exhausted    = max(0, (int)($snapshot['retry_exhausted'] ?? 0));
        $scheduled    = max(0, (int)($snapshot['retry_scheduled'] ?? 0));
        $nextRetryAt  = $snapshot['next_retry_at'] ?? null;
        $commands     = self::commands();

        $problems = [];

        if (!$jobsTable) {
            // Single root cause: without the queue every dependent probe
            // (events table, presence table, worker classification) fails
            // for the same reason, and listing four variants of "the
            // schema is not imported" buries the one action that fixes it.
            $problems[] = [
                'code'     => 'jobs_table_missing',
                'severity' => 'error',
                'message'  => 'The durable job queue table (revoap_jobs) does not exist, so the worker has nothing '
                    . 'to pick up. Import db/schema.sql (Settings → Database → ReCreate DB, or the mysql CLI).',
                'command'  => 'mysql -u <user> -p <database> < db/schema.sql',
            ];
        } else {
            if (!$migration) {
                $problems[] = [
                    'code'     => 'migration_not_applied',
                    'severity' => 'error',
                    'message'  => 'The job-queue migration has not been applied on this database — apply '
                        . 'db/schema.sql or Settings → Database → Apply Migrations.',
                    'command'  => 'mysql -u <user> -p <database> < db/schema.sql',
                ];
            }
            if (!$eventsTableAvailable) {
                $problems[] = [
                    'code'     => 'events_table_missing',
                    'severity' => 'error',
                    'message'  => 'The job audit table (revoap_job_events) is missing, so the retry/recovery '
                        . 'timeline cannot be recorded. Apply migration 013 (Settings → Database → Apply Migrations).',
                    'command'  => null,
                ];
            }
            if (!$presence) {
                $problems[] = [
                    'code'     => 'presence_table_missing',
                    'severity' => 'warning',
                    'message'  => 'The worker presence table (revoap_worker_presence) is missing, so worker uptime, '
                        . 'version and "what is it doing now" stay unknown. Apply migration 017_worker_presence.sql '
                        . '(Settings → Database → Apply Migrations) and restart the worker.',
                    'command'  => null,
                ];
            }

            if ($workerStatus === 'not_running') {
                $problems[] = [
                    'code'     => 'worker_not_running',
                    'severity' => 'error',
                    'message'  => 'No worker process is running, so queued jobs are not being processed. Start it:',
                    'command'  => $commands['systemd_start'],
                ];
            } elseif ($workerStatus === 'stale') {
                $problems[] = [
                    'code'     => 'worker_stale',
                    'severity' => 'error',
                    'message'  => 'The last worker heartbeat is older than the stale threshold — the process is stuck '
                        . 'or dead. Restart it and watch the journal:',
                    'command'  => $commands['systemd_restart'],
                ];
            } elseif ($workerStatus === 'unknown') {
                $problems[] = [
                    'code'     => 'worker_status_unknown',
                    'severity' => 'error',
                    'message'  => 'The worker state could not be determined from the health snapshot. '
                        . 'Check the worker process directly:',
                    'command'  => $commands['status'],
                ];
            }
        }

        if ($staleJobs > 0) {
            $problems[] = [
                'code'     => 'stale_jobs',
                'severity' => 'error',
                'message'  => $staleJobs . ' job(s) hold a lapsed heartbeat (status=stale). The auto-recovery sweep '
                    . 'reclaims them, but recurring staleness means the worker is dying mid-run — check the journal:',
                'command'  => $commands['journal_tail'],
            ];
        }
        if ($exhausted > 0) {
            $problems[] = [
                'code'     => 'retry_exhausted',
                'severity' => 'error',
                'message'  => $exhausted . ' job(s) exhausted the retry budget (exhausted_retries=true) and will not '
                    . 'be retried automatically. Inspect them in History and re-queue manually.',
                'command'  => null,
            ];
        }

        return [
            'jobs_table_available'     => $jobsTable,
            'events_table_available'   => $eventsTableAvailable,
            'presence_table_available' => $presence,
            'migration_applied'        => $migration,
            'worker_status'            => $workerStatus,
            'stale_jobs'               => $staleJobs,
            'retry_exhausted'          => $exhausted,
            'retry_scheduled'          => $scheduled,
            'next_retry_at'            => is_string($nextRetryAt) && $nextRetryAt !== '' ? $nextRetryAt : null,
            'problems'                 => $problems,
            'ok'                       => $problems === [],
        ];
    }

    /** @return array<string,string> */
    private static function commands(): array
    {
        return WorkerHealthService::commands();
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        if ($this->health === null) {
            return [];
        }
        try {
            return $this->health->snapshot();
        } catch (Throwable) {
            return [];
        }
    }

    private function eventsTableAvailable(): bool
    {
        try {
            return $this->audit?->tableAvailable() ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function nullableStamp(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $string = trim((string)$value);
        return $string === '' ? null : $string;
    }

    private static function toUnix(string $ts): ?int
    {
        $t = strtotime($ts);
        return is_int($t) && $t > 0 ? $t : null;
    }
}
