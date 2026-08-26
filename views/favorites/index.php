<?php $title = 'Favorites'; ?>

<div class="favorites-hero">
    <p class="eyebrow">Your collection</p>
    <h1>Favorites</h1>
    <p class="muted">A quiet place for the galleries and searches you want close at hand.</p>
</div>

<section class="favorites-section">
    <div class="favorites-heading"><h2>Favorite galleries</h2><a href="<?= e(url('/galleries')) ?>">Browse galleries</a></div>
    <?php if (empty($favoriteGalleries)): ?>
        <div class="empty-state"><p>No favorite galleries yet.</p><a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Find a gallery</a></div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($favoriteGalleries as $gallery): ?>
                <?php $cover = $gallery['first_photo'] ?? null; $galleryCategories = $cardCovers['categories'][(int) $gallery['id']] ?? []; require __DIR__ . '/../partials/gallery_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!empty($recentlyViewed)): ?>
<section class="favorites-section">
    <div class="favorites-heading"><h2>Recently viewed</h2><a href="<?= e(url('/galleries')) ?>">Browse all</a></div>
    <div class="grid">
        <?php foreach ($recentlyViewed as $gallery): ?>
            <?php $cover = $gallery['first_photo'] ?? null; $galleryCategories = $cardCovers['categories'][(int) $gallery['id']] ?? []; require __DIR__ . '/../partials/gallery_card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="favorites-section">
    <div class="favorites-heading"><h2>Saved searches</h2><a href="<?= e(url('/galleries')) ?>">Search galleries</a></div>
    <?php if (empty($savedSearches)): ?>
        <div class="empty-state"><p>You have no saved searches.</p><a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Create one</a></div>
    <?php else: ?>
        <div class="saved-search-list">
            <?php foreach ($savedSearches as $saved): ?>
                <?php $filters = $saved['filters']; $params = array_filter(['q' => $filters['q'], 'category' => $filters['category'] ?: null, 'type' => $filters['type'], 'sort' => $filters['sort']], static fn ($value) => $value !== null && $value !== ''); ?>
                <div class="saved-search card">
                    <a href="<?= e(url('/galleries') . '?' . http_build_query($params)) ?>"><strong><?= e($filters['q']) ?></strong><span><?= e(ucfirst($filters['type'] ?: 'all')) ?><?= $filters['sort'] ? ' · ' . e(ucfirst($filters['sort'])) : '' ?></span></a>
                    <form method="post" action="<?= e(url('/saved-searches/' . (int) $saved['id'] . '/delete')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline" type="submit">Remove</button></form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
