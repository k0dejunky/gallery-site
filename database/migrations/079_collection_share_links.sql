-- A private share link for a collection.
--
-- The owner could copy the list of URLs out, but there was no way to let
-- somebody else see the set itself. A share token is a random string stored
-- on the collection: the link is /collections/{id}/s/{token}, and the owner
-- can clear it at any time (which kills the link).
ALTER TABLE collections
    ADD COLUMN share_token CHAR(32) NULL AFTER name,
    ADD UNIQUE KEY uq_collections_share_token (share_token);
