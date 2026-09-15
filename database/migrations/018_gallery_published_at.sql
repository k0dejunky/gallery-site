-- Scheduled site publication: a gallery with a future published_at stays
-- hidden from public listings, category pages, search, favourites and the
-- gallery viewer until that moment, then appears automatically. NULL means
-- publish immediately (the legacy behaviour).
ALTER TABLE galleries
    ADD COLUMN published_at DATETIME NULL DEFAULT NULL AFTER min_level,
    ADD INDEX idx_galleries_published (published_at);