-- Queue for auto-posting: holds recommended X/Reddit posts generated from
-- recent uploads, ready for the admin to review, queue, or dismiss.
-- One row per queued post (linked to a photo for media and context).
CREATE TABLE IF NOT EXISTS auto_poster_queue (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform    VARCHAR(20) NOT NULL DEFAULT 'twitter',
    photo_id    INT UNSIGNED NULL,
    gallery_id  INT UNSIGNED NULL,
    text        VARCHAR(280) NOT NULL,
    status      ENUM('queued','posted','failed','dismissed') NOT NULL DEFAULT 'queued',
    post_url    VARCHAR(500) NULL,
    error       VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    posted_at   DATETIME NULL,
    INDEX idx_apq_status (status),
    INDEX idx_apq_photo (photo_id),
    INDEX idx_apq_created (created_at),
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
