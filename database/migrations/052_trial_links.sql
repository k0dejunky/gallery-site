-- Admin-generated free-trial links: each link grants a trial at the
-- configured membership level for the configured number of days, up to a
-- signup quota (max_uses). Redeeming is limited to one trial per user by the
-- existing TRIAL- guard in Subscription::grantTrialFor.
CREATE TABLE IF NOT EXISTS trial_links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(24) NOT NULL,
  level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  days INT UNSIGNED NOT NULL DEFAULT 3,
  max_uses INT UNSIGNED NOT NULL DEFAULT 1,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_trial_links_code (code),
  CONSTRAINT fk_trial_links_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;