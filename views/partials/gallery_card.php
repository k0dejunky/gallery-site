<?php
// The cover and the categories come from the caller (Gallery::preloadCards):
// a per-card query here cost the guest home page 24 queries for 12 cards.
$cover = $cover ?? null;
// The gate is on the CONTENT, not on the discovery: a gallery the viewer
// cannot open is shown BLURRED with its lock, whether the viewer is a guest
// (a safe gallery at any level, or an adult one in the free tier, is open
// to them) or a member whose membership is below the gallery's level. The
// search needs this: it reaches every category, so the gated galleries have
// to be visible as teasers instead of vanishing.
//
// The blurred teaser is PUBLIC (the files route serves it without a token),
// so it needs no token and the card markup stays cacheable.
$isGuest      = !\App\Core\Auth::check();
$memberLevel  = \App\Core\Auth::effectiveLevel();
$galleryLevel = \App\Models\Gallery::effectiveLevel($gallery);
$canOpen      = $isGuest
    ? \App\Models\Gallery::guestViewable($gallery)
    : ($memberLevel >= $galleryLevel);
$coverSize    = $canOpen ? 'thumb' : 'blur';
$isAdult      = (int) ($gallery['is_nsfw'] ?? 1) === 1;
$coverUrl     = static function (string $size, string $format = '') use ($cover, $canOpen, $isGuest): string {
    if ($size === 'blur' || ($isGuest && $canOpen)) {
        return public_file_url((string) $cover['filename'], $size, $format);
    }

    return file_url((string) $cover['filename'], $size, $format);
};
$galleryCategories = $galleryCategories ?? [];
$galleryUrl  = url('/galleries/' . (int) $gallery['id']) . (!empty($listingReturnTo) ? '?' . http_build_query(['return_to' => $listingReturnTo]) : '');
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
$cardId = 'card-' . (int) $gallery['id'];
// A gallery the member cannot open yet says so on the card, instead of
// letting them click through to a blurred wall and an upgrade prompt.
// Two cases: the gallery is above the member's level, or it is pay-per-view
// (everyone sees the teaser; the full set needs a code or a purchase).
// A safe gallery is open to everyone regardless of its stored level, so the
// lock badge must use the EFFECTIVE level or it would advertise a paywall on
// a gallery the visitor can already open.
$isLocked    = $memberLevel < PHP_INT_MAX && $galleryLevel > $memberLevel;
$lockLevel   = $galleryLevel;
$coverTitle  = $canOpen
    ? ''
    : ($isGuest
        ? 'Members-only content'
        : 'Needs a ' . \App\Models\Subscription::levelLabel($lockLevel) . ' membership');
