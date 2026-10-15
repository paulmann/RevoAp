<?php
/**
 * SystemHealthController: HTTP layer for the R7 system health panel.
 *
 * One endpoint, read-only:
 *
 *   GET /api/health/system - every check with its explanation and the
 *                            action that answers it, plus the first-run
 *                            checklist. Public, like the rest of
 *                            `/api/health/*`, because a brand-new install
 *                            has to be able to read why it is not working
 *                            *before* an admin key exists.
 *
 * **Repairs are not here on purpose.** Every action the report offers is an
 * existing, already-gated endpoint (`POST /api/settings/migrations/apply`,
 * `POST /api/worker/restart`, `POST /api/models/actualize`) or a command
 * the operator runs on the host. A second "fix" API would be a second place
 * to keep the same safety rules, and the panel would be able to drift from
 * the cards it duplicates. Host-level facts - file ownership, restarting a
 * service - are returned as `command` actions marked `external`, and the
 * panel shows the command instead of running it: no root, no sudo, no shell
 * from PHP, and nothing that comes in on the request is ever executed.
 *
 * Secret hygiene matches `/api/health`: counts, versions, root-relative
 * paths, worker identity and copy/paste commands - never a key, a DSN
 * password or an absolute host path.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-24
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Logger;
use App\Http\Response;
use App\Settings\SystemHealthService;

final class SystemHealthController
{
    public function __construct(private readonly SystemHealthService $service) {}

    /**
     * GET /api/health/system
     *
     * Always 200 while the service can answer at all: the envelope carries
     * the verdict (`ok` + `overall`), so a monitor reads the JSON rather
     * than a status code. A probe that fails answers `ok: false` with the
     * reason, never a 500 HTML page.
     */
    public function index(): string
    {
        $report = $this->service->report();

        Logger::info('health.system.probe', [
            'overall'   => (string)($report['overall'] ?? 'unknown'),
            'error'     => (int)($report['summary']['error'] ?? 0),
            'warn'      => (int)($report['summary']['warn'] ?? 0),
            'unknown'   => (int)($report['summary']['unknown'] ?? 0),
        ]);

        return Response::json($report);
    }
}
