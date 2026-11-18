-- Hard-bounce suppression list.
--
-- Member mail is sent with the site's From address, so an invalid recipient
-- bounced straight back to the admin inbox (147 of them by mid-November). The
-- envelope sender is now bounce@ (MAIL_BOUNCE), a cron worker reads that
-- mailbox, and every address that hard-bounced lands here: the queue marks
-- its rows "skipped" instead of retrying forever and the direct senders skip
-- it too.
--
-- Releasing an address (a member fixed their inbox) deletes the row, so a
-- future bounce re-suppresses it.
CREATE TABLE IF NOT EXISTS email_suppressions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255) NOT NULL,
    reason     VARCHAR(120) NOT NULL DEFAULT 'hard bounce',
    source     VARCHAR(40) NOT NULL DEFAULT 'dsn',
    detail     VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_suppressions_email (email),
    KEY idx_email_suppressions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- A suppressed recipient is not a delivery failure: it is a decision. Keeping
-- it out of the "failed" bucket means the retry-all button cannot resurrect it
-- and the Emailer page can show it for what it is.
ALTER TABLE email_queue
    MODIFY COLUMN status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued';
