<?php
// In-page video player rendered inside the site template, with Previous/Next
// navigation (gallery neighbours, or the collection playlist when launched
// with ?playlist={id}) and a button back to it.
$title = $photo['caption'] !== '' ? $photo['caption'] : ($gallery !== null ? $gallery['title'] : 'Video');
$src   = file_url($photo['filename'], 'web');
$reportUrl = url('/support') . '?' . http_build_query(['return_to' => $_SERVER['REQUEST_URI'] ?? url('/galleries')]);
$galleryTitle = $gallery !== null ? (string) $gallery['title'] : '';
$metaDescription = $photo['caption'] !== ''
    ? $photo['caption'] . ($galleryTitle !== '' ? ' — a video from "' . $galleryTitle . '" on ' . config('app.site_name') . '.' : '')
    : 'A video from ' . ($galleryTitle !== '' ? '"' . $galleryTitle . '"' : 'the gallery') . ' on ' . config('app.site_name') . '.';
$canonicalUrl = absolute_url('/videos/' . (int) $photo['id']);
$ogImage = absolute_url(file_url($photo['filename'], 'thumb'));
// A video page is a video, not a website. Every page said "website".
$ogType = 'video.other';
$ogVideo = [
    'url'       => $canonicalUrl,
    'type'      => 'video/mp4',
    'width'     => 1280,
    'height'    => 720,
];
$videoObject = [
    '@context' => 'https://schema.org',
    '@type'    => 'VideoObject',
    'name'     => $title,
    'url'      => $canonicalUrl,
    'description' => $metaDescription,
    'thumbnailUrl' => absolute_url(file_url($photo['filename'], 'thumb')),
    // ISO 8601. It was the raw MySQL datetime, which is not a valid
    // dateTime for a search engine.
    'uploadDate' => date('c', strtotime((string) ($photo['created_at'] ?? 'now')) ?: time()),
    'author'   => ['@type' => 'Organization', 'name' => config('app.site_name'), 'url' => absolute_url('')],
];
$sampleClipUrl = video_sample_url((int) $photo['id'], (string) $photo['filename']);
if ($sampleClipUrl !== '') {
    // Point Google at the short public sample clip so it can verify + index
    // the video; the full-length file stays behind the membership gate.
    $videoObject['contentUrl'] = $sampleClipUrl;
}
if (!empty($photo['duration_seconds'])) {
    $videoObject['duration'] = 'PT' . (int) $photo['duration_seconds'] . 'S';
}
// Paywall markup for gated videos (see the note in gallery/image_full.php).
if (empty($canViewFull)) {
    $videoObject['isAccessibleForFree'] = false;
    $videoObject['hasPart'] = [
        '@type' => 'WebPageElement',
        'isAccessibleForFree' => false,
        'cssSelector' => '.paywalled-content',
    ];
}
$ldJson = $videoObject;
$plUrl  = function (int $videoId) use ($playlistId): string {
    return url('/videos/' . $videoId) . ($playlistId > 0 ? '?playlist=' . $playlistId : '');
};
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => 'Gallery', 'url' => url('/galleries/' . (int) ($gallery['id'] ?? 0))],
    ['label' => $photo['caption'] ?: 'Video'],
];

// Playback ladder: only the rungs that actually exist on disk are offered, so
// the quality menu can never hand the browser a URL that 404s. Ordered
// low -> high by config; the player picks the first rung when the connection
// looks metered/slow.
//
// Each rung also carries the PIXEL SIZE it will have, so the quality menu can
// say "720p - 576x1280 - 2.1 MB" instead of a label that lies. The size is
// derived from the source's display size (rotation applied) and the rung's
// target box, which is exactly what the encoder produces - no probing per
// page view.
$qualitySources = [];
$rungPaths = video_rung_paths((string) $photo['filename']);
$sourceDims = \App\Models\Photo::dimensions((int) $photo['id']);
if ($sourceDims === null) {
    // Not measured yet (a row from before the dimensions worker existed):
    // fall back to probing the source once, and cache it.
    $probed = video_display_dimensions($rungPaths['web'] ?? '');
    if ($probed === null) {
        foreach ($rungPaths as $candidate) {
            $probed = video_display_dimensions($candidate);
            if ($probed !== null) {
                break;
            }
        }
    }
    if ($probed !== null) {
        $sourceDims = ['w' => (int) $probed['w'], 'h' => (int) $probed['h'], 'rotation' => (int) $probed['rotation']];
        \App\Models\Photo::setDimensions((int) $photo['id'], $sourceDims['w'], $sourceDims['h'], $sourceDims['rotation']);
    }
}
foreach (video_ladder() as $rung) {
    $rungPath = $rungPaths[$rung['size']] ?? '';
    if ($rungPath === '' || !is_file($rungPath)) {
        continue;
    }
    $rungW = 0;
    $rungH = 0;
    if ($sourceDims !== null) {
        [$boxW, $boxH] = video_ladder_box($rung, $sourceDims['w'], $sourceDims['h']);
        [$rungW, $rungH] = video_fit_dimensions($sourceDims['w'], $sourceDims['h'], $boxW, $boxH);
    }
    $qualitySources[$rung['size']] = [
        'label' => (string) $rung['label'],
        'url'   => file_url((string) $photo['filename'], $rung['size']),
        'bytes' => (int) @filesize($rungPath),
        'w'     => $rungW,
        'h'     => $rungH,
    ];
}
$posterUrl = video_poster_url((string) $photo['filename']);
$chapters  = is_array($chapters ?? null) ? $chapters : [];

