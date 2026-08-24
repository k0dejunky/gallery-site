CREATE DATABASE IF NOT EXISTS gallery_mvc;
USE gallery_mvc;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('super_admin', 'admin', 'editor', 'moderator', 'viewer', 'user') NOT NULL DEFAULT 'user',
    status        ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    session_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL DEFAULT NULL,
    date_of_birth DATE NULL DEFAULT NULL,
    age_verified  TINYINT(1) NOT NULL DEFAULT 0,
    age_verified_at DATETIME NULL DEFAULT NULL,
    billing_first_name VARCHAR(100) NULL DEFAULT NULL,
    billing_last_name  VARCHAR(100) NULL DEFAULT NULL,
    billing_address_line1 VARCHAR(255) NULL DEFAULT NULL,
    billing_address_line2 VARCHAR(255) NULL DEFAULT NULL,
    billing_city   VARCHAR(100) NULL DEFAULT NULL,
    billing_state  VARCHAR(50) NULL DEFAULT NULL,
    billing_zip    VARCHAR(20) NULL DEFAULT NULL,
    billing_country VARCHAR(2) NULL DEFAULT NULL,
    payment_customer_id VARCHAR(255) NULL DEFAULT NULL,
    card_last_four CHAR(4) NULL DEFAULT NULL,
    card_brand     VARCHAR(20) NULL DEFAULT NULL,
    card_exp_month TINYINT NULL DEFAULT NULL,
    card_exp_year  SMALLINT NULL DEFAULT NULL,
    flag          VARCHAR(32) NULL DEFAULT NULL,
    theme_preset  VARCHAR(120) NULL
);

CREATE TABLE IF NOT EXISTS galleries (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    type         VARCHAR(10) NOT NULL DEFAULT 'images',
    min_level    INT UNSIGNED NOT NULL DEFAULT 0,
    views        INT UNSIGNED NOT NULL DEFAULT 0,
    unique_views INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at   DATETIME NULL,
    FULLTEXT KEY ft_search (title, description)
);

CREATE TABLE IF NOT EXISTS photos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename     VARCHAR(255) NOT NULL,
    is_video     TINYINT(1) NOT NULL DEFAULT 0,
    hash         CHAR(40) NOT NULL UNIQUE,
    caption      VARCHAR(255) NOT NULL DEFAULT '',
    link         VARCHAR(500) NOT NULL DEFAULT '',
    views        INT UNSIGNED NOT NULL DEFAULT 0,
    unique_views INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gallery_photo (
    gallery_id INT UNSIGNED NOT NULL,
    photo_id   INT UNSIGNED NOT NULL,
    position   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (gallery_id, photo_id),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
    FOREIGN KEY (photo_id)   REFERENCES photos(id)   ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(255) NOT NULL,
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_ip_time (email, ip, attempted_at)
);

CREATE TABLE IF NOT EXISTS user_notes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    author_id  INT UNSIGNED NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_notes_user (user_id)
);

CREATE TABLE IF NOT EXISTS storage_snapshots (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    captured_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uploads_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    photos_count INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_storage_snapshots_at (captured_at)
);


CREATE TABLE IF NOT EXISTS categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL UNIQUE,
    slug       VARCHAR(120) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FULLTEXT KEY ft_name (name)
);

