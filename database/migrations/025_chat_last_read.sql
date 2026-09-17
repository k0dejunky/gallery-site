-- Track what the member has actually read so the sidebar unread badge
-- reflects unseen replies rather than all replies after their last message.
-- The ChatController marks this on page load and on streamed messages.
ALTER TABLE chat_conversations
    ADD COLUMN last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER status;