-- Live chat moderation: a member can be muted from the live group chat for a
-- period (NULL = never muted). chatSend rejects muted members and the site-wide
-- word filter (chat_settings.live_chat_filters) applies to member messages.
ALTER TABLE users ADD COLUMN chat_muted_until DATETIME NULL AFTER age_verified_at;