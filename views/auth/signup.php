<?php
$title = 'Sign Up';
$pictureBlank = 'data:image/svg+xml;utf8,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#ffd9e8"/><rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="#f472b6" stroke-width="8"/><circle cx="185" cy="145" r="14" fill="#ec4899"/><path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="#9333ea" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);

$recentItems = [];
foreach ($recentImages as $photo) {
    if ((int) $photo['gallery_id'] <= 0) {
        continue;
    }
    $recentItems[] = [
        'type'  => 'image',
        'url'   => url('/images/' . (int) $photo['id']),
        'thumb' => file_url($photo['filename'], 'blur'),
    ];
}
foreach ($recentVideos as $photo) {
    if ((int) $photo['gallery_id'] <= 0) {
        continue;
    }
    $recentItems[] = [
        'type'  => 'video',
        'url'   => url('/videos/' . (int) $photo['id']),
        'thumb' => file_url($photo['filename'], 'blur'),
    ];
}
?>

<style>
    .auth-panel { max-width: 1200px; }
    .signup-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.5rem; text-align: left; }
    .signup-grid h3 { margin: 0 0 0.5rem; font-size: 0.95rem; color: var(--purple-800); }
    .signup-grid input[type="text"], .signup-grid input[type="email"], .signup-grid input[type="password"], .signup-grid input[type="date"] { width: 100%; box-sizing: border-box; }
    .signup-grid input[type="date"]::-webkit-calendar-picker-indicator { cursor: pointer; opacity: 0.7; font-size: 1.1rem; }
    .signup-grid input[type="date"]::-webkit-calendar-picker-indicator:hover { opacity: 1; }
    .signup-actions { grid-column: 1 / -1; text-align: center; margin-top: 0.5rem; }
    @media (max-width: 750px) { .signup-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 500px) { .signup-grid { grid-template-columns: 1fr; } }
</style>

<div class="auth-panel">
    <h1>Create an Account</h1>

    <p class="muted">You need an account to browse <?= e(config('app.site_name')) ?>.</p>

    <form method="post" action="<?= url('/signup') ?>">
        <?= csrf_field() ?>
        <p style="position:absolute;left:-9999px" aria-hidden="true">
            <label for="website">Leave this field empty</label>
            <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
        </p>

        <div class="signup-grid">
            <div>
                <h3>Account</h3>
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
                    <input type="date" name="date_of_birth" id="date_of_birth" placeholder="MM/DD/YYYY" required onclick="this.showPicker()" onfocus="this.showPicker()">
    </p>
            </div>

            <div>
                <h3>Billing <span class="muted">(optional)</span></h3>
                <p>
                    <label for="billing_first_name">First Name</label><br>
                    <input type="text" name="billing_first_name" id="billing_first_name">
                </p>
                <p>
                    <label for="billing_last_name">Last Name</label><br>
                    <input type="text" name="billing_last_name" id="billing_last_name">
                </p>
                <p>
                    <label for="billing_address_line1">Address Line 1</label><br>
                    <input type="text" name="billing_address_line1" id="billing_address_line1">
                </p>
                <p>
                    <label for="billing_address_line2">Address Line 2</label><br>
                    <input type="text" name="billing_address_line2" id="billing_address_line2">
                </p>
            </div>

            <div>
                <h3>&nbsp;</h3>
                <p>
                    <label for="billing_city">City</label><br>
                    <input type="text" name="billing_city" id="billing_city">
                </p>
                <p>
                    <label for="billing_state">State</label><br>
                    <input type="text" name="billing_state" id="billing_state" maxlength="50">
                </p>
                <p>
                    <label for="billing_zip">ZIP / Postal Code</label><br>
                    <input type="text" name="billing_zip" id="billing_zip" maxlength="20">
                </p>
                <p>
                    <label for="billing_country">Country</label><br>
                    <input type="text" name="billing_country" id="billing_country" maxlength="2" placeholder="US">
                </p>
            </div>

            <div class="signup-actions">
                <button type="submit" class="btn">Sign Up</button>
            </div>
        </div>
    </form>

    <p class="auth-links">Already have an account? <a href="<?= url('/login') ?>">Log in</a> &middot; <a href="<?= url('/membership') ?>">Membership</a> &middot; <a href="<?= url('/admin') ?>">Admin login</a></p>
</div>

<?php if (!empty($recentImages)): ?>
<section>
    <h2 class="section-title">Recent Pictures</h2>
    <div class="recent-strip">
        <?php foreach ($recentItems as $item): ?>
            <?php if ($item['type'] !== 'image') { continue; } ?>
            <div class="card recent-card">
                <a class="card-link" href="<?= e($item['url']) ?>">
                    <div class="card-cover">
                        <img src="<?= e($item['thumb']) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= e($pictureBlank) ?>'">
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($recentVideos)): ?>
<section>
    <h2 class="section-title">Recent Videos</h2>
    <div class="recent-strip">
        <?php foreach ($recentItems as $item): ?>
            <?php if ($item['type'] !== 'video') { continue; } ?>
            <div class="card recent-card">
                <a class="card-link" href="<?= e($item['url']) ?>">
                    <div class="card-cover">
                        <img src="<?= e($item['thumb']) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= e($pictureBlank) ?>'">
                        <span class="play-badge">&#9654;</span>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<script>
    function fitRecentStrip() {
        document.querySelectorAll('.recent-strip').forEach(function (strip) {
            const cards = Array.from(strip.querySelectorAll('.recent-card'));
            if (!cards.length) return;

            const cardWidth = cards[0].offsetWidth || 220;
            const gap = parseFloat(getComputedStyle(strip).gap) || 0;
            const available = strip.clientWidth;
            const count = Math.max(0, Math.floor((available + gap) / (cardWidth + gap)));

            strip.style.justifyContent = cards.length < 4 ? 'space-evenly' : 'space-between';

            cards.forEach(function (card, i) {
                card.style.display = i < count ? '' : 'none';
            });
        });
    }
    fitRecentStrip();
    window.addEventListener('resize', fitRecentStrip);
    window.addEventListener('load', fitRecentStrip);
</script>