// The player's aspect ratio, from the source's display size. The box is laid
// out at this ratio from the very first paint, so a vertical clip is never
// shown in a wide box with black bars around it.
$playerAspect = '';
if ($sourceDims !== null && $sourceDims['w'] > 0 && $sourceDims['h'] > 0) {
    $playerAspect = round($sourceDims['w'] / $sourceDims['h'], 6);
}
$playlistTotal = 0;
foreach ((array) ($playlist ?? []) as $item) {
    $playlistTotal += (int) ($item['duration_seconds'] ?? 0);
}
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<?php if (!empty($canViewFull)): ?>
<?php require __DIR__ . '/../partials/media_nav.php'; ?>

<?php if (!empty($collections)): ?>
    <form method="post" action="<?= url('/collections/' . (int) $collections[0]['id'] . '/photos') ?>" class="settings-form player-save-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.25rem 0 .75rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
        <label class="muted u-fs-09">Save video to collection</label>
        <select name="collection_id" aria-label="Collection to save this video in" data-col-action="<?= url('/collections') ?>/" data-col-suffix="photos" data-action="collectionActionSelect">
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
<?php endif; ?>

<div class="player-layout"<?= !empty($playlist) ? ' data-has-playlist="1"' : '' ?>>
    <div class="player-main">
        <figure id="video-player-wrap" class="paywalled-content" style="margin: 1rem 0; text-align: center;">
            <?php if (empty($canViewFull)): ?>
                <?php // Blurred, indexable preview for guests and below-level
                    // members: play the short blurred sample clip, or fall
                    // back to the blurred poster when no clip exists yet. ?>
                <?php $sampleUrl = video_sample_url((int) $photo['id'], (string) $photo['filename']); ?>
                <div class="media-stage">
                    <?php if ($sampleUrl !== ''): ?>
                        <video id="preview-video" src="<?= e($sampleUrl) ?>" controls playsinline
                               preload="metadata" poster="<?= e(file_url($photo['filename'], 'blur')) ?>"
                               aria-label="<?= e($title) ?>"></video>
                    <?php else: ?>
                        <img src="<?= e(file_url($photo['filename'], 'blur')) ?>"
                             alt="<?= e($title) ?>" decoding="async">
                    <?php endif; ?>
                </div>
                <figcaption class="muted" style="margin-top:.5rem">
                    <?php if ($photo['caption'] !== ''): ?><span><?= e($photo['caption']) ?></span><br><?php endif; ?>
                    <span><?= number_format((int) ($photo['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($photo['unique_views'] ?? 0)) ?> unique</span>
                </figcaption>
                <?php
                $gateTitle = $title;
                // Say what the gate actually requires; a Gold-only video must
                // not advertise itself as "members content".
                $gateLevel = (int) ($minimumLevel ?? 0);
                $gateMedia = 'video';
                require __DIR__ . '/../partials/membership_gate.php';
                ?>
            <?php else: ?>
            <p class="media-progress" role="status">Item <?= (int) ($currentIndex + 1) ?> of <?= (int) ($mediaCount ?? 1) ?></p>
            <div class="gallery-player<?= is_video($photo['filename']) ? ' is-poster' : '' ?>" data-player
                 <?= $playerAspect !== '' ? 'style="--vp-aspect:' . e((string) $playerAspect) . '"' : '' ?>
                 data-aspect="<?= e((string) $playerAspect) ?>">
                <?php if (is_video($photo['filename'])): ?>
                    <video id="gallery-video-<?= (int) $photo['id'] ?>"
                           data-video-id="<?= (int) $photo['id'] ?>"
                           data-saved-position="<?= (float) ($savedPosition ?? 0) ?>"
                           data-media-type="video"
                           src="<?= e($src) ?>"
                           <?= $posterUrl !== '' ? 'poster="' . e($posterUrl) . '"' : '' ?>
                           data-sources='<?= e(json_encode((object) $qualitySources, JSON_UNESCAPED_SLASHES)) ?>'
                           <?php if ($chapters !== []): ?>data-chapters='<?= e(json_encode($chapters, JSON_UNESCAPED_SLASHES)) ?>'<?php endif; ?>
                           controls preload="metadata" playsinline
                           aria-label="<?= e($title) ?>"></video>
                    <div class="player-overlay" data-overlay hidden>
                        <span class="player-spinner" data-spinner aria-hidden="true"></span>
                        <p class="player-overlay-msg" data-overlay-msg role="status"></p>
                        <button type="button" class="btn btn-sm player-retry" data-retry hidden>Retry</button>
                    </div>
                    <?php if ($chapters !== []): ?>
                        <details class="player-chapters">
                            <summary>Chapters (<?= count($chapters) ?>)</summary>
                            <ol>
                                <?php foreach ($chapters as $ch): ?>
                                    <li>
                                        <button type="button" data-chapter-seek="<?= (float) $ch['start'] ?>">
                                            <span class="ch-time"><?= e(gmdate('i:s', (int) round((float) $ch['start']))) ?></span>
                                            <span class="ch-title"><?= e((string) $ch['title']) ?></span>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <img id="slideshow-img" data-video-id="<?= (int) $photo['id'] ?>" data-media-type="image" src="<?= e($src) ?>" alt="<?= e($title) ?>">
                    <div class="slideshow-bar" data-slideshow-bar>
                        <span class="slideshow-note muted">Auto-advances in a few seconds</span>
                        <button type="button" class="btn btn-sm btn-outline" data-slideshow-toggle>Pause slideshow</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="video-resume" hidden role="status">Resume from <span class="video-resume-time">0:00</span> <button type="button" class="btn btn-sm">Resume</button></div>
            <figcaption class="muted" style="margin-top: 0.5rem">
                <?php if ($photo['caption'] !== ''): ?>
                    <span><?= e($photo['caption']) ?></span><br>
                <?php endif; ?>
                <span><?= number_format((int) ($photo['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($photo['unique_views'] ?? 0)) ?> unique</span>
            </figcaption>
            <?php endif; ?>
        </figure>
        <p style="text-align:center"><a href="<?= e($reportUrl) ?>">Report broken media</a></p>
    </div>

    <?php if (!empty($canViewFull)): ?>
    <div class="player-side">
        <aside class="player-playlist" id="player-playlist">
            <button type="button" class="pl-collapse" data-playlist-toggle aria-expanded="true" aria-controls="player-playlist-body">Hide playlist</button>
            <div id="player-playlist-body">
            <h3><?= !empty($playlistName) ? e($playlistName) : 'Up next' ?>
                <?php if (!empty($playlist)): ?>
                    <span class="muted" style="font-weight:400;">(<?= count($playlist) ?><?= $playlistTotal > 0 ? ', ' . e(gmdate('H:i:s', $playlistTotal)) : '' ?>)</span>
                <?php elseif (!empty($upNext)): ?>
                    <span class="muted" style="font-weight:400;">(from this gallery)</span>
                <?php endif; ?>
            </h3>
            <div class="pl-controls" role="group" aria-label="Playback queue">
                <button type="button" class="pl-ctl" data-queue="autoplay" aria-pressed="true" title="Play the next video automatically">Autoplay: on</button>
                <button type="button" class="pl-ctl" data-queue="repeat" aria-pressed="false" title="Repeat this video when it ends">Repeat: off</button>
                <button type="button" class="pl-ctl" data-queue="shuffle" aria-pressed="false" title="Shuffle the queue order">Shuffle: off</button>
                <button type="button" class="pl-ctl" data-save-collection<?= empty($collections) ? ' hidden' : '' ?>>Save queue as collection</button>
            </div>
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
                <?php elseif (!empty($upNext)): ?>
                    <?php foreach ($upNext as $item): ?>
                        <?php if ((int) $item['id'] === (int) $photo['id']) { continue; } ?>
                        <li class="pl-item" data-video-id="<?= (int) $item['id'] ?>">
                            <a data-swap href="<?= e(url('/videos/' . (int) $item['id'])) ?>">
                                <img src="<?= e((string) $item['thumb']) ?>" alt="" loading="lazy">
                                <span class="pl-title"><?= e($item['caption'] !== '' ? (string) $item['caption'] : 'Video ' . (int) $item['id']) ?></span>
                                <span class="pl-duration"><?= e(!empty($item['duration_seconds']) ? gmdate('i:s', (int) $item['duration_seconds']) : '') ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <?php if (empty($playlist) && empty($upNext)): ?>
                <p class="pl-empty muted" style="padding:.5rem;margin:.25rem 0 0;font-size:.85rem;">No videos queued. Browse galleries to add to the playlist.</p>
            <?php endif; ?>
            </div>
        </aside>

        <div class="u-center">
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
    <?php endif; ?>
</div>
<?php
$sharePath  = '/videos/' . (int) $photo['id'];
$shareTitle = $title;
require __DIR__ . '/../partials/share-bar.php';

// A "more like this" rail: the galleries that share a category with this
// one, and that the member can actually open.
if (!empty($relatedGalleries)): ?>
<?php $currentGallery = $gallery ?? null; ?>
<section class="fav-section">
    <h2>More like this</h2>
    <div class="grid">
        <?php foreach ($relatedGalleries as $related): ?>
            <?php
            $gallery = $related;
            $cover = $relatedCovers['covers'][(int) $related['id']] ?? null;
            $galleryCategories = $relatedCovers['categories'][(int) $related['id']] ?? [];
            require __DIR__ . '/../partials/gallery_card.php';
            ?>
        <?php endforeach; ?>
    </div>
</section>
<?php $gallery = $currentGallery; ?>
<?php endif; ?>
<?php

$commentableType = 'photo';
$commentableId   = (int) $photo['id'];
require __DIR__ . '/../partials/comments.php';
