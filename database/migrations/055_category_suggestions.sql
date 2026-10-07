-- 055 AI category suggestions.
-- A vision backend (Ollama now, an external API later) reviews a gallery's
-- media and proposes categories; admins accept or dismiss each proposal on
-- the manage page or the /admin/category-suggestions review page. Nothing is
-- ever applied without an explicit accept.
--
-- Two tables: an analysis row is the per-gallery queue/error surface (a
-- failed run has no category to attach to, so it cannot live in the
-- suggestions table), and suggestion rows are the actual proposals.

CREATE TABLE IF NOT EXISTS gallery_category_jobs (
    gallery_id  INT UNSIGNED PRIMARY KEY,
    status      VARCHAR(12) NOT NULL DEFAULT 'queued',
    engine      VARCHAR(16) NOT NULL DEFAULT '',
    error       TEXT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cat_jobs_status (status, updated_at),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS gallery_category_suggestions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gallery_id  INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    confidence  DECIMAL(5,4) NULL DEFAULT NULL,
    status      VARCHAR(12)  NOT NULL DEFAULT 'pending',
    engine      VARCHAR(16)  NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at  DATETIME NULL,
    decided_by  INT UNSIGNED NULL,
    UNIQUE KEY uq_suggestion_gallery_category (gallery_id, category_id),
    INDEX idx_suggestions_status (status),
    INDEX idx_suggestions_gallery (gallery_id),
    FOREIGN KEY (gallery_id)  REFERENCES galleries(id)  ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by)  REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
