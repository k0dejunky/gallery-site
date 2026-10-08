-- 056 Wall posts can reference a gallery.
-- The auto poster publishes a wall post per recommended gallery it submits,
-- and the wall renders a membership-gated preview grid from the linked
-- gallery (real thumbnails above the viewer's level, blurred teasers below).
ALTER TABLE wall_posts
    ADD COLUMN gallery_id INT UNSIGNED NULL AFTER body,
    ADD INDEX idx_wall_posts_gallery (gallery_id);