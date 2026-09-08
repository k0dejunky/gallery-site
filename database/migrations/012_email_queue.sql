-- Emailer queue: one row per outgoing newsletter email, holding a snapshot of
-- the recipient address and the rendered subject/body so a digest can never
-- change after it was enqueued. The email worker picks 'queued' rows and hands
-- them to Mailer::sendHtml(), marking each row 'sent' or 'failed'. A deleted
-- account keeps its queued row but drops its user link (the send still goes
-- to the snapshot address so the worker never has to re-resolve recipients).
CREATE TABLE IF NOT EXISTS email_queue (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience   ENUM('subscriber','non_subscriber') NOT NULL,
    user_id    INT UNSIGNED NULL,
    email      VARCHAR(255) NOT NULL,
    subject    VARCHAR(255) NOT NULL,
    html_body  MEDIUMTEXT NOT NULL,
    text_body  TEXT NULL,
    status     ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error      VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at    DATETIME NULL,
    INDEX idx_email_queue_status (status),
    INDEX idx_email_queue_audience (audience),
    CONSTRAINT fk_email_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;