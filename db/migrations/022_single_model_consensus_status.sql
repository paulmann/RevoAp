-- =====================================================================
-- 022_single_model_consensus_status.sql - 'single' joins the consensus verdicts
-- @table-version council_sessions 1.0.5
--
-- Idempotent migration for existing deployments that must converge on the
-- shape declared in db/schema.sql without a full import. It changes the
-- column's value set only, and carries the same @table-version annotation
-- so MigrationManager upserts the matching version into app_config after a
-- successful apply.
--
-- Why: a run can now deliberately ask ONE model and use its answer as the
-- final answer, with no consensus computed at all. Until this file the only
-- honest values in `council_sessions.consensus_status` were 'pending',
-- 'agreement', 'partial' and 'conflict', so such a run had to either claim a
-- consensus it never measured (the single-response path of the consensus
-- engines reports a trivial 1.0 / agreement) or be stored as 'pending',
-- which reads as "a verdict is still owed". 'single' says what actually
-- happened: one model answered, nothing was compared.
--
-- It always travels with `consensus_score = NULL`. NULL there means "not
-- measured"; 0 would mean "measured, and the models disagreed completely".
-- The two must not be conflated, which is the same rule the cost and
-- cache_* columns already follow.
--
-- No DML is shipped here on purpose: there are no existing rows to convert.
-- A row can only carry 'single' after this value exists, so a database that
-- never applies this file simply cannot receive one - it would be stored as
-- the empty string by a non-strict MySQL, which is exactly the defect this
-- file exists to prevent. The engine refuses to write the verdict when the
-- column does not accept it (see PipelineEngine's single-model branch).
--
-- Note on the top-of-file annotation: like 018, 019 and 021, this file
-- carries no `@schema-version` of its own and does not bump it - that annotation
-- stays in sync with `app.version` by convention (and the project's doctrine
-- check enforces exactly that), while a column's value set is versioned by the
-- per-table annotation above.
-- =====================================================================

SET @db := DATABASE();

-- MySQL has no `MODIFY COLUMN IF NOT EXISTS`, so the change is guarded by an
-- information_schema probe on the column's current definition. The probe
-- matches on COLUMN_TYPE rather than on the presence of 'single' anywhere in
-- it, so a database that already carries the wider ENUM (typically after a
-- fresh db/schema.sql import) is left completely untouched. That also makes
-- the file safe to re-run after a partial failure.
--
-- The new definition repeats the column exactly as db/schema.sql declares
-- it - same type, same NULL-ability, same DEFAULT 'pending' - because
-- MODIFY COLUMN replaces the whole definition, not just its value set.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME   = 'council_sessions'
        AND COLUMN_NAME  = 'consensus_status'
        AND COLUMN_TYPE LIKE '%single%') = 0,
    'ALTER TABLE council_sessions MODIFY COLUMN consensus_status '
    'ENUM(''pending'',''agreement'',''partial'',''conflict'',''single'') DEFAULT ''pending''',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
