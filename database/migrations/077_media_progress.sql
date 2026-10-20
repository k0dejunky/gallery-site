-- The watch position, on the server.
--
-- The player's resume position lived in localStorage: per device, per
-- browser, invisible to the site. A member who watched half a video on
-- their phone started again on their laptop, and there was no list of what
-- they were watching.
--
-- The table holds one row per (member, video): where they stopped and how
-- long the video is, so the dashboard can show "Continue watching" and the
-- player can offer to resume.
CREATE TABLE IF NOT EXISTS media_progress (
    user_id          INT UNSIGNED NOT NULL,
    photo_id         INT UNSIGNED NOT NULL,
    position_seconds DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration_seconds DECIMAL(10,2) NULL,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, photo_id),
    INDEX idx_media_progress_updated (updated_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
