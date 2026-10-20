-- The indexes the hot queries were missing.
--
-- Every one of these was an EXPLAIN on the live schema: a filesort, a
-- temporary table or a full table scan on a page a member loads.
--
-- 1. The notification inbox: "WHERE user_id = ? ORDER BY created_at DESC,
--    id DESC LIMIT 30" - the existing index is on (user_id, read_at), so
--    the sort was a filesort on every member page.
CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at, id);

-- 2. The wall feed: "WHERE deleted_at IS NULL ORDER BY pinned DESC,
--    created_at DESC, id DESC LIMIT n" - a full table scan plus a filesort.
CREATE INDEX idx_wall_posts_feed ON wall_posts (deleted_at, pinned, created_at, id);

-- 3. The photos of a gallery, ordered by position: the index stopped at
--    position, so the join to photos had to sort.
CREATE INDEX idx_gallery_photo_pos_photo ON gallery_photo (gallery_id, position, photo_id);

-- 4. The items of a collection, ordered by position.
CREATE INDEX idx_collection_items_order ON collection_items (collection_id, position, id);

-- 5. A member's favourite galleries, newest first.
CREATE INDEX idx_gallery_favorites_user_created ON gallery_favorites (user_id, created_at);

-- 6. A member's recently viewed galleries, newest first.
CREATE INDEX idx_gallery_viewers_user_viewed_at ON gallery_viewers (user_id, viewed_at, gallery_id);

-- 7. The unlock check: "WHERE user_id = ? AND item_type = 'gallery' AND
--    item_id = ? AND status IN ('paid','granted')" - the wall asks this for
--    a dozen galleries at a time.
CREATE INDEX idx_purchases_user_item ON purchases (user_id, item_type, item_id, status);

-- 8. The email worker's due query uses OR predicates that defeat
--    idx_email_queue_due, so it fell back to a scan.
CREATE INDEX idx_email_queue_status_id ON email_queue (status, id);
