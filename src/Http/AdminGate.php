<?php
/**
 * AdminGate: the one place that decides whether an operator route is open.
 *
 * Every v2 controller needs the same two-line predicate, and getting it wrong
 * in either direction is a security bug:
 *
 *   - gating on `AdminAuth::check()` ALONE locks out an installation that has
 *     deliberately set `Protect_Settings_by_Admin_Key = 0`. That flag exists
 *     for single-operator intranet hosts, and the v1 surface honours it; a new
 *     controller that ignores it would make the Audit or Fallback panel
 *     unreachable on exactly the deployments that cannot afford a token file.
 *   - gating on `protectionEnabled()` ALONE opens every new panel to the
 *     internet on a default install, because "protection is on" is not the same
 *     statement as "this request presented the token".
 *
 * The correct predicate is therefore:
 *
 *     protection is off            -> open  (and the shell says so, loudly)
 *     protection is on + valid     -> open
 *     protection is on + invalid   -> 403
 *
 * Which is `!protectionEnabled() || check()`, written once here.
 *
 * This class also owns the 403 body, so every new panel returns the same shape
 * and the SPA can show one consistent "unlock the admin area" affordance
 * instead of each panel inventing its own.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Http;

use App\Core\AdminAuth;

final class AdminGate
{
    private function __construct()
    {
    }

    /**
     * True when the current request may reach an operator route.
     *
     * Delegates to `AdminAuth::gateState()['allowed']` rather than
     * re-deriving the predicate, because that key is already the canonical
     * verdict the v1 controllers act on: `unlocked && (!protected ||
     * configured)`. The extra `|| configured` clause matters — a protected
     * install with NO token file can never be unlocked, and reporting it as
     * "open" would let every request through with an empty comparison.
     */
    public static function open(): bool
    {
        return AdminAuth::gateState()['allowed'] === true;
    }

    /**
     * The 403 response for a closed gate, or null when the gate is open.
     *
     * Usage:
     *     if ($denied = AdminGate::deny()) { return $denied; }
     *
     * Returning the string directly keeps call sites to one line, which is what
     * makes the check easy to add to a new endpoint and hard to forget.
     */
    public static function deny(?string $what = null): ?string
    {
        if (self::open()) {
            return null;
        }
        $gate = AdminAuth::gateState();
        $configured = (bool)($gate['configured'] ?? false);
        return Response::json([
            'ok'    => false,
            'error' => 'admin_required',
            // The SPA uses this to choose between "enter the token" and "the
            // operator never configured one", which are different problems
            // with different remedies.
            'gate'  => [
                'configured' => $configured,
                'protected'  => (bool)($gate['protected'] ?? true),
                'allowed'    => (bool)($gate['allowed'] ?? false),
                'key'        => (string)($gate['key'] ?? ''),
                'location'   => AdminAuth::protectionLocation(),
            ],
            'hint'  => $configured
                ? 'Unlock the admin area with the token from .admin.key.'
                : 'Create .admin.key at the project root (openssl rand -hex 32, chmod 600).',
            ...($what !== null ? ['resource' => $what] : []),
        ], 403);
    }

    /**
     * True when the gate is open because protection is OFF rather than because
     * a token was presented.
     *
     * Endpoints that mutate state use this to write an audit entry with
     * `actor_type = 'anonymous'`: an unprotected install still deserves an
     * audit trail, and pretending the actor was an authenticated administrator
     * would make the trail actively misleading.
     */
    public static function openWithoutToken(): bool
    {
        return !AdminAuth::protectionEnabled();
    }
}
