-- Chat feature: members chat with a self-hosted AI model (or a human
-- operator via the Android app). Eligibility is gated by a per-plan
-- can_chat flag (set on Platinum-yearly, Lifetime, and the Chat add-on).
-- Conversations run in either 'retrieval' (few-shot over operator replies)
-- or 'finetuned' (LoRA adapter) AI mode, switchable by the admin.
ALTER TABLE plans
    ADD COLUMN can_chat TINYINT(1) NOT NULL DEFAULT 0 AFTER can_comment;

CREATE TABLE IF NOT EXISTS chat_conversations (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    ai_mode     ENUM('retrieval','finetuned') NOT NULL DEFAULT 'retrieval',
    status      ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_chat_conv_user (user_id),
    KEY idx_chat_conv_status (status),
    CONSTRAINT fk_chat_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_role     ENUM('user','model','operator') NOT NULL,
    message         TEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chat_msg_conv_date (conversation_id, created_at),
    CONSTRAINT fk_chat_msg_conv FOREIGN KEY (conversation_id)
        REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_training_pairs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_message  TEXT NOT NULL,
    operator_reply TEXT NOT NULL,
    cleaned       TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant chat to the plans the admin designates: Platinum, Platinum-yearly
-- (Yearly), Lifetime, and the Chat add-on.
UPDATE plans SET can_chat = 1
WHERE name = 'Platinum'
   OR name = 'Chat add-on'
   OR (billing_cycle IN ('yearly','lifetime') AND level >= 3);