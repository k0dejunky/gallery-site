<?php
$title = $tag['name'];
$canonicalUrl = absolute_url('/galleries/tag/' . rawurlencode((string) $tag['slug']));
$metaDescription = 'Galleries tagged #' . (string) $tag['name'] . ' on ' . config('app.site_name') . '.';
?>
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
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => '#' . $tag['name']],
];
require __DIR__ . '/../partials/breadcrumbs.php';
?>

<h1>#<?= e($tag['name']) ?></h1>
<p class="muted">Galleries tagged &ldquo;<?= e($tag['name']) ?>&rdquo;.</p>

<?php
// The same Type / Content / Sort chips as the catalogue and the category
// pages, built from ONE state so a chip never drops another filter. A plain
// loop, not a closure: a closure between the card and its captured variables
// confuses the guard that checks the card receives the member's own state.
$tagBase  = url('/galleries/tag/' . rawurlencode((string) $tag['slug']));
$tagState = [];
foreach (['type' => (string) ($type ?? ''), 'content' => (string) ($contentFilter ?? ''), 'sort' => (string) ($sort ?? '')] as $k => $v) {
    if ($v !== '') {
        $tagState[$k] = $v;
    }
}
$tagChipUrls = [];
foreach (['type' => ['', 'images', 'videos'], 'content' => ['', 'sfw', 'nsfw'], 'sort' => ['', 'views', 'title']] as $key => $values) {
    foreach ($values as $value) {
        $p = $tagState;
        if ($value === '') {
            unset($p[$key]);
        } else {
            $p[$key] = $value;
        }
        $tagChipUrls[$key][$value] = $p === [] ? $tagBase : $tagBase . '?' . http_build_query($p);
    }
}
?>
<div class="filter-bar">
    <p class="filter-bar-label">Type</p>
    <div class="chips" style="margin:0">
        <a class="chip <?= ($type ?? '') === '' ? 'active' : '' ?>" href="<?= e($tagChipUrls['type']['']) ?>">All</a>
        <a class="chip <?= ($type ?? '') === 'images' ? 'active' : '' ?>" href="<?= e($tagChipUrls['type']['images']) ?>">Image galleries</a>
        <a class="chip <?= ($type ?? '') === 'videos' ? 'active' : '' ?>" href="<?= e($tagChipUrls['type']['videos']) ?>">Video galleries</a>
    </div>
</div>

<div class="filter-bar">
    <p class="filter-bar-label">Content</p>
    <div class="chips content-chips" style="margin:0">
        <?php foreach (['' => 'All', 'sfw' => 'Safe for work', 'nsfw' => 'Adult'] as $val => $label): ?>
            <a class="chip <?= ($contentFilter ?? '') === $val ? 'active' : '' ?>" href="<?= e($tagChipUrls['content'][$val]) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="filter-bar">
    <p class="filter-bar-label">Sort</p>
    <div class="chips" style="margin:0">
        <?php foreach (['' => 'Newest', 'views' => 'Most viewed', 'title' => 'A–Z'] as $val => $label): ?>
            <a class="chip <?= ($sort ?? '') === $val ? 'active' : '' ?>" href="<?= e($tagChipUrls['sort'][$val]) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($galleries)): ?>
    <div class="empty-state">
        <p class="muted">No galleries use this tag yet.</p>
        <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse all galleries</a>
    </div>
<?php else: ?>
    <div class="grid">
        <?php echo \App\Core\PageCache::fragment('tag.' . md5(json_encode([$tag['id'] ?? 0, array_column($galleries, 'id')])), 120, static function () use ($galleries, $viewedIds, $favoriteGalleryIds, $currentUser, $hasActive, $cardCovers): void {
            foreach ($galleries as $gallery) {
                $cover = $cardCovers['covers'][(int) $gallery['id']] ?? null;
                $galleryCategories = $cardCovers['categories'][(int) $gallery['id']] ?? [];
                require __DIR__ . '/../partials/gallery_card.php';
            }
        }); ?>
    </div>

    <?php if ($page > 1 || count($galleries) === 24): ?>
        <nav style="display:flex;gap:1rem;margin-top:1.5rem;">
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-outline" href="<?= url('/galleries/tag/' . e($tag['slug']) . '?page=' . ($page - 1)) ?>">&larr; Previous</a>
            <?php endif; ?>
            <?php if (count($galleries) === 24): ?>
                <a class="btn btn-sm" href="<?= url('/galleries/tag/' . e($tag['slug']) . '?page=' . ($page + 1)) ?>">Next &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>