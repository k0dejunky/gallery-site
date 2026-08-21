<?php
// In-page video player rendered inside the site template, with Previous/Next
// navigation through the gallery and a button back to it.
$title = $photo['caption'] !== '' ? $photo['caption'] : ($gallery !== null ? $gallery['title'] : 'Video');
$src   = file_url($photo['filename']);
?>
<?php require __DIR__ . '/../partials/media_nav.php'; ?>

<figure style="margin: 1rem 0; text-align: center;">
    <video src="<?= e($src) ?>" controls playsinline style="max-width: 100%; max-height: 72vh; border-radius: 10px; background: #000; box-shadow: 0 2px 12px rgba(59, 7, 100, 0.35);"></video>
    <figcaption class="muted" style="margin-top: 0.5rem">
        <?php if ($photo['caption'] !== ''): ?>
            <span><?= e($photo['caption']) ?></span><br>
        <?php endif; ?>
        <span><?= number_format((int) ($photo['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($photo['unique_views'] ?? 0)) ?> unique</span>
    </figcaption>
</figure>
