-- SQLite schema (run with: sqlite3 storage/gallery.sqlite < schema.sqlite.sql)
-- Faithful SQLite port of schema.sql (incl. migration net state 011-033).
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('super_admin', 'admin', 'editor', 'moderator', 'viewer', 'user')),
    status        VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    session_version INTEGER NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME,
    last_seen_at  DATETIME,
    date_of_birth DATE,
    age_verified  INTEGER NOT NULL DEFAULT 0,
    age_verified_at DATETIME,
    email_verified_at DATETIME,
    email_verification_token CHAR(64),
    recovery_email_sent_at DATETIME,
    marketing_opt_out INTEGER NOT NULL DEFAULT 0,
    signup_source_link_id INTEGER,
    utm_source   VARCHAR(120),
    utm_medium   VARCHAR(120),
    utm_campaign VARCHAR(120),
    utm_content  VARCHAR(120),
    utm_term     VARCHAR(120),
    billing_first_name VARCHAR(100),
    billing_last_name  VARCHAR(100),
    billing_address_line1 VARCHAR(255),
    billing_address_line2 VARCHAR(255),
    billing_city   VARCHAR(100),
    billing_state  VARCHAR(50),
    billing_zip    VARCHAR(20),
    billing_country VARCHAR(2),
    payment_customer_id VARCHAR(255),
    card_last_four CHAR(4),
    card_brand     VARCHAR(20),
    card_exp_month INTEGER,
    card_exp_year  INTEGER,
    flag           VARCHAR(32),
    theme_preset   VARCHAR(120),
    totp_secret    CHAR(64),
    totp_enabled   INTEGER NOT NULL DEFAULT 0,
    totp_verified_at DATETIME
);

CREATE INDEX IF NOT EXISTS idx_users_email_verification_token ON users (email_verification_token);

CREATE TABLE IF NOT EXISTS support_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, email VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, message TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'read', 'postponed', 'resolved', 'ignored')), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, user_read_at DATETIME, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL);
CREATE INDEX IF NOT EXISTS idx_support_messages_user ON support_messages (user_id);
CREATE INDEX IF NOT EXISTS idx_support_messages_status ON support_messages (status);

CREATE TABLE IF NOT EXISTS support_replies (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, user_id INTEGER, author_role VARCHAR(10) NOT NULL CHECK (author_role IN ('user', 'admin')), message TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (ticket_id) REFERENCES support_messages(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL);
CREATE INDEX IF NOT EXISTS idx_support_replies_ticket_date ON support_replies (ticket_id, created_at);

CREATE TABLE IF NOT EXISTS saved_searches (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    query      VARCHAR(255) NOT NULL,
    type       VARCHAR(10) NOT NULL DEFAULT '',
    sort       VARCHAR(20) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, query, type, sort),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_saved_searches_user_created ON saved_searches (user_id, created_at);

CREATE TABLE IF NOT EXISTS galleries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    type         VARCHAR(10) NOT NULL DEFAULT 'images' CHECK (type IN ('images', 'videos')),
    min_level    INTEGER NOT NULL DEFAULT 0,
    is_secret    INTEGER NOT NULL DEFAULT 0,
    published_at DATETIME,
    views        INTEGER NOT NULL DEFAULT 0,
    unique_views INTEGER NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at   DATETIME
);
CREATE INDEX IF NOT EXISTS idx_galleries_listing ON galleries (deleted_at, published_at, created_at);
CREATE INDEX IF NOT EXISTS idx_galleries_secret ON galleries (is_secret);

