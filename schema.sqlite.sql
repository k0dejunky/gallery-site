-- SQLite schema (run with: sqlite3 storage/gallery.sqlite < schema.sqlite.sql)
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    theme_preset  VARCHAR(120),
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS galleries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    type         VARCHAR(10) NOT NULL DEFAULT 'images' CHECK (type IN ('images', 'videos')),
    views        INTEGER NOT NULL DEFAULT 0,
    unique_views INTEGER NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at   DATETIME
);

CREATE TABLE IF NOT EXISTS photos (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    filename     VARCHAR(255) NOT NULL,
    hash         CHAR(40) NOT NULL UNIQUE,
    caption      VARCHAR(255) NOT NULL DEFAULT '',
    link         VARCHAR(500) NOT NULL DEFAULT '',
    views        INTEGER NOT NULL DEFAULT 0,
    unique_views INTEGER NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gallery_photo (
    gallery_id INTEGER NOT NULL,
    photo_id   INTEGER NOT NULL,
    position   INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (gallery_id, photo_id),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
    FOREIGN KEY (photo_id)   REFERENCES photos(id)   ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    email        VARCHAR(255) NOT NULL,
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_email_ip_time ON login_attempts (email, ip, attempted_at);

CREATE TABLE IF NOT EXISTS categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       VARCHAR(100) NOT NULL UNIQUE,
    slug       VARCHAR(120) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gallery_category (
    gallery_id  INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    PRIMARY KEY (gallery_id, category_id),
    FOREIGN KEY (gallery_id)  REFERENCES galleries(id)  ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_favorite_categories (
    user_id     INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, category_id),
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS gallery_viewers (
    user_id    INTEGER NOT NULL,
    gallery_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, gallery_id),
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS photo_viewers (
    user_id  INTEGER NOT NULL,
    photo_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, photo_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admin_logs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER,
    action         VARCHAR(40) NOT NULL,
    entity_type    VARCHAR(40) NOT NULL,
    entity_id      INTEGER,
    description    VARCHAR(500) NOT NULL,
    before_json    TEXT,
    after_json     TEXT,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rolled_back_at DATETIME,
    rollback_by    INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rollback_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS category_views (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    user_id     INTEGER,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_catviews_category_created ON category_views (category_id, created_at);

CREATE TABLE IF NOT EXISTS search_stats (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    term       VARCHAR(255) NOT NULL,
    user_id    INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_searchstats_term_created ON search_stats (term, created_at);

CREATE TABLE IF NOT EXISTS plans (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          VARCHAR(100) NOT NULL,
    slug          VARCHAR(120) NOT NULL UNIQUE,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly'
                  CHECK (billing_cycle IN ('monthly', 'yearly', 'lifetime')),
    description   VARCHAR(500) NOT NULL DEFAULT '',
    sort_order    INTEGER NOT NULL DEFAULT 0,
    level         INTEGER NOT NULL DEFAULT 1,
    active        INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    plan_id    INTEGER NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending', 'active', 'cancelled', 'expired')),
    starts_at  DATETIME,
    expires_at DATETIME,
    sale_id    INTEGER,
    sale_code_id INTEGER,
    price_paid DECIMAL(10,2),
    access_level INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sales (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    plan_id           INTEGER NOT NULL,
    name              VARCHAR(120) NOT NULL,
    sale_price        DECIMAL(10,2) NOT NULL,
    max_subscriptions INTEGER,
    ends_at           DATETIME,
    active            INTEGER NOT NULL DEFAULT 1,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sale_codes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id     INTEGER,
    name        VARCHAR(120),
    code        VARCHAR(120) NOT NULL UNIQUE,
    max_uses    INTEGER,
    used_count  INTEGER NOT NULL DEFAULT 0,
    active      INTEGER NOT NULL DEFAULT 1,
    discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    upgrade_level INTEGER,
    target_level INTEGER NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS video_projects (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    source_photo_id INTEGER NOT NULL,
    user_id         INTEGER NOT NULL,
    title           VARCHAR(180) NOT NULL,
    project_json    TEXT NOT NULL,
    version         INTEGER NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (source_photo_id, user_id),
    FOREIGN KEY (source_photo_id) REFERENCES photos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS video_export_jobs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id   INTEGER NOT NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'queued',
    progress     INTEGER NOT NULL DEFAULT 0,
    output_file  VARCHAR(255),
    error        TEXT,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at   DATETIME,
    finished_at  DATETIME,
    FOREIGN KEY (project_id) REFERENCES video_projects(id) ON DELETE CASCADE
);

-- Auto poster posting history.
CREATE TABLE IF NOT EXISTS auto_poster_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    platform   VARCHAR(20) NOT NULL,
    target     VARCHAR(255) NOT NULL DEFAULT '',
    status     VARCHAR(20) NOT NULL,
    message    TEXT,
    user_id    INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO plans (name, slug, price, billing_cycle, description, sort_order, level, active) VALUES
    ('Silver', 'silver', 5.00, 'monthly', 'Full access for one month.', 1, 1, 1),
    ('Gold', 'gold', 10.00, 'monthly', 'Full access for one month.', 2, 2, 1),
    ('Platinum', 'platinum', 20.00, 'monthly', 'Full access for one month.', 3, 3, 1),
    ('OnlyFans', 'onlyfans', 25.00, 'monthly', 'Full access for one month.', 4, 4, 1),
    ('Monthly', 'monthly', 9.99, 'monthly', 'Full access for one month.', 5, 1, 1),
    ('Yearly', 'yearly', 99.99, 'yearly', 'Full access for one year.', 6, 1, 1),
    ('Lifetime', 'lifetime', 249.99, 'lifetime', 'Full access forever.', 7, 1, 1);

INSERT OR IGNORE INTO users (email, password_hash, role)
VALUES ('admin@example.com', '$2y$10$uNmLZcHOdbU1ClIdYBshduRC5MV6kNjkvhr20NZaWDRbyLFI4kX0m', 'admin');
