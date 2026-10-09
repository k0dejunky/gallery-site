<?php
/**
 * Shared login/signup layout: recent pictures on the left, the form panel in
 * the middle, recent videos on the right. Both auth pages use this shell so
 * they stay visually identical and only swap the form. Variables:
 *   $authFormFile  — path to the form partial to require
 *   $authHeroTitle — the hero heading text
 *   $authHeroText  — the hero description
 *   $authHeading   — the section heading (Login / Create an Account)
 *   $authLinksHtml — the footer link line (raw HTML)
 */
?>
<style>
    .auth-splash .auth-panel { flex: 0 1 auto; width: auto; max-width: 520px; }
    .auth-splash-side { flex: 1 1 300px; }
    .auth-panel .auth-hero { text-align: center; margin: 0 0 .5rem; }
    .auth-panel .auth-hero h1 { margin: 0 0 .35rem; font-size: 1.35rem; }
    .auth-panel .auth-panel-heading { margin: 0 0 .25rem; font-size: 1.1rem; }
    .auth-panel .guest-teaser-stats { margin: var(--spacing-md) 0; }
    @media (max-width: 1352px) { .auth-splash { flex-direction: column; } .auth-splash .auth-panel { order: -1; } }
</style>

<div class="auth-splash">
    <div class="auth-splash-side">
        <?php $guestSide = 'pics'; require __DIR__ . '/../../partials/guest_teaser.php'; ?>
    </div>

    <div class="auth-panel">
        <div class="auth-hero">
            <h1><?= e($authHeroTitle) ?></h1>
            <p class="muted"><?= e($authHeroText) ?></p>
        </div>

        <h1 class="auth-panel-heading"><?= e($authHeading) ?></h1>

        <?php require $authFormFile; ?>

        <?php if (!empty($mediaCounts)): ?>
        <p class="guest-teaser-stats muted">
            <strong><?= number_format((int) $mediaCounts['images']) ?></strong> pictures &middot;
            <strong><?= number_format((int) $mediaCounts['videos']) ?></strong> videos across the site
        </p>
        <?php endif; ?>

        <p class="auth-links"><?= $authLinksHtml ?></p>
        <p class="muted" style="text-align:center;font-size:0.8rem;margin-bottom:0;">
            <a href="<?= url('/terms') ?>">Terms of Service</a> &middot; <a href="<?= url('/privacy') ?>">Privacy Policy</a> &middot; <a href="<?= url('/2257') ?>">2257</a> &middot; <a href="<?= url('/dmca') ?>">DMCA</a> &middot; <a href="<?= url('/about') ?>">About Us</a>
        </p>
    </div>

    <div class="auth-splash-side">
        <?php $guestSide = 'videos'; require __DIR__ . '/../../partials/guest_teaser.php'; ?>
    </div>
</div>