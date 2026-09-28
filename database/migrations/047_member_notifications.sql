-- Member notifications (default ON, opt-out per type): email members when a
-- new gallery goes live and when a live show starts. The gallery/live flags
-- gate the two notification types; marketing_opt_out remains the global kill
-- switch. notified_at / live_notified_at make each notification idempotent so
-- it is sent exactly once per gallery / live session.
ALTER TABLE users
  ADD COLUMN notify_new_gallery TINYINT(1) NOT NULL DEFAULT 1 AFTER marketing_opt_out,
  ADD COLUMN notify_live        TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_new_gallery;
ALTER TABLE galleries ADD COLUMN notified_at DATETIME NULL AFTER published_at;
ALTER TABLE live_sessions ADD COLUMN live_notified_at DATETIME NULL AFTER started_at;
ALTER TABLE email_queue MODIFY COLUMN audience ENUM('subscriber','non_subscriber','notification') NOT NULL;