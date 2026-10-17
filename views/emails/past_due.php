<?php
/**
 * "Your subscription is past due" — billing / retention. Transactional (no
 * unsubscribe link). Variables: $planName, $dueAmount, $manageUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName  = (string) ($planName ?? 'your membership');
$dueAmount = '$' . number_format((float) ($dueAmount ?? 0), 2);
$manageUrl = (string) ($manageUrl ?? absolute_url('/membership/my'));

$emailTitle  = 'Your subscription is past due';
$headerHref  = absolute_url('/');
$emailFooter = 'transactional';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">Your subscription is past due</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">We haven't been able to collect the <?= e($dueAmount) ?> due for
                        your <strong><?= e($planName) ?></strong> membership. Please sort this soon so your access is not interrupted.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($manageUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Update payment details</a>
                    </td>
                </tr>
