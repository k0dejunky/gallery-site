<?php
// Site-wide footer with legal / compliance links. Included once by
// views/layout.php for every rendered page.
?>
<footer class="site-footer">
    <nav aria-label="Legal">
        <a class="thin-link" href="<?= url('/terms') ?>">Terms of Service</a>
        &middot; <a class="thin-link" href="<?= url('/privacy') ?>">Privacy Policy</a>
        &middot; <a class="thin-link" href="<?= url('/2257') ?>">18 U.S.C. § 2257</a>
        &middot; <a class="thin-link" href="<?= url('/dmca') ?>">DMCA</a>
        &middot; <a class="thin-link" href="<?= url('/report-abuse') ?>">Report Abuse</a>
        &middot; <a class="thin-link" href="<?= url('/about') ?>">About</a>
    </nav>
    <p class="muted" style="margin:.4rem 0 0;">&copy; <?= date('Y') ?> <?= e(config('app.site_name')) ?>. Adults only — 18+.</p>
</footer>