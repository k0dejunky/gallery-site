-- Typing indicator: the member's website composer pings /chat/typing while
-- writing; the bridge stream reports it to the operator app. Stored as a
-- timestamp on the conversation (updated in place, no history needed).
ALTER TABLE chat_conversations ADD COLUMN member_typing_at DATETIME NULL;
