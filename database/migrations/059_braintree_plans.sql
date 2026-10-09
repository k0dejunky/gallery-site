-- 059 Braintree subscription plan mapping.
-- Braintree subscriptions must reference a plan that exists in the merchant's
-- Braintree account. Each site plan stores the id of the Braintree plan it
-- bills through (provisioned from Admin -> Plans -> "Provision Braintree
-- plans"). Lifetime plans have no Braintree subscription and stay NULL.
ALTER TABLE plans
    ADD COLUMN braintree_plan_id VARCHAR(64) NULL DEFAULT NULL AFTER level;
