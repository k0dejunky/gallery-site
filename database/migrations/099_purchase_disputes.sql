-- 099_purchase_disputes.sql
--
-- A one-off charge can come back as a DISPUTE (a chargeback). The row had no
-- such state, so a disputed charge stayed 'paid': it counted as revenue and
-- the member kept the unlock even though the money was in flight.
--
-- 'disputed' is money that is neither ours nor refunded - the processor has
-- not decided yet. It grants nothing (every access check asks for
-- paid/granted) and it is not counted as revenue (the same filters). When the
-- dispute is won the row returns to 'paid'; when it is lost it becomes
-- 'refunded'.
--
-- The subscription status enum is deliberately NOT touched: a membership
-- dispute is handled on the purchase/charge side, and altering the
-- subscriptions enum would invalidate rows on installations where the level
-- columns are hand-edited.
ALTER TABLE purchases
    MODIFY COLUMN status ENUM('pending', 'paid', 'granted', 'refunded', 'disputed')
    NOT NULL DEFAULT 'paid';
