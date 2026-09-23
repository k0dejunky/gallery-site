-- Performance indexes: media lookup, membership/reporting filters, and the
-- recently-viewed / top-content sorts. Each ALTER is metadata-guarded.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'photos') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'photos' AND index_name = 'idx_photos_filename') = 0,
    'ALTER TABLE `photos` ADD INDEX `idx_photos_filename` (`filename`)',
    'SELECT 1');
PREPARE photos_filename FROM @sql;
EXECUTE photos_filename;
DEALLOCATE PREPARE photos_filename;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subscriptions') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'subscriptions' AND index_name = 'idx_subscriptions_status_expires') = 0,
    'ALTER TABLE `subscriptions` ADD INDEX `idx_subscriptions_status_expires` (`status`, `expires_at`)',
    'SELECT 1');
PREPARE subscriptions_status_expires FROM @sql;
EXECUTE subscriptions_status_expires;
DEALLOCATE PREPARE subscriptions_status_expires;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_role_status') = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_role_status` (`role`, `status`)',
    'SELECT 1');
PREPARE users_role_status FROM @sql;
EXECUTE users_role_status;
DEALLOCATE PREPARE users_role_status;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'gallery_viewers') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'gallery_viewers' AND index_name = 'idx_gallery_viewers_user_viewed') = 0,
    'ALTER TABLE `gallery_viewers` ADD INDEX `idx_gallery_viewers_user_viewed` (`user_id`, `viewed_at`)',
    'SELECT 1');
PREPARE gallery_viewers_user_viewed FROM @sql;
EXECUTE gallery_viewers_user_viewed;
DEALLOCATE PREPARE gallery_viewers_user_viewed;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'photos') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'photos' AND index_name = 'idx_photos_views') = 0,
    'ALTER TABLE `photos` ADD INDEX `idx_photos_views` (`views`)',
    'SELECT 1');
PREPARE photos_views FROM @sql;
EXECUTE photos_views;
DEALLOCATE PREPARE photos_views;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'galleries') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'galleries' AND index_name = 'idx_galleries_views') = 0,
    'ALTER TABLE `galleries` ADD INDEX `idx_galleries_views` (`views`)',
    'SELECT 1');
PREPARE galleries_views FROM @sql;
EXECUTE galleries_views;
DEALLOCATE PREPARE galleries_views;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_created_at') = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_created_at` (`created_at`)',
    'SELECT 1');
PREPARE users_created_at FROM @sql;
EXECUTE users_created_at;
DEALLOCATE PREPARE users_created_at;