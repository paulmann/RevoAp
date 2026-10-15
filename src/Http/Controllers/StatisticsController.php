<?php
/**
 * StatisticsController: HTTP layer for the admin Statistic tab.
 *
 * Visibility model (v1.8.6):
 *   1. `StatisticTab=true` in config.php — when false, returns 404
 *      statistics_disabled.
 *   2. Endpoints are intentionally public (no admin gate) so operators
 *      can review aggregated usage without unlocking Settings.
 *      Sensitive tabs (Settings, Logs, Security) remain admin-protected.
 *      Hosts that consider statistics sensitive should keep
 *      StatisticTab=false in config.php.
 *
 * Endpoints:
 *   GET /api/statistics/status          — public probe (tab_enabled +
 *                                         admin state + db state +
 *                                         supported periods).
 *   GET /api/statistics/summary?period= — aggregated totals + per-LLM
 *                                         breakdown for the given
 *                                         period (all/year/quarter/
 *                                         week/day/hour). Defaults to
 *                                         "all" when omitted/unknown.
 *
 * Numeric statistics are returned; no free-form text leaves the
 * service, so secret-leakage paths are limited to the diagnostic
 * "message" string surfaced when a query fails — which is run through
 * Logger::sanitizeScalar before serialization.
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

use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\DatabaseHealth;
use App\Http\Response;
use App\Statistics\StatisticsPeriod;
use App\Statistics\StatisticsService;

final class StatisticsController
{
    public function __construct(
        private readonly StatisticsService $statistics,
    ) {}

    public function status(): string
    {
        $health = DatabaseHealth::snapshot($this->statistics->db());
        return Response::json([
            'tab_enabled'       => Config::isStatisticTabEnabled(),
            'admin_protection'  => AdminAuth::protectionEnabled(),
            'admin_configured'  => AdminAuth::isConfigured(),
            'admin_unlocked'    => AdminAuth::gateState()['unlocked'],
            'admin_diagnostics' => AdminAuth::diagnostics(),
            'db_available'      => $this->statistics->isAvailable(),
            'db_health'         => $health,
            'periods'           => StatisticsPeriod::SUPPORTED,
            'default_period'    => StatisticsPeriod::DEFAULT,
        ]);
    }

    public function summary(): string
    {
        if (($r = $this->requireTabEnabled()) !== null) { return $r; }
        if (!$this->statistics->isAvailable()) {
            $health = DatabaseHealth::snapshot($this->statistics->db());
            return Response::json([
                'ok'        => false,
                'error'     => 'no_database',
                'message'   => $health['message'],
                'db_health' => $health,
            ]);
        }
        $period = isset($_GET['period']) ? (string)$_GET['period'] : StatisticsPeriod::DEFAULT;
        $result = $this->statistics->summary($period);
        return Response::json($result);
    }

    /**
     * Public visibility gate. v1.8.6 removed the admin token requirement
     * for the Statistic tab so aggregated usage data is viewable without
     * unlocking Settings. The StatisticTab flag in config.php remains
     * the single switch operators use to hide the tab on hosts where
     * usage figures are sensitive.
     */
    private function requireTabEnabled(): ?string
    {
        if (!Config::isStatisticTabEnabled()) {
            return Response::json([
                'error'   => 'statistics_disabled',
                'message' => 'Statistic tab is disabled in config.php (StatisticTab=false).',
            ], 404);
        }
        return null;
    }
}
