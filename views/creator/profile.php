<?php
// The title must NOT carry the site name: the layout appends it, and
// this one appended it too - "Amethyst - amethyst2213 - amethyst2213".
$title = ($profile['display_name'] ?: 'About the creator');
$canonicalUrl = absolute_url('/creator');
$metaDescription = trim((string) ($profile['tagline'] ?? '')) !== ''
    ? (string) $profile['tagline'] . ' — ' . config('app.site_name')
    : (string) config('app.site_name') . ' — curated galleries of original photos and videos.';
$ogType = 'profile';
?>

<div class="favorites-hero">
    <p class="eyebrow">Meet the creator</p>
    <h1><?= e($profile['display_name'] ?: 'About') ?></h1>
    <?php if (!empty($profile['tagline'])): ?>
        <p class="muted"><?= e($profile['tagline']) ?></p>
    <?php endif; ?>
</div>

<section class="favorites-section">
    <?php if (!empty($profile['avatar'])): ?>
        <?php /* Only a safe URL is ever rendered (the model filters it too).
               The avatar is either a full https:// URL or a stored filename. */ ?>
        <?php $avatarUrl = \App\Models\SiteProfile::safeUrl((string) $profile['avatar'], ['https']);
        $avatarUrl = $avatarUrl === ''
            ? ''
            : (preg_match('#^https://#i', $avatarUrl) ? $avatarUrl : file_url($avatarUrl)); ?>
        <img src="<?= e($avatarUrl) ?>" alt="" style="max-width:180px;border-radius:12px;margin-bottom:1rem;">
    <?php endif; ?>

    <?php if ($profile['bio'] !== ''): ?>
        <p style="white-space:pre-line;"><?= e($profile['bio']) ?></p>
    <?php else: ?>
        <p class="muted">This creator hasn&rsquo;t written their bio yet.</p>
    <?php endif; ?>

    <?php if (!empty($profile['links'])): ?>
        <div class="chips u-mt-1">
            <?php foreach ($profile['links'] as $link): ?>
                <a class="chip" href="<?= e(\App\Models\SiteProfile::safeUrl((string) $link['url'])) ?>" rel="noopener nofollow" target="_blank"><?= e($link['label']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="u-mt-15">
        <a class="btn" href="<?= url('/galleries') ?>">Browse the galleries</a>
    </div>
</section>