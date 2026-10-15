-- RevoAp — full database schema (single source of truth)
-- Project: paulmann/AI-RevoAp
-- @schema-version 1.9.4
--   Unchanged (1.9.4) by the single-model release: as with migrations 018 and
--   019, a column's value set is versioned by its own
--   `-- @table-version council_sessions` annotation, while this top-of-file
--   value tracks the release and stays equal to config.php `app.version`. The
--   last shape change recorded here was 1.9.3 -> 1.9.4 with the 2026-09-29
--   release: migration
--   021_research_candidates.sql changes the SHAPE of a table (research_runs
--   gains candidates_json - the whole ranked window a search found). Bumped
--   1.9.2 -> 1.9.3 before that with the same-day release: migration 020 added
--   the two research journal tables. Bumped 1.8.44 -> 1.9.0 before that with
--   the 2026-09-22 release: migration 018 changed the SHAPE of a table.
--   The value stays equal to config.php `app.version` by convention.
-- Engine:  MySQL 8.x (utf8mb4)
--
-- This file is the SINGLE source of truth for the RevoAp schema. It
-- contains the consolidated FINAL state of every former migration under
-- db/migrations/ (001_initial.sql .. 013_job_audit_events.sql), including
-- the job subsystem (revoap_jobs, revoap_job_events) introduced by
-- the v1.8.35..v1.8.40 worker-architecture migrations. Importing it on a
-- fresh database produces the same 15 tables that the incremental
-- migration chain produced.
--
-- The db/migrations/ directory is intentionally a stub: it is reserved
-- for FUTURE schema changes that must be shipped to existing databases.
-- Do not reintroduce 001..013 there.
--
-- Fresh install (empty database):
--   mysql -u root opencorum < db/schema.sql
--
-- Upgrade (existing database, previous RevoAp version):
--   A new schema change ships BOTH as an edit to this file (with a
--   bumped @table-version) AND — when existing databases must converge
--   without a full import — as a new idempotent file under
--   db/migrations/NNN_*.sql carrying the same @table-version annotation.
--   Apply the pending file through the admin Settings tab
--   "Apply Migrations". See db/migrations/README.md.
--
-- All CREATE statements are idempotent (CREATE TABLE IF NOT EXISTS); the
-- file is safe to re-import against an already-populated database.
--
-- ---------------------------------------------------------------------
-- Schema versioning convention (v1.8.40+):
--   * Every CREATE TABLE block is preceded by a `-- @table-version <name> <semver>`
--     comment recording the version that produced the current shape.
--   * db/schema.sql is the source of truth; db/migrations/ is a stub.
--   * To change the schema: edit the table here AND bump its
--     `@table-version`. When the change must also reach existing
--     databases incrementally, ship a new file db/migrations/NNN_*.sql
--     that re-declares the same `@table-version`, so live databases
--     converge to the same number when "Apply Migrations" is clicked.
--   * The Settings UI compares the live versions (app_config) against
--     these annotations to surface drift (Check DB).
-- ---------------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- Skills
-- @table-version skills 1.0.0
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS skills (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug         VARCHAR(64) NOT NULL UNIQUE,
    name         VARCHAR(128) NOT NULL,
    description  TEXT NULL,
    definition   JSON NOT NULL,
    is_system    TINYINT(1) DEFAULT 0,
    use_count    INT UNSIGNED DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_skills_is_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Council sessions
-- @table-version council_sessions 1.0.6
-- Includes the History-tab metadata columns (skill_slug, options_json)
-- plus the chronological / FULLTEXT indexes used by /api/history and
-- /api/statistics.
--
-- consensus_status 'single' marks a run that deliberately computed NO
-- consensus: one model was asked and its answer is the final answer, so
-- there was no second opinion to compare it against. It is distinct from
-- 'pending' (a verdict is still owed) and from every scored status, and it
-- always travels with consensus_score = NULL - "not measured" is not a
-- score of zero or one. Migration
-- 022_single_model_consensus_status.sql converges existing databases.
--
-- 'none' (migration 025) marks a session CLOSED WITHOUT a verdict that is
-- owed: the job died, was rejected, or never produces one (worker
-- self-test), and SessionSafetyNet closed the orphan. Before it existed
-- the safety net wrote '', which a strict-mode server refuses (error
-- 1265) — leaving the session 'running' forever, the exact symptom the
-- safety net exists to prevent. 'none' also travels with score NULL.
CREATE TABLE IF NOT EXISTS council_sessions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NULL,
    skill_id         INT UNSIGNED NOT NULL,
    skill_slug       VARCHAR(64) NULL,
    query_text       TEXT NOT NULL,
    final_answer     TEXT NULL,
    consensus_score  DECIMAL(5,4) NULL,
    consensus_status ENUM('pending','agreement','partial','conflict','single','none') DEFAULT 'pending',
    status           ENUM('running','completed','failed') DEFAULT 'running',
    total_tokens     INT UNSIGNED DEFAULT 0,
    duration_ms      INT UNSIGNED DEFAULT 0,
    options_json     JSON NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at     DATETIME NULL,
    INDEX idx_sessions_status      (status),
    INDEX idx_sessions_skill       (skill_id),
    INDEX idx_sessions_skill_slug  (skill_slug),
    INDEX idx_sessions_created_at  (created_at),
    FULLTEXT KEY ft_sessions_search (query_text, final_answer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pipeline steps (append-only)
-- @table-version council_steps 1.8.28
-- summary widened to MEDIUMTEXT so the full L1/L4 response is preserved
-- verbatim. Statistics indexes are inlined so a fresh schema import
-- already has them. step_key widened to VARCHAR(191) so composite
-- role@model keys such as
-- `L2.round2.normalize.solution-auditor@deepseek_deepseek_v4_pro`
-- fit without truncation; the App\Helpers\StepKeyHelper helper
-- guarantees keys longer than 191 chars collapse to a deterministic
-- `<prefix>-<sha256:12>` value before persistence.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS council_steps (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED NOT NULL,
    step_key    VARCHAR(191) NOT NULL,
    label       VARCHAR(128) NOT NULL,
    order_index SMALLINT UNSIGNED NOT NULL,
    status      ENUM('running','completed','failed','skipped') DEFAULT 'running',
    summary     MEDIUMTEXT NULL,
    meta        JSON NULL,
    started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at    DATETIME NULL,
    INDEX idx_steps_session          (session_id, order_index),
    INDEX idx_steps_session_step_key (session_id, step_key),
    INDEX idx_steps_started_at       (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Model responses per step
-- @table-version council_model_responses 1.2.0
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS council_model_responses (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    step_id             INT UNSIGNED NOT NULL,
    model               VARCHAR(128) NOT NULL,
    provider            VARCHAR(64)  NOT NULL,
    role_name           VARCHAR(64)  NULL,
    raw_content         MEDIUMTEXT NOT NULL,
    normalized_response JSON NULL,
    prompt_tokens       INT UNSIGNED DEFAULT 0,
    completion_tokens   INT UNSIGNED DEFAULT 0,
    total_tokens        INT UNSIGNED DEFAULT 0,
    latency_ms          INT UNSIGNED DEFAULT 0,
    tokens_estimated    TINYINT(1) DEFAULT 0,
    -- Prompt-cache statistics. All three are NULLABLE, and NULL means "the
    -- provider returned no cache data for this call" — a reported zero is
    -- stored as 0. That distinction is what lets History and Statistics
    -- render "provider returned no cache data" instead of the misleading
    -- "the cache did not fire". cache_read_tokens is the read side in
    -- either dialect (OpenAI-like prompt_tokens_details.cached_tokens or
    -- Anthropic-like cache_read_input_tokens); cache_discount is Bothub's
    -- own saving figure and is signed (negative on a cache write for a
    -- vendor that charges more to write than to read).
    cache_read_tokens   INT UNSIGNED   NULL,
    cache_write_tokens  INT UNSIGNED   NULL,
    cache_discount      DECIMAL(18,10) NULL,
    -- R10: the price the provider reported for this call, in its own unit.
    -- NULL means "not reported" and must never be summed as a zero.
    cost                DECIMAL(18,10) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_responses_step  (step_id),
    INDEX idx_responses_model (model, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pipeline events (append-only event log)
-- @table-version pipeline_events 1.0.0
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pipeline_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED NOT NULL,
    step_id     INT UNSIGNED NULL,
    event_type  VARCHAR(64) NOT NULL,
    payload     JSON NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_events_session (session_id, id),
    INDEX idx_events_type    (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Vector embeddings (for skill routing memory)
-- @table-version vector_embeddings 1.0.0
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vector_embeddings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collection  VARCHAR(64) NOT NULL,
    source_id   INT UNSIGNED NOT NULL,
    vector      JSON NOT NULL,
    metadata    JSON NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_collection_source (collection, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Durable vector-memory items (Task 5)
-- @table-version memory_items 1.0.0
--
-- Full memory text kept verbatim; the embedding vectors live in
-- `vector_embeddings` (collection='memory'), the raw rows live here so
-- recall still works when the vector store is unavailable or its driver
-- is switched.
--
-- Deliberately carries NO hard FK to `council_sessions`:
-- `council_sessions` lives in a separate table family, and memory must
-- stay usable even after the session row has been pruned (the degraded
-- "no memory" mode stays valid either way).
--
-- Shipped incrementally as db/migrations/014_memory_items.sql for
-- existing deployments; mirrored here so a fresh import (and
-- Settings -> Database -> ReCreate DB) produces the table without
-- relying on that migration being executed.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS memory_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED NOT NULL,
    type        VARCHAR(24)  NOT NULL DEFAULT 'text',
    content     MEDIUMTEXT   NOT NULL,
    metadata    JSON         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_memory_session_created (session_id, created_at),
    INDEX idx_memory_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Persistent model-response cache
-- @table-version model_response_cache 1.0.3
-- Keyed by SHA-256 of the canonical request envelope so identical
-- prompts at identical reasoning settings reuse a single answer.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS model_response_cache (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_key    CHAR(64)     NOT NULL UNIQUE,
    provider     VARCHAR(64)  NOT NULL,
    model        VARCHAR(192) NOT NULL,
    payload      MEDIUMTEXT   NOT NULL,
    version      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_hit_at  DATETIME     NULL,
    INDEX idx_mrc_provider_model (provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Shared session tokens. High-entropy, opaque tokens granting read-only
-- public access to one completed council session (query + final answer
-- + full audit/consensus history). Tokens are random — they cannot be
-- derived from or enumerated by session id.
-- @table-version shared_sessions 1.0.6
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shared_sessions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id   INT UNSIGNED NOT NULL,
    token        CHAR(64)     NOT NULL UNIQUE,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at   DATETIME     NULL,
    INDEX idx_shared_session  (session_id),
    INDEX idx_shared_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Schema migration tracker
-- @table-version schema_migrations 1.0.0
-- Mirrors the table that App\Settings\MigrationManager creates lazily.
-- Defining it here lets a fresh `mysql < db/schema.sql` produce a
-- schema that is byte-identical to one built up incrementally through
-- the (now consolidated) migration chain.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration  VARCHAR(255) NOT NULL UNIQUE,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Application configuration (v1.8.27)
-- @table-version app_config 1.8.27
--
-- Key/value store for system-managed runtime metadata. Today the main
-- consumers are the schema-versioning subsystem (keys of the form
-- `table_version.<table>`) and the UI preference `ui.theme`, but the
-- table is intentionally generic so future subsystems can extend it
-- without DDL changes. (The layout-width preference `ui.width` that
-- shipped alongside `ui.theme` in 1.8.33 was retired — the content
-- column follows the window now — and a leftover row is deleted on the
-- next interface save.)
--   * `config_key`   — globally unique key. Use dotted namespaces
--                      (`table_version.skills`, `app.feature.foo`).
--   * `config_value` — string value (TEXT). Numbers / booleans / JSON
--                      are serialised by the caller.
--   * `scope`        — high-level grouping (`schema`, `app`, …) so the
--                      Settings UI can filter related entries.
--   * `updated_at`   — last-write timestamp.
-- The name is `app_config` (lowercase, snake_case) — every other table
-- in this schema uses the same casing, so `app_config` is the safest
-- choice across case-sensitive filesystems. The user-facing "Config"
-- name is preserved in the Settings UI / docs; the SQL identifier is
-- always backticked so a future rename is a single-edit change.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_config (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    config_key   VARCHAR(191) NOT NULL UNIQUE,
    config_value TEXT         NULL,
    scope        VARCHAR(64)  NOT NULL DEFAULT 'app',
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_app_config_scope (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Users (v1.8.32)
-- @table-version users 1.0.0
--
-- Identity surface. Single-admin deployments use the deterministic
-- row with `external_id = 'local'`. Future authentication layers
-- attach additional rows without disturbing this seed.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NOT NULL UNIQUE,
    email       VARCHAR(191) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- User profiles (v1.8.32)
-- @table-version user_profiles 1.0.0
--
-- 1:1 detail row keyed by `user_id`. The pipeline reads
-- `system_prompt_addendum` and appends it to every outbound system
-- prompt after the base prompt file from prompts/system/default.md.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_profiles (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                  INT UNSIGNED NOT NULL UNIQUE,
    email                    VARCHAR(191) NULL,
    phone                    VARCHAR(64)  NULL,
    first_name               VARCHAR(128) NULL,
    last_name                VARCHAR(128) NULL,
    job_title                VARCHAR(191) NULL,
    bio                      TEXT NULL,
    system_prompt_addendum   TEXT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_profiles_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Domain model presets (v1.8.32)
-- @table-version domain_presets 1.0.0
--
-- Per-domain (`default`, `mathematics`, `physics`, future) selection
-- of provider/model rows plus an arbiter for synthesis. Slug uniquely
-- identifies the preset; `definition_json` carries the model list and
-- arbiter so future extensions stay schema-free.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS domain_presets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(64) NOT NULL UNIQUE,
    name            VARCHAR(128) NOT NULL,
    description     TEXT NULL,
    definition_json JSON NOT NULL,
    is_system       TINYINT(1) DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain_presets_is_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Durable job queue (Worker Architecture v2)
-- @table-version revoap_jobs 1.1.0
--
-- Decouples the long LLM consensus pipeline from the HTTP/SSE request
-- lifecycle: POST /api/council/jobs enqueues one row per consensus run
-- and a worker polls the table with row locks. This is the final shape
-- of the v1.8.35 job layer plus the v1.8.39 auto-recovery columns
-- (attempts, max_attempts, next_run_at, last_recovered_at,
-- recovery_reason, last_error_at) and the retry-policy indexes.
--
-- Lifecycle column `status` accepts: queued, running, pause_requested,
-- paused, resume_requested, cancel_requested, cancelled,
-- consensus_requested, completed, failed, stale.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_jobs (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id         INT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED NULL,
    status             VARCHAR(32)  NOT NULL DEFAULT 'queued',
    priority           INT          NOT NULL DEFAULT 0,
    attempts           INT          NOT NULL DEFAULT 0,
    max_attempts       INT          NOT NULL DEFAULT 3,
    payload_json       JSON         NOT NULL,
    result_json        JSON         NULL,
    error_json         JSON         NULL,
    locked_by          VARCHAR(128) NULL,
    locked_at          DATETIME     NULL,
    heartbeat_at       DATETIME     NULL,
    next_run_at        DATETIME     NULL,
    last_recovered_at  DATETIME     NULL,
    recovery_reason    VARCHAR(190) NULL,
    last_error_at      DATETIME     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at         DATETIME     NULL,
    finished_at        DATETIME     NULL,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_jobs_status_priority (status, priority, created_at),
    INDEX idx_jobs_session         (session_id),
    INDEX idx_jobs_user            (user_id),
    INDEX idx_jobs_lock            (locked_by, locked_at),
    INDEX idx_jobs_heartbeat       (heartbeat_at),
    INDEX idx_jobs_next_run        (status, next_run_at, priority, id),
    INDEX idx_jobs_attempts        (attempts, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Job audit events (durable retry/audit timeline)
-- @table-version revoap_job_events 1.0.0
--
-- Append-only audit log for every notable lifecycle transition of an
-- revoap_jobs row (job created, worker acquired, stale detected,
-- requeued, retries exhausted, completed, failed, …), so the History
-- UI can render a complete "Retry & recovery" timeline for any job /
-- session even when the job crashed mid-run and was auto-recovered.
-- Indexes cover the timeline queries:
--   WHERE job_id = ? ORDER BY id ASC
--   WHERE session_id = ? ORDER BY id ASC
--   WHERE event_type = ? AND created_at >= ?
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_job_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id          BIGINT UNSIGNED NOT NULL,
    session_id      INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NULL,
    event_type      VARCHAR(64)     NOT NULL,
    status_from     VARCHAR(32)     NULL,
    status_to       VARCHAR(32)     NULL,
    attempt         INT             NULL,
    max_attempts    INT             NULL,
    backoff_seconds INT             NULL,
    next_run_at     DATETIME        NULL,
    worker_id       VARCHAR(128)    NULL,
    reason          VARCHAR(190)    NULL,
    message         VARCHAR(500)    NULL,
    context_json    JSON            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_events_job          (job_id, id),
    INDEX idx_job_events_session      (session_id, id),
    INDEX idx_job_events_event_type   (event_type, created_at),
    INDEX idx_job_events_created      (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Worker presence (v1.8.44)
-- @table-version revoap_worker_presence 1.0.0
--
-- Liveness beacon for the durable worker. Before this table the ONLY
-- trace a worker left in the database came from `revoap_jobs`
-- (`locked_by` / `heartbeat_at`), so an idle-but-alive worker was
-- indistinguishable from a dead one — the Settings UI rendered
-- "Waiting for worker" while the process was happily polling. Each
-- worker upserts exactly one row per second while it runs:
--
--   * the row EXISTS and `last_seen_at` is fresh  → worker is alive;
--   * the row EXISTS but `last_seen_at` is stale  → worker crashed
--     (SIGKILL / OOM / host reboot) mid-flight;
--   * the row is ABSENT                           → the worker exited
--     cleanly (it deletes its own row on SIGTERM), or never started.
--
-- `started_at` is written once on the first beat and never updated, so
-- `now - started_at` is the process uptime. `current_job_id` is the
-- job the worker is running right now (NULL while polling) and
-- `jobs_done` is the process-local completion counter. Both are
-- diagnostics only — the authoritative job state stays in
-- `revoap_jobs`. No secrets are stored: worker ids are already public
-- through `revoap_jobs.locked_by`.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- External REST API keys (v1.8.42)
-- @table-version api_keys 1.0.0
--
-- Own credential surface for the public /v1/* consensus API. Keys are
-- generated by ApiKeyService as `rv_` + 48 lowercase hex chars and are
-- shown to the operator exactly once; only the SHA-256 hex digest is
-- persisted (`key_hash`), so a leaked database dump cannot reconstruct
-- usable keys. `prefix` stores the readable `rv_XXXXXXXX` identifier
-- for lists/debugging. `scopes` is a comma-separated capability list
-- (`full`, `read`). Rate-limit counters (RPM / daily) live in runtime
-- files (see App\Api\RateLimitStore); the columns carry each key's
-- own ceilings. `revoked_at` marks a key as unusable; `expires_at` adds
-- an optional TTL. API keys NEVER authenticate against admin paths -
-- the /v1 router maintains an explicit deny-list (see App\Api\ApiAuth).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label        VARCHAR(128) NOT NULL,
    key_hash     CHAR(64)     NOT NULL UNIQUE,
    prefix       VARCHAR(16)  NOT NULL,
    scopes       VARCHAR(191) NOT NULL DEFAULT 'full',
    rpm_limit    INT UNSIGNED NOT NULL DEFAULT 30,
    daily_limit  INT UNSIGNED NOT NULL DEFAULT 1000,
    expires_at   DATETIME     NULL,
    last_used_at DATETIME     NULL,
    revoked_at   DATETIME     NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_keys_prefix    (prefix),
    INDEX idx_api_keys_revoked   (revoked_at),
    INDEX idx_api_keys_last_used (last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Outbound webhooks (v1.8.43)
-- @table-version webhooks 1.0.0
-- @table-version webhook_deliveries 1.0.0
--
-- Subscriptions for run lifecycle events (run.completed / run.failed /
-- run.cancelled). Delivery is performed OUT-OF-BAND by the worker after
-- the job reaches a terminal status (see App\Api\WebhookDispatcher).
-- Every payload is signed with HMAC-SHA256 over the raw body using the
-- per-subscription `secret` (which must stay recoverable to compute
-- signatures, hence plain storage). Retries are idempotent: the same
-- `event_id` is re-sent and every attempt is recorded in
-- webhook_deliveries for audit.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- Research journal: the arXiv searcher's runs and its seen-paper memory
-- @table-version research_runs 1.1.0
-- @table-version research_seen 1.0.0
-- Written by App\Research\ResearchJournal; db/migrations/020 ships the
-- same two tables to existing databases.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS research_runs (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NOT NULL,
    kind              VARCHAR(16)  NOT NULL DEFAULT 'analyse',
    topic             VARCHAR(400) NOT NULL,
    query             VARCHAR(500) NOT NULL DEFAULT '',
    candidates        INT UNSIGNED NOT NULL DEFAULT 0,
    papers_json       MEDIUMTEXT   NOT NULL,
    candidates_json   MEDIUMTEXT   NULL,
    provider          VARCHAR(64)  NOT NULL DEFAULT '',
    model             VARCHAR(191) NOT NULL DEFAULT '',
    tokens            INT UNSIGNED NOT NULL DEFAULT 0,
    analysis          MEDIUMTEXT   NULL,
    proposals_json    TEXT         NULL,
    plan_md           MEDIUMTEXT   NULL,
    plan_status       VARCHAR(16)  NOT NULL DEFAULT 'draft',
    plan_checked_json TEXT         NULL,
    PRIMARY KEY (id),
    INDEX idx_research_runs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_seen (
    base_id       VARCHAR(255) NOT NULL,
    run_id        INT UNSIGNED NULL,
    first_seen_at DATETIME     NOT NULL,
    PRIMARY KEY (base_id),
    INDEX idx_research_seen_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- RevoAp 2.0.0 subsystems (mirrors db/migrations/023_v2_admin_and_fallback.sql)
--
-- Availability monitor, operator substitution rules, the hash-chained audit
-- log, RBAC admin accounts and their revocable sessions, feature flags,
-- budgets, the task scheduler, backups and notification channels.
-- =====================================================================

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
-- Per-run usage ledger (append-only, v2.1.0 multi-user foundation)
-- @table-version revoap_usage_ledger 1.0.0
--
-- The billing source of truth for "what did user N spend". One row per
-- model call per settled run, plus one marker row per run
-- (response_id = 0) so runs are countable from the ledger alone. The
-- per-call numbers are denormalised from `council_model_responses`,
-- which stays authoritative: the ledger is rebuilt from it by
-- App\Usage\UsageLedger::syncSession() / bin/usage-ledger-backfill.php.
--
-- Invariants, each asserted by tests/UsageLedgerTest.php:
--   * append-only — the application issues no UPDATE or DELETE and the
--     repository exposes no method that could;
--   * idempotent — UNIQUE (session_id, response_id) makes a re-sync a
--     no-op, so every completion path can call it unconditionally;
--   * NULL means "the provider did not report it" — `cost` NULL is never
--     summed as zero, `cached_tokens` NULL is not a cache miss. The same
--     rule council_model_responses follows since migrations 018/019;
--   * `run_created_at` (not the write-time `created_at`) is what period
--     windows bucket on, so a backfill bills the run's own day.
--
-- `user_id` is nullable: runs whose caller presented no identity are
-- real spend and stay visible as UNATTRIBUTED rather than being folded
-- into someone's total or dropped.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_usage_ledger (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id         INT UNSIGNED    NOT NULL,
    user_id            INT UNSIGNED    NULL,
    response_id        INT UNSIGNED    NOT NULL DEFAULT 0,
    provider           VARCHAR(64)     NOT NULL DEFAULT '',
    model              VARCHAR(128)    NOT NULL DEFAULT '',
    prompt_tokens      INT UNSIGNED    NOT NULL DEFAULT 0,
    completion_tokens  INT UNSIGNED    NOT NULL DEFAULT 0,
    total_tokens       INT UNSIGNED    NOT NULL DEFAULT 0,
    cached_tokens      INT UNSIGNED    NULL,
    cache_write_tokens INT UNSIGNED    NULL,
    reasoning_tokens   INT UNSIGNED    NULL,
    cost               DECIMAL(18,10)  NULL,
    cache_discount     DECIMAL(18,10)  NULL,
    cached             TINYINT(1)      NOT NULL DEFAULT 0,
    tokens_estimated   TINYINT(1)      NOT NULL DEFAULT 0,
    run_status         VARCHAR(16)     NOT NULL DEFAULT '',
    created_at         INT UNSIGNED    NOT NULL DEFAULT 0,
    run_created_at     DATETIME        NULL,
    UNIQUE KEY uq_ledger_session_response (session_id, response_id),
    INDEX idx_ledger_user_created (user_id, run_created_at),
    INDEX idx_ledger_session      (session_id),
    INDEX idx_ledger_created      (created_at),
    INDEX idx_ledger_model        (provider, model)
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
