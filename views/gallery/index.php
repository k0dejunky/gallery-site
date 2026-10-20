<?php $title = config('app.site_name'); ?>

<?php
// The card partial needs the member's own state (seen, favourited). It is
// rendered inside a fragment closure, so the closure has to be given these
// explicitly - and they are defaulted here so a page that does not know
// about them (a tag page, a preview) never trips an undefined variable.
$viewedIds         = $viewedIds ?? [];
$favoriteGalleryIds = $favoriteGalleryIds ?? [];
$currentUser       = $currentUser ?? null;
$hasActive         = $hasActive ?? false;
?>
<?php
$metaDescription = 'Browse every gallery on ' . config('app.site_name') . ' — original photos and videos across all categories, updated regularly.';
$canonicalUrl = absolute_url('/galleries');
$ldJson = [
    '@context' => 'https://schema.org',
    '@type'    => 'CollectionPage',
    'name'     => $title . ' — Galleries',
    'url'      => $canonicalUrl,
    'description' => $metaDescription,
    'isPartOf' => ['@type' => 'WebSite', 'name' => $title, 'url' => absolute_url('')],
];
?>
<?php
// Build the type-filter links (All / Images / Videos) preserving the current
// search and category so switching a chip never loses the active filter.
// The left navigation sidebar is rendered by the shared layout.
$base = url('/galleries');
$query = [];
if ($q !== '') {
    $query['q'] = $q;
}
if ($categoryId > 0) {
    $query['category'] = $categoryId;
}
if (($sort ?? '') !== '') {
    $query['sort'] = $sort;
}
$allUrl = $query ? $base . '?' . http_build_query($query) : $base;
$imgUrl = url('/images') . ($query ? '?' . http_build_query($query) : '');
$vidUrl = url('/videos') . ($query ? '?' . http_build_query($query) : '');

// Back links from a gallery/media opened here return to this exact listing
// (search/category/type/sort preserved) instead of the generic gallery page.
$listingReturnTo = safe_return_to($_SERVER['REQUEST_URI'] ?? '') ?? url('/galleries');
?>

<div class="hero">
    <form method="get" action="<?= url('/galleries') ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search galleries by title, description or category…" aria-label="Search galleries">
        <button type="submit" class="btn">Search</button>
    </form>
</div>

<div class="chips" style="justify-content:center">
    <a class="chip <?= $type === '' ? 'active' : '' ?>" href="<?= e($allUrl) ?>"<?= $type === '' ? ' aria-current="page"' : '' ?>>All Galleries</a>
    <a class="chip <?= $type === 'images' ? 'active' : '' ?>" href="<?= e($imgUrl) ?>"<?= $type === 'images' ? ' aria-current="page"' : '' ?>>&#128444; Image Galleries</a>
    <a class="chip <?= $type === 'videos' ? 'active' : '' ?>" href="<?= e($vidUrl) ?>"<?= $type === 'videos' ? ' aria-current="page"' : '' ?>>&#9654; Video Galleries</a>
</div>

<div class="sort-bar" style="justify-content:center">
    <span class="label">Sort:</span>
    <?php
    $sortBase = url('/galleries');
    $sortParams = [];
    if ($q !== '') $sortParams['q'] = $q;
    if ($categoryId > 0) $sortParams['category'] = $categoryId;
    if ($type !== '') $sortParams['type'] = $type;
    $sortOptions = ['' => 'Newest', 'views' => 'Most Viewed', 'title' => 'A–Z'];
    foreach ($sortOptions as $val => $label):
        $p = $sortParams;
        if ($val !== '') $p['sort'] = $val;
        $href = $p ? $sortBase . '?' . http_build_query($p) : $sortBase;
    ?>
        <a class="chip <?= ($sort ?? '') === $val ? 'active' : '' ?>" href="<?= e($href) ?>"<?= ($sort ?? '') === $val ? ' aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
</div>

