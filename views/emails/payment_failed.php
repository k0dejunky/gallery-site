<?php
/**
 * "Your payment didn't go through" — billing / retention. Rendered at enqueue
 * time. Transactional: deliberately no unsubscribe link (account-critical).
 * Variables: $planName, $amount, $attemptedAt, $manageUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName  = (string) ($planName ?? 'your membership');
$amount    = '$' . number_format((float) ($amount ?? 0), 2);
$attempted = (string) ($attemptedAt ?? 'recently');
$manageUrl = (string) ($manageUrl ?? absolute_url('/membership/my'));

$emailTitle  = 'Your payment didn\'t go through';
$headerHref  = absolute_url('/');
$emailFooter = 'transactional';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">Your payment didn't go through</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">We couldn't collect the <?= e($amount) ?> charge for
                        your <strong><?= e($planName) ?></strong> membership on <?= e($attempted) ?>. Don't worry — your access is still active for now.</p>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">Please update your payment details so your membership can continue without interruption. If you recently cancelled or your card was replaced, you can ignore this message.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($manageUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Manage my payment details</a>
                    </td>
                </tr>
