<?php
// Privacy Policy page. Public. See terms.php for the same rendering model.
$siteName     = $siteName ?? config('app.site_name');
$supportEmail = $supportEmail ?? ('support@' . config('app.site_name') . '.com');
$lastUpdated  = $lastUpdated ?? 'August 31, 2026';
?>
<div class="card" style="max-width:820px;margin:0 auto;padding:var(--spacing-lg);">
    <h1>Privacy Policy</h1>
    <p class="muted" style="text-align:center;">Effective Date: <?= e($lastUpdated) ?></p>

    <p><?= e($siteName) ?> ("we", "us", "our") respects your privacy. This Privacy Policy explains
    what information we collect when you use our Site, how we use and share it, and the choices you
    have. By using the Site, you agree to the practices described here.</p>

    <h2>1. Information We Collect</h2>
    <ul>
        <li><strong>Account information:</strong> name, email address, and any profile details you
        provide when creating an account.</li>
        <li><strong>Payment information:</strong> billing details processed by our payment
        processors. We do not store your full card numbers; card data is handled by the processor.</li>
        <li><strong>Usage information:</strong> pages visited, galleries and media viewed, search
        queries, saved favorites, and similar activity.</li>
        <li><strong>Technical information:</strong> IP address, browser and device type, operating
        system, and basic log data.</li>
        <li><strong>Support communications:</strong> the messages you send when contacting us.</li>
    </ul>

    <h2>2. How We Use Your Information</h2>
    <ul>
        <li>To provide, maintain, and personalize the Site and your membership.</li>
        <li>To process payments and manage subscriptions, renewals, and cancellations.</li>
        <li>To communicate with you about your account, billing, and service updates.</li>
        <li>To respond to support requests.</li>
        <li>To detect, prevent, and address fraud, security, and technical issues.</li>
        <li>To comply with legal obligations.</li>
        <li>To understand Site usage and improve our services.</li>
    </ul>

    <h2>3. Cookies and Similar Technologies</h2>
    <p>We use cookies and similar technologies (such as local storage) to keep you signed in, remember
    your preferences, and understand how the Site is used. You can control cookies through your
    browser settings, but disabling them may affect certain functionality.</p>

    <h2>4. How We Share Your Information</h2>
    <p>We do not sell your personal information. We may share it with:</p>
    <ul>
        <li><strong>Service providers</strong> who help us operate the Site, process payments,
        deliver email, host infrastructure, and provide analytics.</li>
        <li><strong>Legal and safety</strong> authorities when required by law or to protect our
        rights, the safety of users, or to prevent fraud.</li>
        <li><strong>Business transfers</strong> in connection with a merger, sale, or acquisition of
        all or part of our business.</li>
    </ul>

    <h2>5. Data Security</h2>
    <p>We implement reasonable technical and organizational measures to protect your information
    against unauthorized access, alteration, disclosure, or destruction. However, no method of
    transmission or storage is completely secure, and we cannot guarantee absolute security.</p>

    <h2>6. Data Retention</h2>
    <p>We retain your information for as long as your account is active or as needed to provide the
    service, comply with legal obligations, resolve disputes, and enforce agreements. Payment records
    are retained as required by applicable law.</p>

    <h2>7. Your Rights and Choices</h2>
    <p>Depending on your jurisdiction, you may have rights to access, correct, delete, or restrict the
    processing of your personal information, and to object to certain processing. You may update your
    account and profile information at any time from
    <a href="<?= url('/settings') ?>">your settings</a>. You may also contact us to exercise any
    applicable rights.</p>

    <h2>8. International Data Transfers</h2>
    <p>Your information may be processed and stored on servers outside your country of residence.
    Where required, we rely on appropriate safeguards to protect such transfers in accordance with
    applicable law.</p>

    <h2>9. Children's Privacy</h2>
    <p>The Site is intended for adults only and is not directed to individuals under the age of 18.
    We do not knowingly collect personal information from minors. If we learn that we have collected
    information from a minor, we will delete it.</p>

    <h2>10. Third-Party Links</h2>
    <p>The Site may contain links to third-party websites or services. We are not responsible for the
    privacy practices or content of those third parties. We encourage you to review their privacy
    policies.</p>

    <h2>11. Changes to This Policy</h2>
    <p>We may update this Privacy Policy from time to time. The most current version will always be
    posted at <a href="<?= url('/privacy') ?>">this page</a> with a new effective date. Material
    changes will be communicated where appropriate.</p>

    <h2>12. Contact Us</h2>
    <p>If you have questions or concerns about this Privacy Policy or our data practices, please
    contact us at <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
</div>
