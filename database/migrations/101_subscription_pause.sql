-- 101_subscription_pause.sql
--
-- A member can PAUSE a membership the SITE bills (a manual/comped/trial
-- subscription). The status enum is deliberately NOT touched: a pause is a
-- property of the term, not a fifth state, and an ALTER on the enum would
-- fail on installations whose subscription rows predate it.
--
-- Mechanics:
--   paused_until is a moment in the future. While it is ahead, the access
--   predicates exclude the subscription and the member has no access.
--   expires_at is moved forward by the whole pause at pause time, so the
--   member never loses the days they paid for; resuming early moves it back
--   by the unused remainder. No worker is needed to end a pause: once
--   paused_until has passed the predicates let the row back in, with the
--   already-extended expiry.
--
-- Card/PayPal memberships are NOT pausable here: the processor owns the
-- billing schedule and would keep charging while the site revoked access.
-- Subscription::pause() refuses those with a reason the UI shows.
ALTER TABLE subscriptions
    ADD COLUMN paused_at    DATETIME NULL DEFAULT NULL AFTER past_due_at,
    ADD COLUMN paused_until DATETIME NULL DEFAULT NULL AFTER paused_at,
    ADD INDEX idx_subscriptions_paused (paused_until);
