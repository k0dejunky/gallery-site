-- Live group chat: one shared room per live session, so everyone watching the
-- stream can chat together.
CREATE TABLE IF NOT EXISTS live_chat_messages (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  BIGINT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    sender_role VARCHAR(10) NOT NULL DEFAULT 'user',
    message     VARCHAR(1000) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_live_chat_session (session_id, id),
    FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;