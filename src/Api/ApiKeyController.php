<?php
/**
 * ApiKeyController: admin CRUD for external REST API keys (rv_*).
 *
 * Backs the "API" tab in the UI. Every endpoint is gated by the ApiTab
 * config flag and the same admin token as the rest of the operator
 * surface (identical gate to WebhookController):
 *   1. ApiTab=false         -> 404 api_tab_disabled
 *   2. no admin token       -> 503 admin_not_configured + diagnostics
 *   3. wrong/missing token  -> 401 unauthorized
 *
 * Endpoints (mounted in index.php):
 *   GET  /api/apikeys/status        - public tab/admin probe
 *   GET  /api/apikeys               - list keys (no hashes) + stats
 *   POST /api/apikeys               - create; returns the plaintext ONCE
 *   POST /api/apikeys/{id}/rotate   - new material; returns plaintext ONCE
 *   POST /api/apikeys/{id}/revoke   - disable (audit row kept)
 *
 * Security invariants:
 *   - listings use ApiKey::toPublicArray(): key_hash is NEVER serialized;
 *   - the plaintext appears in exactly two responses: create and rotate,
 *     and is produced by ApiKeyService only (never persisted);
 *   - mutating admin paths are also registered in ApiAuth::isAdminPath()
 *     so an external API key can never reach them.
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

use App\Core\AdminAuth;
use App\Core\Config;
use App\Helpers\JsonHelper;
use App\Http\Controllers\SettingsController;
use App\Http\Response;

final class ApiKeyController
{
    public const MAX_LABEL  = 128;
    public const MAX_LIMIT  = 1_000_000;

    public function __construct(private readonly ApiKeyService $service) {}

    /**
     * ApiTab + admin gate. Returns a response string to short-circuit
     * with, or null when the caller may proceed.
     */
    private function requireAdmin(): ?string
    {
        if (!Config::isApiTabEnabled()) {
            return Response::json([
                'error'   => 'api_tab_disabled',
                'message' => 'API tab is disabled in config.php (ApiTab=false).',
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
                    'message' => SettingsController::adminNotConfiguredMessage('API keys'),
                    'admin_diagnostics' => AdminAuth::diagnostics(),
                ], 503);
            }
            return Response::json(['error' => 'unauthorized', 'message' => 'admin token required'], 401);
        }
        return null;
    }

    /** Public, no-auth probe mirroring the other tabs. */
    public function status(): string
    {
        return Response::json([
            'tab_enabled'      => Config::isApiTabEnabled(),
            'admin_protection' => AdminAuth::protectionEnabled(),
            'admin_configured' => AdminAuth::isConfigured(),
            'admin_unlocked'   => AdminAuth::gateState()['unlocked'],
        ]);
    }

    /** GET /api/apikeys - list keys (public shape, no hashes) + stats. */
    public function index(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        return Response::json([
            'api_keys' => $this->service->listPublic(),
            'stats'    => $this->service->stats(),
        ]);
    }

    /**
     * POST /api/apikeys - create a key; the plaintext is returned ONLY
     * in this response.
     *
     * @param array<string,mixed>|null $body Test seam: pass an explicit
     *        body instead of reading php://input.
     */
    public function create(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $body ?? $this->readJsonBody();

        $label = trim((string)($body['label'] ?? ''));
        if ($label !== '' && mb_strlen($label) > self::MAX_LABEL) {
            return Response::json([
                'error'   => 'invalid_label',
                'message' => 'Label must be at most 128 characters',
            ], 400);
        }

        $rpm = self::readLimit($body, 'rpm_limit', ApiKeyService::DEFAULT_RPM);
        if ($rpm === null || $rpm < 0) {
            return Response::json([
                'error'   => 'invalid_rpm_limit',
                'message' => 'rpm_limit must be a non-negative integer',
            ], 400);
        }
        $daily = self::readLimit($body, 'daily_limit', ApiKeyService::DEFAULT_DAILY);
        if ($daily === null || $daily < 0) {
            return Response::json([
                'error'   => 'invalid_daily_limit',
                'message' => 'daily_limit must be a non-negative integer',
            ], 400);
        }

        $scopes = $body['scopes'] ?? null;
        if ($scopes !== null && !is_string($scopes) && !is_array($scopes)) {
            return Response::json([
                'error'   => 'invalid_scopes',
                'message' => 'scopes must be a comma string or an array (full|read)',
            ], 400);
        }

        $expiresAt = null;
        if (isset($body['expires_at']) && is_string($body['expires_at'])) {
            $expiresRaw = trim($body['expires_at']);
            if ($expiresRaw !== '') {
                if (strtotime($expiresRaw) === false) {
                    return Response::json([
                        'error'   => 'invalid_expires_at',
                        'message' => 'expires_at must be a parseable date/time',
                    ], 400);
                }
                $expiresAt = $expiresRaw;
            }
        }

        $made = $this->service->generate(
            label:      $label,
            scopes:     $scopes,
            rpmLimit:   $rpm,
            dailyLimit: $daily,
            expiresAt:  $expiresAt,
        );
        return Response::json([
            'ok'        => true,
            'api_key'   => $made['key']->toPublicArray(),
            'plaintext' => $made['plaintext'],
        ], 201);
    }

    /** POST /api/apikeys/{id}/rotate - new key material ONCE. */
    public function rotate(array $params): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $id = (int)($params['id'] ?? 0);
        $rotated = $this->service->rotate($id);
        if ($rotated === null) {
            return Response::json(['error' => 'unknown_api_key'], 404);
        }
        return Response::json([
            'ok'        => true,
            'api_key'   => $rotated['key']->toPublicArray(),
            'plaintext' => $rotated['plaintext'],
        ]);
    }

    /** POST /api/apikeys/{id}/revoke - disable the key (row kept). */
    public function revoke(array $params): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $id = (int)($params['id'] ?? 0);
        $key = $this->service->revoke($id);
        if ($key === null) {
            return Response::json(['error' => 'unknown_api_key'], 404);
        }
        return Response::json(['ok' => true, 'api_key' => $key->toPublicArray()]);
    }

    /**
     * Coerce a quota field to a non-negative int (capped), or null when
     * the value is present but invalid. Absent -> the service default.
     *
     * @param array<string,mixed> $body
     */
    private static function readLimit(array $body, string $field, int $default): ?int
    {
        if (!array_key_exists($field, $body)) {
            return $default;
        }
        $v = $body[$field];
        if (is_int($v) && $v >= 0) {
            return min($v, self::MAX_LIMIT);
        }
        if (is_string($v) && ctype_digit($v)) {
            return min((int)$v, self::MAX_LIMIT);
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
}
