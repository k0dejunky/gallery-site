<?php
$title = 'Your Dashboard';
$activeSub = $activeSub ?? null;
$pendingSub = $pendingSub ?? null;
$latestSub = $latestSub ?? null;
$formatDate = static function (?string $date): string {
    $timestamp = $date !== null ? strtotime($date) : false;
    return $timestamp !== false ? e(tzdate('F j, Y', $timestamp)) : '';
};
$billingLabels = [
    'monthly' => 'Monthly',
    'yearly'  => 'Yearly',
    'lifetime' => 'Lifetime',
];
?>

<div class="dashboard">
    <div class="dashboard-intro">
         <div>
            <p class="muted">Member area</p>
            <h1>Welcome back<?= !empty($user['billing_first_name']) ? ', ' . e($user['billing_first_name']) : '' ?>.</h1>
            <p class="muted">Pick up where you left off or explore something new.</p>
        </div>
    </div>

    <?php if (!empty($emailUnverified)): ?>
        <section class="card" style="border-left:4px solid var(--purple-500);margin-bottom:var(--spacing-lg);">
            <h2 style="text-align:left;margin-top:0;">Please verify your email</h2>
            <p>We sent a verification link to <strong><?= e($user['email']) ?></strong>. Verify it to keep your account details current.</p>
            <form method="post" action="<?= url('/verify-email/resend') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm">Resend verification email</button>
            </form>
        </section>
    <?php endif; ?>

     <p class="dashboard-actions"><a class="btn btn-outline" href="<?= url('/membership/my') ?>">Membership details</a> <a class="btn btn-outline" href="<?= url('/support') ?>">Support<?php if (!empty($supportUnreadCount)): ?> <span class="nav-unread"><?= (int) $supportUnreadCount ?></span><?php endif; ?></a></p>
     <?php if ($activeSub !== null): ?>
        <?php
        $cycle = $billingLabels[$activeSub['billing_cycle'] ?? ''] ?? ucfirst((string) ($activeSub['billing_cycle'] ?? 'Membership'));
        $expiry = $formatDate($activeSub['expires_at'] ?? null);
        ?>
        <section class="membership-banner" aria-labelledby="membership-status">
            <div>
                <h2 id="membership-status"><?= e($activeSub['plan_name']) ?> membership</h2>
                 <p>Your membership is active and your gallery access is ready. Access level: <strong><?= (int) ($activeSub['plan_level'] ?? $activeSub['access_level'] ?? 0) ?></strong>.</p>
            </div>
            <div class="membership-state">
                <strong>Active</strong>
                <?php if ($expiry !== ''): ?>
                    <small>Access through <?= e($expiry) ?> &middot; <?= e($cycle) ?> billing</small>
                <?php else: ?>
                    <small>No expiry date &middot; <?= e($cycle) ?> membership</small>
                <?php endif; ?>
            </div>
        </section>
    <?php elseif ($pendingSub !== null): ?>
        <section class="card" style="margin-bottom:var(--spacing-lg);border-left:4px solid var(--purple-500);">
            <h2 style="text-align:left;margin-top:0;">Membership request pending</h2>
            <p>Your request for <strong><?= e($pendingSub['plan_name']) ?></strong> is awaiting approval. We will update your access once it is processed.</p>
            <a class="btn btn-sm" href="<?= url('/membership/my') ?>">View membership details</a>
        </section>
    <?php else: ?>
        <section class="card" style="margin-bottom:var(--spacing-lg);">
            <h2 style="text-align:left;margin-top:0;">Choose your membership</h2>
            <p class="muted">Unlock the full gallery collection with a membership plan.</p>
            <a class="btn" href="<?= url('/membership') ?>">View membership plans</a>
        </section>
    <?php endif; ?>

    <section class="dashboard-section" aria-labelledby="recent-galleries">
        <h2 class="section-title" id="recent-galleries">Recently viewed galleries</h2>
        <?php if (empty($recentlyViewed)): ?>
                <div class="card dashboard-empty empty-state">
                <p class="muted">Your recently viewed galleries will appear here.</p>
                <a class="btn btn-sm" href="<?= url('/galleries') ?>">Find a gallery</a>
            </div>
        <?php else: ?>
            <div class="dashboard-grid">
                <?php foreach ($recentlyViewed as $gallery): ?>
                    <?php $cover = $cardCovers[(int) $gallery['id']] ?? null; ?>
                    <article class="card dashboard-card">
                        <a href="<?= url('/galleries/' . (int) $gallery['id']) ?>">
                            <?php if ($cover !== null): ?>
                                <img src="<?= e(file_url($cover['filename'], 'thumb')) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="dashboard-media-thumb" aria-hidden="true"></div>
                            <?php endif; ?>
                            <h3><?= e($gallery['title']) ?></h3>
                        </a>
                        <p class="muted"><?= number_format((int) ($gallery['photo_count'] ?? 0)) ?> photos<?= (int) ($gallery['video_count'] ?? 0) > 0 ? ' &middot; ' . number_format((int) $gallery['video_count']) . ' videos' : '' ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-section" aria-labelledby="recent-media">
        <h2 class="section-title" id="recent-media">Recent uploads</h2>
        <?php if (empty($recentImages) && empty($recentVideos)): ?>
            <div class="card dashboard-empty empty-state">
                <p class="muted">Recent uploads will appear here.</p>
                <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse galleries</a>
            </div>
        <?php else: ?>
            <div class="dashboard-media">
                <?php foreach ($recentImages as $photo): ?>
                    <article class="card">
                        <a href="<?= url('/images/' . (int) $photo['id']) ?>">
                            <img class="dashboard-media-thumb" src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="" loading="lazy">
                            <span class="dashboard-media-label">Picture</span>
                        </a>
                    </article>
                <?php endforeach; ?>
                <?php foreach ($recentVideos as $photo): ?>
                    <article class="card">
                        <a href="<?= url('/videos/' . (int) $photo['id']) ?>">
                            <div style="position:relative"><img class="dashboard-media-thumb" src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="" loading="lazy"><span class="play-badge">&#9654;</span></div>
                            <span class="dashboard-media-label">Video</span>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
