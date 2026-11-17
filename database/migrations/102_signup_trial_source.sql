-- Record which trial link a signup came from. The subscription keeps the
-- TRIAL-... reference, but that random value cannot be traced back to the
-- admin-generated link (or its level/days) after the fact. The code is stored
-- alongside the FK so the source survives deleting the link.
ALTER TABLE users
    ADD COLUMN signup_trial_link_id INT UNSIGNED NULL DEFAULT NULL AFTER signup_source_link_id,
    ADD COLUMN signup_trial_code VARCHAR(24) NULL DEFAULT NULL AFTER signup_trial_link_id,
    ADD KEY idx_users_signup_trial (signup_trial_link_id),
    ADD CONSTRAINT fk_users_trial_link FOREIGN KEY (signup_trial_link_id)
        REFERENCES trial_links(id) ON DELETE SET NULL;
