-- =====================================================================
-- 019_response_cost.sql - the provider's price for a call on the typed ledger
-- @table-version council_model_responses 1.2.0
--
-- Idempotent migration for existing deployments that must converge on the
-- schema declared in db/schema.sql without a full import. Mirrors the column
-- shipped there exactly, including the @table-version annotation so
-- MigrationManager upserts the matching version into app_config after a
-- successful apply.
--
-- Why: R10 estimates what a run will cost before it starts, and the only
-- honest basis for that is what comparable runs actually cost. Until now the
-- provider-reported price (`ChatResponse::$cost`, summed by StatsAggregator
-- for a single session) was never written to `council_model_responses`, so
-- money could not be aggregated per model after the fact - a run's cost
-- existed only for as long as its own request did.
--
--   cost   the amount the provider reported for that call, in whatever unit
--          the provider uses. NULLABLE, and NULL means "not reported": a
--          provider that returns no cost must stay distinguishable from one
--          that returned zero, otherwise an estimate would read missing data
--          as "free". DECIMAL(18,10) matches the precision the neighbouring
--          cache_discount column already uses.
--
-- No DML is shipped here: the row lifecycle belongs to
-- App\Storage\ResponseRepository, which probes for this column exactly the
-- way it probes for the 018 cache columns. A database that never applies this
-- file keeps working and simply answers "price not recorded" - which is the
-- truth.
--
-- Note on the top-of-file schema annotation: like 018, this file carries no
-- `@schema-version` of its own and does not bump it - that annotation is held
-- in sync with `app.version` by convention, while a column addition is
-- versioned by the per-table annotation above.
-- =====================================================================

SET @db := DATABASE();

-- MySQL has no `ADD COLUMN IF NOT EXISTS`, so the column is guarded by an
-- information_schema existence check. That also makes the file safe to re-run
-- after a partial failure.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME   = 'council_model_responses'
        AND COLUMN_NAME  = 'cost') = 0,
    'ALTER TABLE council_model_responses ADD COLUMN cost DECIMAL(18,10) NULL AFTER cache_discount',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
