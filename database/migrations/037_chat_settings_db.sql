-- Chat site-wide settings, migrated from storage/chat.json into a key/value
-- table so updates (trainer watermark, finetuned metadata) are atomic and
-- survive storage/ rewrites. Existing installs are seeded lazily by
-- ChatSettings::all() when the table is empty and the legacy file is present.
CREATE TABLE IF NOT EXISTS chat_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT   NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;