<?php
/**
 * "Your payment was received" — a receipt. Rendered at enqueue time.
 * Transactional: deliberately no unsubscribe link (account-critical).
 * Variables: $planName, $amount, $receiptNumber, $paidAt, $expiresAt, $manageUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName  = (string) ($planName ?? 'your membership');
$amount    = '$' . number_format((float) ($amount ?? 0), 2);
$receipt   = (string) ($receiptNumber ?? '');
$paid      = (string) ($paidAt ?? 'recently');
$expires   = (string) ($expiresAt ?? '');
$manageUrl = (string) ($manageUrl ?? absolute_url('/membership/my'));

$emailTitle  = 'Your payment was received';
$headerHref  = absolute_url('/');
$emailFooter = 'transactional';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">Your payment was received</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">We received your <?= e($amount) ?> payment for your
                        <strong><?= e($planName) ?></strong> membership on <?= e($paid) ?>. Thank you — your access continues.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 28px 8px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#6b5b82;width:40%;">Plan</td>
                                <td style="padding:6px 0;font-size:13px;color:#3b2550;"><?= e($planName) ?></td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#6b5b82;">Amount</td>
                                <td style="padding:6px 0;font-size:13px;color:#3b2550;"><?= e($amount) ?></td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#6b5b82;">Receipt</td>
                                <td style="padding:6px 0;font-size:13px;color:#3b2550;word-break:break-all;"><?= e($receipt) ?></td>
                            </tr>
                            <?php if ($expires !== ''): ?>
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#6b5b82;">Next payment due</td>
                                <td style="padding:6px 0;font-size:13px;color:#3b2550;"><?= e($expires) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($manageUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Manage my membership</a>
                    </td>
                </tr>
