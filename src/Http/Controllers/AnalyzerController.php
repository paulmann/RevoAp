<?php
/**
 * AnalyzerController: HTTP surface for the optional request analyzer.
 *
 * Endpoints:
 *   GET  /api/analyzer/settings  — current analyzer settings (admin-gated)
 *   POST /api/analyzer/settings  — toggle / pick provider+model
 *   POST /api/analyzer/classify  — preview classification for the supplied query
 *
 * The classify endpoint never logs the user query body — only the
 * resulting metadata. Admin gate mirrors Settings.
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

use App\Analyzer\RequestAnalyzer;
use App\Core\AdminAuth;
use App\Core\Config;
use App\Http\Response;
use App\Settings\SettingsPersist;

final class AnalyzerController
{
    public function __construct(
        private readonly RequestAnalyzer $analyzer,
    ) {}

    public function settings(): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        return Response::json([
            'enabled'  => $this->analyzer->isEnabled(),
            'provider' => $this->analyzer->provider(),
            'model'    => $this->analyzer->model(),
            'default'  => [
                'provider' => RequestAnalyzer::DEFAULT_PROVIDER,
                'model'    => RequestAnalyzer::DEFAULT_MODEL,
            ],
            // v1.8.50 — what the settings file on disk actually says, so a
            // host whose runtime.json is unreadable reports "stored state
            // unavailable" instead of showing config.php defaults as if the
            // operator had chosen them.
            'persisted' => SettingsPersist::persisted(),
        ]);
    }

    /**
     * POST /api/analyzer/settings.
     *
     * @param array<string,mixed>|null $body Injected by the offline tests
     *        (the CLI SAPI has no `php://input`); null reads the request.
     */
    public function saveSettings(?array $body = null): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        $body ??= $this->readJsonBody();
        $updates = [];
        if (array_key_exists('enabled', $body)) {
            $updates['analyzer.enabled'] = (bool)$body['enabled'];
        }
        if (array_key_exists('provider', $body) && is_string($body['provider'])) {
            $p = trim($body['provider']);
            if ($p !== '') {
                $updates['analyzer.provider'] = mb_substr($p, 0, 64);
            }
        }
        if (array_key_exists('model', $body) && is_string($body['model'])) {
            $m = trim($body['model']);
            if ($m !== '') {
                $updates['analyzer.model'] = mb_substr($m, 0, 191);
            }
        }
        if ($updates !== []) {
            // v1.8.50 — verified write. Reported symptom: "the analyzer does
            // not save either, but it says Saved". That was this endpoint
            // answering `ok` after a write that had not landed (a settings
            // file the PHP process cannot replace, e.g. one left owned by
            // root): the reload below re-read the OLD file, so the form
            // snapped back on the next load while the button said "Saved.".
            // A failed write is now a 500 naming the failing stage, the
            // target path and the filesystem facts.
            $persist = SettingsPersist::runtime($updates, Config::rootDir(), 'analyzer.settings');
            if (!$persist['ok']) { return $persist['response']; }
        }
        return Response::json([
            'ok'      => true,
            'updated' => array_keys($updates),
            'state'   => [
                'enabled'  => $this->analyzer->isEnabled(),
                'provider' => $this->analyzer->provider(),
                'model'    => $this->analyzer->model(),
            ],
            // Read back from var/config/runtime.json: the Models tab
            // compares this against the form before it claims the save took
            // effect, so a write that silently did nothing cannot render as
            // "Saved.".
            'persisted' => SettingsPersist::persisted(),
        ]);
    }

    public function classify(): string
    {
        if (($err = $this->guard()) !== null) { return $err; }
        $body = $this->readJsonBody();
        $query = is_string($body['query'] ?? null) ? trim((string)$body['query']) : '';
        if ($query === '') {
            return Response::json(['error' => 'invalid', 'message' => 'query is required'], 400);
        }
        $result = $this->analyzer->analyze($query);
        return Response::json($result);
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
}
