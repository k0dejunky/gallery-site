<?php
$title = 'Login';
// Inline SVG fallback so a missing thumbnail never shows a broken image on
// the guest landing page; also doubles as a "no picture" placeholder.
$pictureBlank = 'data:image/svg+xml;utf8,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#ffd9e8"/><rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="#f472b6" stroke-width="8"/><circle cx="185" cy="145" r="14" fill="#ec4899"/><path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="#9333ea" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);

// Merge the recent images and videos into one ordered list, each card
// linking to its own in-page viewer (image or video page).
$recentItems = [];
foreach ($recentImages as $photo) {
    if ((int) $photo['gallery_id'] <= 0) {
        continue;
    }
    $recentItems[] = [
        'type'  => 'image',
        'url'   => url('/images/' . (int) $photo['id']),
        'thumb' => file_url($photo['filename'], 'blur'),
        'filename' => (string) $photo['filename'],
    ];
}
foreach ($recentVideos as $photo) {
    if ((int) $photo['gallery_id'] <= 0) {
        continue;
    }
    $recentItems[] = [
        'type'  => 'video',
        'url'   => url('/videos/' . (int) $photo['id']),
        'thumb' => file_url($photo['filename'], 'blur'),
        'filename' => (string) $photo['filename'],
    ];
}
?>

<div class="auth-panel">
    <h1>Login</h1>

    <form method="post" action="<?= url('/login') ?>">
        <?= csrf_field() ?>
        <p>
            <label for="email">Email</label><br>
            <input type="email" name="email" id="email" required autofocus>
        </p>
        <p>
            <label for="password">Password</label><br>
            <input type="password" name="password" id="password" required>
            <a href="<?= url('/forgot-password') ?>" style="font-size:0.85rem;margin-left:0.5rem;">Forgot password?</a>
        </p>
        <p>
            <label style="display:inline-flex;align-items:center;gap:.35rem;font-weight:normal;cursor:pointer;">
                <input type="checkbox" name="remember_me" value="1" id="remember_me" style="width:auto;">
                Keep me signed in on this device
            </label>
        </p>
        <p>
            <button type="submit" class="btn">Login</button>
        </p>
    </form>

    <p class="auth-links">No account yet? <a href="<?= url('/signup') ?>">Sign up</a> &middot; <a href="<?= url('/membership') ?>">Membership</a> &middot; <a href="<?= url('/admin') ?>">Admin login</a></p>
    <p class="muted" style="text-align:center;font-size:0.8rem;margin-bottom:0;">
        <a href="<?= url('/terms') ?>">Terms of Service</a> &middot; <a href="<?= url('/privacy') ?>">Privacy Policy</a> &middot; <a href="<?= url('/about') ?>">About Us</a>
    </p>
</div>

<?php // Recent uploads shown as gallery cards; clicking one opens the
     // image or video page it belongs to. ?>
<?php if (!empty($recentImages)): ?>
<section>
    <?php // One row only: cards flow horizontally and overflow is clipped so only the pictures that fit the row are visible. ?>
    <h2 class="section-title">Recent Pictures</h2>
    <div class="recent-strip">
        <?php foreach ($recentItems as $item): ?>
            <?php if ($item['type'] !== 'image') { continue; } ?>
            <div class="card recent-card">
                <a class="card-link" href="<?= e($item['url']) ?>">
                    <div class="card-cover">
                        <picture>
                            <source type="image/webp" srcset="<?= e(file_url($item['filename'], 'blur', 'webp')) ?>">
                            <img src="<?= e($item['thumb']) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= e($pictureBlank) ?>'">
                        </picture>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($recentVideos)): ?>
<section>
    <?php // Videos also stay on one row, clipped to whatever fits. ?>
    <h2 class="section-title">Recent Videos</h2>
    <div class="recent-strip">
        <?php foreach ($recentItems as $item): ?>
            <?php if ($item['type'] !== 'video') { continue; } ?>
            <div class="card recent-card">
                <a class="card-link" href="<?= e($item['url']) ?>">
                    <div class="card-cover">
                        <img src="<?= e($item['thumb']) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= e($pictureBlank) ?>'">
                        <span class="play-badge">&#9654;</span>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<script>
    // Keep each recent strip to a single row: measure how many cards fit,
    // hide any card that would be clipped (never show a cut-off thumbnail).
    // Strips with fewer than 4 thumbnails are centered with equal spacing
    // between the cards and the page edges (space-evenly); larger strips
    // are justified across the full row.
    function fitRecentStrip() {
        document.querySelectorAll('.recent-strip').forEach(function (strip) {
            const cards = Array.from(strip.querySelectorAll('.recent-card'));
            if (!cards.length) return;

            const cardWidth = cards[0].offsetWidth || 220;
            const gap = parseFloat(getComputedStyle(strip).gap) || 0;
            const available = strip.clientWidth;
            const count = Math.max(0, Math.floor((available + gap) / (cardWidth + gap)));

            strip.style.justifyContent = cards.length < 4 ? 'space-evenly' : 'space-between';

            cards.forEach(function (card, i) {
                card.style.display = i < count ? '' : 'none';
            });
        });
    }
    fitRecentStrip();
    window.addEventListener('resize', fitRecentStrip);
    window.addEventListener('load', fitRecentStrip);
</script>
