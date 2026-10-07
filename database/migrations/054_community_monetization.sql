-- 054 Community, discovery & monetisation (Phase 1-3 of the adult-site plan).
-- Adds wall posts, ratings, comments, in-app notifications, one-off purchases
-- + unlock codes, and tags; galleries gain featured + ppv_price.

ALTER TABLE galleries
    ADD COLUMN featured  TINYINT(1)   NOT NULL DEFAULT 0 AFTER unique_views,
    ADD COLUMN ppv_price DECIMAL(10,2) NULL DEFAULT NULL AFTER featured;

-- Wall feed: creator posts that members read + comment on.
CREATE TABLE IF NOT EXISTS wall_posts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    body       TEXT NOT NULL,
    pinned     TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_wall_posts_pin_created (pinned, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Gallery star ratings (1-5). One per user per gallery.
CREATE TABLE IF NOT EXISTS gallery_ratings (
    gallery_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    rating     TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (gallery_id, user_id),
    INDEX idx_gallery_ratings_user (user_id),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Comments on galleries and wall posts (member authored, admin moderated).
CREATE TABLE IF NOT EXISTS comments (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    commentable_type ENUM('gallery','wall_post') NOT NULL,
    commentable_id   INT UNSIGNED NOT NULL,
    body             TEXT NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at       DATETIME NULL,
    INDEX idx_comments_entity (commentable_type, commentable_id),
    INDEX idx_comments_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- In-app notification hub (read state per user).
CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(40) NOT NULL,
    title      VARCHAR(255) NOT NULL,
    body       VARCHAR(255) NULL,
    url        VARCHAR(255) NOT NULL DEFAULT '',
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_read (user_id, read_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One-off gallery unlocks sold off-site via codes the creator generates.
CREATE TABLE IF NOT EXISTS unlock_codes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(120) NOT NULL UNIQUE,
    gallery_id  INT UNSIGNED NULL,
    amount      DECIMAL(10,2) NULL,
    max_uses    INT UNSIGNED NULL,
    used_count  INT UNSIGNED NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One-off ledger: PPV unlocks, tips. Subscriptions stay the main ledger.
CREATE TABLE IF NOT EXISTS purchases (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    item_type   ENUM('gallery','tip') NOT NULL,
    item_id     INT UNSIGNED NOT NULL DEFAULT 0,
    amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency    CHAR(3) NOT NULL DEFAULT 'USD',
    gateway     VARCHAR(40) NOT NULL DEFAULT 'offline',
    gateway_ref VARCHAR(255) NULL,
    note        TEXT NULL,
    status      ENUM('pending','paid','granted','refunded') NOT NULL DEFAULT 'paid',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_purchases_user (user_id, created_at),
    INDEX idx_purchases_item (item_type, item_id),
    INDEX idx_purchases_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Taxonomy tags for galleries.
CREATE TABLE IF NOT EXISTS tags (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(80) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS tag_gallery (
    tag_id     INT UNSIGNED NOT NULL,
    gallery_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (tag_id, gallery_id),
    INDEX idx_tag_gallery_gallery (gallery_id),
    FOREIGN KEY (tag_id)     REFERENCES tags(id)     ON DELETE CASCADE,
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;