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

<div class="hero-cover hero-cover-center hero-cover-tall">
    <div class="hero-cover-media">
        <?php if (!empty($covers) && !empty(reset($covers))): ?>
            <?php $c = reset($covers); ?>
            <img src="<?= e(public_file_url((string) $c['filename'], 'blur')) ?>" alt="" loading="eager" fetchpriority="high" data-fallback="data:image/svg+xml;utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 640 360'%3E%3Crect width='640' height='360' fill='%23150a1d'/%3E%3C/svg%3E">
        <?php endif; ?>
    </div>
    <div class="hero-cover-scrim"></div>
    <div class="hero-cover-inner">
        <p class="hero-cover-kicker">Adults only &middot; Verified creators</p>
        <h1 class="hero-cover-title"><span class="glass-word"><?= e(config('app.site_name')) ?></span></h1>
        <p class="hero-cover-lede">
            <strong>Original photos, videos and live streams</strong> — updated regularly.
            Safe-for-work galleries are open to everyone; adult galleries open in the free tier
            and beyond with a membership.
        </p>
        <div class="hero-cover-actions">
            <a class="btn btn-grad btn-lg" href="<?= url('/signup') ?>">Join now — free preview</a>
            <a class="btn btn-ghost btn-lg" href="<?= url('/login') ?>">Log in</a>
            <a class="btn btn-ghost btn-lg" href="<?= url('/membership') ?>">See membership</a>
        </div>
        <div class="stat-chip-row">
            <?php if (!empty($mediaCounts['images'])): ?>
                <div class="stat-chip">
                    <b><?= number_format((int) $mediaCounts['images']) ?></b>
                    <span>Photos</span>
                </div>
            <?php endif; ?>
            <?php if (!empty($mediaCounts['videos'])): ?>
                <div class="stat-chip">
                    <b><?= number_format((int) $mediaCounts['videos']) ?></b>
                    <span>Videos</span>
                </div>
            <?php endif; ?>
            <div class="stat-chip">
                <b>18+</b>
                <span>Age verified</span>
            </div>
        </div>
        <nav class="guest-browse" aria-label="Quick browse">
            <a class="pill" href="<?= url('/galleries') ?>">All galleries</a>
            <a class="pill" href="<?= url('/galleries?type=videos') ?>">Videos</a>
            <a class="pill" href="<?= url('/galleries?content=sfw') ?>">Safe for work</a>
        </nav>
    </div>
</div>

<?php if ($categories !== []): ?>
    <section class="home-section">
        <div class="home-section-head">
            <h2 class="u-m0">Browse by category</h2>
            <a class="btn btn-sm btn-link" href="<?= url('/galleries') ?>">Browse all galleries &rarr;</a>
        </div>
        <div class="home-chips">
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
    <section class="home-section home-section-gap">
        <div class="home-section-head">
            <h2 class="u-m0">Featured</h2>
            <a class="btn btn-sm btn-link" href="<?= url('/galleries') ?>">View all &rarr;</a>
        </div>
        <div class="grid u-mt-075">
            <?php echo \App\Core\PageCache::fragment('home.featured.' . md5(json_encode(array_column($featured, 'id'))), 120, static function () use ($featured, $cardCovers): void {
                foreach ($featured as $g) {
                    $gallery = $g;
                    $cover = $cardCovers['covers'][(int) $g['id']] ?? null;
                    $galleryCategories = $cardCovers['categories'][(int) $g['id']] ?? [];
                    require __DIR__ . '/partials/gallery_card.php';
                }
            }); ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($trending !== []): ?>
    <section class="home-section home-section-gap">
        <div class="home-section-head">
            <h2 class="u-m0">Trending this week</h2>
            <a class="btn btn-sm btn-link" href="<?= url('/galleries') ?>">View all &rarr;</a>
        </div>
        <div class="grid u-mt-075">
            <?php echo \App\Core\PageCache::fragment('home.trending.' . md5(json_encode(array_column($trending, 'id'))), 120, static function () use ($trending, $cardCovers): void {
                foreach ($trending as $g) {
                    $gallery = $g;
                    $cover = $cardCovers['covers'][(int) $g['id']] ?? null;
                    $galleryCategories = $cardCovers['categories'][(int) $g['id']] ?? [];
                    require __DIR__ . '/partials/gallery_card.php';
                }
            }); ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($tags !== []): ?>
    <section class="home-section">
        <h2 style="margin:0 0 .6rem;">Popular tags</h2>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
            <?php foreach (array_slice($tags, 0, 16) as $tag): ?>
                <a class="chip" href="<?= url('/galleries/tag/' . e($tag['slug'])) ?>">#<?= e($tag['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="home-section home-section-gap">
    <div class="home-section-head">
        <h2 class="u-m0">Latest uploads</h2>
        <a class="btn btn-sm btn-link" href="<?= url('/galleries') ?>">View all &rarr;</a>
    </div>
    <?php if ($galleries === []): ?>
        <p class="muted u-mt-1">No galleries published yet — check back soon.</p>
    <?php else: ?>
        <div class="grid u-mt-075">
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

<section class="home-upsell">
    <h2 class="home-upsell-title">Unlock everything</h2>
    <p class="muted home-upsell-text">
        Silver and above unlocks every gallery at full resolution, favorites,
        collections, the members' chat and live streams. New accounts start with
        a <?= (int) \App\Models\SiteConfig::trialDays() ?>-day trial.
    </p>
    <a class="btn" href="<?= url('/membership') ?>">View membership plans</a>
</section>
