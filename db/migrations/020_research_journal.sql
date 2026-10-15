-- =====================================================================
-- 020_research_journal.sql - the arXiv searcher gains a memory
-- @table-version research_runs 1.0.0
-- @table-version research_seen 1.0.0
--
-- Idempotent migration for existing deployments that must converge on the
-- schema declared in db/schema.sql without a full import. Mirrors the two
-- tables shipped there exactly, including the @table-version annotations so
-- MigrationManager upserts the matching versions into app_config after a
-- successful apply.
--
-- Why: the research card began ephemeral - search, analyse, copy, gone. That
-- made "which papers have I already seen" unanswerable, so a second search
-- showed the same papers as if they were new, and a good analysis lived only
-- until the tab was closed. The journal records every run and remembers every
-- paper shown, first sighting wins.
--
--   research_runs   one row per search/analysis: topic, query, candidate
--                   count, the papers as shown (JSON, score/matched
--                   included), provider/model/tokens, analysis,
--                   proposals_json, the generated plan_md, plan_status
--                   (draft|active|done|dropped) and plan_checked_json - the
--                   live checkboxes.
--
--   research_seen   base_id (scheme- and version-folded arXiv id) is the
--                   key; run_id points at the first run that showed it and
--                   becomes NULL when that run is deleted - the memory
--                   outlives the row it was born from, because "seen" stays
--                   true.
--
-- Writes are best-effort at the application layer: a database that never
-- applies this file keeps working, searches simply answer saved=false, and
-- no DML is shipped here - the row lifecycle belongs to
-- App\Research\ResearchJournal.
--
-- Note on the top-of-file schema annotation: like 018 and 019, this file
-- carries no `@schema-version` of its own; the top-level annotation lives in
-- db/schema.sql and moves with the release.
-- =====================================================================

CREATE TABLE IF NOT EXISTS research_runs (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NOT NULL,
    kind              VARCHAR(16)  NOT NULL DEFAULT 'analyse',
    topic             VARCHAR(400) NOT NULL,
    query             VARCHAR(500) NOT NULL DEFAULT '',
    candidates        INT UNSIGNED NOT NULL DEFAULT 0,
    papers_json       MEDIUMTEXT   NOT NULL,
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
