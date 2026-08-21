<?php $title = $category['name']; ?>


<h1 class="section-title">Category: <?= e($category['name']) ?></h1>

<?php // Text search scoped to just this category; it narrows the type sections. ?>
<div class="hero" style="padding:1.25rem 1.5rem; text-align:left; margin-top:1rem;">
    <form method="get" action="<?= url('/galleries/category/' . e($category['slug'])) ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search within this category…">
        <button type="submit" class="btn">Search</button>
    </form>
</div>

<?php
// Build the type-filter links (All / Images / Videos), preserving the active
// search so switching a chip never loses the query.
$base = url('/galleries/category/' . e($category['slug']));
$query = $q !== '' ? ['q' => $q] : [];
$allUrl = $query ? $base . '?' . http_build_query($query) : $base;
$imgUrl = $base . '?' . http_build_query(array_merge($query, ['type' => 'images']));
$vidUrl = $base . '?' . http_build_query(array_merge($query, ['type' => 'videos']));
?>

<div class="chips" style="justify-content:center">
    <a class="chip <?= $type === '' ? 'active' : '' ?>" href="<?= e($allUrl) ?>">All Galleries</a>
    <a class="chip <?= $type === 'images' ? 'active' : '' ?>" href="<?= e($imgUrl) ?>">&#128444; Image Galleries</a>
    <a class="chip <?= $type === 'videos' ? 'active' : '' ?>" href="<?= e($vidUrl) ?>">&#9654; Video Galleries</a>
</div>

<?php
// The search query (if any) and the active type filter apply to the sections.
$paginationQuery = $q !== '' ? ['q' => $q] : [];
if ($type !== '') {
    $paginationQuery['type'] = $type;
}
$searchSuffix = $q !== '' ? ' match your search' : '';
$sectionBaseUrl = url('/galleries/category/' . e($category['slug']));
if ($paginationQuery) {
    $sectionBaseUrl .= '?' . http_build_query($paginationQuery);
}
?>

<?php if ($type === '' || $type === 'images'): ?>
<section class="fav-section">
    <h2>Image Galleries</h2>
    <?php if (empty($imagePaginator['items'])): ?>
        <p class="muted">No image galleries in this category<?= $searchSuffix ?>.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($imagePaginator['items'] as $gallery): ?>
                <?php
                $gid = (int) $gallery['id'];
                $cover = $cardCovers['covers'][$gid] ?? null;
                $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                require __DIR__ . '/../partials/gallery_card.php';
                ?>
            <?php endforeach; ?>
        </div>
        <?php
        // Reuse the pagination partial for this section's own pages.
        $baseUrl = $sectionBaseUrl;
        $paginator = $imagePaginator;
        require __DIR__ . '/../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($type === '' || $type === 'videos'): ?>
<section class="fav-section">
    <h2>&#9654; Video Galleries</h2>
    <?php if (empty($videoPaginator['items'])): ?>
        <p class="muted">No video galleries in this category<?= $searchSuffix ?>.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($videoPaginator['items'] as $gallery): ?>
                <?php
                $gid = (int) $gallery['id'];
                $cover = $cardCovers['covers'][$gid] ?? null;
                $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                require __DIR__ . '/../partials/gallery_card.php';
                ?>
            <?php endforeach; ?>
        </div>
        <?php
        // Reuse the pagination partial for this section's own pages.
        $baseUrl = $sectionBaseUrl;
        $paginator = $videoPaginator;
        require __DIR__ . '/../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
<?php endif; ?>
