-- Operator read-tracking: how far through a conversation the operator has
-- read. The inbox "unread" count is based on this marker so reading a thread
-- (or replying) clears the new-message state for that user.
ALTER TABLE chat_conversations
    ADD COLUMN operator_read_through_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_read_message_id;