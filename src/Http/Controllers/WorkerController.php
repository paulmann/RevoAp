<?php
/**
 * WorkerController: HTTP layer for the Settings → Worker card.
 *
 * Two endpoints, deliberately different in blast radius:
 *
 *   POST /api/settings/worker/test   — enqueue a token-free self-test job
 *                                      (`kind=selftest`) and return its id.
 *                                      Admin gate (same as every other
 *                                      mutating /api/settings/* route).
 *
 *   GET  /api/settings/worker/debug  — read-only diagnostic bundle for
 *                                      operators. Gate: admin token OR a
 *                                      client IP present in
 *                                      `security.ip_whitelist`
 *                                      ({@see IpFirewall::allowsOperatorAccess()}).
 *                                      Any other caller gets a 403 that
 *                                      explains both ways in.
 *
 * The debug bundle reuses the very same services the public endpoints
 * use (`WorkerHealthService`, `DatabaseHealth`, `LogsService`,
 * `AdminAuth::diagnostics()`) instead of re-deriving their logic, so
 * what an operator pastes into a bug report is byte-comparable with
 * what /api/health* returns.
 *
 * Secret hygiene is absolute: no `.config.php` values, no connector
 * keys, no admin token, no DSN password. Log lines go through
 * {@see LogsService} (its whitelist + `Logger::sanitizeScalar`), and
 * every non-log string is sanitized before it leaves the process.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\DatabaseHealth;
use App\Core\Diagnostics;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Jobs\JobAuditRepository;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\WorkerHealthService;
use App\Jobs\WorkerPresenceRepository;
use App\Logs\LogsService;
use App\Security\ClientIp;
use App\Security\IpFirewall;
use App\Security\SecurityStore;
use PDO;
use Throwable;

final class WorkerController
{
    /** Lines of each log file included in the debug dump. */
    private const DEBUG_LOG_LINES = 200;

    /** Rows of `revoap_jobs` / `revoap_job_events` included in the dump. */
    private const DEBUG_ROW_LIMIT = 10;

    public function __construct(
        private readonly WorkerHealthService  $health,
        private readonly JobService           $jobs,
        private readonly JobRepository        $jobRepo,
        private readonly LogsService          $logs,
        private readonly ?PDO                 $pdo = null,
        private readonly ?JobAuditRepository  $audit = null,
        private readonly ?string              $rootDir = null,
    ) {}

    /**
     * POST /api/settings/worker/test
     *
     * Enqueues one `kind=selftest` job. The worker runs the cheap
     * in-process checks ({@see \App\Jobs\WorkerSelfTest}) instead of the
     * LLM pipeline, so this proves the web → DB → worker → result chain
     * without spending a single token.
     *
     * Body (optional JSON): {"probe_providers": true} to also run the
     * opt-in TCP reachability probe (still no HTTP request, no tokens).
     *
     * Responses:
     *   201 {ok:true, job_id, session_id, status:"queued", events_url}
     *   400 invalid_request / 401 unauthorized / 404 settings_disabled
     *   503 admin_not_configured | job_table_missing
     */
    public function test(): string
    {
        if (($r = $this->requireAdmin()) !== null) {
            return $r;
        }
        if (!$this->jobRepo->tableAvailable()) {
            return Response::json([
                'ok'      => false,
                'error'   => 'job_table_missing',
                'message' => 'The durable job queue (revoap_jobs) is not available, so the worker has nothing to '
                    . 'pick up. Import db/schema.sql via Settings → Database → ReCreate DB first.',
            ], 503);
        }

        $body = $this->readJsonBody();
        $probe = ($body['probe_providers'] ?? false) === true;

        try {
            $created = $this->jobs->createSelfTestJob($probe);
        } catch (Throwable $e) {
            return Response::json([
                'ok'      => false,
                'error'   => 'enqueue_failed',
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ], 500);
        }

        return Response::json([
            'ok'          => true,
            'probe_providers' => $probe,
            'message'     => 'Self-test enqueued. The worker will run it without calling any model.',
            ...$created,
        ], 201);
    }

    /**
     * GET /api/settings/worker/debug
     *
     * Read-only diagnostic bundle. GET (not POST) because nothing is
     * mutated: the gate is verified before a single byte of the payload
     * is produced, so there is no CSRF/state-change surface to protect,
     * and GET keeps the dump curl-able / bookmarkable for an operator
     * debugging a host over SSH.
     *
     * Responses:
     *   200 dump
     *   403 forbidden (reason: admin_or_ip_required) with both ways in
     */
    public function debug(): string
    {
        $gate = $this->authorizeDiagnostics();
        if (is_string($gate)) {
            return $gate;
        }
        $state    = $this->securityState();
        $clientIp = $this->clientIp($state);
        $isAdmin  = $this->isAdminUnlocked();

        try {
            $dump = $this->buildDebugDump($state, $clientIp, $isAdmin);
        } catch (Throwable $e) {
            // A dump that fails to build must still be a readable JSON
            // error, never a 500 HTML page.
            Logger::warn('worker.debug.build_failed', [
                'class'   => (new \ReflectionClass($e))->getShortName(),
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return Response::json([
                'ok'      => false,
                'error'   => 'debug_failed',
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ], 500);
        }

        Logger::info('worker.debug.served', [
            'gate' => $isAdmin ? 'admin' : 'ip_whitelist',
            'ip'   => $clientIp,
        ]);
        return Response::json($dump);
    }

    /**
     * Settings-tab + admin gate, identical to the one every other
     * mutating /api/settings/* route uses.
     */

    /**
     * POST /api/worker/restart
     *
     * Asks the running workers to stop, so that systemd starts them again on the
     * deployed code.
     *
     * The panel cannot restart a unit itself and does not try to: the unit is
     * `Restart=always`, so a clean exit *is* a restart, and the request is a
     * timestamp that only counts for processes older than it. That is the whole
     * mechanism - no sudo, no privileged helper, no shell from PHP.
     *
     * Responses:
     *   200 {ok:true, requested_at, running_jobs, note}
     *   401 unauthorized / 404 settings_disabled
     *   503 no_database - there is nowhere to record the request
     */
    public function restart(): string
    {
        if (($r = $this->requireAdmin()) !== null) {
            return $r;
        }

        $at = (new \App\Jobs\WorkerRestartSignal($this->pdo))->request();
        if ($at === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'no_database',
                'message' => 'A restart request is recorded in the database, and none is configured.',
            ], 503);
        }

        $running = 0;
        try {
            $running = (int)($this->databaseSnapshot()['jobs']['running'] ?? 0);
        } catch (Throwable) {
            $running = 0;
        }

        // R7 - the journal entry for this repair. The durable trace is the
        // timestamp in app_config (a worker only obeys a request newer than
        // its own start), and this line records who asked and what was in
        // flight at that moment.
        Logger::info('worker.restart_requested', [
            'requested_at' => $at,
            'running_jobs' => $running,
        ]);
        return Response::json([
            'ok'           => true,
            'requested_at' => $at,
            'running_jobs' => $running,
            'note'         => $running > 0
                ? 'A job is running. Each worker stops after the current poll and systemd starts it again; '
                    . 'an interrupted job is picked up by the usual recovery sweep.'
                : 'No job is running. Each worker stops within one poll and systemd starts it again on the '
                    . 'deployed code, which is what makes this a restart rather than a kill.',
        ]);
    }

    private function requireAdmin(): ?string
    {
        if (!Config::isSettingsTabEnabled()) {
            return Response::json([
                'error'   => 'settings_disabled',
                'message' => 'Settings tab is disabled in config.php (SettingsTab=false).',
            ], 404);
        }
        // v1.8.60 — the decision is taken once, in AdminAuth, so every guard
        // on this surface obeys `Protect_Settings_by_Admin_Key`: with the flag
        // at 0 the gate is open (no key configured, no key presented, nothing
        // to challenge), while at 1 — or absent — the historical 503 / 401
        // pair is returned unchanged.
        $gate = AdminAuth::gateState();
        if (!$gate['allowed']) {
            if (!$gate['configured']) {
                return Response::json([
                    'error'   => 'admin_not_configured',
                    'message' => SettingsController::adminNotConfiguredMessage('Settings'),
                    'admin_diagnostics' => AdminAuth::diagnostics(),
                ], 503);
            }
            return Response::json(['error' => 'unauthorized', 'message' => 'admin token required'], 401);
        }
        return null;
    }

    /**
     * Debug gate: unlocked admin token OR a whitelisted client IP. The
     * 403 message lists both remedies verbatim so the operator is never
     * left guessing, and echoes the resolved client IP (their own
     * address — not a leak) so a mismatch is immediately obvious.
     */
    private function authorizeDiagnostics(): ?string
    {
        $gate = AdminAuth::gateState();
        if ($gate['unlocked']) {
            return null;
        }
        $state    = $this->securityState();
        $clientIp = $this->clientIp($state);
        if (IpFirewall::allowsOperatorAccess($clientIp, $state)) {
            return null;
        }
        return Response::json([
            'ok'      => false,
            'error'   => 'forbidden',
            'reason'  => 'admin_or_ip_required',
            'message' => 'The worker debug dump is operator-only. Either unlock the admin token '
                . '(Settings → Session → enter the admin token; it is stored in an HttpOnly cookie) '
                . 'or add your client IP to the whitelist in Settings → Security (config key '
                . 'security.ip_whitelist).',
            'client_ip'             => $clientIp,
            // v1.8.60 — the flag travels with the refusal, so an operator who
            // set `Protect_Settings_by_Admin_Key = 0` can see that this
            // endpoint refused on the IP rule, not on a key that no longer
            // exists.
            'admin_protection'      => $gate['protected'],
            'admin_configured'      => $gate['configured'],
            'admin_unlocked'        => false,
            'ip_whitelist_enabled'  => ($state['ip_whitelist_enabled'] ?? false) === true,
            'ip_whitelist'          => $state['ip_whitelist'] ?? [],
            'settings_tab_enabled'  => Config::isSettingsTabEnabled(),
        ], 403);
    }

    /**
     * Build the full dump. Every sub-block comes from the shared service
     * that owns it — no logic is duplicated here.
     *
     * @param  array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function buildDebugDump(array $state, string $clientIp, bool $isAdmin): array
    {
        // One snapshot, two consumers (health.worker + worker_presence).
        $worker = $this->health->snapshot();

        return [
            'ok'           => true,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'sensitive'    => true,
            'note'         => 'Operator diagnostic bundle. Contains worker ids, host/pid, log excerpts and job '
                . 'metadata. It never contains API keys, admin tokens or .config.php values — treat it as '
                . 'internal nonetheless.',
            'gate' => [
                'mode'                 => $isAdmin ? 'admin' : 'ip_whitelist',
                'client_ip'            => $clientIp,
                'admin_configured'     => AdminAuth::isConfigured(),
                'admin_unlocked'       => $isAdmin,
                'settings_tab_enabled' => Config::isSettingsTabEnabled(),
                'ip_whitelist_enabled' => ($state['ip_whitelist_enabled'] ?? false) === true,
                'ip_whitelist'         => $state['ip_whitelist'] ?? [],
            ],
            // Same shapes /api/health, /api/health/database and
            // /api/health/worker emit; the services are shared.
            'health' => [
                'app' => [
                    'name'     => Config::get('app.name'),
                    'version'  => Config::get('app.version'),
                    'debug'    => Config::isDebug(),
                    'build_id' => Diagnostics::buildId(),
                ],
                'admin'          => AdminAuth::diagnostics(),
                'config_sources' => Config::get('app.diagnostics.config_sources', []),
                'root'           => Config::get('app.diagnostics.root', null),
                'database'       => DatabaseHealth::snapshot($this->pdo),
                'worker'         => $worker,
            ],
            'worker_presence' => $worker['worker_presence'] ?? [],
            'environment'     => $this->environmentBlock(),
            'logs'            => [
                'revoap-error' => $this->logs->tail('revoap-error', self::DEBUG_LOG_LINES),
                'revoap-debug' => $this->logs->tail('revoap-debug', self::DEBUG_LOG_LINES),
            ],
            'database_snapshot' => $this->databaseSnapshot(),
            'commands'          => self::commands(),
        ];
    }

    /**
     * Runtime environment of the WEB process. Note that the worker runs
     * as a different user under systemd — `php_version` here is the
     * FPM/CLI version serving this request. The worker's own version and
     * extensions are reported by the self-test (`runtime` check).
     *
     * @return array<string,mixed>
     */
    private function environmentBlock(): array
    {
        $extensions = array_map('strval', get_loaded_extensions());
        sort($extensions, SORT_STRING);

        return [
            'php_version'  => PHP_VERSION,
            'sapi'         => PHP_SAPI,
            'os'           => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'pdo_drivers'  => array_map('strval', PDO::getAvailableDrivers()),
            'extensions'   => $extensions,
            'ini'          => [
                'memory_limit'           => (string)ini_get('memory_limit'),
                'max_execution_time'     => (string)ini_get('max_execution_time'),
                'date.timezone'          => (string)ini_get('date.timezone'),
                'log_errors'             => (string)ini_get('log_errors'),
                'display_errors'         => (string)ini_get('display_errors'),
                'opcache.enable'         => (string)ini_get('opcache.enable'),
                'opcache.enable_cli'     => (string)ini_get('opcache.enable_cli'),
            ],
            'functions'    => [
                // The CLI worker only gets graceful SIGTERM handling when
                // pcntl is available; without it a stop is a SIGKILL and
                // the presence row goes stale instead of disappearing.
                'pcntl_signal' => function_exists('pcntl_signal'),
                'proc_open'    => function_exists('proc_open'),
                'stream_socket_client' => function_exists('stream_socket_client'),
            ],
        ];
    }

    /**
     * Last few jobs and audit events — the same sanitized projections the
     * public job/timeline endpoints return (payloads are stripped of
     * secret-looking keys by Job::sanitizePayload).
     *
     * @return array<string,mixed>
     */
    private function databaseSnapshot(): array
    {
        $jobs = [];
        try {
            foreach ($this->jobRepo->listRecent(self::DEBUG_ROW_LIMIT) as $job) {
                $jobs[] = $job->toPublicArray();
            }
        } catch (Throwable) {
            $jobs = [];
        }

        $events = [];
        if ($this->audit !== null) {
            try {
                foreach ($this->audit->listRecent(self::DEBUG_ROW_LIMIT) as $event) {
                    $events[] = $event->toPublicArray();
                }
            } catch (Throwable) {
                $events = [];
            }
        }

        return [
            'jobs_table_available'   => $this->jobRepo->tableAvailable(),
            'events_table_available' => $this->audit?->tableAvailable() ?? false,
            'recent_jobs'            => $jobs,
            'recent_events'          => $events,
            'presence_table'         => WorkerPresenceRepository::TABLE,
        ];
    }

    /**
     * Copy/paste commands for the operator. Extends the shared list with
     * the `-o cat` journal form (no decoration, safe to paste into a bug
     * report).
     *
     * @return array<string,string>
     */
    private static function commands(): array
    {
        return WorkerHealthService::commands() + [
            'journal_cat'    => 'sudo journalctl -u revoap-worker.service -o cat',
            'presence_query' => 'mysql -e "SELECT worker_id, mode, started_at, last_seen_at, jobs_done '
                . 'FROM revoap_worker_presence ORDER BY last_seen_at DESC" <db>',
        ];
    }

    private function isAdminUnlocked(): bool
    {
        // v1.8.60 — `gateState()` folds in `Protect_Settings_by_Admin_Key`, so
        // an unprotected install reports "unlocked" here too and the worker
        // debug endpoint stops demanding a key it will never need.
        try {
            return AdminAuth::gateState()['unlocked'];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Security subtree (`security.ip_whitelist`, …). Never throws — a
     * Config that has not been loaded yields an empty (loopback-only)
     * state, which fails closed for the debug gate.
     *
     * @return array<string,mixed>
     */
    private function securityState(): array
    {
        try {
            return SecurityStore::load();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $state */
    private function clientIp(array $state): string
    {
        try {
            return ClientIp::resolve(null, $state);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $decoded = JsonHelper::tryDecode($raw, []);
        return is_array($decoded) ? $decoded : [];
    }
}
