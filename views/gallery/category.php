<?php $title = $category['name']; ?>
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
$metaDescription = 'Browse the ' . $category['name'] . ' galleries on ' . config('app.site_name') . ' — original photos and videos in this category.';
$canonicalUrl = absolute_url('/galleries/category/' . e($category['slug']));
$ldJson = [
    '@context' => 'https://schema.org',
    '@type'    => 'CollectionPage',
    'name'     => $category['name'],
    'url'      => $canonicalUrl,
    'description' => $metaDescription,
    'isPartOf' => ['@type' => 'WebSite', 'name' => config('app.site_name'), 'url' => absolute_url('')],
];
?>
<?php
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => $category['name']],
];
require __DIR__ . '/../partials/breadcrumbs.php';

// Back links from a gallery/media opened here return to this category page.
$listingReturnTo = safe_return_to($_SERVER['REQUEST_URI'] ?? '') ?? url('/galleries/category/' . $category['slug']);
?>


<h1 class="section-title">Category: <?= e($category['name']) ?></h1>

<?php // Text search scoped to just this category; it narrows the type sections. ?>
<div class="hero" style="padding:1.25rem 1.5rem; text-align:left; margin-top:1rem;">
    <form method="get" action="<?= url('/galleries/category/' . e($category['slug'])) ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search within this category…" aria-label="Search within this category">
        <button type="submit" class="btn">Search</button>
    </form>
</div>

<?php
// Build the type-filter links (All / Images / Videos), preserving the active
// search so switching a chip never loses the query.
$base = url('/galleries/category/' . e($category['slug']));
$query = $q !== '' ? ['q' => $q] : [];
if (($sort ?? '') !== '') {
    $query['sort'] = $sort;
}
// Every section has its own page parameter, so the chips carry the page the
// member was on instead of dropping them back to the first one.
$imagePage = (int) ($imagePage ?? 1);
$videoPage = (int) ($videoPage ?? 1);
$allUrl = $base . '?' . http_build_query(array_merge($query, ['page_images' => $imagePage, 'page_videos' => $videoPage]));
$imgUrl = $base . '?' . http_build_query(array_merge($query, ['type' => 'images', 'page_images' => $imagePage]));
$vidUrl = $base . '?' . http_build_query(array_merge($query, ['type' => 'videos', 'page_videos' => $videoPage]));
?>

<div class="chips" style="justify-content:center">
    <a class="chip <?= $type === '' ? 'active' : '' ?>" href="<?= e($allUrl) ?>"<?= $type === '' ? ' aria-current="page"' : '' ?>>All Galleries</a>
    <a class="chip <?= $type === 'images' ? 'active' : '' ?>" href="<?= e($imgUrl) ?>"<?= $type === 'images' ? ' aria-current="page"' : '' ?>>&#128444; Image Galleries</a>
    <a class="chip <?= $type === 'videos' ? 'active' : '' ?>" href="<?= e($vidUrl) ?>"<?= $type === 'videos' ? ' aria-current="page"' : '' ?>>&#9654; Video Galleries</a>
</div>

<div class="sort-bar" style="justify-content:center">
    <span class="label">Sort:</span>
    <?php foreach (['' => 'Newest', 'views' => 'Most Viewed', 'title' => 'A-Z'] as $value => $label): ?>
        <?php $sortQuery = $query; if ($value === '') unset($sortQuery['sort']); else $sortQuery['sort'] = $value; ?>
        <a class="chip <?= ($sort ?? '') === $value ? 'active' : '' ?>" href="<?= e($base . ($sortQuery ? '?' . http_build_query($sortQuery) : '')) ?>"<?= ($sort ?? '') === $value ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php
$paginationQuery = $q !== '' ? ['q' => $q] : [];
if ($type !== '') $paginationQuery['type'] = $type;
if (($sort ?? '') !== '') $paginationQuery['sort'] = $sort;
$searchSuffix = $q !== '' ? ' match your search' : '';
$sectionBaseUrl = $base . ($paginationQuery ? '?' . http_build_query($paginationQuery) : '');
// A section's pager link keeps the other section on the page it was on.
$withParam = static function (string $url, string $key, int $value): string {
    return $url . (strpos($url, '?') !== false ? '&' : '?') . $key . '=' . $value;
};
// Shared fragment-cache namespace for this category (used by both sections).
$catBase = 'cat.' . md5(json_encode([$category['id'] ?? 0, $category['slug'] ?? '']));
?>

