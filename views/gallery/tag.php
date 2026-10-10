<?php $title = $tag['name']; ?>

<?php
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => '#' . $tag['name']],
];
require __DIR__ . '/../partials/breadcrumbs.php';
?>

<h1>#<?= e($tag['name']) ?></h1>
<p class="muted">Galleries tagged &ldquo;<?= e($tag['name']) ?>&rdquo;.</p>

<?php if (empty($galleries)): ?>
    <div class="empty-state">
        <p class="muted">No galleries use this tag yet.</p>
        <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse all galleries</a>
    </div>
<?php else: ?>
    <div class="grid">
        <?php echo \App\Core\PageCache::fragment('tag.' . md5(json_encode([$tag['id'] ?? 0, array_column($galleries, 'id')])), 120, static function () use ($galleries): void {
            foreach ($galleries as $gallery) {
                $cover = null;
                $galleryCategories = null;
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