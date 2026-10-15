<?php
/**
 * WorkerSelfTest: end-to-end proof that the web → DB → worker → result
 * chain works, without spending a single model token.
 *
 * The Settings → Worker card's "Test" button enqueues a normal
 * `revoap_jobs` row whose payload carries `kind = 'selftest'`
 * ({@see KIND}). {@see PipelineJobRunner} recognises that marker before
 * it touches the engine and calls this class instead: the LLM pipeline
 * is never invoked, so a self-test can be run on a production host as
 * often as the operator likes.
 *
 * What it proves, in order:
 *   1. `job_roundtrip`       — the row can be written AND read back
 *                              through the same repository the worker
 *                              uses for real jobs (no phantom rows).
 *   2. `job_events`          — `revoap_job_events` accepts an append and
 *                              returns it (audit trail is alive).
 *   3. `paths_writable`      — the worker process may write the cache and
 *                              log directories (the classic silent
 *                              failure after a deploy with wrong
 *                              ownership).
 *   4. `runtime`             — PHP version, autoloader in play, required
 *                              extensions.
 *   5. `providers_configured`— at least one connector is active and keyed.
 *                              Config introspection only: NO network call,
 *                              NO tokens.
 *   6. `providers_reachable` — OPT-IN ONLY (`selftest.probe_providers =
 *                              true`). A bare TCP connect to the
 *                              provider host:443 with a short timeout —
 *                              no HTTP request, no credentials, no
 *                              tokens. Off by default because a
 *                              self-test must be safe on an air-gapped
 *                              host.
 *
 * Every `detail` string is passed through {@see Logger::sanitizeScalar()}
 * before it leaves the process: the result envelope is persisted to
 * `revoap_jobs.result_json`, which is readable through the public
 * GET /api/council/jobs/{id} endpoint, so an absolute path or an
 * embedded credential must never end up in it.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Config;
use App\Core\Logger;
use Throwable;

final class WorkerSelfTest
{
    /**
     * Payload discriminator. `JobService::createSelfTestJob()` stamps it
     * and {@see isSelfTest()} matches it; nothing else in the codebase
     * may set this key.
     */
    public const KIND = 'selftest';

    /** Hard cap on the check list so the envelope stays small. */
    public const MAX_CHECKS = 12;

    /** Connect timeout (seconds) for the opt-in TCP reachability probe. */
    public const PROBE_TIMEOUT_SECONDS = 3.0;

    /** Directory (relative to the project root) the write probe uses. */
    private const WRITABLE_DIRS = ['cache', 'logs'];

    private readonly string $rootDir;

    public function __construct(
        private readonly JobRepository $jobs,
        ?string $rootDir = null,
    ) {
        $this->rootDir = rtrim($rootDir ?? Config::rootDir(), '/\\');
    }

    /**
     * Whether the payload marks this job as a self-test. Tolerates a
     * hostile payload: only the exact literal counts.
     *
     * @param array<string,mixed> $payload
     */
    public static function isSelfTest(array $payload): bool
    {
        $kind = $payload['kind'] ?? null;
        return is_string($kind) && strtolower(trim($kind)) === self::KIND;
    }

    /**
     * Whether the operator asked for the opt-in TCP reachability probe.
     * Defaults to false, so a self-test never opens a socket unless the
     * caller explicitly said so.
     *
     * @param array<string,mixed> $payload
     */
    public static function wantsProviderProbe(array $payload): bool
    {
        $selftest = $payload['selftest'] ?? null;
        if (!is_array($selftest)) {
            return false;
        }
        return ($selftest['probe_providers'] ?? false) === true;
    }

    /**
     * Run every check and return the envelope that goes into
     * `revoap_jobs.result_json`.
     *
     * @return array{
     *   kind:string,ok:bool,passed:int,failed:int,
     *   checks:list<array{name:string,ok:bool,detail:string,ms:float}>,
     *   worker_id:string,app_version:string,php_version:string,
     *   storage:string,probe_providers:bool,
     *   started_at:string,finished_at:string,duration_ms:int,note:string
     * }
     */
    public function run(Job $job, string $workerId): array
    {
        $startedAt = microtime(true);
        $probe     = self::wantsProviderProbe($job->payload);

        $checks = [];
        $checks[] = $this->check('job_roundtrip', fn(): array => $this->checkJobRoundTrip($job, $workerId));
        $checks[] = $this->check('job_events', fn(): array => $this->checkJobEvents($job));
        $checks[] = $this->check('paths_writable', fn(): array => $this->checkWritablePaths());
        $checks[] = $this->check('runtime', fn(): array => $this->checkRuntime());
        $checks[] = $this->check('providers_configured', fn(): array => $this->checkProvidersConfigured());
        if ($probe) {
            $checks[] = $this->check('providers_reachable', fn(): array => $this->checkProvidersReachable());
        }
        $checks = array_slice($checks, 0, self::MAX_CHECKS);

        $passed = 0;
        $failed = 0;
        foreach ($checks as $check) {
            if ($check['ok']) { $passed++; } else { $failed++; }
        }

        $finishedAt = microtime(true);
        return [
            'kind'            => self::KIND,
            'ok'              => $failed === 0,
            'passed'          => $passed,
            'failed'          => $failed,
            'checks'          => $checks,
            'worker_id'       => $workerId,
            'app_version'     => self::appVersion(),
            'php_version'     => PHP_VERSION,
            'storage'         => $this->jobs->database() === null ? 'memory' : 'database',
            'probe_providers' => $probe,
            'started_at'      => gmdate('Y-m-d\TH:i:s\Z', (int)$startedAt),
            'finished_at'     => gmdate('Y-m-d\TH:i:s\Z', (int)$finishedAt),
            'duration_ms'     => (int)round(($finishedAt - $startedAt) * 1000),
            'note'            => 'No LLM / provider API call was made — no tokens were spent.'
                . ($probe ? '' : ' Network reachability probe disabled (pass selftest.probe_providers=true to enable).'),
        ];
    }

    /**
     * Write + read back the very row this job lives in. Uses the same
     * repository the worker drives real jobs through, so a success here
     * means the queue round-trip genuinely works.
     *
     * @return array{0:bool,1:string}
     */
    private function checkJobRoundTrip(Job $job, string $workerId): array
    {
        if (!$this->jobs->tableAvailable()) {
            return [false, 'revoap_jobs is not available — import db/schema.sql (Settings → Database → ReCreate DB).'];
        }
        if (!$this->jobs->heartbeat($job->id, $workerId)) {
            return [false, 'heartbeat UPDATE matched no row for job #' . $job->id . ' (row missing?).'];
        }
        $fresh = $this->jobs->find($job->id);
        if ($fresh === null) {
            return [false, 'job #' . $job->id . ' disappeared between the write and the read-back.'];
        }
        $beat = $fresh->heartbeatAt;
        if (!is_string($beat) || $beat === '') {
            return [false, 'heartbeat_at is still empty after the UPDATE — write did not persist.'];
        }
        return [true, sprintf(
            'job #%d write+read-back ok (status=%s, heartbeat_at=%s, storage=%s)',
            $job->id,
            $fresh->status->value,
            $beat,
            $this->jobs->database() === null ? 'memory' : 'database',
        )];
    }

    /**
     * Append one audit row to `revoap_job_events` and read it back. A
     * missing table is reported as a failure (not skipped) because the
     * retry/recovery timeline depends on it.
     *
     * @return array{0:bool,1:string}
     */
    private function checkJobEvents(Job $job): array
    {
        $audit = new JobAuditRepository($this->jobs->database());
        if (!$audit->tableAvailable()) {
            return [false, 'revoap_job_events is missing — apply migration 013 / db/schema.sql.'];
        }
        $id = $audit->record([
            'job_id'     => $job->id,
            'session_id' => $job->sessionId,
            'event_type' => 'selftest',
            'status_to'  => JobStatus::Running->value,
            'reason'     => 'worker_selftest',
            'message'    => 'Worker self-test event.',
            'context'    => ['kind' => self::KIND],
        ]);
        if ($id === null) {
            return [false, 'INSERT into revoap_job_events returned no id (check DB permissions/schema).'];
        }
        $events = $audit->listByJobId($job->id, 10);
        if ($events === []) {
            return [false, 'event #' . $id . ' was written but could not be read back.'];
        }
        return [true, 'event #' . $id . ' written and read back (' . count($events) . ' event(s) for this job).'];
    }

    /**
     * Verify the worker process itself can write the cache and log
     * directories. Deliberately executed from the worker, not the web
     * request — the two run as different users on a systemd deploy.
     *
     * @return array{0:bool,1:string}
     */
    private function checkWritablePaths(): array
    {
        if ($this->rootDir === '' || !is_dir($this->rootDir)) {
            return [false, 'project root could not be resolved — cannot probe writable directories.'];
        }
        $ok = true;
        $parts = [];
        foreach (self::WRITABLE_DIRS as $name) {
            $dir = $this->rootDir . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                $ok = false;
                $parts[] = $name . '=missing';
                continue;
            }
            $probe = $dir . DIRECTORY_SEPARATOR . '.selftest-' . bin2hex(random_bytes(4)) . '.tmp';
            $payload = 'selftest ' . gmdate('Y-m-d\TH:i:s\Z');
            if (@file_put_contents($probe, $payload) === false) {
                $ok = false;
                $parts[] = $name . '=not_writable';
                continue;
            }
            $readBack = @file_get_contents($probe);
            @unlink($probe);
            if ($readBack !== $payload) {
                $ok = false;
                $parts[] = $name . '=read_back_failed';
                continue;
            }
            $parts[] = $name . '=ok';
        }
        return [$ok, implode(' ', $parts)];
    }

    /**
     * PHP version, autoloader in use, required extensions. Only
     * `json` + `pdo` + `mbstring` are treated as hard requirements —
     * `pdo_mysql` is reported but not enforced so a worker attached to a
     * memory-mode deploy still passes.
     *
     * @return array{0:bool,1:string}
     */
    private function checkRuntime(): array
    {
        $autoload = $this->rootDir !== '' && is_file($this->rootDir . '/vendor/autoload.php')
            ? 'vendor'
            : 'bundled';
        $extensions = ['json', 'pdo', 'pdo_mysql', 'mbstring', 'curl'];
        $missing = [];
        $parts = [];
        foreach ($extensions as $ext) {
            $loaded = extension_loaded($ext);
            $parts[] = $ext . '=' . ($loaded ? '1' : '0');
            if (!$loaded && in_array($ext, ['json', 'pdo', 'mbstring'], true)) {
                $missing[] = $ext;
            }
        }
        $ok = $missing === [];
        $detail = 'PHP ' . PHP_VERSION . ', sapi=' . PHP_SAPI . ', autoload=' . $autoload
            . ', ' . implode(' ', $parts);
        if (!$ok) {
            $detail .= ' — missing required extension(s): ' . implode(', ', $missing);
        }
        return [$ok, $detail];
    }

    /**
     * Are any connectors active and keyed? Config introspection only —
     * no socket is opened and no token is read into the response.
     *
     * @return array{0:bool,1:string}
     */
    private function checkProvidersConfigured(): array
    {
        $configured = self::configuredProviders();
        if ($configured['active'] === []) {
            return [false, 'no active connector — configure Providers in Settings before running the pipeline.'];
        }
        $withoutKey = array_values(array_diff($configured['active'], $configured['keyed']));
        $detail = 'active: ' . implode(', ', $configured['active'])
            . '; key present: ' . ($configured['keyed'] === [] ? 'none' : implode(', ', $configured['keyed']))
            . '; network probe: skipped (no tokens spent)';
        if ($withoutKey !== []) {
            $detail .= '; active without key: ' . implode(', ', $withoutKey);
        }
        return [true, $detail];
    }

    /**
     * OPT-IN TCP reachability probe. `stream_socket_client()` to
     * host:443 and an immediate close — no HTTP request, no headers, no
     * credentials, nothing that could be billed. Only included when
     * `selftest.probe_providers=true` was requested.
     *
     * @return array{0:bool,1:string}
     */
    private function checkProvidersReachable(): array
    {
        $configured = self::configuredProviders();
        $targets = [];
        foreach ($configured['active'] as $provider) {
            $host = self::providerHost($provider);
            if ($host !== null) {
                $targets[$provider] = $host;
            }
        }
        if ($targets === []) {
            return [false, 'no provider base_url to probe.'];
        }
        $ok = true;
        $parts = [];
        foreach ($targets as $provider => $host) {
            $t0 = microtime(true);
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                'tcp://' . $host . ':443',
                $errno,
                $errstr,
                self::PROBE_TIMEOUT_SECONDS,
                STREAM_CLIENT_CONNECT,
            );
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if (is_resource($socket)) {
                fclose($socket);
                $parts[] = $provider . ':' . $host . ':443 ok (' . $ms . 'ms)';
                continue;
            }
            $ok = false;
            $parts[] = $provider . ':' . $host . ':443 failed (' . Logger::sanitizeScalar($errstr !== '' ? $errstr : 'errno ' . $errno) . ')';
        }
        return [$ok, implode('; ', $parts) . ' — TCP connect only, no request sent, no tokens spent.'];
    }

    /**
     * Active connectors from config plus the subset with usable
     * credentials. The key VALUE is never returned, only membership.
     *
     * @return array{active:list<string>,keyed:list<string>}
     */
    private static function configuredProviders(): array
    {
        $active = [];
        $keyed  = [];
        try {
            $connectors = Config::get('connectors', []);
        } catch (Throwable) {
            return ['active' => [], 'keyed' => []];
        }
        if (!is_array($connectors)) {
            return ['active' => [], 'keyed' => []];
        }
        foreach ($connectors as $name => $cfg) {
            if (!is_string($name) || $name === '' || !is_array($cfg)) {
                continue;
            }
            $provider = strtolower($name);
            if (($cfg['active'] ?? false) !== true) {
                continue;
            }
            $active[] = $provider;
            try {
                if (trim(Config::getConnectorKey($provider)) !== '') {
                    $keyed[] = $provider;
                }
            } catch (Throwable) {
                // No key for this provider — it stays in `active` only.
            }
        }
        sort($active, SORT_STRING);
        sort($keyed, SORT_STRING);
        return ['active' => $active, 'keyed' => $keyed];
    }

    /**
     * Host name of a provider's configured base_url, or null when it is
     * missing/unusable. Used only by the opt-in reachability probe.
     */
    private static function providerHost(string $provider): ?string
    {
        try {
            $baseUrl = (string)Config::get('connectors.' . $provider . '.base_url', '');
        } catch (Throwable) {
            return null;
        }
        if ($baseUrl === '') {
            return null;
        }
        $host = parse_url($baseUrl, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : null;
    }

    private static function appVersion(): string
    {
        try {
            $version = Config::get('app.version', '');
        } catch (Throwable) {
            return '';
        }
        return is_scalar($version) ? (string)$version : '';
    }

    /**
     * Run one check, timing it and turning any escaping exception into a
     * failed check instead of a dead worker. The detail is always
     * sanitized — see the class docblock.
     *
     * @param  callable():array{0:bool,1:string} $fn
     * @return array{name:string,ok:bool,detail:string,ms:float}
     */
    private function check(string $name, callable $fn): array
    {
        $t0 = microtime(true);
        try {
            $result = $fn();
            $ok     = (bool)($result[0] ?? false);
            $detail = (string)($result[1] ?? '');
        } catch (Throwable $e) {
            $ok     = false;
            $detail = (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
        }
        return [
            'name'   => $name,
            'ok'     => $ok,
            'detail' => Logger::sanitizeScalar($detail),
            'ms'     => round((microtime(true) - $t0) * 1000, 1),
        ];
    }
}
