Your subscription is past due.

We haven't been able to collect the <?= e('$' . number_format((float) ($dueAmount ?? 0), 2)) ?> due for
your <?= e($planName ?? 'membership') ?>. Please sort this soon so your access is not interrupted.

Update your payment details here:
<?= e($manageUrl ?? absolute_url('/membership/my')) ?>

This is an important notice about your <?= e((string) config('app.site_name')) ?> account.