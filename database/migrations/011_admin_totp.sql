-- Admin two-factor authentication (TOTP).
-- Adds per-user columns to enable RFC 6238 TOTP for admin accounts.
ALTER TABLE users
    ADD COLUMN totp_secret CHAR(64) NULL DEFAULT NULL AFTER theme_preset,
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret,
    ADD COLUMN totp_verified_at DATETIME NULL DEFAULT NULL AFTER totp_enabled;
