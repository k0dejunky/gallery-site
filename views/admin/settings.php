<?php
/**
 * The admin's own settings page.
 *
 * It used to be two lines that required the MEMBER settings page, which
 * meant the admin console showed a second page heading, member-only
 * sections (favourites, the site tour, the gallery display, the theme
 * picker) and a link back to /account - the member dashboard, not the
 * admin's. It also broke when the admin console
 * stopped loading the member stylesheet, because the page's classes
 * lived in it.
 *
 * These are the sections that mean something for the operator.
 */
$title = 'Settings';
?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Account security</h2>
        <p>Your sign-in details for the console.</p>
    </div>
    <div class="sys-card">
        <p><strong>Last login:</strong> <?= !empty($user['last_login_at']) ? e(tzdate('Y-m-d H:i', $user['last_login_at'])) : 'Not available' ?></p>
        <p><strong>Last seen:</strong> <?= !empty($user['last_seen_at']) ? e(tzdate('Y-m-d H:i', $user['last_seen_at'])) : 'Not available' ?></p>
        <p class="muted">Use a unique password of at least 8 characters. Changing it does not automatically sign out other devices.</p>
        <form method="post" action="<?= e(url('/settings/logout-everywhere')) ?>" data-confirm="Sign out every device connected to your account?">
            <?= csrf_field() ?><button type="submit" class="btn btn-danger">Log out everywhere</button>
        </form>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Change password</h2>
    </div>
    <div class="sys-card">
        <form method="post" action="<?= e(url('/settings/password')) ?>">
            <?= csrf_field() ?>
            <div class="adm-field-grid">
                <label>
                    <span>Current password</span>
                    <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
                </label>
                <label>
                    <span>New password (min 8 chars)</span>
                    <input type="password" name="new_password" id="new_password" required autocomplete="new-password">
                </label>
                <label>
                    <span>Confirm new password</span>
                    <input type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password">
                </label>
            </div>
            <div class="adm-field-actions">
                <button type="submit" class="btn">Change password</button>
            </div>
        </form>
    </div>
</section>

<?php if (\App\Core\Auth::isAdmin()): ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Two-factor authentication</h2>
        <p>Add a time-based one-time code from an authenticator app to protect admin access.</p>
    </div>
    <div class="sys-card">
        <?php $totpEnabled = !empty($user['totp_enabled']); ?>
        <?php if ($totpEnabled): ?>
            <p><span class="pill pill-ok">Enabled</span></p>
            <form method="post" action="<?= e(url('/settings/two-factor/disable')) ?>">
                <?= csrf_field() ?>
                <label class="adm-field" for="disable-2fa-code"><span>Current verification code to disable</span><input type="text" name="code" id="disable-2fa-code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required></label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn btn-danger">Disable two-factor</button>
                </div>
            </form>
        <?php else: ?>
            <a class="btn" href="<?= e(url('/settings/two-factor/setup')) ?>">Set up two-factor authentication</a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Profile and billing details</h2>
        <p>These details are used for your account and future billing. Your email and permissions are managed separately.</p>
    </div>
    <div class="sys-card">
        <form method="post" action="<?= e(url('/settings/profile')) ?>">
            <?= csrf_field() ?>
            <div class="adm-field-grid">
                <?php foreach (['billing_first_name' => 'First name', 'billing_last_name' => 'Last name', 'billing_address_line1' => 'Address', 'billing_address_line2' => 'Address line 2', 'billing_city' => 'City', 'billing_state' => 'State / region', 'billing_zip' => 'Postal code', 'billing_country' => 'Country code (e.g. US)'] as $field => $label): ?>
                    <label>
                        <span><?= e($label) ?></span>
                        <input type="text" name="<?= e($field) ?>" id="setting-<?= e($field) ?>" value="<?= e($user[$field] ?? '') ?>" maxlength="255">
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="adm-field-actions">
                <button type="submit" class="btn">Save profile details</button>
            </div>
        </form>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Email notifications</h2>
        <p>Updates are sent by default. Turn off the ones you do not want.</p>
    </div>
    <div class="sys-card">
        <form method="post" action="<?= e(url('/settings/notifications')) ?>">
            <?= csrf_field() ?>
            <div class="check-row">
                <label>
                    <input type="checkbox" name="notify_new_gallery" value="1"<?= !empty($user['notify_new_gallery']) ? ' checked' : '' ?>>
                    New galleries
                </label>
                <label>
                    <input type="checkbox" name="notify_live" value="1"<?= !empty($user['notify_live']) ? ' checked' : '' ?>>
                    Live shows
                </label>
                <label>
                    <input type="checkbox" name="notify_chat_reply" value="1"<?= !empty($user['notify_chat_reply']) ? ' checked' : '' ?>>
                    Chat replies
                </label>
            </div>
            <div class="adm-field-actions">
                <button type="submit" class="btn">Save notification preferences</button>
            </div>
        </form>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Site timezone</h2>
        <p>Choose the timezone that dates and times across the site should be displayed in. Times are stored in UTC and converted to this timezone on display.</p>
    </div>
    <div class="sys-card">
        <form method="post" action="<?= e(url('/settings/timezone')) ?>">
            <?= csrf_field() ?>
            <label class="adm-field" for="site-timezone"><span>Timezone</span><select name="timezone" id="site-timezone">
                <option value="UTC"<?= $siteTimezone === 'UTC' ? ' selected' : '' ?>>UTC (Coordinated Universal Time)</option>
                <?php foreach ($siteTimezones as [$value, $label]): ?>
                    <option value="<?= e($value) ?>"<?= $siteTimezone === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
            <p class="muted">Current time in this timezone: <strong id="site-tz-now"><?= e(tzdate('Y-m-d H:i', null)) ?></strong></p>
            <div class="adm-field-actions">
                <button type="submit" class="btn">Save timezone</button>
            </div>
        </form>
        <script nonce="<?= csp_nonce() ?>">
        (function () {
            var select = document.getElementById('site-timezone');
            var now = document.getElementById('site-tz-now');
            if (!select || !now) return;
            function refresh() {
                var tz = select.value === 'UTC' ? 'UTC' : select.value;
                try {
                    var d = new Date();
                    now.textContent = d.toLocaleDateString('en-CA', { timeZone: tz }) + ' ' + d.toLocaleTimeString('en-GB', { timeZone: tz });
                } catch (e) { /* keep the server-rendered value */ }
            }
            select.addEventListener('change', refresh);
        }());
        </script>
    </div>
</section>

<?php // Super admins see the operator chat bridge connection details. ?>
<?php if (!empty($chatBridge) && !empty($chatBridge['visible'])): ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Operator chat bridge</h2>
        <p>Enter these in the Operator Chat Android app to connect as an operator.</p>
    </div>
    <div class="sys-card">
        <p><strong>Server URL</strong><br><code class="adm-clip-wrap"><?= e((string) $chatBridge['server_url']) ?></code></p>
        <p><strong>Bridge token</strong><br><code class="adm-clip-wrap"><?= e((string) $chatBridge['bridge_token']) ?></code></p>
        <p class="muted">Keep the token private - it grants operator reply and training-data access.</p>
    </div>
</section>
<?php endif; ?>
