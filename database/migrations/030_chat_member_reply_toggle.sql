-- Per-conversation member reply control: the operator can message any user,
-- and the admin toggles whether that user may reply. Default is enabled; a
-- chat-eligible member can only send when both eligible AND this flag is on.
ALTER TABLE chat_conversations
    ADD COLUMN member_reply_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status;