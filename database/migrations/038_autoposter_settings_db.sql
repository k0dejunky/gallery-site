-- Auto Poster credentials + per-platform templates, migrated from
-- storage/autoposter.json into a key/value table (Reddit/X OAuth tokens and
-- post templates). Existing installs are seeded lazily by
-- AutoPosterConfig::all() when the table is empty and the legacy file exists.
CREATE TABLE IF NOT EXISTS autoposter_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT   NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;