<?php
/**
 * HistoryService: read-only access to past council sessions.
 *
 * Backs the History admin tab. Performs three jobs:
 *   1. Paginated, filtered listing of council_sessions for the
 *      sidebar list view (`list`). Pagination is offset-based with
 *      a hard cap on `limit`.
 *   2. Server-side grouping by year/month so the UI can render the
 *      list with section headers without making the client iterate
 *      through every row to compute the bucket.
 *   3. Full session detail (`detail`) — joins council_sessions with
 *      council_steps, council_model_responses, pipeline_events and
 *      skills so the UI can render every L0/L1/L2/L3/L4/Final step
 *      and every model response in one round-trip.
 *
 * The service NEVER returns API keys, DSN credentials, Bearer tokens
 * or absolute project paths. Free-form text fields (query, final
 * answer, raw model output, payload) are passed through
 * Logger::sanitizeScalar to scrub anything that looks like a secret
 * before it leaves the host.
 *
 * When the database is not configured, every method returns a
 * documented "no_database" result so the controller can render a
 * clear diagnostic instead of a generic 500.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\History;

use App\Batch\BatchState;
use App\Core\Database;
use App\Core\Logger;
use App\Diagnostics\RunDiagnosis;
use App\Jobs\JobRepository;
use PDO;

final class HistoryService
{
    public const LIMIT_DEFAULT = 25;
    public const LIMIT_MAX     = 100;
    public const QUERY_PREVIEW = 220;
    public const ANSWER_PREVIEW = 360;
    public const SEARCH_MAX_LEN = 256;

    /**
     * @param JobRepository|null $jobs Optional durable-job reader. History
     *        asks it one question — "is this session an asynchronous batch
     *        run, and if so what is its phase" — so a batch session can read
     *        as "awaiting the provider" instead of as a stalled `running`
     *        row. Null (the default, and how the offline tests build this
     *        service) means the enrichment is skipped entirely: History then
     *        behaves exactly as it did before, which is what keeps the
     *        feature additive.
     */
    public function __construct(
        private readonly null|PDO|Database $db,
        private readonly ?JobRepository $jobs = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->db !== null;
    }

    /**
     * Expose the underlying connection so callers (controllers, the
     * health endpoint) can produce a sanitized DatabaseHealth snapshot.
     * The wrapper type is preserved — DatabaseHealth understands both.
     */
    public function db(): null|PDO|\App\Core\Database
    {
        return $this->db;
    }

    /**
     * Whether the new history columns from migration 004 are present.
     * Used by the controller to surface a clear "migration required"
     * diagnostic rather than a generic SQL error on hosts that have
     * a database but never applied 004_history_session_metadata.sql.
     */
    public function isMigrated(): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            return (bool)$this->run(static function (PDO $pdo): bool {
                $driver = '';
                try {
                    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                } catch (\Throwable) {
                    $driver = '';
                }
                if ($driver === 'sqlite') {
                    $stmt = $pdo->query("PRAGMA table_info(council_sessions)");
                    if ($stmt === false) { return false; }
                    $rows = $stmt->fetchAll();
                    if (!is_array($rows)) { return false; }
                    foreach ($rows as $row) {
                        if (!is_array($row)) { continue; }
                        if (strcasecmp((string)($row['name'] ?? ''), 'options_json') === 0) {
                            return true;
                        }
                    }
                    return false;
                }
                if ($driver === 'mysql') {
                    $stmt = $pdo->query("SHOW COLUMNS FROM council_sessions LIKE 'options_json'");
                    if ($stmt === false) { return false; }
                    $rows = $stmt->fetchAll();
                    return is_array($rows) && count($rows) > 0;
                }
                // Generic fallback: attempt a zero-row SELECT against the
                // column. Succeeds on any SQL dialect that recognises the
                // column; throws otherwise and yields false.
                try {
                    $stmt = $pdo->query('SELECT options_json FROM council_sessions WHERE 1=0');
                    if ($stmt === false) { return false; }
                    $stmt->fetchAll();
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Tables this service expects to read. Used by schemaDiagnostics()
     * to enumerate what is missing on hosts that imported an outdated
     * schema.sql or skipped a migration. Order matches the read path:
     * council_sessions is the entry point; the rest hang off it.
     *
     * @return array<int,string>
     */
    public static function requiredTables(): array
    {
        return [
            'skills',
            'council_sessions',
            'council_steps',
            'council_model_responses',
            'pipeline_events',
        ];
    }

    /**
     * Paginated session listing with optional full-text search.
     *
     * @param array{
     *   limit?:int,
     *   offset?:int,
     *   q?:string,
     *   status?:string,
     * } $params
     *
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   message?:string,
     *   total:int,
     *   limit:int,
     *   offset:int,
     *   has_more:bool,
     *   q:string,
     *   status:?string,
     *   items:array<int,array<string,mixed>>,
     *   groups:array<int,array{key:string,label:string,count:int}>,
     * }
     */
    public function list(array $params): array
    {
        if ($this->db === null) {
            return $this->emptyList(self::LIMIT_DEFAULT, 0, '', null, [
                'ok'      => false,
                'error'   => 'no_database',
                'message' => 'Persistent history requires a configured database. Open the Settings tab to configure the DSN, then apply migrations.',
            ]);
        }
        $limit  = max(1, min(self::LIMIT_MAX, (int)($params['limit']  ?? self::LIMIT_DEFAULT)));
        $offset = max(0, (int)($params['offset'] ?? 0));
        $q      = trim((string)($params['q'] ?? ''));
        if (function_exists('mb_substr')) {
            $q = mb_substr($q, 0, self::SEARCH_MAX_LEN);
        } else {
            $q = substr($q, 0, self::SEARCH_MAX_LEN);
        }
        $statusRaw = trim((string)($params['status'] ?? ''));
        $status = in_array($statusRaw, ['running', 'completed', 'failed'], true) ? $statusRaw : null;

        $where  = [];
        $bind   = [];
        if ($q !== '') {
            // We use LIKE rather than MATCH AGAINST so that very short
            // tokens (typical when an operator types a partial word)
            // still match — InnoDB's default ngram_token_size is 2 and
            // many hosts skip the FT index altogether on small tables.
            $where[] = '(query_text LIKE :q OR final_answer LIKE :q OR skill_slug LIKE :q)';
            $bind['q'] = '%' . $q . '%';
        }
        if ($status !== null) {
            $where[] = 'status = :status';
            $bind['status'] = $status;
        }
        $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

        try {
            $total = (int)$this->run(static function (PDO $pdo) use ($whereSql, $bind): int {
                $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM council_sessions ' . $whereSql);
                $stmt->execute($bind);
                $row = $stmt->fetch();
                return is_array($row) ? (int)($row['c'] ?? 0) : 0;
            });

            $rows = $this->run(static function (PDO $pdo) use ($whereSql, $bind, $limit, $offset): array {
                $sql = 'SELECT cs.id, cs.skill_id, cs.skill_slug, cs.query_text, cs.final_answer,
                               cs.consensus_score, cs.consensus_status, cs.status,
                               cs.total_tokens, cs.duration_ms, cs.options_json,
                               cs.created_at, cs.completed_at,
                               s.slug AS skill_slug_join, s.name AS skill_name
                        FROM council_sessions cs
                        LEFT JOIN skills s ON s.id = cs.skill_id
                        ' . $whereSql . '
                        ORDER BY cs.id DESC
                        LIMIT :lim OFFSET :off';
                $stmt = $pdo->prepare($sql);
                foreach ($bind as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
                $stmt->bindValue('off', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $rs = $stmt->fetchAll();
                return is_array($rs) ? $rs : [];
            });

            $groupCounts = $this->run(static function (PDO $pdo) use ($whereSql, $bind): array {
                $driver = '';
                try {
                    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                } catch (\Throwable) {
                    $driver = '';
                }
                // SUBSTR(created_at, 1, 7) yields YYYY-MM on every dialect
                // that stores timestamps in ISO/MySQL canonical form and
                // sidesteps DATE_FORMAT (MySQL-only) vs strftime (SQLite).
                $bucketExpr = $driver === 'mysql'
                    ? "DATE_FORMAT(created_at, '%Y-%m')"
                    : "SUBSTR(created_at, 1, 7)";
                $sql = "SELECT {$bucketExpr} AS bucket, COUNT(*) AS c
                        FROM council_sessions
                        " . $whereSql . "
                        GROUP BY bucket
                        ORDER BY bucket DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bind);
                $rs = $stmt->fetchAll();
                return is_array($rs) ? $rs : [];
            });
        } catch (\Throwable $e) {
            return $this->emptyList($limit, $offset, $q, $status, [
                'ok'      => false,
                'error'   => 'history_query_failed',
                'message' => Logger::sanitizeScalar('History query failed: ' . $e->getMessage()),
            ]);
        }

        $items = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $items[] = $this->withBatchState($this->buildListItem($r));
        }

        $groups = [];
        foreach (is_array($groupCounts) ? $groupCounts : [] as $g) {
            if (!is_array($g)) { continue; }
            $bucket = (string)($g['bucket'] ?? '');
            if ($bucket === '') { continue; }
            $groups[] = [
                'key'   => $bucket,
                'label' => $this->formatBucketLabel($bucket),
                'count' => (int)($g['c'] ?? 0),
            ];
        }

        return [
            'ok'       => true,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => $offset + count($items) < $total,
            'q'        => $q,
            'status'   => $status,
            'items'    => $items,
            'groups'   => $groups,
        ];
    }

    /**
     * Full detail for one session — header, ordered steps, model
     * responses per step, ordered events. Returns null when no row
     * matches (the controller maps that to HTTP 404).
     *
     * @return array<string,mixed>|null
     */
    public function detail(int $sessionId): ?array
    {
        if ($this->db === null) {
            return [
                'ok'      => false,
                'error'   => 'no_database',
                'message' => 'Persistent history requires a configured database.',
            ];
        }
        try {
            $session = $this->run(static function (PDO $pdo) use ($sessionId): ?array {
                $stmt = $pdo->prepare('SELECT cs.*, s.slug AS skill_slug_join, s.name AS skill_name
                                       FROM council_sessions cs
                                       LEFT JOIN skills s ON s.id = cs.skill_id
                                       WHERE cs.id = :id LIMIT 1');
                $stmt->execute(['id' => $sessionId]);
                $row = $stmt->fetch();
                return is_array($row) ? $row : null;
            });
            if ($session === null) {
                return null;
            }
            $steps = $this->run(static function (PDO $pdo) use ($sessionId): array {
                $stmt = $pdo->prepare('SELECT * FROM council_steps WHERE session_id = :sid
                                       ORDER BY order_index ASC, id ASC');
                $stmt->execute(['sid' => $sessionId]);
                $rs = $stmt->fetchAll();
                return is_array($rs) ? $rs : [];
            });
            $stepIds = array_map(static fn(array $s): int => (int)($s['id'] ?? 0), $steps);
            $responsesByStep = [];
            if ($stepIds !== []) {
                $place = implode(',', array_fill(0, count($stepIds), '?'));
                $rows = $this->run(static function (PDO $pdo) use ($stepIds, $place): array {
                    $stmt = $pdo->prepare('SELECT * FROM council_model_responses
                                           WHERE step_id IN (' . $place . ')
                                           ORDER BY id ASC');
                    foreach ($stepIds as $i => $sid) {
                        $stmt->bindValue($i + 1, $sid, PDO::PARAM_INT);
                    }
                    $stmt->execute();
                    $rs = $stmt->fetchAll();
                    return is_array($rs) ? $rs : [];
                });
                foreach (is_array($rows) ? $rows : [] as $r) {
                    if (!is_array($r)) { continue; }
                    $sid = (int)($r['step_id'] ?? 0);
                    $responsesByStep[$sid][] = $this->scrubResponse($r);
                }
            }
            $events = $this->run(static function (PDO $pdo) use ($sessionId): array {
                $stmt = $pdo->prepare('SELECT * FROM pipeline_events WHERE session_id = :sid
                                       ORDER BY id ASC');
                $stmt->execute(['sid' => $sessionId]);
                $rs = $stmt->fetchAll();
                return is_array($rs) ? $rs : [];
            });
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'error'   => 'history_detail_failed',
                'message' => Logger::sanitizeScalar('History detail failed: ' . $e->getMessage()),
            ];
        }

        $stepsOut = [];
        foreach ($steps as $s) {
            if (!is_array($s)) { continue; }
            $sid = (int)($s['id'] ?? 0);
            $stepsOut[] = $this->buildStepEntry($s, $responsesByStep[$sid] ?? []);
        }
        $eventsOut = [];
        foreach (is_array($events) ? $events : [] as $e) {
            if (!is_array($e)) { continue; }
            $eventsOut[] = $this->scrubEvent($e);
        }

        $header = $this->withBatchState($this->buildHeader($session));
        // Prompt-cache roll-up for the run, derived from the per-step meta
        // the pipeline already wrote. Derived here rather than stored on
        // council_sessions on purpose: the cache figures are additive
        // statistics, and a second copy on the session row would be one
        // more thing that can disagree with the steps it summarizes.
        $header['cache'] = $this->cacheRollup($stepsOut);

        // R2 - the diagnosis the History screen shows. Everything it needs is
        // already loaded: the session row, and the events - including the terminal
        // verdict the engine recorded when the run ended.
        $donePayload = [];
        foreach (is_array($events) ? $events : [] as $event) {
            if (!is_array($event) || (string)($event['event_type'] ?? '') !== 'PIPELINE_DONE') { continue; }
            $decoded = json_decode((string)($event['payload'] ?? ''), true);
            if (is_array($decoded)) { $donePayload = $decoded; }
        }
        $l1Outcome = is_array($donePayload['l1_outcome'] ?? null) ? $donePayload['l1_outcome'] : [];
        // R2 - read the system facts once, defensively: a database without the
        // presence table, or a checkout without db/schema.sql, leaves them unknown
        // rather than failing the whole detail.
        $systemFacts = ['code_state' => 'unknown', 'schema_live' => null, 'schema_expected' => null];
        try {
            $parsed = \App\Settings\VersionParser::parse((string)@file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql'));
            $systemFacts['schema_expected'] = is_string($parsed['schema'] ?? null) ? $parsed['schema'] : null;
            $systemFacts['schema_live'] = $this->run(static function (PDO $pdo): string {
                $stmt = $pdo->prepare('SELECT config_value FROM app_config WHERE config_key = :k LIMIT 1');
                $stmt->execute(['k' => \App\Settings\ConfigRepository::SCHEMA_KEY]);
                $row = $stmt->fetch();
                return is_array($row) ? (string)($row['config_value'] ?? '') : '';
            }) ?: null;
            $workerVersion = $this->run(static function (PDO $pdo): string {
                $stmt = $pdo->query('SELECT app_version FROM revoap_worker_presence ORDER BY last_seen_at DESC LIMIT 1');
                $row = $stmt === false ? false : $stmt->fetch();
                return is_array($row) ? (string)($row['app_version'] ?? '') : '';
            });
            $deployed = (string)\App\Core\Config::get('app.version', '');
            $systemFacts['code_state'] = ($workerVersion !== '' && $deployed !== '' && $workerVersion !== $deployed) ? 'stale' : 'current';
        } catch (\Throwable) {
            // stays unknown
        }

        $diagnosis = RunDiagnosis::fromFacts([
            'status'        => (string)($header['status'] ?? ''),
            'status_reason' => (string)($donePayload['status_reason'] ?? ''),
            'status_detail' => (string)($donePayload['status_detail'] ?? ''),
            'missing'       => is_array($l1Outcome['missing'] ?? null) ? $l1Outcome['missing'] : [],
            'breaker'       => is_array($l1Outcome['breaker'] ?? null) ? $l1Outcome['breaker'] : [],
            'preflight'     => is_array($donePayload['preflight'] ?? null) ? $donePayload['preflight'] : null,
            // R2 - the two system facts the diagnosis checks before any run cause:
            // the version the running worker reported against the deployed one, and
            // the schema version on record against the one db/schema.sql declares.
            // Both come from what is already stored; a failed read leaves them unknown.
            'code_state'      => $systemFacts['code_state'],
            'schema_live'     => $systemFacts['schema_live'],
            'schema_expected' => $systemFacts['schema_expected'],
            'session_id'    => $sessionId,
            // R2 - the evidence the status may disagree with.
            'failed_steps'  => array_values(array_filter(array_map(
                static fn(array $step): array => [
                    'step_key' => (string)($step['step_key'] ?? ''),
                    'summary'  => (string)($step['summary'] ?? ''),
                ],
                array_filter(is_array($steps) ? $steps : [], static fn(mixed $step): bool => is_array($step) && (string)($step['status'] ?? '') === 'failed'),
            ))),
        ]);

        return [
            'ok'        => true,
            'session'   => $header,
            'steps'     => $stepsOut,
            'events'    => $eventsOut,
            'diagnosis' => $diagnosis,
        ];
    }

    /**
     * Aggregate the prompt-cache figures a run's steps carry.
     *
     * Reads the same keys the pipeline writes into `council_steps.meta`
     * (`ChatResponse::statsPayload()`): `cached_tokens` is the read side in
     * either dialect, `cache_write_tokens` the write side, `cache_discount`
     * Bothub's saving.
     *
     * The shape mirrors `SessionStats::toArray()`'s cache keys so the
     * History header and the Chat session panel can share one renderer
     * (`assets/js/prompt-cache.js`). `cache_reported_calls` is the
     * denominator that keeps the numbers honest: a run in which no step
     * reported cache statistics returns zeroes with
     * `cache_reported_calls = 0`, which the renderer shows as "no data"
     * rather than "the cache never fired".
     *
     * @param  array<int,array<string,mixed>> $steps
     * @return array<string,mixed>
     */
    private function cacheRollup(array $steps): array
    {
        $read = 0;
        $write = 0;
        $calls = 0;
        $reported = 0;
        $totalCalls = 0;
        $discount = 0.0;
        $discountSeen = false;

        foreach ($steps as $step) {
            $meta = $step['meta'] ?? null;
            if (!is_array($meta)) { continue; }
            $hasRead  = array_key_exists('cached_tokens', $meta) && $meta['cached_tokens'] !== null;
            $hasWrite = array_key_exists('cache_write_tokens', $meta) && $meta['cache_write_tokens'] !== null;
            $hasDisc  = array_key_exists('cache_discount', $meta) && is_numeric($meta['cache_discount']);
            // A step that reached a provider is what makes this a "call";
            // L2/L3 steps carry no provider stats and are not cache misses.
            if (!isset($meta['provider']) && !isset($meta['prompt_tokens']) && !isset($meta['total_tokens'])) {
                continue;
            }

            $totalCalls++;
            if ($hasRead || $hasWrite || $hasDisc || ($meta['cache_stats'] ?? false) === true) {
                $reported++;
            }
            if ($hasRead) {
                $value = (int)$meta['cached_tokens'];
                $read += $value;
                if ($value > 0) { $calls++; }
            }
            if ($hasWrite)  { $write += (int)$meta['cache_write_tokens']; }
            if ($hasDisc) {
                $discountSeen = true;
                $discount += (float)$meta['cache_discount'];
            }
        }

        return [
            'total_calls'              => $totalCalls,
            'cache_calls'              => $calls,
            'cache_reported_calls'     => $reported,
            'cache_read_tokens'        => $read,
            'cache_write_tokens'       => $write,
            'cache_discount'           => $discountSeen ? $discount : null,
            'cache_discount_available' => $discountSeen,
        ];
    }

    /**
     * Attach the batch run state, when this session has one.
     *
     * Purely additive: a session with no durable batch job comes back
     * unchanged, and a session whose job predates the batch feature (or
     * whose `result_json` carries no `batch` block) comes back unchanged
     * too — {@see BatchState::fromJobResult()} reports the fresh phase for
     * an empty block, so the guard has to ask the job payload whether it is
     * a batch job at all, not whether state exists.
     *
     * The keys added:
     *   * `run_mode`   — 'batch' or absent; drives the History pill.
     *   * `batch`      — the same browser-safe snapshot the Chat card
     *                    renders, so both screens describe one run
     *                    identically.
     *   * `job_status` — the durable job status, which is where
     *                    `queued`-between-polls is visible. The session
     *                    column cannot carry that: its enum only knows
     *                    running/completed/failed, and the session really is
     *                    `running` (not finished, not failed) the whole time
     *                    a batch is outstanding.
     *
     * Best-effort: any failure here degrades to the plain History row. A
     * History page must never fail because the job layer could not be read.
     *
     * @param  array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function withBatchState(array $item): array
    {
        if ($this->jobs === null || !isset($item['id'])) {
            return $item;
        }
        $sessionId = (int)$item['id'];
        if ($sessionId <= 0) {
            return $item;
        }
        try {
            $job = $this->jobs->findBySessionId($sessionId);
        } catch (\Throwable) {
            return $item;
        }
        if ($job === null || !\App\Batch\BatchPipeline::isBatchPayload($job->payload)) {
            return $item;
        }
        $item['run_mode']   = 'batch';
        $item['job_id']     = $job->id;
        $item['job_status'] = $job->status->value;
        $item['batch']      = BatchState::fromJobResult($job->result)->toPublicArray();
        return $item;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildListItem(array $row): array
    {
        $query  = (string)($row['query_text']   ?? '');
        $answer = (string)($row['final_answer'] ?? '');
        $opts   = $this->decodeJson($row['options_json'] ?? null);
        $modelCount = 0;
        if (is_array($opts) && isset($opts['selected_models']) && is_array($opts['selected_models'])) {
            $modelCount = count($opts['selected_models']);
        }
        $skillSlug = (string)($row['skill_slug_join'] ?? $row['skill_slug'] ?? '');
        $skillName = (string)($row['skill_name'] ?? '');
        $createdAt = (string)($row['created_at'] ?? '');
        return [
            'id'               => (int)($row['id'] ?? 0),
            'created_at'       => $createdAt,
            'bucket'           => $this->bucketFor($createdAt),
            'completed_at'     => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
            'status'           => (string)($row['status'] ?? ''),
            'consensus_status' => (string)($row['consensus_status'] ?? ''),
            'consensus_score'  => $row['consensus_score'] !== null ? (float)$row['consensus_score'] : null,
            'total_tokens'     => (int)($row['total_tokens'] ?? 0),
            'duration_ms'      => (int)($row['duration_ms'] ?? 0),
            'skill_slug'       => $skillSlug,
            'skill_name'       => $skillName,
            'query_preview'    => $this->preview(Logger::sanitizeScalar($query), self::QUERY_PREVIEW),
            'answer_preview'   => $answer === '' ? '' : $this->preview(Logger::sanitizeScalar($answer), self::ANSWER_PREVIEW),
            'model_count'      => $modelCount,
            'files'            => is_array($opts) && isset($opts['attachments']) && is_array($opts['attachments'])
                                    ? count($opts['attachments']) : 0,
            'reasoning'        => is_array($opts) && isset($opts['reasoning']) && is_string($opts['reasoning'])
                                    ? $opts['reasoning'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildHeader(array $row): array
    {
        $opts = $this->decodeJson($row['options_json'] ?? null);
        return [
            'id'               => (int)($row['id'] ?? 0),
            'created_at'       => (string)($row['created_at'] ?? ''),
            'completed_at'     => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
            'status'           => (string)($row['status'] ?? ''),
            'consensus_status' => (string)($row['consensus_status'] ?? ''),
            'consensus_score'  => $row['consensus_score'] !== null ? (float)$row['consensus_score'] : null,
            'total_tokens'     => (int)($row['total_tokens'] ?? 0),
            'duration_ms'      => (int)($row['duration_ms'] ?? 0),
            'skill_slug'       => (string)($row['skill_slug_join'] ?? $row['skill_slug'] ?? ''),
            'skill_name'       => (string)($row['skill_name'] ?? ''),
            'query_text'       => Logger::sanitizeScalar((string)($row['query_text'] ?? '')),
            'final_answer'     => Logger::sanitizeScalar((string)($row['final_answer'] ?? '')),
            'options'          => is_array($opts) ? $opts : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $responses
     * @return array<string,mixed>
     */
    private function buildStepEntry(array $row, array $responses): array
    {
        $meta = $this->decodeJson($row['meta'] ?? null);
        return [
            'id'          => (int)($row['id'] ?? 0),
            'step_key'    => (string)($row['step_key'] ?? ''),
            'label'       => (string)($row['label'] ?? ''),
            'order_index' => (int)($row['order_index'] ?? 0),
            'status'      => (string)($row['status'] ?? ''),
            'summary'     => $row['summary'] !== null
                                ? Logger::sanitizeScalar((string)$row['summary']) : null,
            'meta'        => is_array($meta) ? $this->scrubArray($meta) : null,
            'started_at'  => (string)($row['started_at'] ?? ''),
            'ended_at'    => $row['ended_at'] !== null ? (string)$row['ended_at'] : null,
            'responses'   => $responses,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function scrubResponse(array $row): array
    {
        $normalized = $this->decodeJson($row['normalized_response'] ?? null);
        return [
            'id'                => (int)($row['id'] ?? 0),
            'step_id'           => (int)($row['step_id'] ?? 0),
            'model'             => (string)($row['model'] ?? ''),
            'provider'          => (string)($row['provider'] ?? ''),
            'role_name'         => $row['role_name'] !== null ? (string)$row['role_name'] : null,
            'raw_content'       => Logger::sanitizeScalar((string)($row['raw_content'] ?? '')),
            'normalized'        => is_array($normalized) ? $this->scrubArray($normalized) : null,
            'prompt_tokens'     => (int)($row['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($row['completion_tokens'] ?? 0),
            'total_tokens'      => (int)($row['total_tokens'] ?? 0),
            'latency_ms'        => (int)($row['latency_ms'] ?? 0),
            'tokens_estimated'  => (int)($row['tokens_estimated'] ?? 0) === 1,
            // Prompt cache. Deliberately null-preserving: `(int)null` would
            // turn "the provider reported nothing" into a reported 0, which
            // is the one thing these three columns exist to distinguish.
            // `?? null` also covers a database that has not applied
            // db/migrations/018 yet, where the keys are simply absent.
            'cache_read_tokens'  => isset($row['cache_read_tokens']) && $row['cache_read_tokens'] !== null
                                        ? (int)$row['cache_read_tokens'] : null,
            'cache_write_tokens' => isset($row['cache_write_tokens']) && $row['cache_write_tokens'] !== null
                                        ? (int)$row['cache_write_tokens'] : null,
            'cache_discount'     => isset($row['cache_discount']) && is_numeric($row['cache_discount'])
                                        ? (float)$row['cache_discount'] : null,
            'created_at'        => (string)($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function scrubEvent(array $row): array
    {
        $payload = $this->decodeJson($row['payload'] ?? null);
        return [
            'id'         => (int)($row['id'] ?? 0),
            'step_id'    => $row['step_id'] !== null ? (int)$row['step_id'] : null,
            'event_type' => (string)($row['event_type'] ?? ''),
            'payload'    => is_array($payload) ? $this->scrubArray($payload) : null,
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    /**
     * Recursively sanitize every scalar string inside a decoded JSON tree.
     *
     * @param array<int|string,mixed> $arr
     * @return array<int|string,mixed>
     */
    private function scrubArray(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->scrubArray($v);
            } elseif (is_string($v)) {
                $out[$k] = Logger::sanitizeScalar($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function decodeJson(mixed $raw): mixed
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function preview(string $text, int $max): string
    {
        $text = trim($text);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $len = mb_strlen($text);
            if ($len <= $max) { return $text; }
            return rtrim(mb_substr($text, 0, $max)) . '…';
        }
        if (strlen($text) <= $max) { return $text; }
        return rtrim(substr($text, 0, $max)) . '…';
    }

    private function bucketFor(string $createdAt): string
    {
        if ($createdAt === '') { return ''; }
        return substr($createdAt, 0, 7); // YYYY-MM
    }

    private function formatBucketLabel(string $bucket): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $bucket, $m) !== 1) {
            return $bucket;
        }
        $names = [
            '01' => 'January', '02' => 'February', '03' => 'March',
            '04' => 'April',   '05' => 'May',      '06' => 'June',
            '07' => 'July',    '08' => 'August',   '09' => 'September',
            '10' => 'October', '11' => 'November', '12' => 'December',
        ];
        $month = $names[$m[2]] ?? $m[2];
        return $month . ' ' . $m[1];
    }

    /**
     * @template T
     * @param  callable(PDO):T $fn
     * @return T
     */
    private function run(callable $fn): mixed
    {
        if ($this->db instanceof Database) {
            return $this->db->run($fn);
        }
        if ($this->db instanceof PDO) {
            return $fn($this->db);
        }
        throw new \RuntimeException('history database is not configured');
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function emptyList(int $limit, int $offset, string $q, ?string $status, array $extra): array
    {
        return array_merge([
            'ok'       => true,
            'total'    => 0,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => false,
            'q'        => $q,
            'status'   => $status,
            'items'    => [],
            'groups'   => [],
        ], $extra);
    }
}
