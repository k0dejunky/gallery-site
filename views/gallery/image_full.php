<?php
$galleryTitle = $gallery !== null ? (string) $gallery['title'] : '';
// NOT ONE of the 7,058 photos on production had a caption, so every
// image page was titled "Full-size image" and every image had no alt text.
// The gallery title is the meaningful fallback.
$photoAlt   = \App\Models\Photo::altText($photo, $galleryTitle);
$title      = $photo['caption'] !== '' ? $photo['caption'] : ($galleryTitle !== '' ? $galleryTitle : 'Full-size image');
$metaDescription = $photo['caption'] !== ''
    ? $photo['caption'] . ($galleryTitle !== '' ? ' — from "' . $galleryTitle . '" on ' . config('app.site_name') . '.' : '')
    : 'An image from ' . ($galleryTitle !== '' ? '"' . $galleryTitle . '"' : 'the gallery') . ' on ' . config('app.site_name') . '.';
$canonicalUrl = absolute_url('/images/' . (int) $photo['id']);
$ogImage = absolute_url(file_url($photo['filename'], 'thumb'));
$canDownloadFull = $canDownloadFull ?? true;
$publicMedia     = $publicMedia ?? false;
$ldJson = [
    '@context' => 'https://schema.org',
    '@type'    => 'ImageObject',
    'name'     => $title,
    'url'      => $canonicalUrl,
    // On a page a logged-out visitor may open, the schema URL must be the
    // PUBLIC web rendition (no session token) or a crawler cannot fetch it.
    // A full url: a relative one is "Invalid URL in field contentUrl" to
    // Search Console, and a crawler cannot fetch it either.
    'contentUrl' => absolute_url($publicMedia ? public_file_url($photo['filename'], 'web') : file_url($photo['filename'])),
    'caption'  => $photo['caption'],
    'description' => $metaDescription,
    'author'   => ['@type' => 'Organization', 'name' => config('app.site_name'), 'url' => absolute_url('')],
];
// Google's paywalled-content markup: without it a gated page looks like an
// empty page that is hiding its content behind a form (a "cloaking" pattern),
// which is exactly how Google classifies it. Declaring the paywall up front
// lets it index the page as subscription content instead.
if (empty($canViewFull)) {
    $ldJson['isAccessibleForFree'] = false;
    $ldJson['hasPart'] = [
        '@type' => 'WebPageElement',
        'isAccessibleForFree' => false,
        'cssSelector' => '.paywalled-content',
    ];
}
?>
<?php
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => 'Gallery', 'url' => url('/galleries/' . (int) ($gallery['id'] ?? 0))],
    ['label' => $photo['caption'] ?: 'Image'],
];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<?php if (!empty($canViewFull)): ?>
<?php require __DIR__ . '/../partials/media_nav.php'; ?>
<?php endif; ?>

<?php // Save this image to a collection (the viewer already loads them). ?>
<?php if (!empty($canViewFull) && !empty($collections)): ?>
<form method="post" action="<?= url('/collections/' . (int) $collections[0]['id'] . '/photos') ?>" class="settings-form player-save-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.25rem 0 .75rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
    <label class="muted u-fs-09">Save this image to a collection</label>
    <select name="collection_id" aria-label="Collection to save this image in" data-col-action="<?= url('/collections') ?>/" data-col-suffix="photos" data-action="collectionActionSelect">
        <?php foreach ($collections as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline">Add to collection</button>
    <a href="<?= url('/collections') ?>" class="muted" style="font-size:.85rem;">Manage collections</a>
</form>
<?php elseif (!empty($canViewFull)): ?>
<p class="muted" style="font-size:.9rem;margin:.25rem 0 .75rem;"><a href="<?= url('/collections') ?>">Create a collection</a> to save this image.</p>
<?php endif; ?>
<?php $reportUrl = url('/support') . '?' . http_build_query(['return_to' => $_SERVER['REQUEST_URI'] ?? url('/galleries')]); ?>

<figure class="paywalled-content" style="margin: 1rem 0; text-align: center;">
    <?php if (empty($canViewFull)): ?>
        <?php // Blurred, indexable preview for guests and below-level members. ?>
        <img src="<?= e(file_url($photo['filename'], 'blur')) ?>"
             alt="<?= e($photoAlt) ?>"
             decoding="async"
             style="max-width:100%;height:auto;border-radius:10px;box-shadow:0 2px 12px rgba(59,7,100,.35);">
        <?php
        $gateTitle = $photo['caption'] !== '' ? $photo['caption'] : 'This image';
        $gateLevel = 0;
        $gateMedia = 'image';
        require __DIR__ . '/../partials/membership_gate.php';
        ?>
    <?php else: ?>
    <p class="media-progress" role="status">Item <?= (int) ($currentIndex + 1) ?> of <?= (int) ($mediaCount ?? 1) ?></p>
    <?php
    // A logged-out visitor reads the public web rendition; the original (and
    // the "View full size" button that swaps it in) is for account holders.
    $webUrl  = $publicMedia ? public_file_url($photo['filename'], 'web') : file_url($photo['filename'], 'web');
    $webWebp = $publicMedia ? public_file_url($photo['filename'], 'web', 'webp') : file_url($photo['filename'], 'web', 'webp');
    ?>
    <img id="fullsize-img"
         src="<?= e($webUrl) ?>"
         data-web="<?= e($webUrl) ?>"
         data-web-webp="<?= e($webWebp) ?>"
         <?php if ($canDownloadFull): ?>data-full="<?= e(file_url($photo['filename'])) ?>"<?php endif; ?>
         decoding="async"
         fetchpriority="high"
         alt="<?= e($photoAlt) ?>"
         style="max-width: 100%; height: auto; border-radius: 10px; box-shadow: 0 2px 12px rgba(59, 7, 100, 0.35);">
    <figcaption class="muted" style="margin-top: 0.75rem">
        <?php if ($photo['caption'] !== ''): ?>
            <span><?= e($photo['caption']) ?></span><br>
        <?php endif; ?>
        <?php if ($canDownloadFull): ?>
            <button id="fullsize-toggle" class="btn" type="button">View full size</button>
        <?php endif; ?>
        <span>&middot; <?= number_format((int) ($photo['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($photo['unique_views'] ?? 0)) ?> unique</span>
    </figcaption>
    <?php endif; ?>
</figure>
<p style="text-align:center"><a href="<?= e($reportUrl) ?>">Report broken media</a></p>
<?php
$commentableType = 'photo';
$commentableId   = (int) $photo['id'];
require __DIR__ . '/../partials/comments.php';
?>
<script nonce="<?= csp_nonce() ?>">
    (function () {
        var img = document.getElementById('fullsize-img');
        var toggle = document.getElementById('fullsize-toggle');
        if (!img || !toggle) return;
        var webpSupported = null;
        function supportsWebP() {
            if (webpSupported !== null) return webpSupported;
            try {
                var c = document.createElement('canvas');
                webpSupported = c.toDataURL('image/webp').indexOf('data:image/webp') === 0;
            } catch (e) {
                webpSupported = false;
            }
            return webpSupported;
        }
        var full = false;
        toggle.addEventListener('click', function () {
            full = !full;
            if (full) {
                img.src = img.dataset.full;
            } else {
                img.src = img.dataset.web;
                if (img.dataset.webWebp && supportsWebP()) {
                    // Serve WebP when supported; otherwise the jpeg web copy.
                    img.src = img.dataset.webWebp;
                }
            }
            toggle.textContent = full ? 'Show smaller image' : 'View full size';
        });
    })();
</script>
