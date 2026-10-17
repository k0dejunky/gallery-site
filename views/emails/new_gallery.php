<?php
/**
 * "New gallery published" notification. Rendered once at enqueue time; the
 * {{unsubscribe-url}} placeholder is swapped for the recipient's signed
 * opt-out link by the worker. Variables: $gallery (row: title, description).
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$gallery = isset($gallery) ? (array) $gallery : [];
$title   = (string) ($gallery['title'] ?? 'a new gallery');
$desc    = trim((string) ($gallery['description'] ?? ''));
$href    = \App\Models\Traffic::buildUrl('/galleries/' . (int) ($gallery['id'] ?? 0), 'email-alert');

$emailTitle  = 'new gallery';
$headerHref  = $href;
$emailFooter = 'marketing';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">A new gallery just went live</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;"><strong><?= e($title) ?></strong> is now waiting for you.</p>
                        <?php if ($desc !== ''): ?>
                            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;"><?= e(mb_substr($desc, 0, 400)) ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($href) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">Open the gallery</a>
                    </td>
                </tr>
