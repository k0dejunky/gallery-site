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
<p class="muted"><?= number_format((int) ($gallery['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($gallery['unique_views'] ?? 0)) ?> unique viewers &middot; <?= $photoCount ?? count($photos) ?> items</p>

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
    <div class="grid" id="gallery">
        <?php foreach ($photos as $idx => $photo): ?>
            <?php if (is_video($photo['filename'])): ?>
                <figure class="gallery-item">
                    <a class="video-open" data-gallery-index="<?= $idx ?>" href="<?= e(url('/videos/' . (int) $photo['id']) . '?' . http_build_query(['return_to' => $returnTo])) ?>" title="Play item <?= $idx + 1 ?> of <?= count($photos) ?>">
                        <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="Video thumbnail for item <?= $idx + 1 ?>" loading="lazy" decoding="async">
                        <span class="play-badge">&#9654;</span>
                    </a>
                    <figcaption>
                        <?php if ($photo['link'] !== ''): ?>
                            <a href="<?= e($photo['link']) ?>" rel="noopener"><?= e($photo['caption']) ?></a>
                        <?php else: ?>
                            <?= e($photo['caption']) ?>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            <?php else: ?>
                <figure class="gallery-item">
                    <a class="grid-link" data-gallery-index="<?= $idx ?>" href="<?= e(url('/images/' . (int) $photo['id']) . '?' . http_build_query(['return_to' => $returnTo])) ?>" title="View item <?= $idx + 1 ?> of <?= count($photos) ?>">
                        <picture>
                            <source type="image/webp" srcset="<?= e(file_url($photo['filename'], 'thumb', 'webp')) ?>">
                            <source type="image/jpeg" srcset="<?= e(file_url($photo['filename'], 'thumb')) ?>">
                            <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>"
                                 data-lightbox="<?= e(file_url($photo['filename'])) ?>"
                                 data-lightbox-caption="<?= e($photo['caption']) ?>"
                                 alt="<?= e($photo['caption']) ?>"
                                 loading="lazy" decoding="async" sizes="(max-width: 640px) 100vw, (max-width: 1000px) 50vw, 220px">
                        </picture>
                    </a>
                    <figcaption>
                        <?php if ($photo['link'] !== ''): ?>
                            <a href="<?= e($photo['link']) ?>" rel="noopener"><?= e($photo['caption']) ?></a>
                        <?php else: ?>
                            <?= e($photo['caption']) ?>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
