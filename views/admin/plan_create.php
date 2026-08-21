<?php $title = 'Add Membership Plan'; ?>

<p><a href="<?= url('/admin/plans') ?>">&larr; Plans</a></p>

<h1>Add New Membership Plan</h1>
<form method="post" action="<?= url('/admin/plans') ?>" class="admin-form">
    <?= csrf_field() ?>
    <p>
        <label for="name">Name</label><br>
        <input type="text" name="name" id="name" required>
    </p>
    <p>
        <label for="billing_cycle">Billing Cycle</label><br>
        <select name="billing_cycle" id="billing_cycle">
            <option value="monthly">Monthly</option>
            <option value="yearly">Yearly</option>
            <option value="lifetime">Lifetime</option>
        </select>
    </p>
    <p>
        <label for="price">Price</label><br>
        <input type="text" name="price" id="price" inputmode="decimal" required>
    </p>
    <p>
        <label for="description">Description</label><br>
        <textarea name="description" id="description" rows="2"></textarea>
    </p>
    <p>
        <label for="sort_order">Sort Order</label><br>
        <input type="number" name="sort_order" id="sort_order" value="0">
    </p>
    <p>
        <label for="level">Level</label><br>
        <input type="number" name="level" id="level" min="1" value="<?= \App\Models\Plan::SILVER_LEVEL ?>">
        <small>Minimum level required to unlock level-gated features (e.g. Silver = 1).</small>
    </p>
    <p>
        <label><input type="checkbox" name="active" value="1" checked> Active</label>
    </p>
    <p>
        <button type="submit" class="btn">Add Plan</button>
    </p>
</form>
