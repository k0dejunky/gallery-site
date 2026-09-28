<?php
// In-page video player rendered inside the site template, with Previous/Next
// navigation through the gallery and a button back to it.
$title = $photo['caption'] !== '' ? $photo['caption'] : ($gallery !== null ? $gallery['title'] : 'Video');
$src   = file_url($photo['filename'], 'web');
$reportUrl = url('/support') . '?' . http_build_query(['return_to' => $_SERVER['REQUEST_URI'] ?? url('/galleries')]);
?>
<?php require __DIR__ . '/../partials/media_nav.php'; ?>

<figure id="video-player-wrap" style="margin: 1rem 0; text-align: center;">
    <p class="media-progress" role="status">Item <?= (int) ($currentIndex + 1) ?> of <?= (int) ($mediaCount ?? 1) ?></p>
    <div class="gallery-player" data-player>
        <video id="gallery-video-<?= (int) $photo['id'] ?>" data-video-id="<?= (int) $photo['id'] ?>" src="<?= e($src) ?>" controls preload="metadata" playsinline aria-label="<?= e($title) ?>"></video>
    </div>
    <div class="video-resume" hidden role="status">Resume from <span class="video-resume-time">0:00</span> <button type="button" class="btn btn-sm">Resume</button></div>
    <figcaption class="muted" style="margin-top: 0.5rem">
        <?php if ($photo['caption'] !== ''): ?>
            <span><?= e($photo['caption']) ?></span><br>
        <?php endif; ?>
        <span><?= number_format((int) ($photo['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($photo['unique_views'] ?? 0)) ?> unique</span>
    </figcaption>
</figure>
<p style="text-align:center"><a href="<?= e($reportUrl) ?>">Report broken media</a></p>
