-- 083 An idempotency key for the one-off charges.
--
-- A double-click on "Pay" created TWO Braintree sales for one unlock.
-- The rate limit (5 per 900 seconds) did not help: the two charges are
-- milliseconds apart.
--
-- The key travels with the form, so the second click of the same button
-- carries the same key and is served the purchase the first click made,
-- instead of charging again. The key is bound to the purchase: the same
-- key with a different amount or a different item is refused rather than
-- answered with the wrong purchase.
ALTER TABLE purchases
    ADD COLUMN idempotency_key VARCHAR(80) NULL AFTER payment_processor_id,
    ADD UNIQUE KEY uq_purchases_idempotency (idempotency_key);
