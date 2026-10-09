We could not collect the <?= e('$' . number_format((float) ($amount ?? 0), 2)) ?> charge for your
<?= e($planName ?? 'membership') ?> on <?= e($attemptedAt ?? 'recently') ?>. Your access is still active for now.

Update your payment details here so your membership continues without interruption:
<?= e($manageUrl ?? absolute_url('/membership/my')) ?>

This is an important notice about your <?= e((string) config('app.site_name')) ?> account.