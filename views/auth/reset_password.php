<?php $title = 'Reset Password'; ?>

<div class="auth-panel">
    <h1>Reset Password</h1>
    <form method="post" action="<?= url('/reset-password') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div style="margin-bottom:1rem">
            <label for="password">New Password</label><br>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div style="margin-bottom:1.5rem">
            <label for="password_confirm">Confirm Password</label><br>
            <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn" style="width:100%">Reset Password</button>
    </form>
</div>
