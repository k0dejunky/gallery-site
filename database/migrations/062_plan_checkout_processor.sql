-- 062 Admin-selectable checkout processor per plan.
-- Each plan chooses how the membership page takes subscriptions:
--   auto     - legacy behaviour: PayPal quick-subscribe buttons on the
--              silver/gold/platinum/chat plans, generic processor dropdown
--              (incl. Braintree card checkout) on every other plan.
--   paypal   - always show the PayPal subscription button.
--   braintree- always send the card (Braintree) checkout.
--   offline  - manual request only (no card / PayPal button).
-- Lifetime plans have no Braintree subscription; they ignore 'braintree'.
ALTER TABLE plans
    ADD COLUMN checkout_processor ENUM('auto','paypal','braintree','offline')
        NOT NULL DEFAULT 'auto' AFTER braintree_plan_id;