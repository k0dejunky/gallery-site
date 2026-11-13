<?php $title = 'Gift a membership'; ?>
<?php
/** @var array<int, array<string, mixed>> $plans */
/** @var array<int, array<string, mixed>> $gifts */
$plans = $plans ?? [];
$gifts = $gifts ?? [];
?>
<div class="auth-panel" style="max-width: 900px;">
    <h1>Gift a membership</h1>
    <p><a class="btn btn-outline" href="<?= e(url('/membership')) ?>">&larr; Back to membership</a></p>

    <div class="card">
        <p style="margin-top:0;">
            A gift is a <strong>single-use code</strong> worth one membership at the tier you choose.
            Send it to whoever you like: they enter it under
            &ldquo;Have a promotion code?&rdquo; on the <a href="<?= e(url('/membership')) ?>">membership page</a>
            and their membership starts at once. Nothing is charged to them.
        </p>
        <p class="muted" style="margin-bottom:0;">
            The code appears here as soon as your payment is confirmed. A gift code is used up by the
            first redemption and cannot be transferred back.
        </p>
    </div>

    <?php if ($plans === []): ?>
        <div class="empty-state">
            <p class="muted">There is no paid plan to gift right now.</p>
        </div>
    <?php else: ?>
        <div class="gift-shop">
            <?php foreach ($plans as $plan): ?>
                <?php
                $planId    = (int) $plan['id'];
                $planName  = (string) $plan['name'];
                $planPrice = (float) ($plan['price'] ?? 0);
                $tier      = \App\Models\Subscription::levelLabel((int) ($plan['level'] ?? 0));
                $cycle     = ucfirst((string) ($plan['billing_cycle'] ?? ''));
                ?>
                <div class="card gift-plan">
                    <h2 style="margin-top:0;"><?= e($planName) ?></h2>
                    <p class="muted" style="margin:.25rem 0;">
                        <?= e($tier) ?> access &middot; <?= e($cycle) ?><?= !empty($plan['description']) ? ' — ' . e((string) $plan['description']) : '' ?>
                    </p>
                    <p style="font-size:1.5rem;margin:.4rem 0;">
                        $<?= e(number_format($planPrice, 2)) ?>
                    </p>
                    <?php
                    // The reusable one-off checkout block. The price is the
                    // plan's, and the server re-reads it from the plan; the
                    // hidden plan_id is all the request carries.
                    $ccAction      = url('/gift/live');
                    $ccId          = 'gift-' . $planId;
                    $ccAmount      = number_format($planPrice, 2);
                    $ccLabel       = 'Buy a ' . $planName . ' gift';
                    $ccExtraFields = ['plan_id' => $planId];
                    require __DIR__ . '/../partials/card_checkout.php';
                    ?>
                    <form class="inline" method="post" action="<?= e(url('/gift/request')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="plan_id" value="<?= $planId ?>">
                        <button type="submit" class="btn btn-sm btn-ghost" title="We will arrange the payment with you; the code appears once it is confirmed.">
                            Request to pay another way
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 style="margin-top:1.5rem;">Gifts I sent</h2>

    <?php if ($gifts === []): ?>
        <div class="empty-state">
            <p class="muted">You have not bought a gift yet.</p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="membership-history">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Bought</th>
                        <th>Paid</th>
                        <th>State</th>
                        <th>Code</th>
                        <th>Redeemed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gifts as $gift): ?>
                        <?php
                        $state = (string) ($gift['state'] ?? '');
                        $badge = [
                            \App\Models\Gift::STATE_AVAILABLE => 'active',
                            \App\Models\Gift::STATE_REDEEMED  => 'active',
                            \App\Models\Gift::STATE_PENDING   => 'pending',
                            \App\Models\Gift::STATE_EXPIRED   => 'expired',
                            \App\Models\Gift::STATE_VOID      => 'refunded',
                        ][$state] ?? 'pending';
                        $codeElId = 'gifted-code-' . (int) $gift['purchase_id'];
                        ?>
                        <tr>
                            <td><?= e((string) ($gift['plan_name'] ?? '—')) ?></td>
                            <td><?= !empty($gift['purchased_at']) ? e(tzdate('Y-m-d', (string) $gift['purchased_at'])) : '—' ?></td>
                            <td>$<?= e(number_format((float) ($gift['amount'] ?? 0), 2)) ?></td>
                            <td>
                                <span class="status-badge <?= e($badge) ?>"><?= e((string) ($gift['state_label'] ?? '')) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($gift['code'])): ?>
                                    <code id="<?= e($codeElId) ?>"><?= e((string) $gift['code']) ?></code>
                                    <button type="button" class="btn btn-sm" data-copy="<?= e($codeElId) ?>">Copy</button>
                                <?php elseif ($state === \App\Models\Gift::STATE_PENDING): ?>
                                    <span class="muted">Appears after payment</span>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($gift['redeemed_at']) ? e(tzdate('Y-m-d', (string) $gift['redeemed_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top:.6rem;">
            A code marked &ldquo;Ready to give&rdquo; has not been used. &ldquo;Redeemed&rdquo; means
            somebody has already entered it; a refunded gift's code no longer works.
        </p>
    <?php endif; ?>
</div>
