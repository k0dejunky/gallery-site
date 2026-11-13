-- Covering indexes for the per-page session queries.
--
-- The analytics page still has to answer "how many distinct addresses entered
-- on this page" for the top rows. idx_web_visits_entry_day covers (day, is_bot,
-- entry_url) but not ip, so MySQL read the row for every session: ~330 ms per
-- table. Adding ip makes the same query index-only (measured 0.011 s on
-- production, 474k sessions).
--
-- exit_url gets the mirror image.

ALTER TABLE web_visits
    ADD INDEX idx_web_visits_entry_ip (day, is_bot, entry_url, ip),
    ADD INDEX idx_web_visits_exit_ip  (day, is_bot, exit_url, ip);
