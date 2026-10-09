<?php
/**
 * "Your subscription is past due" — billing / retention. Transactional (no
 * unsubscribe link). Variables: $planName, $dueAmount, $manageUrl.
 */
$siteName  = (string) config('app.site_name');
$planName  = (string) ($planName ?? 'your membership');
$dueAmount = '$' . number_format((float) ($dueAmount ?? 0), 2);
$manageUrl = (string) ($manageUrl ?? absolute_url('/membership/my'));
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Your subscription is past due</title></head>
<body style="margin:0;padding:0;background:#f7dfea;font-family:Arial,Helvetica,sans-serif;color:#3b2550;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7dfea;padding:24px 12px;"><tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e9c3d6;">
        <tr><td style="background:#6d2ea8;padding:20px 28px;text-align:center;"><a href="<?= e(absolute_url('/')) ?>" style="font-size:20px;font-weight:bold;color:#fff;text-decoration:none;"><?= e($siteName) ?></a></td></tr>
        <tr><td style="padding:28px 28px 8px;">
            <h1 style="margin:0 0 8px;font-size:22px;color:#3b2550;">Your subscription is past due</h1>
            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">We haven't been able to collect the <?= e($dueAmount) ?> due for
            your <strong><?= e($planName) ?></strong> membership. Please sort this soon so your access is not interrupted.</p>
        </td></tr>
        <tr><td align="center" style="padding:24px 28px;"><a href="<?= e($manageUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#fff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Update payment details</a></td></tr>
        <tr><td style="padding:20px 28px;background:#fbf1f7;border-top:1px solid #ecd9e5;">
            <p style="margin:0;font-size:12px;line-height:1.6;color:#8a7a99;">This is an important notice about your <?= e($siteName) ?> account.</p>
            <p style="margin:6px 0 0;font-size:11px;color:#b3a3c0;">&copy; <?= date('Y') ?> <?= e($siteName) ?></p>
        </td></tr>
    </table>
</td></tr></table>
</body>
</html>