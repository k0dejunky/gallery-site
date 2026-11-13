<?php
/**
 * "Your paid access ends soon" — the last dunning notice before the account
 * drops to the free tier. Transactional (no unsubscribe link).
 * Variables: $planName, $endsOn, $manageUrl, $membershipUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName      = (string) ($planName ?? 'your membership');
$endsOn        = (string) ($endsOn ?? '');
$manageUrl     = (string) ($manageUrl ?? absolute_url('/membership/my'));
$membershipUrl = (string) ($membershipUrl ?? absolute_url('/membership'));

$emailTitle  = 'Your paid access ends soon';
$headerHref  = $manageUrl;
$emailFooter = 'transactional';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">Your paid access ends soon</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">The payment for your
                        <strong><?= e($planName) ?></strong> membership has not gone through, and the grace period ends on
                        <strong><?= e($endsOn !== '' ? $endsOn : 'the date shown on your membership page') ?></strong>.</p>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">After that date the paid membership ends and your
                        account stays open on the <strong>free tier</strong>. You keep your account, your favorites and access to the free
                        galleries - nothing is deleted - but the members-only sets will close. You can upgrade again at any time.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($manageUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Update my card</a>
                        <a href="<?= e($membershipUrl) ?>" style="display:inline-block;margin-left:10px;color:#6d2ea8;text-decoration:underline;font-size:14px;padding:12px 8px;">See the plans</a>
                    </td>
                </tr>
