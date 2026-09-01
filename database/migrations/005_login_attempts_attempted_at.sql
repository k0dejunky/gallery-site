-- Speed up the time-windowed security monitoring queries in Stats::security()
-- and Stats::feed() (e.g. "failed logins in the last hour", TOP offending IPs).
-- Those filter by attempted_at alone, which the existing (email, ip, attempted_at)
-- index cannot help because email/ip are not equality filters there.
-- MySQL/MariaDB do not support ADD INDEX IF NOT EXISTS, so this is guarded.

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'login_attempts') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'login_attempts'
           AND index_name = 'idx_login_attempts_at') = 0,
    'ALTER TABLE `login_attempts` ADD INDEX `idx_login_attempts_at` (`attempted_at`)',
    'SELECT 1');
PREPARE operational_index FROM @sql;
EXECUTE operational_index;
DEALLOCATE PREPARE operational_index;
