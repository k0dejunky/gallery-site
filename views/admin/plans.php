<?php $title = 'Plans'; ?>

<h2>Plans</h2>
<?php if (empty($plans)): ?>
    <p>No plans yet.</p>
<?php else: ?>
    <div class="users-table-wrap">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Cycle</th>
                <th>Price</th>
                <th>Sort</th>
                <th>Level</th>
                <th>Braintree</th>
                <th>Status</th>
                <th>Subscribers</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($plans as $plan): ?>
                <tr>
                    <td><?= e($plan['name']) ?></td>
                    <td><?= e(\App\Models\Plan::cycleLabel($plan['billing_cycle'])) ?></td>
                    <td>$<?= number_format((float) $plan['price'], 2) ?></td>
                    <td><?= (int) $plan['sort_order'] ?></td>
                    <td><?= (int) ($plan['level'] ?? \App\Models\Plan::SILVER_LEVEL) ?></td>
                    <td><?= e((string) ($plan['braintree_plan_id'] ?? '')) ?: '<span class="muted">—</span>' ?></td>
                    <td><?= (int) $plan['active'] === 1 ? 'Active' : 'Inactive' ?></td>
                    <td><?= (int) $plan['subscriber_count'] ?></td>
                    <td>
                        <a class="btn btn-sm" href="<?= url('/admin/plans/' . (int) $plan['id'] . '/edit') ?>">Edit</a>
                        <form class="inline" method="post" action="<?= url('/admin/plans/' . (int) $plan['id'] . '/delete') ?>"
                              data-confirm="Delete this plan and its subscriptions?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<p style="margin: var(--spacing-md) 0 var(--spacing-lg);">
    <a class="btn" href="<?= url('/admin/plans/create') ?>">Add New Membership Plan</a>
    <form class="inline" method="post" action="<?= url('/admin/plans/provision-braintree') ?>" style="display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline" title="Create a Braintree subscription plan for every active recurring tier and save its id">Provision Braintree plans</button>
    </form>
</p>

