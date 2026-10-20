-- Search the photo captions.
--
-- A gallery's search covered the title, the description and the category
-- names, but NOT the captions - and a caption is the descriptive text of an
-- image (it is the alt text of every grid item). A member searching for a
-- word that is in a caption found nothing.
--
-- A FULLTEXT index on the caption makes that search possible. The table is
-- small (a few hundred rows), so the build is quick.
ALTER TABLE photos
    ADD FULLTEXT KEY ft_caption (caption);
