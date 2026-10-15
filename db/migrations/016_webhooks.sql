-- =====================================================================
-- 016_webhooks.sql - outbound webhook subscriptions for run lifecycle
--                    events (run.completed / run.failed / run.cancelled)
-- @table-version webhooks 1.0.0
-- @table-version webhook_deliveries 1.0.0
--
-- Idempotent migration for existing deployments that must converge on
-- the schema declared in db/schema.sql without a full import. Mirrors
-- the CREATE TABLE blocks shipped there exactly, including the
-- @table-version annotations so MigrationManager upserts the matching
-- versions into app_config after a successful apply.
--
-- The `secret` column stores the HMAC signing secret used to sign
-- OUTBOUND payloads (it must be recoverable to compute signatures, so
-- it cannot be a one-way digest). Public listings mask it to the last
-- 4 characters (see App\Api\Webhook::toPublicArray()).
--
-- `webhook_deliveries.payload` keeps the exact JSON body that was (or
-- will be) sent, so a retry re-sends the SAME payload while re-signing
-- it with a fresh timestamp.
-- =====================================================================
CREATE TABLE IF NOT EXISTS webhooks (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(128)  NOT NULL,
    url        VARCHAR(2048) NOT NULL,
    secret     VARCHAR(191)  NOT NULL,
    events     VARCHAR(191)  NOT NULL DEFAULT 'run.completed',
    active     TINYINT(1)    NOT NULL DEFAULT 1,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_webhooks_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id    INT UNSIGNED NOT NULL,
    event_id      CHAR(36)     NOT NULL,
    event_type    VARCHAR(64)  NOT NULL,
    attempt       INT UNSIGNED NOT NULL DEFAULT 1,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    payload       MEDIUMTEXT   NULL,
    response_code SMALLINT UNSIGNED NULL,
    response_body VARCHAR(512) NULL,
    next_retry_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_whd_event (webhook_id, event_id),
    INDEX idx_whd_status_retry  (status, next_retry_at),
    CONSTRAINT fk_whd_webhook FOREIGN KEY (webhook_id)
        REFERENCES webhooks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
