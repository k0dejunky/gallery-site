-- A sold unlock code must survive the gallery it was sold for.
--
-- unlock_codes.gallery_id was ON DELETE CASCADE, so deleting a gallery
-- deleted the codes that had been sold for it - while the purchases that
-- reference those codes survived, pointing at a code that no longer
-- existed. The admin's "who redeemed this code" trail went with it.
--
-- The foreign key is dropped by LOOKING IT UP: the name differs between a
-- database created from schema.sql and one that went through the
-- migrations, and this file has to work on both.

-- The auto-generated name (schema.sql creates the key unnamed).
SET @fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'unlock_codes'
      AND COLUMN_NAME = 'gallery_id'
      AND REFERENCED_TABLE_NAME = 'galleries'
    LIMIT 1
);
SET @sql := IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE unlock_codes DROP FOREIGN KEY `', @fk, '`'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE unlock_codes
    MODIFY gallery_id INT UNSIGNED NULL,
    ADD CONSTRAINT unlock_codes_gallery_fk FOREIGN KEY (gallery_id)
        REFERENCES galleries(id) ON DELETE SET NULL;
