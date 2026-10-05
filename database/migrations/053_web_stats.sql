-- Admin web analytics aggregated from the Apache combined access logs.
--
-- Design notes:
--  * Nothing here is ever pruned automatically. bin/aggregate_access_log.php
--    recomputes a day with DELETE WHERE day = ? + insert inside a transaction,
--    so re-running is idempotent and rotation of the raw logs cannot corrupt
--    the history.
--  * Bots are stored side by side with humans (human_hits/bot_hits) rather than
--    mixed in, so the UI can hide them with a toggle without re-parsing.
--  * Raw IPs are kept on purpose: this is a private analytics table that only
--    admins with the "analytics" permission can read.
--  * web_visits is the only place sessions are stored; entry/exit pages,
--    average duration and bounce rate are derived from it with SQL, so there is
--    no denormalised copy that could drift.
CREATE TABLE IF NOT EXISTS web_stats_daily (
  day DATE NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  human_hits INT UNSIGNED NOT NULL DEFAULT 0,
  bot_hits INT UNSIGNED NOT NULL DEFAULT 0,
  page_views INT UNSIGNED NOT NULL DEFAULT 0,
  bot_page_views INT UNSIGNED NOT NULL DEFAULT 0,
  asset_hits INT UNSIGNED NOT NULL DEFAULT 0,
  api_hits INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  visits INT UNSIGNED NOT NULL DEFAULT 0,
  visit_pages INT UNSIGNED NOT NULL DEFAULT 0,
  visit_duration BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uniq_ips INT UNSIGNED NOT NULL DEFAULT 0,
  human_uniq_ips INT UNSIGNED NOT NULL DEFAULT 0,
  status_4xx INT UNSIGNED NOT NULL DEFAULT 0,
  status_5xx INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_lines INT UNSIGNED NOT NULL DEFAULT 0,
  source_files VARCHAR(255) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_stats_hourly (
  day DATE NOT NULL,
  hour TINYINT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  human_hits INT UNSIGNED NOT NULL DEFAULT 0,
  bot_hits INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uniq_ips INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_stats_urls (
  day DATE NOT NULL,
  url VARCHAR(255) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  human_hits INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uniq_ips INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, url),
  KEY idx_web_stats_urls_day_hits (day, hits),
  KEY idx_web_stats_urls_url (url(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_stats_referrers (
  day DATE NOT NULL,
  host VARCHAR(190) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  bot_hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, host),
  KEY idx_web_stats_referrers_day_hits (day, hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_stats_agents (
  day DATE NOT NULL,
  browser VARCHAR(48) NOT NULL,
  os VARCHAR(48) NOT NULL,
  device VARCHAR(16) NOT NULL,
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, browser, os, device, is_bot),
  KEY idx_web_stats_agents_day_hits (day, hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_stats_status (
  day DATE NOT NULL,
  status SMALLINT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_stats_filetypes (
  day DATE NOT NULL,
  ext VARCHAR(16) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, ext)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (day, ip, bot flag): an address can appear both as a person and
-- as a crawler, and the include-bots toggle must be able to split them.
CREATE TABLE IF NOT EXISTS web_stats_ips (
  day DATE NOT NULL,
  ip VARCHAR(45) NOT NULL,
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  visits INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, ip, is_bot),
  KEY idx_web_stats_ips_day_hits (day, hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_visits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL,
  ip VARCHAR(45) NOT NULL,
  started_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  duration_sec INT UNSIGNED NOT NULL DEFAULT 0,
  pages INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  entry_url VARCHAR(255) NOT NULL DEFAULT '',
  exit_url VARCHAR(255) NOT NULL DEFAULT '',
  referrer VARCHAR(190) NOT NULL DEFAULT '',
  browser VARCHAR(48) NOT NULL DEFAULT '',
  os VARCHAR(48) NOT NULL DEFAULT '',
  device VARCHAR(16) NOT NULL DEFAULT '',
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_web_visits_day (day, is_bot),
  KEY idx_web_visits_started (started_at),
  KEY idx_web_visits_entry (entry_url(191)),
  KEY idx_web_visits_exit (exit_url(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;