-- =====================================================================
-- 017_worker_presence.sql - worker liveness beacon (presence table)
-- @table-version revoap_worker_presence 1.0.0
--
-- Idempotent migration for existing deployments that must converge on
-- the schema declared in db/schema.sql without a full import. Mirrors
-- the CREATE TABLE block shipped there exactly, including the
-- @table-version annotation so MigrationManager upserts the matching
-- version into app_config after a successful apply.
--
-- Why: before this table the only database trace a durable worker left
-- came from `revoap_jobs` (`locked_by` / `heartbeat_at`). A worker that
-- was alive but had nothing to do wrote nothing at all, so the Settings
-- UI could not tell "running and idle" apart from "not started" and
-- rendered "Waiting for worker" while a healthy process was polling.
-- The worker now upserts one row per second:
--
--   row present + last_seen_at fresh -> alive
--   row present + last_seen_at stale -> crashed (SIGKILL / OOM / reboot)
--   row absent                       -> clean exit, or never started
--
-- No secrets: worker ids are already public via revoap_jobs.locked_by.
-- No DML is shipped here - the worker owns the row lifecycle.
-- =====================================================================
CREATE TABLE IF NOT EXISTS revoap_worker_presence (
    worker_id         VARCHAR(128)    NOT NULL,
    host              VARCHAR(190)    NULL,
    pid               INT UNSIGNED    NULL,
    app_version       VARCHAR(32)     NULL,
    mode              VARCHAR(16)     NULL,
    started_at        DATETIME        NOT NULL,
    last_seen_at      DATETIME        NOT NULL,
    current_job_id    BIGINT UNSIGNED NULL,
    jobs_done         INT UNSIGNED    NOT NULL DEFAULT 0,
    last_error_at     DATETIME        NULL,
    last_error_reason VARCHAR(190)    NULL,
    PRIMARY KEY (worker_id),
    INDEX idx_worker_presence_last_seen   (last_seen_at),
    INDEX idx_worker_presence_current_job (current_job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
