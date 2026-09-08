<?php
/**
 * Plain-text part of the newsletter digest (multipart/alternative). Kept as a
 * real template so headings and links mirror the HTML part without hard-coding
 * copy in the model.
 */
$subscriber = !empty($subscriber);
$samples    = isset($samples) ? (array) $samples : [];
$count      = max(0, (int) ($count ?? count($samples)));
$siteName   = (string) config('app.site_name');
$targetGalleryId = 0;
foreach ($samples as $photo) {
    if (!empty($photo['gallery_id'])) {
        $targetGalleryId = (int) $photo['gallery_id'];
        break;
    }
}
$ctaHref    = $subscriber
    ? absolute_url('/galleries/' . $targetGalleryId)
    : absolute_url('/membership');
$ctaLabel   = $subscriber ? 'Open the gallery' : 'Become a member';
?>
<?= e($siteName) ?> — New uploads<?= $count > 0 ? ' (' . $count . ' photo' . ($count === 1 ? '' : 's') . ')' : '' ?>
<?= str_repeat('=', 40) ?>


<?= e($subscriber
    ? 'A small sample of the latest uploads is waiting for you in the gallery.'
    : 'A blurred peek at what is new on the site. Become a member to see the real images.') ?>


<?php foreach ($samples as $photo): ?>
- <?= e($photo['filename']) ?> (in gallery): <?= e(absolute_url('/galleries/' . (int) ($photo['gallery_id'] ?? 0))) ?>

<?php endforeach; ?>
<?= e($ctaLabel) ?>: <?= e($ctaHref) ?>


You are receiving this because you <?= $subscriber ? 'are a member of' : 'signed up on' ?> <?= e($siteName) ?>.
To stop these updates, open the unsubscribe link:
{{unsubscribe-url}}

&copy; <?= date('Y') ?> <?= e($siteName) ?>