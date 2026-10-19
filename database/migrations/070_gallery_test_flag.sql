-- 070 gallery announcement suppression.
-- A gallery can be marked as a TEST gallery, which stops the site from
-- announcing it: no "new gallery" email to the members, no in-app bell
-- notification, and no social auto-post. Test galleries are created all the
-- time while working on the site, and each one that went live mailed every
-- opted-in member the title of a throwaway set.
--
-- The flag is deliberately separate from is_secret: a secret gallery is
-- hidden from members (and must never be announced either), while a test
-- gallery is a normal, visible gallery that simply must not be announced.
--
-- Galleries are also recognised as test galleries by their title (see
-- app.gallery_test_patterns), so a gallery created by a script or an import
-- is caught even when nobody ticked the box.

ALTER TABLE galleries
    ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER is_secret,
    ADD INDEX idx_galleries_test (is_test, notified_at);
