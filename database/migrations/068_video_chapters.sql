-- 068 video chapters.
-- The chapters worker runs ffmpeg scene detection over each video and stores
-- the detected scene boundaries as chapter markers (start offsets + titles),
-- rendered as seek-bar ticks and a clickable chapter list in the player, and
-- exposed in the VideoObject schema markup. Chapters are derived data: the
-- worker rewrites them whenever the source changes or they are missing.

CREATE TABLE IF NOT EXISTS video_chapters (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    photo_id      INT UNSIGNED NOT NULL,
    position      INT UNSIGNED NOT NULL DEFAULT 0,
    start_seconds DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    title         VARCHAR(120) NOT NULL DEFAULT '',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chapter_start (photo_id, start_seconds),
    INDEX idx_chapters_photo (photo_id, position),
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
