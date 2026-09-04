<?php $title = 'Settings'; ?>
<?php // Favourite IDs as a plain list so checkbox state can be checked cheaply. ?>
<?php $favIds = array_map('intval', array_column($favorites, 'id')); ?>

<h1>Settings</h1>
<p class="settings-back"><a class="btn btn-outline" href="<?= e(url('/account')) ?>">&larr; Back to dashboard</a></p>

<section class="card settings-card">
    <h2 class="section-title">Profile and billing details</h2>
    <p class="muted">These details are used for your account and future billing. Your email and permissions are managed separately.</p>
    <form method="post" action="<?= e(url('/settings/profile')) ?>" class="settings-form">
        <?= csrf_field() ?>
        <div class="settings-fields">
        <?php foreach (['billing_first_name' => 'First name', 'billing_last_name' => 'Last name', 'billing_address_line1' => 'Address', 'billing_address_line2' => 'Address line 2', 'billing_city' => 'City', 'billing_state' => 'State / region', 'billing_zip' => 'Postal code', 'billing_country' => 'Country code (e.g. US)'] as $field => $label): ?>
            <label><?= e($label) ?><input type="text" name="<?= e($field) ?>" value="<?= e($user[$field] ?? '') ?>" maxlength="255"></label>
        <?php endforeach; ?>
        </div>
        <button type="submit" class="btn">Save profile details</button>
    </form>
</section>

<section class="card settings-card">
    <h2 class="section-title">Gallery display</h2>
    <div class="settings-fields" style="grid-template-columns:repeat(3,minmax(0,1fr))">
        <label>View mode
            <select id="gd-view" data-gd="view">
                <option value="grid">Grid</option>
                <option value="list">List</option>
                <option value="compact">Compact</option>
            </select>
        </label>
        <label>Thumbnail size
            <select id="gd-size" data-gd="size">
                <option value="sm">Small</option>
                <option value="md">Medium</option>
                <option value="lg">Large</option>
            </select>
        </label>
        <label>Layout
            <select id="gd-masonry" data-gd="masonry">
                <option value="0">Standard grid</option>
                <option value="1">Masonry</option>
            </select>
        </label>
        <label>Per page
            <select id="gd-perpage" data-gd="perpage">
                <option value="12">12</option>
                <option value="24">24</option>
                <option value="48">48</option>
                <option value="0">All</option>
            </select>
        </label>
        <label>Default sort
            <select id="gd-sort" data-gd="sort">
                <option value="">Newest</option>
                <option value="views">Most Viewed</option>
                <option value="title">A–Z</option>
            </select>
        </label>
    </div>
    <p class="muted">These preferences are saved in this browser only and do not change your account data.</p>
</section>

<?php if (!empty($emailUnverified)): ?>
    <section class="card" style="border-left:4px solid var(--purple-500);margin-bottom:var(--spacing-lg);">
        <h2 style="text-align:left;margin-top:0;">Verify your email address</h2>
        <p>Please verify your email to keep your account details current. Check your inbox for the verification link.</p>
        <form method="post" action="<?= url('/verify-email/resend') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Resend verification email</button>
        </form>
    </section>
<?php endif; ?>

<?php if (!empty($themeEligible)): ?>
<h2 class="section-title">User theme</h2>
<p class="muted">Choose any saved user theme. Your selection applies only to your account.</p>
<form method="post" action="<?= url('/settings/theme') ?>" id="user-theme-form">
    <?= csrf_field() ?>
    <div class="theme-choice-layout">
        <div class="theme-choice-list">
            <label class="theme-choice-card<?= empty($themeSelected) ? ' selected' : '' ?>">
                <input type="radio" name="theme_preset" value=""<?= empty($themeSelected) ? ' checked' : '' ?>>
                <strong>Default theme</strong>
                <span>Use the current site default.</span>
            </label>
            <?php foreach ($themePresets as $preset): ?>
                <label class="theme-choice-card<?= $themeSelected === $preset['slug'] ? ' selected' : '' ?>">
                    <input type="radio" name="theme_preset" value="<?= e($preset['slug']) ?>"<?= $themeSelected === $preset['slug'] ? ' checked' : '' ?>>
                    <strong><?= e($preset['name']) ?></strong>
                    <span>Saved user theme</span>
                    <span class="theme-swatches">
                        <?php foreach (array_slice($preset['theme']['colors'], 0, 8) as $color): ?><i style="background:<?= e($color) ?>"></i><?php endforeach; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <div>
            <div class="theme-choice-preview-label">Live preview</div>
            <div class="theme-choice-preview" id="user-theme-preview">
                <img data-theme-preview-title alt="Site title image">
                <div class="theme-choice-preview-nav"><b>Galleries</b><a href="#">Home</a><a href="#">Categories</a></div>
                <div class="theme-choice-preview-body">
                    <aside><b>Favorites</b><span>Nature</span><span>Travel</span><span>Portraits</span></aside>
                    <main><div class="theme-choice-preview-card"><b>Gallery title</b><small>12 photos · 340 views</small><a href="#">View details</a></div><div class="theme-choice-preview-card"><b>Another gallery</b><small>8 photos · 190 views</small><a href="#">View details</a></div><button type="button">Create Gallery</button></main>
                </div>
            </div>
        </div>
    </div>
    <p><button type="submit" class="btn">Use Selected Theme</button></p>
