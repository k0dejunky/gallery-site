-- 084 The append-only money ledger.
--
-- A purchase row was MUTATED in place: pending -> paid -> refunded, and an
-- admin could set any status on any row at any time. There was no record of
-- what happened: the row only ever showed the LAST state, so "was this
-- paid and then refunded, or never paid at all" had no answer, and an
-- admin's manual change was indistinguishable from a gateway's.
--
-- Every state change now appends a row here. The ledger is the record; the
-- purchases row is the current state, a projection of it.
CREATE TABLE IF NOT EXISTS purchase_events (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id  INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NULL,
    -- created | paid | granted | refunded | chargeback | status_changed | note
    event        VARCHAR(32) NOT NULL,
    amount       DECIMAL(10,2) NULL,
    currency     CHAR(3) NULL,
    gateway      VARCHAR(40) NULL,
    gateway_ref  VARCHAR(255) NULL,
    -- Who caused it: 0 for the site, a user id for an admin.
    actor_id     INT UNSIGNED NULL,
    reason       VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_purchase_events_purchase (purchase_id, id),
    INDEX idx_purchase_events_user (user_id, id),
    INDEX idx_purchase_events_event (event, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
