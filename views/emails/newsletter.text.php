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
$ctaHref    = $subscriber ? absolute_url('/galleries') : absolute_url('/membership');
$ctaLabel   = $subscriber ? 'Open the gallery' : 'Become a member';
$imageSize  = $subscriber ? 'thumb' : 'blur';
?>
<?= e($siteName) ?> — New uploads<?= $count > 0 ? ' (' . $count . ' photo' . ($count === 1 ? '' : 's') . ')' : '' ?>
<?= str_repeat('=', 40) ?>


<?= e($subscriber
    ? 'A small sample of the latest uploads is waiting for you in the gallery.'
    : 'A blurred peek at what is new on the site. Become a member to see the real images.') ?>


<?php foreach ($samples as $photo): ?>
- View photo: <?= e(absolute_url(file_url((string) $photo['filename'], $imageSize))) ?>

<?php endforeach; ?>
<?= e($ctaLabel) ?>: <?= e($ctaHref) ?>


You are receiving this because you <?= $subscriber ? 'are a member of' : 'signed up on' ?> <?= e($siteName) ?>.
To stop these updates, open the unsubscribe link:
{{unsubscribe-url}}

&copy; <?= date('Y') ?> <?= e($siteName) ?>