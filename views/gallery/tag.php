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
        <?php foreach ($galleries as $gallery): ?>
            <?php require __DIR__ . '/../partials/gallery_card.php'; ?>
        <?php endforeach; ?>
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