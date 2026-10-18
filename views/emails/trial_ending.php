<?php
/**
 * "Your free trial ends soon" — conversion nudge sent a few days before a
 * TRIAL-% subscription expires (renewal reminders skip trials). Marketing, so
 * it carries the unsubscribe footer. Variables: $planName, $endsOn,
 * $galleryUrl, $membershipUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName      = (string) ($planName ?? 'your membership');
$endsOn        = (string) ($endsOn ?? 'soon');
$galleryUrl    = (string) ($galleryUrl ?? absolute_url('/galleries'));
$membershipUrl = (string) ($membershipUrl ?? \App\Models\Traffic::buildUrl('/membership', 'email-digest'));

$emailTitle  = 'Your free trial ends soon';
$headerHref  = absolute_url('/');
$emailFooter = 'marketing';
$emailFooterNote = 'You are receiving this because you started a free trial on ' . e((string) config('app.site_name')) . '. '
    . 'If you\'d rather not hear from us, you can <a href="{{unsubscribe-url}}" style="color:#6d2ea8;">unsubscribe</a>.';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">Your free trial ends soon</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">Your <strong><?= e($planName) ?></strong> trial access ends on
                        <strong><?= e($endsOn) ?></strong>. After that the member galleries, videos and downloads close again.</p>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">If you've been enjoying it, keep your access going — it takes a minute, and you can
                        <a href="<?= e($galleryUrl) ?>" style="color:#6d2ea8;">take one more look around first</a>.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($membershipUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Keep full access</a>
                    </td>
                </tr>
