<?php $title = 'Set up two-factor authentication'; ?>
<?php $required = !empty($required); ?>

<div class="auth-panel fa-setup">
    <?php if ($required): ?>
    <div class="alert alert-warning" role="alert" style="margin-bottom:1rem;">
        <strong>Two-factor is required for your role.</strong>
        <p style="margin:.35rem 0 0;">Every other page stays locked until you finish this step. It takes about a minute.</p>
    </div>
    <?php endif; ?>
    <p>Open your authenticator app and add the secret below (or the full <code>otpauth://</code> URI), then enter the 6-digit code it generates to confirm.</p>

    <p><strong>Secret key</strong></p>
    <div class="secret-box"><?= e($secret) ?></div>

    <details style="margin:.5rem 0 1rem;">
        <summary class="muted" style="cursor:pointer;">Show setup URI</summary>
        <div class="uri-box"><?= e($uri) ?></div>
    </details>

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
        Keep a backup of your secret. If you lose access to your authenticator app, contact support to recover the account.
    </p>
</div>