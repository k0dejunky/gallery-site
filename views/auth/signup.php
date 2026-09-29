<?php
// Signup page: account form in the middle, with the recent pictures on the
// left and recent videos on the right.
?>
<style>
    .auth-splash .auth-panel { flex: 0 1 auto; width: auto; max-width: 520px; }
    .auth-splash-side { flex: 1 1 300px; }
    .auth-panel .auth-hero { text-align: center; margin: 0 0 .5rem; }
    .auth-panel .auth-hero h1 { margin: 0 0 .35rem; font-size: 1.35rem; }
    .auth-panel > h1 { margin: 0 0 .25rem; font-size: 1.1rem; }
    .auth-panel > h1 + p { margin: 0 0 var(--spacing-md); }
    .auth-panel .guest-teaser-stats { margin: var(--spacing-md) 0; }
    .signup-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; text-align: left; }
    .signup-grid h3 { margin: 0 0 0.5rem; font-size: 0.95rem; color: var(--purple-800); }
    .signup-grid input[type="text"], .signup-grid input[type="email"], .signup-grid input[type="password"], .signup-grid input[type="date"] { width: 100%; box-sizing: border-box; }
    .signup-grid input[type="date"]::-webkit-calendar-picker-indicator { cursor: pointer; opacity: 0.7; font-size: 1.1rem; }
    .signup-grid input[type="date"]::-webkit-calendar-picker-indicator:hover { opacity: 1; }
    .signup-actions { grid-column: 1 / -1; text-align: center; margin-top: calc(-1rem); }
    .signup-grid > div > p:last-child { margin-bottom: 0; }
    @media (max-width: 1352px) { .auth-splash { flex-direction: column; } .auth-splash .auth-panel { order: -1; } }
</style>

<div class="auth-splash">
    <div class="auth-splash-side">
        <?php $guestSide = 'pics'; require __DIR__ . '/../partials/guest_teaser.php'; ?>
    </div>

    <div class="auth-panel">
        <div class="auth-hero">
            <h1>Amethyst's Private Collection</h1>
            <p class="muted">A personal collection of photos and videos from the site's model, updated regularly — browse the catalog, save your favorites and chat. Sign up to start exploring.</p>
        </div>

        <h1>Create an Account</h1>

        <p class="muted">Create a free account to browse <?= e(config('app.site_name')) ?>'s exclusive photos and videos.</p>

        <form method="post" action="<?= url('/signup') ?>">
            <?= csrf_field() ?>
            <p style="position:absolute;left:-9999px" aria-hidden="true">
                <label for="website">Leave this field empty</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </p>

            <div class="signup-grid">
                    <div>
                    <p>
                        <label for="email">Email</label><br>
                        <input type="email" name="email" id="email" required autofocus>
                    </p>
                    <p>
                        <label for="password">Password</label><br>
                        <input type="password" name="password" id="password" minlength="8" required>
                    </p>
                    <p>
                        <label for="password_confirm">Confirm Password</label><br>
                        <input type="password" name="password_confirm" id="password_confirm" minlength="8" required>
                    </p>
                    <p>
                        <label for="date_of_birth">Date of Birth</label><br>
                        <input type="date" name="date_of_birth" id="date_of_birth" placeholder="MM/DD/YYYY" required onclick="if(window.HTMLInputElement&&HTMLInputElement.prototype.showPicker)this.showPicker()" onfocus="if(window.HTMLInputElement&&HTMLInputElement.prototype.showPicker)this.showPicker()">
                    </p>
                </div>

                <div class="signup-actions">
                    <button type="submit" class="btn">Sign Up</button>
                </div>
            </div>
        </form>

        <?php if (!empty($mediaCounts)): ?>
        <p class="guest-teaser-stats muted">
            <strong><?= number_format((int) $mediaCounts['images']) ?></strong> pictures &middot;
            <strong><?= number_format((int) $mediaCounts['videos']) ?></strong> videos across the site
        </p>
        <?php endif; ?>

        <p class="auth-links">Already have an account? <a href="<?= url('/login') ?>">Log in</a> &middot; <a href="<?= url('/membership') ?>">Membership</a> &middot; <a href="<?= url('/admin') ?>">Admin login</a></p>
    </div>

    <div class="auth-splash-side">
        <?php $guestSide = 'videos'; require __DIR__ . '/../partials/guest_teaser.php'; ?>
    </div>
</div>