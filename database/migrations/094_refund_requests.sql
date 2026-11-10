-- 094 Refund requests.
--
-- A member had no way to ask for their money back. The site could take a
-- card payment, and an admin could refund a purchase from the ledger, but
-- the member had no surface on which to say "I want a refund" - the only
-- route was to email support and hope.
--
-- One row per request. Exactly one of purchase_id / subscription_id is set:
--
--   purchase_id      a one-off charge (a gallery unlock or a tip). The site
--                    holds the processor's transaction id, so approving the
--                    request can refund the money itself.
--   subscription_id  a membership term. The site stores the SUBSCRIPTION id,
--                    not the id of each monthly charge, so it cannot refund
--                    a single term by itself; an approval records the
--                    decision and the money is refunded in the processor.
--
-- status is the decision, and only 'open' can be decided. A member may
-- withdraw their own open request.
CREATE TABLE IF NOT EXISTS refund_requests (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    purchase_id     BIGINT UNSIGNED NULL DEFAULT NULL,
    subscription_id INT UNSIGNED NULL DEFAULT NULL,
    reason          VARCHAR(1000) NOT NULL,
    status          ENUM('open', 'approved', 'denied', 'withdrawn') NOT NULL DEFAULT 'open',
    admin_note      VARCHAR(1000) NULL DEFAULT NULL,
    decided_by      INT UNSIGNED NULL DEFAULT NULL,
    decided_at      DATETIME NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refund_status (status, created_at),
    INDEX idx_refund_user (user_id, created_at),
    INDEX idx_refund_purchase (purchase_id),
    INDEX idx_refund_subscription (subscription_id),
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by)  REFERENCES users(id)     ON DELETE SET NULL
    -- Deliberately NO foreign key on subscription_id: installations differ on
    -- whether subscriptions.id is INT or INT UNSIGNED (the two live boxes are
    -- signed, the schema is unsigned) and MySQL refuses a FK across the two.
    -- Subscription::delete() removes the requests instead, and a request can
    -- only be created from a subscription the member owns.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
