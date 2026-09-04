<?php $title = 'Two-factor verification'; ?>

<div class="auth-panel">
    <h1>Two-factor verification</h1>

    <p class="muted" style="text-align:center;">
        Enter the 6-digit code from your authenticator app to finish signing in.
    </p>

    <form method="post" action="<?= url('/login/2fa') ?>">
        <?= csrf_field() ?>
        <p>
            <label for="code">Verification code</label><br>
            <input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code"
                   maxlength="6" pattern="[0-9]{6}" autofocus required
                   style="text-align:center;font-size:1.25rem;letter-spacing:.4rem;">
        </p>
        <p>
            <button type="submit" class="btn">Verify</button>
        </p>
    </form>

    <p class="muted" style="text-align:center;font-size:0.8rem;margin-bottom:0;">
        Can&rsquo;t access your authenticator app? Contact support to recover your account.
    </p>
</div>
