<?php
/**
 * "It's been a while" — retention email for an activated member with no
 * usable subscription who has not been seen for a month. Marketing, so it
 * carries the unsubscribe footer. Variables: $galleryUrl, $membershipUrl.
 *
 * Body rows only — the shared chrome lives in views/emails/layout.php.
 */
$galleryUrl    = (string) ($galleryUrl ?? absolute_url('/galleries'));
$membershipUrl = (string) ($membershipUrl ?? \App\Models\Traffic::buildUrl('/membership', 'email-digest'));

$emailTitle  = "It's been a while";
$headerHref  = absolute_url('/');
$emailFooter = 'marketing';
$emailFooterNote = 'You are receiving this because you have an account on ' . e((string) config('app.site_name')) . '. '
    . 'If you\'d rather not hear from us, you can <a href="{{unsubscribe-url}}" style="color:#6d2ea8;">unsubscribe</a>.';
?>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#3b2550;">It's been a while</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">We noticed you haven't stopped by in a while — and the site hasn't been standing still.
                        New galleries and videos have been going up the whole time.</p>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b5b82;">Come see what you missed.
                        <a href="<?= e($membershipUrl) ?>" style="color:#6d2ea8;">Membership details are here</a> whenever you're ready.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 28px;">
                        <a href="<?= e($galleryUrl) ?>" style="display:inline-block;background:#6d2ea8;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 28px;border-radius:28px;">See what's new</a>
                    </td>
                </tr>
