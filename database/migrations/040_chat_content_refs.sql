-- Chat AI content-search: store the clickable gallery references a model
-- reply used (JSON list of {title, url}) so the chat UI can link them even
-- after a reload/poll. Conditional guard keeps this safe on fresh installs
-- (the column comes from schema.sql) and on upgraded installs (added here).
SET @cm_content_refs_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'content_refs'
);

SET @alter_sql = IF(
    @cm_content_refs_exists = 0,
    'ALTER TABLE chat_messages ADD COLUMN content_refs TEXT NULL AFTER attachment_path',
    'SELECT 1'
);

PREPARE alter_stmt FROM @alter_sql;
EXECUTE alter_stmt;
DEALLOCATE PREPARE alter_stmt;