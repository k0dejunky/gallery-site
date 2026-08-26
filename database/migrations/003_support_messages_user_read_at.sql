-- Add user_read_at to support_messages for member unread-reply tracking.
-- Missing from earlier installs because the column was added to schema.sql
-- before the migration system existed.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'support_messages' AND column_name = 'user_read_at') = 0,
    'ALTER TABLE support_messages ADD COLUMN user_read_at DATETIME NULL DEFAULT NULL AFTER updated_at',
    'SELECT 1');
PREPARE add_user_read_at FROM @sql;
EXECUTE add_user_read_at;
DEALLOCATE PREPARE add_user_read_at;
