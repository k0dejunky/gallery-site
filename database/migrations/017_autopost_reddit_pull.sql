-- Reddit pull-mode bridge: the Devvit polling app claims a queued reddit row
-- before it posts, so two installed app instances (or a crashed poll cycle)
-- can never publish the same row twice. picked_by holds the claim token the
-- bridge echoes back in its report so only the claimant can settle the row.
ALTER TABLE auto_poster_queue
    ADD COLUMN picked_at DATETIME NULL AFTER error,
    ADD COLUMN picked_by VARCHAR(120) NULL AFTER picked_at,
    ADD INDEX idx_apq_reddit_claim (platform, status, picked_at);