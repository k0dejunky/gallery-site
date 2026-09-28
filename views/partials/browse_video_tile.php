<?php
// Compact video tile for the player's in-page browser (no layout). Carries
// the per-session web URL + token so clicking it can swap the player's video
// element directly (PiP keeps playing).
$vidWeb = file_url($photo['filename'], 'web');
$vidUrl = url('/videos/' . (int) $photo['id']);
?>
<figure class="gallery-item browse-video-tile">
    <a class="video-open" href="#"
       data-browse-video="<?= (int) $photo['id'] ?>"
       data-video-url="<?= e($vidUrl) ?>"
       data-video-web="<?= e($vidWeb) ?>"
       data-video-title="<?= e($photo['caption'] !== '' ? (string) $photo['caption'] : 'Video') ?>"
       data-video-thumb="<?= e(file_url($photo['filename'], 'thumb')) ?>"
       title="Play item">
        <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="" loading="lazy">
        <span class="play-badge">&#9654;</span>
    </a>
</figure>