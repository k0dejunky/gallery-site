-- 081 A grace period for a past-due subscription.
--
-- The Braintree webhook for "subscription_went_past_due" said, in a
-- comment: "Flag but don't cancel - give a grace period". There was no
-- grace period. Subscription::activeWhere() accepted only 'active' and
-- 'cancelled', so the moment the flag was set the member LOST their
-- access - on the very first failed card, with no grace and no way to
-- fix the card, because the past-due email links to a page with no
-- payment-method form on it.
--
-- This adds the column the grace period is measured from, so the window
-- starts when the subscription went past due rather than whenever the row
-- was last touched for any reason.
ALTER TABLE subscriptions
    ADD COLUMN past_due_at DATETIME NULL AFTER status;
