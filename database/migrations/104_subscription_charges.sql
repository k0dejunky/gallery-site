-- Subscription charges: one row per actual charge.
--
-- Revenue used to be derived from the subscriptions table itself: one row per
-- subscription, dated by COALESCE(starts_at, created_at). A subscription can
-- therefore only ever contribute its FIRST payment - a renewal has no row of
-- its own and no query could see it. On 2026-11-23 the System page reported
-- $5.00 collected for November while $25.00 had actually arrived: a $20
-- Platinum renewal on Nov 1 (subscription 41) plus a $5 Silver signup on
-- Nov 15 (subscription 4892). The renewal was invisible.
--
-- This is the missing per-charge ledger. Every money path writes one row
-- (Subscription::renew(), the Braintree card checkout, an admin settlement)
-- and Stats::finance() sums it by paid_at.
--
-- UNIQUE (subscription_id, reference) makes a replayed gateway webhook a
-- no-op: the reference is the payment id the gateway gave us, or a stable
-- synthetic key ("manual:<id>") for a settlement made outside the site.
CREATE TABLE IF NOT EXISTS subscription_charges (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency        CHAR(3) NOT NULL DEFAULT 'USD',
    gateway         VARCHAR(20) NULL DEFAULT NULL,
    reference       VARCHAR(160) NOT NULL,
    kind            ENUM('initial', 'renewal', 'adjustment') NOT NULL DEFAULT 'initial',
    paid_at         DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sub_charges_reference (subscription_id, reference),
    KEY idx_sub_charges_paid_at (paid_at),
    KEY idx_sub_charges_user (user_id),
    -- No FK on subscription_id on purpose: installations differ on whether
    -- subscriptions.id is INT or INT UNSIGNED (MySQL refuses a FK across the
    -- two). Subscription::delete() cleans the charges up instead.
    CONSTRAINT fk_sub_charges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
