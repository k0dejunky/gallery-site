-- Add bounded retry tracking for the supervised video export queue.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'video_export_jobs' AND column_name = 'attempts') = 0,
    'ALTER TABLE video_export_jobs ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE add_export_attempts FROM @sql;
EXECUTE add_export_attempts;
DEALLOCATE PREPARE add_export_attempts;
