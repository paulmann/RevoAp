-- =====================================================================
-- 014_memory_items.sql - durable vector-memory items (Task 5)
-- @table-version memory_items 1.0.0
--
-- Idempotent migration applying cleanly on every MySQL 5.7+ / MariaDB
-- server. The table stores full memory text; the embedding vectors
-- live in `vector_embeddings` (collection='memory').
--
-- Deliberately drops the session hard-FK: `council_sessions` lives in
-- a separate table family, and memory must stay usable even when the
-- session row has been pruned (degraded "no memory" mode stays valid).
-- =====================================================================
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
