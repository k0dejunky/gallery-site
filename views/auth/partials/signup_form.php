<?php
// Signup form — injected into the shared auth shell.
?>
<style>
    .signup-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; text-align: left; }
    .signup-grid input[type="text"], .signup-grid input[type="email"], .signup-grid input[type="password"], .signup-grid input[type="date"] { width: 100%; box-sizing: border-box; }
    .signup-grid input[type="date"]::-webkit-calendar-picker-indicator { cursor: pointer; opacity: 0.7; font-size: 1.1rem; }
    .signup-grid input[type="date"]::-webkit-calendar-picker-indicator:hover { opacity: 1; }
    .signup-actions { grid-column: 1 / -1; text-align: center; margin-top: calc(-1rem); }
    .signup-grid > div > p:last-child { margin-bottom: 0; }
    .auth-form-note { margin: 0 0 var(--spacing-md); }
</style>

<p class="muted auth-form-note">Create a free account to browse <?= e(config('app.site_name')) ?>'s exclusive photos and videos.</p>

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

        <div style="grid-column:1 / -1;">
            <p style="margin-bottom:.4rem;">
                <label for="promo">Promotion code (optional)</label><br>
                <input type="text" name="promo" id="promo" value="<?= e((string) ($promo ?? '')) ?>" autocomplete="off" placeholder="Have a promotion code? Enter it here">
            </p>
            <?php if (($promoInfo['ok'] ?? null) === true): ?>
                <p class="muted" style="font-size:.85rem;margin:0;">
                    This promotion grants a free <?= (int) $promoInfo['days'] ?>-day <?= e((string) $promoInfo['level']) ?> trial when you sign up.
                </p>
            <?php elseif (($promoInfo['ok'] ?? null) === false): ?>
                <p style="color:#b91c1c;font-size:.85rem;margin:0;">
                    That promotion code is not available or has been fully used.
                </p>
            <?php endif; ?>
        </div>

        <div class="signup-actions">
            <button type="submit" class="btn">Sign Up</button>
        </div>
    </div>
</form>