CREATE TABLE IF NOT EXISTS gallery_user_access (
    gallery_id INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (gallery_id, user_id),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_gallery_user_access_user ON gallery_user_access (user_id);

CREATE TABLE IF NOT EXISTS photos (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    filename     VARCHAR(255) NOT NULL,
    is_video     INTEGER NOT NULL DEFAULT 0,
    hash         CHAR(40) NOT NULL UNIQUE,
    caption      VARCHAR(255) NOT NULL DEFAULT '',
    link         VARCHAR(500) NOT NULL DEFAULT '',
    views        INTEGER NOT NULL DEFAULT 0,
    unique_views INTEGER NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_photos_media_created ON photos (is_video, created_at);

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
CREATE INDEX IF NOT EXISTS idx_login_attempts_at ON login_attempts (attempted_at);

CREATE TABLE IF NOT EXISTS user_notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    author_id  INTEGER,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_user_notes_user ON user_notes (user_id);

CREATE TABLE IF NOT EXISTS storage_snapshots (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    captured_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uploads_bytes INTEGER NOT NULL DEFAULT 0,
    photos_count  INTEGER NOT NULL DEFAULT 0,
    video_count   INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_storage_snapshots_at ON storage_snapshots (captured_at);

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
CREATE INDEX IF NOT EXISTS idx_gallery_category_category ON gallery_category (category_id);

CREATE TABLE IF NOT EXISTS user_favorite_categories (
    user_id     INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, category_id),
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS gallery_favorites (
    user_id    INTEGER NOT NULL,
    gallery_id INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, gallery_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS gallery_viewers (
    user_id    INTEGER NOT NULL,
    gallery_id INTEGER NOT NULL,
    viewed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
CREATE INDEX IF NOT EXISTS idx_admin_logs_created ON admin_logs (created_at);
CREATE INDEX IF NOT EXISTS idx_admin_logs_entity ON admin_logs (entity_type, entity_id);

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
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    can_view_galleries INTEGER NOT NULL DEFAULT 1,
    can_favorite       INTEGER NOT NULL DEFAULT 0,
    can_upload         INTEGER NOT NULL DEFAULT 0,
    can_custom_theme   INTEGER NOT NULL DEFAULT 0,
    can_download       INTEGER NOT NULL DEFAULT 0,
    can_comment        INTEGER NOT NULL DEFAULT 0,
    can_comment_guest  INTEGER NOT NULL DEFAULT 0,
    can_chat           INTEGER NOT NULL DEFAULT 0,
    max_upload_size_mb INTEGER NOT NULL DEFAULT 100,
    max_favorites      INTEGER NOT NULL DEFAULT 10
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    membership_number TEXT UNIQUE,
    user_id    INTEGER NOT NULL,
    plan_id    INTEGER NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending', 'active', 'cancelled', 'expired', 'past_due')),
    starts_at  DATETIME,
    expires_at DATETIME,
    sale_id    INTEGER,
    sale_code_id INTEGER,
    price_paid DECIMAL(10,2),
    access_level INTEGER,
    payment_processor_id INTEGER,
    transaction_ref VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_processor_id) REFERENCES payment_processors(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_subscriptions_user ON subscriptions (user_id);
CREATE INDEX IF NOT EXISTS idx_subscriptions_status ON subscriptions (status);

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

CREATE TABLE IF NOT EXISTS payment_processors (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    provider       VARCHAR(40) NOT NULL,
    name           VARCHAR(120) NOT NULL,
    mode           VARCHAR(10) NOT NULL DEFAULT 'test',
    api_key        TEXT,
    secret_key     TEXT,
    webhook_secret TEXT,
    config_json    TEXT,
    currency       VARCHAR(8) NOT NULL DEFAULT 'USD',
    is_default     INTEGER NOT NULL DEFAULT 0,
    enabled        INTEGER NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
    metadata_json TEXT,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at   DATETIME,
    finished_at  DATETIME,
    attempts     INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (project_id) REFERENCES video_projects(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_video_export_status ON video_export_jobs (status);

CREATE TABLE IF NOT EXISTS photo_edit_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    gallery_id    INTEGER NOT NULL,
    operation     VARCHAR(20) NOT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'queued',
    progress      INTEGER NOT NULL DEFAULT 0,
    total         INTEGER NOT NULL DEFAULT 0,
    done          INTEGER NOT NULL DEFAULT 0,
    failed        INTEGER NOT NULL DEFAULT 0,
    error         TEXT,
    metadata_json TEXT,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at    DATETIME,
    finished_at   DATETIME,
    attempts      INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_photo_edit_status ON photo_edit_jobs (status);
CREATE INDEX IF NOT EXISTS idx_photo_edit_gallery ON photo_edit_jobs (gallery_id);

CREATE TABLE IF NOT EXISTS site_templates (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        VARCHAR(255) NOT NULL,
    description TEXT,
    scope       VARCHAR(10) NOT NULL DEFAULT 'user' CHECK (scope IN ('user', 'admin')),
    config_json TEXT NOT NULL,
    is_active   INTEGER NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_site_templates_scope ON site_templates (scope);

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
CREATE INDEX IF NOT EXISTS idx_auto_poster_platform ON auto_poster_log (platform);
CREATE INDEX IF NOT EXISTS idx_auto_poster_created ON auto_poster_log (created_at);

CREATE TABLE IF NOT EXISTS autoposter_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS auto_poster_queue (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    platform     VARCHAR(20) NOT NULL DEFAULT 'twitter',
    photo_id     INTEGER,
    gallery_id   INTEGER,
    media_ids    VARCHAR(400),
    text         VARCHAR(280) NOT NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','posted','failed','dismissed','skipped')),
    post_url     VARCHAR(500),
    error        VARCHAR(500),
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scheduled_at DATETIME,
    posted_at    DATETIME,
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_apq_status ON auto_poster_queue (status);
CREATE INDEX IF NOT EXISTS idx_apq_photo ON auto_poster_queue (photo_id);
CREATE INDEX IF NOT EXISTS idx_apq_created ON auto_poster_queue (created_at);
CREATE INDEX IF NOT EXISTS idx_apq_scheduled ON auto_poster_queue (status, scheduled_at);

CREATE TABLE IF NOT EXISTS email_queue (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    audience   VARCHAR(20) NOT NULL CHECK (audience IN ('subscriber','non_subscriber')),
    user_id    INTEGER,
    email      VARCHAR(255) NOT NULL,
    subject    VARCHAR(255) NOT NULL,
    html_body  TEXT NOT NULL,
    text_body  TEXT,
    status     VARCHAR(10) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','sent','failed')),
    attempts   INTEGER NOT NULL DEFAULT 0,
    error      VARCHAR(500),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at    DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_email_queue_status ON email_queue (status);
CREATE INDEX IF NOT EXISTS idx_email_queue_audience ON email_queue (audience);

CREATE TABLE IF NOT EXISTS content_views (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type VARCHAR(10) NOT NULL CHECK (entity_type IN ('gallery', 'photo')),
    entity_id   INTEGER NOT NULL,
    view_date   DATE NOT NULL,
    count       INTEGER NOT NULL DEFAULT 0,
    UNIQUE (entity_type, entity_id, view_date)
);
CREATE INDEX IF NOT EXISTS idx_content_views_date ON content_views (view_date);

CREATE TABLE IF NOT EXISTS page_ip_visits (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    page       VARCHAR(16) NOT NULL,
    ip         VARCHAR(45) NOT NULL,
    visit_date DATE NOT NULL,
    UNIQUE (page, ip, visit_date)
);
CREATE INDEX IF NOT EXISTS idx_page_ip_visits_date ON page_ip_visits (visit_date);

CREATE TABLE IF NOT EXISTS traffic_links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        VARCHAR(64)  NOT NULL UNIQUE,
    name        VARCHAR(120) NOT NULL,
    target_path VARCHAR(255) NOT NULL DEFAULT '/signup',
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_traffic_links_active ON traffic_links (active);

CREATE TABLE IF NOT EXISTS traffic_visits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id     INTEGER NOT NULL,
    visitor_id  CHAR(32)     NOT NULL,
    ref_date    DATE         NOT NULL,
    ip          VARCHAR(45),
    user_agent  VARCHAR(255),
    landed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (link_id, ref_date, visitor_id),
    FOREIGN KEY (link_id) REFERENCES traffic_links(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_traffic_visits_date ON traffic_visits (ref_date, link_id);

CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_token ON password_resets (token);
CREATE INDEX IF NOT EXISTS idx_email ON password_resets (email);

-- User activity monitor: per-member login / logout / gallery-view events
-- powering the admin User Monitor tab (see UserActivity model).
CREATE TABLE IF NOT EXISTS user_activity (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    action       VARCHAR(20) NOT NULL,
    gallery_id   INTEGER,
    gallery_name VARCHAR(255),
    ip           VARCHAR(45),
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_ua_user ON user_activity (user_id);
CREATE INDEX IF NOT EXISTS idx_ua_action ON user_activity (action);
CREATE INDEX IF NOT EXISTS idx_ua_created ON user_activity (created_at);

-- Chat: members chat with a self-hosted AI model (or a human operator via the
-- Android app). Eligibility is gated by a per-plan can_chat flag.
CREATE TABLE IF NOT EXISTS chat_conversations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    ai_mode     VARCHAR(20) NOT NULL DEFAULT 'retrieval' CHECK (ai_mode IN ('retrieval','finetuned','operator')),
    status      VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
    member_reply_enabled INTEGER NOT NULL DEFAULT 1,
    last_read_message_id INTEGER NOT NULL DEFAULT 0,
    operator_read_through_id INTEGER NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_chat_conv_user ON chat_conversations (user_id);
CREATE INDEX IF NOT EXISTS idx_chat_conv_status ON chat_conversations (status);

CREATE TABLE IF NOT EXISTS chat_settings (
    setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS live_sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    stream_key  VARCHAR(64) NOT NULL,
    created_by  INTEGER NOT NULL,
    status      VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','live','ended')),
    started_at  DATETIME,
    ended_at    DATETIME,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_live_status ON live_sessions (status);
CREATE INDEX IF NOT EXISTS idx_live_key ON live_sessions (stream_key);

CREATE TABLE IF NOT EXISTS chat_messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL,
    sender_role     VARCHAR(10) NOT NULL CHECK (sender_role IN ('user','model','operator')),
    message         TEXT NOT NULL,
    attachment_name VARCHAR(255),
    attachment_type VARCHAR(60),
    attachment_path VARCHAR(255),
    content_refs    TEXT,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_chat_msg_conv_date ON chat_messages (conversation_id, created_at);

CREATE TABLE IF NOT EXISTS chat_training_pairs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_message  TEXT NOT NULL,
    operator_reply TEXT NOT NULL,
    cleaned       INTEGER NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS chat_reply_idempotency (
    idempotency_key VARCHAR(128) NOT NULL PRIMARY KEY,
    conversation_id INTEGER NOT NULL,
    message_id INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_chat_reply_idempotency_conversation ON chat_reply_idempotency (conversation_id);

CREATE TABLE IF NOT EXISTS chat_daily_broadcasts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    message     TEXT NOT NULL,
    scheduled_at DATETIME,
    status      VARCHAR(20) NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled','sending','sent','partial','failed','cancelled')),
    recipients  INTEGER NOT NULL DEFAULT 0,
    sent_count  INTEGER NOT NULL DEFAULT 0,
    error       VARCHAR(500),
    created_by  INTEGER,
    sent_at     DATETIME,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_chat_daily_broadcasts_status ON chat_daily_broadcasts (status, scheduled_at);

CREATE TABLE IF NOT EXISTS operator_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    label       VARCHAR(120) NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    scopes      VARCHAR(255) NOT NULL DEFAULT 'chat',
    expires_at  DATETIME,
    revoked     INTEGER NOT NULL DEFAULT 0,
    last_used_at DATETIME,
    created_by  INTEGER,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_operator_tokens_revoked ON operator_tokens (revoked);

INSERT OR IGNORE INTO plans (name, slug, price, billing_cycle, description, sort_order, level, active) VALUES
    ('Silver', 'silver', 5.00, 'monthly', 'Full access for one month.', 1, 1, 1),
    ('Gold', 'gold', 10.00, 'monthly', 'Full access for one month.', 2, 2, 1),
    ('Platinum', 'platinum', 20.00, 'monthly', 'Full access for one month.', 3, 3, 1),
    ('Monthly', 'monthly', 9.99, 'monthly', 'Full access for one month.', 5, 1, 1),
    ('Yearly', 'yearly', 99.99, 'yearly', 'Full access for one year.', 6, 1, 1),
    ('Lifetime', 'lifetime', 249.99, 'lifetime', 'Full access forever.', 7, 1, 1);

-- No default admin user is seeded (see schema.sql note): install.sh creates
-- the initial admin with a fresh bcrypt password.