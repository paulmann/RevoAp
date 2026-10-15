<?php
/**
 * ApiAuth: authentication, authorization and rate limiting for the
 * external REST API (/v1/*).
 *
 * Pipeline for every external API request:
 *   1. Extract the presented key from `X-API-Key`, `Authorization:
 *      Bearer <key>` or `?api_key=` (for MVP curl simplicity). When
 *      several are present, header wins over bearer and bearer wins
 *      over query, matching AdminAuth's priority design.
 *   2. Resolve it through ApiKeyService::verify() (constant-time-ish
 *      digest lookup - SHA-256 of the presented value).
 *   3. Reject unless ApiKey::isUsable() (not revoked, not expired).
 *   4. Reject when the route requires a scope the key does not carry
 *      (write endpoints require `full`; read endpoint require `read`,
 *      which every key has).
 *   5. Deny-list: paths that are reserved for the operator UI and
 *      MUST NEVER be reachable with an API key (settings/security/
 *      logs/profile/admin surfaces). Checked even against `full` keys.
 *   6. Rate-limit via ApiRateLimiter using the key's own rpm/daily
 *      quotas; 429 on breach.
 *   6b. When an IP quota is configured, the same limiter enforces an
 *      anonymous per-IP minute budget BEFORE any credential handling
 *      (brute-force protection); exhaustion yields 429 without ever
 *      looking at the presented key.
 *   7. On success, touch last_used_at and return the ApiKey so the
 *      caller can hydrate the job owner.
 *
 * Auditing: every failure writes an event without the key value (only
 * the reason and the caller IP). A timeout recheck is emitted for
 * invalid credentials only after `check()` rejects, to give the
 * compare-path parity; it never logs the presented secret.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

namespace App\Api;

use App\Core\Logger;

final class ApiAuth
{
    public const WRITE_SCOPE = ApiKey::SCOPE_FULL;
    public const READ_SCOPE  = ApiKey::SCOPE_READ;

    private readonly ?string $limitsDir;

    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly ApiRateLimiter $limiter,
        ?string $limitsDir = null,
        private readonly ?int $ipRpm = null,
    ) {
        // Kept for BC with earlier constructor signatures; the limiter
        // parameter is the single source of truth for counting.
        $this->limitsDir = $limitsDir;
    }

    /**
     * Result of a request gate. `ok=true` carries the authenticated key;
     * `ok=false` carries a ready-to-emit JSON error payload + status.
     *
     * @return array{ok:bool,status?:int,error?:string,message?:string,key?:ApiKey,headers?:array<string,string>}
     */
    public function check(
        array  $server,
        array  $get,
        string $path,
        bool   $write = false,
    ): array {
        // 6b: anonymous per-IP budget. Enforced BEFORE credential
        // handling so key enumeration traffic cannot hide behind fresh
        // keys. A failed IP check never logs or inspects the key value.
        if ($this->ipRpm !== null) {
            $ip = (string)($server['REMOTE_ADDR'] ?? 'unknown');
            $ipRl = $this->limiter->registerAndCheckIp($ip, $this->ipRpm);
            if (!$ipRl['allowed']) {
                $this->audit('ip_rate_limited', $path, $server);
                return self::failWithHeaders(
                    429,
                    'rate_limited',
                    'Too many requests from this address. Check the Retry-After header.',
                    [
                        'Retry-After'                  => (string)$ipRl['retry_after'],
                        'X-RateLimit-Remaining-Minute' => (string)$ipRl['remaining_minute'],
                        'X-RateLimit-Reset-Minute'     => (string)$ipRl['minute_reset_at'],
                    ],
                );
            }
        }

        $presented = self::extractKey($server, $get);
        if ($presented === '') {
            $this->audit('missing_key', $path, $server);
            return self::fail(401, 'unauthorized', 'Missing API key. Pass X-API-Key or Authorization: Bearer <key>.');
        }

        $key = $this->keys->verify($presented);
        if ($key === null || !$key->isUsable()) {
            $this->audit('invalid_key', $path, $server);
            return self::fail(401, 'unauthorized', 'Invalid, revoked or expired API key.');
        }

        if (!$this->hasScope($key, $write ? self::WRITE_SCOPE : self::READ_SCOPE)) {
            $this->audit('insufficient_scope', $path, $server, $key);
            return self::fail(403, 'forbidden', 'This API key is not allowed to perform this action.');
        }

        if ($this->isAdminPath($path)) {
            $this->audit('admin_path_blocked', $path, $server, $key);
            return self::fail(404, 'not_found', 'Route not available.');
        }

        $rpm   = max(0, $key->rpmLimit);
        $daily = max(0, $key->dailyLimit);
        $rl = $this->limiter->registerAndCheck($key->id, $rpm, $daily);
        if (!$rl['allowed']) {
            $this->audit('rate_limited', $path, $server, $key);
            return self::failWithHeaders(
                429,
                'rate_limited',
                'Rate limit exceeded. Check the Retry-After header.',
                [
                    'Retry-After'                     => (string)$rl['retry_after'],
                    'X-RateLimit-Remaining-Minute'    => (string)$rl['remaining_minute'],
                    'X-RateLimit-Reset-Minute'        => (string)$rl['minute_reset_at'],
                    'X-RateLimit-Remaining-Daily'     => (string)$rl['remaining_daily'],
                ],
            );
        }

        $this->keys->touchLastUsed($key->id);
        return [
            'ok'      => true,
            'key'     => $key,
            'headers' => [
                'X-RateLimit-Remaining-Minute' => (string)$rl['remaining_minute'],
                'X-RateLimit-Reset-Minute'     => (string)$rl['minute_reset_at'],
                'X-RateLimit-Remaining-Daily'  => (string)$rl['remaining_daily'],
            ],
        ];
    }

    /**
     * Extract the presented API key from a $server superglobal shape.
     * Priority: X-API-Key > Authorization Bearer > ?api_key.
     *
     * @param array<string,mixed> $server $_SERVER-like array
     * @param array<string,mixed> $get    $_GET-like array
     */
    public static function extractKey(array $server, array $get): string
    {
        $hdr = $server['HTTP_X_API_KEY'] ?? '';
        if (is_string($hdr) && trim($hdr) !== '') {
            return trim($hdr);
        }
        $auth = $server['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($auth) && stripos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
            if ($token !== '') {
                return $token;
            }
        }
        $q = $get['api_key'] ?? '';
        if (is_string($q) && trim($q) !== '') {
            return trim($q);
        }
        return '';
    }

    /**
     * Whether the requested path belongs to the operator UI and must
     * never be reachable with an external API key.
     */
    public function isAdminPath(string $path): bool
    {
        $p = '/' . ltrim($path, '/');
        $blocked = [
            '/api/settings', '/api/profile', '/api/domain-presets',
            '/api/analyzer/settings', '/api/logs', '/api/security',
            '/api/history', '/api/statistics', '/api/share/session',
            '/api/share/document',
            // The Skills tab's document writer reuses the share write path;
            // external API keys stay off that surface like off the writer.
            '/api/consensus/skills',
            '/api/skills/draft', '/api/research', '/api/skills/generator',
            '/api/apikeys', '/api/webhooks',
        ];
        foreach ($blocked as $prefix) {
            if ($p === $prefix || str_starts_with($p, $prefix . '/')) {
                return true;
            }
        }
        // The /api/skills CRUD surface is also operator-owned.
        if ($p === '/api/skills' || str_starts_with($p, '/api/skills/')) {
            return true;
        }
        return false;
    }

    public function hasScope(ApiKey $key, string $scope): bool
    {
        return $scope === self::WRITE_SCOPE
            ? $key->hasFullScope()
            : $key->hasReadScope();
    }

    /**
     * Record a failed authentication/authorization event. NEVER logs the
     * presented key, the key hash, or any request body - only the reason,
     * path and caller IP.
     *
     * @param array<string,mixed> $server
     */
    private function audit(string $reason, string $path, array $server, ?ApiKey $key = null): void
    {
        Logger::warn('api.auth.denied', [
            'reason'    => $reason,
            'path'      => $path,
            'ip'        => (string)($server['REMOTE_ADDR'] ?? 'unknown'),
            'key_id'    => $key?->id,
            'key_label' => null, // deliberately never resolved for audit
        ]);
    }

    /**
     * @return array{ok:bool,status:int,error:string,message:string}
     */
    private static function fail(int $status, string $error, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'message' => $message];
    }

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool,status:int,error:string,message:string,headers:array<string,string>}
     */
    private static function failWithHeaders(int $status, string $error, string $message, array $headers): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'message' => $message, 'headers' => $headers];
    }
}
