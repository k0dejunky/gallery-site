-- Hot listing indexes: category filters and newest published galleries.
-- Each ALTER is metadata-guarded for existing installations.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'gallery_category') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'gallery_category' AND index_name = 'idx_gallery_category_category') = 0,
    'ALTER TABLE `gallery_category` ADD INDEX `idx_gallery_category_category` (`category_id`)',
    'SELECT 1');
PREPARE gallery_category_index FROM @sql;
EXECUTE gallery_category_index;
DEALLOCATE PREPARE gallery_category_index;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'galleries') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'galleries' AND index_name = 'idx_galleries_listing') = 0,
    'ALTER TABLE `galleries` ADD INDEX `idx_galleries_listing` (`deleted_at`, `published_at`, `created_at`)',
    'SELECT 1');
PREPARE galleries_listing_index FROM @sql;
EXECUTE galleries_listing_index;
DEALLOCATE PREPARE galleries_listing_index;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'photos') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'photos' AND index_name = 'idx_photos_media_created') = 0,
    'ALTER TABLE `photos` ADD INDEX `idx_photos_media_created` (`is_video`, `created_at`)',
    'SELECT 1');
PREPARE photos_media_index FROM @sql;
EXECUTE photos_media_index;
DEALLOCATE PREPARE photos_media_index;