-- Refer-a-friend: each member can share a personal link (/go/{code}); signups
-- that arrive through it are attributed to the referrer (referred_by_user_id)
-- and, once the referred member pays, the referrer earns +7 free days.
ALTER TABLE users
  ADD COLUMN referral_code CHAR(12) NULL UNIQUE AFTER signup_source_link_id,
  ADD COLUMN referred_by_user_id INT UNSIGNED NULL AFTER referral_code,
  ADD COLUMN referred_by_rewarded_at DATETIME NULL AFTER referred_by_user_id;