<?php
/**
 * "Live now" notification. Rendered once at enqueue time; the
 * {{unsubscribe-url}} placeholder is swapped for the recipient's signed
 * opt-out link by the worker. Variable: $liveUrl (public /live page URL).
 */
$liveUrl = absolute_url('/live');
$siteName = (string) config('app.site_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($siteName) ?> — live now</title>
</head>
<body style="margin:0;padding:0;background:#f7dfea;font-family:Arial,Helvetica,sans-serif;color:#3b2550;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7dfea;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e9c3d6;">
                <tr>
                    <td style="background:#6d2ea8;padding:20px 28px;text-align:center;">
                        <a href="<?= e($liveUrl) ?>" style="font-size:20px;font-weight:bold;color:#ffffff;text-decoration:none;"><?= e($siteName) ?></a>
                    </td>
                </tr>
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
                <tr>
                    <td style="padding:20px 28px;background:#fbf1f7;border-top:1px solid #ecd9e5;">
                        <p style="margin:0 0 10px;font-size:12px;line-height:1.6;color:#8a7a99;">
                            You are receiving this because you are a member of <?= e($siteName) ?>.
                            If you no longer want these updates, you can
                            <a href="{{unsubscribe-url}}" style="color:#6d2ea8;">unsubscribe</a>.
                        </p>
                        <p style="margin:0;font-size:11px;color:#b3a3c0;">&copy; <?= date('Y') ?> <?= e($siteName) ?></p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>