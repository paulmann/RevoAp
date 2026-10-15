<?php
/**
 * AdminController: HTTP surface for the v2.0.0 operator subsystems.
 *
 * One controller for System / Feature flags / Maintenance / Localization, plus
 * the read side of the queue. They share three properties that would otherwise be
 * reimplemented five times, slightly differently each time:
 *
 *   - every route goes through {@see AdminGate::deny()} as its first statement;
 *   - every write is recorded in the hash-chained audit trail, including DENIED
 *     attempts (a denial is the more interesting event — it is either a mistake
 *     or a probe);
 *   - every write reports whether it actually persisted. An overlay that cannot
 *     be written must not be reported as saved: that is how an operator ends up
 *     debugging a setting they can see in the UI and the application does not
 *     apply.
 *
 * The heavier subsystems (Users/RBAC, Backups, Budgets, Scheduler, Notifications)
 * have their tables in migration 023 and their services under `src/Admin/`, but
 * their own controllers rather than being folded in here — each carries enough
 * domain rules (Argon2id parameter upgrades, TOTP envelope encryption, cron
 * parsing, HMAC-signed deliveries) that a shared controller would be a switch
 * statement with five unrelated jobs.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Admin\Audit\AuditLog;
use App\Admin\FeatureFlags\FeatureFlagRegistry;
use App\Admin\Maintenance\MaintenanceMode;
use App\Admin\SystemInfo\EnvironmentReport;
use App\Core\Config;
use App\Core\RuntimeConfig;
use App\Http\AdminGate;
use App\Http\Response;
use App\I18n\Locale;
use App\I18n\LocaleNegotiator;
use App\I18n\MessageCatalog;
use App\I18n\PluralRules;
use App\I18n\Translator;

final class AdminController
{
    public function __construct(
        private readonly string $rootDir,
        private readonly ?AuditLog $audit = null,
        private readonly ?FeatureFlagRegistry $flags = null,
    ) {
    }

    /* ------------------------------------------------------------------
     * System
     * ---------------------------------------------------------------- */

    /** GET /api/admin/system — the runtime report behind the System tab. */
    public function system(): string
    {
        if ($denied = AdminGate::deny('admin.system')) {
            $this->audit?->denied('admin.system.read', 'system');
            return $denied;
        }
        $report = EnvironmentReport::build($this->rootDir);
        $report['database_persistent'] = $this->audit?->isPersistent() ?? false;
        $report['audit'] = $this->audit === null
            ? ['available' => false]
            : ['available' => true, 'actions' => count($this->audit->actions())];
        return Response::json($report);
    }

    /* ------------------------------------------------------------------
     * Feature flags
     * ---------------------------------------------------------------- */

    /** GET /api/admin/flags */
    public function flags(): string
    {
        if ($denied = AdminGate::deny('admin.flags')) {
            $this->audit?->denied('flags.read', 'feature_flags');
            return $denied;
        }
        if ($this->flags === null) {
            return Response::json(['ok' => true, 'flags' => [], 'persistent' => false]);
        }
        return Response::json([
            'ok' => true,
            'flags' => $this->flags->all(),
            'rollouts' => [
                ['value' => 'all', 'label_key' => 'feature_flags.rollout_all'],
                ['value' => 'admins', 'label_key' => 'feature_flags.rollout_admins'],
                ['value' => 'percent', 'label_key' => 'feature_flags.rollout_percent'],
            ],
        ]);
    }

    /** POST /api/admin/flags */
    public function saveFlag(): string
    {
        if ($denied = AdminGate::deny('admin.flags')) {
            $this->audit?->denied('flags.write', 'feature_flags');
            return $denied;
        }
        if ($this->flags === null) {
            return Response::json(['ok' => false, 'error' => 'flags_unavailable'], 503);
        }
        $body = $this->jsonBody();
        $flag = trim((string)($body['flag'] ?? ''));
        if ($flag === '') {
            return Response::json(['ok' => false, 'error' => 'missing_flag'], 400);
        }
        if (!in_array($flag, FeatureFlagRegistry::knownFlags(), true)) {
            // Refusing an unregistered flag is what keeps the table from filling
            // with keys no code path reads.
            return Response::json([
                'ok' => false, 'error' => 'unknown_flag',
                'known' => FeatureFlagRegistry::knownFlags(),
            ], 400);
        }

        $before = $this->flags->all()[$flag] ?? null;
        $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $rollout = strtolower(trim((string)($body['rollout'] ?? 'all')));
        $percent = max(0, min(100, (int)($body['percent'] ?? 100)));

        $result = $this->flags->set($flag, $enabled, $rollout, $percent);
        // The action name carries the outcome, not just the verb. A ternary
        // whose branches are identical (which this was) compiles and passes
        // review, and leaves the trail queryable only by the separate `result`
        // column — so `WHERE action = 'flags.write.failed'`, the question an
        // operator actually asks after an incident, returns nothing.
        $this->audit?->record(
            $result['ok'] ? 'flags.write' : 'flags.write.failed',
            $before === null ? [] : ['flag' => $before],
            ['flag' => $flag, 'enabled' => $enabled, 'rollout' => $rollout, 'percent' => $percent],
            $result['ok'] ? AuditLog::RESULT_OK : AuditLog::RESULT_ERROR,
            'feature_flag',
            $flag,
            $result['error']
        );

        return Response::json([
            'ok' => $result['ok'],
            ...( $result['error'] !== null ? ['error' => $result['error']] : []),
            'flags' => $this->flags->all(),
        ], $result['ok'] ? 200 : 500);
    }

    /** POST /api/admin/flags/reset */
    public function resetFlags(): string
    {
        if ($denied = AdminGate::deny('admin.flags')) {
            $this->audit?->denied('flags.reset', 'feature_flags');
            return $denied;
        }
        $removed = $this->flags?->reset() ?? 0;
        $this->audit?->record('flags.reset', ['removed' => $removed], [], AuditLog::RESULT_OK, 'feature_flags');
        return Response::json(['ok' => true, 'removed' => $removed, 'flags' => $this->flags?->all() ?? []]);
    }

    /* ------------------------------------------------------------------
     * Maintenance mode
     * ---------------------------------------------------------------- */

    /** GET /api/admin/maintenance */
    public function maintenance(): string
    {
        if ($denied = AdminGate::deny('admin.maintenance')) {
            $this->audit?->denied('maintenance.read', 'maintenance');
            return $denied;
        }
        return Response::json(['ok' => true] + MaintenanceMode::state());
    }

    /** POST /api/admin/maintenance */
    public function saveMaintenance(): string
    {
        if ($denied = AdminGate::deny('admin.maintenance')) {
            $this->audit?->denied('maintenance.write', 'maintenance');
            return $denied;
        }
        $body = $this->jsonBody();
        $enable = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $before = MaintenanceMode::state();

        if ($enable) {
            $message = trim((string)($body['message'] ?? ''));
            $eta = trim((string)($body['eta'] ?? ''));
            $allow = $body['allow_ips'] ?? [];
            $result = MaintenanceMode::enable(
                $message,
                $eta === '' ? null : $eta,
                is_array($allow) ? $allow : (is_string($allow) ? preg_split('/[\r\n,]+/', $allow) ?: [] : [])
            );
        } else {
            $result = MaintenanceMode::disable();
        }

        // Distinct action names for enable / disable / failure. "Who turned
        // maintenance off, and when" is the first question after an incident,
        // and it should be a WHERE clause rather than a read of every diff.
        $action = !$result['ok']
            ? 'maintenance.write.failed'
            : ($enable ? 'maintenance.enable' : 'maintenance.disable');
        $this->audit?->record(
            $action,
            ['enabled' => $before['enabled']],
            ['enabled' => $enable, 'message' => $body['message'] ?? null, 'eta' => $body['eta'] ?? null],
            $result['ok'] ? AuditLog::RESULT_OK : AuditLog::RESULT_ERROR,
            'maintenance',
            null,
            $result['error']
        );

        return Response::json([
            'ok' => (bool)$result['ok'],
            ...($result['error'] !== null ? ['error' => $result['error']] : []),
            'state' => $result['state'],
        ], $result['ok'] ? 200 : 500);
    }

    /* ------------------------------------------------------------------
     * Localization admin
     * ---------------------------------------------------------------- */

    /** GET /api/admin/localization — coverage across all five catalogues. */
    public function localization(): string
    {
        if ($denied = AdminGate::deny('admin.localization')) {
            $this->audit?->denied('localization.read', 'i18n');
            return $denied;
        }
        MessageCatalog::flush();
        $reference = MessageCatalog::load(Locale::English);
        $rows = [];
        foreach (Locale::all() as $locale) {
            $catalog = MessageCatalog::load($locale);
            $diff = $catalog->diffAgainst($reference);
            $rows[] = [
                'code'           => $locale->value,
                'native_name'    => $locale->nativeName(),
                'english_name'   => $locale->englishName(),
                'dir'            => $locale->dir(),
                'rtl'            => $locale->isRtl(),
                'ok'             => $catalog->ok,
                'problem'        => $catalog->problem,
                'keys'           => $catalog->count(),
                'reference_keys' => $reference->count(),
                'coverage'       => $reference->count() === 0
                    ? 1.0
                    : max(0.0, min(1.0, ($reference->count() - count($diff['missing'])) / $reference->count())),
                'missing'        => array_slice($diff['missing'], 0, 200),
                'missing_total'  => count($diff['missing']),
                'extra'          => array_slice($diff['extra'], 0, 200),
                'empty'          => $diff['non_string'],
                'plurals'        => PluralRules::categoriesFor($locale),
                'writable'       => is_writable(MessageCatalog::path($locale)),
            ];
        }
        return Response::json([
            'ok'          => true,
            'default'     => (string)Config::tryGet('ui.locale', 'en'),
            'active'      => Translator::locale()->value,
            'reference'   => Locale::English->value,
            'locales'     => $rows,
            'problems'    => MessageCatalog::problems(),
            'translator'  => Translator::stats(),
            'negotiated'  => (new LocaleNegotiator($_SERVER, $_GET, $_COOKIE))->negotiate()['source'],
        ]);
    }

    /** POST /api/admin/localization/default */
    public function setDefaultLocale(): string
    {
        if ($denied = AdminGate::deny('admin.localization')) {
            $this->audit?->denied('localization.write', 'i18n');
            return $denied;
        }
        $body = $this->jsonBody();
        $locale = Locale::fromTag((string)($body['locale'] ?? ''));
        if ($locale === null) {
            return Response::json([
                'ok' => false, 'error' => 'unsupported_locale',
                'supported' => array_map(static fn(Locale $l): string => $l->value, Locale::all()),
            ], 400);
        }
        $before = (string)Config::tryGet('ui.locale', 'en');
        $result = RuntimeConfig::updateChecked(['ui.locale' => $locale->value]);
        $this->audit?->record(
            $result['ok'] ? 'localization.default' : 'localization.default.failed',
            ['ui.locale' => $before],
            ['ui.locale' => $locale->value],
            $result['ok'] ? AuditLog::RESULT_OK : AuditLog::RESULT_ERROR,
            'i18n',
            'ui.locale'
        );
        return Response::json([
            'ok' => (bool)$result['ok'],
            ...($result['ok'] ? [] : ['error' => 'persist_failed', 'detail' => $result['error']]),
            'default' => $locale->value,
        ], $result['ok'] ? 200 : 500);
    }

    /* ------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        // A size cap before decoding: the bodies here are a flag name or a
        // maintenance message, so anything over 64 KiB is a mistake or a probe.
        if (!is_string($raw) || $raw === '' || strlen($raw) > 65_536) {
            return [];
        }
        $decoded = json_decode($raw, true, 8);
        return is_array($decoded) ? $decoded : [];
    }
}
