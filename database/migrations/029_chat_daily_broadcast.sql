-- Daily chat broadcasts: a message the admin can schedule (or send now) to
-- every chat-eligible member's conversation. Status tracks each run so the
-- admin chat page can show a send log (scheduled -> sending -> sent/partial/
-- failed, or cancelled before it fires). A NULL scheduled_at means "send now".
CREATE TABLE IF NOT EXISTS chat_daily_broadcasts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message     TEXT NOT NULL,
    scheduled_at DATETIME NULL,
    status      ENUM('scheduled','sending','sent','partial','failed','cancelled') NOT NULL DEFAULT 'scheduled',
    recipients  INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count  INT UNSIGNED NOT NULL DEFAULT 0,
    error       VARCHAR(500) NULL,
    created_by  INT UNSIGNED NULL,
    sent_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_daily_broadcasts_status (status, scheduled_at),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;