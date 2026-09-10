-- Remove the level-4 "OnlyFans" membership plan. It was a dev-only plan that
-- never existed in production, and gallery gating tops out at Platinum (level
-- 3). No galleries use level 4, but clamp any stragglers so the UI (whose
-- highest option is grade 3) can never orphan a gallery above its maximum.
DELETE FROM plans WHERE slug = 'onlyfans';
UPDATE galleries SET min_level = 3 WHERE min_level > 3;