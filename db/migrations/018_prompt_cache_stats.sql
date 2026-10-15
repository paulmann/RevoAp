-- =====================================================================
-- 018_prompt_cache_stats.sql - prompt-cache accounting on model responses
-- @table-version council_model_responses 1.1.0
--
-- Idempotent migration for existing deployments that must converge on the
-- schema declared in db/schema.sql without a full import. Mirrors the
-- column set shipped there exactly, including the @table-version
-- annotation so MigrationManager upserts the matching version into
-- app_config after a successful apply.
--
-- Why: prompt caching (Bothub) makes a provider report three numbers the
-- accounting table had nowhere to put. `council_steps.meta` (JSON) already
-- carries them for the primary read path, but `council_model_responses` is
-- the typed per-call ledger that History's per-response rows and
-- StatsController read, and it silently dropped every cache figure - which
-- reads as "the cache was never used" rather than "it was never recorded".
--
--   cache_read_tokens   tokens served from the cache. ONE number whichever
--                       dialect reported them: OpenAI-like
--                       `usage.prompt_tokens_details.cached_tokens` or
--                       Anthropic-like `usage.cache_read_input_tokens`.
--                       Storing both under one column is what keeps an
--                       aggregate from double-counting.
--   cache_write_tokens  tokens written to the cache (Anthropic-like
--                       `usage.cache_creation_input_tokens`; the automatic
--                       families do not report a write at all).
--   cache_discount      Bothub's `cache_discount`, i.e. how much the cache
--                       saved. SIGNED: at a 1.25x write multiplier the
--                       discount on a write is negative, so the column is
--                       DECIMAL and not UNSIGNED.
--
-- All three are NULLABLE and NULL means "not reported". A provider that
-- returns no cache block must stay distinguishable from one that returned
-- a zero, otherwise the UI would report a cache miss where there is simply
-- no data. No DML is shipped here: the row lifecycle belongs to
-- App\Storage\ResponseRepository.
--
-- The existing token columns are untouched, so a database that never
-- applies this file keeps working exactly as before (minus the cache
-- columns, which then read as "no data" - which is the truth).
--
-- Note on the top-of-file schema annotation: it is deliberately NOT bumped
-- here, and this file carries no schema annotation of its own (none of
-- 014..017 do either). That annotation is held in sync with config.php
-- `app.version` by convention, and this change is a column addition that
-- the per-table annotation already versions. Bumping the schema annotation
-- here alone would report a schema version ahead of the running build for
-- no operational gain; it was instead bumped together with `app.version`
-- to 1.9.0 in `db/schema.sql` when this file shipped.
-- =====================================================================

SET @db := DATABASE();

-- MySQL has no `ADD COLUMN IF NOT EXISTS`, so each column is guarded by an
-- information_schema existence check. The three statements are independent,
-- which also makes the file safe to re-run after a partial failure.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME   = 'council_model_responses'
        AND COLUMN_NAME  = 'cache_read_tokens') = 0,
    'ALTER TABLE council_model_responses ADD COLUMN cache_read_tokens INT UNSIGNED NULL AFTER tokens_estimated',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME   = 'council_model_responses'
        AND COLUMN_NAME  = 'cache_write_tokens') = 0,
    'ALTER TABLE council_model_responses ADD COLUMN cache_write_tokens INT UNSIGNED NULL AFTER cache_read_tokens',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME   = 'council_model_responses'
        AND COLUMN_NAME  = 'cache_discount') = 0,
    'ALTER TABLE council_model_responses ADD COLUMN cache_discount DECIMAL(18,10) NULL AFTER cache_write_tokens',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
