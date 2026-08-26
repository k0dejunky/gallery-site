<?php $title = 'Forgot Password'; ?>

<div class="auth-panel">
    <h1>Forgot Password</h1>
    <p class="muted" style="text-align:center;margin-bottom:1.5rem;">Enter your email and we'll send you a reset link.</p>
    <form method="post" action="<?= url('/forgot-password') ?>">
        <?= csrf_field() ?>
        <div style="margin-bottom:1rem">
            <label for="email">Email</label><br>
            <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@example.com">
        </div>
        <button type="submit" class="btn" style="width:100%">Send Reset Link</button>
    </form>
    <p style="text-align:center;margin-top:1rem;"><a href="<?= url('/login') ?>">&larr; Back to login</a></p>
</div>
