-- One-click newsletter opt-out: users who open the signed unsubscribe link in
-- an emailed digest are excluded from every future emailer send. Excluded
-- users are simply filtered out of the recipient queries; nothing is deleted.
ALTER TABLE users
    ADD COLUMN marketing_opt_out TINYINT(1) NOT NULL DEFAULT 0 AFTER email_verified_at;