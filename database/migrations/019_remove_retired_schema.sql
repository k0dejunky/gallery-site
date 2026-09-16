-- Remove dead schema from retired features:
--
--  1. Reddit pull-bridge claim columns (picked_at/picked_by + index) — the
--     Devvit polling bridge was retired because the HTTP-fetch domain
--     allow-list for amethyst2213.com was rejected by Reddit; the app now
--     posts through the OAuth2 RedditClient instead.
--
--  2. galleries.published_at — scheduled site publication was reverted, so
--     the column is unused dead schema.
--
-- The conditional guards keep this safe on fresh installs (where the columns
-- were never added) and on upgraded installs (where they exist).
SET @apq_picked_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auto_poster_queue' AND COLUMN_NAME = 'picked_at'
);

SET @drop_sql = IF(
    @apq_picked_exists > 0,
    'ALTER TABLE auto_poster_queue
        DROP INDEX idx_apq_reddit_claim,
        DROP COLUMN picked_by,
        DROP COLUMN picked_at',
    'SELECT 1'
);

PREPARE drop_stmt FROM @drop_sql;
EXECUTE drop_stmt;
DEALLOCATE PREPARE drop_stmt;

SET @gal_published_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'galleries' AND COLUMN_NAME = 'published_at'
);

SET @drop_sql = IF(
    @gal_published_exists > 0,
    'ALTER TABLE galleries
        DROP INDEX idx_galleries_published,
        DROP COLUMN published_at',
    'SELECT 1'
);

PREPARE drop_stmt FROM @drop_sql;
EXECUTE drop_stmt;
DEALLOCATE PREPARE drop_stmt;