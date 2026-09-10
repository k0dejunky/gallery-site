-- Unique-IP page-visit tracking for the admin dashboard's view-trends
-- section. One row per form page + IP + calendar day; each render of the
-- login or signup page INSERT-IGNOREs a row, so an IP is only counted once
-- per page per day. Full-window queries use COUNT(DISTINCT ip), keeping the
-- unique-visitor numbers accurate for any period selector (day..all time).
CREATE TABLE IF NOT EXISTS page_ip_visits (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page       VARCHAR(16) NOT NULL,
    ip         VARCHAR(45) NOT NULL,
    visit_date DATE NOT NULL,
    UNIQUE KEY uq_page_ip_visits_page_ip_date (page, ip, visit_date),
    INDEX idx_page_ip_visits_date (visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;