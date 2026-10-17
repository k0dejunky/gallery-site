<?php
/**
 * "Your renewal is coming up" — retention. Transactional (no unsubscribe
 * link; the member can manage opt-outs in Settings). Variables: $planName,
 * $renewsOn, $amount, $manageUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName  = (string) ($planName ?? 'your membership');
$renewsOn  = (string) ($renewsOn ?? 'soon');
$amount    = '$' . number_format((float) ($amount ?? 0), 2);
$manageUrl = (string) ($manageUrl ?? absolute_url('/membership/my'));

$emailTitle  = 'Your renewal is coming up';
$headerHref  = absolute_url('/');
$emailFooter = 'transactional';
$emailFooterNote = 'A friendly reminder about your ' . e((string) config('app.site_name')) . ' membership.';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">Your renewal is coming up</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">Your <strong><?= e($planName) ?></strong> membership renews on
                        <strong><?= e($renewsOn) ?></strong> for <?= e($amount) ?>. No action is needed — we'll simply charge the card you have on file.</p>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">If your card has changed or you'd like to cancel instead, head to your membership page before the renewal date.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($manageUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Manage membership</a>
                    </td>
                </tr>
