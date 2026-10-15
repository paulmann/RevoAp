<?php
/**
 * Diagnostics: builds the structured, sanitized debug payload used by
 * `?debug=1`, `/api/debug`, and the optional debug envelope embedded in
 * `/api/council/run` and `/api/council/session/{id}`.
 *
 * The same shape is reused everywhere so operators learn one schema. All
 * values flow through Logger::sanitize() so API keys, DSN passwords and
 * absolute filesystem paths never leak.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Core;

use App\Cache\ModelResponseCache;
use App\Connectors\ConnectorRegistry;
use PDO;

final class Diagnostics
{
    /**
     * Last database connection error captured by Application::pdo(). Set on
     * connection failure so /api/debug can explain why $pdo is null without
     * leaking DSN credentials. The recorder strips passwords and paths first.
     */
    private static ?string $lastDbErrorClass = null;
    private static ?string $lastDbErrorMessage = null;
    /** @var array<string,mixed>|null */
    private static ?array  $lastDbErrorContext = null;

    public static function recordDatabaseError(?\Throwable $e, string $operation = 'connect'): void
    {
        if ($e === null) {
            self::$lastDbErrorClass = null;
            self::$lastDbErrorMessage = null;
            self::$lastDbErrorContext = null;
            return;
        }
        self::$lastDbErrorClass   = (new \ReflectionClass($e))->getShortName();
        self::$lastDbErrorMessage = Logger::sanitizeScalar($e->getMessage());
        self::$lastDbErrorContext = ErrorDiagnostics::describeDatabaseError($e, ['operation' => $operation]);
    }

    public static function lastDbErrorClass(): ?string   { return self::$lastDbErrorClass; }
    public static function lastDbErrorMessage(): ?string { return self::$lastDbErrorMessage; }
    /** @return array<string,mixed>|null */
    public static function lastDbErrorContext(): ?array  { return self::$lastDbErrorContext; }

    /**
     * Short identifier for the deployed build. Reads HEAD from the working
     * tree's .git/ when available so operators can confirm whether the
     * server actually runs the commit they just pushed. Falls back to the
     * configured app.version when the project is deployed without git
     * metadata. Never exposes filesystem paths.
     */
    public static function buildId(): string
    {
        $root = Config::rootDir();
        if ($root === '') {
            return (string)Config::get('app.version', '');
        }
        $head = $root . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'HEAD';
        if (is_file($head) && is_readable($head)) {
            $line = trim((string)file_get_contents($head));
            if (str_starts_with($line, 'ref: ')) {
                $ref = trim(substr($line, 5));
                $refFile = $root . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . $ref;
                if (is_file($refFile) && is_readable($refFile)) {
                    $sha = trim((string)file_get_contents($refFile));
                    if ($sha !== '') {
                        return substr($sha, 0, 12);
                    }
                }
            } elseif (preg_match('/^[0-9a-f]{40}$/i', $line) === 1) {
                return substr($line, 0, 12);
            }
        }
        return (string)Config::get('app.version', '');
    }

    /**
     * Build the canonical diagnostic snapshot.
     *
     * @return array<string,mixed>
     */
    public static function buildReport(ConnectorRegistry $connectors, ?PDO $pdo, ?string $resolvedPath = null, ?ModelResponseCache $cache = null): array
    {
        $models = Config::getActiveModels();

        $availableProviders = [];
        foreach ($connectors->discovered() as $c) {
            if ($c->isAvailable()) {
                $availableProviders[strtolower($c->getProviderName())] = true;
            }
        }

        $modelsByProvider          = [];
        $availableModelsByProvider = [];
        $skippedModelsByProvider   = [];
        $skippedActiveModels       = [];
        $usableActiveModels        = [];
        $modelsDetailed            = [];
        foreach ($models as $m) {
            $provider  = strtolower((string)$m['provider']);
            $modelStr  = (string)$m['model'];
            $available = isset($availableProviders[$provider]);
            $modelsByProvider[$provider] = ($modelsByProvider[$provider] ?? 0) + 1;
            if ($available) {
                $availableModelsByProvider[$provider] = ($availableModelsByProvider[$provider] ?? 0) + 1;
                $usableActiveModels[] = ['provider' => $provider, 'model' => $modelStr];
            } else {
                $skippedModelsByProvider[$provider] = ($skippedModelsByProvider[$provider] ?? 0) + 1;
                $skippedActiveModels[] = [
                    'provider' => $provider,
                    'model'    => $modelStr,
                    'reason'   => self::describeUnavailableConnector($provider),
                ];
            }
            $modelsDetailed[] = [
                'provider'  => $provider,
                'model'     => $modelStr,
                'available' => $available,
            ];
        }

        $connectorsDetailed = [];
        foreach ($connectors->discovered() as $c) {
            $name      = strtolower($c->getProviderName());
            $available = $c->isAvailable();
            $connectorsDetailed[] = [
                'provider'   => $name,
                'available'  => $available,
                'reason'     => $available ? null : self::describeUnavailableConnector($name),
            ];
        }

        $synthesisProvider  = (string)Config::get('pipeline.synthesis_provider', '');
        $synthesisAvailable = $synthesisProvider !== '' && $connectors->hasProvider($synthesisProvider);

        // Plan summary: the layers as a static description of what the engine
        // will attempt. Useful for clients to render a progress UI and for
        // operators to confirm the configuration matches their expectations.
        $stagePlan = [
            ['layer' => 'L0', 'name' => 'Skill routing'],
            ['layer' => 'L1', 'name' => 'Distribution'],
            ['layer' => 'L2', 'name' => 'Normalization'],
            ['layer' => 'L3', 'name' => 'Consensus'],
            ['layer' => 'L4', 'name' => 'Synthesis'],
        ];

        $availableProviderList = array_keys($availableProviders);
        sort($availableProviderList);

        $report = [
            'app' => [
                'name'     => Config::get('app.name'),
                'version'  => Config::get('app.version'),
                'debug'    => Config::isDebug(),
                'build_id' => self::buildId(),
            ],
            'request' => [
                'method'         => $_SERVER['REQUEST_METHOD'] ?? null,
                'request_uri'    => $_SERVER['REQUEST_URI']    ?? null,
                'script_name'    => $_SERVER['SCRIPT_NAME']    ?? null,
                'resolved_path'  => $resolvedPath,
            ],
            'database' => array_filter([
                'configured'   => Config::get('database.dsn', '') !== '',
                'connected'    => $pdo !== null,
                'storage_mode' => $pdo === null ? 'memory' : 'persistent',
                'error_class'  => self::$lastDbErrorClass,
                'error_message'=> self::$lastDbErrorMessage,
                // Structured diagnostic block — only when app.debug=true.
                // Production keeps the terse error_message line.
                'diagnostics'  => Config::isDebug() ? self::$lastDbErrorContext : null,
            ], static fn($v): bool => $v !== null),
            'connectors' => [
                'discovered' => array_map(static fn(array $r): string => (string)$r['provider'], $connectorsDetailed),
                'available'  => $availableProviderList,
                'skipped'    => $connectors->skipped(),
                'detailed'   => $connectorsDetailed,
            ],
            'pipeline' => [
                'synthesis_provider'              => $synthesisProvider,
                'synthesis_provider_available'    => $synthesisAvailable,
                'synthesis_model'                 => Config::get('pipeline.synthesis_model'),
                'active_model_count'              => count($models),
                'active_models_by_provider'       => $modelsByProvider,
                'active_models'                   => $modelsDetailed,
                'available_active_models_by_provider' => $availableModelsByProvider,
                'usable_active_models'            => $usableActiveModels,
                'skipped_active_models_by_provider' => $skippedModelsByProvider,
                'skipped_active_models'           => $skippedActiveModels,
                'stage_plan'                      => $stagePlan,
            ],
            'logger' => [
                'enabled' => Logger::enabled(),
            ],
            'cache' => self::cacheReport($cache),
            'routes' => self::routesReport(),
            'hints' => self::hints($synthesisProvider, $synthesisAvailable, $availableProviderList, $models, $pdo),
        ];

        return Logger::sanitize($report);
    }

    /**
     * Cache backend snapshot for the diagnostic envelope. Always
     * present in the report — when no cache instance is supplied,
     * the section reflects the configured defaults so operators can
     * still see whether the feature is enabled.
     *
     * @return array<string,mixed>
     */
    public static function cacheReport(?ModelResponseCache $cache): array
    {
        if ($cache === null) {
            $cfg = Config::get('cache.model_responses', []);
            return [
                'enabled'    => is_array($cfg) ? (bool)($cfg['enabled'] ?? true) : true,
                'configured' => is_array($cfg),
                'backend'    => null,
            ];
        }
        return [
            'enabled' => $cache->isEnabled(),
            'backend' => $cache->backendInfo(),
            'summary' => $cache->summary(),
        ];
    }

    /**
     * List of HTTP routes the deployed build is expected to expose.
     * The list is static (the router does not yet introspect itself)
     * but operators can compare each entry against the build_id to
     * confirm the deployed bundle includes the SSE stream endpoint.
     *
     * @return array<string,mixed>
     */
    public static function routesReport(): array
    {
        return [
            'build_id' => self::buildId(),
            'expected' => [
                ['method' => 'GET',  'path' => '/'],
                ['method' => 'POST', 'path' => '/api/council/run'],
                ['method' => 'GET',  'path' => '/api/council/stream',         'note' => 'SSE — emits PIPELINE events as they fire.'],
                ['method' => 'GET',  'path' => '/api/council/session/{id}'],
                ['method' => 'GET',  'path' => '/api/council/session/{id}/events'],
                ['method' => 'GET',  'path' => '/api/council/session/{id}/stats'],
                ['method' => 'GET',  'path' => '/api/connectors'],
                ['method' => 'GET',  'path' => '/api/skills'],
                ['method' => 'GET',  'path' => '/api/health'],
                ['method' => 'GET',  'path' => '/api/debug'],
            ],
            'troubleshooting' => [
                'route_not_found' => 'If /api/council/stream returns 404, the deployed code predates v1.2.0. '
                    . 'Pull latest, run composer dump-autoload (if Composer is in use), restart PHP-FPM, '
                    . 'and verify the build_id above matches the latest git SHA.',
                'sse_buffered'    => 'If SSE arrives in one chunk: disable proxy buffering. nginx: '
                    . '`proxy_buffering off; proxy_cache off; gzip off;` for the /api/council/stream '
                    . 'location. Apache: `SetEnv no-gzip 1`.',
                'mysql_gone_away' => 'Long parallel L1 + L4 calls can outlast wait_timeout. The Database '
                    . 'wrapper retries each repository call once after reconnect. Increase MySQL '
                    . 'wait_timeout or reduce pipeline.parallel_l1 if "gone away" errors persist.',
            ],
        ];
    }

    /**
     * @param string[] $available
     * @param array<int,array{provider:string,model:string,active:bool}> $models
     * @return array<int,string>
     */
    public static function hints(string $synthesisProvider, bool $synthesisAvailable, array $available, array $models, ?PDO $pdo): array
    {
        $hints = [];
        if ($models === []) {
            $hints[] = 'No active models configured — set models[].active=true in config.php.';
        }
        if (!$synthesisAvailable && $synthesisProvider !== '') {
            $envName = strtoupper($synthesisProvider) . '_API_KEY';
            $hints[] = "Synthesis provider '{$synthesisProvider}' has no available connector. "
                . "Provide an API key via connectors.{$synthesisProvider}.api_key in config.php, a "
                . ".{$synthesisProvider}.key file at the project root, or the {$envName} environment variable.";
        }
        if ($available === []) {
            $hints[] = 'No connector is available — every pipeline call will fall back. '
                . 'Add at least one provider API key.';
        }

        // L1 silent-skip diagnostic. If at least one connector is available
        // but the active model list excludes it (or the available providers
        // are not represented), the engine would skip every L1 call. Surface
        // this clearly so operators stop chasing ghosts.
        if ($models !== [] && $available !== []) {
            $hasAvailableModel = false;
            $availableMap = array_flip(array_map('strtolower', $available));
            foreach ($models as $m) {
                if (isset($availableMap[strtolower((string)$m['provider'])])) {
                    $hasAvailableModel = true;
                    break;
                }
            }
            if (!$hasAvailableModel) {
                $hints[] = 'L1 will skip every role: each active model points to a provider whose connector is '
                    . 'unavailable. Either enable a connector for one of the listed model providers or set '
                    . 'models[].active=true on at least one model whose provider is available.';
            }
        }

        if ($pdo === null) {
            $hints[] = 'Database not connected — sessions and audit trail are kept in memory only and '
                . 'GET /api/council/session/{id} will 404 on subsequent requests. Configure database.dsn '
                . 'in config.php and run db/schema.sql to enable persistence.';
        }
        return $hints;
    }

    /**
     * Human-readable explanation of why a discovered connector is not
     * available right now. Mirrors PipelineEngine::describeMissingConnector
     * but without exposing key bytes.
     */
    public static function describeUnavailableConnector(string $provider): string
    {
        $cfg = Config::get("connectors.{$provider}", null);
        if (!is_array($cfg)) {
            return "Provider '{$provider}' is not configured in config.connectors.";
        }
        if (($cfg['active'] ?? false) !== true) {
            return "Provider '{$provider}' is marked inactive (set connectors.{$provider}.active=true).";
        }
        $envName = strtoupper($provider) . '_API_KEY';
        return "Provider '{$provider}' has no API key. Set connectors.{$provider}.api_key in config.php, "
             . "create a .{$provider}.key file at the project root, or export {$envName}.";
    }
}
