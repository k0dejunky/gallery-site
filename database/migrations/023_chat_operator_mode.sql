-- Add an operator-only chat mode: conversations can now run with no AI at
-- all, answered only by a human operator (Android app / admin panel).
ALTER TABLE chat_conversations
    MODIFY COLUMN ai_mode ENUM('retrieval','finetuned','operator') NOT NULL DEFAULT 'retrieval';