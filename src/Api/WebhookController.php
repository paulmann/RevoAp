<?php
/**
 * WebhookController: admin CRUD for outbound webhook subscriptions
 * plus the delivery log.
 *
 * Every mutating endpoint requires the Settings tab enabled and a
 * valid admin token (same gate as LogsController). Listings are
 * admin-gated too: subscription URLs and delivery metadata are
 * operator-only, not public.
 *
 * Endpoints (mounted in index.php):
 *   GET    /api/webhooks/status       - tab/admin state probe
 *   GET    /api/webhooks              - list subscriptions (masked secret)
 *   POST   /api/webhooks              - create
 *   GET    /api/webhooks/{id}         - show one (masked secret)
 *   PUT/post /api/webhooks/{id}      - update (mounted as POST: Router
 *                                      has no PUT verb)
 *   POST   /api/webhooks/{id}/toggle  - enable/disable
 *   DELETE /api/webhooks/{id}         - delete
 *   GET    /api/webhooks/{id}/deliveries - delivery attempts
 *
 * The signing secret is NEVER echoed: create/update return
 * `secret` once (the only chance to copy it out) and listings show
 * Webhook::maskSecret() hints (`***abcd`) only.
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
use InvalidArgumentException;

final class WebhookController
{
    public function __construct(
        private readonly WebhookService          $service,
        private readonly WebhookDeliveryRepository $deliveries,
    ) {}

    /**
     * Settings-tab + admin gate. Returns a response string to short-
     * circuit with, or null when the caller may proceed.
     */
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
                    'message' => SettingsController::adminNotConfiguredMessage('Webhooks'),
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
            'tab_enabled'       => Config::isSettingsTabEnabled(),
            'admin_protection'  => AdminAuth::protectionEnabled(),
            'admin_configured'  => AdminAuth::isConfigured(),
            'admin_unlocked'    => AdminAuth::gateState()['unlocked'],
            'admin_diagnostics' => AdminAuth::diagnostics(),
        ]);
    }

    /** GET /api/webhooks - list subscriptions, secrets masked. */
    public function index(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $out = [];
        foreach ($this->service->repository()->all() as $hook) {
            $out[] = $hook->toPublicArray();
        }
        return Response::json(['webhooks' => $out]);
    }

    /** POST /api/webhooks - create and return the secret ONCE. */
    public function create(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $this->readJsonBody();
        try {
            $hook = $this->service->create(
                label:  (string)($body['label'] ?? ''),
                url:    (string)($body['url'] ?? ''),
                secret: isset($body['secret']) && is_string($body['secret']) ? $body['secret'] : null,
                events: $this->readEvents($body),
                active: ($body['active'] ?? true) !== false,
            );
        } catch (InvalidArgumentException $e) {
            return Response::json([
                'error'   => 'invalid_webhook',
                'message' => $e->getMessage(),
            ], 400);
        }
        $public = $hook->toPublicArray();
        $public['secret'] = $hook->getSecret(); // one-time reveal
        return Response::json(['ok' => true, 'webhook' => $public], 201);
    }

    /** GET /api/webhooks/{id} - show one subscription, secret masked. */
    public function show(array $params): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $id = (int)($params['id'] ?? 0);
        $hook = $this->service->repository()->findById($id);
        if ($hook === null) {
            return Response::json(['error' => 'unknown_webhook'], 404);
        }
        return Response::json(['webhook' => $hook->toPublicArray()]);
    }

    /** POST /api/webhooks/{id} - update; null secret rotates it. */
    public function update(array $params): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $id = (int)($params['id'] ?? 0);
        if ($this->service->repository()->findById($id) === null) {
            return Response::json(['error' => 'unknown_webhook'], 404);
        }
        $body = $this->readJsonBody();
        if (!isset($body['label']) || !isset($body['url'])) {
            return Response::json([
                'error'   => 'missing_fields',
                'message' => 'label and url are required',
            ], 400);
        }
        try {
            $hook = $this->service->update(
                id:     $id,
                label:  (string)$body['label'],
                url:    (string)$body['url'],
                secret: isset($body['secret']) && is_string($body['secret']) && $body['secret'] !== ''
                    ? $body['secret']
                    : null, // rotate
                events: $this->readEvents($body),
                active: ($body['active'] ?? true) !== false,
            );
        } catch (InvalidArgumentException $e) {
            return Response::json([
                'error'   => 'invalid_webhook',
                'message' => $e->getMessage(),
            ], 400);
        }
        if ($hook === null) {
            return Response::json(['error' => 'unknown_webhook'], 404);
        }
        $public = $hook->toPublicArray();
        if (!isset($body['secret']) || !is_string($body['secret']) || $body['secret'] === '') {
            $public['secret'] = $hook->getSecret(); // rotated - reveal once
        }
        return Response::json(['ok' => true, 'webhook' => $public]);
    }

    /** POST /api/webhooks/{id}/toggle - enable/disable. */
    public function toggle(array $params): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $id = (int)($params['id'] ?? 0);
        $body = $this->readJsonBody();
        $active = ($body['active'] ?? null);
        if (!is_bool($active)) {
            return Response::json(['error' => 'missing_active', 'message' => 'active (bool) is required'], 400);
        }
        $hook = $this->service->repository()->toggleActive($id, $active);
        if ($hook === null) {
            return Response::json(['error' => 'unknown_webhook'], 404);
        }
        return Response::json(['ok' => true, 'webhook' => $hook->toPublicArray()]);
    }

    /** DELETE /api/webhooks/{id} - delete subscription. */
    public function destroy(array $params): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $id = (int)($params['id'] ?? 0);
        if (!$this->service->repository()->delete($id)) {
            return Response::json(['error' => 'unknown_webhook'], 404);
        }
        return Response::json(['ok' => true, 'id' => $id]);
    }

    /** GET /api/webhooks/{id}/deliveries - recent attempts for one sub. */
    public function deliveries(array $params): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $id = (int)($params['id'] ?? 0);
        if ($this->service->repository()->findById($id) === null) {
            return Response::json(['error' => 'unknown_webhook'], 404);
        }
        $out = [];
        foreach ($this->deliveries->recent($id) as $delivery) {
            $out[] = $delivery->toPublicArray();
        }
        return Response::json(['deliveries' => $out]);
    }

    /**
     * Extract the event list from a request body, defaulting to
     * ['run.completed'].
     *
     * @param  array<string,mixed> $body
     * @return list<string>
     */
    private function readEvents(array $body): array
    {
        if (!isset($body['events']) || !is_array($body['events'])) {
            return ['run.completed'];
        }
        $events = [];
        foreach ($body['events'] as $event) {
            if (is_string($event)) {
                $events[] = $event;
            }
        }
        return $events === [] ? ['run.completed'] : $events;
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
