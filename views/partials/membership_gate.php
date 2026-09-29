<?php
// Blurred-preview gate banner. Rendered on public preview pages (galleries,
// images, videos) so search engines index the metadata + blurred sample while
// the full content stays behind login/membership. Expects:
//   $gateTitle  (string)  short headline, e.g. gallery title or "Full-size image"
//   $gateLevel  (int)     required membership level (0 = login only)
//   $gateMedia  (string)  'gallery' | 'image' | 'video' — drives the CTA text
$gateLevel  = max(0, (int) ($gateLevel ?? 0));
$gateMedia  = in_array($gateMedia ?? '', ['gallery', 'image', 'video'], true) ? $gateMedia : 'gallery';
$gateLogged = \App\Core\Auth::check();
$gateLabel  = \App\Models\Subscription::levelLabel($gateLevel);
$gateNoun   = $gateMedia === 'video' ? 'videos' : ($gateMedia === 'image' ? 'full-size photos' : 'galleries');
?>
<div class="membership-gate" style="text-align:center;padding:2rem 1.5rem;margin:1.5rem 0;background:var(--pink-100);border:1px solid var(--pink-300);border-radius:var(--border-radius-lg);box-shadow:var(--shadow);">
    <h2 style="margin:0 0 .5rem;color:var(--purple-800);"><?= $gateLevel > 0 ? 'This is ' . e($gateLabel) . ' content' : 'Members content' ?></h2>
    <p class="muted" style="max-width:520px;margin:0 auto .75rem;">
        <?= $gateLogged
            ? 'Your current membership does not include this content. Upgrade to a ' . e($gateLabel) . ' plan to view these ' . e($gateNoun) . ' in full.'
            : 'Create a free account' . ($gateLevel > 0 ? ' and a ' . e($gateLabel) . ' membership' : '') . ' to unlock these ' . e($gateNoun) . ' in full.' ?>
    </p>
    <p style="margin:0;">
        <?php if (!$gateLogged): ?>
            <a class="btn" href="<?= url('/signup') ?>">Sign up</a>
            <a class="btn btn-outline" href="<?= url('/login') ?>">Log in</a>
        <?php else: ?>
            <a class="btn" href="<?= url('/membership') ?>">View membership</a>
        <?php endif; ?>
    </p>
</div>