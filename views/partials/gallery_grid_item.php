<?php if (is_video($photo['filename'])): ?>
    <figure class="gallery-item">
        <a class="video-open" data-gallery-index="<?= $idx ?>" href="<?= e(url('/videos/' . (int) $photo['id']) . '?' . http_build_query(['return_to' => $returnTo])) ?>" title="Play item <?= $idx + 1 ?> of <?= $total ?>">
            <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="Video thumbnail for item <?= $idx + 1 ?>" loading="lazy" decoding="async">
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
    <figure class="gallery-item">
        <a class="grid-link" data-gallery-index="<?= $idx ?>" href="<?= e(url('/images/' . (int) $photo['id']) . '?' . http_build_query(['return_to' => $returnTo])) ?>" title="View item <?= $idx + 1 ?> of <?= $total ?>">
            <picture>
                <source type="image/webp" srcset="<?= e(file_url($photo['filename'], 'thumb', 'webp')) ?>">
                <source type="image/jpeg" srcset="<?= e(file_url($photo['filename'], 'thumb')) ?>">
                <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>"
                     data-lightbox="<?= e(file_url($photo['filename'])) ?>"
                     data-lightbox-caption="<?= e($photo['caption']) ?>"
                     alt="<?= e($photo['caption']) ?>"
                     loading="lazy" decoding="async" sizes="(max-width: 640px) 100vw, (max-width: 1000px) 50vw, 220px">
            </picture>
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
