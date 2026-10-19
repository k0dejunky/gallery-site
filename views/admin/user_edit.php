<?php $title = 'Edit User'; ?>

<form method="post" action="<?= url('/admin/users/' . (int) $user['id']) ?>" class="admin-form edit-user-form">
    <?= csrf_field() ?>

    <div class="edit-user-column">
    <h2>Account</h2>
    <p>
        <label for="billing_first_name">First Name</label><br>
        <input type="text" name="billing_first_name" id="billing_first_name" value="<?= e($user['billing_first_name'] ?? '') ?>">
    </p>
    <p>
        <label for="billing_last_name">Last Name</label><br>
        <input type="text" name="billing_last_name" id="billing_last_name" value="<?= e($user['billing_last_name'] ?? '') ?>">
    </p>
    <p>
        <label for="email">Email</label><br>
        <input type="email" name="email" id="email" value="<?= e($user['email']) ?>" required>
    </p>
    <p>
        <label for="role">Role</label><br>
        <select name="role" id="role">
            <option value="user"<?= $user['role'] === 'user' ? ' selected' : '' ?>>User</option>
            <option value="editor"<?= $user['role'] === 'editor' ? ' selected' : '' ?>>Editor</option>
            <option value="moderator"<?= $user['role'] === 'moderator' ? ' selected' : '' ?>>Moderator</option>
            <option value="viewer"<?= $user['role'] === 'viewer' ? ' selected' : '' ?>>Viewer</option>
            <option value="admin"<?= $user['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
            <?php if (\App\Core\Auth::can('manage_roles')): ?><option value="super_admin"<?= $user['role'] === 'super_admin' ? ' selected' : '' ?>>Super Admin</option><?php endif; ?>
        </select>
    </p>
    <p>
        <label for="password">New Password</label><br>
        <input type="password" name="password" id="password" minlength="8" placeholder="Leave blank to keep current">
    </p>

    <h2>Age Verification</h2>
    <p>
        <label for="date_of_birth">Date of Birth</label><br>
        <input type="date" name="date_of_birth" id="date_of_birth" value="<?= e($user['date_of_birth'] ?? '') ?>">
    </p>
    <p>
        <label><input type="checkbox" name="age_verified" value="1"<?= !empty($user['age_verified']) ? ' checked' : '' ?>> Age Verified</label>
        <?php if (!empty($user['age_verified_at'])): ?>
            <span class="muted">(verified <?= e(tzdate('Y-m-d H:i', $user['age_verified_at'])) ?>)</span>
        <?php endif; ?>
    </p>
    </div>

    <div class="edit-user-column">
    <h2>Billing</h2>
    <p>
        <label for="billing_address_line1">Address Line 1</label><br>
        <input type="text" name="billing_address_line1" id="billing_address_line1" value="<?= e($user['billing_address_line1'] ?? '') ?>">
    </p>
    <p>
        <label for="billing_address_line2">Address Line 2</label><br>
        <input type="text" name="billing_address_line2" id="billing_address_line2" value="<?= e($user['billing_address_line2'] ?? '') ?>">
    </p>
    <p>
        <label for="billing_city">City</label><br>
        <input type="text" name="billing_city" id="billing_city" value="<?= e($user['billing_city'] ?? '') ?>">
    </p>
    <p>
        <label for="billing_state">State</label><br>
        <input type="text" name="billing_state" id="billing_state" value="<?= e($user['billing_state'] ?? '') ?>" maxlength="50">
    </p>
    <p>
        <label for="billing_zip">ZIP / Postal Code</label><br>
        <input type="text" name="billing_zip" id="billing_zip" value="<?= e($user['billing_zip'] ?? '') ?>" maxlength="20">
    </p>
    <p>
        <label for="billing_country">Country</label><br>
        <input type="text" name="billing_country" id="billing_country" value="<?= e($user['billing_country'] ?? '') ?>" maxlength="2" placeholder="US">
    </p>

    <?php if (!empty($user['payment_customer_id']) || !empty($user['card_last_four'])): ?>
    <h2>Payment Method</h2>
    <p>
        <?php if (!empty($user['card_last_four'])): ?>
            <?= e(ucfirst($user['card_brand'] ?? 'card')) ?> ending in <?= e($user['card_last_four']) ?>
            (expires <?= sprintf('%02d/%04d', $user['card_exp_month'] ?? 0, $user['card_exp_year'] ?? 0) ?>)
        <?php else: ?>
            <span class="muted">No card on file</span>
        <?php endif; ?>
    </p>
    <?php endif; ?>
    </div>

    <p class="edit-user-actions">
        <button type="submit" class="btn">Save Changes</button>
        <a class="btn btn-outline" href="<?= url('/admin/users') ?>">Cancel</a>
    </p>
</form>
