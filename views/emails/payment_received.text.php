We received your <?= e('$' . number_format((float) ($amount ?? 0), 2)) ?> payment for your
<?= e($planName ?? 'membership') ?> on <?= e($paidAt ?? 'recently') ?>. Thank you — your access continues.

Plan: <?= e($planName ?? 'membership') ?>
Amount: <?= e('$' . number_format((float) ($amount ?? 0), 2)) ?>
Receipt: <?= e($receiptNumber ?? '') ?>
<?php if (!empty($expiresAt)): ?>Next payment due: <?= e($expiresAt) ?>
<?php endif; ?>
Manage your membership here:
<?= e($manageUrl ?? absolute_url('/membership/my')) ?>

This is an important notice about your <?= e((string) config('app.site_name')) ?> account.