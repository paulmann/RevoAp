-- =====================================================================
-- 024_usage_ledger.sql — RevoAp 2.1.0 multi-user foundation (T5)
--
-- Tables introduced
--   revoap_usage_ledger   append-only, per-run token & cost billing rows
--
-- The ledger is the billing source of truth for "what did user N spend".
-- `council_model_responses` already carries every per-call number, but it
-- is keyed by step and carries no user: answering a per-user question
-- from it means joining three tables and scanning the largest one in the
-- schema on every panel view and every quota check. The ledger denormalises
-- exactly those numbers into one append-only table keyed by the two
-- questions money asks: WHO (user_id) and WHEN (run_created_at).
--
-- Design invariants (asserted by tests/UsageLedgerTest.php):
--   * APPEND-ONLY. The application issues no UPDATE and no DELETE against
--     this table and the repository exposes no method that could. A billing
--     row an administrator can edit is not billing.
--   * IDEMPOTENT. One row per (session_id, response_id) with a UNIQUE key;
--     re-syncing a session is a no-op, so the write can hang off every
--     completion path (engine, worker, safety net, manual repair) without
--     any of them needing to coordinate.
--   * EVERY SETTLED RUN GETS A MARKER ROW (response_id = 0) even when it
--     produced no model calls at all — a run that failed before the first
--     call still happened, and "runs" must be countable from the ledger
--     alone without a second scan of council_sessions.
--   * NULL MEANS "NOT REPORTED", never zero. `cost` is NULL when the
--     provider reported no price; `cached_tokens` is NULL when it reported
--     no cache statistics. Summing NULLs as 0 would under-bill silently —
--     the same rule council_model_responses already follows (migrations
--     018/019), carried through so the two tables can never disagree about
--     what a gap means.
--
-- Every statement is idempotent (CREATE TABLE IF NOT EXISTS), so this file
-- is safe to re-apply and safe to import against an already-populated
-- database. It ships no DML: rows are written only by
-- App\Usage\UsageLedger::syncSession(), which derives them from
-- council_model_responses — the source rows stay authoritative, and
-- `bin/usage-ledger-backfill.php` can rebuild history from them.
--
-- @project RevoAp   @since 2.1.0
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- Per-run usage ledger (append-only)
-- @table-version revoap_usage_ledger 1.0.0
--
-- One row per model call per settled run, plus one marker row per run
-- (response_id = 0). `user_id` is nullable because runs predating the
-- multi-user foundation — and runs whose caller passed no identity — are
-- real spend that must stay visible as UNATTRIBUTED rather than being
-- folded into someone's total or dropped.
--
-- `created_at` is the ledger-write time (INT unix, like every v2 table);
-- `run_created_at` is the session's own DATETIME and is what period
-- windows and daily series bucket on — a backfill that writes yesterday's
-- run today must bill yesterday, not today.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revoap_usage_ledger (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id         INT UNSIGNED    NOT NULL,
    user_id            INT UNSIGNED    NULL,
    -- council_model_responses.id for real rows; 0 for the per-run marker.
    -- NOT NULL on purpose: the UNIQUE key below is what makes syncSession()
    -- idempotent, and MySQL unique indexes treat NULLs as distinct — a
    -- nullable column here would silently defeat the dedup.
    response_id        INT UNSIGNED    NOT NULL DEFAULT 0,
    provider           VARCHAR(64)     NOT NULL DEFAULT '',
    model              VARCHAR(128)    NOT NULL DEFAULT '',
    prompt_tokens      INT UNSIGNED    NOT NULL DEFAULT 0,
    completion_tokens  INT UNSIGNED    NOT NULL DEFAULT 0,
    total_tokens       INT UNSIGNED    NOT NULL DEFAULT 0,
    -- NULL = the provider reported no cache data; 0 = it reported a miss.
    cached_tokens      INT UNSIGNED    NULL,
    cache_write_tokens INT UNSIGNED    NULL,
    -- NULL = not known for this call (step meta carries reasoning tokens
    -- per STEP, and a step with several responses cannot attribute them
    -- to one row honestly).
    reasoning_tokens   INT UNSIGNED    NULL,
    -- NULL = the provider reported no price. Never summed as zero.
    cost               DECIMAL(18,10)  NULL,
    -- Bothub's signed saving figure, carried through for the same reason
    -- as in council_model_responses: cache attribution without it would
    -- report a discounted run at list price.
    cache_discount     DECIMAL(18,10)  NULL,
    -- Denormalised boolean: this call read from the prompt cache
    -- (cached_tokens > 0). Exists so "cost attribution when a run used a
    -- cached response" is one indexed predicate, not a NULL-aware CASE.
    cached             TINYINT(1)      NOT NULL DEFAULT 0,
    tokens_estimated   TINYINT(1)      NOT NULL DEFAULT 0,
    -- Session status at record time ('completed' | 'failed'). A failed run
    -- still burned tokens; billing that hides it under-reports spend.
    run_status         VARCHAR(16)     NOT NULL DEFAULT '',
    created_at         INT UNSIGNED    NOT NULL DEFAULT 0,
    run_created_at     DATETIME        NULL,
    UNIQUE KEY uq_ledger_session_response (session_id, response_id),
    INDEX idx_ledger_user_created (user_id, run_created_at),
    INDEX idx_ledger_session      (session_id),
    INDEX idx_ledger_created      (created_at),
    INDEX idx_ledger_model        (provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
