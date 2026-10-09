<?php
// Standalone-ish confirmation shown after a PayPal one-off capture redirect.
// The purchase is settled asynchronously by the capture webhook.
$purchase = $purchase ?? null;
$kind     = is_array($purchase) && ($purchase['item_type'] ?? '') === 'tip' ? 'tip' : 'PPV unlock';
$amount   = is_array($purchase) ? (float) ($purchase['amount'] ?? 0) : 0.0;
$itemId   = is_array($purchase) ? (int) ($purchase['item_id'] ?? 0) : 0;
?>
<div class="card" style="max-width:560px;margin:2rem auto;text-align:center;padding:var(--spacing-lg);">
    <h1>Payment received</h1>
    <?php if (is_array($purchase)): ?>
        <p class="muted">Your <?= e($kind) ?> of <strong>$<?= number_format($amount, 2) ?></strong> is being finalised
            and will be applied to your account within a moment.</p>
        <?php if ($kind === 'PPV unlock'): ?>
            <p><a class="btn" href="<?= url('/galleries/' . $itemId) ?>">Open the gallery</a></p>
        <?php else: ?>
            <p><a class="btn" href="<?= url('/account') ?>">Back to your dashboard</a></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted">We couldn't match that payment to your account. If you were charged, contact support.</p>
        <p><a class="btn btn-outline" href="<?= url('/account') ?>">Back to your dashboard</a></p>
    <?php endif; ?>
</div>