<?php if (($lockedTotal ?? 0) > 0): ?>
<?php // The paid part of the collection, said out loud. ?>
<div class="listing-locked-note">
    <span><?= number_format((int) $lockedTotal) ?> <?= (int) $lockedTotal === 1 ? 'gallery' : 'galleries' ?> in the collection <?= (int) $lockedTotal === 1 ? 'is' : 'are' ?> above your level.</span>
    <a class="btn btn-sm" href="<?= e(url('/membership')) ?>">View membership</a>
</div>
<?php endif; ?>

<?php if ($q === ''): ?>
<?php /* The listing: the member's own picks FIRST, then what is new and
         what is being looked at, and only then the catalogue - the
         recommendation strip used to sit at the very bottom, under the
         whole catalogue. */ ?>
<?php if (!empty($recommended)): ?>
<section class="fav-section">
    <h2>Recommended for you</h2>
    <p class="muted">Galleries similar to your favourites and recent views.</p>
    <div class="grid">
        <?php
        $recKey = 'recommended.' . md5(json_encode(array_column($recommended, 'id')));
        echo \App\Core\PageCache::fragment($recKey, 120, static function () use ($recommended, $viewedIds, $favoriteGalleryIds, $currentUser, $hasActive): void {
            $recCats = \App\Models\Gallery::categoriesBulk(array_map('intval', array_column($recommended, 'id')));
            foreach ($recommended as $gallery) {
                $gid = (int) $gallery['id'];
                $cover = $gallery['first_photo'] ?? null;
                $galleryCategories = $recCats[$gid] ?? [];
                require __DIR__ . '/../partials/gallery_card.php';
            }
        });
        ?>
    </div>
</section>
<?php endif; ?>

<?php /* Recently added: the newest galleries, whatever their category. */ ?>
<?php if (!empty($recentStrip)): ?>
<section class="fav-section">
    <h2>Recently added</h2>
    <p class="muted">The newest sets in the collection.</p>
    <div class="grid">
        <?php foreach ($recentStrip as $recentGallery): ?>
            <?php
            $gallery = $recentGallery;
            $cover = $stripCovers['covers'][(int) $recentGallery['id']] ?? null;
            $galleryCategories = $stripCovers['categories'][(int) $recentGallery['id']] ?? [];
            require __DIR__ . '/../partials/gallery_card.php';
            ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php /* Trending this week: what the members are actually looking at. */ ?>
<?php if (!empty($trendingStrip)): ?>
<section class="fav-section">
    <h2>Trending this week</h2>
    <p class="muted">The sets getting the most views over the last seven days.</p>
    <div class="grid">
        <?php foreach ($trendingStrip as $trendingGallery): ?>
            <?php
            $gallery = $trendingGallery;
            $cover = $stripCovers['covers'][(int) $trendingGallery['id']] ?? null;
            $galleryCategories = $stripCovers['categories'][(int) $trendingGallery['id']] ?? [];
            require __DIR__ . '/../partials/gallery_card.php';
            ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php if (empty($sections)): ?>
    <div class="empty-state">
        <p class="muted">No galleries yet.</p>
        <a class="btn btn-sm" href="<?= e(url('/support')) ?>">Contact support</a>
    </div>
