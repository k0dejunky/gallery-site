-- Live video sessions: one broadcast at a time, initiated by the operator
-- app. Only the SHA-256 of the one-time RTMP stream key is stored; the raw key
-- is returned to the app exactly once at /live/start and validated by the
-- MediaMTX auth webhook (publish) and the signed playback token (read).
CREATE TABLE IF NOT EXISTS live_sessions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stream_key  CHAR(64) NOT NULL,
    created_by  INT UNSIGNED NOT NULL,
    status      ENUM('pending','live','ended') NOT NULL DEFAULT 'pending',
    started_at  DATETIME NULL,
    ended_at    DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_live_status (status),
    INDEX idx_live_key (stream_key),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;