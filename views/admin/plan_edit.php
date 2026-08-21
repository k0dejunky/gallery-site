<?php $title = 'Edit Plan'; ?>

<form method="post" action="<?= url('/admin/plans/' . (int) $plan['id']) ?>" class="admin-form">
    <?= csrf_field() ?>
    <p>
        <label for="name">Name</label><br>
        <input type="text" name="name" id="name" value="<?= e($plan['name']) ?>" required>
    </p>
    <p>
        <label for="billing_cycle">Billing Cycle</label><br>
        <select name="billing_cycle" id="billing_cycle">
            <?php foreach (['monthly', 'yearly', 'lifetime'] as $cycle): ?>
                <option value="<?= $cycle ?>" <?= $plan['billing_cycle'] === $cycle ? 'selected' : '' ?>><?= e(\App\Models\Plan::cycleLabel($cycle)) ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="price">Price</label><br>
        <input type="text" name="price" id="price" value="<?= e(number_format((float) $plan['price'], 2)) ?>" inputmode="decimal" required>
    </p>
    <p>
        <label for="description">Description</label><br>
        <textarea name="description" id="description" rows="2"><?= e($plan['description']) ?></textarea>
    </p>
    <p>
        <label for="sort_order">Sort Order</label><br>
        <input type="number" name="sort_order" id="sort_order" value="<?= (int) $plan['sort_order'] ?>">
    </p>
    <p>
        <label for="level">Level</label><br>
        <input type="number" name="level" id="level" min="1" value="<?= (int) ($plan['level'] ?? \App\Models\Plan::SILVER_LEVEL) ?>">
        <small>Minimum level required to unlock level-gated features (e.g. Silver = 1).</small>
    </p>
    <p>
        <label><input type="checkbox" name="active" value="1" <?= (int) $plan['active'] === 1 ? 'checked' : '' ?>> Active</label>
    </p>
    <p>
        <button type="submit" class="btn">Save Changes</button>
        <a class="btn btn-outline" href="<?= url('/admin/plans') ?>">Cancel</a>
    </p>
</form>
