<?php $title = $gallery['title']; ?>
<?php
$galleryDescription = trim((string) ($gallery['description'] ?? ''));
$metaDescription = $galleryDescription !== ''
    ? $galleryDescription
    : 'Browse "' . $title . '" — ' . number_format((int) ($total ?? 0)) . ' items on ' . config('app.site_name') . '.';
$canonicalUrl = absolute_url('/galleries/' . (int) $gallery['id']);
$ogImage = isset($photos[0]['filename']) && $photos[0]['filename'] !== '' ? file_url($photos[0]['filename'], 'web') : '';
$ldJson = [
    '@context' => 'https://schema.org',
    '@type'    => 'CollectionPage',
    'name'     => $title,
    'url'      => $canonicalUrl,
    'description' => $galleryDescription !== '' ? $galleryDescription : $metaDescription,
    'isPartOf' => ['@type' => 'WebSite', 'name' => config('app.site_name'), 'url' => absolute_url('')],
];
?>
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

<?php if ($canViewFull && !empty($collections)): ?>
    <form method="post" action="<?= url('/collections/' . (int) $collections[0]['id'] . '/galleries') ?>" class="settings-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="gallery_id" value="<?= (int) $gallery['id'] ?>">
        <label class="muted" style="font-size:.9rem;">Save to collection</label>
        <select name="collection_id" onchange="this.form.action='<?= url('/collections') ?>/'+this.value+'/galleries'">
            <?php foreach ($collections as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline">Add to collection</button>
        <a href="<?= url('/collections') ?>" class="muted" style="font-size:.85rem;">Manage collections</a>
    </form>
<?php elseif ($canViewFull): ?>
    <p class="muted" style="font-size:.9rem;margin-bottom:.75rem;">
        <a href="<?= url('/collections') ?>">Create a collection</a> to save this gallery.
    </p>
<?php endif; ?>

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
<?php elseif (!$canViewFull): ?>
    <?php // Blurred, indexable preview for guests and below-level members; the
        // full grid and its lightbox stay behind the membership gate. ?>
    <div class="grid">
        <?php foreach ($photos as $idx => $photo): ?>
            <figure class="gallery-item">
                <a class="grid-link" href="<?= e(url((is_video($photo['filename']) ? '/videos/' : '/images/') . (int) $photo['id'])) ?>">
                    <img src="<?= e(file_url($photo['filename'], 'blur')) ?>"
                         alt="<?= e($photo['caption']) ?>" loading="lazy" decoding="async">
                </a>
                <figcaption>
                    <?php if ($photo['link'] !== ''): ?>
                        <a href="<?= e($photo['link']) ?>" rel="noopener"><?= e($photo['caption']) ?></a>
                    <?php else: ?>
                        <?= e($photo['caption']) ?>
                    <?php endif; ?>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
    <?php
    $gateTitle = $gallery['title'];
    $gateLevel = (int) ($gallery['min_level'] ?? 0);
    $gateMedia = 'gallery';
    require __DIR__ . '/../partials/membership_gate.php';
    ?>
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
