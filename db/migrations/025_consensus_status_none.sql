-- =====================================================================
-- 025_consensus_status_none.sql — 'none' joins the consensus verdicts
-- @table-version council_sessions 1.0.6
--
-- Idempotent migration for existing deployments; changes the column's
-- VALUE SET only, same pattern as 022 (which added 'single').
--
-- Why: SessionSafetyNet closes a session whose job reached a terminal
-- state without the session ever being closed (crashed worker, rejected
-- run, a job kind that does not produce a verdict — the worker self-test
-- among them). Until now it wrote `consensus_status = ''`, which is NOT a
-- legal ENUM member: a strict-mode MySQL/MariaDB refuses the UPDATE with
-- error 1265 (Data truncated), the safety net catches and logs
-- `worker.session.safety_net_failed` … and the session stays `running`
-- FOREVER — the exact symptom the safety net exists to prevent. A
-- non-strict server "solved" it by silently storing '', which is how
-- ROAD_MAP item 8 ("consensus_status is an empty string") was born. Both
-- behaviours are wrong; the column was missing an honest value.
--
-- 'none' means: no verdict was computed AND NONE IS OWED — the run is
-- closed without consensus. It is distinct from:
--   'pending'  — a verdict is still owed (the run is alive or repairable);
--   'single'   — one model deliberately answered with no council to
--                compare (an actual answer exists; score stays NULL);
--   'agreement'/'partial'/'conflict' — measured verdicts.
-- Like 'single', it always travels with consensus_score = NULL ("not
-- measured" — never 0, which would claim total disagreement).
--
-- Callers switched to legal values alongside this migration:
--   SessionSafetyNet::closeIfOrphaned()          '' → 'none'
--   ModelsActualizeReport::CONSENSUS_STATUS      '' → 'single'
--     (an actualize run IS one model producing the final report — the
--      exact definition of 'single' since migration 022)
--
-- No DML: existing '' rows (non-strict servers only) are left as they
-- are; they pre-date this value and rewriting history rows is not a
-- schema migration's job.
-- =====================================================================

SET @db := DATABASE();

-- Guarded MODIFY COLUMN, same probe pattern as 022: a database that
-- already carries the wider ENUM (fresh db/schema.sql import) is left
-- untouched, and the file is safe to re-run after a partial failure.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME   = 'council_sessions'
        AND COLUMN_NAME  = 'consensus_status'
        AND COLUMN_TYPE LIKE '%none%') = 0,
    'ALTER TABLE council_sessions MODIFY COLUMN consensus_status '
    'ENUM(''pending'',''agreement'',''partial'',''conflict'',''single'',''none'') DEFAULT ''pending''',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
