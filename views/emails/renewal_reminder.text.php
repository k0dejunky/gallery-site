Your <?= e($planName ?? 'membership') ?> renews on <?= e($renewsOn ?? 'soon') ?> for
<?= e('$' . number_format((float) ($amount ?? 0), 2)) ?>. No action is needed — we will simply charge
the card you have on file.

If your card has changed, or you would like to cancel instead, head to your membership page before the renewal date:
<?= e($manageUrl ?? absolute_url('/membership/my')) ?>

A friendly reminder about your <?= e((string) config('app.site_name')) ?> membership.