-- Per-device operator tokens: replace the single shared GALLERY_CHAT_KEY with
-- scoped, revocable, expiring device tokens for the operator app. The bridge
-- accepts a valid device token OR the legacy shared key during migration. Only
-- the SHA-256 hash is stored, so a leaked DB cannot expose usable tokens.
CREATE TABLE IF NOT EXISTS operator_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(120) NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    scopes      VARCHAR(255) NOT NULL DEFAULT 'chat',
    expires_at  DATETIME NULL,
    revoked     TINYINT(1) NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_operator_tokens_revoked (revoked),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;