<?php else: ?>
    <?php $favCatIds = array_map('intval', array_column((array) ($favorites ?? []), 'id')); ?>
    <?php foreach ($sections as $section): ?>
        <section class="fav-section">
            <h2>
                <?php if (!empty($hasActive) && !empty($section['category']['id'])): ?>
                    <button type="button" class="chip cat-fav-toggle<?= in_array((int) $section['category']['id'], $favCatIds, true) ? ' selected' : '' ?>"
                            data-cat-id="<?= (int) $section['category']['id'] ?>"
                            data-csrf="<?= e(\App\Core\Csrf::token()) ?>"
                            title="Add/remove this category to your favorites"
                            aria-label="Toggle favorite category">&#9733;</button>
                <?php endif; ?>
                <?= e($section['category']['name']) ?>
                <?php if (!empty($section['category']['slug'])): ?>
                    <a href="<?= url('/galleries/category/' . e($section['category']['slug']) . ($type !== '' ? '?type=' . $type : '')) ?>">View all &rarr;</a>
                <?php endif; ?>
            </h2>
            <div class="grid">
                <?php
                $sectionKey = 'sections.' . md5(json_encode([
                    $section['category']['id'] ?? 0,
                    array_column($section['galleries'], 'id'),
                ]));
                echo \App\Core\PageCache::fragment($sectionKey, 120, static function () use ($section, $cardCovers, $viewedIds, $favoriteGalleryIds, $currentUser, $hasActive): void {
                    foreach ($section['galleries'] as $gallery) {
                        $gid = (int) $gallery['id'];
                        $cover = $cardCovers['covers'][$gid] ?? null;
                        $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                        require __DIR__ . '/../partials/gallery_card.php';
                    }
                });
                ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php else: ?>
<section>
    <h2 class="section-title">Search results</h2>

    <p class="muted">
        Searching for &ldquo;<?= e($q) ?>&rdquo;
        <?php if ($categoryId > 0): ?>
            <?php foreach ($categories as $cat): if ((int) $cat['id'] === $categoryId): ?>
                in category &ldquo;<?= e($cat['name']) ?>&rdquo;
            <?php endif; endforeach; ?>
        <?php endif; ?>
        <?php if ($type === 'images'): ?>showing image galleries<?php endif; ?>
        <?php if ($type === 'videos'): ?>showing video galleries<?php endif; ?>
        <a href="<?= url('/galleries') ?>">(clear)</a>
    </p>
    <form class="save-search" method="post" action="<?= e(url('/saved-searches')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <input type="hidden" name="category" value="<?= $categoryId > 0 ? (int) $categoryId : 0 ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? url('/galleries')) ?>">
        <button class="btn btn-sm btn-outline" type="submit">Save this search</button>
    </form>

    <?php if (empty($paginator['items'])): ?>
        <div class="empty-state">
            <p>No galleries found<?= $q !== '' ? ' for &ldquo;' . e($q) . '&rdquo;' : '' ?>.</p>
            <?php if (!empty($popularSearches)): ?>
                <p class="muted u-fs-085" style="margin:.5rem 0 .35rem;">Popular searches</p>
                <div class="chips" style="justify-content:center">
                    <?php foreach ($popularSearches as $term): ?>
                        <a class="chip" href="<?= e(url('/galleries?q=' . urlencode($term))) ?>"><?= e($term) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse all galleries</a>
        </div>
    <?php else: ?>
        <div class="grid">
            <?php
            $searchKey = 'search.' . md5(json_encode([
                (string) $q,
                (int) $categoryId,
                (string) $type,
                (string) $sort,
                (int) ($paginator['page'] ?? 1),
                array_column($paginator['items'], 'id'),
            ]));
            echo \App\Core\PageCache::fragment($searchKey, 120, static function () use ($paginator, $cardCovers, $viewedIds, $favoriteGalleryIds, $currentUser, $hasActive): void {
                foreach ($paginator['items'] as $gallery) {
                    $gid = (int) $gallery['id'];
                    $cover = $cardCovers['covers'][$gid] ?? null;
                    $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                    require __DIR__ . '/../partials/gallery_card.php';
                }
            });
            ?>
        </div>
        <?php
        $baseUrl = url('/galleries');
        $query   = [];
        if ($q !== '') {
            $query['q'] = $q;
        }
        if ($categoryId > 0) {
            $query['category'] = $categoryId;
        }
        if ($type !== '') {
            $query['type'] = $type;
        }
        if ($sort !== '') {
            $query['sort'] = $sort;
        }
        require __DIR__ . '/../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
<?php endif; ?>
