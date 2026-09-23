-- Allow subscriptions to record a past-due / suspended state as reported by
-- the payment processors (Braintree subscription_went_past_due, PayPal
-- BILLING.SUBSCRIPTION.SUSPENDED). Metadata-guarded for existing installs.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'subscriptions'
       AND column_name = 'status'
       AND column_type NOT LIKE '%past_due%') = 1,
    'ALTER TABLE `subscriptions` MODIFY `status` ENUM(''pending'', ''active'', ''cancelled'', ''expired'', ''past_due'') NOT NULL DEFAULT ''pending''',
    'SELECT 1');
PREPARE subscriptions_past_due FROM @sql;
EXECUTE subscriptions_past_due;
DEALLOCATE PREPARE subscriptions_past_due;