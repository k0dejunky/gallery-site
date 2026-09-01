-- Daily content view counts for the admin dashboard's view-trends charts.
-- Rows are upserted (one per entity_type + entity_id + view_date) whenever a
-- logged-in user views a gallery or photo, so trends can be charted over time
-- without replaying every individual view event.
CREATE TABLE IF NOT EXISTS content_views (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('gallery','photo') NOT NULL,
    entity_id   INT UNSIGNED NOT NULL,
    view_date   DATE NOT NULL,
    count       INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_content_views_type_id_date (entity_type, entity_id, view_date),
    INDEX idx_content_views_date (view_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