$ppvPrice    = (float) ($gallery['ppv_price'] ?? 0) > 0 ? (float) $gallery['ppv_price'] : null;
// First card on a page gets high fetch priority and eager loading (it is the
// LCP element); all others lazy-load. Reserve the 400x300 cover ratio so the
// browser can lay out the grid without the "jump" caused by late image sizes.
$heroCard = (($coverFetchPriority ?? '') === 'high') || \App\Core\PageCache::claimHero();
$fetchPriorityAttr  = $heroCard ? ' fetchpriority="high"' : '';
$lazyLoadAttr       = $heroCard ? '' : ' loading="lazy"';
$asyncDecodeAttr    = $heroCard ? '' : ' decoding="async"';
?>
<div class="card card-compact" id="<?= e($cardId) ?>">
    <a class="card-link" href="<?= e($galleryUrl) ?>">
        <?php if ($cover === null): ?>
            <div class="card-cover">
                <svg class="card-placeholder" viewBox="0 0 400 300" role="img" aria-label="No photos yet">
                    <rect width="400" height="300" fill="var(--pink-100)"/>
                    <rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="var(--pink-400)" stroke-width="8"/>
                    <circle cx="185" cy="145" r="14" fill="var(--pink-400)"/>
                    <path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="var(--purple-400)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php if ($isViewed): ?>
                    <span class="viewed-badge" title="You have seen this gallery" aria-hidden="true">&#10003;</span>
                    <span class="sr-only">Already viewed</span>
                <?php endif; ?>
                <?php if ($isAdult): ?><span class="nsfw-badge" title="Adult content">18+</span><span class="sr-only">Adult content</span><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card-cover">
                <?php if ($isVideoCard): ?>
                    <img src="<?= e($coverUrl($coverSize)) ?>" alt="<?= e(\App\Models\Photo::altText($cover, (string) ($gallery['title'] ?? ''))) ?>" width="400" height="300"<?= $fetchPriorityAttr ?><?= $lazyLoadAttr ?> data-fallback="<?= e($placeholder) ?>">
                <?php elseif ($coverSize === 'blur'): ?>
                    <?php // Guests (and search engines) only ever see the blurred
                        // preview cover; the real thumbnail is for members. The
                        // blurred cover gets the same modern formats as the
                        // member's thumbnail, so the guest grid is not the one
                        // surface that is stuck with plain JPEG. ?>
                    <picture>
                        <source type="image/avif" srcset="<?= e(public_file_url($cover['filename'], 'blur', 'avif')) ?>">
                        <source type="image/webp" srcset="<?= e(public_file_url($cover['filename'], 'blur', 'webp')) ?>">
                        <source type="image/jpeg" srcset="<?= e(public_file_url($cover['filename'], 'blur')) ?>">
                        <img src="<?= e(public_file_url($cover['filename'], 'blur')) ?>" alt="<?= e(\App\Models\Photo::altText($cover, (string) ($gallery['title'] ?? ''))) ?>" width="400" height="300"<?= $fetchPriorityAttr ?><?= $lazyLoadAttr ?><?= $asyncDecodeAttr ?> data-fallback="<?= e($placeholder) ?>"<?= $coverTitle !== '' ? ' title="' . e($coverTitle) . '"' : '' ?>>
                    </picture>
                <?php else: ?>
                    <picture>
                        <source type="image/avif" srcset="<?= e($coverUrl('thumb', 'avif')) ?>">
                        <source type="image/webp" srcset="<?= e($coverUrl('thumb', 'webp')) ?>">
                        <source type="image/jpeg" srcset="<?= e($coverUrl('thumb')) ?>">
                        <img src="<?= e($coverUrl('thumb')) ?>" srcset="<?= e(file_srcset($cover['filename'], !($isGuest && $canOpen))) ?>" sizes="(min-width: 800px) 400px, 100vw" alt="<?= e(\App\Models\Photo::altText($cover, (string) ($gallery['title'] ?? ''))) ?>" width="400" height="300"<?= $fetchPriorityAttr ?><?= $lazyLoadAttr ?><?= $asyncDecodeAttr ?> data-fallback="<?= e($placeholder) ?>">
                    </picture>
                <?php endif; ?>
                <?php if ($isVideoCard): ?><span class="video-badge" aria-hidden="true">&#9654;</span><?php endif; ?>
                <?php if ($isViewed): ?>
                    <span class="viewed-badge" title="You have seen this gallery" aria-hidden="true">&#10003;</span>
                    <span class="sr-only">Already viewed</span>
                <?php endif; ?>
                <?php if ($isAdult): ?><span class="nsfw-badge" title="Adult content">18+</span><span class="sr-only">Adult content</span><?php endif; ?>
            </div>
        <?php endif; ?>
        <h2><?= e($gallery['title']) ?></h2>
        <?php if ($isLocked): ?>
            <p class="card-lock-line">
                <span class="card-lock"><?= e(\App\Models\Subscription::levelLabel($lockLevel)) ?></span>
                <a class="card-lock-cta" href="<?= e(url('/membership')) ?>">View membership</a>
            </p>
        <?php elseif ($ppvPrice !== null): ?>
            <p class="card-lock-line">
                <span class="card-lock">Pay to unlock</span>
                <span class="card-lock-price">$<?= e(number_format($ppvPrice, 2)) ?></span>
            </p>
        <?php endif; ?>
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
    <div class="card-details" id="<?= e($cardId) ?>-details" hidden>
        <?php if (($gallery['description'] ?? '') !== ''): ?>
            <p class="card-desc"><?= e($gallery['description']) ?></p>
        <?php endif; ?>
        <?php if ($createdAt !== false): ?>
            <p class="muted card-date">Added <?= e(tzdate('F j, Y', $createdAt)) ?></p>
        <?php endif; ?>
        <div class="card-cats">
            <?php foreach ($galleryCategories as $cat): ?>
                <a class="chip" href="<?= url('/galleries/category/' . e($cat['slug'])) ?>"><?= e($cat['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <button class="card-expand-btn" type="button" data-target="<?= e($cardId) ?>-details" aria-expanded="false" aria-controls="<?= e($cardId) ?>-details">Show more</button>
    <?php endif; ?>
    <?php if (!empty($currentUser) && !empty($hasActive)): ?>
        <button type="button" class="btn btn-sm btn-outline favorite-toggle<?= $isFavorite ? ' is-favorite' : '' ?>"
                data-gallery-id="<?= (int) $gallery['id'] ?>"
                data-csrf="<?= e(\App\Core\Csrf::token()) ?>"
                aria-label="<?= $isFavorite ? 'Unfavorite' : 'Favorite' ?> gallery">
            <?= $isFavorite ? '&#9733; Unfavorite' : '&#9734; Favorite' ?>
        </button>
    <?php endif; ?>
</div>
