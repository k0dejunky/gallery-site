<?php
// Compact gallery card for the player's in-page browser (no layout).
$cover = $cover ?? null;
$gid   = (int) $gallery['id'];
$isVideoGallery = (int) ($gallery['type'] ?? 'images') === 1 || ($gallery['type'] ?? '') === 'videos';
?>
<div class="card card-compact browse-gallery-card">
    <a class="card-link" data-browse-gallery="<?= $gid ?>" href="#" title="<?= e((string) $gallery['title']) ?>">
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