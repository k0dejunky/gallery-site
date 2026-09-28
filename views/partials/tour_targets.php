<?php
// Server-provided sample URLs that the guided tour navigates to. The tour
// walks a logged-in member through each feature's own page, so we hand it a
// real gallery URL, a real video URL and (when the user has one) a playlist
// URL for a collection that already holds videos. Cached for an hour; the
// collection lookup is per-user and cheap, so it is not cached.
?>
<?php if (\App\Core\Auth::check()): ?>
<?php
$tourUser    = \App\Core\Auth::user();
$tourMedia   = \App\Core\Cache::rememberGen('tour', 'first_media_v2', 3600, function () {
    $galleryRow = \App\Core\Database::run(
        'SELECT g.id FROM galleries g
         WHERE g.is_secret = 0 AND ' . \App\Models\Gallery::publishedVisibleSql('g') . '
         ORDER BY g.id DESC LIMIT 1'
    )->fetch();

    // Prefer a video from the least-restrictive gallery so a free member can
    // actually reach the player page.
    $videoRow = \App\Core\Database::run(
        'SELECT p.id FROM photos p
         INNER JOIN gallery_photo gp ON gp.photo_id = p.id
         INNER JOIN galleries g ON g.id = gp.gallery_id
         WHERE p.is_video = 1 AND g.is_secret = 0 AND ' . \App\Models\Gallery::publishedVisibleSql('g') . '
         ORDER BY g.min_level ASC, p.id DESC LIMIT 1'
    )->fetch();

    return [
        'gallery' => $galleryRow ? (int) $galleryRow['id'] : null,
        'video'   => $videoRow ? (int) $videoRow['id'] : null,
    ];
});

$tourTargets = [
    'gallery'  => $tourMedia['gallery'] ? url('/galleries/' . $tourMedia['gallery']) : null,
    'video'    => $tourMedia['video'] ? url('/videos/' . $tourMedia['video']) : null,
    'playlist' => null,
];

if ($tourMedia['video'] !== null && $tourUser !== null) {
    foreach (\App\Models\Collection::forUser((int) $tourUser['id']) as $tourCollection) {
        if (!empty(\App\Models\Collection::videos((int) $tourCollection['id'], (int) $tourUser['id']))) {
            $tourTargets['playlist'] = $tourTargets['video'] . '?playlist=' . (int) $tourCollection['id'];
            break;
        }
    }
}
?>
<script>window.TOUR_TARGETS = <?= json_encode($tourTargets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php endif; ?>