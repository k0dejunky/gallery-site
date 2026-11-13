<?php
/**
 * "We still can't charge your card" — billing / dunning. Transactional (no
 * unsubscribe link). Variables: $planName, $amount, $graceEndsOn, $manageUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName    = (string) ($planName ?? 'your membership');
$amount      = '$' . number_format((float) ($amount ?? 0), 2);
$graceEndsOn = (string) ($graceEndsOn ?? '');
$manageUrl   = (string) ($manageUrl ?? absolute_url('/membership/my'));

$emailTitle  = "We still can't charge your card";
$headerHref  = $manageUrl;
$emailFooter = 'transactional';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">We still can&rsquo;t charge your card</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">The <?= e($amount) ?> payment for your
                        <strong><?= e($planName) ?></strong> membership has still not gone through.</p>
                        <?php if ($graceEndsOn !== ''): ?>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">Please update your payment details before
                        <strong><?= e($graceEndsOn) ?></strong> to keep your paid access. If it is still failing on that date, the paid
                        membership ends and your account stays open on the <strong>free tier</strong>: you can still sign in and browse the
                        free galleries, and nothing is deleted.</p>
                        <?php else: ?>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">Please update your payment details to keep your
                        paid access. If it keeps failing, the paid membership ends and your account stays open on the free tier.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($manageUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Update my card</a>
                    </td>
                </tr>
