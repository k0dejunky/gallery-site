-- Traffic links & attribution: admin-generated custom links (short ?c= code,
-- optionally carrying utm_source/medium/campaign/content/term) record where
-- visitors come from and which source signed them up. traffic_visits dedupes
-- per link/day/visitor; users keeps the credited source (SET NULL on link
-- delete) so the reports can join signups back to campaigns.

CREATE TABLE IF NOT EXISTS traffic_links (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(64)  NOT NULL UNIQUE,
    name        VARCHAR(120) NOT NULL,
    target_path VARCHAR(255) NOT NULL DEFAULT '/signup',
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_traffic_links_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS traffic_visits (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id     INT UNSIGNED NOT NULL,
    visitor_id  CHAR(32)     NOT NULL,
    ref_date    DATE         NOT NULL,
    ip          VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    landed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_traffic_visits (link_id, ref_date, visitor_id),
    INDEX idx_traffic_visits_date (ref_date, link_id),
    CONSTRAINT fk_traffic_visits_link FOREIGN KEY (link_id)
        REFERENCES traffic_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE users
    ADD COLUMN signup_source_link_id INT UNSIGNED NULL AFTER marketing_opt_out,
    ADD COLUMN utm_source   VARCHAR(120) NULL AFTER signup_source_link_id,
    ADD COLUMN utm_medium   VARCHAR(120) NULL AFTER utm_source,
    ADD COLUMN utm_campaign VARCHAR(120) NULL AFTER utm_medium,
    ADD COLUMN utm_content  VARCHAR(120) NULL AFTER utm_campaign,
    ADD COLUMN utm_term     VARCHAR(120) NULL AFTER utm_content,
    ADD CONSTRAINT fk_users_traffic_link FOREIGN KEY (signup_source_link_id)
        REFERENCES traffic_links(id) ON DELETE SET NULL;