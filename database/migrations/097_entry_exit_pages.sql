-- Entry and exit pages, pre-aggregated per day.
--
-- The analytics page grouped 474,000 web_visits rows by entry_url and again by
-- exit_url on every load (489 ms + 471 ms), because entry/exit are session
-- properties that were never rolled up. They are now written per day by the
-- log aggregator, the same way web_stats_urls already is, and the page reads
-- 60 daily rows instead of two months of sessions.
--
-- unique_ips is the count of distinct addresses for that page ON THAT DAY;
-- a multi-day range cannot sum them without double-counting a returning
-- visitor, so WebStats::visitPages() recomputes exact uniques for the top
-- rows only (an index range scan over a handful of URLs).

CREATE TABLE IF NOT EXISTS web_stats_entry_pages (
    day          DATE             NOT NULL,
    url          VARCHAR(255)     NOT NULL,
    visits       INT UNSIGNED     NOT NULL DEFAULT 0,
    unique_ips   INT UNSIGNED     NOT NULL DEFAULT 0,
    duration_sec BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (day, url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS web_stats_exit_pages (
    day          DATE             NOT NULL,
    url          VARCHAR(255)     NOT NULL,
    visits       INT UNSIGNED     NOT NULL DEFAULT 0,
    unique_ips   INT UNSIGNED     NOT NULL DEFAULT 0,
    duration_sec BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (day, url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill from the sessions the aggregator has already stored. Runs once;
-- ON DUPLICATE keeps a re-run harmless.
INSERT INTO web_stats_entry_pages (day, url, visits, unique_ips, duration_sec)
SELECT day, entry_url, COUNT(*), COUNT(DISTINCT ip), COALESCE(SUM(duration_sec), 0)
  FROM web_visits
 WHERE is_bot = 0 AND entry_url <> ''
 GROUP BY day, entry_url
ON DUPLICATE KEY UPDATE visits = VALUES(visits), unique_ips = VALUES(unique_ips), duration_sec = VALUES(duration_sec);

INSERT INTO web_stats_exit_pages (day, url, visits, unique_ips, duration_sec)
SELECT day, exit_url, COUNT(*), COUNT(DISTINCT ip), COALESCE(SUM(duration_sec), 0)
  FROM web_visits
 WHERE is_bot = 0 AND exit_url <> ''
 GROUP BY day, exit_url
ON DUPLICATE KEY UPDATE visits = VALUES(visits), unique_ips = VALUES(unique_ips), duration_sec = VALUES(duration_sec);
