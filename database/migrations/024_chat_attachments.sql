-- Chat attachments: operator replies (and member messages) can carry a file
-- (image / video / text). The file is stored under storage/uploads/chat/ and
-- the message row references it so the app and web can render a link/thumbnail.
ALTER TABLE chat_messages
    ADD COLUMN attachment_name VARCHAR(255) NULL AFTER message,
    ADD COLUMN attachment_type VARCHAR(60) NULL AFTER attachment_name,
    ADD COLUMN attachment_path VARCHAR(255) NULL AFTER attachment_type;