<section class="sales-panel">
    <div class="sales-panel-header">
        <div>
            <h2>Membership Sales</h2>
            <p class="muted">Discount a plan by price, time, subscription count, or a combination.</p>
        </div>
    </div>
    <form method="post" action="<?= url('/admin/sales') ?>">
        <?= csrf_field() ?>
        <div class="sale-form-grid">
            <label>Plan
                <select name="plan_id" required>
                    <option value="">Choose an active plan</option>
                    <?php foreach ($plans as $plan): ?>
                        <?php if ((int) $plan['active'] !== 1) continue; ?>
                        <option value="<?= (int) $plan['id'] ?>"><?= e($plan['name']) ?> · $<?= number_format((float) $plan['price'], 2) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Sale name
                <input type="text" name="name" maxlength="120" required placeholder="Summer sale">
            </label>
            <label>Sale price
                <input type="number" name="sale_price" min="0" step="0.01" required placeholder="0.00">
            </label>
            <label>Maximum subscriptions <span class="muted">(optional)</span>
                <input type="number" name="max_subscriptions" min="1" step="1" placeholder="Unlimited">
            </label>
            <label>Sale ends <span class="muted">(optional)</span>
                <input type="datetime-local" name="ends_at">
            </label>
            <div class="sale-form-wide"><button type="submit" class="btn">Create Sale</button></div>
        </div>
    </form>

    <?php if (empty($sales)): ?>
        <p class="muted" style="margin-bottom:0;">No sales created yet.</p>
    <?php else: ?>
        <div class="sales-cards">
            <?php foreach ($sales as $sale): ?>
                <article class="sale-card<?= (int) $sale['active'] === 1 ? '' : ' inactive' ?>">
                    <h3><?= e($sale['name']) ?></h3>
                    <div class="sale-price">$<?= number_format((float) $sale['sale_price'], 2) ?> <small class="muted">for <?= e($sale['plan_name']) ?></small></div>
                    <div class="sale-meta">
                        <?= $sale['max_subscriptions'] === null ? 'Unlimited subscriptions' : (int) $sale['reserved_count'] . ' of ' . (int) $sale['max_subscriptions'] . ' subscriptions reserved' ?><br>
                        <?= !empty($sale['ends_at']) ? 'Ends ' . e(tzdate('M j, Y g:i A', $sale['ends_at'])) : 'No end date' ?><br>
                        <?php foreach (($saleCodes[(int) $sale['id']] ?? []) as $code): ?>
                            <span class="sale-code"><?= e($code['code']) ?></span>
                            <?= $code['max_uses'] === null ? 'unlimited uses' : (int) $code['used_count'] . ' / ' . (int) $code['max_uses'] . ' uses' ?> · <?= (int) $code['active'] === 1 ? 'Active' : 'Deactivated' ?> · created <?= e(tzdate('Y-m-d H:i', $code['created_at'])) ?><br>
                        <?php endforeach; ?>
                    </div>
                    <form class="inline" method="post" action="<?= url('/admin/sales/' . (int) $sale['id'] . '/toggle') ?>">
                        <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline"><?= (int) $sale['active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form class="inline" method="post" action="<?= url('/admin/sales/' . (int) $sale['id'] . '/delete') ?>" data-confirm="Delete this sale?"
                        <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="sales-panel u-mt-1">
    <?php
    $membershipLevels = [];
    foreach ($plans as $plan) {
        $levelId = (int) ($plan['level'] ?? \App\Models\Plan::SILVER_LEVEL);
        if (!isset($membershipLevels[$levelId])) {
            $membershipLevels[$levelId] = $plan['name'];
        }
    }
    ksort($membershipLevels);
    ?>
    <div class="sales-panel-header">
        <div>
            <h2>Standalone Promotion Codes</h2>
            <p class="muted">These codes work independently and stack on top of any active plan sale.</p>
        </div>
    </div>
    <form method="post" action="<?= url('/admin/sale-codes') ?>">
        <?= csrf_field() ?>
        <div class="sale-form-grid">
            <label>Maximum uses
                <input type="number" name="max_uses" min="1" step="1" required placeholder="Required">
            </label>
            <label>Discount type
                <select name="discount_type">
                    <option value="none">No price discount</option>
                    <option value="fixed">Fixed amount off</option>
                    <option value="percent">Percentage off</option>
                </select>
            </label>
            <label>Discount value
                <input type="number" name="discount_value" min="0" step="0.01" value="0">
            </label>
            <label>Membership level upgrade <span class="muted">(optional)</span>
                <select name="upgrade_level">
                    <option value="">No upgrade</option>
                    <?php foreach ($membershipLevels as $levelId => $levelName): ?>
                        <option value="<?= (int) $levelId ?>"><?= e($levelName) ?> (Level <?= (int) $levelId ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Applies to membership level
                <select name="target_level" required>
                    <option value="">Choose membership level</option>
                    <?php foreach ($membershipLevels as $levelId => $levelName): ?>
                        <option value="<?= (int) $levelId ?>"><?= e($levelName) ?> (Level <?= (int) $levelId ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Promotion code name
                <input type="text" name="name" maxlength="120" required placeholder="Summer upgrade offer">
            </label>
            <div class="sale-form-wide"><button type="submit" class="btn">Generate Promotion Code</button></div>
        </div>
    </form>
    <?php if (!empty($allCodes)): ?>
        <div class="sales-cards promotion-code-tiles">
            <?php foreach ($allCodes as $code): ?>
                <?php $usagePercent = $code['max_uses'] ? min(100, ((int) $code['used_count'] / (int) $code['max_uses']) * 100) : 0; ?>
                <article class="sale-card promotion-code-card<?= (int) $code['active'] === 1 ? '' : ' inactive' ?>">
                    <div class="promotion-code-header"><h3><?= e($code['name'] ?: 'Unnamed promotion') ?></h3><span class="promotion-code-status"><?= (int) $code['active'] === 1 ? 'Active' : 'Deactivated' ?></span></div>
                    <span class="promotion-code-value"><?= e($code['code']) ?></span>
                    <div class="promotion-code-benefits">
                        <span class="promotion-code-benefit"><?php if ($code['discount_type'] === 'percent'): ?><?= e($code['discount_value']) ?>% off<?php elseif ($code['discount_type'] === 'fixed'): ?>$<?= number_format((float) $code['discount_value'], 2) ?> off<?php else: ?>No price discount<?php endif; ?></span>
                        <span class="promotion-code-benefit">Applies to <?= e($membershipLevels[(int) $code['target_level']] ?? 'Level ' . (int) $code['target_level']) ?></span>
                        <?php if (!empty($code['upgrade_level'])): ?><span class="promotion-code-benefit">Upgrades to <?= e($membershipLevels[(int) $code['upgrade_level']] ?? 'Level ' . (int) $code['upgrade_level']) ?></span><?php endif; ?>
                    </div>
                    <div class="promotion-code-usage"><?= $code['max_uses'] === null ? 'Unlimited uses' : (int) $code['used_count'] . ' / ' . (int) $code['max_uses'] . ' uses' ?><?php if ($code['max_uses'] !== null): ?><div class="promotion-code-meter"><span style="width:<?= e($usagePercent) ?>%"></span></div><?php endif; ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
