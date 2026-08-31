<?php $title = $gallery['title']; ?>
<?php
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => $gallery['title']],
];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>

<h1><?= e($gallery['title']) ?></h1>
<p><?= e($gallery['description']) ?></p>
<p class="muted"><?= number_format((int) ($gallery['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($gallery['unique_views'] ?? 0)) ?> unique viewers &middot; <?= number_format((int) $total) ?> items</p>

<?php if (!empty($categories)): ?>
    <div class="chips">
        <?php foreach ($categories as $cat): ?>
            <a class="chip" href="<?= url('/galleries/category/' . e($cat['slug'])) ?>"><?= e($cat['name']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($photos)): ?>
    <div class="empty-state">
        <p class="muted">This gallery doesn&rsquo;t have any media yet.</p>
        <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse other galleries</a>
    </div>
<?php else: ?>
    <div class="grid" id="gallery" data-gallery-id="<?= (int) $gallery['id'] ?>"
         data-total="<?= (int) $total ?>" data-loaded="<?= count($photos) ?>"
         data-page-size="<?= (int) $pageSize ?>" data-return-to="<?= e($returnTo) ?>">
        <?php foreach ($photos as $idx => $photo): ?>
            <?php require __DIR__ . '/../partials/gallery_grid_item.php'; ?>
        <?php endforeach; ?>
    </div>
    <?php if ($total > count($photos)): ?>
        <div class="load-more-wrap" id="load-more-wrap">
            <span id="gallery-progress" class="muted" role="status"></span>
            <button type="button" class="btn" id="load-more-btn">Load more</button>
            <span id="load-more-state" class="muted" role="status" aria-live="polite"></span>
        </div>
    <?php endif; ?>
<?php endif; ?>
