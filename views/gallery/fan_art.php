<?php
$title = 'Fan Art';
$canonicalUrl = absolute_url('/fan-art');
$metaDescription = 'Fan art on ' . config('app.site_name') . ' — AI-generated and fan-made images and videos, marked by the creator.';
$ldJson = [
    '@context' => 'https://schema.org',
    '@type'    => 'CollectionPage',
    'name'     => config('app.site_name') . ' — Fan Art',
    'url'      => $canonicalUrl,
    'description' => $metaDescription,
    'isPartOf' => ['@type' => 'WebSite', 'name' => config('app.site_name'), 'url' => absolute_url('')],
];
?>
<?php
// The card partial needs the member's own state (seen, favourited). It is
// rendered inside a fragment closure, so the closure has to be given these
// explicitly - and they are defaulted here so a page that does not know
// about them never trips an undefined variable.
$viewedIds          = $viewedIds ?? [];
$favoriteGalleryIds = $favoriteGalleryIds ?? [];
$currentUser        = $currentUser ?? null;
$hasActive          = $hasActive ?? false;
$galleries          = $paginator['items'] ?? [];
$fanPage            = (int) ($paginator['page'] ?? 1);
?>
<?php
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => 'Fan Art'],
];
require __DIR__ . '/../partials/breadcrumbs.php';
?>

<h1 class="section-title">Fan Art</h1>
<p class="muted">AI-generated and fan-made sets, marked by the creator.</p>

<?php
// The same Type / Content / Sort chips as the catalogue and the category
// pages, built from ONE state so a chip never drops another filter. A plain
// loop, not a closure: a closure between the card and its captured variables
// confuses the guard that checks the card receives the member's own state.
$fanBase  = url('/fan-art');
$fanState = [];
foreach (['type' => (string) ($type ?? ''), 'content' => (string) ($contentFilter ?? ''), 'sort' => (string) ($sort ?? '')] as $k => $v) {
    if ($v !== '') {
        $fanState[$k] = $v;
    }
}
$fanChipUrls = [];
foreach (['type' => ['', 'images', 'videos'], 'content' => ['', 'sfw', 'nsfw'], 'sort' => ['', 'views', 'title']] as $key => $values) {
    foreach ($values as $value) {
        $p = $fanState;
        if ($value === '') {
            unset($p[$key]);
        } else {
            $p[$key] = $value;
        }
        $fanChipUrls[$key][$value] = $p === [] ? $fanBase : $fanBase . '?' . http_build_query($p);
    }
}
// The pagination partial appends ?page=N (or &page=N) to this.
$baseUrl = $fanState === [] ? $fanBase : $fanBase . '?' . http_build_query($fanState);
?>
<div class="filter-bar">
    <p class="filter-bar-label">Type</p>
    <div class="chips" style="margin:0">
        <a class="chip <?= ($type ?? '') === '' ? 'active' : '' ?>" href="<?= e($fanChipUrls['type']['']) ?>"<?= ($type ?? '') === '' ? ' aria-current="page"' : '' ?>>All</a>
        <a class="chip <?= ($type ?? '') === 'images' ? 'active' : '' ?>" href="<?= e($fanChipUrls['type']['images']) ?>"<?= ($type ?? '') === 'images' ? ' aria-current="page"' : '' ?>>Image galleries</a>
        <a class="chip <?= ($type ?? '') === 'videos' ? 'active' : '' ?>" href="<?= e($fanChipUrls['type']['videos']) ?>"<?= ($type ?? '') === 'videos' ? ' aria-current="page"' : '' ?>>Video galleries</a>
    </div>
</div>

<div class="filter-bar">
    <p class="filter-bar-label">Content</p>
    <div class="chips content-chips" style="margin:0">
        <?php foreach (['' => 'All', 'sfw' => 'Safe for work', 'nsfw' => 'Adult'] as $val => $label): ?>
            <a class="chip <?= ($contentFilter ?? '') === $val ? 'active' : '' ?>" href="<?= e($fanChipUrls['content'][$val]) ?>"<?= ($contentFilter ?? '') === $val ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="filter-bar">
    <p class="filter-bar-label">Sort</p>
    <div class="chips" style="margin:0">
        <?php foreach (['' => 'Newest', 'views' => 'Most viewed', 'title' => 'A–Z'] as $val => $label): ?>
            <a class="chip <?= ($sort ?? '') === $val ? 'active' : '' ?>" href="<?= e($fanChipUrls['sort'][$val]) ?>"<?= ($sort ?? '') === $val ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($galleries)): ?>
    <div class="empty-state">
        <p class="muted">No fan art yet. Sets marked as fan art appear here.</p>
        <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse all galleries</a>
    </div>
<?php else: ?>
    <div class="grid">
        <?php echo \App\Core\PageCache::fragment('fanart.' . md5(json_encode([array_column($galleries, 'id'), $fanPage])), 120, static function () use ($galleries, $viewedIds, $favoriteGalleryIds, $currentUser, $hasActive, $cardCovers): void {
            foreach ($galleries as $gallery) {
                $cover = $cardCovers['covers'][(int) $gallery['id']] ?? null;
                $galleryCategories = $cardCovers['categories'][(int) $gallery['id']] ?? [];
                require __DIR__ . '/../partials/gallery_card.php';
            }
        }); ?>
    </div>

    <?php require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
