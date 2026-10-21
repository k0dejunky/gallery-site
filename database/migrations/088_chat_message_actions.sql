-- 088 Per-message operator actions.
--
-- The operator app had no per-message actions at all: a message could not
-- be deleted, pinned, or copied, and a member could not be blocked from
-- the regular chat (only muted out of the live chat). The admin panel
-- could delete LIVE chat messages, but nothing could touch a regular
-- chat message.
--
-- pinned_at marks a message the operator wants to keep at hand; the
-- thread sorts pinned messages to the top and shows them separately.
--
-- The "blocked" state lives on the user: a blocked member cannot send
-- chat messages, but can still read and can still be unblocked.
ALTER TABLE chat_messages
    ADD COLUMN pinned_at DATETIME NULL AFTER created_at,
    ADD INDEX idx_chat_msg_pinned (conversation_id, pinned_at);

ALTER TABLE users
    ADD COLUMN chat_blocked_until DATETIME NULL AFTER chat_muted_until;
