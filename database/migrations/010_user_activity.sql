-- User activity monitor: a lightweight event log recording when a member
-- logs in, logs out, and which galleries they open. Powers the admin
-- "User Monitor" tab (see UserActivity model + UserMonitorController).
-- A new row is written on every event, separate from admin_logs so the
-- audit trail and per-user activity feed stay independent.
CREATE TABLE IF NOT EXISTS user_activity (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    action        VARCHAR(20) NOT NULL,
    gallery_id    INT UNSIGNED NULL,
    gallery_name  VARCHAR(255) NULL,
    ip            VARCHAR(45) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ua_user (user_id),
    INDEX idx_ua_action (action),
    INDEX idx_ua_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
