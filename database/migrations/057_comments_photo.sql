-- 057 Per-media comments.
-- Comments were limited to galleries and wall posts. Members can now comment
-- on an individual image or video, so the commentable_type enum gains 'photo'
-- (the commentable_id is the photos.id of the image or video).
ALTER TABLE comments
    MODIFY COLUMN commentable_type ENUM('gallery','wall_post','photo') NOT NULL;