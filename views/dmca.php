<?php
// DMCA / Copyright takedown policy. Public. Contact resolves from the
// controller (supportEmail) and falls back to the site support address.
$siteName     = $siteName ?? config('app.site_name');
$supportEmail = $supportEmail ?? ('support@' . config('app.site_name') . '.com');
$lastUpdated  = $lastUpdated ?? 'October 8, 2026';
?>
<div class="card" style="max-width:820px;margin:0 auto;padding:var(--spacing-lg);">
    <h1>DMCA Takedown Policy</h1>
    <p class="muted" style="text-align:center;">Last updated: <?= e($lastUpdated) ?></p>

    <p><?= e($siteName) ?> respects the intellectual property rights of others and complies
    with the Digital Millennium Copyright Act (DMCA). If you believe that material on this
    Site infringes your copyright, you may submit a written notice to our designated agent
    as set out below.</p>

    <h2>Designated Agent</h2>
    <p><strong>Designated Agent:</strong> <?= e($siteName) ?><br>
    <strong>Email:</strong> <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></p>

    <h2>Filing a Copyright Infringement Notice</h2>
    <p>To be effective, your notice must be in writing and include substantially the
    following information:</p>
    <ol>
        <li>A physical or electronic signature of the copyright owner or a person authorized
        to act on their behalf;</li>
        <li>Identification of the copyrighted work claimed to have been infringed;</li>
        <li>Identification of the material that is claimed to be infringing and information
        reasonably sufficient to permit us to locate it (e.g. the page URL and file name);</li>
        <li>Your contact information, including an email address and telephone number;</li>
        <li>A statement that you have a good-faith belief that the use of the material is not
        authorized by the copyright owner, its agent, or the law; and</li>
        <li>A statement, made under penalty of perjury, that the information in the notice is
        accurate and that you are authorized to act on behalf of the owner.</li>
    </ol>

    <h2>Counter-Notification</h2>
    <p>If you believe the material was removed or disabled by mistake or misidentification,
    you may send a counter-notification to the designated agent containing substantially the
    following:</p>
    <ol>
        <li>Your physical or electronic signature;</li>
        <li>Identification of the material that was removed and where it previously appeared;</li>
        <li>A statement under penalty of perjury that you have a good-faith belief the material
        was removed by mistake or misidentification; and</li>
        <li>Your name, address, telephone number, and a statement consenting to the jurisdiction
        of the federal district court for your address, or if you are outside the United States,
        any judicial district in which the service provider may be found.</li>
    </ol>

    <h2>Repeat Infringers</h2>
    <p>Accounts of repeat infringers will be reviewed and may be terminated in appropriate
    circumstances.</p>
</div>