-- 095 Chat client.
--
-- The chat worked, but it was a list of bubbles: no reply, no way to take a
-- message back, no reaction, and the member could not tell whether their
-- message had been read. These are the columns a real chat client needs.
--
--   reply_to_id  the message being quoted. A quote is rendered from the row
--                it points at, not from a copy pasted into the text, so an
--                edited or deleted original is reflected in the quote.
--   edited_at    set when the sender edits their own message (the thread
--                says "edited", it does not pretend nothing happened).
--   deleted_at   a SOFT delete: the row stays, the bubble becomes "This
--                message was deleted". A hard delete would renumber nothing
--                (ids are not reused) but it would silently remove a message
--                the other side may have already answered, and it would
--                break a quote pointing at it.
--
--   operator_typing_at  the mirror of member_typing_at: the member needs to
--                       see "Amethyst is typing…" while the operator writes
--                       in the admin panel.
--
-- chat_reactions is one row per (message, actor, emoji). There is exactly one
-- member and one operator per conversation, so the actor is enough - a
-- per-user key would add a column that can never hold a second value.
ALTER TABLE chat_messages
    ADD COLUMN reply_to_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER content_refs,
    ADD COLUMN edited_at DATETIME NULL DEFAULT NULL AFTER reply_to_id,
    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER edited_at,
    ADD KEY idx_chat_msg_reply (reply_to_id);

ALTER TABLE chat_conversations
    ADD COLUMN operator_typing_at DATETIME NULL DEFAULT NULL AFTER member_typing_at;

CREATE TABLE IF NOT EXISTS chat_reactions (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    actor      ENUM('user', 'operator') NOT NULL,
    emoji      VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_reaction (message_id, actor, emoji),
    KEY idx_chat_reaction_msg (message_id),
    CONSTRAINT fk_chat_reaction_msg FOREIGN KEY (message_id)
        REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
