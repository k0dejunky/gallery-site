-- A deleted account must not take the financial ledger with it.
--
-- subscriptions.user_id and purchases.user_id were ON DELETE CASCADE, so
-- deleting a member destroyed their subscription history and every unlock
-- they had paid for. The account is now soft-deleted (and anonymised), so
-- the ledger survives; the account itself leaves every listing.
ALTER TABLE users
    ADD COLUMN deleted_at DATETIME NULL AFTER status,
    ADD INDEX idx_users_deleted (deleted_at);

-- The wall remembers who wrote a post: WallPost::create() took a user id
-- and threw it away.
ALTER TABLE wall_posts
    ADD COLUMN created_by INT UNSIGNED NULL AFTER gallery_id,
    ADD INDEX idx_wall_posts_created_by (created_by);

-- A wall post that pointed at a deleted gallery now points at nothing
-- instead of dangling.
ALTER TABLE wall_posts
    ADD CONSTRAINT wall_posts_gallery_fk FOREIGN KEY (gallery_id)
        REFERENCES galleries(id) ON DELETE SET NULL;

-- A subscription that pointed at a deleted sale or sale code now points at
-- nothing instead of dangling.
ALTER TABLE subscriptions
    ADD CONSTRAINT subscriptions_sale_fk FOREIGN KEY (sale_id)
        REFERENCES sales(id) ON DELETE SET NULL,
    ADD CONSTRAINT subscriptions_sale_code_fk FOREIGN KEY (sale_code_id)
        REFERENCES sale_codes(id) ON DELETE SET NULL;
