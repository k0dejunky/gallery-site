-- Expiring chat media: an operator-sent picture/video that disappears after a
-- chosen time or after a chosen number of views. Applied only to messages with
-- an attachment; the serve endpoints enforce it and a housekeeping sweep
-- deletes the stored files once expired.
ALTER TABLE chat_messages
    ADD COLUMN expires_at DATETIME NULL AFTER attachment_path,
    ADD COLUMN max_views  INT UNSIGNED NULL,
    ADD COLUMN view_count INT UNSIGNED NOT NULL DEFAULT 0;