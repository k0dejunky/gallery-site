We still can't charge your card.

The <?= e('$' . number_format((float) ($amount ?? 0), 2)) ?> payment for your <?= e($planName ?? 'membership') ?> membership has still not gone through.

<?php if (!empty($graceEndsOn)): ?>
Please update your payment details before <?= e($graceEndsOn) ?> to keep your paid access. If it is still failing on that date, the paid membership ends and your account stays open on the free tier: you can still sign in and browse the free galleries, and nothing is deleted.
<?php else: ?>
Please update your payment details to keep your paid access. If it keeps failing, the paid membership ends and your account stays open on the free tier.
<?php endif; ?>

Update your card here:
<?= e($manageUrl ?? absolute_url('/membership/my')) ?>

This is an important notice about your <?= e((string) config('app.site_name')) ?> account.
