<?php
/**
 * "Live now" notification. Rendered once at enqueue time; the
 * {{unsubscribe-url}} placeholder is swapped for the recipient's signed
 * opt-out link by the worker.
 *
 * The CTA goes through Traffic::buildUrl() so a click is attributed to the
 * email digest the same way every other outbound link is, instead of landing
 * as a direct visit.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$liveUrl = \App\Models\Traffic::buildUrl('/live', 'email-digest');

$emailTitle  = 'live now';
$headerHref  = $liveUrl;
$emailFooter = 'marketing';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">She's live right now</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">The live show has started and the chat is open. Come say hi before she gets busy.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($liveUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Watch live</a>
                    </td>
                </tr>
