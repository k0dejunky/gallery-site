<?php
// The paywall.
//
// It used to be a pink box with a centred line of text, and - much worse -
// a guest never saw it at all: the gallery page filtered the photos by the
// viewer's level BEFORE rendering, so a gated gallery came back empty and
// the page said "This gallery doesn't have any media yet" under a gallery
// with 291 photos in it. The gate is now decided by the ACCESS, not by the
// (filtered) photo list, and it says what is behind it.
//
// Expects:
//   $gateTitle  (string)  what is behind the wall (used in the note)
//   $gateLevel  (int)     required membership level (0 = members only)
//   $gateMedia  (string)  'gallery' | 'image' | 'video' - drives the wording
//   $gateCount  (int)     how many items are behind it (0 = unknown)
$gateLevel  = max(0, (int) ($gateLevel ?? 0));
$gateMedia  = in_array($gateMedia ?? '', ['gallery', 'image', 'video'], true) ? $gateMedia : 'gallery';
$gateLogged = \App\Core\Auth::check();
$gateLabel  = \App\Models\Subscription::levelLabel($gateLevel);
$gateNoun   = $gateMedia === 'video' ? 'videos' : 'photos';
$gateCount  = max(0, (int) ($gateCount ?? 0));
$gateItems  = $gateCount > 0 ? number_format($gateCount) . ' ' . $gateNoun : $gateNoun;
$gateTier   = $gateLevel > 0 ? $gateLabel . ' content' : 'Members content';

// What the visitor is being offered, per kind of media.
if ($gateMedia === 'image') {
    $gateOffer = 'the full-size image';
} elseif ($gateMedia === 'video') {
    $gateOffer = 'the full video';
} elseif ($gateCount > 0) {
    $gateOffer = 'all ' . $gateItems . ' in full size';
} else {
    $gateOffer = 'the whole set in full size';
}

// The headline says what is locked, the text says what a membership gets.
if ($gateLevel > 0) {
    $gateTitle = 'This is ' . $gateLabel . ' content';
    $gateText  = $gateLogged
        ? 'Your membership does not include ' . $gateLabel . ' yet. Upgrade to open '
            . $gateOffer . ', and every other ' . $gateLabel . ' set on the site.'
        : 'Unlock ' . $gateOffer . ' with a ' . $gateLabel
            . " membership \u{2014} and every other " . $gateLabel . ' set on the site.';
} else {
    $gateTitle = 'This is for members';
    $gateText  = $gateLogged
        ? 'A membership opens ' . $gateOffer . ', and every set above your current tier.'
        : 'A membership opens ' . $gateOffer . ', and every set above the free tier.';
}
?>
<div class="wall" role="region" aria-label="Members-only content">
    <span class="wall-icon" aria-hidden="true"><?= \App\Core\Icon::svg('lock', 'wall-ico') ?></span>
    <div class="wall-body">
        <p class="wall-eyebrow"><?= e(strtoupper($gateTier)) ?></p>
        <h2 class="wall-title"><?= e($gateTitle) ?></h2>
        <p class="wall-text"><?= e($gateText) ?></p>
        <div class="wall-actions">
            <?php if ($gateLogged): ?>
                <a class="btn" href="<?= e(url('/membership')) ?>">
                    <?= $gateLevel > 0 ? 'Upgrade to ' . e($gateLabel) : 'See membership' ?>
                </a>
            <?php else: ?>
                <a class="btn" href="<?= e(url('/membership')) ?>">
                    <?= $gateLevel > 0 ? 'See ' . e($gateLabel) . ' membership' : 'See membership' ?>
                </a>
                <a class="btn btn-outline" href="<?= e(url('/signup')) ?>">Create a free account</a>
            <?php endif; ?>
        </div>
        <p class="wall-note">
            <?php if ($gateLogged): ?>
                Your current plan: <?= e(\App\Models\Subscription::levelLabel(\App\Core\Auth::effectiveLevel())) ?>.
            <?php else: ?>
                Already a member? <a href="<?= e(url('/login')) ?>">Log in</a>
            <?php endif; ?>
            <span class="wall-18">Adults only (18+)</span>
        </p>
    </div>
</div>
