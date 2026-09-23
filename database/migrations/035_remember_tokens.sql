-- Persistent "remember me" logins: a random selector:validator pair stored
-- in a long-lived secure cookie. Only the SHA-256 hash of the validator is
-- stored, so a leaked DB cannot mint valid sessions. Rotated on each use and
-- invalidated on logout / password change / log-out-everywhere.
CREATE TABLE IF NOT EXISTS remember_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    selector    CHAR(32) NOT NULL UNIQUE,
    validator_hash CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX idx_remember_tokens_user (user_id),
    CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;