<?php $title = $gallery['title']; ?>

<?php // Back link so the gallery page never dead-ends. ?>
<a href="<?= url('/galleries') ?>">&larr; All galleries</a>

<h1><?= e($gallery['title']) ?></h1>
<p><?= e($gallery['description']) ?></p>
<p class="muted"><?= number_format((int) ($gallery['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($gallery['unique_views'] ?? 0)) ?> unique viewers</p>

<?php // Category chips linking to the matching filtered listing. ?>
<?php if (!empty($categories)): ?>
    <div class="chips">
        <?php foreach ($categories as $cat): ?>
            <a class="chip" href="<?= url('/galleries/category/' . e($cat['slug'])) ?>"><?= e($cat['name']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($photos)): ?>
    <p>No photos in this gallery yet.</p>
<?php else: ?>
    <div class="grid" id="gallery">
        <?php foreach ($photos as $photo): ?>
            <?php // Every item opens its own in-page viewer with Previous/Next
                 // navigation and a button back to this gallery. ?>
            <?php if (is_video($photo['filename'])): ?>
                <figure class="gallery-item">
                    <a class="video-open" href="<?= url('/videos/' . (int) $photo['id']) ?>" title="Play video">
                        <video src="<?= e(file_url($photo['filename'])) ?>" preload="metadata"></video>
                        <span class="play-badge">&#9654;</span>
                    </a>
                    <figcaption>
                        <?php if ($photo['link'] !== ''): ?>
                            <a href="<?= e($photo['link']) ?>" rel="noopener"><?= e($photo['caption']) ?></a>
                        <?php else: ?>
                            <?= e($photo['caption']) ?>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            <?php else: ?>
                <?php // The grid shows the fast-loading web variant; clicking
                     // opens the full-size page at its original resolution. ?>
                <figure class="gallery-item">
                    <a class="grid-link" href="<?= url('/images/' . (int) $photo['id']) ?>" title="View full size">
                        <img src="<?= e(file_url($photo['filename'], 'web')) ?>" alt="<?= e($photo['caption']) ?>" loading="lazy">
                    </a>
                    <figcaption>
                        <?php if ($photo['link'] !== ''): ?>
                            <a href="<?= e($photo['link']) ?>" rel="noopener"><?= e($photo['caption']) ?></a>
                        <?php else: ?>
                            <?= e($photo['caption']) ?>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
