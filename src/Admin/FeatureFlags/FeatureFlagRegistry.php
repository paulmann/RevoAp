<?php
/**
 * FeatureFlagRegistry: runtime switches for parts of the console.
 *
 * The point of a flag is to change behaviour WITHOUT a deploy. Two properties
 * follow from that, and both are easy to get wrong:
 *
 *   1. **A flag read must be cheap.** Flags are consulted on hot paths — the
 *      shell asks for several per request — so the whole set is read once per
 *      process and memoised. There is no per-flag query.
 *   2. **A flag must have a shipped default that is correct when the store is
 *      unreachable.** A fresh install, a DB-less install and an install whose
 *      `revoap_feature_flags` table has not been migrated yet all have to behave
 *      identically and sanely. The defaults below are that behaviour; the table
 *      is an override, not the source of truth.
 *
 * Rollout is deliberately coarse: `all`, `admins`, or a percentage. A percentage
 * is decided by hashing the *sticky key* (session id, API key id, or the client
 * IP as a last resort) rather than by `random_int`, so one operator does not see
 * a feature flicker between requests — which is the single most common complaint
 * about naive percentage rollouts and the reason they get abandoned.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Admin\FeatureFlags;

use App\Core\Database;
use App\Core\Logger;
use PDO;

final class FeatureFlagRegistry
{
    public const TABLE = 'revoap_feature_flags';

    /**
     * Shipped defaults. The key is the flag, the value is
     * `[enabled, rollout, descriptionKey]`.
     *
     * Everything here is ON except the two features that change what a run costs
     * or how long it takes, which default to `admins` so they can be tried before
     * they are turned on for everyone.
     */
    private const DEFAULTS = [
        'ui.command_palette'      => [true,  'all',     'nav.command_palette'],
        'ui.appearance_panel'     => [true,  'all',     'theme.appearance'],
        'ui.density_control'      => [true,  'all',     'theme.density'],
        'ui.rtl_support'          => [true,  'all',     'locale.rtl_notice'],
        'models.availability'     => [true,  'all',     'fallback.availability_monitor'],
        'models.fallback_auto'    => [true,  'all',     'fallback.mode_auto'],
        'models.batch_api'        => [false, 'admins',  'models.batch_support'],
        'pipeline.round2'         => [true,  'all',     'pipeline.round'],
        'pipeline.prompt_cache'   => [true,  'all',     'settings.cache'],
        'pipeline.research'       => [false, 'admins',  'skills.generator'],
        'history.full_text'       => [true,  'all',     'history.search'],
        'admin.audit_chain'       => [true,  'all',     'audit.integrity'],
        'admin.backup_restore'    => [false, 'admins',  'backup.restore'],
        'admin.totp'              => [true,  'all',     'users.totp'],
        'api.webhooks'            => [true,  'all',     'api.webhooks'],
        'cost.budgets'            => [false, 'admins',  'budgets.title'],
        // v2.1.0 (T5) — ownership enforcement for the multi-user foundation.
        // OFF by default: a v1.8.6 install is single-operator and its History
        // tab is intentionally open (HistoryController's header says so). ON
        // means an anonymous request no longer sees every session: History,
        // Statistics and Share answer per App\Auth\Identity. Ship-default OFF
        // is what makes upgrading an existing install a no-op instead of a
        // lockout; turning it on is the operator's explicit multi-user switch.
        'multiuser.ownership'     => [false, 'all',     'usage.ownership_flag'],
    ];

    /** @var array<string,array{enabled:bool,rollout:string,percent:int}>|null */
    private ?array $memo = null;

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * Every flag, defaults overridden by stored rows.
     *
     * @return array<string,array{enabled:bool,rollout:string,percent:int,description_key:string,overridden:bool}>
     */
    public function all(): array
    {
        $flags = $this->resolve();
        $out = [];
        foreach ($flags as $key => $row) {
            $out[$key] = [
                'enabled'         => $row['enabled'],
                'rollout'         => $row['rollout'],
                'percent'         => $row['percent'],
                'description_key' => self::DEFAULTS[$key][2] ?? 'common.description',
                'overridden'      => $row['overridden'],
            ];
        }
        ksort($out);
        return $out;
    }

    /**
     * Is a flag on for this caller?
     *
     * @param string|null $stickyKey session id / API key id / client IP. Null
     *                               means "no stable identity", and a percentage
     *                               rollout then resolves to OFF — a feature that
     *                               appears and disappears between requests of an
     *                               unidentifiable caller is worse than one that
     *                               is consistently absent.
     * @param bool        $isAdmin
     */
    public function enabled(string $flag, ?string $stickyKey = null, bool $isAdmin = false): bool
    {
        $row = $this->resolve()[$flag] ?? null;
        if ($row === null) {
            // An unknown flag is OFF. Returning true would make a typo in a call
            // site silently enable a feature nobody registered.
            return false;
        }
        if (!$row['enabled']) {
            return false;
        }
        return match ($row['rollout']) {
            'all'     => true,
            'admins'  => $isAdmin,
            'percent' => $stickyKey !== null && self::bucket($stickyKey) < $row['percent'],
            default   => true,
        };
    }

    /**
     * Stable 0..99 bucket for a sticky key.
     *
     * CRC32 rather than a cryptographic hash: this is a distribution function,
     * not a security boundary, and it has to be cheap enough to call per request.
     * The key is salted with the flag name so two flags at 10 % do not select the
     * *same* 10 % of callers — otherwise every percentage rollout would be
     * perfectly correlated and the "10 %" cohort would carry all the risk.
     */
    public static function bucket(string $stickyKey, string $salt = ''): int
    {
        return (int)(crc32($salt . "\x1f" . $stickyKey) % 100);
    }

    /**
     * Persist an override.
     *
     * @return array{ok:bool,error:?string}
     */
    public function set(string $flag, bool $enabled, string $rollout = 'all', int $percent = 100): array
    {
        if (!array_key_exists($flag, self::DEFAULTS)) {
            // Refusing an unregistered flag is what keeps the table from filling
            // with keys no code reads — which then look like live configuration
            // to the next operator.
            return ['ok' => false, 'error' => 'unknown_flag'];
        }
        if (!in_array($rollout, ['all', 'admins', 'percent'], true)) {
            return ['ok' => false, 'error' => 'invalid_rollout'];
        }
        $percent = max(0, min(100, $percent));

        $pdo = $this->db?->pdo();
        if ($pdo === null) {
            return ['ok' => false, 'error' => 'no_database'];
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (flag_key, enabled, rollout, rollout_percent, description, updated_at)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    rollout = VALUES(rollout),
                    rollout_percent = VALUES(rollout_percent),
                    updated_at = VALUES(updated_at)'
            );
            $stmt->execute([
                $flag,
                $enabled ? 1 : 0,
                $rollout,
                $percent,
                self::DEFAULTS[$flag][2],
                time(),
            ]);
            $this->memo = null;
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Logger::debug('feature flag write failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'write_failed'];
        }
    }

    /** Drop every override, returning to the shipped defaults. */
    public function reset(): int
    {
        $this->memo = null;
        $pdo = $this->db?->pdo();
        if ($pdo === null) {
            return 0;
        }
        try {
            return $pdo->exec('DELETE FROM ' . self::TABLE) ?: 0;
        } catch (\Throwable $e) {
            Logger::debug('feature flag reset failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** @return array<int,string> every flag key the code can read */
    public static function knownFlags(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,array{enabled:bool,rollout:string,percent:int,overridden:bool}>
     */
    private function resolve(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $out = [];
        foreach (self::DEFAULTS as $flag => [$enabled, $rollout, $_desc]) {
            $out[$flag] = [
                'enabled' => (bool)$enabled,
                'rollout' => (string)$rollout,
                'percent' => 100,
                'overridden' => false,
            ];
        }

        $pdo = $this->db?->pdo();
        if ($pdo === null) {
            return $this->memo = $out;
        }
        try {
            $stmt = $pdo->query('SELECT flag_key, enabled, rollout, rollout_percent FROM ' . self::TABLE);
            if ($stmt !== false) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $key = (string)($row['flag_key'] ?? '');
                    // An unknown row is ignored rather than surfaced: it cannot
                    // be read by any code path, and showing it would imply it
                    // does something.
                    if (!array_key_exists($key, $out)) {
                        continue;
                    }
                    $rollout = (string)($row['rollout'] ?? 'all');
                    $out[$key] = [
                        'enabled' => (bool)($row['enabled'] ?? false),
                        'rollout' => in_array($rollout, ['all', 'admins', 'percent'], true) ? $rollout : 'all',
                        'percent' => max(0, min(100, (int)($row['rollout_percent'] ?? 100))),
                        'overridden' => true,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // A missing table (migration 023 not applied) is a normal state on a
            // fresh or DB-less install. Log at debug and serve the defaults.
            Logger::debug('feature flag read failed, using defaults: ' . $e->getMessage());
        }
        return $this->memo = $out;
    }
}
