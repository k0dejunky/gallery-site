-- 061 Live one-off card payments (PPV unlocks / tips).
-- purchases gains a payment processor reference, an updated timestamp, and a
-- unique (gateway, gateway_ref) key so webhook reconciliation of a charge is
-- idempotent (a settled/denied event can never double-credit or double-grant).
ALTER TABLE purchases
    ADD COLUMN payment_processor_id INT UNSIGNED NULL DEFAULT NULL AFTER gateway_ref,
    ADD COLUMN updated_at DATETIME NULL DEFAULT NULL AFTER created_at,
    ADD UNIQUE KEY uq_purchases_gateway_ref (gateway, gateway_ref);