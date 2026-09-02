-- Auto-post queue gains a 'skipped' status for queue items whose target
-- platform has no authorized API connection. The autopost cron skips these
-- rows (recorded, never attempted) instead of failing them every tick.
ALTER TABLE auto_poster_queue
    MODIFY COLUMN status ENUM('queued','posted','failed','dismissed','skipped') NOT NULL DEFAULT 'queued';