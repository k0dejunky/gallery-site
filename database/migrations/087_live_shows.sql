-- 087 Scheduled live shows.
--
-- The operator can schedule a live show from the app; the member live page
-- then shows a countdown banner until it starts. The schedule is a first
-- class table (rather than a column on live_sessions) because a show is
-- scheduled BEFORE any session exists - a session is the broadcast itself.
--
-- starts_at is stored in UTC. The boxes disagree about their MySQL session
-- timezone (the dev box runs 6 hours behind UTC, the production box is
-- UTC), so a DATETIME compared with NOW() would fire at the wrong moment
-- on one of them. Every comparison therefore uses UTC_TIMESTAMP().
CREATE TABLE IF NOT EXISTS live_shows (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(160) NOT NULL,
    description VARCHAR(1000) NULL,
    -- When the show starts, in UTC.
    starts_at   DATETIME NOT NULL,
    -- How long the show is expected to run, in minutes. Informational: it
    -- drives the countdown on the member page and the operator's own
    -- schedule list, it does not end the broadcast.
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    status      ENUM('scheduled','started','ended','cancelled') NOT NULL DEFAULT 'scheduled',
    created_by  INT UNSIGNED NOT NULL,
    -- When the operator actually went live for this show, if they did.
    started_at  DATETIME NULL,
    ended_at    DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_live_shows_status_start (status, starts_at),
    INDEX idx_live_shows_creator (created_by),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
