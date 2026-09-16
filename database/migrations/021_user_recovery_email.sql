-- Abandoned-signup recovery: marks which users have received the one-off
-- "finish setting up your account" email (sent by housekeeping N days after
-- signup when the email is still unverified). NULL = not yet sent; the column
-- prevents the same user from being emailed repeatedly.
ALTER TABLE users
    ADD COLUMN recovery_email_sent_at DATETIME NULL AFTER email_verification_token;