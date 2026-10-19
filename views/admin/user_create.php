<?php $title = 'Create User'; ?>

<form method="post" action="<?= url('/admin/users') ?>" class="admin-form create-user-form">
    <?= csrf_field() ?>

    <div class="create-user-column">
    <h2>Account</h2>
    <p>
        <label for="billing_first_name">First Name</label><br>
        <input type="text" name="billing_first_name" id="billing_first_name">
    </p>
    <p>
        <label for="billing_last_name">Last Name</label><br>
        <input type="text" name="billing_last_name" id="billing_last_name">
    </p>
    <p>
        <label for="email">Email</label><br>
        <input type="email" name="email" id="email" placeholder="email@example.com" required>
    </p>
    <p>
        <label for="password">Password</label><br>
        <input type="password" name="password" id="password" placeholder="Minimum 8 characters" minlength="8" required>
    </p>
    <p>
        <label for="role">Role</label><br>
        <select name="role" id="role">
            <option value="user" selected>User</option>
            <option value="editor">Editor</option>
            <option value="moderator">Moderator</option>
            <option value="viewer">Viewer</option>
            <option value="admin">Admin</option>
            <?php if (\App\Core\Auth::can('manage_roles')): ?><option value="super_admin">Super Admin</option><?php endif; ?>
        </select>
    </p>

    <h2>Age Verification</h2>
    <p>
        <label for="date_of_birth">Date of Birth</label><br>
        <input type="date" name="date_of_birth" id="date_of_birth" placeholder="MM/DD/YYYY" data-picker data-picker-focus>
    </p>
    </div>

    <div class="create-user-column">
    <h2>Billing <span class="muted">(optional)</span></h2>
    <p>
        <label for="billing_address_line1">Address Line 1</label><br>
        <input type="text" name="billing_address_line1" id="billing_address_line1">
    </p>
    <p>
        <label for="billing_address_line2">Address Line 2</label><br>
        <input type="text" name="billing_address_line2" id="billing_address_line2">
    </p>
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

    <h2>Membership</h2>
    <p>
        <label for="plan_id">Plan</label><br>
        <select name="plan_id" id="plan_id">
            <option value="0">None</option>
            <?php foreach ($plans as $plan): ?>
                <option value="<?= (int) $plan['id'] ?>"><?= e($plan['name']) ?> — $<?= number_format($plan['price'], 2) ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    </div>

    <p class="create-user-actions">
        <button type="submit" class="btn">Create User</button>
        <a class="btn btn-outline" href="<?= url('/admin/users') ?>">Cancel</a>
    </p>
</form>