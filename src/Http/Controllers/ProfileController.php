<?php
/**
 * ProfileController: HTTP layer for Settings → Profile.
 *
 * Endpoints:
 *   GET  /api/profile        — current profile (admin-gated, mirrors Settings)
 *   POST /api/profile        — upsert profile fields (admin-gated)
 *
 * Authentication policy mirrors the rest of the Settings surface:
 * the SettingsTab flag must be on, the admin token must be configured
 * and the request must carry the admin session cookie. This keeps the
 * profile data private until a future auth layer attaches per-user
 * identity.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\AdminAuth;
use App\Core\Config;
use App\Http\Response;
use App\Profiles\UserProfileRepository;

final class ProfileController
{
    public function __construct(
        private readonly UserProfileRepository $profiles,
    ) {}

    public function get(): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        $profile = $this->profiles->findByExternalId(UserProfileRepository::LOCAL_EXTERNAL_ID);
        return Response::json([
            'profile'   => $profile->toArray(),
            'persisted' => $this->profiles->tablesAvailable(),
            'storage'   => $this->profiles->tablesAvailable() ? 'database' : 'memory',
        ]);
    }

    public function save(): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        $body = $this->readJsonBody();
        $input = [
            'email'                  => self::stringField($body['email']                   ?? ''),
            'phone'                  => self::stringField($body['phone']                   ?? ''),
            'first_name'             => self::stringField($body['first_name']              ?? ''),
            'last_name'              => self::stringField($body['last_name']               ?? ''),
            'job_title'              => self::stringField($body['job_title']               ?? ''),
            'bio'                    => self::stringField($body['bio']                     ?? ''),
            'system_prompt_addendum' => self::stringField($body['system_prompt_addendum']  ?? ''),
        ];
        $profile = $this->profiles->save(UserProfileRepository::LOCAL_EXTERNAL_ID, $input);
        return Response::json([
            'ok'        => true,
            'profile'   => $profile->toArray(),
            'persisted' => $this->profiles->tablesAvailable(),
            'storage'   => $this->profiles->tablesAvailable() ? 'database' : 'memory',
        ]);
    }

    private function guard(): ?string
    {
        if (!Config::isSettingsTabEnabled()) {
            return Response::json(['error' => 'settings_disabled'], 404);
        }
        // v1.8.60 — the decision is taken once, in AdminAuth, so every guard
        // on this surface obeys `Protect_Settings_by_Admin_Key`: with the flag
        // at 0 the gate is open (no key configured, no key presented, nothing
        // to challenge), while at 1 — or absent — the historical 503 / 401
        // pair is returned unchanged.
        $gate = AdminAuth::gateState();
        if (!$gate['allowed']) {
            if (!$gate['configured']) {
                return Response::json(['error' => 'admin_not_configured'], 503);
            }
            return Response::json(['error' => 'unauthorized'], 401);
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') { return []; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function stringField(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
