-- The searches that found something.
--
-- search_stats only held the searches that found NOTHING (the missed ones),
-- so the site could not tell what the members are actually looking for. The
-- same table now records every search with a "found" flag, and the member
-- facing "popular searches" comes from the ones that hit.
ALTER TABLE search_stats
    ADD COLUMN found TINYINT(1) NOT NULL DEFAULT 0 AFTER term,
    ADD INDEX idx_searchstats_found_term (found, term);
