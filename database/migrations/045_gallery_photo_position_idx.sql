-- Gallery photo ordering: gallery viewers order photos by display position
-- (ORDER BY position ASC, id ASC). The PK is (gallery_id, photo_id), so a
-- filesort was needed for every gallery with more than a few photos. This
-- index lets the ordering be satisfied straight from the index.
CREATE INDEX idx_gallery_photo_pos ON gallery_photo (gallery_id, position);