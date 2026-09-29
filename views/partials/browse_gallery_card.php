<?php
// Compact gallery card for the player's in-page browser (no layout). Clicking
// the card queues the gallery's first video into the playlist.
$cover = $cover ?? null;
$gid   = (int) $gallery['id'];
$isVideoGallery = (int) ($gallery['type'] ?? 'images') === 1 || ($gallery['type'] ?? '') === 'videos';
$firstVideo = $firstVideo ?? null;
?>
<div class="card card-compact browse-gallery-card">
    <a class="card-link" data-browse-gallery="<?= $gid ?>" href="#"
       title="<?= e((string) $gallery['title']) ?>"
       <?php if ($firstVideo !== null): ?>
       data-video-id="<?= (int) $firstVideo['id'] ?>"
       data-video-title="<?= e((string) ($firstVideo['caption'] ?? '')) ?>"
       data-video-thumb="<?= e(file_url((string) $firstVideo['filename'], 'thumb')) ?>"
       data-video-web="<?= e(file_url((string) $firstVideo['filename'], 'web')) ?>"
       data-video-url="<?= e(url('/videos/' . (int) $firstVideo['id'])) ?>"
       <?php endif; ?>>
        <div class="card-cover">
            <?php if ($cover !== null): ?>
                <img src="<?= e(file_url((string) $cover['filename'], 'thumb')) ?>" alt="" loading="lazy">
            <?php else: ?>
                <div class="card-placeholder" style="display:grid;place-items:center;height:100%;color:var(--purple-600);font-size:1.8rem;">&#128247;</div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <h3 style="margin:.4rem 0 .15rem;font-size:.95rem;"><?= e((string) $gallery['title']) ?></h3>
            <p class="muted" style="margin:0;font-size:.8rem;"><?= (int) ($gallery['photo_count'] ?? 0) ?> item<?= (int) ($gallery['photo_count'] ?? 0) === 1 ? '' : 's' ?></p>
        </div>
    </a>
</div>