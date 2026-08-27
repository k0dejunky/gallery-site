-- Background queue for long-running photo edits (bulk rotate). Photos are
-- processed by the supervised photo_edit_worker instead of the HTTP request so
-- rotating many large images no longer hits the FPM timeout.
CREATE TABLE IF NOT EXISTS photo_edit_jobs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    gallery_id    INT UNSIGNED NOT NULL,
    operation     VARCHAR(20) NOT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'queued',
    progress      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    total         INT UNSIGNED NOT NULL DEFAULT 0,
    done          INT UNSIGNED NOT NULL DEFAULT 0,
    failed        INT UNSIGNED NOT NULL DEFAULT 0,
    error         TEXT NULL,
    metadata_json TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at    DATETIME NULL,
    finished_at   DATETIME NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_photo_edit_status (status),
    INDEX idx_photo_edit_gallery (gallery_id),
    CONSTRAINT fk_photo_edit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_photo_edit_gallery FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
