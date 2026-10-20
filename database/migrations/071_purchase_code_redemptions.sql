-- A multi-use unlock code could only ever be redeemed once.
--
-- The redemption writes a purchases row whose gateway_ref is the unlock
-- code's id, and the table had UNIQUE KEY (gateway, gateway_ref). The
-- second redemption of the same code therefore violated the key and raised
-- a PDOException (HTTP 500) - after UnlockCode::redeem() had already
-- consumed one of the code's uses, so the member paid and got nothing.
--
-- The key becomes per (code, user, item): a code can be redeemed by
-- several members (that is what max_uses is for), while one member
-- cannot redeem the same code for the same gallery twice.
ALTER TABLE purchases
    DROP INDEX uq_purchases_gateway_ref,
    ADD UNIQUE KEY uq_purchases_gateway_ref (gateway, gateway_ref, user_id, item_id);
