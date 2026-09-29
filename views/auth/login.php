<?php
// Guest landing (the site root): a short value proposition, then a
// three-column layout — recent pictures on the left, the login form in the
// middle, recent videos on the right — with the site's media totals above.
// Clicking a teaser thumbnail goes to the media page, which (thanks to login
// return_to) brings the guest straight back to it after signing in.
?>

<div class="auth-hero">
    <h1>Exclusive photos and videos</h1>
    <p class="muted">A personal collection of photos and videos from the site's model, updated regularly — browse the catalog, save your favorites and chat. Log in to view, or sign up to start exploring.</p>
</div>

<?php if (!empty($mediaCounts)): ?>
<p class="guest-teaser-stats muted">
    <strong><?= number_format((int) $mediaCounts['images']) ?></strong> pictures &middot;
    <strong><?= number_format((int) $mediaCounts['videos']) ?></strong> videos across the site
</p>
<?php endif; ?>

<div class="auth-splash">
    <div class="auth-splash-side">
        <?php $guestSide = 'pics'; require __DIR__ . '/../partials/guest_teaser.php'; ?>
    </div>

    <div class="auth-panel">
        <h2 style="margin-top:0;">Login</h2>

        <form method="post" action="<?= url('/login') ?>">
            <?= csrf_field() ?>
            <p>
                <label for="email">Email</label><br>
                <input type="email" name="email" id="email" required autofocus>
            </p>
            <p>
                <label for="password">Password</label><br>
                <input type="password" name="password" id="password" required>
                <a href="<?= url('/forgot-password') ?>" style="font-size:0.85rem;margin-left:0.5rem;">Forgot password?</a>
            </p>
            <p>
                <label style="display:inline-flex;align-items:center;gap:.35rem;font-weight:normal;cursor:pointer;">
                    <input type="checkbox" name="remember_me" value="1" id="remember_me" style="width:auto;">
                    Keep me signed in on this device
                </label>
            </p>
            <p>
                <button type="submit" class="btn">Login</button>
            </p>
        </form>

        <p class="auth-links">No account yet? <a href="<?= url('/signup') ?>">Sign up</a> &middot; <a href="<?= url('/membership') ?>">Membership</a> &middot; <a href="<?= url('/admin') ?>">Admin login</a></p>
        <p class="muted" style="text-align:center;font-size:0.8rem;margin-bottom:0;">
            <a href="<?= url('/terms') ?>">Terms of Service</a> &middot; <a href="<?= url('/privacy') ?>">Privacy Policy</a> &middot; <a href="<?= url('/about') ?>">About Us</a>
        </p>
    </div>

    <div class="auth-splash-side">
        <?php $guestSide = 'videos'; require __DIR__ . '/../partials/guest_teaser.php'; ?>
    </div>
</div>

<style>
    .auth-hero { text-align: center; max-width: 640px; margin: 0 auto var(--spacing-lg); }
    .auth-hero h1 { margin: 0 0 .35rem; }
    .guest-grid { max-height: calc(100dvh - 428px); }
</style>