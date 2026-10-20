-- 080 AI category feedback: what the editor did to the proposals.
--
-- The categorizer proposes categories, the editor accepts, dismisses, adds
-- and removes them. Until now only the accept/dismiss survived (as the
-- status of a proposal row), so the AI could never learn from it: a
-- category the editor removed by hand and a category the editor added by
-- hand - the two strongest signals - were thrown away.
--
-- One row per (gallery, category): the latest verdict wins, so accepting a
-- proposal and removing it again later leaves a 'removed' verdict.
--
--   verdict: kept    - the editor kept a category the AI proposed
--           removed  - the editor took a category off the gallery
--           added    - the editor put a category on that the AI never proposed
--   source:  suggestion | manual
--
-- The 'source' column on the suggestions table marks a proposal that came
-- from the editor's vocabulary for similar galleries instead of the model.
CREATE TABLE IF NOT EXISTS gallery_category_feedback (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gallery_id  INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    verdict     VARCHAR(10)  NOT NULL,
    source      VARCHAR(16)  NOT NULL DEFAULT 'suggestion',
    confidence  DECIMAL(5,4) NULL DEFAULT NULL,
    -- How often the editor kept, took off or added this category here. The
    -- counters are what the score is made of: one row per gallery and
    -- category, but every decision counted.
    the_kept    INT UNSIGNED NOT NULL DEFAULT 0,
    the_removed INT UNSIGNED NOT NULL DEFAULT 0,
    the_added   INT UNSIGNED NOT NULL DEFAULT 0,
    decided_by  INT UNSIGNED NULL DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cat_feedback (gallery_id, category_id),
    INDEX idx_cat_feedback_category (category_id),
    INDEX idx_cat_feedback_verdict (verdict),
    CONSTRAINT fk_cat_feedback_gallery  FOREIGN KEY (gallery_id)  REFERENCES galleries(id)  ON DELETE CASCADE,
    CONSTRAINT fk_cat_feedback_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_cat_feedback_user    FOREIGN KEY (decided_by)  REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The counters, for a table that was created before them: a re-run reports
-- "duplicate column name" (MySQL 1060), which scripts/migrate.php tolerates.
ALTER TABLE gallery_category_feedback ADD COLUMN the_kept    INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE gallery_category_feedback ADD COLUMN the_removed INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE gallery_category_feedback ADD COLUMN the_added   INT UNSIGNED NOT NULL DEFAULT 0;

-- A proposal from the editor's vocabulary rather than from the model.
-- A re-run of this migration reports "duplicate column name" (MySQL 1060),
-- which scripts/migrate.php tolerates.
ALTER TABLE gallery_category_suggestions ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'model';
