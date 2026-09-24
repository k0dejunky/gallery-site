-- Auto-post worker: atomic per-row claim so two runs (processes or hosts)
-- never publish the same queue row, and so a crashed worker's in-flight row
-- is reclaimed after 15 minutes instead of reposted on the next tick.
-- Conditional guards keep this safe on fresh installs (columns come from
-- schema.sql) and on upgraded installs (columns added here).
SET @apq_claimed_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auto_poster_queue' AND COLUMN_NAME = 'claimed_at'
);

SET @alter_sql = IF(
    @apq_claimed_exists = 0,
    'ALTER TABLE auto_poster_queue
        ADD COLUMN claimed_at DATETIME NULL AFTER posted_at,
        ADD COLUMN claimed_by VARCHAR(64) NULL AFTER claimed_at,
        ADD INDEX idx_apq_claim (status, claimed_at)',
    'SELECT 1'
);

PREPARE alter_stmt FROM @alter_sql;
EXECUTE alter_stmt;
DEALLOCATE PREPARE alter_stmt;