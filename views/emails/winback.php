<?php
/**
 * "We miss you" win-back — marketing / retention. This one carries its own
 * footer wording (the member had a membership rather than still having one),
 * and the {{unsubscribe-url}} placeholder is swapped by the worker.
 * Variables: $planName, $galleryUrl, $membershipUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName      = (string) ($planName ?? 'your membership');
$galleryUrl    = (string) ($galleryUrl ?? absolute_url('/galleries'));
$membershipUrl = (string) ($membershipUrl ?? \App\Models\Traffic::buildUrl('/membership', 'email-digest'));

$emailTitle  = 'We miss you';
$headerHref  = absolute_url('/');
$emailFooter = 'marketing';
$emailFooterNote = 'You are receiving this because you had a ' . e((string) config('app.site_name')) . ' membership. '
    . 'If you\'d rather not hear from us, you can <a href="{{unsubscribe-url}}" style="color:#6d2ea8;">unsubscribe</a>.';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">We miss you</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">Your <strong><?= e($planName) ?></strong> membership has been
                        away for a little while, and the site keeps growing — new galleries and videos are being added all the time.</p>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;"><a href="<?= e($galleryUrl) ?>" style="color:#6d2ea8;">Browse the latest</a> and, if it feels right, come on back.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($membershipUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Rejoin today</a>
                    </td>
                </tr>
