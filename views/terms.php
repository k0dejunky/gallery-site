<?php
// Terms of Service page. Public; safe to render guest nav. Redirect behavior:
// when a guest is on this page, pass the guest nav unchanged. The main layout
// renders the top nav for guests on non-auth pages.
$siteName     = $siteName ?? config('app.site_name');
$supportEmail = $supportEmail ?? ('support@' . config('app.site_name') . '.com');
$lastUpdated  = $lastUpdated ?? 'August 31, 2026';
$t = 'var(--purple-800)';
?>
<div class="card" style="max-width:820px;margin:0 auto;padding:var(--spacing-lg);">
    <h1>Terms of Service</h1>
    <p class="muted" style="text-align:center;">Effective Date: <?= e($lastUpdated) ?></p>

    <p>Welcome to <?= e($siteName) ?> ("the Site", "we", "us", "our"). These Terms of Service ("Terms",
    "Agreement") govern your access to and use of the Site, which is an exclusive, membership-based
    service offering photographs and videos ("Content"). By accessing or using the Site, you agree to
    be bound by these Terms. If you do not agree with any part of these Terms, you must not use the
    Site.</p>

    <h2>1. Eligibility and Adult Content</h2>
    <p>You must be at least <strong>18 years of age</strong> (or the age of majority in your
    jurisdiction, whichever is greater) to access or use the Site. The Site contains sexually explicit
    adult material intended only for adults. By accessing the Site you represent and warrant that you
    are of legal age to view such material in your jurisdiction, and that you are not accessing it
    from a jurisdiction where such material is prohibited.</p>

    <h2>2. Accounts and Membership</h2>
    <ul>
        <li>You must provide accurate, current and complete information when creating an account and
        keep it up to date.</li>
        <li>You are responsible for safeguarding your password and for all activity under your
        account. Notify us immediately of any unauthorized use.</li>
        <li>Membership fees, billing cycles, renewal and cancellation terms are described during
        checkout and at <a href="<?= url('/membership') ?>">the membership page</a>. All sales are
        subject to the applicable payment processor's terms.</li>
        <li>We may suspend or terminate accounts that violate these Terms or applicable law.</li>
    </ul>

    <h2>3. Access and License</h2>
    <p>Members are granted a limited, non-exclusive, non-transferable, revocable license to access the
    Content for personal, non-commercial viewing only. You may not download, copy, record, re-upload,
    redistribute, broadcast, or create derivative works from any Content unless explicitly permitted
    in writing.</p>

    <h2>4. Prohibited Conduct</h2>
    <p>You agree not to:</p>
    <ul>
        <li>Share or otherwise make your account or its access available to others.</li>
        <li>Attempt to bypass paywalls, restrictions, or technical protection measures.</li>
        <li>Scrape, harvest, or otherwise collect Content or data from the Site by automated means.</li>
        <li>Reverse engineer, decompile, or interfere with the Site or its systems.</li>
        <li>Upload, post, or transmit any unlawful, infringing, harasing, or malicious material.</li>
        <li>Use the Site in any way that violates applicable law.</li>
    </ul>

    <h2>5. Intellectual Property</h2>
    <p>All Content, trademarks, logos, and other intellectual property displayed on the Site are owned
    by us or our licensors and are protected by copyright and other laws. Nothing in these Terms
    grants you any right, title, or interest in such intellectual property.</p>

    <h2>6. Payments and Refunds</h2>
    <p>Membership is billed in advance via our payment processors. Unless otherwise stated in an
    applicable billing notice or required by law, payments are non-refundable and no refunds or
    credits will be provided for partial membership periods, with the exception of automatic renewals
    cancelled before the renewal charge, or as otherwise required by consumer-protection law in your
    jurisdiction. Please contact <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>
    with any billing questions.</p>

    <h2>7. Third-Party Services</h2>
    <p>The Site may use third-party services (including payment processors, analytics, and content
    delivery networks). Your use of those services is subject to their own terms and privacy policies.
    We are not responsible for the policies or actions of such third parties.</p>

    <h2>8. Disclaimer of Warranties</h2>
    <p>The Site and its Content are provided "as is" and "as available" without warranties of any
    kind, whether express or implied, including, but not limited to, implied warranties of
    merchantability, fitness for a particular purpose, and non-infringement. We do not warrant that
    the Site will be uninterrupted, error-free, or free of harmful components.</p>

    <h2>9. Limitation of Liability</h2>
    <p>To the fullest extent permitted by law, in no event shall we (or our officers, directors,
    employees, or agents) be liable for any indirect, incidental, special, consequential, or punitive
    damages, or any loss of profits or revenues, whether incurred directly or indirectly, or any loss
    of data, use, goodwill, or other intangible losses, resulting from (a) your use or inability to
    use the Site; (b) any conduct or Content of any third party; or (c) unauthorized access or
    alteration of your transmissions or data.</p>

    <h2>10. Indemnification</h2>
    <p>You agree to indemnify, defend, and hold harmless us and our affiliates, officers, directors,
    employees, and agents from and against any claims, liabilities, damages, losses, and expenses
    (including reasonable attorneys' fees) arising out of or in any way connected with your access to
    or use of the Site, your violation of these Terms, or your violation of any rights of another.</p>

    <h2>11. Changes to These Terms</h2>
    <p>We may revise these Terms from time to time. The most current version will always be posted at
    <a href="<?= url('/terms') ?>">this page</a> with a new effective date. Your continued use of the
    Site after changes take effect constitutes your acceptance of the revised Terms.</p>

    <h2>12. Termination</h2>
    <p>We may terminate or suspend your access to the Site at any time, with or without cause or
    notice, for conduct that we believe violates these Terms or is harmful to the Site or other users.
    Upon termination, your right to use the Site ceases immediately.</p>

    <h2>13. Governing Law</h2>
    <p>These Terms are governed by and construed in accordance with the laws of the jurisdiction in
    which we are established, without regard to its conflict-of-law provisions. You agree to submit
    to the exclusive jurisdiction of the courts in that jurisdiction for any disputes arising out of
    these Terms or your use of the Site.</p>

    <h2>14. Contact Us</h2>
    <p>If you have any questions about these Terms, please contact us at
    <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
</div>
