<?php
// Guest landing teaser: a stats line with the site's total image/video
// counts, then two horizontal strips of the most recent uploads. Shared by
// the login and signup pages. Expects $recentImages, $recentVideos and an
// optional $mediaCounts array (['images' => n, 'videos' => n]).
$pictureBlank = 'data:image/svg+xml;utf8,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#ffd9e8"/><rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="#f472b6" stroke-width="8"/><circle cx="185" cy="145" r="14" fill="#ec4899"/><path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="#9333ea" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);

$recentItems = [];
foreach ($recentImages as $photo) {
    if ((int) $photo['gallery_id'] <= 0) {
        continue;
    }
    $recentItems[] = [
        'type'     => 'image',
        'url'      => url('/images/' . (int) $photo['id']),
        'thumb'    => file_url($photo['filename'], 'blur'),
        'filename' => (string) $photo['filename'],
    ];
}
foreach ($recentVideos as $photo) {
    if ((int) $photo['gallery_id'] <= 0) {
        continue;
    }
    $recentItems[] = [
        'type'     => 'video',
        'url'      => url('/videos/' . (int) $photo['id']),
        'thumb'    => file_url($photo['filename'], 'blur'),
        'filename' => (string) $photo['filename'],
    ];
}
?>
<div class="guest-teaser">
    <?php if (!empty($mediaCounts)): ?>
    <p class="guest-teaser-stats muted">
        <strong><?= number_format((int) $mediaCounts['images']) ?></strong> pictures &middot;
        <strong><?= number_format((int) $mediaCounts['videos']) ?></strong> videos across the site
    </p>
    <?php endif; ?>

    <?php if (!empty($recentImages)): ?>
    <section>
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
                            <span class="card-blur-note">Members-only</span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($recentVideos)): ?>
    <section>
        <h2 class="section-title">Recent Videos</h2>
        <div class="recent-strip">
            <?php foreach ($recentItems as $item): ?>
                <?php if ($item['type'] !== 'video') { continue; } ?>
                <div class="card recent-card">
                    <a class="card-link" href="<?= e($item['url']) ?>">
                        <div class="card-cover">
                            <img src="<?= e($item['thumb']) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= e($pictureBlank) ?>'">
                            <span class="play-badge">&#9654;</span>
                            <span class="card-blur-note">Members-only</span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<style>
    .guest-teaser { margin-top: var(--spacing-lg); }
    .guest-teaser-stats { text-align: center; margin: 0 0 var(--spacing-md); font-size: .95rem; }
    .guest-teaser-stats strong { color: var(--purple-800); }
    .recent-card { position: relative; }
    .card-blur-note { position: absolute; left: 0; right: 0; bottom: 0; padding: .25rem .5rem; font-size: .72rem; text-align: center; background: rgba(20,8,34,.55); color: #fff; border-radius: 0 0 var(--card-radius) var(--card-radius); }
</style>