-- 105 Rename the AI flag to Fan Art.
--
-- The flag marks a set as fan art. It was born as is_ai in migration 093, so
-- every box that has been running since then carries that name, while a
-- fresh install gets is_fanart from schema.sql. This migration handles all
-- three states:
--
--   only is_ai      -> rename it (every value is preserved)
--   both columns    -> merge is_ai into is_fanart, then drop is_ai
--   only is_fanart  -> nothing to do (re-run or fresh install)
--
-- It is deliberately conditional: a plain CHANGE COLUMN fails on a fresh
-- install (the column is already is_fanart), and a plain ADD COLUMN would
-- leave a dead is_ai behind.
SET @has_ai = (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'galleries' AND COLUMN_NAME = 'is_ai');
SET @has_fanart = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'galleries' AND COLUMN_NAME = 'is_fanart');

SET @sql = CASE
    WHEN @has_ai = 1 AND @has_fanart = 0
        THEN 'ALTER TABLE galleries CHANGE COLUMN is_ai is_fanart TINYINT(1) NOT NULL DEFAULT 0'
    WHEN @has_ai = 1 AND @has_fanart = 1
        THEN 'UPDATE galleries SET is_fanart = GREATEST(is_fanart, is_ai)'
    ELSE 'DO 0'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = CASE
    WHEN @has_ai = 1 AND @has_fanart = 1
        THEN 'ALTER TABLE galleries DROP COLUMN is_ai'
    ELSE 'DO 0'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
