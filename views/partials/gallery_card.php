<?php
$cover = $cover ?? \App\Models\Gallery::firstPhoto((int) $gallery['id']);
$galleryCategories = $galleryCategories ?? \App\Models\Gallery::categories((int) $gallery['id']);
$galleryUrl  = url('/galleries/' . (int) $gallery['id']);
$isVideoCard = $cover !== null && is_video($cover['filename']);
$viewedIds   = $viewedIds ?? [];
$isViewed    = in_array((int) $gallery['id'], $viewedIds, true);
$favoriteGalleryIds = array_map('intval', (array) ($favoriteGalleryIds ?? []));
$isFavorite = in_array((int) $gallery['id'], $favoriteGalleryIds, true);
$photoCount  = (int) ($gallery['photo_count'] ?? 0);
$videoCount  = (int) ($gallery['video_count'] ?? 0);
$createdAt = !empty($gallery['created_at']) ? strtotime((string) $gallery['created_at']) : false;
$hasDetails = ($gallery['description'] ?? '') !== '' || $createdAt !== false || !empty($galleryCategories);
$placeholder = 'data:image/svg+xml;utf8,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#ffd9e8"/><rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="#f472b6" stroke-width="8"/><circle cx="185" cy="145" r="14" fill="#ec4899"/><path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="#9333ea" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);
?>
<div class="card card-compact">
    <a class="card-link" href="<?= e($galleryUrl) ?>">
        <?php if ($cover === null): ?>
            <div class="card-cover">
                <svg class="card-placeholder" viewBox="0 0 400 300" role="img" aria-label="No photos yet">
                    <rect width="400" height="300" fill="var(--pink-100)"/>
                    <rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="var(--pink-400)" stroke-width="8"/>
                    <circle cx="185" cy="145" r="14" fill="var(--pink-400)"/>
                    <path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="var(--purple-400)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php if ($isViewed): ?><span class="viewed-badge"></span><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card-cover">
                <img src="<?= e(file_url($cover['filename'], 'thumb')) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= e($placeholder) ?>'">
                <?php if ($isVideoCard): ?><span class="video-badge">&#9654;</span><?php endif; ?>
                <?php if ($isViewed): ?><span class="viewed-badge"></span><?php endif; ?>
            </div>
        <?php endif; ?>
        <h2><?= e($gallery['title']) ?></h2>
        <p class="muted card-media-count">
            <?php if ($photoCount > 0): ?><?= number_format($photoCount) ?> photo<?= $photoCount !== 1 ? 's' : '' ?><?php endif; ?>
            <?php if ($photoCount > 0 && $videoCount > 0): ?> &middot; <?php endif; ?>
            <?php if ($videoCount > 0): ?><?= number_format($videoCount) ?> video<?= $videoCount !== 1 ? 's' : '' ?><?php endif; ?>
            <?php if ($photoCount === 0 && $videoCount === 0): ?>
                <?= number_format((int) ($gallery['views'] ?? 0)) ?> views
            <?php endif; ?>
        </p>
    </a>
    <?php if ($hasDetails): ?>
    <div class="card-details" hidden>
        <?php if (($gallery['description'] ?? '') !== ''): ?>
            <p class="card-desc"><?= e($gallery['description']) ?></p>
        <?php endif; ?>
        <?php if ($createdAt !== false): ?>
            <p class="muted card-date">Added <?= e(date('F j, Y', $createdAt)) ?></p>
        <?php endif; ?>
        <div class="card-cats">
            <?php foreach ($galleryCategories as $cat): ?>
                <a class="chip" href="<?= url('/galleries/category/' . e($cat['slug'])) ?>"><?= e($cat['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <button class="card-expand-btn" type="button">Show more</button>
    <?php endif; ?>
    <?php if (!empty($currentUser) && !empty($hasActive)): ?>
        <form method="post" action="<?= url('/favorites/galleries/' . (int) $gallery['id'] . '/toggle') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/galleries') ?>">
            <button type="submit" class="btn btn-sm btn-outline favorite-toggle" aria-label="<?= $isFavorite ? 'Unfavorite' : 'Favorite' ?> gallery">
                <?= $isFavorite ? '&#9733; Unfavorite' : '&#9734; Favorite' ?>
            </button>
        </form>
    <?php endif; ?>
</div>
