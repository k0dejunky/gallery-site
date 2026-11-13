Your paid access ends soon.

The payment for your <?= e($planName ?? 'membership') ?> membership has not gone through, and the grace period ends on <?= e($endsOn !== '' ? $endsOn : 'the date shown on your membership page') ?>.

After that date the paid membership ends and your account stays open on the free tier. You keep your account, your favorites and access to the free galleries - nothing is deleted - but the members-only sets will close. You can upgrade again at any time.

Update your card here:
<?= e($manageUrl ?? absolute_url('/membership/my')) ?>

See the plans here:
<?= e($membershipUrl ?? absolute_url('/membership')) ?>

This is an important notice about your <?= e((string) config('app.site_name')) ?> account.
