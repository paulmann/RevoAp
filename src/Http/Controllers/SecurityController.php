<?php
/**
 * SecurityController: HTTP layer for the admin Security tab.
 *
 * Every protected endpoint requires:
 *   1. `SecurityTab=true` in config.php — when false, returns 404
 *      `security_disabled` (the matching API surface goes dark too).
 *   2. Admin token (env / .admin.key, presented via header / cookie /
 *      query) — same gate as Settings/Logs tabs.
 *
 * Endpoints:
 *   GET  /api/security/status       — public probe (tab + admin state).
 *                                     When admin-authenticated also returns
 *                                     the live state + the detected
 *                                     client IP, and auto-adds that IP
 *                                     to the whitelist (per requirements).
 *   POST /api/security/save         — replace the security state. Always
 *                                     re-asserts loopback rules and the
 *                                     current client IP when whitelist
 *                                     enforcement is being enabled, to
 *                                     prevent operator lockout.
 *   POST /api/security/test-ip      — dry-run: test an arbitrary IP
 *                                     against the configured rules.
 *   POST /api/security/reset        — reset to safe defaults
 *                                     (enforcement OFF, loopback only).
 *
 * Output is sanitized — the public status endpoint returns only the
 * boolean tab/admin flags, never the IP lists or detected IP.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\AdminAuth;
use App\Core\Config;
use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Security\ClientIp;
use App\Security\IpFirewall;
use App\Security\IpMatcher;
use App\Security\SecurityStore;

final class SecurityController
{
    public function __construct(
        private readonly string $rootDir,
    ) {}

    private function requireAdmin(): ?string
    {
        if (!Config::isSecurityTabEnabled()) {
            return Response::json([
                'error'   => 'security_disabled',
                'message' => 'Security tab is disabled in config.php (SecurityTab=false).',
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
                    'message' => SettingsController::adminNotConfiguredMessage('Security tab'),
                    'admin_diagnostics' => AdminAuth::diagnostics(),
                ], 503);
            }
            return Response::json(['error' => 'unauthorized', 'message' => 'admin token required'], 401);
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $decoded = JsonHelper::tryDecode($raw, []);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Public probe + (when admin-authenticated) the live security state.
     * Per the product spec, opening the Security tab while admin-
     * authenticated auto-adds the current client IP to the whitelist so
     * an operator who just enabled enforcement is never locked out.
     */
    public function status(): string
    {
        $tabEnabled = Config::isSecurityTabEnabled();
        // v1.8.60 — one gate verdict for both branches. `admin_protection`
        // is the raw `Protect_Settings_by_Admin_Key` flag and `admin_unlocked`
        // is true whenever the protection is off, so a disabled tab cannot
        // look like a locked one on an install that asks for no key.
        $gate = AdminAuth::gateState();
        if (!$tabEnabled) {
            return Response::json([
                'tab_enabled'       => false,
                'admin_protection'  => $gate['protected'],
                'admin_configured'  => $gate['configured'],
                'admin_unlocked'    => false,
                'admin_diagnostics' => AdminAuth::diagnostics(),
            ]);
        }
        $public = [
            'tab_enabled'       => true,
            'admin_protection'  => $gate['protected'],
            'admin_configured'  => $gate['configured'],
            'admin_unlocked'    => $gate['unlocked'],
            'admin_diagnostics' => AdminAuth::diagnostics(),
        ];
        if (!$gate['unlocked']) {
            return Response::json($public);
        }

        $state    = SecurityStore::load();
        $clientIp = ClientIp::resolve(null, $state);
        // Auto-add the current admin's IP to the whitelist on every
        // visit to the Security tab so a misclick on "enable whitelist"
        // can never cut the operator off from their own console.
        if ($clientIp !== '') {
            $state = SecurityStore::ensureIpInWhitelist($clientIp);
        }
        $verdict = IpFirewall::decide($clientIp, $state);

        return Response::json($public + [
            'client_ip'    => $clientIp,
            'verdict'      => $verdict,
            'security'     => $state,
            'health'       => $this->healthChecks(),
            'loopback_rules' => IpMatcher::LOOPBACK_RULES,
        ]);
    }

    public function save(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $this->readJsonBody();

        $current  = SecurityStore::load();
        $clientIp = ClientIp::resolve();
        $patch    = $current;

        if (array_key_exists('ip_whitelist_enabled', $body)) {
            $patch['ip_whitelist_enabled'] = (bool)$body['ip_whitelist_enabled'];
        }
        if (array_key_exists('ip_blacklist_enabled', $body)) {
            $patch['ip_blacklist_enabled'] = (bool)$body['ip_blacklist_enabled'];
        }
        if (array_key_exists('trust_proxy_headers', $body)) {
            $patch['trust_proxy_headers'] = (bool)$body['trust_proxy_headers'];
        }
        if (array_key_exists('ip_whitelist', $body)) {
            $patch['ip_whitelist'] = $this->parseList($body['ip_whitelist']);
        }
        if (array_key_exists('ip_blacklist', $body)) {
            $patch['ip_blacklist'] = $this->parseList($body['ip_blacklist']);
        }
        if (array_key_exists('trusted_proxies', $body)) {
            $patch['trusted_proxies'] = $this->parseList($body['trusted_proxies']);
        }
        if (array_key_exists('admin_session_ttl_minutes', $body)) {
            $patch['admin_session_ttl_minutes'] = (int)$body['admin_session_ttl_minutes'];
        }

        // Anti-lockout: when whitelist enforcement is being enabled OR
        // is already on, ensure loopback + the current client IP are in
        // the list before the save lands. (Loopback is also enforced by
        // SecurityStore::normalize, but this re-assertion is explicit
        // about why and surfaces in the operator's UI.)
        if (($patch['ip_whitelist_enabled'] ?? false) === true && $clientIp !== '') {
            $norm = IpMatcher::normalizeEntry($clientIp);
            if ($norm !== null && !in_array($norm, $patch['ip_whitelist'], true)
                && !IpMatcher::matchesAny($clientIp, $patch['ip_whitelist'])
            ) {
                $patch['ip_whitelist'][] = $norm;
            }
        }

        try {
            $clean = SecurityStore::save($patch);
        } catch (\App\Core\ConfigWriteException $e) {
            return Response::json(['ok' => false] + $e->details(), 500);
        }
        return Response::json([
            'ok'        => true,
            'security'  => $clean,
            'client_ip' => $clientIp,
            'verdict'   => IpFirewall::decide($clientIp, $clean),
            'persisted' => SecurityStore::load(),
        ]);
    }

    public function testIp(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $this->readJsonBody();
        $candidate = is_string($body['ip'] ?? null) ? trim((string)$body['ip']) : '';
        if ($candidate === '' || @inet_pton($candidate) === false) {
            return Response::json(['ok' => false, 'error' => 'invalid_ip'], 400);
        }
        $state   = SecurityStore::load();
        $verdict = IpFirewall::decide($candidate, $state);
        return Response::json([
            'ok'        => true,
            'ip'        => IpMatcher::canonical($candidate),
            'verdict'   => $verdict,
            'allowed'   => $verdict === IpFirewall::ALLOW,
            'is_loopback' => IpMatcher::isLoopback($candidate),
        ]);
    }

    public function reset(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        try {
            $clean = SecurityStore::save([
                'ip_whitelist_enabled' => false,
                'ip_whitelist'         => IpMatcher::LOOPBACK_RULES,
                'ip_blacklist_enabled' => false,
                'ip_blacklist'         => [],
                'trusted_proxies'      => [],
                'trust_proxy_headers'  => false,
                'admin_session_ttl_minutes' => SecurityStore::DEFAULT_TTL_MINUTES,
            ]);
        } catch (\App\Core\ConfigWriteException $e) {
            return Response::json(['ok' => false] + $e->details(), 500);
        }
        return Response::json(['ok' => true, 'security' => $clean]);
    }

    /**
     * Surface the read-only health checks the Security tab presents to
     * the operator. None of these reveal secrets — just booleans/strings
     * the user can see anyway via `view-source`.
     *
     * @return array<string,mixed>
     */
    private function healthChecks(): array
    {
        $debug      = (bool)Config::get('app.debug', false);
        $https      = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $cacheDir   = $this->rootDir . '/var';
        $logsDir    = (string)Config::get('logging.path', $this->rootDir . '/logs');
        $hasAdmin   = AdminAuth::isConfigured();
        return [
            'app_debug'           => $debug,
            'https_detected'      => $https,
            'admin_token_configured' => $hasAdmin,
            'settings_tab_enabled'=> Config::isSettingsTabEnabled(),
            'logs_tab_enabled'    => Config::isLogsTabEnabled(),
            'security_tab_enabled'=> Config::isSecurityTabEnabled(),
            'var_dir_writable'    => is_dir($cacheDir) ? is_writable($cacheDir) : @mkdir($cacheDir, 0o700, true) || is_dir($cacheDir),
            'logs_dir_writable'   => is_dir($logsDir) && is_writable($logsDir),
            'security_headers'    => true, // Response::html sets CSP/X-Content-Type-Options
        ];
    }

    /**
     * Accept either an array of strings or a newline-separated string
     * (the textarea form). Returned list is unfiltered — normalization
     * happens in SecurityStore::normalize.
     *
     * @param  mixed $value
     * @return list<string>
     */
    private function parseList(mixed $value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                if (is_string($v)) { $out[] = $v; }
            }
            return $out;
        }
        if (is_string($value)) {
            $parts = preg_split('/[\r\n,]+/', $value) ?: [];
            $out   = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') { $out[] = $p; }
            }
            return $out;
        }
        return [];
    }
}
