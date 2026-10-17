<?php
/**
 * "You have a new chat reply" notification. Rendered at enqueue time; the
 * {{unsubscribe-url}} placeholder is swapped for the recipient's signed
 * opt-out link by the worker. Variable: $conversation_id.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$chatUrl = absolute_url('/chat');

$emailTitle  = 'new reply';
$headerHref  = $chatUrl;
$emailFooter = 'marketing';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">You have a new reply</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">The operator just replied to you in chat. Come say hi and keep the conversation going.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($chatUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Open chat</a>
                    </td>
                </tr>
