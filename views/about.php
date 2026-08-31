<?php
// About page. Public; safe to render guest nav (same rendering model as
// terms.php / privacy.php).
$siteName     = $siteName ?? config('app.site_name');
$supportEmail = $supportEmail ?? ('support@' . config('app.site_name') . '.com');
?>
<div class="card" style="max-width:820px;margin:0 auto;padding:var(--spacing-lg);">
    <h1>About <?= e($siteName) ?></h1>

    <p><?= e($siteName) ?> is an exclusive, membership-based service offering access to a curated
    collection of original photographs and videos for adult audiences. Every piece is hand-selected,
    and the library is updated continually so members always have something new to explore.</p>

    <h2>What we offer</h2>
    <ul>
        <li>A growing, curated gallery of high-resolution photography and video.</li>
        <li>Simple, flexible membership plans designed around how you enjoy the content.</li>
        <li>Full-featured editing tools so the site's media is always presented at its best.</li>
        <li>Secure, private access for members only.</li>
    </ul>

    <h2>Who we are</h2>
    <p>We are a small, independent team focused on quality and craft. We believe great content should
    be easy to enjoy and presented beautifully, without clutter or interruption. That philosophy
    drives everything from the imagery we select to how the site is built and maintained.</p>

    <h2>Contact</h2>
    <p>Questions, feedback, or support? Reach us any time at
    <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>. We read every message.</p>

    <p style="margin-top:1.5rem;">
        Read our <a href="<?= url('/terms') ?>">Terms of Service</a> and
        <a href="<?= url('/privacy') ?>">Privacy Policy</a>.
    </p>
</div>
