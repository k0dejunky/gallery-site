-- 060 Lifecycle / retention emails.
-- email_queue gains scheduling + retry backoff so dunning can be queued for a
-- later time and failures back off instead of hammering SMTP every 5 minutes.
-- subscription_email_log makes each lifecycle mail exactly-once per
-- subscription and event (past_due, payment_failed, expired, ...).
--
-- NOTE: subscription_email_log.subscription_id deliberately has NO foreign
-- key to subscriptions: live installs ship subscriptions.id as signed INT
-- while schema.sql declares it INT UNSIGNED, so an FK would fail one or the
-- other. The app only ever logs valid ids and the (subscription_id, event_key)
-- unique key is the exactly-once guard.
ALTER TABLE email_queue
    ADD COLUMN scheduled_at DATETIME NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN next_attempt_at DATETIME NULL DEFAULT NULL AFTER attempts;

CREATE INDEX idx_email_queue_due ON email_queue (status, scheduled_at, next_attempt_at);

CREATE TABLE IF NOT EXISTS subscription_email_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NOT NULL,
    event_key VARCHAR(40) NOT NULL,
    email_queue_id BIGINT UNSIGNED NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sub_email_event (subscription_id, event_key),
    KEY idx_sel_event (event_key),
    KEY idx_sel_subscription (subscription_id),
    CONSTRAINT fk_sel_queue FOREIGN KEY (email_queue_id) REFERENCES email_queue(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;