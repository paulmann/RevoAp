<?php
/**
 * MaintenanceMode: a switch that answers every non-admin request with 503.
 *
 * Three decisions worth recording, because each is a way this feature usually
 * goes wrong:
 *
 *  1. **The state lives in the runtime overlay, not in a file of its own.** An
 *     operator flipping maintenance mode during an incident must not need write
 *     access to a new path, and the console must not grow a second source of
 *     truth for "is the site up". `RuntimeConfig` is already merged over
 *     `config.php` on every request, so the switch takes effect on the next
 *     request with no PHP-FPM reload.
 *  2. **Administrators are let through.** A maintenance mode that locks out the
 *     person who turned it on is a mode nobody dares enable — which is why the
 *     check runs after AdminGate, not before.
 *  3. **Loopback and the operator's own IP are allowed by default.** Deploying
 *     from the host itself (`curl localhost/api/health` in a post-deploy probe)
 *     must keep working, or the deploy script reports a failure it caused.
 *
 * Health endpoints (`/api/health*`, `/healthz`, `/readyz`) are ALWAYS answered,
 * including in maintenance: a load balancer that cannot reach its health check
 * pulls the node out of rotation, which turns "temporarily unavailable" into
 * "permanently removed from the pool" and the node never comes back on its own.
 *
 * @project  RevoAp   @version 2.0.0   @since 2.0.0
 */
declare(strict_types=1);

namespace App\Admin\Maintenance;

use App\Core\Config;
use App\Core\RuntimeConfig;
use App\Http\AdminGate;
use App\Security\ClientIp;

final class MaintenanceMode
{
    /** Paths that stay reachable while maintenance is on. Prefixes. */
    private const ALWAYS_ALLOWED = [
        '/api/health', '/healthz', '/readyz', '/livez',
        '/api/settings/unlock', '/api/settings/logout', '/api/tabs',
        '/api/maintenance',
        // The shell itself and its assets: the 503 page is rendered by the app,
        // so refusing these would produce a bare, unstyled error.
        '/assets/', '/favicon', '/manifest.webmanifest',
    ];

    public static function isEnabled(): bool
    {
        return Config::tryGet('maintenance.enabled', false) === true;
    }

    /** @return array<string,mixed> */
    public static function state(): array
    {
        $allowed = Config::tryGet('maintenance.allow_ips', []);
        return [
            'enabled'   => self::isEnabled(),
            'message'   => (string)Config::tryGet('maintenance.message', ''),
            'eta'       => Config::tryGet('maintenance.eta', null),
            'allow_ips' => is_array($allowed) ? array_values(array_filter(array_map('strval', $allowed))) : [],
            'since'     => Config::tryGet('maintenance.since', null),
        ];
    }

    /**
     * @return array{ok:bool,error:?string,state:array<string,mixed>}
     */
    public static function enable(string $message = '', ?string $eta = null, array $allowIps = []): array
    {
        $fields = [
            'maintenance.enabled' => true,
            'maintenance.message' => mb_substr($message, 0, 500),
            'maintenance.eta'     => $eta === null ? null : mb_substr($eta, 0, 100),
            'maintenance.since'   => time(),
            'maintenance.allow_ips' => self::normalizeIps($allowIps),
        ];
        $result = RuntimeConfig::updateChecked($fields);
        return [
            'ok'    => (bool)$result['ok'],
            // Reporting success while the overlay was not written would leave the
            // operator believing an incident response is in place when it is not.
            'error' => $result['ok'] ? null : 'persist_failed',
            'state' => self::state(),
        ];
    }

    /** @return array{ok:bool,error:?string,state:array<string,mixed>} */
    public static function disable(): array
    {
        $result = RuntimeConfig::updateChecked([
            'maintenance.enabled' => false,
            'maintenance.since'   => null,
        ]);
        return [
            'ok'    => (bool)$result['ok'],
            'error' => $result['ok'] ? null : 'persist_failed',
            'state' => self::state(),
        ];
    }

    /**
     * Should THIS request be refused with 503?
     *
     * Called from index.php after routing is resolved but before the handler
     * runs. Returns null when the request may proceed, or a short reason string
     * when it must be refused (which the caller logs).
     */
    public static function refusalFor(string $path, array $server = []): ?string
    {
        if (!self::isEnabled()) {
            return null;
        }
        foreach (self::ALWAYS_ALLOWED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return null;
            }
        }
        // An administrator is let through, which is what makes the switch safe
        // to enable from the console in the first place.
        if (AdminGate::open()) {
            return null;
        }
        $ip = self::clientIp($server);
        if ($ip !== null && (self::isLoopback($ip) || self::isAllowed($ip))) {
            return null;
        }
        return 'maintenance_mode';
    }

    /** Only addresses that parse, deduplicated, capped. */
    public static function normalizeIps(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) { continue; }
            $value = trim($entry);
            if ($value === '' || strlen($value) > 64) { continue; }
            // Accept a bare address or a CIDR range; validate the address part.
            $address = str_contains($value, '/') ? explode('/', $value, 2)[0] : $value;
            if (@inet_pton($address) === false) { continue; }
            $out[$value] = true;
        }
        return array_slice(array_keys($out), 0, 200);
    }

    /**
     * Does the address match the operator's allow-list?
     *
     * Delegates CIDR handling to App\Security\IpMatcher rather than
     * reimplementing subnet arithmetic — that class already handles IPv4 and
     * IPv6, malformed ranges and the loopback special cases, and a second
     * implementation of "is this IP in this range" is a second place to get it
     * wrong. `matchesAny` takes the whole rule list, so bare addresses are
     * normalised into `/32` / `/128` form and passed along with the CIDRs.
     */
    private static function isAllowed(string $ip): bool
    {
        $rules = [];
        foreach (self::state()['allow_ips'] as $rule) {
            $rules[] = str_contains($rule, '/') ? $rule : self::withFullMask($rule);
        }
        if ($rules === []) {
            return false;
        }
        return \App\Security\IpMatcher::matchesAny($ip, $rules);
    }

    /** `1.2.3.4` -> `1.2.3.4/32`, `::1` -> `::1/128`. */
    private static function withFullMask(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        return $ip . '/' . (strlen($packed) === 16 ? 128 : 32);
    }

    private static function isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.');
    }

    /**
     * The client address, honouring X-Forwarded-For ONLY from a proxy the
     * operator has listed as trusted. Trusting every proxy would let a client
     * forge its own IP and walk straight through the allow-list.
     */
    private static function clientIp(array $server): ?string
    {
        // ClientIp::resolve() returns a string and already implements the whole
        // policy: it only honours X-Forwarded-For from a proxy the operator
        // listed as trusted, and falls back to REMOTE_ADDR. Reusing it is what
        // keeps this allow-list and the IP firewall in SecurityController from
        // ever disagreeing about who the client is — two implementations of
        // "which address is this?" is how an operator gets locked out by one and
        // let through by the other.
        try {
            $ip = ClientIp::resolve($server);
        } catch (\Throwable) {
            $ip = (string)($server['REMOTE_ADDR'] ?? '');
        }
        if ($ip === '' || @inet_pton($ip) === false) {
            return null;
        }
        return $ip;
    }
}
