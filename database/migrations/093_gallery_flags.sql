-- 093 Two content-tracking flags on galleries: AI-generated and explicit.
--
-- The owner publishes a mix of photographed and AI-generated sets and had no
-- way to tell them apart in the console, so "which of these are AI" could only
-- be answered from memory. The flags are stored on the gallery (the unit the
-- owner publishes and describes), not on the individual media.
--
--   is_ai  = 1  the set was generated with an image model
--   is_xxx = 1  the set is explicit ("XXX" in the old sense), not merely adult
--
-- Both default to 0, so applying this migration to an existing site changes
-- nothing: every current gallery is neither AI nor XXX until it is flagged.
-- is_xxx is a stricter label than is_nsfw, not a replacement for it: an
-- explicit gallery is also adult, and the controller forces is_nsfw = 1 when
-- is_xxx is set so the two can never disagree.
ALTER TABLE galleries
    ADD COLUMN is_ai  TINYINT(1) NOT NULL DEFAULT 0 AFTER is_nsfw,
    ADD COLUMN is_xxx TINYINT(1) NOT NULL DEFAULT 0 AFTER is_ai;
