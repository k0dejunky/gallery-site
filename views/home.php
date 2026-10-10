<?php $title = config('app.site_name'); ?>
<?php
$metaDescription = $metaDescription ?? ('Join ' . config('app.site_name') . ' for full access to original photos, videos and live streams.');
$canonicalUrl    = $canonicalUrl ?? absolute_url('/');
$ogImage         = $ogImage ?? '';
$galleries       = (array) ($galleries ?? []);
$covers          = (array) ($covers ?? []);
$categories      = (array) ($categories ?? []);
$categoryCounts  = (array) ($categoryCounts ?? []);
$mediaCounts     = (array) ($mediaCounts ?? []);
$featured        = (array) ($featured ?? []);
$trending        = (array) ($trending ?? []);
$tags            = (array) ($tags ?? []);
?>

<div class="hero home-hero">
    <p class="hero-kicker" style="color:var(--brand-2);font-variant:small-caps;letter-spacing:.14em;margin:0 0 .5rem;">Adults only</p>
    <h1 style="font-size:clamp(1.8rem, 4vw, 2.6rem);margin:0 0 .4rem;"><?= e(config('app.site_name')) ?></h1>
    <p class="muted" style="max-width:52rem;margin:0 auto 1.5rem;">
        Original photos, videos and live streams — updated regularly. Join for full access;
        guests may browse blurred teasers.
    </p>
    <div style="display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;">
        <a class="btn" href="<?= url('/signup') ?>">Join now — free preview</a>
        <a class="btn btn-outline" href="<?= url('/login') ?>">Log in</a>
        <a class="btn btn-outline" href="<?= url('/membership') ?>">See membership</a>
    </div>
    <?php if (!empty($mediaCounts['images']) || !empty($mediaCounts['videos'])): ?>
        <p class="muted" style="font-size:.9rem;margin:1rem 0 0;">
            <?= number_format((int) ($mediaCounts['images'] ?? 0)) ?> photos
            <?= !empty($mediaCounts['videos']) ? ' &middot; ' . number_format((int) $mediaCounts['videos']) . ' videos' : '' ?>
            in the home collection
        </p>
    <?php endif; ?>
    <p class="muted" style="font-size:.8rem;margin:.4rem 0 0;">
        You must be 18 or older to enter. Content is blurred until you sign up.
    </p>
</div>

<?php if ($categories !== []): ?>
    <section class="home-section" style="margin-top:1.5rem;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
            <h2 style="margin:0;">Browse by category</h2>
            <a class="btn btn-sm btn-link" href="<?= url('/galleries') ?>">Browse all galleries &rarr;</a>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.75rem;">
            <?php foreach ($categories as $cat): ?>
                <a class="chip" href="<?= url('/galleries/category/' . e($cat['slug'])) ?>">
                    <?= e($cat['name']) ?>
                    <span class="muted">(<?= (int) ($categoryCounts[(int) $cat['id']] ?? 0) ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($featured !== []): ?>
    <section class="home-section" style="margin-top:1.75rem;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
            <h2 style="margin:0;">Featured</h2>
            <a class="btn btn-sm btn-link" href="<?= url('/galleries') ?>">View all &rarr;</a>
        </div>
        <div class="grid" style="margin-top:.75rem;">
            <?php echo \App\Core\PageCache::fragment('home.featured.' . md5(json_encode(array_column($featured, 'id'))), 120, static function () use ($featured): void {
                foreach ($featured as $g) {
                    $gallery = $g;
                    $cover = null;
                    require __DIR__ . '/partials/gallery_card.php';
                }
            }); ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($trending !== []): ?>
    <section class="home-section" style="margin-top:1.75rem;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
            <h2 style="margin:0;">Trending this week</h2>
            <a class="btn btn-sm btn-link" href="<?= url('/galleries') ?>">View all &rarr;</a>
        </div>
        <div class="grid" style="margin-top:.75rem;">
            <?php echo \App\Core\PageCache::fragment('home.trending.' . md5(json_encode(array_column($trending, 'id'))), 120, static function () use ($trending): void {
                foreach ($trending as $g) {
                    $gallery = $g;
                    $cover = null;
                    require __DIR__ . '/partials/gallery_card.php';
                }
            }); ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($tags !== []): ?>
    <section class="home-section" style="margin-top:1.5rem;">
        <h2 style="margin:0 0 .6rem;">Popular tags</h2>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
            <?php foreach (array_slice($tags, 0, 16) as $tag): ?>
                <a class="chip" href="<?= url('/galleries/tag/' . e($tag['slug'])) ?>">#<?= e($tag['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="home-section" style="margin-top:1.75rem;">
    <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2 style="margin:0;">Latest uploads</h2>
        <a class="btn btn-sm btn-link" href="<?= url('/galleries') ?>">View all &rarr;</a>
    </div>
    <?php if ($galleries === []): ?>
        <p class="muted" style="margin-top:1rem;">No galleries published yet — check back soon.</p>
    <?php else: ?>
        <div class="grid" style="margin-top:.75rem;">
            <?php echo \App\Core\PageCache::fragment('home.latest.' . md5(json_encode(array_column($galleries, 'id'))), 120, static function () use ($galleries, $covers): void {
                foreach ($galleries as $g) {
                    $gallery = $g;
                    $cover = $covers[(int) $g['id']] ?? null;
                    require __DIR__ . '/partials/gallery_card.php';
                }
            }); ?>
        </div>
    <?php endif; ?>
</section>

<section class="home-upsell" style="margin-top:2rem;border:1px solid var(--border);border-radius:var(--border-radius);padding:1.5rem;text-align:center;background:linear-gradient(180deg,color-mix(in srgb,var(--brand-1) 6%,transparent),transparent);">
    <h2 style="margin:0 0 .35rem;">Unlock everything</h2>
    <p class="muted" style="max-width:44rem;margin:0 auto .9rem;">
        Silver and above unlocks every gallery at full resolution, favorites,
        collections, the members' chat and live streams. New accounts start with
        a <?= (int) \App\Models\SiteConfig::trialDays() ?>-day trial.
    </p>
    <a class="btn" href="<?= url('/membership') ?>">View membership plans</a>
</section>