<?php if ($type === '' || $type === 'images'): ?>
<section class="fav-section">
    <h2>Image Galleries</h2>
    <?php if (empty($imagePaginator['items'])): ?>
        <div class="empty-state">
            <?php if (($lockedCount ?? 0) > 0): ?>
                <p class="muted"><?= number_format((int) $lockedCount) ?> <?= (int) $lockedCount === 1 ? 'gallery' : 'galleries' ?> in this category <?= (int) $lockedCount === 1 ? 'is' : 'are' ?> above your level.</p>
                <a class="btn btn-sm" href="<?= e(url('/membership')) ?>">View membership</a>
            <?php else: ?>
                <p class="muted">No image galleries in this category<?= e($searchSuffix) ?>.</p>
                <?php if ($q !== ''): ?>
                    <a class="btn btn-sm" href="<?= e(url('/galleries/category/' . $category['slug'])) ?>">Clear search</a>
                <?php else: ?>
                    <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse all galleries</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid">
            <?php
            echo \App\Core\PageCache::fragment($catBase . '.images.' . md5(json_encode([(string) $q, (int) ($imagePaginator['page'] ?? 1), array_column($imagePaginator['items'], 'id')])), 120, static function () use ($imagePaginator, $cardCovers, $viewedIds, $favoriteGalleryIds, $currentUser, $hasActive): void {
                foreach ($imagePaginator['items'] as $gallery) {
                    $gid = (int) $gallery['id'];
                    $cover = $cardCovers['covers'][$gid] ?? null;
                    $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                    require __DIR__ . '/../partials/gallery_card.php';
                }
            });
            ?>
        </div>
        <?php
        // Reuse the pagination partial for this section's own pages. The
        // section pages on page_images, so the video section stays where it
        // is.
        $baseUrl = $withParam($sectionBaseUrl, 'page_videos', $videoPage);
        $paginator = $imagePaginator;
        $pageParam = 'page_images';
        require __DIR__ . '/../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($type === '' || $type === 'videos'): ?>
<section class="fav-section">
    <h2>&#9654; Video Galleries</h2>
    <?php if (empty($videoPaginator['items'])): ?>
        <div class="empty-state">
            <?php if (($lockedCount ?? 0) > 0): ?>
                <p class="muted"><?= number_format((int) $lockedCount) ?> <?= (int) $lockedCount === 1 ? 'gallery' : 'galleries' ?> in this category <?= (int) $lockedCount === 1 ? 'is' : 'are' ?> above your level.</p>
                <a class="btn btn-sm" href="<?= e(url('/membership')) ?>">View membership</a>
            <?php else: ?>
                <p class="muted">No video galleries in this category<?= e($searchSuffix) ?>.</p>
                <?php if ($q !== ''): ?>
                    <a class="btn btn-sm" href="<?= e(url('/galleries/category/' . $category['slug'])) ?>">Clear search</a>
                <?php else: ?>
                    <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse all galleries</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid">
            <?php
            echo \App\Core\PageCache::fragment($catBase . '.videos.' . md5(json_encode([(string) $q, (int) ($videoPaginator['page'] ?? 1), array_column($videoPaginator['items'], 'id')])), 120, static function () use ($videoPaginator, $cardCovers, $viewedIds, $favoriteGalleryIds, $currentUser, $hasActive): void {
                foreach ($videoPaginator['items'] as $gallery) {
                    $gid = (int) $gallery['id'];
                    $cover = $cardCovers['covers'][$gid] ?? null;
                    $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                    require __DIR__ . '/../partials/gallery_card.php';
                }
            });
            ?>
        </div>
        <?php
        $baseUrl = $withParam($sectionBaseUrl, 'page_images', $imagePage);
        $paginator = $videoPaginator;
        $pageParam = 'page_videos';
        require __DIR__ . '/../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
<?php endif; ?>
