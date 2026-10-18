-- 067 AI gallery descriptions.
-- The same vision backend that proposes categories (CATEGORIZER_* config)
-- also writes a short marketing description for galleries that have none;
-- admins accept (optionally after editing) or dismiss each proposal on the
-- manage page or /admin/description-suggestions. Nothing is ever applied
-- without an explicit accept.
--
-- Two tables, mirroring 055: gallery_description_jobs is the per-gallery
-- queue/error surface (a failed run has no text to attach to), and
-- gallery_description_suggestions holds the actual proposals (pending rows
-- are once-per-gallery; accepted/dismissed rows are kept as history).

CREATE TABLE IF NOT EXISTS gallery_description_jobs (
    gallery_id  INT UNSIGNED PRIMARY KEY,
    status      VARCHAR(12) NOT NULL DEFAULT 'queued',
    engine      VARCHAR(16) NOT NULL DEFAULT '',
    error       TEXT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_desc_jobs_status (status, updated_at),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS gallery_description_suggestions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gallery_id  INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    status      VARCHAR(12)  NOT NULL DEFAULT 'pending',
    engine      VARCHAR(16)  NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at  DATETIME NULL,
    decided_by  INT UNSIGNED NULL,
    INDEX idx_desc_suggestions_status (status),
    INDEX idx_desc_suggestions_gallery (gallery_id, status),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
