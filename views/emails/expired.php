<?php
/**
 * "Your membership has ended" — billing / retention. Transactional (no
 * unsubscribe link). Variables: $planName, $rejoinUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$planName  = (string) ($planName ?? 'your membership');
$rejoinUrl = (string) ($rejoinUrl ?? absolute_url('/membership'));

$emailTitle  = 'Your membership has ended';
$headerHref  = $rejoinUrl;
$emailFooter = 'transactional';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">Your membership has ended</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">Your <strong><?= e($planName) ?></strong> membership has now
                        ended, so some galleries may no longer be available to you. It was great having you with us — you can rejoin at any time.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($rejoinUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">View membership options</a>
                    </td>
                </tr>
