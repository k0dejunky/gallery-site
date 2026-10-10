<?php
// Server-provided sample URLs that the guided tour navigates to. The tour
// walks a logged-in member through each feature's own page, so we hand it a
// real gallery URL, a real video URL and (when the user has one) a playlist
// URL for a collection that already holds videos. Targets are restricted to
// content the current member can actually view (their membership level), so
// the tour never navigates into a gallery that redirects to the membership
// page. Cached per level for an hour; the collection lookup is per-user and
// cheap, so it is not cached.
?>
<?php if (\App\Core\Auth::check()): ?>
<?php
$tourUser    = \App\Core\Auth::user();
$tourLevel   = \App\Core\Auth::effectiveLevel();
$tourAdmin   = $tourLevel >= PHP_INT_MAX;
$levelClause = $tourAdmin ? '' : ' AND g.min_level <= ' . (int) $tourLevel;
$levelKey    = $tourAdmin ? 'admin' : 'l' . (int) $tourLevel;
$tourMedia   = \App\Core\Cache::rememberGen('tour', 'first_media_v3_' . $levelKey, 3600, function () use ($levelClause) {
    $galleryRow = \App\Core\Database::run(
        'SELECT g.id FROM galleries g
         WHERE g.is_secret = 0 AND ' . \App\Models\Gallery::publishedVisibleSql('g') . $levelClause . '
         ORDER BY g.id DESC LIMIT 1'
    )->fetch();

    $videoRow = \App\Core\Database::run(
        'SELECT p.id FROM photos p
         INNER JOIN gallery_photo gp ON gp.photo_id = p.id
         INNER JOIN galleries g ON g.id = gp.gallery_id
         WHERE p.is_video = 1 AND g.is_secret = 0 AND ' . \App\Models\Gallery::publishedVisibleSql('g') . $levelClause . '
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
<script nonce="<?= csp_nonce() ?>">window.TOUR_TARGETS = <?= json_encode($tourTargets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php endif; ?>