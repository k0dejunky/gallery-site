-- Collections hold a whole gallery OR a single video; videos cache their
-- duration (seconds) so playlist rows can show it without a per-row probe.
ALTER TABLE photos ADD COLUMN duration_seconds INT UNSIGNED NULL AFTER link;

ALTER TABLE collection_items
  MODIFY gallery_id INT UNSIGNED NULL,
  ADD COLUMN photo_id INT UNSIGNED NULL AFTER gallery_id,
  ADD UNIQUE KEY uq_collection_photo (collection_id, photo_id),
  ADD FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE;