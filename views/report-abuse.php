<?php
// Report abuse / safety page. Public. Consolidates the ways members and
// visitors can flag content, watermarks, impersonation, and law-enforcement
// requests. Contact resolves from the controller (supportEmail).
$siteName     = $siteName ?? config('app.site_name');
$supportEmail = $supportEmail ?? ('support@' . config('app.site_name') . '.com');
$lastUpdated  = $lastUpdated ?? 'October 8, 2026';
?>
<div class="card" style="max-width:820px;margin:0 auto;padding:var(--spacing-lg);">
    <h1>Report Abuse</h1>
    <p class="muted" style="text-align:center;">Last updated: <?= e($lastUpdated) ?></p>

    <p><?= e($siteName) ?> takes safety and community standards seriously. If you see content
    on the Site that you believe violates our <a href="<?= url('/terms') ?>">Terms of Service</a>,
    depicts a minor, concerns unauthorized use of your likeness or identity, or otherwise
    appears abusive, please report it and we will review it promptly.</p>

    <h2>How to report</h2>
    <ul>
        <li><strong>General abuse reports</strong> — send details (URL of the gallery or media,
        a description of the issue, and a link to any relevant correspondence) to
        <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>, or use the
        <a href="<?= url('/support') ?>">support contact form</a>.</li>
        <li><strong>Copyright matters</strong> — see our <a href="<?= url('/dmca') ?>">DMCA takedown
        policy</a> for the required notice format.</li>
        <li><strong>Record-keeping questions</strong> — see our
        <a href="<?= url('/2257') ?>">18 U.S.C. § 2257 statement</a>.</li>
        <li><strong>Law enforcement requests</strong> — please mark the subject accordingly and
        include a case reference number where available.</li>
    </ul>

    <h2>What happens next</h2>
    <p>We acknowledge reports as soon as we can, investigate, and take appropriate action,
    which may include removing content, restricting the affected gallery, or notifying the
    relevant authorities. We do not reply to anonymous or clearly abusive submissions.</p>
</div>