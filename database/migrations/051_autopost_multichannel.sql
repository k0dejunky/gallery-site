-- Multi-channel auto poster: the queue now carries any enabled channel.
-- - text:  varchar(280) -> MEDIUMTEXT so long-form channels (Telegram 4096,
--   Lemmy/Blogger 40000+) can store the full composed post.
-- - platform: varchar(20) -> varchar(32) headroom for all channel dbKeys.
-- - meta: per-post JSON carrying the per-post target (chat_id, community,
--   instance, relay, ...) chosen in the admin compose form.
-- Conditional guards keep this safe on fresh installs (columns come from
-- schema.sql) and on upgraded installs (columns widened here).
SET @apq_text_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auto_poster_queue' AND COLUMN_NAME = 'text'
);

SET @alter_sql = IF(
    @apq_text_exists = 0,
    'SELECT 1',
    'ALTER TABLE auto_poster_queue
        MODIFY text MEDIUMTEXT NOT NULL,
        MODIFY platform VARCHAR(32) NOT NULL DEFAULT ''x'',
        ADD COLUMN meta TEXT NULL AFTER media_ids'
);

PREPARE alter_stmt FROM @alter_sql;
EXECUTE alter_stmt;
DEALLOCATE PREPARE alter_stmt;