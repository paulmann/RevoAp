<?php
/**
 * ShareController: HTTP endpoints for read-only public sharing of a
 * completed council session.
 *
 * The flow:
 *   1. The Chat UI calls `POST /api/share/session` with `{session_id}`
 *      to mint (or look up) a token. The handler returns `{token, url}`.
 *   2. Recipients open `/shared?token=…` (or `/share/{token}`) and the
 *      controller renders the read-only shared view embedded with a
 *      snapshot of the query, final answer, ordered steps, model
 *      responses and consensus statistics.
 *
 * The shared view does NOT expose: model selection controls, settings,
 * logs, security, history, statistics, or any admin/run controls. The
 * page is intentionally a separate template that does not include the
 * SPA navigation or model-selector scripts.
 *
 * Tokens are 64-char opaque random hex (256 bits of entropy). They are
 * persisted in the `shared_sessions` table (migration 006) — see
 * App\Storage\ShareRepository.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.2.0
 * @since    2026-05-12
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseHealth;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use App\Helpers\TemplateHelper;
use App\Admin\FeatureFlags\FeatureFlagRegistry;
use App\History\HistoryService;
use App\Http\Response;
use App\Settings\ConfigRepository;
use App\Storage\SchemaMissingException;
use App\Storage\ShareRepository;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;
use PDO;
use PDOException;

final class ShareController
{
    /**
     * @param FeatureFlagRegistry|null $flags reads `multiuser.ownership`
     *        (v2.1.0, T5); null keeps the legacy open shape for CLI/tests.
     */
    public function __construct(
        private readonly ShareRepository    $share,
        private readonly SessionRepository  $sessions,
        private readonly StepRepository     $steps,
        private readonly ?HistoryService    $history = null,
        private readonly null|PDO|Database  $db = null,
        private readonly ?FeatureFlagRegistry $flags = null,
    ) {}

    /**
     * May the current request publish ONE session? (v2.1.0, T5)
     *
     * Minting a share republishes the full run content behind a token URL —
     * an ownership gap here is a data leak with a front door. Operators may
     * share any session; a scoped end user only its own; an anonymous request
     * on an enforcement-enabled install none at all. Unattributed sessions
     * (user_id NULL) belong to no end user and cannot be shared by one.
     *
     * @param array<string,mixed> $session row from SessionRepository::find()
     */
    private function mayShare(array $session): bool
    {
        $strict = false;
        if ($this->flags !== null) {
            try {
                $strict = $this->flags->enabled('multiuser.ownership');
            } catch (\Throwable) {
                $strict = false;
            }
        }
        $viewer = \App\Auth\Identity::fromRequest($strict);
        if ($viewer->canSeeAll()) {
            return true;
        }
        $scope = $viewer->scopedUserId();
        if ($scope === null) {
            return false;   // anonymous-strict or a user identity with no id
        }
        $owner = isset($session['user_id']) ? (int)$session['user_id'] : 0;
        return $owner === $scope;
    }

    /**
     * POST /api/share/session
     * Body: {"session_id": 123}
     * Response: {"ok": true, "token": "…", "url": "https://host/shared?token=…"}
     */
    public function create(): string
    {
        $payload = $this->readJsonBody();
        $sessionId = (int)($payload['session_id'] ?? $_GET['session_id'] ?? 0);
        if ($sessionId <= 0) {
            return Response::json([
                'ok'    => false,
                'error' => 'session_id_required',
                'message' => 'session_id is required to mint a share token',
            ], 400);
        }
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return Response::json([
                'ok'    => false,
                'error' => 'session_not_found',
                'message' => 'No council session was found with the supplied id.',
            ], 404);
        }
        // v2.1.0 (T5) — ownership. Answering with the SAME 404 a missing
        // session gets is deliberate: "exists but is not yours" must not be
        // a distinguishable answer, or the share endpoint doubles as a
        // session-id oracle.
        if (!$this->mayShare($session)) {
            return Response::json([
                'ok'    => false,
                'error' => 'session_not_found',
                'message' => 'No council session was found with the supplied id.',
            ], 404);
        }
        try {
            $token = $this->share->shareSession($sessionId);
        } catch (SchemaMissingException $e) {
            Logger::warn('share.create.schema_missing', [
                'session_id' => $sessionId,
                'table'      => $e->table,
                'migration'  => $e->migration,
                'message'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return Response::json([
                'ok'             => false,
                'error'          => 'migration_required',
                'message'        => 'Sharing is unavailable: the shared_sessions table is missing from the live database. '
                    . 'Apply migration ' . ($e->migration !== '' ? $e->migration : 'db/migrations/006_shared_sessions.sql')
                    . ' (Settings → Migrations → Apply) and try again.',
                'missing_table'  => $e->table,
                'migration'      => $e->migration !== '' ? $e->migration : 'db/migrations/006_shared_sessions.sql',
                'db_health'      => $this->safeDbHealth(),
            ], 409);
        } catch (PDOException $e) {
            Logger::warn('share.create.db_error', [
                'session_id' => $sessionId,
                'sqlstate'   => is_array($e->errorInfo ?? null) ? ($e->errorInfo[0] ?? '') : '',
                'message'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return Response::json([
                'ok'      => false,
                'error'   => 'database_unavailable',
                'message' => 'The database is currently unavailable. Try again in a moment.',
                'reason'  => [
                    'class'    => 'PDOException',
                    'sqlstate' => is_array($e->errorInfo ?? null) ? (string)($e->errorInfo[0] ?? '') : '',
                    'message'  => Logger::sanitizeScalar($e->getMessage()),
                ],
            ], 503);
        } catch (\Throwable $e) {
            Logger::warn('share.create.failed', [
                'session_id' => $sessionId,
                'class'      => self::shortClass($e),
                'message'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return Response::json([
                'ok'      => false,
                'error'   => 'share_failed',
                'message' => 'Could not create share link.',
                'reason'  => [
                    'class'   => self::shortClass($e),
                    'message' => Logger::sanitizeScalar($e->getMessage()),
                ],
            ], 500);
        }
        return Response::json([
            'ok'    => true,
            'token' => $token,
            'url'   => $this->buildShareUrl($token),
            'persistent' => $this->share->isPersistent(),
        ]);
    }

    /**
     * GET /api/share/session (no token) — POST is the correct verb for
     * minting; surface a precise 405 method_not_allowed instead of the
     * generic 404 the router would otherwise emit.
     */
    public function methodNotAllowed(): string
    {
        if (!headers_sent()) {
            header('Allow: POST');
        }
        return Response::json([
            'ok'      => false,
            'error'   => 'method_not_allowed',
            'message' => 'Use POST /api/share/session with {"session_id": <id>} to mint a share link.',
            'allow'   => 'POST',
        ], 405);
    }

    /**
     * Build a sanitized DatabaseHealth snapshot so error responses can
     * include the missing-table list operators need without exposing
     * the password / DSN secret bits.
     *
     * @return array<string,mixed>|null
     */
    private function safeDbHealth(): ?array
    {
        if ($this->db === null) {
            return null;
        }
        try {
            return DatabaseHealth::snapshot($this->db);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function shortClass(\Throwable $e): string
    {
        $parts = explode('\\', get_class($e));
        return (string)end($parts);
    }

    /**
     * GET /share/{token} or GET /shared?token=…
     * Renders the read-only shared view.
     *
     * @param array<string,string> $params
     */
    public function view(array $params): string
    {
        $token = trim((string)($params['token'] ?? $_GET['token'] ?? ''));
        if ($token === '') {
            return $this->renderError(400, 'No token supplied.',
                'The share link is missing its token. Ask the sender to copy the link again.');
        }
        $sessionId = $this->share->findSessionId($token);
        if ($sessionId === null) {
            return $this->renderError(404, 'Share link not found.',
                'This share link was revoked, expired, or never existed.');
        }
        $payload = $this->loadSharedPayload($sessionId);
        if ($payload === null) {
            return $this->renderError(404, 'Session unavailable.',
                'The session referenced by this link is no longer available.');
        }
        return $this->renderSharedView($payload, $token);
    }

    /**
     * GET /api/share/session/{token}
     * JSON variant of view() used by automation. Same redaction rules.
     *
     * @param array<string,string> $params
     */
    public function json(array $params): string
    {
        $token = trim((string)($params['token'] ?? $_GET['token'] ?? ''));
        if ($token === '') {
            return Response::json([
                'ok' => false, 'error' => 'token_required',
                'message' => 'Token is required',
            ], 400);
        }
        $sessionId = $this->share->findSessionId($token);
        if ($sessionId === null) {
            return Response::json([
                'ok' => false, 'error' => 'token_not_found',
                'message' => 'Share token not found or revoked',
            ], 404);
        }
        $payload = $this->loadSharedPayload($sessionId);
        if ($payload === null) {
            return Response::json([
                'ok' => false, 'error' => 'session_unavailable',
            ], 404);
        }
        return Response::json([
            'ok'      => true,
            'token'   => $token,
            'shared'  => $payload,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadSharedPayload(int $sessionId): ?array
    {
        // Prefer the HistoryService-style payload when the database is
        // configured: it already redacts secrets via Logger::sanitize
        // and joins steps + responses + events in a stable shape.
        if ($this->history !== null && $this->history->isAvailable()) {
            $detail = $this->history->detail($sessionId);
            if (is_array($detail) && (($detail['ok'] ?? false) === true)) {
                return [
                    'session_id' => $sessionId,
                    'session'    => $detail['session'] ?? [],
                    'steps'      => $detail['steps']   ?? [],
                    'events'     => $detail['events']  ?? [],
                ];
            }
        }
        // Fallback: rebuild from in-memory or basic repositories.
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return null;
        }
        return [
            'session_id' => $sessionId,
            'session'    => $this->redactSessionRow($session),
            'steps'      => $this->scrubSteps($this->steps->listStepsBySession($sessionId)),
            'events'     => $this->scrubEvents($this->steps->listEventsBySession($sessionId)),
        ];
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function redactSessionRow(array $row): array
    {
        return [
            'id'               => (int)($row['id'] ?? 0),
            'created_at'       => (string)($row['created_at'] ?? ''),
            'completed_at'     => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
            'status'           => (string)($row['status'] ?? ''),
            'consensus_status' => (string)($row['consensus_status'] ?? ''),
            'consensus_score'  => isset($row['consensus_score']) ? (float)$row['consensus_score'] : null,
            'total_tokens'     => (int)($row['total_tokens'] ?? 0),
            'duration_ms'      => (int)($row['duration_ms'] ?? 0),
            'query_text'       => Logger::sanitizeScalar((string)($row['query_text'] ?? '')),
            'final_answer'     => Logger::sanitizeScalar((string)($row['final_answer'] ?? '')),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function scrubSteps(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $meta = $r['meta'] ?? null;
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            $out[] = [
                'id'          => (int)($r['id'] ?? 0),
                'step_key'    => (string)($r['step_key'] ?? ''),
                'label'       => (string)($r['label'] ?? ''),
                'order_index' => (int)($r['order_index'] ?? 0),
                'status'      => (string)($r['status'] ?? ''),
                'summary'     => $r['summary'] !== null
                                    ? Logger::sanitizeScalar((string)$r['summary']) : null,
                'meta'        => is_array($meta) ? Logger::sanitize($meta) : null,
                'started_at'  => (string)($r['started_at'] ?? ''),
                'ended_at'    => $r['ended_at'] !== null ? (string)$r['ended_at'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function scrubEvents(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $payload = $r['payload'] ?? null;
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                $payload = is_array($decoded) ? $decoded : null;
            }
            $out[] = [
                'id'         => (int)($r['id'] ?? 0),
                'step_id'    => $r['step_id'] !== null ? (int)$r['step_id'] : null,
                'event_type' => (string)($r['event_type'] ?? ''),
                'payload'    => is_array($payload) ? Logger::sanitize($payload) : null,
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    private function buildShareUrl(string $token): string
    {
        $base = trim((string)Config::get('app.url', ''));
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base   = $scheme . '://' . $host;
        }
        return rtrim($base, '/') . '/shared?token=' . rawurlencode($token);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderSharedView(array $payload, string $token): string
    {
        $session = is_array($payload['session'] ?? null) ? $payload['session'] : [];
        // v1.8.33: enrich the snapshot with a statistics block and the
        // operator-configured default theme so the read-only page
        // renders with the same shell as the main app.
        $payload['stats'] = self::buildStats($payload);
        $ui = $this->uiPreferences();
        $payload['ui'] = $ui;
        $renderTheme = $this->renderTheme($ui['theme']);
        $body = TemplateHelper::render('shared.html', [
            'title'        => 'Shared council result · RevoAp',
            'app_name'     => Config::get('app.name', 'RevoAp'),
            'app_version'  => Config::get('app.version', '1.0.0'),
            'asset_version'=> $this->assetVersion(),
            'token'        => $token,
            'snapshot'     => $this->encodeSnapshot($payload),
            'query_text'   => (string)($session['query_text'] ?? ''),
            'final_answer' => (string)($session['final_answer'] ?? ''),
            'status'       => (string)($session['consensus_status'] ?? ''),
            'score'        => isset($session['consensus_score']) ? number_format((float)$session['consensus_score'], 3) : '—',
            'created_at'   => (string)($session['created_at'] ?? ''),
            'ui_theme'     => $renderTheme,
        ] + $this->shellVars($ui['theme']));
        return Response::html($body);
    }

    /**
     * Compute aggregate statistics from the persisted session payload.
     * The numbers shown on the shared page come exclusively from this
     * function — no live counters, no connector lookups, no pricing
     * tables. When a value is unknown the field is omitted and the
     * front-end renders a dash.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function buildStats(array $payload): array
    {
        $session = is_array($payload['session'] ?? null) ? $payload['session'] : [];
        $steps   = is_array($payload['steps']   ?? null) ? $payload['steps']   : [];

        $stats = [
            'duration_ms'      => (int)($session['duration_ms']  ?? 0),
            'total_tokens'     => (int)($session['total_tokens'] ?? 0),
            'consensus_status' => (string)($session['consensus_status'] ?? ''),
            'consensus_score'  => isset($session['consensus_score']) && $session['consensus_score'] !== null
                                    ? (float)$session['consensus_score']
                                    : null,
            'step_count'       => count($steps),
        ];

        /**
         * Per-model aggregate.
         * @var array<string,array{
         *     provider:string,model:string,
         *     responses:int,total_tokens:int,prompt_tokens:int,
         *     completion_tokens:int,latency_total_ms:int,cost:float,
         *     cached_tokens:int
         * }> $byModel
         */
        $byModel = [];
        $responseCount  = 0;
        $promptTokens   = 0;
        $completionTok  = 0;
        $latencyTotal   = 0;
        $costTotal      = 0.0;
        $costSeen       = false;
        $cacheHits      = 0;
        $cacheSeen      = false;

        foreach ($steps as $step) {
            if (!is_array($step)) { continue; }
            $responses = is_array($step['responses'] ?? null) ? $step['responses'] : [];
            foreach ($responses as $r) {
                if (!is_array($r)) { continue; }
                $responseCount++;
                $provider = (string)($r['provider'] ?? '');
                $model    = (string)($r['model'] ?? '');
                $id       = $provider !== '' ? $provider . '/' . $model : $model;
                if ($id === '') { $id = 'unknown'; }
                $tt = (int)($r['total_tokens'] ?? 0);
                $pt = (int)($r['prompt_tokens'] ?? 0);
                $ct = (int)($r['completion_tokens'] ?? 0);
                $lat = (int)($r['latency_ms'] ?? 0);
                $promptTokens  += $pt;
                $completionTok += $ct;
                $latencyTotal  += $lat;

                // Optional cost / cache fields live inside normalized_response.
                $norm = is_array($r['normalized'] ?? null) ? $r['normalized'] : null;
                $cost = null;
                $cached = null;
                if ($norm !== null) {
                    if (isset($norm['cost']) && is_numeric($norm['cost'])) {
                        $cost = (float)$norm['cost'];
                    }
                    if (isset($norm['cached_tokens']) && is_numeric($norm['cached_tokens'])) {
                        $cached = (int)$norm['cached_tokens'];
                    }
                }
                if ($cost !== null) { $costSeen = true; $costTotal += $cost; }
                if ($cached !== null) {
                    $cacheSeen = true;
                    if ($cached > 0) { $cacheHits++; }
                }

                if (!isset($byModel[$id])) {
                    $byModel[$id] = [
                        'provider' => $provider,
                        'model'    => $model,
                        'responses' => 0,
                        'total_tokens' => 0,
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0,
                        'latency_total_ms' => 0,
                        'cost' => 0.0,
                        'cached_tokens' => 0,
                    ];
                }
                $byModel[$id]['responses']++;
                $byModel[$id]['total_tokens']     += $tt;
                $byModel[$id]['prompt_tokens']    += $pt;
                $byModel[$id]['completion_tokens']+= $ct;
                $byModel[$id]['latency_total_ms'] += $lat;
                if ($cost !== null)  { $byModel[$id]['cost']          += $cost; }
                if ($cached !== null){ $byModel[$id]['cached_tokens'] += $cached; }
            }
        }

        $stats['model_count']      = count($byModel);
        $stats['response_count']   = $responseCount;
        $stats['prompt_tokens']    = $promptTokens;
        $stats['completion_tokens']= $completionTok;
        $stats['avg_latency_ms']   = $responseCount > 0 ? (int)round($latencyTotal / $responseCount) : 0;
        if ($costSeen) {
            $stats['estimated_cost_usd'] = round($costTotal, 6);
        }
        if ($cacheSeen) {
            $stats['cache_hits'] = $cacheHits;
        }

        // If session total_tokens was not persisted (older rows), fall
        // back to the sum we just computed.
        if ($stats['total_tokens'] === 0 && ($promptTokens + $completionTok) > 0) {
            $stats['total_tokens'] = $promptTokens + $completionTok;
        }

        // Most / least / fastest / slowest call-outs. Each carries the
        // facts the front-end displays (responses, total_tokens, avg
        // latency); the labels are decided client-side.
        $top = null; $weakest = null; $fastest = null; $slowest = null;
        foreach ($byModel as $id => $m) {
            $entry = [
                'id'             => $id,
                'provider'       => $m['provider'],
                'model'          => $m['model'],
                'responses'      => $m['responses'],
                'total_tokens'   => $m['total_tokens'],
                'avg_latency_ms' => $m['responses'] > 0 ? (int)round($m['latency_total_ms'] / $m['responses']) : 0,
            ];
            if ($top === null || $m['total_tokens'] > $top['total_tokens']) {
                $top = $entry;
            }
            if ($weakest === null || $m['total_tokens'] < $weakest['total_tokens']) {
                $weakest = $entry;
            }
            if ($entry['avg_latency_ms'] > 0
                && ($fastest === null || $entry['avg_latency_ms'] < $fastest['avg_latency_ms'])) {
                $fastest = $entry;
            }
            if ($entry['avg_latency_ms'] > 0
                && ($slowest === null || $entry['avg_latency_ms'] > $slowest['avg_latency_ms'])) {
                $slowest = $entry;
            }
        }
        if ($top !== null)     { $stats['top_contributor']     = $top; }
        if ($weakest !== null) { $stats['weakest_contributor'] = $weakest; }
        if ($fastest !== null) { $stats['fastest_model']       = $fastest; }
        if ($slowest !== null) { $stats['slowest_model']       = $slowest; }

        return $stats;
    }

    /**
     * Resolve the default UI theme. Reads from app_config when the
     * table exists; otherwise returns the conservative default so the
     * page still renders on a host mid-migration.
     *
     * @return array{theme:string}
     */
    private function uiPreferences(): array
    {
        $pdo = $this->resolvePdo();
        if ($pdo === null) {
            return ['theme' => 'dark'];
        }
        try {
            $repo = new ConfigRepository($pdo);
            return $repo->getUiPreferences();
        } catch (\Throwable) {
            return ['theme' => 'dark'];
        }
    }

    private function resolvePdo(): ?PDO
    {
        if ($this->db instanceof PDO) {
            return $this->db;
        }
        if ($this->db instanceof Database) {
            try {
                return $this->db->run(static fn(PDO $pdo): PDO => $pdo);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * The template's `data-theme` attribute must be a concrete value
     * ("light"|"dark"). When configured to "system" we fall back to
     * dark for SSR — the page also exposes a manual toggle so the
     * viewer can override.
     */
    private function renderTheme(string $configured): string
    {
        if ($configured === 'light' || $configured === 'dark') {
            return $configured;
        }
        return 'dark';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function encodeSnapshot(array $payload): string
    {
        // The snapshot is consumed by the read-only shared.js script which
        // hydrates the page with the audit log. Encoded as JSON inside a
        // <script type="application/json"> tag — never inlined into
        // attribute or executable context.
        return JsonHelper::encode($payload, false);
    }

    private function renderError(int $status, string $title, string $body): string
    {
        $ui = $this->uiPreferences();
        $html = TemplateHelper::render('shared.html', [
            'title'        => 'RevoAp · Shared',
            'app_name'     => Config::get('app.name', 'RevoAp'),
            'app_version'  => Config::get('app.version', '1.0.0'),
            'asset_version'=> $this->assetVersion(),
            'token'        => '',
            'snapshot'     => JsonHelper::encode([
                'error' => $title,
                'detail' => $body,
                'ui'    => $ui,
            ], false),
            'query_text'   => '',
            'final_answer' => '',
            'status'       => 'unavailable',
            'score'        => '—',
            'created_at'   => '',
            'ui_theme'     => $this->renderTheme($ui['theme']),
        ] + $this->shellVars($ui['theme']));
        return Response::html($html, $status);
    }

    /**
     * The v2 shell variables shared.html now needs.
     *
     * The public share page is the one surface an ANONYMOUS visitor reaches, so
     * it is also the one where a wrong `dir` or a missing nonce is most
     * visible: an Arabic reader landing on a shared link would get a
     * left-to-right page with the stylesheet blocked by the CSP.
     *
     * Locale is resolved the same way as the main shell (cookie, then
     * Accept-Language, then the operator default) but the share page never
     * WRITES a cookie. Setting one from a public endpoint would let any page
     * that links to a share token pin the visitor's language, and a public
     * endpoint that mutates state is a CSRF surface for no benefit.
     *
     * @param  string $uiTheme 'light'|'dark'|'system' from app_config
     * @return array<string,string>
     */
    private function shellVars(string $uiTheme): array
    {
        $negotiated = (new \App\I18n\LocaleNegotiator($_SERVER, [], $_COOKIE))->negotiate();
        $locale = $negotiated['locale'];
        \App\I18n\Translator::setLocale($locale);

        $allowed = static function (mixed $value, array $choices, string $fallback): string {
            $v = is_string($value) ? strtolower(trim($value)) : '';
            return in_array($v, $choices, true) ? $v : $fallback;
        };

        $accent = $allowed(
            $_COOKIE['revoap_accent'] ?? null,
            ['indigo', 'violet', 'blue', 'cyan', 'emerald', 'amber', 'rose', 'slate'],
            $allowed(Config::get('ui.accent', 'indigo'),
                ['indigo', 'violet', 'blue', 'cyan', 'emerald', 'amber', 'rose', 'slate'], 'indigo')
        );
        $density = $allowed(
            $_COOKIE['revoap_density'] ?? null,
            ['cozy', 'comfortable', 'compact'],
            $allowed(Config::get('ui.density', 'comfortable'),
                ['cozy', 'comfortable', 'compact'], 'comfortable')
        );

        $bootPath = dirname(__DIR__, 3) . '/assets/js/theme-boot.js';
        $boot = is_file($bootPath) ? (string)file_get_contents($bootPath) : '';
        // A `</script>` inside the inlined boot source would terminate the
        // block early. There is none today; escaping it makes that impossible
        // to introduce by accident.
        $boot = str_replace('</script', '<\/script', $boot);

        $catalog = \App\I18n\Translator::export($locale);
        $payload = json_encode(
            ['locale' => $locale->value, 'dir' => $locale->dir(), 'messages' => $catalog],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($payload)) {
            $payload = json_encode(['locale' => $locale->value, 'dir' => $locale->dir(), 'messages' => new \stdClass()]);
        }

        $resolved = $uiTheme;
        if ($uiTheme === 'system') {
            $hint = $_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] ?? '';
            $resolved = (is_string($hint) && stripos($hint, 'dark') !== false) ? 'dark' : 'light';
        }

        return [
            'locale'            => $locale->value,
            'locale_dir'        => $locale->dir(),
            'ui_accent'         => $accent,
            'ui_density'        => $density,
            'resolved_theme'    => $resolved,
            'csp_nonce'         => \App\Http\Response::nonce(),
            'theme_boot_inline' => $boot,
            'i18n_catalog'      => (string)$payload,
        ];
    }

    private function assetVersion(): string
    {
        return (string)Config::get('app.version', '1.0.0');
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return $_POST;
        }
        $parsed = JsonHelper::tryDecode($raw);
        return is_array($parsed) ? $parsed : [];
    }
}
