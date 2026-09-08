<?php
/**
 * Newsletter digest email. Rendered once per audience at enqueue time; the
 * {{unsubscribe-url}} placeholder is replaced with each recipient's signed
 * opt-out link when the worker sends the row.
 *
 * Variables: $subscriber (bool), $samples (rows: id, filename, created_at),
 * $count (int). Subscribers see sharp thumbnails + a CTA into the gallery;
 * non-subscribers see the public blurred previews + a CTA to become a member.
 */
$subscriber = !empty($subscriber);
$samples    = isset($samples) ? (array) $samples : [];
$count      = max(0, (int) ($count ?? count($samples)));
$siteName   = (string) config('app.site_name');
$ctaHref    = $subscriber ? absolute_url('/galleries') : absolute_url('/membership');
$ctaLabel   = $subscriber ? 'Open the gallery' : 'Become a member';
$imageSize  = $subscriber ? 'thumb' : 'blur';
$copy       = $subscriber
    ? 'A small sample of the latest uploads is waiting for you in the gallery.'
    : 'A blurred peek at what is new on the site. Become a member to see the real images.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(config('app.site_name')) ?> — new uploads</title>
</head>
<body style="margin:0;padding:0;background:#f7dfea;font-family:Arial,Helvetica,sans-serif;color:#3b2550;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7dfea;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e9c3d6;">

                <!-- Header -->
                <tr>
                    <td style="background:#6d2ea8;padding:20px 28px;text-align:center;">
                        <a href="<?= e($ctaHref) ?>" style="font-size:20px;font-weight:bold;color:#ffffff;text-decoration:none;"><?= e($siteName) ?></a>
                    </td>
                </tr>

                <!-- Intro -->
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;"><?= $count > 0 ? 'New uploads — ' . $count . ' fresh photo' . ($count === 1 ? '' : 's') : 'New in the gallery' ?></h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;"><?= e($copy) ?></p>
                    </td>
                </tr>

                <!-- Sample grid -->
                <tr>
                    <td style="padding:8px 28px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="8">
                            <tr>
                                <?php foreach ($samples as $i => $photo): ?>
                                    <?php if ($i % 3 === 0 && $i > 0): ?>
                            </tr><tr>
                                    <?php endif; ?>
                                    <td width="33%" valign="top">
                                        <a href="<?= e($ctaHref) ?>">
                                            <img src="<?= e(absolute_url(file_url((string) $photo['filename'], $imageSize))) ?>"
                                                 alt="Latest upload" width="100%" style="display:block;width:100%;height:96px;object-fit:cover;border-radius:8px;background:#efe5f5;">
                                        </a>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Call to action -->
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($ctaHref) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;"><?= e($ctaLabel) ?></a>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="padding:20px 28px;background:#fbf1f7;border-top:1px solid #ecd9e5;">
                        <p style="margin:0 0 10px;font-size:12px;line-height:1.6;color:#8a7a99;">
                            You are receiving this because you <?= $subscriber ? 'are a member of' : 'signed up on' ?> <?= e($siteName) ?>.
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