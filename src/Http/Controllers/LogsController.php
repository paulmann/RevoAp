<?php
/**
 * LogsController: HTTP layer for the admin Logs tab.
 *
 * Every endpoint requires:
 *   1. `LogsTab=true` in config.php — when false, returns 404 logs_disabled.
 *   2. Admin token (env / .admin.key, presented via header / cookie / query).
 *
 * Endpoints:
 *   GET  /api/logs/status       — public probe (tab_enabled + admin state)
 *   GET  /api/logs/list         — list whitelisted files with size/mtime
 *   GET  /api/logs/tail?slug=…&lines=… — last N lines, sanitized
 *   POST /api/logs/clear        — truncate one whitelisted file
 *
 * Output is always sanitized through Logger::sanitizeScalar — log lines
 * may contain secrets (especially the debug log when something fails to
 * authenticate against a provider) and we MUST never echo them raw.
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
use App\Logs\LogsService;

final class LogsController
{
    public function __construct(
        private readonly LogsService $logs,
    ) {}

    private function requireAdmin(): ?string
    {
        if (!Config::isLogsTabEnabled()) {
            return Response::json([
                'error'   => 'logs_disabled',
                'message' => 'Logs tab is disabled in config.php (LogsTab=false).',
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
                    'message' => SettingsController::adminNotConfiguredMessage('Logs'),
                    'admin_diagnostics' => AdminAuth::diagnostics(),
                ], 503);
            }
            return Response::json(['error' => 'unauthorized', 'message' => 'admin token required'], 401);
        }
        return null;
    }

    /** Public, no-auth — used by the front-end to decide whether to render the tab. */
    public function status(): string
    {
        return Response::json([
            'tab_enabled'       => Config::isLogsTabEnabled(),
            'admin_protection'  => AdminAuth::protectionEnabled(),
            'admin_configured'  => AdminAuth::isConfigured(),
            'admin_unlocked'    => AdminAuth::gateState()['unlocked'],
            'admin_diagnostics' => AdminAuth::diagnostics(),
        ]);
    }

    public function list(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        return Response::json([
            'files' => $this->logs->list(),
        ]);
    }

    public function tail(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $slug  = isset($_GET['slug']) && is_string($_GET['slug']) ? trim((string)$_GET['slug']) : '';
        $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : LogsService::TAIL_DEFAULT;
        if ($slug === '') {
            return Response::json(['error' => 'missing_slug'], 400);
        }
        $tail = $this->logs->tail($slug, $lines);
        if ($tail === null) {
            return Response::json(['error' => 'unknown_log'], 404);
        }
        return Response::json($tail);
    }

    public function clear(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body = $this->readJsonBody();
        $slug = is_string($body['slug'] ?? null) ? trim((string)$body['slug']) : '';
        if ($slug === '') {
            return Response::json(['error' => 'missing_slug'], 400);
        }
        if ($this->logs->resolve($slug) === null) {
            return Response::json(['error' => 'unknown_log'], 404);
        }
        if (!$this->logs->clear($slug)) {
            return Response::json([
                'ok'    => false,
                'error' => 'clear_failed',
                'message' => 'Could not truncate the log file. Check filesystem permissions.',
            ], 500);
        }
        return Response::json(['ok' => true, 'slug' => $slug]);
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
