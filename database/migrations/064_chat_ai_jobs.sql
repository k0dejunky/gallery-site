-- Chat upgrade cycle C: background AI reply queue + supporting indexes +
-- one-time retention prunes.
--
-- `chat_ai_jobs` holds one row per member message that the background worker
-- must answer while `chat_ai_async` is enabled. The UNIQUE key on
-- user_message_id makes enqueueing idempotent (a retried POST can never
-- schedule a second answer for the same message). `user_level` snapshots the
-- member's effective level at send time so the worker can run the gallery
-- content search with exactly the visibility the synchronous path would use.

CREATE TABLE IF NOT EXISTS chat_ai_jobs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    user_message_id BIGINT UNSIGNED NOT NULL,
    message         TEXT NOT NULL,
    ai_mode         VARCHAR(20) NOT NULL,
    user_level      INT NULL,
    status          ENUM('queued', 'running', 'done', 'error') NOT NULL DEFAULT 'queued',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error           VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_ai_jobs_message (user_message_id),
    KEY idx_chat_ai_jobs_status (status, updated_at),
    CONSTRAINT fk_chat_ai_jobs_conv FOREIGN KEY (conversation_id)
        REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The unread predicate is `id > operator_read_through_id`; without an
-- (conversation_id, id) index every queue refresh walks the created_at index.
ALTER TABLE chat_messages
    ADD INDEX idx_chat_msg_conv_id (conversation_id, id);

-- Retention prunes below filter on created_at only.
ALTER TABLE chat_reply_idempotency
    ADD INDEX idx_chat_reply_idem_created (created_at);
ALTER TABLE notifications
    ADD INDEX idx_notifications_created (created_at);

-- One-time prunes (ongoing pruning runs in Housekeeping):
-- idempotency keys only need to outlive a client's retry window.
DELETE FROM chat_reply_idempotency
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
-- Read notifications older than 90 days are noise in the wall inbox.
DELETE FROM notifications
    WHERE read_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
