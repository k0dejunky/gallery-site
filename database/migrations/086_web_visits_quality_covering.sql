-- 086 A covering index for the analytics quality and bounce queries.
--
-- "SELECT COUNT(*), COUNT(DISTINCT ip), AVG(duration_sec), AVG(pages),
-- MAX(pages), AVG(bytes) FROM web_visits WHERE day BETWEEN ? AND ? AND
-- is_bot = 0" read every row of the table: the index on (day, is_bot)
-- narrows the range but the columns it needs are not in it, so each
-- matched row was a row lookup - 271,170 of them.
--
-- This index carries the columns the query reads, so it is an index-only
-- scan.
ALTER TABLE web_visits
    ADD INDEX idx_web_visits_quality (day, is_bot, ip, duration_sec, pages, bytes);