</form>
<script>
(function () {
    var presets = <?= json_encode(array_merge([['slug' => '', 'theme' => $themeDefault]], $themePresets), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var preview = document.getElementById('user-theme-preview');
    var title = preview && preview.querySelector('[data-theme-preview-title]');
    function applyTheme(theme) {
        if (!preview || !theme) return;
        Object.keys(theme.colors || {}).forEach(function (key) { preview.style.setProperty('--' + key, theme.colors[key]); });
        Object.keys(theme.layout || {}).forEach(function (key) { preview.style.setProperty('--' + key, theme.layout[key]); });
        if (title) title.src = theme.title_image_url || '';
    }
    function selectedTheme() {
        var input = document.querySelector('#user-theme-form input[name="theme_preset"]:checked');
        return presets.find(function (item) { return item.slug === (input ? input.value : ''); });
    }
    document.querySelectorAll('#user-theme-form input[name="theme_preset"]').forEach(function (input) {
        input.addEventListener('change', function () {
            document.querySelectorAll('.theme-choice-card').forEach(function (card) { card.classList.toggle('selected', card.querySelector('input').checked); });
            applyTheme(selectedTheme().theme);
        });
    });
    applyTheme(selectedTheme().theme);
}());
</script>
<?php endif; ?>

<?php // Favourites are chosen with checkboxes; unchecked ones are simply absent from the POST. ?>
<?php if (!empty($favoritesLocked)): ?>
    <?php // Do not expose controls for a permission the account does not have. ?>
<?php else: ?>
<h2 class="section-title">Favorite categories</h2>
<p class="muted">Pick the categories you want to see on your home page.</p>
<form method="post" action="<?= url('/settings/favorites') ?>">
    <?= csrf_field() ?>
    <div class="chips">
        <?php foreach ($categories as $cat): ?>
            <?php $checked = in_array((int) $cat['id'], $favIds, true); ?>
            <label class="chip favorite-option<?= $checked ? ' selected' : '' ?>">
                <input type="checkbox" name="categories[]" value="<?= (int) $cat['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                <?= e($cat['name']) ?>
            </label>
        <?php endforeach; ?>
    </div>
    <p>
        <button type="submit" class="btn">Save Favorite Categories</button>
    </p>
</form>

<script>
    document.querySelectorAll('.favorite-option input[type="checkbox"]').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            checkbox.closest('.favorite-option').classList.toggle('selected', checkbox.checked);
        });
    });
</script>
<?php endif; ?>

<h2 class="section-title">Change password</h2>
<section class="card settings-card security-card">
    <h2 class="section-title">Account security</h2>
    <p><strong>Last login:</strong> <?= e($user['last_login_at'] ?? 'Not available') ?></p>
    <p><strong>Last seen:</strong> <?= e($user['last_seen_at'] ?? 'Not available') ?></p>
    <p class="muted">Use a unique password of at least 8 characters. Changing it does not automatically sign out other devices.</p>
    <form method="post" action="<?= e(url('/settings/logout-everywhere')) ?>" onsubmit="return confirm('Sign out every device connected to your account?');">
        <?= csrf_field() ?><button type="submit" class="btn btn-danger">Log out everywhere</button>
    </form>
    <?php if (\App\Core\Auth::isAdmin()): ?>
        <?php $totpEnabled = !empty($user['totp_enabled']); ?>
        <hr style="margin:1rem 0;border:none;border-top:1px solid var(--pink-300);">
        <h3 style="text-align:left;margin:0 0 .5rem;">Two-factor authentication</h3>
        <p class="muted">Add a time-based one-time code from an authenticator app to protect admin access.</p>
        <?php if ($totpEnabled): ?>
            <p><span class="pill pill-ok" style="text-transform:none;">Enabled</span></p>
            <form method="post" action="<?= e(url('/settings/two-factor/disable')) ?>" style="margin-top:.5rem;">
                <?= csrf_field() ?>
                <p>
                    <label for="disable-2fa-code">Current verification code to disable</label><br>
                    <input type="text" name="code" id="disable-2fa-code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required>
                </p>
                <button type="submit" class="btn btn-danger">Disable two-factor</button>
            </form>
        <?php else: ?>
            <a class="btn" href="<?= e(url('/settings/two-factor/setup')) ?>">Set up two-factor authentication</a>
        <?php endif; ?>
    <?php endif; ?>
</section>
<form method="post" action="<?= url('/settings/password') ?>">
    <?= csrf_field() ?>
    <p>
        <label for="current_password">Current password</label><br>
        <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
    </p>
    <p>
        <label for="new_password">New password (min 8 chars)</label><br>
        <input type="password" name="new_password" id="new_password" required autocomplete="new-password">
    </p>
    <p>
        <label for="confirm_password">Confirm new password</label><br>
        <input type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password">
    </p>
    <p>
        <button type="submit" class="btn">Change Password</button>
    </p>
</form>
