-- Retention emails keyed by USER (not subscription). A registered member with
-- no subscription has no subscription row to key the once-ever log on, so the
-- inactive-member win-back (and future user-level nudges) use this table.
-- Mirrors subscription_email_log; the unique key makes each event fire at most
-- once per member, whatever the cron cadence.
CREATE TABLE IF NOT EXISTS retention_email_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    event_key VARCHAR(40) NOT NULL,
    email_queue_id BIGINT UNSIGNED NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rel_user_event (user_id, event_key),
    KEY idx_rel_event (event_key),
    CONSTRAINT fk_rel_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rel_queue FOREIGN KEY (email_queue_id) REFERENCES email_queue(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
