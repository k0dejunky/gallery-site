-- Gallery publish queue: a gallery with a future published_at stays hidden
-- from every public read path (listing, search, category, favourites, the
-- gallery viewer) until that moment passes, then appears automatically.
-- NULL means publish immediately (the legacy behaviour).
ALTER TABLE galleries
    ADD COLUMN published_at DATETIME NULL DEFAULT NULL AFTER min_level,
    ADD INDEX idx_galleries_published (published_at);