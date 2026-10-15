-- =====================================================================
-- 023_v2_admin_and_fallback.sql — RevoAp 2.0.0 subsystems
--
-- Tables introduced
--   revoap_model_availability   cross-run health verdict per model
--   revoap_fallback_rules       operator-authored substitution rules
--   revoap_audit_log            append-only, hash-chained admin trail
--   revoap_admin_users          RBAC accounts (Argon2id + optional TOTP)
--   revoap_admin_sessions       server-side session registry, revocable
--   revoap_feature_flags        runtime feature switches
--   revoap_budgets              spend limits per scope + period
--   revoap_budget_alerts        fired thresholds (once per period each)
--   revoap_scheduled_tasks      cron-driven recurring jobs
--   revoap_task_runs            per-execution record for the above
--   revoap_backups              backup manifest + integrity
--   revoap_notification_channels   where alerts go
--   revoap_notification_deliveries  delivery attempts + signatures
--
-- Every statement is idempotent (CREATE TABLE IF NOT EXISTS / guarded
-- ALTER), so this file is safe to re-apply and safe to import against an
-- already-populated database. It ships no DML: the application owns every
-- row lifecycle, and a migration that seeds data is a migration that
-- silently overwrites an operator's choices on the second apply.
--
-- Charset is utf8mb4 throughout — model ids, audit diffs and Arabic
-- translations all live in these tables.
--
-- @project RevoAp   @since 2.0.0
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- Cross-run model availability
-- @table-version revoap_model_availability 1.0.0
--
-- One row per `provider/model`. Counters are windowed: the repository
-- halves them per elapsed window on read, so a model that recovered
-- converges back to `healthy` without a scheduled job.
--
-- `model_id` is the natural key. It is 191 characters, not 255, because
-- that is the longest prefix MySQL will index under utf8mb4 with the
-- default 767-byte InnoDB limit — 191 * 4 = 764. A longer key would make
-- the UNIQUE index fail on older row formats.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_model_availability (
    model_id             VARCHAR(191)   NOT NULL,
    provider             VARCHAR(64)    NOT NULL DEFAULT '',
    model                VARCHAR(191)   NOT NULL DEFAULT '',
    status               VARCHAR(16)    NOT NULL DEFAULT 'unknown',
    successes            INT UNSIGNED   NOT NULL DEFAULT 0,
    failures             INT UNSIGNED   NOT NULL DEFAULT 0,
    success_rate         DECIMAL(6,5)   NULL,
    avg_latency_ms       DECIMAL(12,2)  NULL,
    p95_latency_ms       DECIMAL(12,2)  NULL,
    last_success_at      INT UNSIGNED   NULL,
    last_failure_at      INT UNSIGNED   NULL,
    last_probed_at       INT UNSIGNED   NULL,
    last_error           VARCHAR(500)   NULL,
    -- JSON array of UnavailableReason values, most recent first, capped at 8.
    recent_reasons       VARCHAR(255)   NULL,
    consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    note                 VARCHAR(255)   NULL,
    updated_at           INT UNSIGNED   NOT NULL DEFAULT 0,
    PRIMARY KEY (model_id),
    INDEX idx_availability_status (status),
    INDEX idx_availability_provider (provider),
    INDEX idx_availability_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Operator-authored substitution rules
-- @table-version revoap_fallback_rules 1.0.0
--
-- A rule is `when <condition> then <action>`, evaluated in priority order
-- before the scored ranking in AlternativeResolver. Rules exist because a
-- score is a good default and a bad policy: an operator who knows that
-- gpt-5.5-pro must NEVER be substituted by a cheaper model needs a hard
-- statement, not a weight.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_fallback_rules (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120)   NOT NULL,
    enabled       TINYINT(1)     NOT NULL DEFAULT 1,
    priority      SMALLINT       NOT NULL DEFAULT 100,
    -- subject: which models the rule applies to. NULL = every model.
    match_model   VARCHAR(191)   NULL,
    match_provider VARCHAR(64)   NULL,
    match_reason  VARCHAR(32)    NULL,
    -- action
    action        VARCHAR(24)    NOT NULL DEFAULT 'substitute',
    action_model  VARCHAR(191)   NULL,
    created_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    updated_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    INDEX idx_fallback_rules_enabled (enabled, priority),
    INDEX idx_fallback_rules_model (match_model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit log
-- @table-version revoap_audit_log 1.0.0
--
-- Append-only. The application never issues UPDATE or DELETE against this
-- table, and the repository exposes no method that could. Each row stores
-- the SHA-256 of the previous row's hash, so removing or editing a row at
-- the database level breaks the chain and `verifyChain()` reports exactly
-- where. That is the difference between "a log" and "evidence".
--
-- `before_json` / `after_json` hold the DIFF, not the whole object, and
-- both pass through the secret redactor before being written — an audit
-- trail that leaks the API key it was recording a change to is worse than
-- no trail at all.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_audit_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    actor_type    VARCHAR(16)    NOT NULL DEFAULT 'admin',
    actor_id      VARCHAR(191)   NULL,
    actor_label   VARCHAR(191)   NOT NULL DEFAULT '',
    action        VARCHAR(96)    NOT NULL,
    target_type   VARCHAR(64)    NOT NULL DEFAULT '',
    target_id     VARCHAR(191)   NULL,
    result        VARCHAR(16)    NOT NULL DEFAULT 'ok',
    detail        VARCHAR(500)   NULL,
    before_json   MEDIUMTEXT     NULL,
    after_json    MEDIUMTEXT     NULL,
    ip            VARBINARY(16)  NULL,
    user_agent    VARCHAR(255)   NULL,
    request_id    VARCHAR(64)    NULL,
    prev_hash     CHAR(64)       NOT NULL DEFAULT '',
    entry_hash    CHAR(64)       NOT NULL DEFAULT '',
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_action (action),
    INDEX idx_audit_actor (actor_type, actor_id),
    INDEX idx_audit_target (target_type, target_id),
    INDEX idx_audit_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Administrator accounts (RBAC)
-- @table-version revoap_admin_users 1.0.0
--
-- Separate from `users`, which is the pipeline's per-end-user identity
-- table (external_id + profile). Conflating them would mean a pipeline
-- user could be promoted to administrator by editing one column, and it
-- would put the console's own credentials in the same table as customer
-- identities that a data-export request has to cover.
--
-- `password_hash` is Argon2id via password_hash() with the default cost
-- parameters, which PHP raises over time — the stored hash records the
-- parameters it used, and password_needs_rehash() drives a transparent
-- upgrade on the next successful login.
--
-- `totp_secret` is stored ENCRYPTED, not hashed: TOTP has to recompute the
-- code from the secret on every login, so a one-way hash is not usable.
-- The envelope key comes from `security.encryption_key` in config; an
-- install without one cannot enable 2FA, which is enforced in code rather
-- than by a nullable column that quietly stores plaintext.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_admin_users (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username          VARCHAR(64)    NOT NULL,
    display_name      VARCHAR(120)   NOT NULL DEFAULT '',
    email             VARCHAR(191)   NULL,
    role              VARCHAR(24)    NOT NULL DEFAULT 'viewer',
    password_hash     VARCHAR(255)   NOT NULL DEFAULT '',
    status            VARCHAR(16)    NOT NULL DEFAULT 'active',
    failed_attempts   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until      INT UNSIGNED   NULL,
    totp_enabled      TINYINT(1)     NOT NULL DEFAULT 0,
    totp_secret_enc   VARBINARY(512) NULL,
    totp_recovery_enc VARBINARY(2048) NULL,
    permissions_json  TEXT           NULL,
    last_login_at     INT UNSIGNED   NULL,
    last_login_ip     VARBINARY(16)  NULL,
    password_changed_at INT UNSIGNED NULL,
    created_at        INT UNSIGNED   NOT NULL DEFAULT 0,
    updated_at        INT UNSIGNED   NOT NULL DEFAULT 0,
    -- No standalone PRIMARY KEY (id) here: the column above already declares
    -- `AUTO_INCREMENT PRIMARY KEY`, and a second declaration is ERROR 1068
    -- (Multiple primary key defined) — the import dies and every table after
    -- this one never gets created. Caught by the T6 beta pass on a live
    -- MariaDB import and pinned by SmokeTest's double-PK invariant.
    UNIQUE KEY uq_admin_users_username (username),
    UNIQUE KEY uq_admin_users_email (email),
    INDEX idx_admin_users_role (role),
    INDEX idx_admin_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Revocable admin sessions
-- @table-version revoap_admin_sessions 1.0.0
--
-- PHP's own session store cannot answer "show this account's active
-- sessions" or "end every other session", both of which an operator needs
-- after a lost laptop. This registry mirrors the session id so those two
-- operations are a DELETE, and gives the idle timeout somewhere authoritative
-- to live when several php-fpm workers share no memory.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_admin_sessions (
    -- Named `session_id`, not `id`: this is the PHP session identifier (an
    -- opaque string), not a surrogate key. The project convention — asserted by
    -- SmokeTest — is that every column literally called `id` is an UNSIGNED
    -- auto-increment primary key, and a VARCHAR PK called `id` would both break
    -- that check and mislead anyone reading the schema.
    session_id    VARCHAR(128)   NOT NULL,
    admin_user_id INT UNSIGNED   NULL,
    created_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    last_seen_at  INT UNSIGNED   NOT NULL DEFAULT 0,
    expires_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    ip            VARBINARY(16)  NULL,
    user_agent    VARCHAR(255)   NULL,
    csrf_token    CHAR(64)       NOT NULL DEFAULT '',
    PRIMARY KEY (session_id),
    INDEX idx_admin_sessions_user (admin_user_id),
    INDEX idx_admin_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Feature flags
-- @table-version revoap_feature_flags 1.0.0
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_feature_flags (
    flag_key      VARCHAR(96)    NOT NULL,
    enabled       TINYINT(1)     NOT NULL DEFAULT 0,
    rollout       VARCHAR(16)    NOT NULL DEFAULT 'all',
    rollout_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
    description   VARCHAR(500)   NULL,
    updated_by    VARCHAR(191)   NULL,
    updated_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    PRIMARY KEY (flag_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Budgets and the alerts they fire
-- @table-version revoap_budgets 1.0.0
-- @table-version revoap_budget_alerts 1.0.0
--
-- `spent_*` is denormalised on purpose. Recomputing a period's spend from
-- `council_model_responses` on every page view is a full scan of the largest
-- table in the schema; a rollup the scheduler refreshes is O(1). The rollup
-- is never authoritative for money — the cost report still reads the source
-- rows — it is only authoritative for the LIMIT CHECK, which needs to be
-- fast and is allowed to be a few seconds stale.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_budgets (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(120)   NOT NULL,
    scope          VARCHAR(16)    NOT NULL DEFAULT 'global',
    scope_value    VARCHAR(191)   NULL,
    currency       CHAR(3)        NOT NULL DEFAULT 'USD',
    amount         DECIMAL(14,4)  NOT NULL DEFAULT 0,
    period         VARCHAR(12)    NOT NULL DEFAULT 'monthly',
    action_at_limit VARCHAR(16)   NOT NULL DEFAULT 'warn',
    thresholds     VARCHAR(120)   NOT NULL DEFAULT '50,80,95',
    spent_amount   DECIMAL(14,4)  NOT NULL DEFAULT 0,
    period_start   INT UNSIGNED   NOT NULL DEFAULT 0,
    period_end     INT UNSIGNED   NOT NULL DEFAULT 0,
    enabled        TINYINT(1)     NOT NULL DEFAULT 1,
    created_at     INT UNSIGNED   NOT NULL DEFAULT 0,
    updated_at     INT UNSIGNED   NOT NULL DEFAULT 0,
    INDEX idx_budgets_scope (scope, scope_value),
    INDEX idx_budgets_enabled (enabled, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revoap_budget_alerts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    budget_id    INT UNSIGNED   NOT NULL,
    threshold    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    kind         VARCHAR(16)    NOT NULL DEFAULT 'threshold',
    fired_at     INT UNSIGNED   NOT NULL DEFAULT 0,
    period_key   VARCHAR(24)    NOT NULL DEFAULT '',
    spent        DECIMAL(14,4)  NOT NULL DEFAULT 0,
    amount       DECIMAL(14,4)  NOT NULL DEFAULT 0,
    notified     TINYINT(1)     NOT NULL DEFAULT 0,
    INDEX idx_budget_alerts_budget (budget_id, period_key, threshold),
    INDEX idx_budget_alerts_fired (fired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Scheduled tasks and their runs
-- @table-version revoap_scheduled_tasks 1.0.0
-- @table-version revoap_task_runs 1.0.0
--
-- `next_run_at` is denormalised from the cron expression rather than parsed
-- on every poll: the scheduler entry point runs from cron every minute and
-- must decide "is there anything to do?" in one indexed query.
--
-- `locked_by` + `locked_until` implement the overlap guard. A task whose
-- previous run is still going is skipped when `on_overlap='skip'`, which is
-- what stops a slow catalogue refresh from stacking twelve copies of itself
-- after an hour of provider timeouts.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_scheduled_tasks (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_key      VARCHAR(96)    NOT NULL,
    label_key     VARCHAR(120)   NOT NULL DEFAULT '',
    cron          VARCHAR(64)    NOT NULL DEFAULT '0 * * * *',
    enabled       TINYINT(1)     NOT NULL DEFAULT 0,
    timeout_sec   INT UNSIGNED   NOT NULL DEFAULT 300,
    on_overlap    VARCHAR(12)    NOT NULL DEFAULT 'skip',
    last_run_at   INT UNSIGNED   NULL,
    next_run_at   INT UNSIGNED   NULL,
    last_result   VARCHAR(16)    NULL,
    last_message  VARCHAR(500)   NULL,
    locked_by     VARCHAR(128)   NULL,
    locked_until  INT UNSIGNED   NULL,
    config_json   TEXT           NULL,
    created_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    updated_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    UNIQUE KEY uq_scheduled_tasks_key (task_key),
    INDEX idx_scheduled_tasks_due (enabled, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revoap_task_runs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_key     VARCHAR(96)    NOT NULL,
    started_at   INT UNSIGNED   NOT NULL DEFAULT 0,
    finished_at  INT UNSIGNED   NULL,
    duration_ms  INT UNSIGNED   NULL,
    result       VARCHAR(16)    NOT NULL DEFAULT 'running',
    message      VARCHAR(500)   NULL,
    metrics_json TEXT           NULL,
    triggered_by VARCHAR(24)    NOT NULL DEFAULT 'cron',
    INDEX idx_task_runs_task (task_key, started_at),
    INDEX idx_task_runs_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Backups
-- @table-version revoap_backups 1.0.1
--
-- `path` is RELATIVE to var/backups and is validated against traversal on
-- every read; a stored absolute path or a `../` would let a crafted row
-- stream an arbitrary file through the download endpoint.
-- `sha256` is checked before a restore, so a truncated or edited archive
-- is refused rather than half-applied.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_backups (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(191)   NOT NULL,
    path          VARCHAR(255)   NOT NULL,
    size_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256        CHAR(64)       NOT NULL DEFAULT '',
    contents      VARCHAR(255)   NOT NULL DEFAULT '',
    encrypted     TINYINT(1)     NOT NULL DEFAULT 0,
    kdf_algo      VARCHAR(32)    NULL,
    created_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    created_by    VARCHAR(191)   NULL,
    -- `trigger_kind`, NOT `trigger`: TRIGGER is a reserved word in MySQL
    -- and MariaDB alike — an unquoted column with that name is ERROR 1064,
    -- and a backticked one is a landmine in every future query. Renamed
    -- before any code ever read the column (the Backups panel is pending);
    -- values stay 'manual' | 'schedule' | 'event'.
    trigger_kind  VARCHAR(16)    NOT NULL DEFAULT 'manual',
    note          VARCHAR(255)   NULL,
    UNIQUE KEY uq_backups_name (name),
    INDEX idx_backups_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notification channels and deliveries
-- @table-version revoap_notification_channels 1.0.0
-- @table-version revoap_notification_deliveries 1.0.0
--
-- `config_enc` holds channel credentials (SMTP password, bot token, webhook
-- secret) ENCRYPTED with the same envelope key as the TOTP secret. A
-- plaintext bot token in a database that is also in every backup archive is
-- how a console becomes a spam relay.
--
-- Deliveries are kept for troubleshooting "why did nobody get paged": the
-- attempt count, the provider's response and the signature are all there.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_notification_channels (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120)   NOT NULL,
    kind          VARCHAR(24)    NOT NULL,
    enabled       TINYINT(1)     NOT NULL DEFAULT 1,
    config_enc    VARBINARY(2048) NULL,
    -- JSON array of event keys this channel subscribes to.
    events_json   TEXT           NULL,
    priority      SMALLINT       NOT NULL DEFAULT 100,
    quiet_from    VARCHAR(5)     NULL,
    quiet_to      VARCHAR(5)     NULL,
    created_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    updated_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    INDEX idx_notification_channels_kind (kind, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revoap_notification_deliveries (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_id    INT UNSIGNED   NOT NULL,
    event         VARCHAR(64)    NOT NULL,
    status        VARCHAR(16)    NOT NULL DEFAULT 'pending',
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at INT UNSIGNED NULL,
    last_error    VARCHAR(500)   NULL,
    response_code SMALLINT       NULL,
    payload_json  MEDIUMTEXT     NULL,
    created_at    INT UNSIGNED   NOT NULL DEFAULT 0,
    delivered_at  INT UNSIGNED   NULL,
    INDEX idx_notification_deliveries_status (status, next_attempt_at),
    INDEX idx_notification_deliveries_channel (channel_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
