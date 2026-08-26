<?php $title = config('app.site_name'); ?>

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
$imgUrl = $base . '?' . http_build_query(array_merge($query, ['type' => 'images']));
$vidUrl = $base . '?' . http_build_query(array_merge($query, ['type' => 'videos']));
?>

<div class="hero">
    <form method="get" action="<?= url('/galleries') ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search galleries by title, description or category…">
        <button type="submit" class="btn">Search</button>
    </form>
</div>

<div class="chips" style="justify-content:center">
    <a class="chip <?= $type === '' ? 'active' : '' ?>" href="<?= e($allUrl) ?>">All Galleries</a>
    <a class="chip <?= $type === 'images' ? 'active' : '' ?>" href="<?= e($imgUrl) ?>">&#128444; Image Galleries</a>
    <a class="chip <?= $type === 'videos' ? 'active' : '' ?>" href="<?= e($vidUrl) ?>">&#9654; Video Galleries</a>
</div>

<?php require __DIR__ . '/../partials/gallery_display_bar.php'; ?>

<?php if ($q === ''): ?>
<?php // Full listing: every gallery grouped by category, unpaginated, never filtered by favourites. ?>
<?php if (empty($sections)): ?>
    <div class="empty-state">
        <p class="muted">No galleries yet.</p>
        <a class="btn btn-sm" href="<?= e(url('/support')) ?>">Contact support</a>
    </div>
<?php else: ?>
    <?php foreach ($sections as $section): ?>
        <section class="fav-section">
            <h2>
                <?= e($section['category']['name']) ?>
                <?php if (!empty($section['category']['slug'])): ?>
                    <a href="<?= url('/galleries/category/' . e($section['category']['slug']) . ($type !== '' ? '?type=' . $type : '')) ?>">View all &rarr;</a>
                <?php endif; ?>
            </h2>
            <div class="grid">
                <?php foreach ($section['galleries'] as $gallery): ?>
                    <?php
                    $gid = (int) $gallery['id'];
                    $cover = $cardCovers['covers'][$gid] ?? null;
                    $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                    require __DIR__ . '/../partials/gallery_card.php';
                    ?>
                <?php endforeach; ?>
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
            <p>No galleries found.</p>
            <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse all galleries</a>
        </div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($paginator['items'] as $gallery): ?>
                <?php
                $gid = (int) $gallery['id'];
                $cover = $cardCovers['covers'][$gid] ?? null;
                $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                require __DIR__ . '/../partials/gallery_card.php';
                ?>
            <?php endforeach; ?>
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
