<?php $title = ($profile['display_name'] ?: 'About the creator') . ' — ' . config('app.site_name'); ?>

<div class="favorites-hero">
    <p class="eyebrow">Meet the creator</p>
    <h1><?= e($profile['display_name'] ?: 'About') ?></h1>
    <?php if (!empty($profile['tagline'])): ?>
        <p class="muted"><?= e($profile['tagline']) ?></p>
    <?php endif; ?>
</div>

<section class="favorites-section">
    <?php if (!empty($profile['avatar'])): ?>
        <?php $avatarUrl = preg_match('#^https?://#i', (string) $profile['avatar'])
            ? $profile['avatar']
            : file_url((string) $profile['avatar']); ?>
        <img src="<?= e($avatarUrl) ?>" alt="" style="max-width:180px;border-radius:12px;margin-bottom:1rem;">
    <?php endif; ?>

    <?php if ($profile['bio'] !== ''): ?>
        <p style="white-space:pre-line;"><?= e($profile['bio']) ?></p>
    <?php else: ?>
        <p class="muted">This creator hasn&rsquo;t written their bio yet.</p>
    <?php endif; ?>

    <?php if (!empty($profile['links'])): ?>
        <div class="chips" style="margin-top:1rem;">
            <?php foreach ($profile['links'] as $link): ?>
                <a class="chip" href="<?= e($link['url']) ?>" rel="noopener nofollow" target="_blank"><?= e($link['label']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="margin-top:1.5rem;">
        <a class="btn" href="<?= url('/galleries') ?>">Browse the galleries</a>
    </div>
</section>