CREATE TABLE IF NOT EXISTS gallery_category (
    gallery_id  INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (gallery_id, category_id),
    FOREIGN KEY (gallery_id)  REFERENCES galleries(id)  ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_favorite_categories (
    user_id     INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, category_id),
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS gallery_viewers (
    user_id    INT UNSIGNED NOT NULL,
    gallery_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, gallery_id),
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS photo_viewers (
    user_id  INT UNSIGNED NOT NULL,
    photo_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, photo_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admin_logs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NULL,
    action        VARCHAR(40) NOT NULL,
    entity_type   VARCHAR(40) NOT NULL,
    entity_id     INT UNSIGNED NULL,
    description   VARCHAR(500) NOT NULL,
    before_json   LONGTEXT NULL,
    after_json    LONGTEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rolled_back_at DATETIME NULL,
    rollback_by   INT UNSIGNED NULL,
    INDEX idx_admin_logs_created (created_at),
    INDEX idx_admin_logs_entity (entity_type, entity_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rollback_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS category_views (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_catviews_category_created (category_id, created_at),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS search_stats (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term       VARCHAR(255) NOT NULL,
    user_id    INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_searchstats_term_created (term, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS plans (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    slug          VARCHAR(120) NOT NULL UNIQUE,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    billing_cycle ENUM('monthly', 'yearly', 'lifetime') NOT NULL DEFAULT 'monthly',
    description   VARCHAR(500) NOT NULL DEFAULT '',
    sort_order    INT NOT NULL DEFAULT 0,
    level         INT NOT NULL DEFAULT 1,
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    can_view_galleries TINYINT(1) NOT NULL DEFAULT 1,
    can_favorite       TINYINT(1) NOT NULL DEFAULT 0,
    can_upload         TINYINT(1) NOT NULL DEFAULT 0,
    can_custom_theme   TINYINT(1) NOT NULL DEFAULT 0,
    can_download       TINYINT(1) NOT NULL DEFAULT 0,
    can_comment        TINYINT(1) NOT NULL DEFAULT 0,
    can_comment_guest  TINYINT(1) NOT NULL DEFAULT 0,
    max_upload_size_mb INT UNSIGNED NOT NULL DEFAULT 100,
    max_favorites      INT UNSIGNED NOT NULL DEFAULT 10
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    plan_id     INT UNSIGNED NOT NULL,
    status      ENUM('pending', 'active', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    starts_at   DATETIME NULL,
    expires_at  DATETIME NULL,
    sale_id     INT UNSIGNED NULL,
    sale_code_id INT UNSIGNED NULL,
    price_paid  DECIMAL(10,2) NULL,
    access_level INT NULL,
    payment_processor_id INT UNSIGNED NULL,
    transaction_ref VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subscriptions_user (user_id),
    INDEX idx_subscriptions_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_processor_id) REFERENCES payment_processors(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sales (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id           INT UNSIGNED NOT NULL,
    name              VARCHAR(120) NOT NULL,
    sale_price        DECIMAL(10,2) NOT NULL,
    max_subscriptions INT UNSIGNED NULL,
    ends_at           DATETIME NULL,
    active            TINYINT(1) NOT NULL DEFAULT 1,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sales_plan_active (plan_id, active),
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sale_codes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id     INT UNSIGNED NULL,
    name        VARCHAR(120) NULL,
    code        VARCHAR(120) NOT NULL UNIQUE,
    max_uses    INT UNSIGNED NULL,
    used_count  INT UNSIGNED NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    upgrade_level INT NULL,
    target_level INT NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS payment_processors (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider      VARCHAR(40) NOT NULL,
    name          VARCHAR(120) NOT NULL,
    mode          VARCHAR(10) NOT NULL DEFAULT 'test',
    api_key       TEXT NULL,
    secret_key    TEXT NULL,
    webhook_secret TEXT NULL,
    config_json   TEXT NULL,
    currency      VARCHAR(8) NOT NULL DEFAULT 'USD',
    is_default    TINYINT(1) NOT NULL DEFAULT 0,
    enabled       TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_processors_enabled (enabled)
);

-- Seed the two standard gateways (disabled until an admin adds keys and
-- enables them, mirroring how production was configured).
INSERT INTO payment_processors (provider, name, mode, currency, is_default, enabled) VALUES
    ('stripe', 'Stripe', 'test', 'USD', 0, 0),
    ('paypal', 'PayPal', 'test', 'USD', 0, 0);

CREATE TABLE IF NOT EXISTS video_projects (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_photo_id  INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    title            VARCHAR(180) NOT NULL,
    project_json     LONGTEXT NOT NULL,
    version          INT UNSIGNED NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_video_project_source_user (source_photo_id, user_id),
    FOREIGN KEY (source_photo_id) REFERENCES photos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS video_export_jobs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id   INT UNSIGNED NOT NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'queued',
    progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    output_file  VARCHAR(255) NULL,
    error        TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at   DATETIME NULL,
    finished_at  DATETIME NULL,
    metadata_json TEXT NULL,
    FOREIGN KEY (project_id) REFERENCES video_projects(id) ON DELETE CASCADE,
    INDEX idx_video_export_status (status)
);

-- Visual site editor templates. Created here so fresh installs include the
-- table; the app seeds default rows at runtime via SiteTemplate::seedDefaults().
CREATE TABLE IF NOT EXISTS site_templates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    description TEXT NULL,
    scope       ENUM('user','admin') NOT NULL DEFAULT 'user',
    config_json MEDIUMTEXT NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_site_templates_scope (scope)
);

-- Auto poster posting history.
CREATE TABLE IF NOT EXISTS auto_poster_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform   VARCHAR(20) NOT NULL,
    target     VARCHAR(255) NOT NULL DEFAULT '',
    status     VARCHAR(20) NOT NULL,
    message    TEXT NULL,
    user_id    INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auto_poster_platform (platform),
    INDEX idx_auto_poster_created (created_at)
);

INSERT INTO plans (name, slug, price, billing_cycle, description, sort_order, level, active) VALUES
    ('Silver', 'silver', 5.00, 'monthly', 'Full access for one month.', 1, 1, 1),
    ('Gold', 'gold', 10.00, 'monthly', 'Full access for one month.', 2, 2, 1),
    ('Platinum', 'platinum', 20.00, 'monthly', 'Full access for one month.', 3, 3, 1),
    ('OnlyFans', 'onlyfans', 25.00, 'monthly', 'Full access for one month.', 4, 4, 1),
    ('Monthly', 'monthly', 9.99, 'monthly', 'Full access for one month.', 5, 1, 1),
    ('Yearly', 'yearly', 99.99, 'yearly', 'Full access for one year.', 6, 1, 1),
    ('Lifetime', 'lifetime', 249.99, 'lifetime', 'Full access forever.', 7, 1, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (email, password_hash, role)
VALUES ('admin@example.com', '$2y$10$uNmLZcHOdbU1ClIdYBshduRC5MV6kNjkvhr20NZaWDRbyLFI4kX0m', 'admin')
ON DUPLICATE KEY UPDATE email = email;
