-- Auto posts become per-gallery recommendations with 1-4 attached media
-- files (media_ids) and a scheduled publish date/time (scheduled_at). The
-- autopost cron worker publishes rows once scheduled_at passes.
ALTER TABLE auto_poster_queue
    ADD COLUMN media_ids VARCHAR(400) NULL AFTER photo_id,
    ADD COLUMN scheduled_at DATETIME NULL AFTER created_at,
    ADD INDEX idx_apq_scheduled (status, scheduled_at);