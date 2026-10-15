-- =====================================================================
-- 021_research_candidates.sql - every article a search found is kept
-- @table-version research_runs 1.1.0
--
-- Idempotent migration for existing deployments that must converge on the
-- schema declared in db/schema.sql without a full import. Mirrors the column
-- shipped there exactly, including the @table-version annotation so
-- MigrationManager upserts the matching version into app_config after a
-- successful apply.
--
-- Why: the research journal kept only the papers that were shown (the top of
-- the ranked window), so "nothing a search found is lost" was true only for
-- the answer's slice - the window itself was thrown away. candidates_json
-- keeps the whole ranked window exactly as the showing logic saw it
-- (score/matched/seen_first included), for every run, search or analysis.
--
--   candidates_json  the ranked window as a JSON list; NULLABLE, and NULL
--                    means "this run predates the column" - otherwise the
--                    window is always stored, even when every paper was
--                    hidden by the only-new filter.
--
-- No DML is shipped here: the row lifecycle belongs to
-- App\Research\ResearchJournal, which simply omits the column from its reads
-- and writes on a database that has not applied this file yet.
--
-- Note on the top-of-file schema annotation: like 018-020, this file carries
-- no `@schema-version` of its own - that annotation is held in sync with
-- `app.version` by convention, while a column addition is versioned by the
-- per-table annotation above.
-- =====================================================================

SET @db := DATABASE();

-- MySQL has no `ADD COLUMN IF NOT EXISTS`, so the column is guarded by an
-- information_schema existence check. That also makes the file safe to re-run
-- after a partial failure.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME   = 'research_runs'
        AND COLUMN_NAME  = 'candidates_json') = 0,
    'ALTER TABLE research_runs ADD COLUMN candidates_json MEDIUMTEXT NULL AFTER papers_json',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
