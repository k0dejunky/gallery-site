-- Operational indexes. MySQL/MariaDB do not support
-- ALTER TABLE ... ADD INDEX IF NOT EXISTS, so each ADD is metadata-guarded.

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'subscriptions') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'subscriptions'
           AND index_name = 'idx_subscriptions_status_expires') = 0,
    'ALTER TABLE `subscriptions` ADD INDEX `idx_subscriptions_status_expires` (`status`, `expires_at`)',
    'SELECT 1');
PREPARE operational_index FROM @sql;
EXECUTE operational_index;
DEALLOCATE PREPARE operational_index;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'video_export_jobs') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'video_export_jobs'
           AND index_name = 'idx_video_export_project_status') = 0,
    'ALTER TABLE `video_export_jobs` ADD INDEX `idx_video_export_project_status` (`project_id`, `status`)',
    'SELECT 1');
PREPARE operational_index FROM @sql;
EXECUTE operational_index;
DEALLOCATE PREPARE operational_index;
