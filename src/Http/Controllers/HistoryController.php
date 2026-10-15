<?php
/**
 * HistoryController: HTTP layer for the admin History tab.
 *
 * Visibility model (v1.8.6):
 *   1. `HistoryTab=true` in config.php — when false, returns 404
 *      history_disabled.
 *   2. Endpoints are intentionally public (no admin gate) so operators
 *      can inspect past sessions without unlocking Settings. Sensitive
 *      tabs (Settings, Logs, Security) remain admin-protected. Hosts
 *      that consider session contents sensitive should keep
 *      HistoryTab=false in config.php.
 *
 * Endpoints:
 *   GET  /api/history/status         — public probe (tab_enabled +
 *                                      admin state + db state).
 *   GET  /api/history/list           — paginated session list with
 *                                      optional ?q=<search>,
 *                                      ?status=<state>, ?limit, ?offset
 *                                      and pre-grouped year/month
 *                                      bucket counts for the sidebar.
 *   GET  /api/history/session/{id}   — full session detail: header,
 *                                      ordered steps, per-step model
 *                                      responses, ordered events.
 *   POST /api/history/session/{id}/complete
 *                                    — finish an interrupted session:
 *                                      rebuild only the pipeline stages
 *                                      that never produced a result, then
 *                                      mark the session completed.
 *                                      `?dry_run=1` returns the plan
 *                                      without writing anything;
 *                                      `?force=1` skips the "another
 *                                      worker is still running this
 *                                      session" guard.
 *
 * The controller never returns API keys, DSN credentials, Bearer
 * tokens or absolute paths — HistoryService runs every free-form
 * field through Logger::sanitizeScalar before serialization, and
 * SessionCompletionService does the same for its own payloads.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Admin\FeatureFlags\FeatureFlagRegistry;
use App\Auth\Identity;
use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\DatabaseHealth;
use App\History\HistoryService;
use App\History\SessionCompletionService;
use App\History\RunMarkdownExporter;
use App\Http\Response;
use App\Storage\SessionRepository;

final class HistoryController
{
    /**
     * @param FeatureFlagRegistry|null $flags    reads `multiuser.ownership`;
     *        null (CLI, tests, DB-less installs) keeps the legacy open shape.
     * @param SessionRepository|null   $sessions owner lookup for the WRITE
     *        paths (complete/export), which cannot be filtered in SQL.
     */
    public function __construct(
        private readonly HistoryService $history,
        private readonly ?SessionCompletionService $completion = null,
        private readonly ?FeatureFlagRegistry $flags = null,
        private readonly ?SessionRepository $sessions = null,
    ) {}

    /**
     * The identity this request acts as (v2.1.0, T5).
     *
     * Resolution is delegated to Identity::fromRequest so every surface
     * shares one answer; the only input from here is the operator's
     * `multiuser.ownership` enforcement switch.
     */
    private function viewer(): Identity
    {
        $strict = false;
        if ($this->flags !== null) {
            try {
                $strict = $this->flags->enabled('multiuser.ownership');
            } catch (\Throwable) {
                $strict = false;
            }
        }
        return Identity::fromRequest($strict);
    }

    /**
     * May the current viewer touch ONE session by id? Write paths need this
     * because there is no WHERE clause to own: complete() rebuilds pipeline
     * stages and export() writes the full run content to disk.
     *
     * Fail-closed for scoped viewers: if the owner cannot be established
     * (no repository wired, row vanished mid-request), the answer is no.
     * Unscoped viewers (operators, and anonymous on a legacy-open install)
     * keep the historical behaviour.
     */
    private function mayTouchSession(int $id, Identity $viewer): bool
    {
        if ($viewer->canSeeAll()) {
            return true;
        }
        if ($viewer->seesNothing()) {
            return false;
        }
        $scope = $viewer->scopedUserId();
        if ($scope === null || $this->sessions === null) {
            return false;
        }
        $session = $this->sessions->find($id);
        if ($session === null) {
            return false;
        }
        $owner = isset($session['user_id']) ? (int)$session['user_id'] : 0;
        return $owner === $scope;
    }

    public function status(): string
    {
        $health = DatabaseHealth::snapshot($this->history->db());
        return Response::json([
            'tab_enabled'       => Config::isHistoryTabEnabled(),
            'admin_protection'  => AdminAuth::protectionEnabled(),
            'admin_configured'  => AdminAuth::isConfigured(),
            'admin_unlocked'    => AdminAuth::gateState()['unlocked'],
            'admin_diagnostics' => AdminAuth::diagnostics(),
            'db_available'      => $this->history->isAvailable(),
            'db_migrated'       => $this->history->isAvailable() ? $this->history->isMigrated() : false,
            'db_health'         => $health,
        ]);
    }

    public function list(): string
    {
        if (($r = $this->requireTabEnabled()) !== null) { return $r; }
        if (!$this->history->isAvailable()) {
            $health = DatabaseHealth::snapshot($this->history->db());
            return Response::json([
                'ok'        => false,
                'error'     => 'no_database',
                'message'   => $health['message'],
                'db_health' => $health,
            ]);
        }
        if (!$this->history->isMigrated()) {
            $health = DatabaseHealth::snapshot($this->history->db());
            $missing = $health['missing_tables'];
            $detail = $missing !== []
                ? ' Missing tables: ' . implode(', ', $missing) . '.'
                : '';
            return Response::json([
                'ok'        => false,
                'error'     => 'migration_required',
                'message'   => 'Apply db/migrations/004_history_session_metadata.sql to enable the History tab.' . $detail,
                'db_health' => $health,
            ]);
        }
        $params = [
            'limit'  => isset($_GET['limit'])  ? (int)$_GET['limit']  : HistoryService::LIMIT_DEFAULT,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0,
            'q'      => isset($_GET['q'])      ? (string)$_GET['q']   : '',
            'status' => isset($_GET['status']) ? (string)$_GET['status'] : '',
        ];
        $result = $this->history->list($params, $this->viewer());
        return Response::json($result);
    }

    /**
     * @param array<string,string> $params
     */
    public function session(array $params): string
    {
        if (($r = $this->requireTabEnabled()) !== null) { return $r; }
        if (!$this->history->isAvailable()) {
            $health = DatabaseHealth::snapshot($this->history->db());
            return Response::json([
                'ok'        => false,
                'error'     => 'no_database',
                'message'   => $health['message'],
                'db_health' => $health,
            ]);
        }
        if (!$this->history->isMigrated()) {
            $health = DatabaseHealth::snapshot($this->history->db());
            return Response::json([
                'ok'        => false,
                'error'     => 'migration_required',
                'message'   => 'Apply db/migrations/004_history_session_metadata.sql to enable the History tab.',
                'db_health' => $health,
            ]);
        }
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        $detail = $this->history->detail($id, $this->viewer());
        if ($detail === null) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        return Response::json($detail);
    }

    /**
     * POST /api/history/session/{id}/complete
     *
     * Finishes an interrupted session by rebuilding exactly the pipeline
     * stages that never produced a result, reusing everything that is
     * already stored. Same visibility gates as the rest of the History
     * tab ({@see self::requireTabEnabled()} plus the database/migration
     * probes); no additional admin token is required, matching
     * GET /api/history/list and GET /api/history/session/{id}.
     *
     * Query flags:
     *   dry_run=1 — return the plan without writing anything to the
     *               database (no steps, no events, no session update).
     *   force=1   — proceed even when another worker still holds a run
     *               lock for this session.
     *
     * Status codes:
     *   200 — plan (dry run) or successful rebuild.
     *   400 — invalid id.
     *   404 — tab disabled, or the session does not exist.
     *   409 — already completed / nothing to repair / reconstruction in
     *         progress / a live run still owns the session.
     *   500 — the rebuild failed; the failure is journaled and the
     *         session is left retryable.
     *   `no_database` and `migration_required` keep the 200-with-ok:false
     *   shape used by GET /api/history/list so the client renders the
     *   same diagnostic instead of a generic error.
     *
     * @param array<string,string> $params
     */

    /**
     * POST /api/history/session/{id}/export
     *
     * R8 - write the run out as a file you can keep. Two files land in
     * `share/` next to index.php: the Markdown document, and the same
     * content as a self-contained, print-ready page. That page is how a PDF
     * is produced here - the operator opens it and prints - so the export
     * needs no PDF library and cannot drift from the document it came from.
     *
     * The names come from the session id and its timestamp only: run text
     * never reaches a path, so a question full of slashes or quotes cannot
     * steer the write out of `share/`.
     *
     * Same visibility gates as the rest of the History tab
     * ({@see self::requireTabEnabled()} plus the database/migration probes).
     *
     * Status codes: 200 written; 400 invalid id; 404 tab disabled or session
     * missing; 500 the share directory could not be created or written.
     *
     * @param array<string,string> $params
     */
    public function export(array $params): string
    {
        if (($r = $this->requireTabEnabled()) !== null) { return $r; }
        if (!$this->history->isAvailable()) {
            $health = DatabaseHealth::snapshot($this->history->db());

            return Response::json([
                'ok'        => false,
                'error'     => 'no_database',
                'message'   => $health['message'],
                'db_health' => $health,
            ]);
        }
        if (!$this->history->isMigrated()) {
            $health = DatabaseHealth::snapshot($this->history->db());

            return Response::json([
                'ok'        => false,
                'error'     => 'migration_required',
                'message'   => 'Apply db/migrations/004_history_session_metadata.sql to enable the History tab.',
                'db_health' => $health,
            ]);
        }
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        // v2.1.0 (T5) — the viewer flows into detail(), so a scoped identity
        // exporting somebody else's run gets the same 404 a missing session
        // gets. Export writes the FULL run content to disk; it is the last
        // place an ownership gap would be forgivable.
        $detail = $this->history->detail($id, $this->viewer());
        if ($detail === null) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $dir = dirname(__DIR__, 3) . '/share';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return Response::json([
                'ok'      => false,
                'error'   => 'share_not_writable',
                'message' => 'Could not create the share/ directory next to index.php.',
            ], 500);
        }

        $mdName   = RunMarkdownExporter::fileName($detail);
        $htmlName = RunMarkdownExporter::fileNameFor($detail, 'html');
        $mdBytes   = @file_put_contents($dir . '/' . $mdName, RunMarkdownExporter::render($detail));
        $htmlBytes = @file_put_contents($dir . '/' . $htmlName, RunMarkdownExporter::renderHtml($detail));
        if ($mdBytes === false || $htmlBytes === false) {
            return Response::json([
                'ok'      => false,
                'error'   => 'write_failed',
                'message' => 'The share/ directory is not writable by the web process.',
            ], 500);
        }

        return Response::json([
            'ok'         => true,
            'session_id' => $id,
            'files'      => [
                ['name' => $mdName, 'kind' => 'markdown', 'bytes' => $mdBytes, 'url' => '/share/' . rawurlencode($mdName)],
                ['name' => $htmlName, 'kind' => 'print', 'bytes' => $htmlBytes, 'url' => '/share/' . rawurlencode($htmlName)],
            ],
            'print_hint' => 'Open the print copy and use the browser Print dialog - Save as PDF.',
        ]);
    }
    public function complete(array $params): string
    {
        if (($r = $this->requireTabEnabled()) !== null) { return $r; }
        if (!$this->history->isAvailable()) {
            $health = DatabaseHealth::snapshot($this->history->db());
            return Response::json([
                'ok'        => false,
                'error'     => 'no_database',
                'message'   => $health['message'],
                'db_health' => $health,
            ]);
        }
        if (!$this->history->isMigrated()) {
            $health = DatabaseHealth::snapshot($this->history->db());
            return Response::json([
                'ok'        => false,
                'error'     => 'migration_required',
                'message'   => 'Apply db/migrations/004_history_session_metadata.sql to enable the History tab.',
                'db_health' => $health,
            ]);
        }
        if ($this->completion === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'completion_unavailable',
                'message' => 'Session completion is not wired on this host.',
            ], 503);
        }
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        // v2.1.0 (T5) — a scoped viewer may only repair its own runs. The
        // 404 (not 403) keeps existence of foreign sessions unconfirmed.
        if (!$this->mayTouchSession($id, $this->viewer())) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $dryRun = $this->flag('dry_run');
        $force  = $this->flag('force');
        try {
            $result = $this->completion->complete($id, $dryRun, $force);
        } catch (\Throwable $e) {
            return Response::json([
                'ok'         => false,
                'session_id' => $id,
                'error'      => 'completion_failed',
                'message'    => \App\Core\Logger::sanitizeScalar($e->getMessage()),
            ], 500);
        }
        return Response::json($result['body'], $result['status']);
    }

    /**
     * Truthy check for the `?flag=1|true|on|yes` query convention used
     * across the API.
     */
    private function flag(string $name): bool
    {
        $raw = $_GET[$name] ?? null;
        if (is_bool($raw)) {
            return $raw;
        }
        if (!is_scalar($raw)) {
            return false;
        }
        $v = strtolower(trim((string)$raw));
        return $v === '1' || $v === 'true' || $v === 'on' || $v === 'yes';
    }

    /**
     * Public visibility gate. v1.8.6 removed the admin token requirement
     * for History so the past-sessions browser is viewable without
     * unlocking Settings. The HistoryTab flag in config.php remains the
     * single switch operators use to hide the tab on hosts where
     * session contents are sensitive.
     */
    private function requireTabEnabled(): ?string
    {
        if (!Config::isHistoryTabEnabled()) {
            return Response::json([
                'error'   => 'history_disabled',
                'message' => 'History tab is disabled in config.php (HistoryTab=false).',
            ], 404);
        }
        return null;
    }
}
