<?php
/**
 * Shared chrome for every notification email.
 *
 * Before this file, all eight HTML templates each carried their own copy of the
 * DOCTYPE, the 100%-width presentation table, the white 600px card, the purple
 * brand header and the footer wrapper - the brand colour #6d2ea8 alone was
 * hard-coded three times per template. A change to any of it meant eight edits.
 *
 * A template now renders only its own body rows and sets the variables that
 * render_email() forwards here:
 *
 *   $emailTitle       string  <title> suffix, e.g. "new gallery"
 *   $headerHref       string  where the brand wordmark links
 *   $emailFooter      string  'marketing' (default) or 'transactional'; picks
 *                             the default footer note
 *   $emailFooterNote  string  optional HTML that replaces the default note
 *                             (the win-back uses its own wording)
 *
 * Everything is inline-styled on purpose: email clients ignore <style> blocks
 * far more often than they ignore attributes, so this markup stays inline even
 * though the site's own pages no longer do.
 *
 * In scope: $content (the template's body rows) plus the four variables above.
 */
$siteName    = (string) config('app.site_name');
$emailTitle  = (string) ($emailTitle ?? 'notification');
$headerHref  = (string) ($headerHref ?? absolute_url('/'));
$isMarketing = ($emailFooter ?? 'marketing') !== 'transactional';

$defaultNote = $isMarketing
    ? 'You are receiving this because you are a member of ' . e($siteName) . '. '
        . 'If you no longer want these updates, you can '
        . '<a href="{{unsubscribe-url}}" style="color:#6d2ea8;">unsubscribe</a>.'
    : 'This is an important notice about your ' . e($siteName) . ' account.';

$footerNote = (string) ($emailFooterNote ?? $defaultNote);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($siteName) ?> — <?= e($emailTitle) ?></title>
</head>
<body style="margin:0;padding:0;background:#f7dfea;font-family:Arial,Helvetica,sans-serif;color:#3b2550;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7dfea;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e9c3d6;">
                <tr>
                    <td style="background:#6d2ea8;padding:20px 28px;text-align:center;">
                        <a href="<?= e($headerHref) ?>" style="font-size:20px;font-weight:bold;color:#ffffff;text-decoration:none;"><?= e($siteName) ?></a>
                    </td>
                </tr>
                <?= $content ?>
                <tr>
                    <td style="padding:20px 28px;background:#fbf1f7;border-top:1px solid #ecd9e5;">
                        <p style="margin:0 0 10px;font-size:12px;line-height:1.6;color:#8a7a99;"><?= $footerNote ?></p>
                        <p style="margin:0;font-size:11px;color:#b3a3c0;">&copy; <?= date('Y') ?> <?= e($siteName) ?></p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
