<?php
/**
 * Identity: who is making THIS request, for ownership decisions.
 *
 * RevoAp deliberately has TWO identity tables and this class is the only
 * place that folds them into one question:
 *
 *   - `revoap_admin_users` — the OPERATOR (console/RBAC accounts, migration
 *     023). An operator session sees everything; that is the job.
 *   - `users` — the END USER the pipeline writes (`council_sessions.user_id`,
 *     `revoap_jobs.user_id`, the usage ledger). An end-user session sees only
 *     rows it owns.
 *
 * A third table would be a bug, not a feature: it would mean two rows can
 * claim the same person, and every ownership check would have to decide which
 * one to believe.
 *
 * Resolution order in {@see self::fromRequest()}:
 *   1. admin — the operator gate is open for this request (AdminGate). This
 *      stays the v1 token gate until the RBAC login surface ships; when it
 *      does, `$_SESSION['revoap_admin_user_id']` upgrades the label from the
 *      masked token to the account name with NO change at any call site,
 *      because AuditLog::actorId() already reads exactly that key.
 *   2. user — an end-user session (`$_SESSION['revoap_user_id']`). Nothing
 *      sets that key yet: the end-user login is the next layer of the
 *      multi-user foundation (see docs/MULTI_USER.md). The plumbing is here
 *      now so ownership filtering has one resolver to be right in, instead
 *      of being re-derived per controller when login lands.
 *   3. anonymous — with a `strict` flag driven by the `multiuser.ownership`
 *      feature flag. Strict=false is the documented v1.8.6 behaviour (a
 *      single-operator install where the History tab is intentionally open);
 *      strict=true means an unauthenticated request sees NOTHING owned,
 *      which is what a multi-user install must do before logins exist.
 *
 * The value object is pure (no I/O in the constructor, no globals read at
 * call sites): services accept `?Identity` and stay testable; only
 * fromRequest() touches $_SESSION / AdminGate.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

namespace App\Auth;

use App\Http\AdminGate;

final class Identity
{
    public const KIND_ADMIN     = 'admin';
    public const KIND_USER      = 'user';
    public const KIND_ANONYMOUS = 'anonymous';

    /** $_SESSION key an end-user login must set. Reserved here so the login
     *  surface and every reader agree on one spelling from day one. */
    public const SESSION_USER_KEY = 'revoap_user_id';

    /** $_SESSION key the (pending) RBAC login sets for operators; already
     *  read by AuditLog::actorId() since migration 023. */
    public const SESSION_ADMIN_KEY = 'revoap_admin_user_id';

    private function __construct(
        public readonly string $kind,
        public readonly ?int $userId,
        public readonly string $label,
        public readonly bool $strict,
    ) {
    }

    /* ------------------------------------------------------------------ */

    /** An operator: sees every row, every user. */
    public static function admin(string $label = 'operator'): self
    {
        return new self(self::KIND_ADMIN, null, $label !== '' ? $label : 'operator', false);
    }

    /** An authenticated end user: sees only rows owned by $userId. */
    public static function user(int $userId, string $label = ''): self
    {
        return new self(self::KIND_USER, $userId > 0 ? $userId : null, $label, $userId > 0);
    }

    /**
     * Nobody authenticated.
     *
     * @param bool $strict when true (the `multiuser.ownership` flag is on),
     *                     owned reads answer EMPTY rather than ALL.
     */
    public static function anonymous(bool $strict = false): self
    {
        return new self(self::KIND_ANONYMOUS, null, 'anonymous', $strict);
    }

    /* ------------------------------------------------------------------ */

    public function isAdmin(): bool     { return $this->kind === self::KIND_ADMIN; }
    public function isUser(): bool      { return $this->kind === self::KIND_USER; }
    public function isAnonymous(): bool { return $this->kind === self::KIND_ANONYMOUS; }

    /**
     * The user id owned reads must be scoped to, or null when the request
     * is not scoped (operators, and anonymous on a legacy-open install).
     */
    public function scopedUserId(): ?int
    {
        return $this->isUser() ? $this->userId : null;
    }

    /**
     * True when this identity must see NOTHING: an anonymous request on an
     * install that turned ownership enforcement on, or a user identity that
     * cannot name its user (a malformed session must fail CLOSED — "no id"
     * turning into "no filter" is the exact inversion of what a scoped
     * viewer is promised). Kept separate from scopedUserId() because "no
     * rows" and "no filter" must not be the same answer — conflating them
     * is how an enforcement flag accidentally becomes a data leak
     * (null = everything).
     */
    public function seesNothing(): bool
    {
        return ($this->isAnonymous() && $this->strict)
            || ($this->isUser() && $this->userId === null);
    }

    /** True when this identity may see every row regardless of owner. */
    public function canSeeAll(): bool
    {
        return $this->isAdmin() || ($this->isAnonymous() && !$this->strict);
    }

    /**
     * Resolve the identity of the CURRENT request.
     *
     * @param bool|null $ownershipStrict cached answer of
     *        FeatureFlagRegistry::enabled('multiuser.ownership'); null lets
     *        this method answer anonymous(strict=false), the legacy shape,
     *        so callers without a flag registry (CLI, tests) keep working.
     */
    public static function fromRequest(?bool $ownershipStrict = false): self
    {
        // 1. Operator. AdminGate is the one predicate (protection flag AND
        //    presented token) — re-deriving it here is how two surfaces end
        //    up disagreeing about who an admin is.
        try {
            if (AdminGate::open()) {
                $label = 'operator';
                if (self::sessionActive()) {
                    $adminId = $_SESSION[self::SESSION_ADMIN_KEY] ?? null;
                    $adminName = $_SESSION['revoap_admin_username'] ?? null;
                    if (is_string($adminName) && $adminName !== '') {
                        $label = 'admin:' . $adminName;
                    } elseif (is_scalar($adminId) && $adminId !== '') {
                        $label = 'admin:' . $adminId;
                    }
                }
                return self::admin($label);
            }
        } catch (\Throwable) {
            // A gate that cannot answer (Config not booted in a CLI harness)
            // is a CLOSED gate — fall through to the weaker identities.
        }

        // 2. End user (reserved; no login surface sets this yet).
        if (self::sessionActive()) {
            $raw = $_SESSION[self::SESSION_USER_KEY] ?? null;
            if (is_scalar($raw) && $raw !== '' && (int)$raw > 0) {
                return self::user((int)$raw);
            }
        }

        // 3. Anonymous, strict only when the operator asked for enforcement.
        return self::anonymous((bool)$ownershipStrict);
    }

    /** $_SESSION readable AND started. CLI tests never start a session. */
    private static function sessionActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && isset($_SESSION);
    }
}
