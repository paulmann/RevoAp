<?php
/**
 * IpFirewall: enforce whitelist/blacklist rules at the front of the
 * request lifecycle.
 *
 * Decision order:
 *   1. Loopback (127.0.0.0/8 or ::1) is ALWAYS allowed — even when both
 *      lists are enabled. This is the explicit anti-lockout rule and is
 *      stated in docs/Security.md so operators rely on it for recovery.
 *   2. If blacklist enforcement is enabled and the client IP matches a
 *      blacklist rule → DENY. Blacklist takes precedence over whitelist.
 *   3. If whitelist enforcement is enabled and the client IP does NOT
 *      match a whitelist rule → DENY.
 *   4. Otherwise → ALLOW.
 *
 * The enforce() helper short-circuits the request via http_response_code
 * and exits with a sanitized JSON 403. It writes a single `denied` line
 * to the application logger so operators can audit blocked attempts.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Security;

use App\Core\Logger;
use App\Helpers\JsonHelper;

final class IpFirewall
{
    public const REASON_BLACKLIST = 'blacklist';
    public const REASON_WHITELIST = 'whitelist';
    public const ALLOW             = 'allow';

    /**
     * Pure decision function. Returns one of: 'allow', 'blacklist',
     * 'whitelist'. The latter two indicate denial.
     *
     * @param string $ip   Resolved client IP (already passed through
     *                     ClientIp::resolve()).
     * @param array{
     *     ip_whitelist_enabled: bool, ip_whitelist: list<string>,
     *     ip_blacklist_enabled: bool, ip_blacklist: list<string>
     * } $state
     */
    public static function decide(string $ip, array $state): string
    {
        if ($ip === '') {
            // Empty IP cannot be matched against any rule; allow so we
            // never wedge legitimate CLI / health-check traffic.
            return self::ALLOW;
        }
        if (IpMatcher::isLoopback($ip)) {
            return self::ALLOW;
        }
        if (($state['ip_blacklist_enabled'] ?? false) === true
            && IpMatcher::matchesAny($ip, $state['ip_blacklist'] ?? [])
        ) {
            return self::REASON_BLACKLIST;
        }
        if (($state['ip_whitelist_enabled'] ?? false) === true) {
            if (!IpMatcher::matchesAny($ip, $state['ip_whitelist'] ?? [])) {
                return self::REASON_WHITELIST;
            }
        }
        return self::ALLOW;
    }

    /**
     * v1.8.44 — "is this request allowed to pull an operator diagnostic
     * payload" (Settings → Worker → Debug).
     *
     * This is a WEAKER, explicitly-scoped gate than {@see enforce()}: it
     * answers "is the caller a trusted operator" for a read-only dump
     * that is already sanitized, not "may this request reach the app".
     * The rules mirror the firewall's own trust model:
     *
     *   1. Loopback is trusted — exactly like {@see decide()}, the host
     *      itself can always recover a deployment.
     *   2. Any other address must appear in `security.ip_whitelist`.
     *      Membership is the grant; we deliberately do NOT require
     *      `ip_whitelist_enabled`, because a whitelist entry is an
     *      explicit operator statement about that address. When the
     *      whitelist is empty this reduces to loopback-only.
     *
     * An empty/unresolvable IP is never trusted (fail closed).
     *
     * @param array{
     *     ip_whitelist?: list<string>,
     *     ip_whitelist_enabled?: bool
     * } $state
     */
    public static function allowsOperatorAccess(string $ip, array $state): bool
    {
        if ($ip === '') {
            return false;
        }
        if (IpMatcher::isLoopback($ip)) {
            return true;
        }
        return IpMatcher::matchesAny($ip, $state['ip_whitelist'] ?? []);
    }

    /**
     * Apply the decision for the live request. When denied, emit a 403
     * JSON response, log the event, and exit immediately so no other
     * controller code runs. When allowed, returns silently.
     *
     * Loopback access never reaches the deny branch (decide() short-
     * circuits) — even if an operator misconfigures their lists, they
     * can always recover from the local machine.
     */
    public static function enforce(): void
    {
        $state    = SecurityStore::load();
        $clientIp = ClientIp::resolve(null, $state);
        $verdict  = self::decide($clientIp, $state);
        if ($verdict === self::ALLOW) {
            return;
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        Logger::warn('ip_firewall.denied', [
            'reason' => $verdict,
            'ip'     => $clientIp,
            'path'   => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        ]);
        try {
            echo JsonHelper::encode([
                'error'   => 'forbidden',
                'reason'  => $verdict,
                'message' => 'Access denied by IP policy.',
            ]);
        } catch (\JsonException) {
            echo '{"error":"forbidden"}';
        }
        // Hard stop — nothing else may run for this request.
        exit;
    }
}
