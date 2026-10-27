-- 091 A missed search can be dismissed without losing the history.
--
-- The admin's "Pending Category Approval" list holds the search terms that
-- were searched often and found nothing. "Dismiss" told the site to forget
-- the term, and it did that by DELETING every search_stats row for it - so
-- silencing one term also threw away the record of every search behind it
-- (including the ones that did hit).
--
-- dismissed is that flag: the term stops being offered, the rows stay, and
-- the trends keep their history. The pending list is also filtered on
-- found = 0 (a search that hit is not a miss) and on terms that are not
-- already a category (the site does not need to be told it has one).
ALTER TABLE search_stats
    ADD COLUMN dismissed TINYINT(1) NOT NULL DEFAULT 0 AFTER found;

CREATE INDEX idx_searchstats_found_dismissed ON search_stats (found, dismissed, term);
