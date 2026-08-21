<?php $title = 'Sales'; ?>

<h1>Membership Sales</h1>

<div class="card">
    <h2>Create Sale</h2>
    <form method="post" action="<?= url('/admin/sales') ?>">
        <?= csrf_field() ?>
        <p><label>Plan<br><select name="plan_id" required><option value="">Choose plan</option><?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"><?= e($plan['name']) ?> ($<?= number_format((float) $plan['price'], 2) ?>)</option><?php endforeach; ?></select></label></p>
        <p><label>Sale name<br><input type="text" name="name" maxlength="120" required placeholder="Summer sale"></label></p>
        <p><label>Sale price<br><input type="number" name="sale_price" min="0" step="0.01" required></label></p>
        <p><label>Maximum subscriptions <span class="muted">(optional)</span><br><input type="number" name="max_subscriptions" min="1" step="1" placeholder="Unlimited"></label></p>
        <p><label>Sale ends <span class="muted">(optional)</span><br><input type="datetime-local" name="ends_at"></label></p>
        <p><label>Sale code <span class="muted">(optional)</span><br><input type="text" name="code" maxlength="120" placeholder="SUMMER2026"></label></p>
        <p><label>Code maximum uses <span class="muted">(optional)</span><br><input type="number" name="code_max_uses" min="1" step="1" placeholder="Unlimited"></label></p>
        <button type="submit" class="btn">Create Sale</button>
    </form>
</div>

<h2>Existing Sales</h2>
<?php if (empty($sales)): ?>
    <p class="muted">No sales created.</p>
<?php else: ?>
<table>
    <thead><tr><th>Sale</th><th>Plan</th><th>Price</th><th>Limits</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($sales as $sale): ?>
        <tr>
            <td><?= e($sale['name']) ?></td>
            <td><?= e($sale['plan_name']) ?></td>
            <td>$<?= number_format((float) $sale['sale_price'], 2) ?></td>
            <td><?= $sale['max_subscriptions'] === null ? 'Unlimited' : (int) $sale['reserved_count'] . ' / ' . (int) $sale['max_subscriptions'] ?><?php if (!empty($sale['ends_at'])): ?><br>until <?= e(date('Y-m-d H:i', strtotime($sale['ends_at']))) ?><?php endif; ?><br><?php foreach (($codes[(int) $sale['id']] ?? []) as $code): ?>Code <code><?= e($code['code']) ?></code> (<?= $code['max_uses'] === null ? 'unlimited' : (int) $code['used_count'] . ' / ' . (int) $code['max_uses'] ?> uses)<br><?php endforeach; ?></td>
            <td><?= (int) $sale['active'] === 1 ? 'Active' : 'Inactive' ?></td>
            <td>
                <form method="post" action="<?= url('/admin/sales/' . (int) $sale['id'] . '/toggle') ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline" type="submit"><?= (int) $sale['active'] === 1 ? 'Deactivate' : 'Activate' ?></button></form>
                <form method="post" action="<?= url('/admin/sales/' . (int) $sale['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Delete this sale?')"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
