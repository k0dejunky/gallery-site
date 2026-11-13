-- 100_gifts.sql
--
-- A member can buy a membership as a GIFT. The mechanism is the one the site
-- already has: a 100%-off, single-use sale code pinned to the plan's tier.
-- The code IS the gift - no recipient account, no delivery token, no
-- redemption table. Redeeming it creates an ordinary subscription whose
-- `sale_code_id` records that the gift was used, which is the audit trail.
--
-- The purchase is an ordinary one-off charge (`purchases.item_type = 'gift'`,
-- `item_id` = the plan). Paying for it is the existing card/PayPal/offline
-- path; the code is issued on the purchaser's gifts page once the charge is
-- settled, and the unique key on `gift_purchase_id` makes that idempotent
-- (two page loads cannot mint two codes).
ALTER TABLE purchases
    MODIFY COLUMN item_type ENUM('gallery', 'tip', 'gift') NOT NULL;

ALTER TABLE sale_codes
    ADD COLUMN gift_purchaser_id INT UNSIGNED NULL DEFAULT NULL AFTER name,
    ADD COLUMN gift_plan_id INT UNSIGNED NULL DEFAULT NULL AFTER gift_purchaser_id,
    ADD COLUMN gift_purchase_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER gift_plan_id,
    ADD UNIQUE KEY uq_sale_codes_gift_purchase (gift_purchase_id),
    ADD INDEX idx_sale_codes_gift_purchaser (gift_purchaser_id);
