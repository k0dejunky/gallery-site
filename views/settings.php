<?php $title = 'Settings'; ?>
<?php // Favourite IDs as a plain list so checkbox state can be checked cheaply. ?>
<?php $favIds = array_map('intval', array_column($favorites, 'id')); ?>

<h1>Settings</h1>

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
