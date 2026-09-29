<?php
// In-page video player rendered inside the site template, with Previous/Next
// navigation (gallery neighbours, or the collection playlist when launched
// with ?playlist={id}) and a button back to it.
$title = $photo['caption'] !== '' ? $photo['caption'] : ($gallery !== null ? $gallery['title'] : 'Video');
$src   = file_url($photo['filename'], 'web');
$reportUrl = url('/support') . '?' . http_build_query(['return_to' => $_SERVER['REQUEST_URI'] ?? url('/galleries')]);
$plUrl  = function (int $videoId) use ($playlistId): string {
    return url('/videos/' . $videoId) . ($playlistId > 0 ? '?playlist=' . $playlistId : '');
};
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => 'Gallery', 'url' => url('/galleries/' . (int) ($gallery['id'] ?? 0))],
    ['label' => $photo['caption'] ?: 'Video'],
];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<?php require __DIR__ . '/../partials/media_nav.php'; ?>

<?php if (!empty($collections)): ?>
    <form method="post" action="<?= url('/collections/' . (int) $collections[0]['id'] . '/photos') ?>" class="settings-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.25rem 0 .75rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
        <label class="muted" style="font-size:.9rem;">Save video to collection</label>
        <select name="collection_id" onchange="this.form.action='<?= url('/collections') ?>/'+this.value+'/photos'">
            <?php foreach ($collections as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline">Add to collection</button>
        <a href="<?= url('/collections') ?>" class="muted" style="font-size:.85rem;">Manage collections</a>
    </form>
<?php else: ?>
    <p class="muted" style="font-size:.9rem;margin:.25rem 0 .75rem;"><a href="<?= url('/collections') ?>">Create a collection</a> to save this video.</p>
<?php endif; ?>

<div class="player-layout"<?= !empty($playlist) ? ' data-has-playlist="1"' : '' ?>>
    <div class="player-main">
        <figure id="video-player-wrap" style="margin: 1rem 0; text-align: center;">
            <p class="media-progress" role="status">Item <?= (int) ($currentIndex + 1) ?> of <?= (int) ($mediaCount ?? 1) ?></p>
            <div class="gallery-player" data-player>
                <video id="gallery-video-<?= (int) $photo['id'] ?>" data-video-id="<?= (int) $photo['id'] ?>" src="<?= e($src) ?>" controls preload="metadata" playsinline aria-label="<?= e($title) ?>"></video>
            </div>
            <div class="video-resume" hidden role="status">Resume from <span class="video-resume-time">0:00</span> <button type="button" class="btn btn-sm">Resume</button></div>
            <figcaption class="muted" style="margin-top: 0.5rem">
                <?php if ($photo['caption'] !== ''): ?>
                    <span><?= e($photo['caption']) ?></span><br>
                <?php endif; ?>
                <span><?= number_format((int) ($photo['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($photo['unique_views'] ?? 0)) ?> unique</span>
            </figcaption>
        </figure>
        <p style="text-align:center"><a href="<?= e($reportUrl) ?>">Report broken media</a></p>
    </div>

    <div class="player-side">
        <aside class="player-playlist" id="player-playlist">
            <h3><?= !empty($playlistName) ? e($playlistName) : 'Up next' ?><?= !empty($playlist) ? ' <span class="muted" style="font-weight:400;">(' . count($playlist) . ')</span>' : '' ?></h3>
            <ul>
                <?php if (!empty($playlist)): ?>
                    <?php foreach ($playlist as $item): ?>
                        <li class="pl-item<?= (int) $item['id'] === (int) $photo['id'] ? ' active' : '' ?>" data-video-id="<?= (int) $item['id'] ?>">
                            <a data-swap href="<?= e($plUrl((int) $item['id'])) ?>">
                                <img src="<?= e((string) $item['thumb']) ?>" alt="" loading="lazy">
                                <span class="pl-title"><?= e($item['caption'] !== '' ? (string) $item['caption'] : 'Video ' . ((int) array_search((int) $item['id'], array_map('intval', array_column($playlist, 'id')), true) + 1)) ?></span>
                                <span class="pl-duration"><?= e(!empty($item['duration_seconds']) ? gmdate('i:s', (int) $item['duration_seconds']) : '') ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <?php if (empty($playlist)): ?>
                <p class="pl-empty muted" style="padding:.5rem;margin:.25rem 0 0;font-size:.85rem;">No videos queued. Browse galleries to add to the playlist.</p>
            <?php endif; ?>
        </aside>

        <div style="text-align:center;">
            <button type="button" class="btn btn-sm btn-outline" id="browse-galleries-btn">&#128269; Browse galleries</button>
        </div>
        <div id="player-browse" class="player-browse" hidden data-playlist="<?= (int) $playlistId ?>">
            <div class="pb-toolbar">
                <input type="search" id="pb-search" placeholder="Search galleries&hellip;" aria-label="Search galleries">
            </div>
            <p class="muted" style="font-size:.85rem;">Click a video to play or add it to the playlist. The picture-in-picture window keeps playing while you browse.</p>
            <div class="pb-body"></div>
        </div>
    </div>
</div>