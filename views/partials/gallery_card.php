<?php
// Card cover: the gallery's first photo. Images and videos both open the
// gallery page so mixed media is rendered dynamically in the current tab.
// Callers may pre-set $cover and $galleryCategories to avoid N+1 queries.
$cover = $cover ?? \App\Models\Gallery::firstPhoto((int) $gallery['id']);
$galleryCategories = $galleryCategories ?? \App\Models\Gallery::categories((int) $gallery['id']);
$galleryUrl  = url('/galleries/' . (int) $gallery['id']);
$isVideoCard = $cover !== null && is_video($cover['filename']);
// Inline SVG fallback shown when a thumbnail is missing or fails to load,
// so cards never render with a broken image icon.
$placeholder = 'data:image/svg+xml;utf8,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#ffd9e8"/><rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="#f472b6" stroke-width="8"/><circle cx="185" cy="145" r="14" fill="#ec4899"/><path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="#9333ea" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);
?>
<div class="card">
    <a class="card-link" href="<?= e($galleryUrl) ?>">
        <?php if ($cover === null): ?>
            <svg class="card-placeholder" viewBox="0 0 400 300" role="img" aria-label="No photos yet">
                <rect width="400" height="300" fill="var(--pink-100)"/>
                <rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="var(--pink-400)" stroke-width="8"/>
                <circle cx="185" cy="145" r="14" fill="var(--pink-400)"/>
                <path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="var(--purple-400)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        <?php else: ?>
            <div class="card-cover">
                <img src="<?= e(file_url($cover['filename'], 'thumb')) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= e($placeholder) ?>'">
                <?php if ($isVideoCard): ?><span class="video-badge">&#9654;</span><?php endif; ?>
            </div>
        <?php endif; ?>
        <h2><?= e($gallery['title']) ?></h2>
        <p><?= e($gallery['description']) ?></p>
        <p class="muted" style="margin: 0.25rem 0 0; font-size: 0.85rem;"><?= number_format((int) ($gallery['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($gallery['unique_views'] ?? 0)) ?> unique</p>
    </a>
    <div class="card-cats">
        <?php foreach ($galleryCategories as $cat): ?>
            <a class="chip" href="<?= url('/galleries/category/' . e($cat['slug'])) ?>"><?= e($cat['name']) ?></a>
        <?php endforeach; ?>
    </div>
    <button class="card-cats-toggle" type="button" hidden>Show more</button>
</div>
