<?php $title = 'Set up two-factor authentication'; ?>
<?php $required = !empty($required); ?>

<div class="auth-panel fa-setup">
    <?php if ($required): ?>
    <div class="alert alert-warning" role="alert" style="margin-bottom:1rem;">
        <strong>Two-factor is required for your role.</strong>
        <p style="margin:.35rem 0 0;">Every other page stays locked until you finish this step. It takes about a minute.</p>
    </div>
    <?php endif; ?>
    <p>Scan this with your authenticator app, or type the secret underneath it, then enter the 6-digit code it generates to confirm.</p>

    <?php // Drawn locally by qrcodejs: an otpauth:// URI contains the shared
          // secret, so it is never handed to an external QR service. If the
          // script is blocked the secret below still works. ?>
    <div id="totp-qr" data-uri="<?= e($uri) ?>" style="display:flex;justify-content:center;margin:1rem 0;background:var(--adm-surface);padding:12px;border-radius:10px;width:max-content;margin-inline:auto;"></div>

    <p><strong>Secret key</strong> <span class="muted" style="font-weight:normal;">(if you cannot scan)</span></p>
    <div class="secret-box"><?= e($secret) ?></div>

    <details style="margin:.5rem 0 1rem;">
        <summary class="muted" style="cursor:pointer;">Show setup URI</summary>
        <div class="uri-box"><?= e($uri) ?></div>
    </details>

    <p class="muted" style="font-size:.85rem;margin:-.5rem 0 1rem;">
        The key stays the same if you reload this page.
        <a href="<?= url('/settings/two-factor/setup?reset=1') . ($required ? '&amp;required=1' : '') ?>">Generate a different key</a>.
    </p>

    <form method="post" action="<?= url('/settings/two-factor/enable') ?>">
        <?= csrf_field() ?>
        <p>
            <label for="code">Verification code from your app</label><br>
            <input type="text" name="code" id="code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                   autocomplete="one-time-code" autofocus required
                   style="text-align:center;font-size:1.25rem;letter-spacing:.4rem;">
        </p>
        <p>
            <button type="submit" class="btn">Enable two-factor</button>
            <?php if ($required): ?>
                <button type="submit" class="btn btn-outline" form="logout-when-required">Sign out</button>
            <?php else: ?>
                <a class="btn btn-outline" href="<?= url('/settings') ?>">Cancel</a>
            <?php endif; ?>
        </p>
    </form>
    <?php if ($required): ?>
    <?php // Outside the enable form: a form cannot be nested in another form. ?>
    <form id="logout-when-required" method="post" action="<?= url('/logout') ?>" style="display:none;"><?= csrf_field() ?></form>
    <?php endif; ?>

    <p class="muted" style="font-size:.8rem;margin-top:1rem;">
        Keep a backup of your secret. If you lose access to your authenticator app, an operator can run
        <code>php bin/disable_totp.php --email=&lt;address&gt; --yes</code> on the server.
    </p>
</div>

<script nonce="<?= csp_nonce() ?>" src="<?= e(asset_url('/assets/js/qrcode.min.js')) ?>"></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var el = document.getElementById('totp-qr');
    if (!el || typeof QRCode === 'undefined') { return; }
    new QRCode(el, {
        text: el.getAttribute('data-uri'),
        width: 200,
        height: 200,
        correctLevel: QRCode.CorrectLevel.M
    });
})();
</script>