-- 096 Funnel events.
--
-- Usability work needs numbers: which searches find nothing, how often the
-- paywall is seen versus clicked, how many videos are started and finished,
-- and whether a new chat message gets an answer. The analytics tables count
-- TRAFFIC (hits, visits, IPs); they cannot answer any of those questions.
--
-- One row per event, deliberately small and append-only. No foreign keys: an
-- event must survive the deletion of the member it came from (a deleted
-- account does not un-happen a paywall impression), and user_id is nullable
-- for guests.
CREATE TABLE IF NOT EXISTS funnel_events (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(40) NOT NULL,
    user_id    INT UNSIGNED NULL DEFAULT NULL,
    meta       VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_funnel_name_date (name, created_at),
    INDEX idx_funnel_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
