<?php $title = $tag['name']; ?>
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