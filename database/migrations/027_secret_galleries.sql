-- Secret galleries are visible only to explicitly allowed users. Super admins
-- manage them from the admin area; membership level does not grant access.
ALTER TABLE galleries
    ADD COLUMN is_secret TINYINT(1) NOT NULL DEFAULT 0 AFTER min_level,
    ADD INDEX idx_galleries_secret (is_secret);

CREATE TABLE gallery_user_access (
    gallery_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (gallery_id, user_id),
    INDEX idx_gallery_user_access_user (user_id),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
