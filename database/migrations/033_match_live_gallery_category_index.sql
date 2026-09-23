-- Keep new development installations aligned with the live database. The
-- legacy category_id index is retained in production alongside the named
-- listing index, so add it when it is missing without disturbing either one.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'gallery_category') = 1
    AND (SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'gallery_category'
           AND index_name = 'category_id') = 0,
    'ALTER TABLE `gallery_category` ADD INDEX `category_id` (`category_id`)',
    'SELECT 1');
PREPARE gallery_category_legacy_index FROM @sql;
EXECUTE gallery_category_legacy_index;
DEALLOCATE PREPARE gallery_category_legacy_index;
