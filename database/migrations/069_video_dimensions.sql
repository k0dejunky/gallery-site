-- 069 media display dimensions.
-- The player sizes its box from the video's DISPLAY size (the size the
-- viewer sees, after the rotation in the display matrix is applied). Without
-- it the server had no idea whether a clip was vertical or horizontal, so it
-- rendered a portrait video in a narrow strip with black bars around it and
-- the picture jumped when the poster was replaced by the real frame.
--
-- width/height are the DISPLAY pixels; rotation is the clockwise display
-- rotation carried by the source (0/90/180/270). Images use the same
-- columns with the size after their EXIF orientation is applied.

ALTER TABLE photos
    ADD COLUMN width     SMALLINT UNSIGNED NULL DEFAULT NULL AFTER duration_seconds,
    ADD COLUMN height    SMALLINT UNSIGNED NULL DEFAULT NULL AFTER width,
    ADD COLUMN rotation  SMALLINT NOT NULL DEFAULT 0 AFTER height;
