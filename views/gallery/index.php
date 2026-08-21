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

<?php if ($q === ''): ?>
<?php // Home view: one "favourite category" section per category, only when that category has galleries. ?>
<?php if (empty($favorites)): ?>
    <p class="muted">You have no favorite categories yet. Pick some in <a href="<?= url('/settings') ?>">Settings</a>.</p>
<?php elseif (empty($sections)): ?>
    <p class="muted">No galleries in your favorite categories yet.</p>
<?php else: ?>
    <?php foreach ($sections as $section): ?>
        <section class="fav-section">
            <h2>
                &starf; <?= e($section['category']['name']) ?>
                <?php // "View all" keeps the current type filter applied. ?>
                <a href="<?= url('/galleries/category/' . e($section['category']['slug']) . ($type !== '' ? '?type=' . $type : '')) ?>">View all &rarr;</a>
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
<?php endif; ?>

<?php if ($q !== ''): ?>
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

    <?php if (empty($paginator['items'])): ?>
        <p>No galleries found.</p>
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
        $query   = ['q' => $q];
        if ($categoryId > 0) {
            $query['category'] = $categoryId;
        }
        if ($type !== '') {
            $query['type'] = $type;
        }
        require __DIR__ . '/../partials/pagination.php';
        ?>
    <?php endif; ?>
    </section>
    <?php endif; ?>
