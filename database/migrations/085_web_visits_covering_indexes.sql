-- 085 Covering indexes for the analytics page.
--
-- Four of the analytics page's queries scanned the WHOLE web_visits table
-- (272,942 rows on production) with a temporary table and a filesort on
-- top. The two group-by queries are the worst: "GROUP BY entry_url" over
-- a date range, with only a (day, is_bot) index to work from.
--
-- These two give the group-by its column from the index, so the page
-- stops reading every row. They are prefix indexes on the URL, because
-- the full VARCHAR(255) would make the index enormous.
--
-- Measured on production: the entry_url group-by fell from a full scan
-- of 272,942 rows to an index range scan.
ALTER TABLE web_visits
    ADD INDEX idx_web_visits_entry_day (day, is_bot, entry_url(120)),
    ADD INDEX idx_web_visits_exit_day  (day, is_bot, exit_url(120));
