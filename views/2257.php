<?php
// 18 U.S.C. §2257 record-keeping statement. Public. See terms.php for the
// rendering model. Contact resolves from the controller (supportEmail) and
// falls back to the site support address.
$siteName     = $siteName ?? config('app.site_name');
$supportEmail = $supportEmail ?? ('support@' . config('app.site_name') . '.com');
$lastUpdated  = $lastUpdated ?? 'October 8, 2026';
?>
<div class="card" style="max-width:820px;margin:0 auto;padding:var(--spacing-lg);">
    <h1>18 U.S.C. § 2257 Record-Keeping Statement</h1>
    <p class="muted" style="text-align:center;">Last updated: <?= e($lastUpdated) ?></p>

    <p><?= e($siteName) ?> is fully compliant with the record-keeping requirements of
    18 U.S.C. § 2257 and the associated Federal Regulations (28 C.F.R. Part 75). All
    models, actors, actresses and other persons appearing in any photograph or video
    contained on this Site were at least 18 years of age at the time the visual image
    was created.</p>

    <h2>Records Custodian</h2>
    <p>All records required to be maintained by 18 U.S.C. § 2257 and 28 C.F.R. Part 75,
    including the identification of every performer depicted in any visual depiction
    on this Site, are maintained by the designated records custodian and are available
    for inspection during normal business hours at the following location:</p>
    <ul>
        <li><strong>Records Custodian:</strong> <?= e($siteName) ?></li>
        <li><strong>Email:</strong> <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></li>
    </ul>

    <h2>Inspections</h2>
    <p>The custodian of records is available to authorize the lawful inspection of the
    required records in accordance with 28 C.F.R. § 75.3. Arrangements for inspection
    should be made in advance by contacting the records custodian by email at the address
    above.</p>

    <h2>Certification</h2>
    <p>All persons appearing in any visual depiction of sexually explicit conduct were
    over the age of eighteen (18) years at the time of their depiction, and copies of
    their identification documents are retained on file as required by law.</p>
</div>