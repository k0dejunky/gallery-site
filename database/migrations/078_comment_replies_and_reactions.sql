-- Comment replies and reactions.
--
-- The comments were flat: a member could say something, but could not
-- answer it. A reply is a comment with a parent, and a reaction is one
-- emoji per member per comment.
ALTER TABLE comments
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER commentable_id,
    ADD INDEX idx_comments_parent (parent_id),
    ADD CONSTRAINT comments_parent_fk FOREIGN KEY (parent_id)
        REFERENCES comments(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS comment_reactions (
    comment_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    reaction   VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id, user_id, reaction),
    INDEX idx_comment_reactions_comment (comment_id),
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
