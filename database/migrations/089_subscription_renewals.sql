-- 089 Subscription renewals.
--
-- A monthly subscription was never extended: the renewal postback was
-- dropped before it could do anything, and nothing in the site ever moved an
-- expiry date forward. A member was charged by the gateway every month and
-- lost their access when the first term ran out.
--
-- Two breaks had to be fixed together:
--
--   1. the postback was not even FOUND - a renewal arrives as a payment
--      (a sale), and the row is stored under the subscription's id;
--   2. even when found, only a pending or past-due subscription was
--      touched, so a payment for a live subscription did nothing.
--
-- last_payment_ref is the id of the payment that was last counted. It is
-- what makes a renewal idempotent: a replayed postback for the same payment
-- must not grant a second period.
--
-- periods_granted counts the periods the site has granted. It is the
-- "how many months has this member paid for" answer, and it is what the
-- member receipt counts up from.
ALTER TABLE subscriptions
    ADD COLUMN last_payment_ref VARCHAR(120) NULL AFTER transaction_ref,
    ADD COLUMN periods_granted INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_payment_ref;
